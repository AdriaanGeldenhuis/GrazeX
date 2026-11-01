<?php
/**
 * Application constants (safe to commit — no secrets here).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

const GX_VERSION      = '1.0.0';
const GX_TIMEZONE     = 'Africa/Johannesburg';
const GX_LANG_DEFAULT = 'en';
const GX_CURRENCY     = 'ZAR';

// Divisions (DB ids are seeded as 1 = feeds, 2 = ranch)
const GX_DIVISIONS = [
    'feeds' => ['id' => 1, 'name' => 'GrazeX Feeds', 'path' => '/feeds/', 'theme' => 'feeds'],
    'ranch' => ['id' => 2, 'name' => 'GrazeX Ranch', 'path' => '/ranch/', 'theme' => 'ranch'],
];

// Sessions
const GX_SESSION_NAME        = 'GXSESSID';
const GX_ADMIN_IDLE_SECONDS  = 7200;   // admin logged out after 2h idle
const GX_CART_COOKIE_PREFIX  = 'gx_cart_';
const GX_CART_COOKIE_DAYS    = 30;

// Uploads
const GX_UPLOAD_MAX_BYTES    = 8 * 1024 * 1024;  // 8 MB per image
const GX_POP_MAX_BYTES       = 5 * 1024 * 1024;  // 5 MB per proof of payment
const GX_IMAGE_MIMES         = ['image/jpeg', 'image/png', 'image/webp'];
const GX_POP_MIMES           = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const GX_IMAGE_WIDTHS        = [480, 960, 1600];   // generated widths (16:9 crops + originals)

// Passwords
const GX_PASSWORD_MIN        = 10;
