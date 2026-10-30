<?php
declare(strict_types=1);
final class ApiException extends RuntimeException { public int $status; public array $extra; public function __construct(int $status, string $msg, array $extra = []) { parent::__construct($msg); $this->status = $status; $this->extra = $extra; } }
final class Response {
    public static function json($data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
    public static function error(int $status, string $msg, array $extra = []): void { self::json(['error' => $msg] + $extra, $status); }
    /** CS-02: monetary fields never leave the API (the app shows hours and status only) */
    public static function noMoney(array $rows): array {
        static $banned = ['rate', 'price', 'amount', 'discount', 'total', 'cost', 'sale', 'unit_price', 'cost_rate', 'value', 'rate_normal', 'rate_overtime', 'rate_holiday', 'discount_pct', 'parts_budget', 'offer_value', 'manhour_cost', 'rate_card', 'cost_eur'];
        foreach ($rows as &$r) if (is_array($r)) { foreach ($banned as $b) unset($r[$b]); }
        return $rows;
    }
}
