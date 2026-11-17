-- GAS-CP-R97-095 (v1.170.0) — Production orders: marked when the equipment has to comply with IACS UR E26 / E27 (cyber safety approval)
ALTER TABLE production_orders ADD COLUMN cyber_e27 TINYINT(1) NOT NULL DEFAULT 0;
