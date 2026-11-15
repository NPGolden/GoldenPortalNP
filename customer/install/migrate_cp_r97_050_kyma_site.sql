-- GAS-CP-R97-050 (v1.145.0) — second website (KYMA Marine Power): site on inquiries, statistics and newsletter data; brands on contacts and organisations
-- Specification GAS-KYM-SPC-PORTAL-001 R0. Every existing row keeps the value 'golden'. Safe to run twice.
ALTER TABLE web_inquiries ADD COLUMN site VARCHAR(16) NOT NULL DEFAULT 'golden';
ALTER TABLE web_inquiries ADD INDEX ix_web_site (site, status);
ALTER TABLE web_inquiries ADD COLUMN contact_id INT UNSIGNED NULL;
ALTER TABLE web_stats_day ADD COLUMN site VARCHAR(16) NOT NULL DEFAULT 'golden' FIRST;
ALTER TABLE web_stats_day DROP PRIMARY KEY, ADD PRIMARY KEY (site, d, dim, k);
ALTER TABLE web_stats_day DROP INDEX ix_ws_dim;
ALTER TABLE web_stats_day ADD INDEX ix_ws_dim (site, dim, d);
ALTER TABLE web_stats_hour ADD COLUMN site VARCHAR(16) NOT NULL DEFAULT 'golden' FIRST;
ALTER TABLE web_stats_hour DROP PRIMARY KEY, ADD PRIMARY KEY (site, d, h);
ALTER TABLE nl_subs ADD COLUMN site VARCHAR(16) NOT NULL DEFAULT 'golden';
ALTER TABLE nl_subs DROP INDEX uq_nl_sub;
ALTER TABLE nl_subs ADD UNIQUE KEY uq_nl_sub (site, email);
ALTER TABLE nl_optout ADD COLUMN site VARCHAR(16) NOT NULL DEFAULT 'golden' FIRST;
ALTER TABLE nl_optout DROP PRIMARY KEY, ADD PRIMARY KEY (site, email);
ALTER TABLE nl_issues ADD COLUMN site VARCHAR(16) NOT NULL DEFAULT 'golden';
ALTER TABLE contacts ADD COLUMN brands SET('golden','kyma') NOT NULL DEFAULT 'golden';
ALTER TABLE companies ADD COLUMN brands SET('golden','kyma') NOT NULL DEFAULT 'golden';
