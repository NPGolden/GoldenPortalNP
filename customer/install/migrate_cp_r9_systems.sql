-- ================================================================ R9
-- Vessel systems: drawing code + abbreviation, seeded with the Golden A/S
-- master list (52 systems). Run once, after migrate_cp_r8_tasks_jo.sql.
-- Existing systems whose description matches one of the list (e.g. "Main
-- Switchboard", "Galley") receive their code and abbreviation; legacy
-- entries that are not in the list and not used anywhere are retired
-- (not deleted) and can be restored from Admin › Vessel systems.
-- ---------------------------------------------------------------------
ALTER TABLE vessel_systems
  ADD COLUMN code VARCHAR(10) NULL AFTER id,
  ADD COLUMN abbr VARCHAR(12) NULL AFTER code,
  ADD UNIQUE INDEX uq_vs_abbr (abbr),
  ADD INDEX idx_vs_code (code);

INSERT INTO vessel_systems (code, abbr, name, sort_order, is_active) VALUES
 ('10',  'MSB',   'MAIN SWITCHBOARD',                              10,  1),
 ('20',  'EMSB',  'EMERGENCY SWITCHBOARD',                         20,  1),
 ('30',  'EUPS',  'EMERGENCY UPS',                                 30,  1),
 ('40',  'MCC',   'MOTOR CONTROL CENTER',                          40,  1),
 ('50',  'SHC',   'SHORE CONNECTION',                              50,  1),
 ('60',  'EDC',   'EMERGENCY PANEL SUPPLIED FROM EMCY BATTERIES',  60,  1),
 ('70',  'AHU',   'A/C PANEL OR AIR HANDLING PANELS',              70,  1),
 ('80',  'AE',    'AUXILIARY ENGINES',                             80,  1),
 ('90',  'ELS',   'EMERGENCY LIGHTING SYSTEM',                     90,  1),
 ('100', 'PDS',   'POWER DISTRIBUTION SYSTEM',                     100, 1),
 ('200', 'AMS',   'ALARM AND MONITORING SYSTEM',                   200, 1),
 ('300', 'LDS',   'LIGHTING DISTRIBUTION SYSTEM',                  300, 1),
 ('400', 'AUXS',  'AUXILIARY SYSTEMS',                             400, 1),
 ('420', 'DECD',  'DECORATIVE DOORS',                              420, 1),
 ('440', 'WTD',   'WATER TIGHT DOORS',                             440, 1),
 ('460', 'WTHD',  'WEATHER TIGHT DOORS',                           460, 1),
 ('470', 'HPU',   'HYDRAULIC POWER UNIT',                          470, 1),
 ('480', 'NET',   'NETWORKING SYSTEM',                             480, 1),
 ('490', 'AV',    'AUDIO VIDEO PANEL',                             490, 1),
 ('500', 'DCHUB', 'HIGH VOLTAGE DC SYSTEM AND PROTECTION',         500, 1),
 ('510', 'STDC',  'STARTING DC',                                   510, 1),
 ('520', 'SDC',   'SERVICE DC',                                    520, 1),
 ('530', 'EMSS',  'EMERGENCY STOP SYSTEM',                         530, 1),
 ('540', 'NVS',   'NAVIGATION SYSTEM',                             540, 1),
 ('600', 'GMDSS', 'GMDSS PANEL',                                   600, 1),
 ('610', 'WHC',   'WHEELHOUSE CONTROL',                            610, 1),
 ('620', 'STG',   'STEERING CONTROL SYSTEM',                       620, 1),
 ('630', 'TEL',   'TELECOMMUNICATIONS',                            630, 1),
 ('640', 'TBS',   'TALK BACK SYSTEM',                              640, 1),
 ('650', 'TLGS',  'TELEGRAPH SYSTEM',                              650, 1),
 ('660', 'GBS',   'GLASS BRIDGE SYSTEM',                           660, 1),
 ('670', 'NVL',   'NAVIGATION LIGHTING SYSTEM',                    670, 1),
 ('680', 'UDWL',  'UNDER WATER LIGHTS',                            680, 1),
 ('690', 'DP',    'DYNAMIC POSITIONING',                           690, 1),
 ('700', 'BTH',   'BOW THRUSTER',                                  700, 1),
 ('710', 'STH',   'STERN THRUSTER',                                710, 1),
 ('720', 'PCS',   'PROPULSION CONTROL SYSTEM',                     720, 1),
 ('730', 'TRQM',  'TORQUE METER',                                  730, 1),
 ('740', 'AWL',   'ANCHOR WINDLASS SYSTEM',                        740, 1),
 ('750', 'WCH',   'WINCHES SYSTEM',                                750, 1),
 ('760', 'STB',   'STABILIZER SYSTEM',                             760, 1),
 ('800', 'FAS',   'FIRE DETECTION / GENERAL ALARM SYSTEM',         800, 1),
 ('810', 'FDR',   'FIRE DOORS',                                    810, 1),
 ('820', 'FES',   'FIRE EXTINGUISHING SYSTEM',                     820, 1),
 ('830', 'PA',    'PUBLIC ADDRESS SYSTEM',                         830, 1),
 ('900', 'RVO',   'REMOTE VALVE OPERATION',                        900, 1),
 ('910', 'TLS',   'TANK LEVEL SYSTEM',                             910, 1),
 ('920', 'FWS',   'FRESH WATER SYSTEM',                            920, 1),
 ('930', 'BLG',   'BILGE LEVEL SYSTEM',                            930, 1),
 ('940', 'HYD',   'HYDROPHORE SYSTEM',                             940, 1),
 ('950', 'GAL',   'GALLEY',                                        950, 1),
 ('960', 'PRV',   'PROVISION',                                     960, 1)
ON DUPLICATE KEY UPDATE code = VALUES(code), abbr = VALUES(abbr), name = VALUES(name),
                        sort_order = VALUES(sort_order), is_active = 1;

-- legacy entries without a code that nothing refers to: retire, keep history
UPDATE vessel_systems vs SET vs.is_active = 0
 WHERE vs.code IS NULL
   AND NOT EXISTS (SELECT 1 FROM subprojects s WHERE s.system_id = vs.id)
   AND NOT EXISTS (SELECT 1 FROM drawing_requests d WHERE d.system_id = vs.id);
