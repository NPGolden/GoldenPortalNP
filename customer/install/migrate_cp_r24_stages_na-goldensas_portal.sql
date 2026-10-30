USE goldensas_portal;
-- GAS-CP-R24-004 · Stage "N/A": a stage that does not apply to a sub-project is stored as -1 and left out of the overall percentage.
-- Select the portal database first (goldensas_portal). Re-running is harmless.
ALTER TABLE subprojects
  MODIFY pct_engineering TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_procurement TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_production TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_fat TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_installation TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_sathat TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_commissioning TINYINT NOT NULL DEFAULT 0;
ALTER TABLE sp_progress_snapshots
  MODIFY pct_engineering TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_procurement TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_production TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_fat TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_installation TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_sathat TINYINT NOT NULL DEFAULT 0,
  MODIFY pct_commissioning TINYINT NOT NULL DEFAULT 0;
