-- GAS-CP-R17-008 · Configurable offer statuses (Settings › Offer statuses).
CREATE TABLE IF NOT EXISTS offer_statuses (
  skey        VARCHAR(30) NOT NULL PRIMARY KEY,
  label       VARCHAR(80) NOT NULL,
  tone        VARCHAR(10) NOT NULL DEFAULT 'mute',
  behaves     ENUM('open','approved','closed_won','closed_lost') NOT NULL DEFAULT 'open',
  sort_order  INT NOT NULL DEFAULT 50,
  is_builtin  TINYINT(1) NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO offer_statuses (skey, label, tone, behaves, sort_order, is_builtin) VALUES
 ('sent','Sent','gold','open',10,1), ('rejected','Rejected','bad','closed_lost',20,1), ('cancelled','Cancelled','mute','closed_lost',30,1),
 ('approved','Approved','green','approved',40,1), ('in_progress','In progress','blue','approved',50,1), ('to_be_invoiced','To be invoiced','navy','approved',60,1), ('finished','Finished','mute','closed_won',70,1);
ALTER TABLE offers MODIFY status VARCHAR(30) NOT NULL DEFAULT 'sent';
