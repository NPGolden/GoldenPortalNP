-- GAS-CP-R97-281 (v1.269.0): a delivery note assembled from the lines of notes received at a warehouse — each line remembers the line it came from.
ALTER TABLE inv_dn_lines
  ADD COLUMN IF NOT EXISTS src_line_id INT NULL AFTER pr_line_id,
  ADD KEY IF NOT EXISTS ix_dnl_src (src_line_id);
