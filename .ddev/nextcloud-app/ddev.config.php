<?php

declare(strict_types=1);

// #ddev-generated
// Development-only Nextcloud configuration provided by ddev-nextcloud-app.

$primaryUrl = getenv('DDEV_PRIMARY_URL') ?: 'http://localhost';
$url = parse_url($primaryUrl);
$trustedHost = is_array($url) && isset($url['host']) ? $url['host'] : 'localhost';
$serverRoot = dirname(__DIR__);

$CONFIG = [
	'appcodechecker' => false,
	'apps_paths' => [
		[
			'path' => $serverRoot . '/apps',
			'url' => '/apps',
			'writable' => false,
		],
		[
			'path' => $serverRoot . '/apps-extra',
			'url' => '/apps-extra',
			'writable' => true,
		],
	],
	'check_for_working_htaccess' => false,
	'debug' => true,
	'default_phone_region' => 'TW',
	'htaccess.RewriteBase' => '/',
	'loglevel' => 0,
	'mail_from_address' => 'nextcloud',
	'mail_domain' => $trustedHost,
	'mail_smtphost' => '127.0.0.1',
	'mail_smtpmode' => 'smtp',
	'mail_smtpport' => 1025,
	'maintenance_window_start' => 1,
	'overwrite.cli.url' => $primaryUrl,
	'trusted_domains' => [
		$trustedHost,
		'web',
	],
	'updatechecker' => false,
];

if (PHP_SAPI !== 'cli') {
	$CONFIG['memcache.local'] = '\OC\Memcache\APCu';
}
