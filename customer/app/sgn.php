<?php
/**
 * Customer signs on the engineer's device (GAS-CP-R97-057).
 * A member of staff opens a submitted service report or function check in "signing" view (?p=sign_onsite&doc=fsr|fc&id=…):
 * a clean preview without the portal menu, the signer's name and capacity, a remark and a signature pad. The document is
 * accepted (or sent back) in the name the signer typed; who presented the device, when and from which address is recorded.
 */
declare(strict_types=1);

const SGN_DOCS = ['fsr', 'fc'];

function sgn_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM fsr_reports LIKE 'accepted_via'"); } catch (Throwable $e) { $h = false; } } return $h; }
function sgn_ip(): string { return mb_substr(trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]), 0, 64); }
/** The document, when the signed-in member of staff may see it. */
function sgn_load(string $doc, int $id): ?array {
    if (!is_staff() || !in_array($doc, SGN_DOCS, true) || $id <= 0) return null;
    if ($doc === 'fsr') { $r = fsr_load($id); if (!$r || ($r['kind'] ?? 'internal') === 'external') return null; return ['doc' => 'fsr', 'id' => $id, 'r' => $r, 'no' => (string)$r['report_no'], 'title' => t('fsr.title'), 'status' => (string)$r['status'], 'pdf' => (int)($r['pdf_file_id'] ?? 0), 'back' => url('fsr', ['id' => $id])]; }
    if (!function_exists('fc_has') || !fc_has()) return null; $r = fc_load($id); if (!$r) return null;
    return ['doc' => 'fc', 'id' => $id, 'r' => $r, 'no' => (string)$r['report_no'], 'title' => (string)(($r['tpl']['title'] ?? '') ?: t('fc.title')), 'status' => (string)$r['status'], 'pdf' => (int)($r['pdf_file_id'] ?? 0), 'back' => url('fc', ['id' => $id])];
}
/** Signing on the device is offered for a submitted document, once the step of this release has run. */
function sgn_can(?array $D): bool { return $D !== null && sgn_has() && $D['status'] === 'submitted'; }
function sgn_button(string $doc, int $id, string $status): string { return is_staff() && sgn_has() && $status === 'submitted' ? '<a class="btn btn--gold" href="' . url('sign_onsite', ['doc' => $doc, 'id' => $id]) . '">✍ ' . tt('sgn.btn') . '</a>' : ''; }
/** Text for the documents: how the acceptance was given. */
function sgn_via_label(?string $via): string { return $via === 'on_site' ? 'Signed on site / Υπογραφή επί τόπου' : ($via === 'email' ? 'Accepted by e-mail / Αποδοχή με e-mail' : 'Accepted electronically / Αποδοχή ηλεκτρονικά'); }

/** The signer's decision, given on the device of a member of staff. Returns 'accepted' or 'disputed'. */
function sgn_decide(string $doc, int $id, string $decision, string $name, string $role, string $remark, string $sig, bool $read): string {
    $u = current_user(); if (!$u || !is_staff()) throw new RuntimeException(t('err.noaccess')); $D = sgn_load($doc, $id); if (!$D) throw new RuntimeException(t('err.notfound'));
    if (!sgn_can($D)) throw new RuntimeException(t('fsr.not_pending'));
    $r = $D['r']; $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 90); $role = mb_substr(trim(preg_replace('/\s+/u', ' ', $role)), 0, 80); $remark = mb_substr(trim($remark), 0, 2000);
    if (mb_strlen($name) < 3) throw new RuntimeException(t('sgn.name_needed'));
    $by = t('sgn.ev_by', ['n' => $name . ($role !== '' ? ', ' . $role : ''), 'u' => (string)$u['full_name']]); $ip = sgn_ip() ?: null; $tbl = $doc === 'fsr' ? 'fsr_reports' : 'fc_reports';
    if ($decision === 'disputed') {
        if ($remark === '') throw new RuntimeException(t('fsr.remark_needed'));
        q("UPDATE {$tbl} SET status = 'disputed', customer_remark = ? WHERE id = ?", [$remark, $id]);
        if ($doc === 'fsr') { fsr_event($id, 'disputed', $remark . ' — ' . $by); fsr_generate_documents($id); } else { fc_event($id, 'disputed', $remark . ' — ' . $by); fc_generate_documents($id); }
        return 'disputed';
    }
    if ($decision !== 'accepted') throw new RuntimeException(t('err.notfound'));
    if (!$read) throw new RuntimeException(t('sgn.read_needed'));
    $signId = $doc === 'fsr' ? fsr_store_signature($sig, $r, 'customer') : fc_store_signature($sig, $r, 'customer');
    if (!$signId) throw new RuntimeException(t('sgn.sign_needed'));
    if ($doc === 'fsr') {
        q("UPDATE fsr_reports SET status = 'accepted', accepted_by = ?, accepted_name = ?, accepted_role = ?, accepted_via = 'on_site', accepted_at = NOW(), accepted_ip = ?, customer_remark = ?, customer_sign_id = ? WHERE id = ? AND status = 'submitted'", [(int)$u['id'], $name, $role ?: null, $ip, $remark ?: null, $signId, $id]);
        fsr_event($id, 'accepted', trim($by . ($remark !== '' ? ' — ' . $remark : ''))); fsr_generate_documents($id);
    } else {
        q("UPDATE fc_reports SET status = 'accepted', accepted_by = ?, accepted_name = ?, accepted_role = ?, accepted_via = 'on_site', accepted_at = NOW(), accepted_ip = ?, customer_remark = ?, customer_name = ?, customer_sign_id = ? WHERE id = ? AND status = 'submitted'", [(int)$u['id'], $name, $role ?: null, $ip, $remark ?: null, $name, $signId, $id]);
        fc_event($id, 'accepted', trim($by . ($remark !== '' ? ' — ' . $remark : ''))); fc_generate_documents($id);
    }
    return 'accepted';
}
function sgn_handle_action(string $action): bool {
    if ($action !== 'sgn_decide') return false; require_staff();
    $doc = (string)($_POST['doc'] ?? ''); $id = (int)($_POST['id'] ?? 0);
    try { $st = sgn_decide($doc, $id, ($_POST['decision'] ?? '') === 'disputed' ? 'disputed' : 'accepted', (string)($_POST['signer_name'] ?? ''), (string)($_POST['signer_role'] ?? ''), (string)($_POST['remark'] ?? ''), (string)($_POST['signature'] ?? ''), !empty($_POST['read'])); }
    catch (RuntimeException $e) { flash('bad', $e->getMessage()); redirect('sign_onsite', ['doc' => $doc, 'id' => $id, '_r' => uniqid()], '#sign'); }
    redirect('sign_onsite', ['doc' => $doc, 'id' => $id, 'done' => $st, '_r' => uniqid()]);
    return true;
}
