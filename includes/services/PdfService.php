<?php
/**
 * HTML → PDF via Dompdf (installed by Composer during deploy).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfService
{
    public static function available(): bool
    {
        return class_exists(Dompdf::class);
    }

    /** Render a template from templates/pdf/<name>.php to PDF bytes. */
    public static function render(string $template, array $vars): string
    {
        $html = self::html($template, $vars);
        if (!self::available()) {
            throw new RuntimeException('PDF library not installed (run composer install).');
        }
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->setChroot([GX_ROOT . '/assets', GX_ROOT . '/templates']);
        $options->set('tempDir', GX_ROOT . '/storage/cache');
        $options->set('fontCache', GX_ROOT . '/storage/cache');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /** Render the HTML only (useful for previews and tests). */
    public static function html(string $template, array $vars): string
    {
        $file = GX_ROOT . '/templates/pdf/' . basename($template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('PDF template missing: ' . $template);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** Send PDF bytes to the browser. */
    public static function stream(string $bytes, string $filename, bool $download = false): never
    {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }
}
