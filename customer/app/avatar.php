<?php
/**
 * User avatars — a square JPEG per account under storage/avatars/<id>.jpg,
 * uploaded on the Account page (resized to 256 px with GD). No database
 * column: the file's presence is the flag.                     GAS-CP-R15-003
 */
function avatar_dir(): string { return cp_storage() . '/avatars'; }
function avatar_path(int $uid): string { return avatar_dir() . '/' . $uid . '.jpg'; }
function avatar_has(int $uid): bool { return $uid > 0 && is_file(avatar_path($uid)); }
/** URL of the user's picture (cache-busted by file time), or null when they have none. */
function avatar_url(int $uid): ?string { return avatar_has($uid) ? url('avatar', ['id' => $uid, 'v' => (string) filemtime(avatar_path($uid))]) : null; }
/** The round picture, or the initials disc when there is no picture. $size in px. */
function avatar_html(?int $uid, string $name, int $size = 32, string $class = ''): string {
    $u = $uid ? avatar_url($uid) : null;
    $style = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . max(10, (int) round($size * 0.4)) . 'px;';
    if ($u) return '<img class="ava ' . e($class) . '" src="' . e($u) . '" alt="' . e($name) . '" title="' . e($name) . '" style="' . $style . '" loading="lazy">';
    return '<span class="ava ava--txt ' . e($class) . '" title="' . e($name) . '" style="' . $style . '">' . e(initials($name)) . '</span>';
}
/** Store an uploaded picture for the user: JPEG/PNG/WebP/GIF, centre-cropped to a 256 px square. */
function avatar_store(int $uid, array $file): void {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException(t('err.upload'));
    if ((int)$file['size'] > 8 * 1024 * 1024) throw new RuntimeException(t('av.too_big'));
    $bytes = (string) file_get_contents($file['tmp_name']);
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) throw new RuntimeException(t('av.bad_type'));
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException(t('av.no_gd'));
    $im = @imagecreatefromstring($bytes); if (!$im) throw new RuntimeException(t('av.bad_type'));
    // honour EXIF orientation from phones
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']); $o = (int)($exif['Orientation'] ?? 1);
        if ($o === 3) $im = imagerotate($im, 180, 0); elseif ($o === 6) $im = imagerotate($im, -90, 0); elseif ($o === 8) $im = imagerotate($im, 90, 0);
    }
    $w = imagesx($im); $h = imagesy($im); $side = min($w, $h); $sx = (int)(($w - $side) / 2); $sy = (int)(($h - $side) / 2);
    $out = imagecreatetruecolor(256, 256); $white = imagecolorallocate($out, 255, 255, 255); imagefill($out, 0, 0, $white);
    imagecopyresampled($out, $im, 0, 0, $sx, $sy, 256, 256, $side, $side);
    if (!is_dir(avatar_dir())) @mkdir(avatar_dir(), 0755, true);
    if (!imagejpeg($out, avatar_path($uid), 88)) throw new RuntimeException(t('err.upload'));
    imagedestroy($im); imagedestroy($out);
}
function avatar_remove(int $uid): void { if (avatar_has($uid)) @unlink(avatar_path($uid)); }
/** Stream a picture; everyone logged in may see every avatar (they appear next to names). */
function avatar_stream(int $uid): void {
    if (!avatar_has($uid)) { http_response_code(404); exit; }
    $p = avatar_path($uid);
    header('Content-Type: image/jpeg'); header('Content-Length: ' . filesize($p)); header('Cache-Control: private, max-age=86400');
    readfile($p); exit;
}
