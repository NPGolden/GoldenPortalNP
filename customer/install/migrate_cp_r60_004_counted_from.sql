-- GAS-CP-R60-004 — Balance import as a cut-over: the imported balance is the truth "as of" a date; the portal counts only what comes after.
ALTER TABLE hr_entitlements ADD COLUMN counted_from DATE NULL AFTER opening_taken;
