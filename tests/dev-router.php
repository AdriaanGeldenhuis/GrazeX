<?php
/**
 * Local development router for PHP's built-in server — mimics the Apache .htaccess rules:
 *   php -S 127.0.0.1:8080 tests/dev-router.php
 * Never deployed (tests/ is excluded from the deploy).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// Blocked folders / files (same as .htaccess)
if (preg_match('#^/(config|includes|templates|storage|database|cron|vendor|tests)(/|$)#i', $uri)
    || preg_match('#(^|/)\.(?!well-known/)#', $uri)
    || preg_match('#^/uploads/.*\.(php\d?|phtml|phar|pl|py|cgi|sh)$#i', $uri)
    || preg_match('#(^|/)(composer\.(json|lock))$#i', $uri)
    || preg_match('#\.(sql|md|log|lock|ya?ml|ini|dist|sh|bak)$#i', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Pretty URLs (mirrors /feeds/.htaccess and /ranch/.htaccess)
$pretty = [
    '#^/feeds/product/([a-z0-9-]+)/?$#' => ['/feeds/product.php', 'slug'],
    '#^/feeds/category/([a-z0-9-]+)/?$#' => ['/feeds/shop.php', 'category'],
    '#^/ranch/lot/([a-z0-9-]+)/?$#' => ['/ranch/lot.php', 'slug'],
    '#^/pages/([a-z0-9-]+)/?$#' => ['/pages/page.php', 'slug'],
];
foreach ($pretty as $re => [$target, $param]) {
    if (preg_match($re, $uri, $m)) {
        $_GET[$param] = $m[1];
        $_SERVER['SCRIPT_NAME'] = $target;
        chdir($root . dirname($target));
        require $root . $target;
        return true;
    }
}

if ($uri === '/robots.txt' || $uri === '/sitemap.xml') {
    require $root . ($uri === '/robots.txt' ? '/robots.php' : '/sitemap.php');
    return true;
}
$path = $root . $uri;
if (is_dir($path)) {
    $path = rtrim($path, '/') . '/index.php';
}
if (is_file($path)) {
    if (str_ends_with($path, '.php')) {
        $_SERVER['SCRIPT_NAME'] = substr($path, strlen($root));
        chdir(dirname($path));
        require $path;
        return true;
    }
    if (str_ends_with($path, '.webmanifest')) {
        header('Content-Type: application/manifest+json');
        readfile($path);
        return true;
    }
    return false; // let the built-in server serve static files
}

http_response_code(404);
require $root . '/404.php';
return true;
