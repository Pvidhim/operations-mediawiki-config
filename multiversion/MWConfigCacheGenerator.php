<?php

namespace Wikimedia\MWConfig;

use MWWikiversions;

/**
 * Wrapper for config caching code.
 */
class MWConfigCacheGenerator {
	# When updating list please run ./docroot/noc/createTxtFileSymlinks.sh
	# Expand computed dblists with ./multiversion/bin/expanddblist

	public static $dbLists = [
		'private',
		'fishbowl',
		'special',
		'closed',
		'flow',
		'flaggedrevs',
		'small',
		'medium',
		'large',
		'wikimania',
		'wikidata',
		'wikibaserepo',
		'wikidataclient',
		'wikidataclient-test',
		'visualeditor-nondefault',
		'commonsuploads',
		'nonbetafeatures',
		'group0',
		'group1',
		'group2',
		'wikipedia',
		'nonglobal',
		'wikitech',
		'nonecho',
		'mobilemainpagelegacy',
		'wikipedia-cyrillic',
		'wikipedia-e-acute',
		'wikipedia-devanagari',
		'wikipedia-english',
		'nowikidatadescriptiontaglines',
		'top6-wikipedia',
		'rtl',
		'pp_stage0',
		'pp_stage1',
		'cirrussearch-big-indices',
	];

	public static $labsDbLists = [
		'flow-labs',
	];

	/**
	 * Create a MultiVersion config object for a wiki
	 *
	 * @param string $wikiDBname The wiki's database name, e.g. 'enwiki' or  'zh_min_nanwikisource'
	 * @param object $site The wiki's site type family, e.g. 'wikipedia' or 'wikisource'
	 * @param object $lang The wiki's MediaWiki language code, e.g. 'en' or 'zh-min-nan'
	 * @param object $wgConf The global MultiVersion wgConf object
	 * @param string $realm Realm, e.g. 'production' or 'labs'
	 * @return object The wiki's config object
	 */
	public static function getMWConfigForCacheing( $wikiDBname, $site, $lang, $wgConf, $realm = 'production' ) {
		# Collect all the dblist tags associated with this wiki
		$wikiTags = [];

		$dbLists = self::$dbLists;
		if ( $realm === 'labs' ) {
			$dbLists = array_merge( $dbLists, self::$labsDbLists );
		}
		foreach ( $dbLists as $tag ) {
			$dblist = MWWikiversions::readDbListFile( $tag );
			if ( in_array( $wikiDBname, $dblist ) ) {
				$wikiTags[] = $tag;
			}
		}

		$dbSuffix = ( $site === 'wikipedia' ) ? 'wiki' : $site;
		$confParams = [
			'lang'    => $lang,
			'docRoot' => $_SERVER['DOCUMENT_ROOT'],
			'site'    => $site,
		];

		// Add a per-language tag as well
		$wikiTags[] = $wgConf->get( 'wgLanguageCode', $wikiDBname, $dbSuffix, $confParams, $wikiTags );
		$globals = $wgConf->getAll( $wikiDBname, $dbSuffix, $confParams, $wikiTags );

		return $globals;
	}

	/**
	 * Read a static cached MultiVersion object from disc
	 *
	 * @param string $confCacheFile The full filepath for the wiki's cached config object
	 * @param string $confActualMtime The expected mtime for the cached config object
	 * @return object|null The wiki's config object, or null if not yet cached or stale
	 */
	public static function readFromStaticCache( $confCacheFile, $confActualMtime ) {
		// Ignore file warnings (file may be inaccessible, or deleted in a race)
		$cacheRecord = @file_get_contents( $confCacheFile );

		if ( $cacheRecord !== false ) {
			// TODO: Use JSON_THROW_ON_ERROR with a try/catch once production is running PHP 7.3.
			$staticCacheObject = json_decode( $cacheRecord, /* assoc */ true );

			if ( json_last_error() === JSON_ERROR_NONE ) {
				// Ignore non-array and array offset warnings (file may be in an older format)
				if ( @$staticCacheObject['mtime'] === $confActualMtime ) {
					return $staticCacheObject['globals'];
				}
			} else {
				// Something went wrong; raise an error
				trigger_error( "Config cache failure: Static decoding failed", E_USER_ERROR );
			}
		}

		// Reached if the file doesn't exist yet, can't be read, was out of date, or was corrupt.
		return null;
	}

	/**
	 * Write a static MultiVersion object to disc cache
	 *
	 * @param string $cacheDir The filepath for cached multiversion config storage
	 * @param string $cacheShard The filename for the cached multiversion config object
	 * @param object $configObject The config object for this wiki
	 */
	public static function writeToStaticCache( $cacheDir, $cacheShard, $configObject ) {
		@mkdir( $cacheDir );
		$tmpFile = tempnam( '/tmp/', $cacheShard );

		$staticCacheObject = json_encode(
			$configObject,
			// TODO: Use JSON_THROW_ON_ERROR with a try/catch once production is running PHP 7.3.
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) . "\n";

		if ( $tmpFile ) {
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				// Something went wrong; for safety, don't write anything, and raise an error
				trigger_error( "Config cache failure: Static encoding failed", E_USER_ERROR );
			} else {
				if ( file_put_contents( $tmpFile, $staticCacheObject ) ) {
					if ( rename( $tmpFile, $cacheDir . '/' . $cacheShard ) ) {
						// Rename succeded; no need to clean up temp file
						return;
					};
				}
			}
			// T136258: Rename failed, write failed, or data wasn't cacheable; clean up temp file
			unlink( $tmpFile );
		}
	}

}
