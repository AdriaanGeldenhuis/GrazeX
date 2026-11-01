<?php
/**
 * CSRF protection: one token per session, sent as X-CSRF-Token header (fetch) or _csrf field (forms).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = random_token(32);
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? (input_json()['_csrf'] ?? ''));
    $known = $_SESSION['_csrf'] ?? '';
    return is_string($sent) && $known !== '' && hash_equals($known, $sent);
}

/** Enforce CSRF on every state-changing request. */
function csrf_require(): void
{
    if (in_array(request_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (!csrf_valid()) {
        if (defined('GX_IS_API')) {
            json_error(t('error.csrf'), 419);
        }
        http_response_code(419);
        exit(t('error.csrf'));
    }
}

/** Standard API preamble: method check + CSRF. */
function api_post(): void
{
    require_method('POST');
    csrf_require();
}
