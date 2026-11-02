<?php
/**
 * Admin form field helpers — consistent, accessible fields across every editor.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function af_id(string $name): string
{
    return 'f-' . trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-');
}

function af_wrap(string $name, string $label, string $control, array $o = []): string
{
    $hint = isset($o['hint']) ? '<span class="hint">' . e($o['hint']) . '</span>' : '';
    $opt = !empty($o['optional']) ? ' <span class="opt">(optional)</span>' : '';
    return '<div class="field' . (!empty($o['span']) ? ' span-2' : '') . '"><label for="' . e($o['id'] ?? af_id($name)) . '">' . e($label) . $opt . '</label>' . $control . $hint . '</div>';
}

function af_attrs(array $o): string
{
    $a = '';
    foreach (['placeholder', 'min', 'max', 'step', 'maxlength', 'pattern', 'inputmode', 'autocomplete'] as $k) {
        if (isset($o[$k])) {
            $a .= ' ' . $k . '="' . e($o[$k]) . '"';
        }
    }
    if (!empty($o['required'])) {
        $a .= ' required';
    }
    if (!empty($o['readonly'])) {
        $a .= ' readonly';
    }
    if (!empty($o['data'])) {
        foreach ($o['data'] as $k => $v) {
            $a .= ' data-' . e($k) . '="' . e($v) . '"';
        }
    }
    return $a;
}

function af_text(string $name, string $label, mixed $value = '', array $o = []): string
{
    $type = $o['type'] ?? 'text';
    return af_wrap($name, $label, '<input class="input" type="' . e($type) . '" id="' . e($o['id'] ?? af_id($name)) . '" name="' . e($name) . '" value="' . e($value) . '"' . af_attrs($o) . '>', $o);
}

function af_money(string $name, string $label, ?int $cents, array $o = []): string
{
    $val = $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    return af_text($name, $label, $val, $o + ['inputmode' => 'decimal', 'placeholder' => '0.00']);
}

function af_textarea(string $name, string $label, ?string $value = '', array $o = []): string
{
    return af_wrap($name, $label, '<textarea class="textarea" id="' . e(af_id($name)) . '" name="' . e($name) . '" rows="' . (int) ($o['rows'] ?? 3) . '"' . af_attrs($o) . '>' . e($value) . '</textarea>', $o);
}

function af_markdown(string $name, string $label, ?string $value = '', array $o = []): string
{
    $control = '<div class="md-editor" data-md><div class="md-toolbar" role="toolbar" aria-label="Formatting">'
        . '<button type="button" data-md-cmd="b" title="Bold"><b>B</b></button><button type="button" data-md-cmd="i" title="Italic"><i>I</i></button>'
        . '<button type="button" data-md-cmd="h" title="Heading">H</button><button type="button" data-md-cmd="ul" title="List">•</button>'
        . '<button type="button" data-md-cmd="link" title="Link">🔗</button><button type="button" data-md-cmd="preview" title="Preview">👁</button></div>'
        . '<textarea class="textarea" id="' . e(af_id($name)) . '" name="' . e($name) . '" rows="' . (int) ($o['rows'] ?? 6) . '">' . e($value) . '</textarea>'
        . '<div class="md-preview prose" hidden></div></div>';
    return af_wrap($name, $label, $control, $o + ['hint' => 'Tip: **bold**, _italic_, - lists, ## headings']);
}

/** $options: [value => label] */
function af_select(string $name, string $label, array $options, mixed $value = '', array $o = []): string
{
    $html = '<select class="select" id="' . e(af_id($name)) . '" name="' . e($name) . '"' . af_attrs($o) . '>';
    if (isset($o['empty'])) {
        $html .= '<option value="">' . e($o['empty']) . '</option>';
    }
    foreach ($options as $k => $l) {
        $html .= '<option value="' . e($k) . '"' . ((string) $k === (string) $value ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return af_wrap($name, $label, $html . '</select>', $o);
}

function af_check(string $name, string $label, bool $checked, array $o = []): string
{
    return '<div class="field' . (!empty($o['span']) ? ' span-2' : '') . '"><input type="hidden" name="' . e($name) . '" value="0"><label class="check"><input type="checkbox" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '> ' . e($label) . '</label>'
        . (isset($o['hint']) ? '<span class="hint">' . e($o['hint']) . '</span>' : '') . '</div>';
}

/** Single image (media library) picker. */
function af_image(string $name, string $label, ?int $mediaId, array $o = []): string
{
    $thumb = $mediaId ? media_url($mediaId, 480, 'wide') : null;
    $control = '<div data-image-field class="row" style="align-items:center"><input type="hidden" name="' . e($name) . '" value="' . e($mediaId) . '">'
        . '<div class="ip-preview thumb" style="width:120px;height:68px">' . ($thumb ? '<img src="' . e($thumb) . '" alt="">' : '<span class="muted small">No image</span>') . '</div>'
        . '<button class="btn btn-sm" type="button" data-pick>Choose…</button><button class="btn btn-sm btn-ghost" type="button" data-clear>Remove</button></div>';
    return af_wrap($name, $label, $control, $o);
}

/** Read a posted money string into cents (null when empty). */
function af_cents(mixed $v): ?int
{
    if ($v === null || trim((string) $v) === '') {
        return null;
    }
    $c = to_cents($v);
    if ($c === null || $c < 0) {
        throw new DomainException('Check the amounts — "' . $v . '" is not a valid price.');
    }
    return $c;
}

function af_bool(mixed $v): int
{
    return in_array($v, [1, '1', true, 'true', 'on'], true) ? 1 : 0;
}

function af_date(mixed $v): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $v = str_replace('T', ' ', $v);
    return strtotime($v) ? date(strlen($v) > 10 ? 'Y-m-d H:i:s' : 'Y-m-d', strtotime($v)) : null;
}
