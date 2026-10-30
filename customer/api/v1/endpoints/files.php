<?php
/** Photo upload (multipart, one file per call, after the record was accepted) and document streaming through the portal's file functions. */
declare(strict_types=1);
function upload_photo(array $u): void {
    $ref = (string)($_POST['record_id'] ?? $_POST['record_uuid'] ?? ''); $task = (int)($_POST['task'] ?? 1); $caption = mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 160) ?: null;
    $f = $_FILES['photo'] ?? null; if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new ApiException(422, 'photo missing');
    if ($f['size'] > 2 * 1024 * 1024) throw new ApiException(413, 'photo too large (compress on device)');
    $info = @getimagesize($f['tmp_name']); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) throw new ApiException(422, 'photo must be JPEG or PNG');
    // CS-06: re-encode so no EXIF survives, keep it an uploaded file for cp_store_file()
    $img = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($f['tmp_name']) : @imagecreatefrompng($f['tmp_name']); if (!$img) throw new ApiException(422, 'photo unreadable');
    imagejpeg($img, $f['tmp_name'], 85); imagedestroy($img); $f['type'] = 'image/jpeg'; $f['name'] = preg_replace('/\.[^.]+$/', '', (string)$f['name']) . '.jpg'; $f['size'] = filesize($f['tmp_name']);
    Response::json(Portal::storePhoto($u, $ref, $f, $task, $caption));
}
function stream_document(array $u, int $id): void {
    $f = one('SELECT f.* FROM sp_files f WHERE f.id = ?', [$id]); if (!$f) throw new ApiException(404, 'Document not found');
    $pids = array_map(fn($p) => (int)$p['id'], Portal::projects()); if (!in_array((int)$f['project_id'], $pids, true) && !is_cp_admin()) throw new ApiException(403, 'Not your project');
    if (str_starts_with((string)$f['kind'], 'offer') || in_array($f['kind'], ['proforma', 'acc_doc', 'bg_doc'], true)) throw new ApiException(403, 'Not available in the app');   // CS-02
    header_remove('Content-Type'); cp_stream_file($id); exit;
}
