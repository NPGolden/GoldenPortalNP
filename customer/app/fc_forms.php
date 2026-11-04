<?php
/**
 * Function-check / commissioning form templates.                                GAS-CP-R77-001
 *
 * Each OEM form is a template: header fields, the two sides (Portside / Starboard) with their groups of
 * check items, the verified adjustment values and the closing statement. The engineer fills it on line,
 * the result is stored as JSON and rendered as a one-page PDF that mirrors the OEM sheet.
 * Every check item is tri-state: ''  not tested · 'ok' tested to the customer's satisfaction · 'na' not existing (crossed out).
 * Add a template = add an entry here (no SQL).
 */
declare(strict_types=1);

function fc_forms(): array {
    static $F = null; if ($F !== null) return $F;
    $F = [
        'marex3' => [
            'title' => 'Function check — Marex OS (III) System',
            'short' => 'Marex OS III',
            'maker' => 'Emerson · AVENTICS',
            'code'  => 'FC-MAREX-OS3',
            'intro' => 'Following functions were tested to the customers satisfaction, please cross out none existing:',
            'head'  => [   // [key, label, kind]  kind: text | date | vessel (pre-filled) | imo (pre-filled) | tech (pre-filled) | project (pre-filled)
                ['vessel', 'Name of vessel', 'vessel'], ['yard', 'Shipyard & ship type', 'text'],
                ['imo', 'IMO registration no.', 'imo'], ['date', 'Actual date', 'date'],
                ['tech', 'Service technician', 'tech'], ['location', 'Actual location', 'text'],
                ['marex_type', 'Marex type', 'text'], ['setup', 'Setup description', 'text'],
                ['project_no', 'Project number', 'project'], ['software', 'Software versions', 'text'],
            ],
            'sides' => ['ps' => 'Portside', 'sb' => 'Starboard'],
            'groups' => [   // each item row = [left label, right label]; a null label is a free text line
                ['key' => 'std', 'title' => 'standard settings', 'rows' => [
                    ['devices O.K.', 'CAN bus O.K.'], ['control head take over', 'change command'], ['rpm setting values', 'physical rpm signals'],
                    ['rpm measurement', 'rpm/speed reduction'], ['rpm cruising curve', 'rpm maneuver curve'], ['fault reaction', 'gear feedback'],
                    ['rpm clutch in window', 'reverse maneuver'], ['min & max ship speed', 'ship speed calculation'],
                ]],
                ['key' => 'spc', 'title' => 'special settings', 'rows' => [
                    ['trolling activation', 'signal if trolling is off'], ['trolling slip curve', 'trolling rpm curve'], ['start interlock', 'enable stations'],
                    ['safety stop', 'emergency maneuver'], ['exhaust pipe switch', 'synchronizing'], ['shaft brake', 'external stations'],
                    ['PLC programming', 'emergency control'], [null, null],
                ]],
                ['key' => 'imp', 'title' => 'important topics and values', 'rows' => [
                    ['fault monitoring on', 'old fault list cleared'], ['crash stop maneuver', 'operator instructed'],
                ]],
            ],
            'values_title' => 'verified adjustment values',
            'values' => [   // [key, label, unit of value 1, unit of value 2]; a null label is a free line
                ['A', 'idle engine speed', 'rpm', 'signal value'], ['B', 'full engine speed', 'rpm', 'signal value'], ['C', 'max. rpm at trolling', 'rpm', 'signal value'],
                ['D', 'min. trolling slip', '%', 'signal value'], ['E', 'max. trolling slip', '%', 'signal value'], ['F', 'gear clutch in time', 'sec.', 'rpm fall'],
                ['G', null, '', ''], ['H', null, '', ''],
            ],
            'extra' => [['crash_stop', 'crash stop distance', 'm'], ['ship_length', 'ship length', '']],   // one line under both sides
            'closing' => 'When leaving the ship there was no fault respectively alarm situation.',
        ],
    ];
    return $F;
}
function fc_form(string $key): ?array { return fc_forms()[$key] ?? null; }
