<?php
/**
 * Safe uploads.
 *  - store_private_upload(): proof of payment / VAT documents → storage/private (never web-accessible).
 *  - store_media_image():    admin images → uploads/media as .webp in several widths + 16:9 focal crops.
 * Every image is decoded and re-encoded with GD (strips EXIF/metadata and anything hidden in the file).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

/** Validate a $_FILES entry. Returns [tmpPath, mime] or throws DomainException. */
function upload_check(?array $file, array $allowedMimes, int $maxBytes): array
{
    if (!$file || !isset($file['error']) || is_array($file['error'])) {
        throw new DomainException('Please choose a file to upload.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new DomainException(match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large.',
            UPLOAD_ERR_NO_FILE => 'Please choose a file to upload.',
            default => 'The upload failed. Please try again.',
        });
    }
    if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxBytes) {
        throw new DomainException('Files may be at most ' . round($maxBytes / 1048576) . ' MB.');
    }
    if (!is_uploaded_file($file['tmp_name']) && !defined('GX_TESTING')) {
        throw new DomainException('Invalid upload.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!in_array($mime, $allowedMimes, true)) {
        throw new DomainException('That file type is not allowed.');
    }
    if (str_starts_with($mime, 'image/')) {
        // Read the size from the header BEFORE decoding: a small file can unpack to gigabytes (image bomb)
        $dim = @getimagesize($file['tmp_name']);
        if (!$dim || $dim[0] < 1 || $dim[1] < 1) {
            throw new DomainException('That image could not be read.');
        }
        if ($dim[0] > GX_IMAGE_MAX_SIDE || $dim[1] > GX_IMAGE_MAX_SIDE || $dim[0] * $dim[1] > GX_IMAGE_MAX_PIXELS) {
            throw new DomainException('That image is too large — please send a photo of at most 40 megapixels.');
        }
    }
    return [$file['tmp_name'], $mime];
}

/** Load an image with GD and apply EXIF orientation. */
function gd_load(string $path, string $mime): GdImage
{
    $im = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => false,
    };
    if (!$im) {
        throw new DomainException('That image could not be read.');
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $rot = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($rot !== 0) {
            $r = imagerotate($im, $rot, 0);
            if ($r) {
                $im = $r;
            }
        }
    }
    if (!imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    imagealphablending($im, true);
    imagesavealpha($im, true);
    return $im;
}

function gd_save(GdImage $im, string $absPath, int $quality = 82): void
{
    $dir = dirname($absPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (function_exists('imagewebp') && str_ends_with($absPath, '.webp')) {
        imagewebp($im, $absPath, $quality);
    } else {
        imagejpeg($im, $absPath, $quality);
    }
}

/** Resize keeping aspect ratio (never upscales). */
function gd_resize(GdImage $src, int $maxW): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= $maxW) {
        $tw = $w;
        $th = $h;
    } else {
        $tw = $maxW;
        $th = (int) round($h * $maxW / $w);
    }
    $t = imagecreatetruecolor($tw, $th);
    imagealphablending($t, false);
    imagesavealpha($t, true);
    imagecopyresampled($t, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    return $t;
}

/** 16:9 crop around a focal point (0..1), output width $tw. */
function gd_crop_wide(GdImage $src, int $tw, float $fx = .5, float $fy = .5): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $ratio = 16 / 9;
    if ($w / $h > $ratio) {
        $cw = (int) round($h * $ratio);
        $ch = $h;
    } else {
        $cw = $w;
        $ch = (int) round($w / $ratio);
    }
    $cx = (int) max(0, min($w - $cw, round($fx * $w - $cw / 2)));
    $cy = (int) max(0, min($h - $ch, round($fy * $h - $ch / 2)));
    $tw = min($tw, $cw);
    $th = (int) round($tw / $ratio);
    $t = imagecreatetruecolor($tw, $th);
    imagealphablending($t, false);
    imagesavealpha($t, true);
    imagecopyresampled($t, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);
    return $t;
}

/**
 * Store a private upload (POP / VAT certificate). Images are re-encoded to webp, PDFs are checked.
 * Returns ['path' => 'storage/private/...', 'mime' => ...].
 */
function store_private_upload(?array $file, string $subdir, array $allowedMimes = GX_POP_MIMES, int $maxBytes = GX_POP_MAX_BYTES): array
{
    [$tmp, $mime] = upload_check($file, $allowedMimes, $maxBytes);
    $dir = 'storage/private/' . trim($subdir, '/') . '/' . date('Y/m');
    $name = random_token(16);
    if ($mime === 'application/pdf') {
        $head = (string) file_get_contents($tmp, false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw new DomainException('That PDF file is not valid.');
        }
        $rel = $dir . '/' . $name . '.pdf';
        if (!is_dir(GX_ROOT . '/' . $dir)) {
            mkdir(GX_ROOT . '/' . $dir, 0755, true);
        }
        if (!(defined('GX_TESTING') ? copy($tmp, GX_ROOT . '/' . $rel) : move_uploaded_file($tmp, GX_ROOT . '/' . $rel))) {
            throw new RuntimeException('Could not save the file.');
        }
        return ['path' => $rel, 'mime' => 'application/pdf'];
    }
    $im = gd_load($tmp, $mime);
    $im = gd_resize($im, 2000);
    $ext = function_exists('imagewebp') ? 'webp' : 'jpg';
    $rel = $dir . '/' . $name . '.' . $ext;
    gd_save($im, GX_ROOT . '/' . $rel, 85);
    return ['path' => $rel, 'mime' => $ext === 'webp' ? 'image/webp' : 'image/jpeg'];
}

/**
 * Store an admin image as a media library item.
 * Generates: orig (480/960/1600 wide, original aspect) + wide (16:9 focal crops at 480/960/1600).
 * Returns the new media id.
 */
function store_media_image(?array $file, ?string $alt = null, float $fx = .5, float $fy = .5): int
{
    [$tmp, $mime] = upload_check($file, GX_IMAGE_MIMES, GX_UPLOAD_MAX_BYTES);
    $im = gd_load($tmp, $mime);
    $base = 'uploads/media/' . date('Y/m') . '/' . random_token(10);
    $variants = media_generate_variants($im, $base, $fx, $fy);
    return db_insert('INSERT INTO media (base_path, original_name, mime, width, height, size_bytes, focal_x, focal_y, variants, alt_text, created_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
        $base, mb_substr((string) ($file['name'] ?? ''), 0, 255), function_exists('imagewebp') ? 'image/webp' : 'image/jpeg',
        imagesx($im), imagesy($im), (int) $file['size'], $fx, $fy, json_encode($variants, JSON_UNESCAPED_SLASHES),
        $alt ? mb_substr($alt, 0, 255) : null, current_admin()['id'] ?? null,
    ]);
}

/** (Re)generate all sizes for an image. Returns the variants map. */
function media_generate_variants(GdImage $im, string $base, float $fx, float $fy): array
{
    $ext = function_exists('imagewebp') ? 'webp' : 'jpg';
    $variants = ['orig' => [], 'wide' => []];
    foreach (GX_IMAGE_WIDTHS as $w) {
        if ($w > imagesx($im) && $w !== GX_IMAGE_WIDTHS[0]) {
            continue;
        }
        $r = gd_resize($im, $w);
        $p = "{$base}-{$w}.{$ext}";
        gd_save($r, GX_ROOT . '/' . $p);
        $variants['orig'][imagesx($r)] = $p;
        $c = gd_crop_wide($im, $w, $fx, $fy);
        $p2 = "{$base}-{$w}x" . (int) round($w * 9 / 16) . ".{$ext}";
        gd_save($c, GX_ROOT . '/' . $p2);
        $variants['wide'][imagesx($c)] = $p2;
    }
    ksort($variants['orig']);
    ksort($variants['wide']);
    return $variants;
}

/** Re-crop an existing media item to a new focal point (from the largest original variant). */
function media_refocus(int $mediaId, float $fx, float $fy): void
{
    $m = db_one('SELECT * FROM media WHERE id = ?', [$mediaId]);
    if (!$m) {
        throw new DomainException('Image not found.');
    }
    $variants = json_decode((string) $m['variants'], true) ?: [];
    $orig = $variants['orig'] ?? [];
    if (!$orig) {
        throw new DomainException('Original image missing.');
    }
    krsort($orig);
    $src = GX_ROOT . '/' . reset($orig);
    $im = gd_load($src, (new finfo(FILEINFO_MIME_TYPE))->file($src) ?: 'image/webp');
    $fx = max(0, min(1, $fx));
    $fy = max(0, min(1, $fy));
    $wide = [];
    foreach (GX_IMAGE_WIDTHS as $w) {
        $c = gd_crop_wide($im, $w, $fx, $fy);
        $p = $m['base_path'] . "-{$w}x" . (int) round($w * 9 / 16) . '.' . pathinfo((string) reset($orig), PATHINFO_EXTENSION);
        gd_save($c, GX_ROOT . '/' . $p);
        $wide[imagesx($c)] = $p;
    }
    ksort($wide);
    $variants['wide'] = $wide;
    db_exec('UPDATE media SET focal_x = ?, focal_y = ?, variants = ? WHERE id = ?', [$fx, $fy, json_encode($variants, JSON_UNESCAPED_SLASHES), $mediaId]);
}

/** Stream a private file (after the caller checked access). */
function stream_private_file(string $relPath, string $downloadName): never
{
    $abs = realpath(GX_ROOT . '/' . $relPath);
    $root = realpath(GX_ROOT . '/storage/private');
    if (!$abs || !$root || !str_starts_with($abs, $root) || !is_file($abs)) {
        http_response_code(404);
        exit('File not found.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    // Shown inline (handy for checking a POP), but sandboxed: an uploaded PDF/image can never run script on our domain
    header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'; object-src \'self\'');
    readfile($abs);
    exit;
}
