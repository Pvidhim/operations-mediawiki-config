<?php
/**
 * To preview NOC locally, check docroot/noc/README.md
 */
	require_once __DIR__ . '/../../../src/Noc/utils.php';
	require_once __DIR__ . '/../../../src/Noc/EtcdCachedConfig.php';

	/**
	 * @param array $viewFilenames
	 * @param bool $highlight
	 * @param string $prefixFunc
	 */
	function wmfOutputFiles( $viewFilenames, $highlight = true, $prefixFunc = 'basename' ) {
		$viewFilenames = array_map( $prefixFunc, $viewFilenames );
		natsort( $viewFilenames );
		foreach ( $viewFilenames as $viewFilename ) {
			$srcFilename = substr( $viewFilename, -4 ) === '.txt'
				? substr( $viewFilename, 0, -4 )
				: $viewFilename;
			echo "\n<li>";

			if ( $highlight ) {
				echo '<a href="./highlight.php?file=' . htmlspecialchars( $srcFilename ) . '">'
					. htmlspecialchars( $srcFilename );
				echo '</a> (<a href="./' . htmlspecialchars( $viewFilename ) . '">raw text</a>)';
			} else {
				echo '<a href="./' . htmlspecialchars( $viewFilename ) . '">'
					. htmlspecialchars( $srcFilename ) . '</a>';
			}
			echo '</li>';
		}
	}

?><!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
	<meta charset="utf-8">
	<title>Configuration files – Wikimedia NOC</title>
	<link rel="shortcut icon" href="/static/favicon/wmf.ico">
	<link rel="stylesheet" href="../css/base.css">
</head>
<body>
<header><div class="wm-container">
	<a role="banner" href="/" title="Visit the home page"><em>Wikimedia</em> NOC</a>
</div></header>

<main role="main"><div class="wm-container">

	<nav class="wm-site-nav"><ul class="wm-nav">
		<li><a href="/conf/" class="wm-nav-item-active">Config files</a>
			<ul>
				<li><a href="#wmf-config">wmf-config/</a></li>
				<li><a href="#dblist">dblists/</a></li>
			</ul>
		</li>
		<li><a href="/db.php">Database config</a></li>
		<li><a href="/wiki.php">Wiki config</a></li>
	</ul></nav>

	<article>

<p>Below is a selection of Wikimedia configuration files available for easy viewing.
	The files are dynamically generated and are perfectly up-to-date.
	Each of these files is also available in public version control in one of the following repositories:
</p>
<ul>
	<li><a href="https://phabricator.wikimedia.org/diffusion/OMWC/">operations/mediawiki-config.git</a></li>
	<li><a href="https://phabricator.wikimedia.org/diffusion/OPUP/">operations/puppet.git</a></li>
</ul>

<hr>
<p>Currently active MediaWiki versions: <?php
	echo implode( ', ', Wikimedia\MWConfig\Noc\getWikiVersions() );
?></p>
<p>Current primary datacenter: <?php
	$masterDC = \Wikimedia\MWConfig\Noc\EtcdCachedConfig::getInstance()->getValue( 'common/WMFMasterDatacenter' );
	echo $masterDC ?? 'unknown';
?></p>
<hr>

<h2 id="wmf-config">MediaWiki configuration</h2>
<ul>
<?php
	$viewFilenames = array_merge(
		glob( __DIR__ . '/*.php.txt' ),
		glob( __DIR__ . '/{fc-list,langlist*,wikiversions*.json,extension-list}', GLOB_BRACE ),
		glob( __DIR__ . '/*.yaml' )
	);
	wmfOutputFiles( $viewFilenames );
?>
</ul>

<h2 id="dblist">Database lists</h2>
<ul>
<?php
	wmfOutputFiles( glob( __DIR__ . '/dblists/*.dblist' ), true, static function ( $name ) {
		return str_replace( __DIR__ . '/', '', $name );
	} );
?>
</ul>

</article>
</div></main>
</body>
</html>
