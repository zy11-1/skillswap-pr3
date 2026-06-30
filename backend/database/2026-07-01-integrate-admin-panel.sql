-- ===================================================================
-- Migration: integrate the admin panel (Tarin) with the dynamic-class
-- rework, bringing a pre-both-branches database up to the merged schema.
--
-- Safe to run AFTER the new code is deployed (the new code expects these
-- columns; the old code expected auto_accept / payment_timing which are
-- dropped here). Order on production: merge -> redeploy -> THEN run this.
--
-- The companion runner (run-migration.php) applies each change only if it
-- is still needed, so this is effectively idempotent.
-- ===================================================================

-- --- TutorAvailability: dynamic-class columns in, legacy flags out -------
ALTER TABLE TutorAvailability
    ADD COLUMN base_price      DECIMAL(10,2) NOT NULL DEFAULT 10.00,
    ADD COLUMN locked_skill_id INT NULL,
    ADD COLUMN topics_covered  TEXT NULL,
    ADD COLUMN needs_syllabus  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN series_id       VARCHAR(40) NULL;

ALTER TABLE TutorAvailability
    ADD CONSTRAINT fk_availability_skill
    FOREIGN KEY (locked_skill_id) REFERENCES Skill(skill_id) ON DELETE SET NULL;

ALTER TABLE TutorAvailability
    DROP COLUMN auto_accept,
    DROP COLUMN payment_timing;

-- --- Booking: time-change consent + admin dispute mediation --------------
ALTER TABLE Booking
    ADD COLUMN change_pending TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN dispute_status ENUM('none', 'open', 'resolved_refund', 'resolved_closed') NOT NULL DEFAULT 'none',
    ADD COLUMN dispute_reason VARCHAR(500) NULL;

ALTER TABLE Booking
    DROP COLUMN payment_timing;

-- --- MeritRequest: credits model -> performance-based snapshot -----------
-- (table holds 0 rows on production, so this loses no data)
ALTER TABLE MeritRequest
    ADD COLUMN classes_completed INT NOT NULL DEFAULT 0,
    ADD COLUMN students_helped   INT NOT NULL DEFAULT 0,
    ADD COLUMN avg_rating        DECIMAL(3,1) NOT NULL DEFAULT 0.0,
    ADD COLUMN review_count      INT NOT NULL DEFAULT 0;

ALTER TABLE MeritRequest
    DROP COLUMN credits_amount,
    DROP COLUMN merit_points;
