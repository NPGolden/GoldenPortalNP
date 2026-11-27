<?php
/** «Wellness» in the Service, PM and Employee apps — GAS-CP-R97-293.
 *  The anonymous check-in of the day, the personal settings for the field (weather, rest, travel, leave nudge, quiet hours), the company information,
 *  the public questions and answers, an anonymous question to HR and a thank-you to a colleague. Rules live in app/wl.php, app/wl2.php, app/soc.php. */
declare(strict_types=1);

final class PortalWellness {
    private const KEYS = ['mood', 'weather', 'rest', 'travel', 'leave_nudge', 'quiet'];
    private static function guard(callable $fn) { try { return $fn(); } catch (ApiException $e) { throw $e; } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } }
    public static function state(): array {
        if (!function_exists('wl_can') || !wl_can()) return ['enabled' => false]; $uid = (int) current_user()['id']; $P = wl_prefs($uid); $pr = [];
        foreach (self::KEYS as $k) $pr[$k] = (bool)$P[$k]; $pr['quiet_from'] = wl_hm((int)$P['quiet_from']); $pr['quiet_to'] = wl_hm((int)$P['quiet_to']);
        $col = []; if (function_exists('soc_has') && soc_has()) foreach (soc_staff() as $id => $s) if ((int)$id !== $uid) $col[] = ['id' => (int)$id, 'name' => (string)$s['full_name']];
        return ['enabled' => true, 'mood_done' => wl_mood_done($uid), 'prefs' => $pr, 'info' => function_exists('wl_info') ? array_filter(wl_info(), fn($v) => $v !== '') : [],
                'qa' => function_exists('wl_questions_public') ? array_map(fn($q) => ['q' => (string)$q['body'], 'a' => (string)$q['answer'], 'date' => substr((string)$q['answered_at'], 0, 10)], wl_questions_public(10)) : [], 'questions' => function_exists('wl2_has') && wl2_has(), 'colleagues' => $col];
    }
    public static function mood(array $p): array { self::guard(fn() => wl_mood_save((int)($p['mood'] ?? 0))); return ['status' => 'accepted', 'state' => self::state()]; }
    public static function prefs(array $p): array {
        $in = []; foreach (self::KEYS as $k) if (array_key_exists($k, $p)) $in[$k] = empty($p[$k]) ? 0 : 1; foreach (['quiet_from', 'quiet_to'] as $k) if (isset($p[$k]) && is_string($p[$k])) $in[$k . '_t'] = $p[$k];
        self::guard(fn() => wl_save($in)); return ['status' => 'accepted', 'state' => self::state()];
    }
    public static function question(array $p): array { if (!function_exists('wl_question_ask')) throw new ApiException(404, 'Not found'); self::guard(fn() => wl_question_ask((string)($p['body'] ?? ''))); return ['status' => 'accepted']; }
    public static function thanks(array $p): array {
        if (!function_exists('soc_kudos_give')) throw new ApiException(404, 'Not found'); $keep = $_POST;
        try { $_POST = ['to' => (int)($p['to'] ?? 0), 'reason' => (string)($p['reason'] ?? '')]; self::guard('soc_kudos_give'); } finally { $_POST = $keep; }
        return ['status' => 'accepted'];
    }
}
