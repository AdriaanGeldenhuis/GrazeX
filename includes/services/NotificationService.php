<?php
/**
 * Customer emails (from editable templates) and admin alerts.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class NotificationService
{
    /**
     * Queue a templated email. Template subject/body live in email_templates (editable in Admin);
     * {{placeholders}} are replaced from $vars (values are escaped, except keys ending in _html).
     */
    public static function email(string $templateKey, string $toEmail, ?string $toName, array $vars = [],
                                 ?int $divisionId = null, array $attachments = [], ?array $button = null, bool $sendNow = true): ?int
    {
        $tpl = db_one('SELECT * FROM email_templates WHERE template_key = ? AND is_active = 1', [$templateKey]);
        if (!$tpl) {
            gx_log('warning', 'Missing/inactive email template: ' . $templateKey);
            return null;
        }
        $division = $divisionId ? division($divisionId) : null;
        $vars += ['site_name' => setting('site_name', 'GrazeX'), 'division' => $division['name'] ?? 'GrazeX'];

        $subject = self::fill($tpl['subject'], $vars, false);
        $bodyMd = self::fill($tpl['body'], $vars, true);
        $html = self::layout($subject, markdown($bodyMd), $division, $button);
        $id = MailService::queue($toEmail, $toName, $subject, $html, null, $attachments, $divisionId);
        if ($sendNow) {
            MailService::sendNow($id);
        }
        return $id;
    }

    /** In-admin notification + email to the admin inbox. */
    public static function admin(string $type, string $title, ?string $body = null, ?string $link = null, ?int $divisionId = null, bool $email = true): void
    {
        db_exec('INSERT INTO admin_notifications (division_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)',
            [$divisionId, $type, mb_substr($title, 0, 191), $body ? mb_substr($body, 0, 500) : null, $link]);
        $to = setting('low_stock_email') ?: (string) env('mail.admin_email', '');
        if ($email && $to !== '') {
            $html = self::layout($title, '<p>' . nl2br(e((string) $body)) . '</p>', $divisionId ? division($divisionId) : null,
                $link ? ['label' => 'Open in admin', 'url' => app_url($link)] : null);
            $id = MailService::queue($to, 'GrazeX admin', '[GrazeX] ' . $title, $html, null, [], $divisionId);
            MailService::sendNow($id);
        }
    }

    /** Replace {{var}} placeholders. Markdown bodies keep raw values for *_md / *_html vars. */
    private static function fill(string $text, array $vars, bool $markdownBody): string
    {
        return (string) preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', static function ($m) use ($vars, $markdownBody) {
            $key = $m[1];
            $val = (string) ($vars[$key] ?? '');
            if (!$markdownBody || str_ends_with($key, '_md')) {
                return $val;
            }
            // Links become clickable markdown links
            if (($key === 'link' || str_ends_with($key, '_link') || str_ends_with($key, '_url')) && preg_match('#^https?://\S+$#', $val)) {
                return '[' . $val . '](' . $val . ')';
            }
            // Neutralise markdown control characters in user-supplied values
            return str_replace(['*', '[', ']'], ['∗', '(', ')'], $val);
        }, $text);
    }

    /** Branded HTML email wrapper (inline styles — email clients ignore <style> blocks). */
    public static function layout(string $title, string $contentHtml, ?array $division = null, ?array $button = null): string
    {
        $isRanch = ($division['code'] ?? '') === 'ranch';
        $accent = $isRanch ? '#86d51a' : '#ff7a00';
        $logo = app_url($isRanch ? '/assets/img/brand/ranch-logo-480.webp' : '/assets/img/brand/feeds-logo-480.webp');
        $logoPng = app_url('/assets/img/brand/og-' . ($isRanch ? 'ranch' : 'feeds') . '.jpg');
        $btn = '';
        if ($button) {
            $btn = '<p style="margin:28px 0 8px"><a href="' . e($button['url']) . '" style="display:inline-block;background:' . $accent
                 . ';color:#111;font-weight:800;text-decoration:none;padding:14px 22px;border-radius:12px">' . e($button['label']) . '</a></p>';
        }
        $footer = e($division['legal_name'] ?? ($division['name'] ?? 'GrazeX'))
                . (!empty($division['vat_registered']) && !empty($division['vat_no']) ? ' · VAT ' . e($division['vat_no']) : '')
                . (setting('contact_phone') ? ' · ' . e(setting('contact_phone')) : '')
                . (setting('contact_email') ? ' · ' . e(setting('contact_email')) : '');
        $contentHtml = str_replace(['<p>', '<ul>', '<li>', '<h2>', '<h3>', '<strong>'], [
            '<p style="margin:0 0 14px;line-height:1.6;color:#d9ded9">', '<ul style="margin:0 0 14px;padding-left:20px;color:#d9ded9">',
            '<li style="margin:4px 0">', '<h2 style="color:#fff;font-size:20px;margin:18px 0 10px">', '<h3 style="color:#fff;font-size:17px;margin:16px 0 8px">',
            '<strong style="color:#fff">'], $contentHtml);

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title></head>'
            . '<body style="margin:0;background:#050606;font-family:Segoe UI,Arial,sans-serif;color:#f4f4ef">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#050606"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#0f1210;border:1px solid #232823;border-radius:18px">'
            . '<tr><td style="padding:24px 28px 8px"><img src="' . e($logoPng) . '" alt="GrazeX" width="200" style="display:block;max-width:200px;height:auto;border-radius:8px"></td></tr>'
            . '<tr><td style="padding:8px 28px 4px"><div style="height:3px;width:56px;background:' . $accent . ';border-radius:3px"></div>'
            . '<h1 style="font-size:22px;line-height:1.3;color:#fff;margin:16px 0 14px">' . e($title) . '</h1></td></tr>'
            . '<tr><td style="padding:0 28px 24px;font-size:15px">' . $contentHtml . $btn . '</td></tr>'
            . '<tr><td style="padding:18px 28px;border-top:1px solid #232823;font-size:12px;color:#7f8781">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
