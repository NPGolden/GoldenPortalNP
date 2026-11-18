-- GAS-CP-R97-106 (v1.179.0) — the record of deleted procurement documents also keeps deleted BOM revisions
ALTER TABLE proc_deleted MODIFY doc_kind ENUM('po','rfq','pr','bom') NOT NULL;
