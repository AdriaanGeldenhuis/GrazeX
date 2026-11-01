<?php
/**
 * Outgoing email via SMTP (PHPMailer). Every email goes through the email_queue table so that
 * Xneelo's 500-per-hour limit is never exceeded (setting: email_hourly_cap).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class MailService
{
    /** Queue an email. Returns the queue id. */
    public static function queue(string $toEmail, ?string $toName, string $subject, string $html, ?string $text = null,
                                 array $attachments = [], ?int $divisionId = null): int
    {
        return db_insert('INSERT INTO email_queue (division_id, to_email, to_name, subject, body_html, body_text, attachments) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $divisionId, $toEmail, $toName, mb_substr($subject, 0, 255), $html, $text,
            $attachments ? json_encode(array_values($attachments), JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /** How many more emails may be sent this hour. */
    public static function capacity(): int
    {
        $cap = setting_int('email_hourly_cap', 300);
        $sent = (int) db_val('SELECT COUNT(*) FROM email_queue WHERE status = ? AND sent_at > NOW() - INTERVAL 1 HOUR', ['sent']);
        return max(0, $cap - $sent);
    }

    /** Send up to $limit queued emails. Returns number sent. */
    public static function processQueue(int $limit = 20): int
    {
        $limit = min($limit, self::capacity());
        if ($limit <= 0 || !self::configured()) {
            return 0;
        }
        $rows = db_all("SELECT * FROM email_queue WHERE status = 'queued' AND send_after <= NOW() AND attempts < 5 ORDER BY id LIMIT " . (int) $limit);
        $sent = 0;
        foreach ($rows as $row) {
            // Claim the row so parallel runs never double-send
            if (db_exec("UPDATE email_queue SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'queued'", [$row['id']]) !== 1) {
                continue;
            }
            try {
                self::send($row);
                db_exec("UPDATE email_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?", [$row['id']]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                db_exec('UPDATE email_queue SET status = ?, last_error = ?, send_after = NOW() + INTERVAL ? MINUTE WHERE id = ?',
                    [$attempts >= 5 ? 'failed' : 'queued', mb_substr($e->getMessage(), 0, 500), 5 * $attempts, $row['id']]);
                gx_log('warning', 'Email send failed', ['id' => $row['id'], 'error' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    /** Try to send a single queued email right away (used after checkout so the customer gets it instantly). */
    public static function sendNow(int $queueId): void
    {
        if (!self::configured() || self::capacity() <= 0) {
            return;
        }
        $row = db_one("SELECT * FROM email_queue WHERE id = ? AND status = 'queued'", [$queueId]);
        if (!$row || db_exec("UPDATE email_queue SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'queued'", [$queueId]) !== 1) {
            return;
        }
        try {
            self::send($row);
            db_exec("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?", [$queueId]);
        } catch (Throwable $e) {
            db_exec("UPDATE email_queue SET status = 'queued', last_error = ?, send_after = NOW() + INTERVAL 5 MINUTE WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $queueId]);
            gx_log('warning', 'Immediate email failed', ['id' => $queueId, 'error' => $e->getMessage()]);
        }
    }

    public static function configured(): bool
    {
        return class_exists(PHPMailer::class) && (string) env('mail.host', '') !== '' && (string) env('mail.user', '') !== '';
    }

    /** @throws MailException */
    private static function send(array $row): void
    {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string) env('mail.host');
        $m->Port = (int) env('mail.port', 465);
        $m->SMTPAuth = true;
        $m->Username = (string) env('mail.user');
        $m->Password = (string) env('mail.pass');
        $m->SMTPSecure = env('mail.secure', 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $m->Timeout = 20;
        $m->CharSet = 'UTF-8';
        $m->setFrom((string) env('mail.from_email'), (string) env('mail.from_name', 'GrazeX'));
        $m->addReplyTo((string) (setting('contact_email') ?: env('mail.from_email')), (string) env('mail.from_name', 'GrazeX'));
        $m->addAddress($row['to_email'], (string) ($row['to_name'] ?? ''));
        $m->Subject = $row['subject'];
        $m->isHTML(true);
        $m->Body = $row['body_html'];
        $m->AltBody = $row['body_text'] ?: trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</h2>', '</li>'], "\n", $row['body_html']))));
        foreach (json_decode((string) $row['attachments'], true) ?: [] as $att) {
            $path = GX_ROOT . '/' . ltrim((string) ($att['path'] ?? ''), '/');
            if (is_file($path) && str_starts_with(realpath($path) ?: '', realpath(GX_ROOT . '/storage') ?: '//')) {
                $m->addAttachment($path, (string) ($att['name'] ?? basename($path)));
            }
        }
        $m->send();
    }
}
