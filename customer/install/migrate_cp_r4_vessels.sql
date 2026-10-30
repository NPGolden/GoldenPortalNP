-- GAS Customer Portal — R4: vessels may exist without a customer      GAS-CP-R4-003
-- (imported fleets, vessels not yet assigned). Safe to run more than once.
ALTER TABLE vessels MODIFY customer_id INT UNSIGNED NULL;
