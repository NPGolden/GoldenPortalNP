-- GAS-CP-R22-007 · File categories configurable (Settings › File categories). sp_files.kind becomes free text so your own
-- categories can be stored. Select the portal database first (goldensas_portal). Re-running is harmless.
ALTER TABLE sp_files MODIFY COLUMN kind VARCHAR(30) NOT NULL DEFAULT 'other';
CREATE TABLE IF NOT EXISTS file_kinds (
  skey         VARCHAR(30) NOT NULL PRIMARY KEY,
  label        VARCHAR(80) NOT NULL,
  doc_code     VARCHAR(8)  NOT NULL DEFAULT 'FILE',        -- part of the document number: <project>-<code>-001
  archive_tab  VARCHAR(30) NOT NULL DEFAULT 'files',       -- folder in the file archive: drawings · owner-approvals · class · tests · weekly-notes · reports · service-reports · files
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  is_builtin   TINYINT(1) NOT NULL DEFAULT 0,
  is_system    TINYINT(1) NOT NULL DEFAULT 0,              -- set by the portal itself (service reports, offers, issued documents); not offered on upload
  is_active    TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO file_kinds (skey, label, doc_code, archive_tab, sort_order, is_builtin, is_system) VALUES
 ('other','Other','FILE','files',10,1,0), ('drawing','Drawing','DRW','drawings',20,1,0), ('photo','Photo','PHO','weekly-notes',30,1,0),
 ('fat','FAT report','FAT','tests',40,1,0), ('hat','HAT report','HAT','tests',50,1,0), ('sat','SAT report','SAT','tests',60,1,0),
 ('owner_doc','Owner-approval document','OWN','owner-approvals',70,1,0), ('class_doc','Class document','CLS','class',80,1,0),
 ('weekly','Weekly report','WR','reports',90,1,0), ('monthly','Monthly report','PR','reports',100,1,0), ('ga','GA drawing','GA','ga',110,1,0),
 ('fsr_doc','Service report document','FSR','service-reports',200,1,1), ('fsr_photo','Service report photo','FSR','service-reports',210,1,1), ('fsr_sign','Service report signature','FSR','service-reports',220,1,1),
 ('offer','Offer document','OFF','offers',230,1,1), ('issued','Issued document','DOC','reports',240,1,1), ('file','Attachment','FILE','files',250,1,1), ('comment','Comment attachment','FILE','files',260,1,1);
