-- GAS-CP-R75-001 — sales pipeline: next action on the offer, follow-up signals
ALTER TABLE offers
  ADD COLUMN next_action_type VARCHAR(20) NULL AFTER lost_reason,
  ADD COLUMN next_action_at DATE NULL AFTER next_action_type,
  ADD COLUMN next_action_user_id INT NULL AFTER next_action_at,
  ADD COLUMN next_action_note VARCHAR(300) NULL AFTER next_action_user_id,
  ADD COLUMN last_contact_at DATE NULL AFTER next_action_note,
  ADD INDEX ix_offers_next_action (next_action_at);
CREATE TABLE IF NOT EXISTS offer_signals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  offer_id INT NOT NULL,
  skey VARCHAR(30) NOT NULL,
  delta SMALLINT NOT NULL DEFAULT 0,
  set_by INT NULL,
  set_name VARCHAR(120) NULL,
  note VARCHAR(300) NULL,
  is_auto TINYINT(1) NOT NULL DEFAULT 0,
  set_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_offer_signal (offer_id, skey),
  KEY ix_signal_offer (offer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
