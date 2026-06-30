-- database/schema.sql
-- SkillSwap database schema
-- Group: Threeway Chaos | SCSM2223-04
--
-- Run this once to create the database and all tables:
--   mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS skillswap CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE skillswap;

-- ---------------------------------------------------------------
-- 1. User
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS User (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('learner', 'tutor', 'admin') NOT NULL DEFAULT 'learner',
    faculty         VARCHAR(100) NOT NULL,
    year_of_study   VARCHAR(20) NULL,
    photo_url       VARCHAR(255) NULL,
    bio             TEXT NULL,
    wallet_balance  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_verified     TINYINT(1) NOT NULL DEFAULT 0,
    merit_points    INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 2. Skill
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Skill (
    skill_id    INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    category    VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 3. UserSkill (tutor's offered skill)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS UserSkill (
    userskill_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    skill_id      INT NOT NULL,
    hourly_rate   DECIMAL(10,2) NOT NULL,
    level         VARCHAR(50) NOT NULL,
    description   TEXT NOT NULL,
    CONSTRAINT fk_userskill_user FOREIGN KEY (user_id) REFERENCES User(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_userskill_skill FOREIGN KEY (skill_id) REFERENCES Skill(skill_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 4. VerificationRequest
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS VerificationRequest (
    request_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    document_url   VARCHAR(255) NOT NULL,
    status         ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    submitted_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_verification_user FOREIGN KEY (user_id) REFERENCES User(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 5. Booking
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Booking (
    booking_id      INT AUTO_INCREMENT PRIMARY KEY,
    learner_id      INT NOT NULL,
    tutor_id        INT NOT NULL,
    skill_id        INT NOT NULL,
    booking_date    DATETIME NOT NULL,
    duration        INT NOT NULL,
    status          ENUM('Pending', 'Accepted', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Pending',
    total_amount    DECIMAL(10,2) NOT NULL,
    recording_url   VARCHAR(255) NULL,
    availability_id INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_booking_learner FOREIGN KEY (learner_id) REFERENCES User(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_tutor FOREIGN KEY (tutor_id) REFERENCES User(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_skill FOREIGN KEY (skill_id) REFERENCES Skill(skill_id) ON DELETE CASCADE
    -- FK to TutorAvailability added via ALTER below (that table is
    -- defined later in this file, so the constraint can't be inline here)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 6. Review (one review per booking)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Review (
    review_id    INT AUTO_INCREMENT PRIMARY KEY,
    booking_id   INT NOT NULL UNIQUE,
    rating       INT NOT NULL,
    comment      TEXT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_booking FOREIGN KEY (booking_id) REFERENCES Booking(booking_id) ON DELETE CASCADE,
    CONSTRAINT chk_rating_range CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 7. Message
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Message (
    message_id   INT AUTO_INCREMENT PRIMARY KEY,
    sender_id    INT NOT NULL,
    receiver_id  INT NOT NULL,
    body         TEXT NOT NULL,
    is_read      TINYINT(1) NOT NULL DEFAULT 0,
    category     ENUM('chat', 'booking', 'system', 'marketplace') NOT NULL DEFAULT 'chat',
    sent_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_message_sender FOREIGN KEY (sender_id) REFERENCES User(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_message_receiver FOREIGN KEY (receiver_id) REFERENCES User(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 8. WalletTransaction
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS WalletTransaction (
    transaction_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    type            ENUM('Credit', 'Debit') NOT NULL,
    booking_id      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES User(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_wallet_booking FOREIGN KEY (booking_id) REFERENCES Booking(booking_id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- ---------------------------------------------------------------
-- 9. TutorAvailability (a tutor's free time slots)
--    capacity = how many learners can book this slot:
--    1 = Solo session, >1 = Group session.
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS TutorAvailability (
    availability_id INT AUTO_INCREMENT PRIMARY KEY,
    tutor_id INT NOT NULL,
    available_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    capacity INT NOT NULL DEFAULT 1,
    mode ENUM('Online', 'Physical') NOT NULL DEFAULT 'Physical',
    meeting_link VARCHAR(255) NULL,   -- for Online sessions (Zoom/Meet/etc.)
    location VARCHAR(255) NULL,        -- for Physical sessions (room/place)
    resources TEXT NULL,               -- notes / source links the tutor shares
    outcomes TEXT NULL,                -- what the learner will get out of the session
    status ENUM('Active', 'Cancelled') NOT NULL DEFAULT 'Active',
    visibility ENUM('Public', 'Private') NOT NULL DEFAULT 'Public',
    share_token VARCHAR(40) NULL,      -- invite link token for Private slots
    CONSTRAINT fk_availability_tutor FOREIGN KEY (tutor_id) REFERENCES User(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Priority re-grab: when a tutor cancels a booked slot, the affected
-- learners can be given first dibs on the tutor's next slot for 12 hours
-- before it opens to everyone else.
CREATE TABLE IF NOT EXISTS SlotPriority (
    priority_id    INT AUTO_INCREMENT PRIMARY KEY,
    tutor_id       INT NOT NULL,
    learner_id     INT NOT NULL,
    origin_slot_id INT NULL,           -- the cancelled slot
    new_slot_id    INT NULL,           -- the replacement slot they're offered
    status         ENUM('Waiting', 'Offered', 'Used', 'Expired') NOT NULL DEFAULT 'Waiting',
    expires_at     DATETIME NULL,      -- 12h deadline once offered
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_priority_tutor FOREIGN KEY (tutor_id) REFERENCES User(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_priority_learner FOREIGN KEY (learner_id) REFERENCES User(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Booking -> TutorAvailability link (declared here because both tables
-- now exist; ON DELETE SET NULL keeps historical bookings if a slot is removed)
ALTER TABLE Booking
    ADD CONSTRAINT fk_booking_availability
    FOREIGN KEY (availability_id) REFERENCES TutorAvailability(availability_id) ON DELETE SET NULL;
-- ---------------------------------------------------------------
-- 10. MeritRequest (tutor converts platform credits -> university merits)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS MeritRequest (
    merit_request_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    credits_amount   DECIMAL(10,2) NOT NULL,
    merit_points     INT NOT NULL,
    status           ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_merit_user FOREIGN KEY (user_id) REFERENCES User(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 11. GroupClass + GroupEnrollment (one tutor, many learners)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS GroupClass (
    group_class_id  INT AUTO_INCREMENT PRIMARY KEY,
    tutor_id        INT NOT NULL,
    skill_id        INT NOT NULL,
    title           VARCHAR(255) NOT NULL,
    class_date      DATETIME NOT NULL,
    duration        INT NOT NULL,
    capacity        INT NOT NULL,
    price_per_seat  DECIMAL(10,2) NOT NULL,
    status          ENUM('Open', 'Cancelled') NOT NULL DEFAULT 'Open',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_groupclass_tutor FOREIGN KEY (tutor_id) REFERENCES User(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_groupclass_skill FOREIGN KEY (skill_id) REFERENCES Skill(skill_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS GroupEnrollment (
    enrollment_id   INT AUTO_INCREMENT PRIMARY KEY,
    group_class_id  INT NOT NULL,
    learner_id      INT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_enroll_class FOREIGN KEY (group_class_id) REFERENCES GroupClass(group_class_id) ON DELETE CASCADE,
    CONSTRAINT fk_enroll_learner FOREIGN KEY (learner_id) REFERENCES User(user_id) ON DELETE CASCADE,
    CONSTRAINT uq_enroll UNIQUE (group_class_id, learner_id)
) ENGINE=InnoDB;

-- =================================================================
-- SEED DATA (matches the mock JSON used in PR2, so demo accounts
-- behave the same way once you switch from mock data to this DB)
-- =================================================================

-- Passwords below are real bcrypt hashes generated with PHP's password_hash().
-- All demo tutor/learner accounts use "password123", admin uses "admin123".
-- (Regenerate any time with: php -r "echo password_hash('password123', PASSWORD_BCRYPT);")
INSERT INTO User (name, email, password_hash, role, faculty, photo_url, bio, wallet_balance, is_verified) VALUES
('Aisyah Rahman', 'aisyah@graduate.utm.my', '$2y$10$WNGTsoDxOx/22BSXYxzHYuoOyA00xbPYw40DFIk3XQ5rqqdx9Q7bq', 'tutor', 'Computing', 'https://i.pravatar.cc/150?img=47', 'Final year Software Engineering student. I love breaking down OOP and Vue.js into simple steps.', 0.00, 1),
('Marcus Tan', 'marcus@graduate.utm.my', '$2y$10$WNGTsoDxOx/22BSXYxzHYuoOyA00xbPYw40DFIk3XQ5rqqdx9Q7bq', 'tutor', 'Computing', 'https://i.pravatar.cc/150?img=12', 'Calculus and Discrete Math tutor. 3 years of peer tutoring experience.', 0.00, 1),
('Nur Izzati', 'izzati@graduate.utm.my', '$2y$10$WNGTsoDxOx/22BSXYxzHYuoOyA00xbPYw40DFIk3XQ5rqqdx9Q7bq', 'tutor', 'Built Environment & Surveying', 'https://i.pravatar.cc/150?img=32', 'Native Cantonese speaker. I teach conversational Cantonese for travel and work.', 0.00, 0),
('Zhao Zhengyi', 'zhengyi@graduate.utm.my', '$2y$10$WNGTsoDxOx/22BSXYxzHYuoOyA00xbPYw40DFIk3XQ5rqqdx9Q7bq', 'learner', 'Computing', 'https://i.pravatar.cc/150?img=5', 'Looking for help with OOP assignments this semester.', 50.00, 0),
('Admin User', 'admin@skillswap.my', '$2y$10$OcOTvppRPYoznpRzVXJdw.B0jcdi56l3fIJDF8bZGtBEG94giz0cu', 'admin', '-', 'https://i.pravatar.cc/150?img=68', 'Platform administrator.', 0.00, 1);

INSERT INTO Skill (name, category) VALUES
('Object-Oriented Programming', 'Academic'),
('Calculus', 'Academic'),
('Vue.js', 'Academic'),
('Photoshop', 'Non-Academic'),
('Cantonese', 'Non-Academic'),
('Public Speaking', 'Non-Academic');

INSERT INTO UserSkill (user_id, skill_id, hourly_rate, level, description) VALUES
(1, 1, 15.00, 'Advanced', 'I will walk you through inheritance, polymorphism, and design patterns with real code examples.'),
(1, 3, 18.00, 'Advanced', 'From components to Pinia state management - built for beginners.'),
(2, 2, 12.00, 'Intermediate', 'Limits, derivatives, and integrals explained with past-year exam questions.'),
(3, 5, 10.00, 'Native', 'Learn everyday conversational Cantonese, no textbook needed.'),
(3, 4, 14.00, 'Intermediate', 'Photo editing basics to portfolio-ready compositing.');

INSERT INTO Booking (learner_id, tutor_id, skill_id, booking_date, duration, status, total_amount, recording_url) VALUES
(4, 1, 1, '2026-06-25 14:00:00', 1, 'Pending', 15.00, NULL),
(4, 2, 2, '2026-06-15 10:00:00', 2, 'Completed', 24.00, 'https://meet.google.com/example-link');

INSERT INTO Review (booking_id, rating, comment) VALUES
(2, 5, 'Marcus explained derivatives so clearly, I finally understand chain rule!');

INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES
(2, 24.00, 'Credit', 2),
(4, 24.00, 'Debit', 2);
