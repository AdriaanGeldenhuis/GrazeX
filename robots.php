<?php
/** robots.txt (served at /robots.txt via .htaccess) — includes the absolute sitemap URL. */
declare(strict_types=1);

define('GX_NO_SESSION', true);
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\nDisallow: /admin/\nDisallow: /account/\nDisallow: /setup/\nDisallow: /payments/\nDisallow: /feeds/cart.php\nDisallow: /feeds/checkout.php\n"
   . "Disallow: /ranch/cart.php\nDisallow: /ranch/checkout.php\nDisallow: /*/api/\n\nSitemap: " . app_url('/sitemap.xml') . "\n";
