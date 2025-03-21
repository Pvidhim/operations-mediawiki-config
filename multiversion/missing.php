<?php
/**
 * Handle "Missing wiki" HTTP response (either redirect or 404).
 *
 * To test the script locally:
 *
 * - Uncomment MISSING_PHP_TEST
 * - Run `php -S localhost:9412` from this directory.
 * - <http://localhost:9412/missing.php?host=aa.wikinews.org> (Incubator redirect)
 * - <http://localhost:9412/missing.php?host=nl.wikiversity.org> (404)
 * - <http://localhost:9412/missing.php?host=nl.wikiversity.org&title=wikt:foo> (interwiki redirect)
 *
 * We redirect non-existing languages of Wikipedia, Wiktionary, Wikiquote,
 * Wikibooks, and Wikinews to the Wikimedia Incubator.
 *
 * Non-existing languages of Wikisource and Wikiversity show a 404 page.
 *
 * The WikimediaIncubator extension ensures a localised "welcome page"
 * adapted to the given project/language.
 *
 * These redirects are relied upon as part of the interwiki map
 * and language link databases, by allowing any two languages to
 * have a stable and canonical link between them, regardless of whether
 * it is still on Incubator, e.g. [[xyz:Page]] on en.wikipedia redirects
 * via missing.php to <https://incubator.wikimedia.org/wiki/Wp/xyz/Page>.
 *
 * @copyright Copyright 2011-2013, Danny B., SPQRobin, Tim Starling
 * @license GPL-2.0-or-later
 */

// define( 'MISSING_PHP_TEST', 1 );

/**
 * The main function
 */
function wmfHandleMissingWiki() {
	$projects = [
		'wikibooks'   => 'b',
		'wikinews'    => 'n',
		'wikipedia'   => 'p',
		'wikiquote'   => 'q',
		// forward compatibility, unused ATM (both 's' and 'v')
		'wikisource'  => 's',
		'wikiversity' => 'v',
		'wikivoyage'  => 'y',
		'wiktionary'  => 't',
	];

	[ $protocol, $host ] = wmfGetProtocolAndHost();

	if ( strpos( $host, '.m.' ) !== false ) {
		// Invalid request to mobile site, not rewritten by Varnish
		wmfShowMobileError();
		return;
	}

	# $language.$project.org
	$tmp = explode( '.', $host );
	$project = $incubatorCode = false;
	if ( count( $tmp ) == 3 ) {
		[ $language, $project, $tld ] = $tmp;
		if ( isset( $_SERVER['PATH_INFO'] )
			&& preg_match( '!^/(.*)$!', $_SERVER['PATH_INFO'], $m ) ) {
			$page = $m[1];
		} else {
			// Fall back to the Main page
			$page = $_GET['title'] ?? '';
		}
		$incubatorCode = $projects[$project] ?? null;
	}

	if ( !$incubatorCode ) {
		wmfShowGenericError();
		return;
	}

	if ( strpos( $page, ':' ) !== false ) {
		# Open the interwiki file to see if we have an interwiki prefix
		$db = null;
		try {
			$db = include __DIR__ . '/../wmf-config/interwiki.php';
		} catch ( Exception $e ) {
		}

		if ( $db ) {
			$prefix = strtok( $page, ':' );

			# Try looking for lateral links (w: q: voy: ...)
			$row = $db[ "{$language}wiki:$prefix" ] ?? null;
			if ( !$row ) {
				# Also try interlanguage links
				$projectKey = ( $project === 'wikipedia' ? 'wiki' : $project );
				if ( isset( $db[ "_$projectKey:$prefix" ] ) ) {
					$row = $db[ "_$projectKey:$prefix" ];
				}
			}

			if ( $row ) {
				[ $iw_local, $iw_url ] = explode( ' ', $row );
				if ( $iw_local ) {
					# Redirect to the appropriate WMF wiki
					# strtok gives us the remainder of the page title after the interwiki prefix
					wmfShowRedirect( str_replace( '$1', strtok( '' ), $iw_url ) );
					return;
				}
			}
			# We don't have an interwiki link, keep going and see what else we could have
		}
	}

	if ( $project === 'wikisource' ) {
		# Wikisource should redirect to the multilingual wikisource
		wmfShowRedirect( $protocol . '://wikisource.org/wiki/' . $page );
	} elseif ( $project === 'wikiversity' ) {
		# Wikiversity gives an error page
		wmfShowMissingSubdomainError( $project, $language );
	} else {
		# Redirect to incubator
		$incubatorBase = 'incubator.wikimedia.org/wiki/';
		$location = $protocol . '://' . $incubatorBase . 'W' . $incubatorCode . '/' . urlencode( $language );
		# Go to the page if specified (look out for slashes), otherwise go to
		# the main page Wx/xyz?goto=mainpage (WikimediaIncubator extension takes care of that)
		$location .= $page && $page !== '/' ? '/' . $page :
			'?goto=mainpage' . ( isset( $_GET['uselang'] ) ? '&uselang=' . urlencode( $_GET['uselang'] ) : '' );

		wmfShowRedirect( $location );
	}
}

/**
 * Obtaining the full self URL
 * @return string Actual URL except for fragment part
 */
function wmfGetProtocolAndHost() {
	if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' ) {
		$protocol = 'https';
	} else {
		$protocol = 'http';
	}
	if ( defined( 'MISSING_PHP_TEST' ) && isset( $_GET['host'] ) ) {
		$host = $_GET['host'];
	} else {
		$host = $_SERVER['HTTP_HOST'];
	}
	$host = strtolower( $host );
	return [ $protocol, $host ];
}

/**
 * Get a stylesheet with the specified logo in the background.
 * @param string $logo
 * @return string
 */
function wmfGetStyleSheet( $logo ) {
	return <<<CSS
* {
	font-family: 'Gill Sans MT', 'Gill Sans', sans-serif;
	margin: 0;
	padding: 0;
}

body {
  background: #fff url('https://upload.wikimedia.org/wikipedia/commons/9/96/Errorbg.png') repeat-x;
  color: #333;
  margin: 0;
  padding: 0;
}

#page {
  background: url('$logo') center left no-repeat;
  height: 300px;
  left: 50%;
  margin: -150px 0 0 -360px;
  overflow: visible;
  position: absolute;
  top: 50%;
  width: 720px;
}

#message {
	background: url('https://upload.wikimedia.org/wikipedia/commons/9/97/Errorline.png') center left no-repeat;
	margin-left: 300px;
	padding-left: 15px;
}

h1, h2, p {
	margin-bottom: 1em;
}

a:link, a:visited {
	color: #005b90;
}

a:hover, a:active {
	color: #900;
}
CSS;
}

/**
 * Output an error which indicates that a request for *.m.wik*.org was received
 */
function wmfShowMobileError() {
	header( 'HTTP/1.x 403 Forbidden' );
	header( 'Content-Type: text/html; charset=utf-8' );

	// Disable caching, due to the suspicion that T49807 is caused by cache poisoning.
	header( 'Cache-Control: no-cache' );

?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<style type="text/css">
/* <![CDATA[ */
<?php echo wmfGetStyleSheet( 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/81/Wikimedia-logo.svg/300px-Wikimedia-logo.svg.png' ); ?>
/* ]]> */
</style>
<title>Internal error</title>
</head>
<body>
	<div id="page">
		<div id="message">
			<h1>Internal error</h1>
			<p>Mobile domains are not served from this server IP address.</p>
			<p style="font-size: smaller;">A&nbsp;project of the <a href="https://wikimediafoundation.org" title="Wikimedia Foundation">Wikimedia Foundation</a></p>
		</div>
	</div>
</body>
</html>

<?php
}

/**
 * Output an error message explaining that no wiki for the given subdomain exists.
 * This has been superseded by an Incubator redirect for all projects other than
 * Wikiversity.
 *
 * @param string $project
 * @param string $language
 */
function wmfShowMissingSubdomainError( $project, $language ) {
	$projectInfos = [
		'wikiversity' => [
			'logo' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/91/Wikiversity-logo.svg/300px-Wikiversity-logo.svg.png',
			'home' => 'https://beta.wikiversity.org',
			'name' => 'Wikiversity',
			'home-name' => 'Beta Wikiversity',
		]
	];
	$info = $projectInfos[$project];
	header( 'HTTP/1.x 404 Not Found' );
	header( 'Content-Type: text/html; charset=utf-8' );

	$escLanguage = htmlspecialchars( $language );
	$escName = htmlspecialchars( $info['name'] );

?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
	<title><?php echo "$escLanguage&nbsp;$escName"; ?> does not exist</title>
	<meta charset="UTF-8" />
	<link rel="shortcut icon" href="<?php echo $info['home']; ?>/favicon.ico" />
	<style type="text/css">
/* <![CDATA[ */
<?php echo wmfGetStyleSheet( $info['logo'] ); ?>
/* ]]> */
	</style>
</head>
<body>
	<div id="page">
		<div id="message">

			<h1>This wiki does not exist</h1>

			<h2>Welcome to <?php echo $escName; ?></h2>

			<p>Unfortunately, <?php echo $escName; ?> in "<?php echo $escLanguage; ?>" does not exist on its own domain yet, or it has been closed.</p>

			<p>You may like to visit <a href="<?php echo $info['home']; ?>"><?php echo $info['home-name']; ?></a> to start or improve <em><?php echo "$escLanguage&nbsp;$escName"; ?></em> there.</p>

			<p>If you would like to request that this wiki be created, see the <a href="https://meta.wikimedia.org/wiki/Requests_for_new_languages">requests for new languages</a> page on Meta-Wiki.</p>

			<p style="font-size: smaller;">A&nbsp;project of the <a href="https://wikimediafoundation.org" title="Wikimedia Foundation">Wikimedia Foundation</a></p>

		</div>
	</div>
</body>
</html>

<?php
}

/**
 * Show a generic error message which does not refer to any particular project.
 */
function wmfShowGenericError() {
	header( 'HTTP/1.x 404 Not Found' );
	header( 'Content-Type: text/html; charset=utf-8' );
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
	<title>No wiki found</title>
	<style type="text/css">
/* <![CDATA[ */
<?php echo wmfGetStyleSheet( 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/81/Wikimedia-logo.svg/300px-Wikimedia-logo.svg.png' ); ?>
/* ]]> */
	</style>
</head>
<body>
	<div id="page">
		<div id="message">

			<h1>No wiki found</h1>

			<p>Sorry, we were not able to work out what wiki you were trying to view.
			Please specify a valid Host header.</p>

			<p style="font-size: smaller;">A&nbsp;project of the <a href="https://wikimediafoundation.org" title="Wikimedia Foundation">Wikimedia Foundation</a></p>

		</div>
	</div>
</body>
</html>

<?php
}

/**
 * Show a redirect, including "short hypertext note" as suggested by RFC 2616 section 10.3.2.
 * @param string $url
 */
function wmfShowRedirect( $url ) {
	header( 'Location: ' . $url );
	header( 'Content-Type: text/html; charset=utf-8' );
	$escUrl = htmlspecialchars( $url );
	echo <<<HTML
<!DOCTYPE html>
<html>
<body>
<a href="$escUrl">The document has moved.</a>
</body>
</html>

HTML;
}

wmfHandleMissingWiki();
