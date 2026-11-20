-- GAS-CP-R97-166 (v1.207.0): Community posts may carry a YouTube video — only the 11-character video id is stored. New post kind 'video'. Idempotent.
ALTER TABLE soc_posts MODIFY kind ENUM('post','poll','photo','video') NOT NULL DEFAULT 'post';
ALTER TABLE soc_posts ADD COLUMN IF NOT EXISTS video_id VARCHAR(20) NULL AFTER mime_type;
