<?php
/**
 * Translations. All fixed UI text lives in config/lang/<lang>.php.
 * Add config/lang/af.php later to switch the site to Afrikaans.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function current_lang(): string
{
    $lang = setting('site_language', GX_LANG_DEFAULT) ?? GX_LANG_DEFAULT;
    return preg_match('/^[a-z]{2}$/', $lang) && is_file(GX_ROOT . "/config/lang/{$lang}.php") ? $lang : GX_LANG_DEFAULT;
}

function t(string $key, array $replace = []): string
{
    static $strings = null;
    if ($strings === null) {
        $strings = require GX_ROOT . '/config/lang/' . GX_LANG_DEFAULT . '.php';
        $lang = current_lang();
        if ($lang !== GX_LANG_DEFAULT) {
            $strings = array_merge($strings, require GX_ROOT . "/config/lang/{$lang}.php");
        }
    }
    $text = $strings[$key] ?? $key;
    foreach ($replace as $k => $v) {
        $text = str_replace('{' . $k . '}', (string) $v, $text);
    }
    return $text;
}
