<?php
/**
 * Serve static files in a multiversion-friendly way.
 *
 * See https://wikitech.wikimedia.org/wiki/MediaWiki_at_WMF#Static_files for
 * usage documentation.
 *
 * Design requirements:
 * - Support changes within the life of a deployed branch. https://phabricator.wikimedia.org/T99096
 * - Support caching beyond the life of a deployed branch. https://phabricator.wikimedia.org/T99096
 * - Avoid cache poisoning. https://phabricator.wikimedia.org/T47877, https://phabricator.wikimedia.org/T117587
 *
 * Overview:
 *
 * - multiversion requires the MediaWiki script directory on disk (/w) to be shared across
 *   all domains. Files in /w are generic and proxy to a real MediaWiki entry point
 *   in the current wiki's MediaWiki version, as determined by the request host name.
 * - MediaWiki configuration sets $wgResourceBasePath to "/w".
 * - Apache configuration rewrites "/w/skins/*", "/w/resources/*", and "/w/extension/*"
 *   to /w/static.php (this file).
 *   Here we stream the file from the appropiate MediaWiki branch directory.
 * - For performance, Varnish caches responses from static.php in a hostname-agnostic
 *   way if a hexidecimal query string is set. (E.g. verifiable hash.)
 *   Therefore static.php MUST respond in a deterministic way for those requests
 *   regardless of which wiki made the request. (Compliance is enforced via VCL by
 *   hardcoding 'en.wikipedia.org' for these requests, per static_host config.)
 *
 * In addition to the above, when responding to a request with a version hash we consider
 * both the latest and previous MediaWiki branches as the source to serve the file.
 * This is to support references from cached HTML in ParserCache and CDN for 30 days.
 * While responses for those static assets may themselves also be cached, these caches
 * are logically separated and generally last much shorter. As such, we must be able to
 * respond to requests for older resources as well.
 *
 * Another reason is to avoid cache poisoning during deployments where otherwise
 * a new URI could be routed to a server with the previous version and end up
 * populating the old object under the new URI (T47877, T117587).
 *
 * StatsD metrics:
 *
 * - wmfstatic.success.<responseType (nohash, verified, unknown)>
 * - wmfstatic.notfound
 * - wmfstatic.mismatch
 */

// This endpoint is supposed to be independent of request cookies and other
// details of the session. Enforce this constraint with respect to session use.
define( 'MW_NO_SESSION', 1 );

require_once __DIR__ . '/../multiversion/MWMultiVersion.php';
require MWMultiVersion::getMediaWiki( 'includes/WebStart.php' );

define( 'WMF_STATIC_5MIN', 300 );
define( 'WMF_STATIC_24H', 86400 );
define( 'WMF_STATIC_1Y', 31536000 );

// Requests for /static/current/ are also rewritten to /w/static.php (T285232)
define( 'WMF_STATIC_PREFIX_CURRENT', '/static/current' );

/**
 * This should always use 404 if there is an issue with the url.
 * Avoid exposing the reason of it being invalid (T204186).
 *
 * @param string $message
 * @param int $status HTTP status code (One of 500 or 404)
 */
function wmfStaticShowError( $message, $status ) {
	HttpStatus::header( $status );
	header(
		'Cache-Control: ' .
		's-maxage=' . WMF_STATIC_5MIN . ', must-revalidate, max-age=0'
	);
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "$message\n";
}

/**
 * Stream file from disk to web response.
 *
 * Based on MediaWiki's StreamFile::stream().
 *
 * @param string $filePath File to stream
 * @param string $responseType Cache control
 *  For details about these modes, and how they are used,
 *  see <https://wikitech.wikimedia.org/wiki/MediaWiki_at_WMF#Static_files>.
 *
 *  - "verified" Immutable cache. These are versioned URLs under /w/,
 *     as generated by MediaWiki. This represents most traffic to static.php.
 *
 *  - "current" 1 year cache. These are URLs to /static/current as per T285232.
 *
 *  - "nohash" 1 year cache. These are unversioned URLs to /w/.
 *
 *  - "unknown" Immutable (e.g. garbage URLs)
 */
function wmfStaticStreamFile( $filePath, $responseType = 'nohash' ) {
	$ctype = StreamFile::contentTypeFromPath( $filePath, /* safe: not for upload */ false );
	if ( !$ctype || $ctype === 'unknown/unknown' ) {
		// Directory, extension-less file or unknown extension
		wmfStaticShowError( 'Unknown file path', 404 );
		return;
	}

	$stat = stat( $filePath );
	if ( !$stat ) {
		wmfStaticShowError( 'Unknown file path', 404 );
		return;
	}

	// Match puppet:///mediawiki/apache/expires.conf
	if ( preg_match( '/\.(gif|jpe?g|png|css|js|json|woff|woff2|svg|eot|ttf|ico)$/', $filePath ) ) {
		header( 'Access-Control-Allow-Origin: *' );
	}
	header( 'Last-Modified: ' . wfTimestamp( TS_RFC2822, $stat['mtime'] ) );
	header( "Content-Type: $ctype" );
	if ( $responseType === 'nohash' ) {
		// Unversioned files must be renewed within 24 hours
		header(
			sprintf( 'Cache-Control: public, s-maxage=%d, must-revalidate, max-age=%d',
				WMF_STATIC_24H, WMF_STATIC_24H
			)
		);
	} elseif ( $responseType == 'current' ) {
		// Requests for /static/current will be cached unconditionally for 1 year (T285232).
		header(
			sprintf( 'Cache-Control: public, s-maxage=%d, max-age=%d',
				WMF_STATIC_1Y, WMF_STATIC_1Y
			)
		);
	} else {
		// Versioned and verifable files are considered immutable.
		// For the CDN, and clients not supporting "immutable", allow re-use for a year.
		header(
			sprintf( 'Cache-Control: public, s-maxage=%d, max-age=%d, immutable',
				WMF_STATIC_1Y, WMF_STATIC_1Y
			)
		);
	}

	if ( !empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		$ims = preg_replace( '/;.*$/', '', $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		if ( wfTimestamp( TS_UNIX, $stat['mtime'] ) <= strtotime( $ims ) ) {
			ini_set( 'zlib.output_compression', 0 );
			header( 'HTTP/1.1 304 Not Modified' );
			return;
		}
	}

	header( 'Content-Length: ' . $stat['size'] );
	readfile( $filePath );
}

/**
 * Extract the path and its prefix from a given url.
 *
 * @param string $uri Full Request URI
 * @return array|false Prefix and path, or false if no prefix found.
 */
function wmfStaticParsePath( $uri ) {
	global $wgScriptPath;

	// Strip query parameters
	$uriPath = parse_url( $uri, PHP_URL_PATH );

	if ( strpos( $uriPath, $wgScriptPath ) === 0 ) {
		$urlPrefix = $wgScriptPath;
	} elseif ( strpos( $uriPath, WMF_STATIC_PREFIX_CURRENT ) === 0 ) {
		$urlPrefix = WMF_STATIC_PREFIX_CURRENT;
	} else {
		// No valid prefix found.
		return false;
	}
	return [
		'prefix' => $urlPrefix,
		// Request path, stripped of the prefix
		'path' => substr( $uriPath, strlen( $urlPrefix ) ),
	];
}

function wmfStaticRespond() {
	global $IP;

	if ( !isset( $_SERVER['REQUEST_URI'] ) || !isset( $_SERVER['SCRIPT_NAME'] ) ) {
		wmfStaticShowError( 'Bad request', 400 );
		return;
	}

	// Reject direct requests (eg. "/w/static.php" or "/w/static.php/test")
	// Use strpos() to tolerate trailing pathinfo or query string
	if ( strpos( $_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'] ) === 0 ) {
		wmfStaticShowError( 'Unknown file path', 404 );
		return;
	}

	// Strip query parameters and the prefix
	$pathData = wmfStaticParsePath( $_SERVER['REQUEST_URI'] );
	if ( !$pathData ) {
		wmfStaticShowError( 'Unknown file path', 404 );
		return;
	}
	$uriPath = $pathData['path'];
	$uriPrefix = $pathData['prefix'];
	// Reject access to dot files and dot directories
	if ( strpos( $uriPath, '/.' ) !== false ) {
		wmfStaticShowError( 'Unknown file path', 404 );
		return;
	}

	// Validation hash
	$urlHash = isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : false;
	$responseType = 'nohash';

	// Get branch dirs and sort with newest first
	$branchDirs = MWWikiversions::getAvailableBranchDirs();
	usort( $branchDirs, static function ( $a, $b ) {
		return version_compare( $b, $a );
	} );
	$newestFoundDir = null;

	// If the request doesn't have a valid 5-char hex hash, prefer the current wikiversion.
	// Note we can't do this for a matching verification hash because Varnish will
	// have already sent us to the static host instead of the individual wiki.
	$validHash = $urlHash && preg_match( '/^[a-fA-F0-9]{5}$/', $urlHash );

	if ( $uriPrefix === WMF_STATIC_PREFIX_CURRENT ) {
		// "Current" always points to the newest branch and ignores any validation hash
		$branchDirs = array_slice( $branchDirs, 0, 1 );
		$urlHash = false;
		$validHash = false;
		$responseType = 'current';
	} elseif ( !$validHash ) {
		array_unshift( $branchDirs, $IP );
	}

	$stats = RequestContext::getMain()->getStats();

	// Try each version in descending order
	// - Requests without a validation hash will get the latest version.
	//   (If the file no longer exists in the latest version, it will correctly
	//   fall back to the last available version.)
	// - Requests with validation hash get the first match. If none found, falls back to the last
	//   available version. Cache expiry is shorted in that case to allow eventual-consistency and
	//   avoids cache poisoning (see T47877).
	foreach ( $branchDirs as $branchDir ) {
		// Use realpath() to prevent path escalation through e.g. "../"
		$filePath = realpath( "$branchDir/$uriPath" );
		if ( !$filePath ) {
			continue;
		}

		if ( strpos( $filePath, $branchDir ) !== 0 ) {
			wmfStaticShowError( 'Unknown file path', 404 );
			return;
		}

		if ( !$newestFoundDir ) {
			// Remember what we found, in case we need a fallback
			$newestFoundDir = $branchDir;
		}

		if ( $urlHash && !$validHash ) {
			// Garbage query string.
			//
			// Simply respond with the first existing version we find.
			// Much like if there was no validation hash in the URL (nohash),
			// except with a longer max-age.
			//
			// This prevents extra backend hits from broken URLs, and yet
			// keeps expected behavior for extensions that embed libraries that
			// have their own URL versioning/cache-busting scheme.
			$responseType = 'unknown';
		} elseif ( $urlHash ) {
			// Match OutputPage::transformFilePath()
			$fileHash = substr( md5_file( $filePath ), 0, 5 );
			if ( $fileHash !== $urlHash ) {
				// Hash mismatch, continue search in older branches
				continue;
			}
			// Cache hash-validated responses for long
			$responseType = 'verified';
		}

		wmfStaticStreamFile( $filePath, $responseType );
		$stats->increment( "wmfstatic.success.$responseType" );
		return;
	}

	if ( !$newestFoundDir ) {
		wmfStaticShowError( 'Unknown file path', 404 );
		$stats->increment( 'wmfstatic.notfound' );
		return;
	}

	// Serve fallback with short TTL if version looks like a valid hash
	// but we don't (yet) have a matching file.
	wmfStaticStreamFile( "$newestFoundDir/$uriPath", $responseType );
	$stats->increment( 'wmfstatic.mismatch' );
}

wfResetOutputBuffers();
wmfStaticRespond();

$mediawiki = new MediaWiki();
$mediawiki->doPostOutputShutdown();
