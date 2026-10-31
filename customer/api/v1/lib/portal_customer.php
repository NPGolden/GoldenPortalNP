<?php
/**
 * Golden A/S customer app — read model and writes for the customer (owner) account.           GAS-CP-R68-001
 * Third application of the mobile API (X-App: customer). Every write goes through the portal's own functions
 * (fsr_customer_decide, oa_decide, of_decide, tk_comment, tk_store_uploads, tk_set_status, tk_save_from_post) and every
 * read through the same customer-scoped queries the web portal uses (scope_sql → customer_id, visibility = external,
 * drafts hidden). Shapes are those of the app contract (status names of the app, portal status kept as portal_status).
 */
declare(strict_types=1);
final class PortalCustomer {
    /** Who may sign in to the customer app: a customer account of an active customer. Staff use the other apps. */
    public static function allowed(array $u): void {
        if (($u['role'] ?? 'customer') !== 'customer' || empty($u['customer_id'])) throw new ApiException(403, 'Customer account required');
    }
    /* ------------------------------------------------------------------ /me */
    public static function me(array $u): array {
        $c = one('SELECT id, name FROM customers WHERE id = ?', [(int)$u['customer_id']]);
        $br = function_exists('br_user') ? br_user($u) : (function_exists('br_default') ? br_default() : ['code' => 'GAS', 'name' => 'Golden A/S']);
        return ['user' => ['id' => (int)$u['id'], 'name' => (string)$u['full_name'], 'first_name' => explode(' ', trim((string)$u['full_name']))[0], 'email' => (string)$u['email'], 'company' => (string)($c['name'] ?? ''), 'title' => (string)($u['title'] ?? ''), 'locale' => (string)($u['locale'] ?? 'en'), 'totp_enabled' => !empty($u['totp_enabled'])],
            'vessels' => self::vessels($u), 'devices' => self::devices($u), 'branch' => ['code' => (string)($br['code'] ?? 'GAS'), 'name' => (string)($br['name'] ?? 'Golden A/S')],
            'languages' => array_keys(LOCALES), 'min_app_version' => api_setting('api_min_version_customer', API_APPS['customer']),
            'features' => ['tickets' => tk_has(), 'offers' => of_has(), 'approvals' => true, 'reports' => true, 'offer_2fa' => self::offerNeeds2fa()]];
    }
    public static function devices(array $u): array {
        return array_map(fn($d) => ['id' => (int)$d['id'], 'device_id' => (string)$d['client_device_id'], 'platform' => (string)$d['platform'], 'model' => (string)$d['model'], 'app_version' => (string)$d['app_version'], 'last_seen_at' => (string)$d['last_seen_at'], 'this' => (int)$d['id'] === (int)($u['device_id'] ?? 0)],
            all('SELECT id, client_device_id, platform, model, app_version, last_seen_at FROM mob_devices WHERE user_id = ? AND app_id = "customer" AND revoked_at IS NULL ORDER BY last_seen_at DESC', [(int)$u['id']]));
    }
    /** Revoke one of my own devices (Account › Devices); the refresh tokens of that device die with it. */
    public static function revokeDevice(array $u, int $id): void {
        $d = one('SELECT id FROM mob_devices WHERE id = ? AND user_id = ? AND app_id = "customer"', [$id, (int)$u['id']]); if (!$d) throw new ApiException(404, 'Device not found');
        q('UPDATE mob_devices SET revoked_at = NOW() WHERE id = ?', [$id]); q('UPDATE mob_refresh_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL', [$id]);
        if (function_exists('login_log')) login_log((int)$u['id'], (string)$u['email'], 'logout');
    }
    public static function setLocale(array $u, string $loc): void { if (locale_valid($loc)) q('UPDATE customer_users SET locale = ? WHERE id = ?', [$loc, (int)$u['id']]); }
    /** Step-up for legally relevant actions: the account's authenticator code when 2FA is on (same rule as Golden PM, CS-28). */
    public static function stepUp(array $u, ?string $totp): void {
        if (empty($u['totp_enabled'])) return;
        $secret = (string)($u['totp_secret'] ?? ''); $code = preg_replace('/\D/', '', (string)($totp ?? ''));
        if ($secret === '' || strlen($code) !== 6 || !function_exists('totp_verify') || !totp_verify($secret, $code)) throw new ApiException(401, 'Authenticator code required', ['totp' => true]);
    }
    /** D-8 (R68-001): decisions on offers from the app require two-factor authentication on the customer account (Settings: api_customer_offer_2fa, default on). */
    public static function offerNeeds2fa(): bool { return api_setting('api_customer_offer_2fa', '1') !== '0'; }
    private static function ip(): string { return trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]); }

    /* ------------------------------------------------------------------ Home / Action Centre */
    public static function home(array $u): array {
        $items = [];
        foreach (self::reports($u, 'sent_for_signature') as $r) $items[] = ['type' => 'sign', 'id' => $r['id'], 'ref' => $r['doc_no'] . ' ' . $r['revision'], 'title' => $r['title'], 'vessel' => $r['vessel'], 'at' => $r['issued_at']];
        $appr = array_values(array_filter(self::approvals($u), fn($a) => $a['status'] === 'sent_for_approval'));
        foreach ($appr as $a) $items[] = ['type' => 'approve', 'id' => $a['id'], 'ref' => $a['doc_no'] . ' ' . $a['revision'], 'title' => $a['title'], 'vessel' => $a['vessel'], 'at' => $a['issued_at']];
        $offers = array_values(array_filter(self::offers($u), fn($o) => $o['status'] === 'issued'));
        foreach ($offers as $o) $items[] = ['type' => 'offer', 'id' => $o['id'], 'ref' => $o['offer_no'] . ' ' . $o['revision'], 'title' => $o['title'], 'vessel' => $o['vessel'], 'at' => $o['issued_at']];
        $tickets = self::tickets($u); $waiting = array_values(array_filter($tickets, fn($t) => $t['status'] === 'waiting_customer'));
        foreach ($waiting as $t) $items[] = ['type' => 'ticket', 'id' => $t['id'], 'ref' => $t['ticket_no'], 'title' => $t['subject'], 'vessel' => $t['vessel'], 'at' => $t['updated_at']];
        usort($items, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
        return ['counters' => ['sign' => count(self::reports($u, 'sent_for_signature')), 'approve' => count($appr), 'offers' => count($offers), 'tickets' => count(array_filter($tickets, fn($t) => !in_array($t['status'], ['closed', 'resolved'], true))), 'tickets_waiting' => count($waiting)], 'items' => $items];
    }

    /* ------------------------------------------------------------------ service reports (fsr_reports) for acceptance = "sign" */
    private const FSR_STATUS = ['submitted' => 'sent_for_signature', 'accepted' => 'signed', 'disputed' => 'returned_to_gas'];
    private static function reportShape(array $r): array {
        return ['id' => (int)$r['id'], 'doc_no' => (string)$r['report_no'], 'revision' => 'R' . (int)($r['version'] ?? 0), 'type' => 'FSR',
            'title' => implode(' — ', array_filter([(string)($r['project_code'] ?? ''), (string)($r['project_name'] ?? ''), (string)($r['sub_name'] ?? '')])) ?: (string)$r['report_no'],
            'vessel' => (string)($r['vessel'] ?? $r['vessel_name'] ?? ''), 'engineer' => (string)($r['submitted_name'] ?? ''), 'report_date' => (string)($r['report_date'] ?? ''),
            'status' => self::FSR_STATUS[$r['status']] ?? (string)$r['status'], 'portal_status' => (string)$r['status'],
            'issued_at' => (string)($r['submitted_at'] ?? $r['report_date'] ?? ''), 'signed_at' => (string)($r['accepted_at'] ?? ''), 'remark' => (string)($r['customer_remark'] ?? ''), 'has_pdf' => !empty($r['pdf_file_id']),
            'signature' => $r['status'] === 'accepted' ? ['name' => (string)($r['accepted_name'] ?? ''), 'signed_at_utc' => (string)($r['accepted_at'] ?? ''), 'ip' => (string)($r['accepted_ip'] ?? ''), 'report' => (string)$r['report_no']] : null];
    }
    public static function reports(array $u, string $status = ''): array {
        $portal = array_keys(array_filter(self::FSR_STATUS, fn($s) => $status === '' || in_array($s, explode(',', $status), true)));
        if (!$portal) return [];
        [$scope, $args] = scope_sql('p'); $in = implode(',', array_fill(0, count($portal), '?'));
        $ext = fsr_ext_has() ? " AND (f.kind IS NULL OR f.kind = 'internal' OR f.owner_visible = 1)" : '';
        $rows = all("SELECT f.id, f.report_no, f.version, f.status, f.report_date, f.submitted_name, f.submitted_at, f.accepted_at, f.accepted_name, f.accepted_ip, f.customer_remark, f.pdf_file_id, p.project_code, p.name AS project_name, v.name AS vessel, s.name AS sub_name
                     FROM fsr_reports f JOIN projects p ON p.id = f.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN subprojects s ON s.id = f.subproject_id
                     WHERE {$scope} AND f.status IN ({$in}){$ext} ORDER BY FIELD(f.status,'submitted','disputed','accepted'), f.submitted_at DESC, f.id DESC LIMIT 300", array_merge($args, $portal));
        return array_map([self::class, 'reportShape'], $rows);
    }
    public static function report(array $u, int $id): array {
        $r = fsr_load($id); if (!$r || $r['status'] === 'draft') throw new ApiException(404, 'Report not found');
        $v = one('SELECT v.name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ?', [(int)$r['project_id']]);
        $r['vessel'] = (string)($v['name'] ?? $r['vessel_name'] ?? '');
        $out = self::reportShape($r);
        $out['works'] = array_map(fn($w) => ['seq' => (int)$w['seq'], 'description' => mb_substr(strip_tags((string)($w['description'] ?? '')), 0, 400), 'open_issues' => mb_substr(strip_tags((string)($w['open_issues'] ?? '')), 0, 400)], $r['works']);
        $out['summary'] = ['works' => count($r['works']), 'photos' => count($r['photos']), 'location' => (string)($r['location'] ?? ''), 'pic' => trim((string)($r['pic_name'] ?? '') . ' ' . (string)($r['pic_position'] ?? ''))];
        return $out;
    }
    public static function reportPdf(array $u, int $id): void {
        $r = fsr_load($id); if (!$r || $r['status'] === 'draft') throw new ApiException(404, 'Report not found');
        if (empty($r['pdf_file_id'])) { fsr_generate_documents($id); $r = fsr_load($id); }
        if (empty($r['pdf_file_id'])) throw new ApiException(404, 'PDF not available');
        header_remove('Content-Type'); cp_stream_file((int)$r['pdf_file_id']); exit;
    }
    /** Accept (= sign) or dispute a submitted report — the portal's own fsr_customer_decide (status, acceptance box, events, regenerated PDF). */
    public static function reportDecide(array $u, int $id, string $decision, array $p): array {
        self::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        if ($decision === 'accept' && empty($p['consent'])) throw new ApiException(422, 'Acceptance statement required');
        $sig = $decision === 'accept' ? (string)($p['signature_png'] ?? '') : null;
        if ($decision === 'accept' && !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]{200,}$#', $sig)) throw new ApiException(422, 'Signature required');
        try { fsr_customer_decide($id, $decision === 'accept' ? 'accepted' : 'disputed', (string)($p['remark'] ?? ''), $sig, 'mobile app'); }
        catch (RuntimeException $e) { throw new ApiException(409, $e->getMessage()); }
        return self::report($u, $id);
    }

    /* ------------------------------------------------------------------ owner approvals */
    private const OA_STATUS = ['submitted' => 'sent_for_approval', 'resubmitted' => 'sent_for_approval', 'approved' => 'approved', 'approved_comments' => 'approved', 'rejected' => 'rejected', 'draft' => 'draft'];
    private const OA_CODE = ['approved' => 'A', 'approved_comments' => 'B', 'rejected' => 'D'];
    public const OA_DECISION = ['A' => 'approved', 'B' => 'approved_comments', 'D' => 'rejected'];
    private static function approvalShape(array $a): array {
        return ['id' => (int)$a['id'], 'doc_no' => (string)$a['doc_no'], 'revision' => (string)$a['revision'], 'title' => (string)$a['title'], 'category' => (string)($a['kind'] ?? $a['category'] ?? ''), 'vessel' => (string)($a['vessel'] ?? ''), 'project' => (string)($a['project_code'] ?? ''), 'subproject' => trim((string)($a['sub_code'] ?? '') . ' ' . (string)($a['sub_name'] ?? '')),
            'status' => self::OA_STATUS[$a['status']] ?? (string)$a['status'], 'portal_status' => (string)$a['status'], 'code' => self::OA_CODE[$a['status']] ?? null, 'comments' => (string)($a['decision_note'] ?? $a['comments'] ?? ''),
            'issued_at' => (string)($a['submitted_at'] ?? ''), 'due_at' => (string)($a['due_at'] ?? ''), 'decided_at' => (string)($a['decided_time'] ?? $a['decided_at'] ?? ''), 'decided_by' => (string)($a['decided_name'] ?? ''), 'has_file' => !empty($a['file_id']), 'file_name' => (string)($a['original_name'] ?? ''), 'n_comments' => (int)($a['n_comments'] ?? 0)];
    }
    public static function approvals(array $u): array { return array_map([self::class, 'approvalShape'], oa_list()); }
    public static function approval(array $u, int $id): array {
        $a = oa_load($id); if (!$a) throw new ApiException(404, 'Document not found');
        $out = self::approvalShape($a);
        $out['thread'] = array_map(fn($c) => ['id' => (int)$c['id'], 'kind' => (string)($c['kind'] ?? 'comment'), 'who' => (string)($c['author'] ?? $c['user_name'] ?? ''), 'side' => (string)($c['side'] ?? ''), 'at' => (string)($c['created_at'] ?? ''), 'body' => (string)($c['body'] ?? '')], $a['thread'] ?? []);
        return $out;
    }
    public static function approvalFile(array $u, int $id): void {
        $a = oa_load($id); if (!$a || empty($a['file_id'])) throw new ApiException(404, 'File not available');
        header_remove('Content-Type'); cp_stream_file((int)$a['file_id']); exit;   // customer scope + visibility enforced by the portal
    }
    public static function approvalDecide(array $u, int $id, array $p): array {
        self::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $code = strtoupper(trim((string)($p['code'] ?? ''))); if (!isset(self::OA_DECISION[$code])) throw new ApiException(422, 'Decision must be A, B or D');
        try { oa_decide($id, self::OA_DECISION[$code], (string)($p['comments'] ?? ''), false); } catch (RuntimeException $e) { throw new ApiException(409, $e->getMessage()); }
        if (function_exists('login_log')) {} // decisions are logged by oa_decide (decided_by, name, ip, time) and the oa_comments thread
        return self::approval($u, $id);
    }
    public static function approvalComment(array $u, int $id, string $body): array {
        if (!oa_load($id)) throw new ApiException(404, 'Document not found');
        $body = mb_substr(trim($body), 0, 4000); if ($body === '') throw new ApiException(422, 'Comment required');
        oa_comment($id, 'comment', $body); return self::approval($u, $id);
    }

    /* ------------------------------------------------------------------ offers (customer sees amount, never cost; drafts hidden) */
    private const OF_STATUS = ['sent' => 'issued', 'approved' => 'accepted', 'in_progress' => 'order_confirmed', 'to_be_invoiced' => 'order_confirmed', 'finished' => 'order_confirmed', 'rejected' => 'declined', 'cancelled' => 'superseded'];
    private static function offerShape(array $o): array {
        $exp = !empty($o['valid_until']) && $o['status'] === 'sent' && strtotime((string)$o['valid_until'] . ' 23:59:59') < time();
        return ['id' => (int)$o['id'], 'offer_no' => (string)$o['offer_no'], 'revision' => (string)$o['revision'], 'title' => (string)$o['title'], 'vessel' => (string)($o['vessel'] ?? ''), 'project' => (string)($o['project_code'] ?? ''),
            'status' => $exp ? 'expired' : (self::OF_STATUS[$o['status']] ?? (string)$o['status']), 'portal_status' => (string)$o['status'], 'portal_status_label' => function_exists('of_status_label') ? of_status_label((string)$o['status']) : (string)$o['status'],
            'total_cents' => (int)round((float)$o['amount'] * 100), 'currency' => (string)($o['currency'] ?: 'EUR'), 'vat_pct' => isset($o['vat_pct']) ? (float)$o['vat_pct'] : null,
            'issued_at' => (string)($o['sent_at'] ?? ''), 'valid_until' => (string)($o['valid_until'] ?? ''), 'decided_at' => (string)($o['decided_at'] ?? ''), 'decided_by' => (string)($o['decided_name'] ?? ''), 'comments' => (string)($o['decision_note'] ?? ''),
            'has_file' => !empty($o['file_id']), 'file_name' => (string)($o['original_name'] ?? ''), 'ticket_no' => (string)($o['ticket_no'] ?? '')];
    }
    public static function offers(array $u): array { if (!of_has()) return []; return array_map([self::class, 'offerShape'], of_list()); }
    public static function offer(array $u, int $id): array {
        $o = of_load($id); if (!$o) throw new ApiException(404, 'Offer not found');
        $out = self::offerShape($o);
        $out['events'] = array_map(fn($e) => ['event' => (string)$e['event'], 'who' => (string)($e['user_name'] ?? ''), 'at' => (string)($e['created_at'] ?? ''), 'note' => (string)($e['note'] ?? '')], array_filter($o['events'] ?? [], fn($e) => in_array($e['event'], ['sent', 'approved', 'rejected', 'status_sent', 'status_cancelled', 'status_in_progress', 'status_finished'], true)));
        return $out;
    }
    public static function offerFile(array $u, int $id): void {
        $o = of_load($id); if (!$o || empty($o['file_id'])) throw new ApiException(404, 'File not available');
        header_remove('Content-Type'); cp_stream_file((int)$o['file_id']); exit;
    }
    /** Accept (with the drawn signature kept as evidence on the offer events) or reject (note required) — of_decide, exactly as the web page. */
    public static function offerDecide(array $u, int $id, array $p): array {
        if (self::offerNeeds2fa() && empty($u['totp_enabled'])) throw new ApiException(403, 'Two-factor authentication is required to decide on offers from the app — enable it in the web portal under Account.', ['needs_2fa' => true]);
        self::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $decision = (string)($p['decision'] ?? ''); if (!in_array($decision, ['approved', 'rejected'], true)) throw new ApiException(422, 'Decision must be approved or rejected');
        $o = of_load($id); if (!$o) throw new ApiException(404, 'Offer not found');
        if ($decision === 'approved') {
            if (empty($p['consent'])) throw new ApiException(422, 'Acceptance statement required');
            if (!empty($o['valid_until']) && strtotime((string)$o['valid_until'] . ' 23:59:59') < time()) throw new ApiException(409, 'This offer has expired — please ask Golden A/S for a new revision.');
            $sig = (string)($p['signature_png'] ?? ''); if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]{200,}$#', $sig)) throw new ApiException(422, 'Signature required');
            $png = base64_decode(substr($sig, 22), true); if (!$png || !@getimagesizefromstring($png)) throw new ApiException(422, 'Signature unreadable');
            $fid = cp_store_bytes($png, 'offer-acceptance-signature.png', 'image/png', (int)$o['project_id'], $o['subproject_id'] ? (int)$o['subproject_id'] : null, 'offer_sign', 'external', null, 'Acceptance signature ' . $o['offer_no'] . ' ' . $o['revision'], ['sign' => 'customer', 'offer_id' => $id]);
        }
        try { of_decide($id, $decision, (string)($p['note'] ?? '')); } catch (RuntimeException $e) { throw new ApiException(409, $e->getMessage()); }
        if ($decision === 'approved') of_event($id, 'signed_app', 'Signature file #' . $fid . ' · ' . (string)($u['full_name'] ?? '') . ' · mobile app');
        return self::offer($u, $id);
    }

    /* ------------------------------------------------------------------ tickets */
    private static function ticketShape(array $t): array {
        return ['id' => (int)$t['id'], 'ticket_no' => (string)$t['ticket_no'], 'subject' => (string)$t['title'], 'vessel' => (string)($t['vessel'] ?? ''), 'status' => (string)$t['status'], 'priority' => (string)($t['priority'] ?? 'normal'), 'category' => (string)($t['category'] ?? ''),
            'created_at' => (string)($t['created_at'] ?? ''), 'updated_at' => (string)($t['updated_at'] ?? $t['created_at'] ?? ''), 'n_comments' => (int)($t['n_comments'] ?? 0), 'project' => (string)($t['project_code'] ?? ''), 'resolution' => (string)($t['resolution'] ?? '')];
    }
    public static function tickets(array $u): array { if (!tk_has()) return []; return array_map([self::class, 'ticketShape'], tk_list()); }
    public static function ticket(array $u, int $id): array {
        $t = tk_load($id); if (!$t) throw new ApiException(404, 'Ticket not found');
        $out = self::ticketShape($t); $files = $t['files'] ?? [];
        $att = fn(?int $cid) => array_values(array_map(fn($f) => ['id' => (int)$f['id'], 'name' => (string)$f['original_name'], 'type' => (string)$f['mime_type'], 'url' => "/cu/tickets/files/{$f['id']}"], array_filter($files, fn($f) => (int)($f['comment_id'] ?? 0) === (int)$cid)));
        $msgs = [['id' => 0, 'from' => ($t['created_side'] ?? 'customer') === 'customer' ? 'customer' : 'gas', 'who' => (string)($t['created_name'] ?? ''), 'at' => (string)$t['created_at'], 'body' => (string)($t['description'] ?? ''), 'attachments' => $att(null)]];
        foreach ($t['comments'] as $c) if (empty($c['deleted_at'])) $msgs[] = ['id' => (int)$c['id'], 'from' => ($c['side'] ?? '') === 'customer' ? 'customer' : 'gas', 'who' => (string)$c['author'], 'at' => (string)$c['created_at'], 'body' => (string)$c['body'], 'attachments' => $att((int)$c['id'])];
        $out['messages'] = $msgs; $out['fsrs'] = array_map(fn($f) => ['id' => (int)$f['id'], 'report_no' => (string)$f['report_no'], 'status' => (string)$f['status']], $t['fsrs'] ?? []);
        $out['can_close'] = $t['status'] === 'resolved'; $out['can_reopen'] = in_array($t['status'], ['resolved', 'closed'], true); $out['can_reply'] = in_array($t['status'], tk_open_statuses(), true) || $t['status'] === 'resolved';
        return $out;
    }
    public static function ticketCreate(array $u, array $in): array {
        if (!tk_has()) throw new ApiException(503, 'Tickets not installed');
        $_POST = ['vessel_id' => (int)($in['vessel_id'] ?? 0), 'title' => (string)($in['subject'] ?? $in['title'] ?? ''), 'description' => (string)($in['body'] ?? ''), 'category' => (string)($in['category'] ?? 'fault'), 'priority' => (string)($in['priority'] ?? 'normal'), 'project_id' => (int)($in['project_id'] ?? 0), 'subproject_id' => (int)($in['subproject_id'] ?? 0)];
        self::guardUploads();
        try { $id = tk_save_from_post(0); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        return self::ticket($u, $id);
    }
    public static function ticketReply(array $u, int $id, string $body): array {
        $t = tk_load($id); if (!$t) throw new ApiException(404, 'Ticket not found');
        if (!(in_array($t['status'], tk_open_statuses(), true) || $t['status'] === 'resolved')) throw new ApiException(409, 'Ticket is closed — reopen it first.');
        $hasFiles = !empty($_FILES['files']['name'][0] ?? null); self::guardUploads();
        $body = mb_substr(trim($body), 0, 8000); if ($body === '' && $hasFiles) $body = '📎';
        try { $cid = tk_comment($id, $body, null, 'external'); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        tk_store_uploads($id, $cid);
        return self::ticket($u, $id);
    }
    public static function ticketStatus(array $u, int $id, string $status): array {
        try { tk_set_status($id, $status === 'closed' ? 'closed' : 'open', ''); } catch (RuntimeException $e) { throw new ApiException(409, $e->getMessage()); }
        return self::ticket($u, $id);
    }
    public static function ticketFile(array $u, int $fileId): void { header_remove('Content-Type'); tk_stream_file($fileId); exit; }   // tk_load inside = customer scope
    /** CS-06: images from the phone are re-encoded (EXIF dropped) before the portal stores them; other types pass as uploaded (portal allow-list applies). */
    private static function guardUploads(): void {
        $f = $_FILES['files'] ?? null; if (!$f || !is_array($f['name'])) return;
        foreach ($f['name'] as $i => $n) {
            if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            $info = @getimagesize($f['tmp_name'][$i]); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) continue;
            $img = $info[2] === IMAGETYPE_JPEG ? @imagecreatefromjpeg($f['tmp_name'][$i]) : @imagecreatefrompng($f['tmp_name'][$i]); if (!$img) continue;
            $w = imagesx($img); $h = imagesy($img); $s = min(1, 1600 / max($w, $h)); if ($s < 1) { $img2 = imagescale($img, (int)($w * $s), (int)($h * $s)); imagedestroy($img); $img = $img2; }
            imagejpeg($img, $f['tmp_name'][$i], 85); imagedestroy($img); $_FILES['files']['type'][$i] = 'image/jpeg'; $_FILES['files']['name'][$i] = preg_replace('/\.[^.]+$/', '', (string)$n) . '.jpg'; $_FILES['files']['size'][$i] = filesize($f['tmp_name'][$i]);
        }
    }

    /* ------------------------------------------------------------------ read-only: vessels, projects, documents */
    public static function vessels(array $u): array {
        return array_map(fn($v) => ['id' => (int)$v['id'], 'name' => (string)$v['name'], 'yard' => (string)($v['yard'] ?? ''), 'imo' => (string)($v['imo'] ?? ''), 'type' => (string)($v['vessel_type'] ?? $v['type'] ?? '')],
            all('SELECT v.* FROM vessels v WHERE v.customer_id = ? AND v.is_active = 1 AND v.merged_into IS NULL ORDER BY v.name', [(int)$u['customer_id']]));
    }
    /** Projects of the customer with the 4-segment lifecycle of the web dashboard (engineering → production → FAT → SAT/HAT). */
    public static function projects(array $u): array {
        [$scope, $args] = scope_sql('p'); $out = [];
        foreach (all("SELECT p.id, p.project_code, p.name, p.status, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE {$scope} AND p.status <> 'completed' ORDER BY p.project_code", $args) as $p) {
            $subs = [];
            foreach (all('SELECT s.* FROM subprojects s WHERE s.project_id = ? ORDER BY s.code', [(int)$p['id']]) as $s) {
                $pct = fn($k) => (float)($s['pct_' . $k] ?? $s[$k . '_pct'] ?? 0);
                $stage = $pct('sathat') > 0 || $pct('commissioning') > 0 ? 'sathat' : ($pct('fat') > 0 ? 'fat' : (($pct('production') > 0 || $pct('installation') > 0) ? 'production' : 'engineering'));
                $subs[] = ['id' => (int)$s['id'], 'code' => (string)($s['code'] ?? ''), 'name' => (string)$s['name'], 'stage' => $stage, 'overall' => (int)round(($pct('engineering') + $pct('procurement') + $pct('production') + $pct('fat') + $pct('installation') + $pct('sathat') + $pct('commissioning')) / 7)];
            }
            $out[] = ['id' => (int)$p['id'], 'code' => (string)$p['project_code'], 'name' => (string)$p['name'], 'vessel' => (string)($p['vessel'] ?? ''), 'subprojects' => $subs];
        }
        return $out;
    }
    /** Files shared with the customer (visibility external), newest first — never offers' internal files. */
    public static function documents(array $u): array {
        [$scope, $args] = scope_sql('p');
        $rows = all("SELECT f.id, f.kind, f.title, f.doc_no, f.revision, f.original_name, f.mime_type, f.size_bytes, f.created_at, v.name AS vessel FROM sp_files f JOIN projects p ON p.id = f.project_id LEFT JOIN vessels v ON v.id = p.vessel_id
                     WHERE {$scope} AND f.visibility = 'external' AND f.kind NOT IN ('fsr_sign','offer_sign','proforma','acc_doc','bg_doc','fsr_photo') AND f.kind NOT LIKE 'offer%' ORDER BY f.created_at DESC, f.id DESC LIMIT 500", $args);
        return array_map(fn($f) => ['id' => (int)$f['id'], 'doc_no' => (string)($f['doc_no'] ?: ''), 'revision' => (string)($f['revision'] ?: ''), 'title' => (string)($f['title'] ?: $f['original_name']), 'vessel' => (string)($f['vessel'] ?? ''), 'category' => (string)$f['kind'], 'date' => (string)$f['created_at'], 'mime' => (string)$f['mime_type'], 'size' => (int)$f['size_bytes']], $rows);
    }
    public static function document(array $u, int $id): void { header_remove('Content-Type'); cp_stream_file($id); exit; }
}
