<?php
# WARNING: This file is publicly viewable on the web. Do not put private data here.

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Session\SessionManager;
use Monolog\Handler\StreamHandler;

wfLoadExtension( 'LdapAuthentication' );
$wgAuthManagerAutoConfig['primaryauth'] += [
	LdapPrimaryAuthenticationProvider::class => [
		'class' => LdapPrimaryAuthenticationProvider::class,
		'args' => [ [
			// don't allow local non-LDAP accounts
			'authoritative' => true,
		] ],
		// must be smaller than local pw provider
		'sort' => 50,
	],
];
$wgLDAPDomainNames = [ 'labs' ];
switch ( $wgDBname ) {
	case 'labswiki':
		$wgLDAPServerNames = [ 'labs' => "ldap-rw.{$wmgDatacenter}.wikimedia.org" ];
		break;
	case 'labtestwiki':
		$wgLDAPServerNames = [ 'labs' => 'cloudservices2004-dev.codfw.wmnet' ];
		break;
}
// T165795: require exact case matching of username via :caseExactMatch:
$wgLDAPSearchAttributes = [ 'labs' => 'cn:caseExactMatch:' ];
$wgLDAPBaseDNs = [ 'labs' => 'dc=wikimedia,dc=org' ];
$wgLDAPUserBaseDNs = [ 'labs' => 'ou=people,dc=wikimedia,dc=org' ];
$wgLDAPEncryptionType = [ 'labs' => 'tls' ];
$wgLDAPWriteLocation = [ 'labs' => 'ou=people,dc=wikimedia,dc=org' ];
$wgLDAPAddLDAPUsers = [ 'labs' => true ];
$wgLDAPUpdateLDAP = [ 'labs' => true ];
$wgLDAPPasswordHash = [ 'labs' => 'clear' ];
// 'invaliddomain' is set to true so that mail password options
// will be available on user creation and password mailing
// Force strict mode. T218589
// $wgLDAPMailPassword = [ 'labs' => true, 'invaliddomain' => true ];
$wgLDAPPreferences = [ 'labs' => [ "email" => "mail" ] ];
$wgLDAPLowerCaseUsername = [ 'labs' => false, 'invaliddomain' => false ];
// Only enable UseLocal if you need to promote an LDAP user
// $wgLDAPUseLocal = true;
// T168692: Attempt to lock LDAP accounts when blocked
$wgLDAPLockOnBlock = true;
$wgLDAPLockPasswordPolicy = 'cn=disabled,ou=ppolicies,dc=wikimedia,dc=org';

// Local debug logging for troubleshooting LDAP issues
// phpcs:disable Generic.CodeAnalysis.UnconditionalIfStatement.Found
if ( false ) {
	$wgLDAPDebug = 5;
	$monolog = LoggerFactory::getProvider();
	$monolog->mergeConfig( [
		'loggers' => [
			'ldap' => [
				'handlers' => [ 'wikitech-ldap' ],
				'processors' => array_keys( $wmgMonologProcessors ),
			],
		],
		'handlers' => [
			'wikitech-ldap' => [
				'class' => StreamHandler::class,
				'args' => [ '/tmp/ldap-s-1-debug.log' ],
				'formatter' => 'line',
			],
		],
	] );
}

wfLoadExtension( 'OpenStackManager' );

// Dummy setting for conduit api token to be used by the BlockIpComplete hook
// that tries to disable Phabricator accounts. Real value should be provided
// by /etc/mediawiki/WikitechPrivateSettings.php
$wmgPhabricatorApiToken = false;

// Dummy settings for Gerrit api access to be used by the BlockIpComplete hook
// that tries to disable Gerrit accounts. Real values should be provided by
// /etc/mediawiki/WikitechPrivateSettings.php
$wmgGerritApiUser = false;
$wmgGerritApiPassword = false;

# This must be loaded AFTER OSM, to overwrite it's defaults
# Except when we're not an OSM host and we're running like a maintenance script.
if ( file_exists( '/etc/mediawiki/WikitechPrivateSettings.php' ) ) {
	require_once '/etc/mediawiki/WikitechPrivateSettings.php';
}

# wgCdnReboundPurgeDelay is set to 11 in reverse-proxy.php but
# since we aren't using the shared jobqueue, we don't support delays
$wgCdnReboundPurgeDelay = 0;

/**
 * Make arbitrary Conduit requests to the Wikimedia Phabricator
 *
 * @param string $apiToken
 * @param string $path
 * @param array $query
 * @return mixed|false Result of the Conduit request or false on error
 */
function wmfPhabClient( string $apiToken, string $path, $query ) {
	$query['__conduit__'] = [ 'token' => $apiToken ];
	$post = [
		'params' => json_encode( $query ),
		'output' => 'json',
	];
	$phabUrl = 'https://phabricator.wikimedia.org';
	$ch = curl_init( "{$phabUrl}/api/{$path}" );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $post );
	$ret = curl_exec( $ch );
	curl_close( $ch );
	if ( $ret ) {
		$resp = json_decode( $ret, true );
		if ( !$resp['error_code'] ) {
			return $resp['result'];
		}
	}
	wfDebugLog(
		'WikitechPhabBan',
		"Phab {$path} error " . var_export( $ret, true )
	);
	return false;
}

// Attempt to disable related accounts when a developer account is
// permablocked.
$wgHooks['BlockIpComplete'][] = static function ( $block, $user, $prior ) use ( $wmgPhabricatorApiToken ) {
	if ( !$wmgPhabricatorApiToken
		// 1 is the value of Block::TYPE_USER
		|| $block->getType() !== 1
		|| $block->getExpiry() !== 'infinity'
		|| !$block->isSitewide()
	) {
		// Nothing to do if we don't have config or if the block is not
		// a site-wide indefinite block of a named user.
		return;
	}

	try {
		$username = $block->getTargetName();
		$resp = wmfPhabClient( $wmgPhabricatorApiToken, 'user.ldapquery', [
			'ldapnames' => [ $username ],
			'offset' => 0,
			'limit' => 1,
		] );

		if ( $resp ) {
			$phid = $resp[0]['phid'];
			wmfPhabClient( $wmgPhabricatorApiToken, 'user.disable', [
				'phids' => [ $phid ],
			] );
		}
	} catch ( Throwable $t ) {
		wfDebugLog(
			'WikitechPhabBan',
			"Unhandled error blocking Phabricator user: {$t}"
		);
	}
};
$wgHooks['UnblockUserComplete'][] = static function ( $block, $user ) use ( $wmgPhabricatorApiToken ) {
	if ( !$wmgPhabricatorApiToken
		// 1 is the value of Block::TYPE_USER
		|| $block->getType() !== 1
		|| $block->getExpiry() !== 'infinity'
		|| !$block->isSitewide()
	) {
		// Nothing to do if we don't have config or if the block is not
		// a site-wide indefinite block of a named user.
		return;
	}

	try {
		$username = $block->getTargetName();
		$resp = wmfPhabClient( $wmgPhabricatorApiToken, 'user.ldapquery', [
			'ldapnames' => [ $username ],
			'offset' => 0,
			'limit' => 1,
		] );

		if ( $resp ) {
			$phid = $resp[0]['phid'];
			wmfPhabClient( $wmgPhabricatorApiToken, 'user.enable', [
				'phids' => [ $phid ],
			] );
		}
	} catch ( Throwable $t ) {
		wfDebugLog(
			'WikitechPhabBan',
			"Unhandled error unblocking Phabricator user: {$t}"
		);
	}
};

/**
 * Changes the Gerrit active status of the specified user using
 * the specified HTTP method (PUT to enable and DELETE to disable)
 *
 * @param string $gerritUsername
 * @param string $gerritPassword
 * @param string $username
 * @param string $httpMethod
 * @return int|null null on failure, or HTTP response code on success
 */
function wmfGerritSetActive(
	string $gerritUsername,
	string $gerritPassword,
	string $username,
	string $httpMethod
) {
	// Disable gerrit user tied to developer account
	$gerritUrl = 'https://gerrit.wikimedia.org';
	$ch = curl_init(
		"{$gerritUrl}/r/a/accounts/" . urlencode( $username ) . '/active'
	);
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $httpMethod );
	curl_setopt(
		$ch, CURLOPT_USERPWD,
		"{$gerritUsername}:{$gerritPassword}"
	);

	if ( !curl_exec( $ch ) ) {
		wfDebugLog(
			'WikitechGerritBan',
			"Gerrit user active status change ({$httpMethod}) of {$username} failed: " . curl_error( $ch )
		);
		$status = null;
	} else {
		$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	}

	curl_close( $ch );

	return $status;
}

$wgHooks['BlockIpComplete'][] = static function ( $block, $user, $prior ) use ( $wmgGerritApiUser, $wmgGerritApiPassword ) {
	if ( !$wmgGerritApiUser
		|| !$wmgGerritApiPassword
		// 1 is the value of Block::TYPE_USER
		|| $block->getType() !== 1
		|| $block->getExpiry() !== 'infinity'
		|| !$block->isSitewide()
	) {
		// Nothing to do if we don't have config or if the block is not
		// a site-wide indefinite block of a named user.
		return;
	}
	try {
		$username = strtolower( $block->getTargetName() );
		$status = wmfGerritSetActive(
			$wmgGerritApiUser,
			$wmgGerritApiPassword,
			$username,
			'DELETE'
		);

		if ( $status && $status !== 204 ) {
			wfDebugLog(
				'WikitechGerritBan',
				"Gerrit block of {$username} failed with status {$status}"
			);
		}
	} catch ( Throwable $t ) {
		wfDebugLog(
			'WikitechGerritBan',
			"Unhandled error blocking Gerrit user: {$t}"
		);
	}
};
$wgHooks['UnblockUserComplete'][] = static function ( $block, $user ) use ( $wmgGerritApiUser, $wmgGerritApiPassword ) {
	if ( !$wmgGerritApiUser
		|| !$wmgGerritApiPassword
		// 1 is the value of Block::TYPE_USER
		|| $block->getType() !== 1
		|| $block->getExpiry() !== 'infinity'
		|| !$block->isSitewide()
	) {
		// Nothing to do if we don't have config or if the block is not
		// a site-wide indefinite block of a named user.
		return;
	}
	try {
		$username = strtolower( $block->getTargetName() );
		$status = wmfGerritSetActive(
			$wmgGerritApiUser,
			$wmgGerritApiPassword,
			$username,
			'PUT'
		);

		if ( $status && $status !== 204 ) {
			wfDebugLog(
				'WikitechGerritBan',
				"Gerrit unblock of {$username} failed with status {$status}"
			);
		}
	} catch ( Throwable $t ) {
		wfDebugLog(
			'WikitechGerritBan',
			"Unhandled error unblocking Gerrit user: {$t}"
		);
	}
};

/**
 * Invalidates sessions of a blocked user and therefore logs them out.
 */
$wgHooks['BlockIpComplete'][] = static function ( $block, $user, $prior ) {
	// 1 is the value of Block::TYPE_USER
	if ( $block->getType() !== 1
		|| $block->getExpiry() !== 'infinity'
		|| !$block->isSitewide()
	) {
		// Nothing to do if we don't have config or if the block is not
		// a site-wide indefinite block of a named user.
		return;
	}
	if ( !$block->getTargetUserIdentity() ) {
		return;
	}
	$userObj = MediaWikiServices::getInstance()
		->getUserFactory()
		->newFromUserIdentity( $block->getTargetUserIdentity() );
	SessionManager::singleton()->invalidateSessionsForUser( $userObj );
};
