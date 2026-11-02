<?php
/**
 * /.well-known/assetlinks.json (see .htaccess) — tells Android that the GrazeX app may open links to this site
 * itself (App Links), instead of asking "open with…". From the settings file:
 *   ANDROID_PACKAGE   the app's package name (default za.co.grazex.app)
 *   ANDROID_SHA256    SHA-256 fingerprint(s) of the app's signing key, comma-separated
 *                     (Play Console → App integrity → App signing; add the upload key too while testing)
 * Served directly, never redirected: Android's check does not follow redirects.
 */
declare(strict_types=1);

define('GX_NO_SESSION', true);
define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');
echo json_encode(android_asset_links(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
