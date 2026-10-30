-- GAS-CP-R60-007 — Personal data on the employee card (HR only): contact, identification, bank.
ALTER TABLE hr_people
  ADD COLUMN personal_email VARCHAR(190) NULL AFTER notes,
  ADD COLUMN phone_mobile   VARCHAR(40)  NULL AFTER personal_email,
  ADD COLUMN phone_home     VARCHAR(40)  NULL AFTER phone_mobile,
  ADD COLUMN address        VARCHAR(200) NULL AFTER phone_home,
  ADD COLUMN birth_date     DATE         NULL AFTER address,
  ADD COLUMN amka           VARCHAR(20)  NULL AFTER birth_date,
  ADD COLUMN afm            VARCHAR(20)  NULL AFTER amka,
  ADD COLUMN doy            VARCHAR(80)  NULL AFTER afm,
  ADD COLUMN id_number      VARCHAR(30)  NULL AFTER doy,
  ADD COLUMN iban           VARCHAR(34)  NULL AFTER id_number,
  ADD COLUMN bank           VARCHAR(80)  NULL AFTER iban,
  ADD COLUMN emergency_name VARCHAR(120) NULL AFTER bank,
  ADD COLUMN emergency_phone VARCHAR(40) NULL AFTER emergency_name;
