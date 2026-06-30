-- Migration: Dynamic Group Class feature
-- Adds dynamic-group columns, removes the auto-accept and postpay options.
-- Apply to any database that predates this feature (local, Railway, teammates).
-- Safe on data: only adds/drops config columns; no rows are deleted.

ALTER TABLE TutorAvailability
  ADD COLUMN base_price      DECIMAL(10,2) NOT NULL DEFAULT 10.00 AFTER capacity,
  ADD COLUMN locked_skill_id INT NULL AFTER share_token,
  ADD COLUMN topics_covered  TEXT NULL AFTER locked_skill_id,
  ADD COLUMN needs_syllabus  TINYINT(1) NOT NULL DEFAULT 0 AFTER topics_covered,
  ADD COLUMN series_id       VARCHAR(40) NULL AFTER needs_syllabus,
  ADD CONSTRAINT fk_availability_skill FOREIGN KEY (locked_skill_id) REFERENCES Skill(skill_id) ON DELETE SET NULL;

-- Remove the now-dead options (instant-accept and postpay).
ALTER TABLE TutorAvailability
  DROP COLUMN auto_accept,
  DROP COLUMN payment_timing;

ALTER TABLE Booking
  DROP COLUMN payment_timing;
