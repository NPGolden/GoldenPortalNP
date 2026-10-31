<?php
/** POST /sync — batched, idempotent operations from the device outbox (sync_ops keeps the result per uuid). R66-001: PM kinds for app 'pm'. */
declare(strict_types=1);
function sync_ops(array $u, array $b): void {
    $ops = $b['ops'] ?? []; if (!is_array($ops) || count($ops) > 200) throw new ApiException(422, 'ops: array of max 200');
    $results = []; $pm = api_app() === 'pm';
    foreach ($ops as $op) {
        $uuid = (string)($op['id'] ?? ''); $kind = (string)($op['kind'] ?? ''); $p = (array)($op['payload'] ?? []);
        if (!preg_match('/^[0-9a-f-]{36}$/', $uuid)) { $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'error' => 'bad uuid']; continue; }
        if ($seen = one('SELECT status, result_id, result_no FROM sync_ops WHERE uuid = ?', [$uuid])) { $results[] = ['uuid' => $uuid, 'status' => $seen['status'], 'id' => $seen['result_id'], 'number' => $seen['result_no']]; continue; }
        $res = null;
        try {
            switch ($kind) {
                case 'assignment_accept': $res = ['id' => Portal::acceptAssignment($u, $p)]; break;
                case 'ticket_accept':     $res = ['id' => Portal::acceptTicket($u, $p)]; break;
                case 'ticket_note':       $res = ['id' => Portal::ticketNote($u, $uuid, $p)]; break;
                case 'fsr':               $res = Portal::createFsr($u, $uuid, $p); break;
                case 'inspection':        $res = Portal::createIsr($u, $uuid, $p); break;
                case 'timesheet':         $res = Portal::bookHours($u, $uuid, $p); break;
                case 'timesheet_submit':  $res = Portal::submitWeek($u, $p); break;
                case 'extra_hours':       $res = Portal::extraHours($u, $uuid, $p); break;
                case 'note':              $res = Portal::note($u, $uuid, $p); break;
                case 'equipment_correction': $res = Portal::equipmentCorrection($u, $uuid, $p); break;
                case 'hr_request':        $res = Portal::hrRequest($u, $p, null); break;          // without a document (document → POST /hr/request multipart)
                case 'hr_cancel':         $res = Portal::hrCancel($u, $p); break;
                case 'hr_period_join':    $res = Portal::hrPeriodJoin($u, $p); break;
                case 'hr_training_apply': $res = Portal::hrTrainingApply($u, $p); break;
                case 'hr_eval_ack':       $res = Portal::hrEvalAck($u, $p); break;
                case 'hr_message':        $res = Portal::hrMessage($u, $p); break;
                case 'hr_seen':           $res = Portal::hrSeen($u); break;
                /* R66-001 — Golden PM app only (api_app() === 'pm'); the Service Engineer app keeps its 403s */
                case 'sp_stages':      $res = $pm ? PortalPM::setStages($u, $p) : throw new ApiException(403, 'Stage changes are for project managers (Golden PM app)'); break;
                case 'sp_comment':     $res = $pm ? PortalPM::comment($u, $uuid, $p) : throw new ApiException(403, 'Comments are for project managers (Golden PM app)'); break;
                case 'issue_status':   $res = $pm ? PortalPM::issueStatus($u, $p) : throw new ApiException(403, 'Issue status is for project managers (Golden PM app)'); break;
                case 'drawing_update': $res = $pm ? PortalPM::drawingUpdate($u, $p) : throw new ApiException(403, 'Drawing tracker is for project managers (Golden PM app)'); break;
                case 'hr_decide':      if (!$pm) throw new ApiException(403, 'Leave decisions are portal-only');   // CS-23 (SE app)
                                       RateLimit::check('decide', 'u' . $u['id'], 30); $res = PortalPM::hrDecide($u, $p); break;
                case 'ts_decide':      if (!$pm) throw new ApiException(403, 'Approvals are portal-only');          // CS-03 (SE app)
                                       RateLimit::check('decide', 'u' . $u['id'], 30); $res = PortalPM::tsDecide($u, $p); break;
                case 'nf_read':        $res = PortalPM::markRead($u, (array)($p['ids'] ?? [])); break;
                case 'approve': case 'reject': case 'issue': case 'eng_decide': throw new ApiException(403, 'Approvals are portal-only');   // CS-03 (both apps: engineering extra-hours decisions stay in the portal)
                default: throw new ApiException(422, 'Unknown operation kind');
            }
            if (isset($res['status'])) { $res['record_status'] = $res['status']; unset($res['status']); }   // 'status' is the sync result; the record's own status travels as record_status
            q('INSERT INTO sync_ops (uuid, device_id, user_id, kind, status, result_id, result_no, server_ts) VALUES (?,?,?,?,?,?,?,NOW())', [$uuid, $u['device_id'], (int)$u['id'], $kind, 'accepted', (string)($res['id'] ?? ''), $res['number'] ?? null]);
            $results[] = ['uuid' => $uuid, 'status' => 'accepted'] + $res;
        } catch (ApiException $e) {
            $st = $e->status === 409 ? 'conflict' : ($e->status === 423 ? 'locked' : 'rejected');
            q('INSERT INTO sync_ops (uuid, device_id, user_id, kind, status, error, server_ts) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), error = VALUES(error)', [$uuid, $u['device_id'], (int)$u['id'], $kind, $st, mb_substr($e->getMessage(), 0, 255)]);
            $results[] = ['uuid' => $uuid, 'status' => $st, 'code' => $e->status, 'error' => $e->getMessage()] + $e->extra;
        } catch (Throwable $e) {
            q('INSERT INTO sync_ops (uuid, device_id, user_id, kind, status, error, server_ts) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), error = VALUES(error)', [$uuid, $u['device_id'], (int)$u['id'], $kind, 'rejected', mb_substr($e->getMessage(), 0, 255)]);
            error_log('[api/v1 sync] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'code' => 500, 'error' => 'Server error' . (cfg('app.debug') ? ': ' . $e->getMessage() : '')];
        }
        $_POST = [];
    }
    Response::json(['results' => $results]);
}
