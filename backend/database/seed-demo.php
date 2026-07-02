<?php
// Demo data seeder — fills EVERY view of the app with realistic data:
//   marketplace tutors (each with open, bookable future slots), topic-locked
//   classes on the Upcoming board, pending requests for tutors to approve,
//   completed classes with reviews, tutor cancellations sitting in the admin
//   dispute queue, pending verification + merit requests, favourites,
//   notifications, and RM200 in every wallet.
//
// Usage:
//   php seed-demo.php                                  → local MAMP (127.0.0.1:8889)
//   php seed-demo.php "mysql://user:pass@host:port/db" → explicit target (e.g. live Railway)
//
// Idempotent: re-running replaces its own rows (bookings tagged with
// created_at='2000-01-01', slots with series_id='SEED', seed users found by
// email; their messages/verifications/merits are wiped and re-inserted).

const SEED_MARKER = '2000-01-01 00:00:00';
const SEED_SERIES = 'SEED';
const UNLIMITED_SEATS = 100000;
// bcrypt of "password123" — same hash the schema seed uses for demo accounts.
const DEMO_HASH = '$2y$10$WNGTsoDxOx/22BSXYxzHYuoOyA00xbPYw40DFIk3XQ5rqqdx9Q7bq';

if (isset($argv[1])) {
    $u = parse_url($argv[1]);
    if (!$u || ($u['scheme'] ?? '') !== 'mysql') {
        fwrite(STDERR, "Target must be a mysql:// URL.\n");
        exit(1);
    }
    $dbName = ltrim($u['path'] ?? '', '/');
    echo "Target: {$u['host']}:{$u['port']} / {$dbName}\n";
    $db = new PDO(
        "mysql:host={$u['host']};port={$u['port']};dbname={$dbName}",
        $u['user'], $u['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} else {
    echo "Target: local MAMP (127.0.0.1:8889 / skillswap)\n";
    $db = new PDO("mysql:host=127.0.0.1;port=8889;dbname=skillswap", "root", "root", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

// ── 1. Seed users (found/created by email) ────────────────────────────────
$seedUsers = [
    // tutors
    'farah.aziz@graduate.utm.my'    => ['Farah Aziz', 'tutor', 'Management', 'Year 3', 25, 'Debate club president. I coach public speaking and presentation skills for class pitches and interviews.', 1],
    'daniel.wong@graduate.utm.my'   => ['Daniel Wong', 'tutor', 'Computing', 'Year 2', 13, 'Math olympiad background. I tutor Calculus and OOP with lots of worked examples.', 1],
    'priya.nair@graduate.utm.my'    => ['Priya Nair', 'tutor', 'Computing', 'Year 4', 41, 'Frontend intern at a startup. I teach Vue.js the way I wish someone taught me.', 0],
    'hafiz.ismail@graduate.utm.my'  => ['Hafiz Ismail', 'tutor', 'Built Environment & Surveying', 'Year 3', 59, 'Freelance designer. Photoshop from zero to portfolio-ready.', 0],
    // learners
    'meiling.chua@graduate.utm.my'  => ['Mei Ling Chua', 'learner', 'Science', 'Year 1', 44, 'First year, trying to keep up with programming classes!', 0],
    'arjun.kumar@graduate.utm.my'   => ['Arjun Kumar', 'learner', 'Electrical Engineering', 'Year 2', 15, 'Need calculus help before finals.', 0],
    'sofia.kamal@graduate.utm.my'   => ['Sofia Kamal', 'learner', 'Computing', 'Year 1', 26, 'Learning web dev on the side.', 0],
    'ethan.lee@graduate.utm.my'     => ['Ethan Lee', 'learner', 'Mechanical Engineering', 'Year 3', 53, 'Picking up design skills for my FYP posters.', 0],
    'amirah.hassan@graduate.utm.my' => ['Amirah Hassan', 'learner', 'Education', 'Year 2', 31, 'Want to learn Cantonese before my exchange semester.', 0],
];

$findUser = $db->prepare("SELECT user_id FROM User WHERE email = :email");
$insertUser = $db->prepare(
    "INSERT INTO User (name, email, password_hash, role, faculty, year_of_study, photo_url, bio, wallet_balance, is_verified)
     VALUES (:name, :email, :hash, :role, :faculty, :year, :photo, :bio, 200.00, :verified)"
);
$seedIds = [];
foreach ($seedUsers as $email => [$name, $role, $faculty, $year, $img, $bio, $verified]) {
    $findUser->execute(['email' => $email]);
    $id = $findUser->fetchColumn();
    if (!$id) {
        $insertUser->execute([
            'name' => $name, 'email' => $email, 'hash' => DEMO_HASH, 'role' => $role,
            'faculty' => $faculty, 'year' => $year, 'photo' => "https://i.pravatar.cc/150?img={$img}",
            'bio' => $bio, 'verified' => $verified,
        ]);
        $id = $db->lastInsertId();
    }
    $seedIds[$email] = (int) $id;
}
$U = fn(string $email): int => $seedIds[$email];

// Original accounts (present in every install via schema.sql).
$aisyah = 1; $marcus = 2; $izzati = 3; $zhao = 4;

// ── 2. Clear previous seed rows ───────────────────────────────────────────
$idList = implode(',', $seedIds);
$db->exec("DELETE FROM WalletTransaction WHERE booking_id IN (SELECT booking_id FROM Booking WHERE created_at = '" . SEED_MARKER . "')");
$db->exec("DELETE FROM Booking WHERE created_at = '" . SEED_MARKER . "'"); // reviews cascade
$db->exec("DELETE FROM TutorAvailability WHERE series_id = '" . SEED_SERIES . "'");
$db->exec("DELETE FROM Message WHERE sender_id IN ($idList) OR receiver_id IN ($idList)");
$db->exec("DELETE FROM VerificationRequest WHERE user_id IN ($idList) OR user_id = $izzati");
$db->exec("DELETE FROM MeritRequest WHERE user_id IN ($idList) OR user_id IN ($aisyah, $marcus)");
$db->exec("DELETE FROM UserSkill WHERE user_id IN ($idList)");

// ── 3. Skills the seed tutors offer ───────────────────────────────────────
$insertSkill = $db->prepare(
    "INSERT INTO UserSkill (user_id, skill_id, hourly_rate, level, description) VALUES (:uid, :sid, :rate, :level, :descr)"
);
$tutorSkills = [
    ['farah.aziz@graduate.utm.my', 6, 14.00, 'Advanced', 'Beat stage fright: structure a talk, own the room, and handle Q&A with confidence.'],
    ['daniel.wong@graduate.utm.my', 2, 13.00, 'Advanced', 'Limits to integrals with past-year UTM papers. We solve until it clicks.'],
    ['daniel.wong@graduate.utm.my', 1, 16.00, 'Intermediate', 'OOP fundamentals in Java: classes, inheritance, interfaces, and common exam traps.'],
    ['priya.nair@graduate.utm.my', 3, 17.00, 'Advanced', 'Vue 3 composition API, components, and state — build a real mini-app in our sessions.'],
    ['hafiz.ismail@graduate.utm.my', 4, 12.00, 'Intermediate', 'Photoshop layers, masking, and colour grading for posters and social media.'],
];
foreach ($tutorSkills as [$email, $sid, $rate, $level, $descr]) {
    $insertSkill->execute(['uid' => $U($email), 'sid' => $sid, 'rate' => $rate, 'level' => $level, 'descr' => $descr]);
}

// ── 4. Wallets: RM200 for everyone ────────────────────────────────────────
$db->exec("UPDATE User SET wallet_balance = 200.00");

// ── 5. Slots + bookings ───────────────────────────────────────────────────
$insertSlot = $db->prepare(
    "INSERT INTO TutorAvailability
       (tutor_id, available_date, start_time, end_time, capacity, base_price, mode, meeting_link, location,
        status, visibility, locked_skill_id, topics_covered, needs_syllabus, series_id)
     VALUES (:tutor, :date, :start, :end, " . UNLIMITED_SEATS . ", :base, :mode, :link, :loc,
        :status, 'Public', :skill, :topics, :needs, '" . SEED_SERIES . "')"
);
$insertBooking = $db->prepare(
    "INSERT INTO Booking (learner_id, tutor_id, skill_id, booking_date, duration, status, total_amount, is_paid,
                          availability_id, dispute_status, dispute_reason, created_at)
     VALUES (:learner, :tutor, :skill, :date, :duration, :status, :amount, :paid, :slot, :dstatus, :dreason, '" . SEED_MARKER . "')"
);
$insertReview = $db->prepare("INSERT INTO Review (booking_id, rating, comment) VALUES (:bid, :rating, :comment)");
$insertTxn = $db->prepare("INSERT INTO WalletTransaction (user_id, amount, type, booking_id) VALUES (:uid, :amount, :type, :bid)");
$insertMsg = $db->prepare("INSERT INTO Message (sender_id, receiver_id, body, category) VALUES (:sender, :receiver, :body, 'booking')");

$locations = ['UTM Library, Level 2', 'Faculty of Computing, MPK3', 'N28 Study Pod B', 'Arked Meranti, back tables'];
$slotCount = 0;

/** Creates a slot; $dayOffset negative = past. Returns availability_id. */
$makeSlot = function (int $tutor, int $dayOffset, int $hour, int $len, float $base, string $mode, ?int $skill, ?string $topics, int $needs = 0, string $status = 'Active') use ($db, $insertSlot, $locations, &$slotCount): int {
    $t = strtotime(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days ' . $hour . ':00');
    $insertSlot->execute([
        'tutor' => $tutor, 'date' => date('Y-m-d', $t), 'start' => date('H:i:s', $t), 'end' => date('H:i:s', $t + $len * 3600),
        'base' => $base, 'mode' => $mode,
        'link' => $mode === 'Online' ? 'https://meet.google.com/skillswap-demo' : null,
        'loc' => $mode === 'Physical' ? $locations[$slotCount % count($locations)] : null,
        'status' => $status, 'skill' => $skill, 'topics' => $topics, 'needs' => $needs,
    ]);
    $slotCount++;
    return (int) $db->lastInsertId();
};
/** Creates a booking on a slot. Returns booking_id. */
$makeBooking = function (int $learner, int $tutor, int $skill, int $slotId, int $dayOffset, int $hour, int $len, float $amount, string $status, string $dstatus = 'none', ?string $dreason = null, int $paid = 1) use ($db, $insertBooking): int {
    $t = strtotime(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days ' . $hour . ':00');
    $insertBooking->execute([
        'learner' => $learner, 'tutor' => $tutor, 'skill' => $skill, 'date' => date('Y-m-d H:i:s', $t),
        'duration' => $len, 'status' => $status, 'amount' => $amount, 'paid' => $paid,
        'slot' => $slotId, 'dstatus' => $dstatus, 'dreason' => $dreason,
    ]);
    return (int) $db->lastInsertId();
};

// Tutor roster: [tutor_id, main skill, skill name, base RM/hr]
$roster = [
    [$aisyah, 1, 'Object-Oriented Programming', 15.0],
    [$marcus, 2, 'Calculus', 12.0],
    [$izzati, 5, 'Cantonese', 10.0],
    [$U('farah.aziz@graduate.utm.my'), 6, 'Public Speaking', 14.0],
    [$U('daniel.wong@graduate.utm.my'), 2, 'Calculus', 13.0],
    [$U('priya.nair@graduate.utm.my'), 3, 'Vue.js', 17.0],
    [$U('hafiz.ismail@graduate.utm.my'), 4, 'Photoshop', 12.0],
];

// Learner pool: every non-admin account (so each shows learner-side history),
// used round-robin, skipping the class's own tutor.
$pool = array_map('intval', $db->query(
    "SELECT user_id FROM User WHERE role <> 'admin' ORDER BY user_id"
)->fetchAll(PDO::FETCH_COLUMN));
$poolIdx = 0;
$nextLearner = function (int $notTutor) use ($pool, &$poolIdx): int {
    do {
        $lid = $pool[$poolIdx % count($pool)];
        $poolIdx++;
    } while ($lid === $notTutor);
    return $lid;
};

$reviews = [
    'Explained OOP inheritance with real code — it finally clicked!',
    'We worked through past-year calculus papers step by step. Super helpful.',
    'Made Cantonese tones fun and easy to remember. 唔該!',
    'Gave me practical tricks to calm my nerves before presenting.',
    'Patient tutor — happily explains a step twice if you need it.',
    'Debugged my Vue project live with me. Learned a ton.',
    'Good content, but the session started 15 minutes late.',
];
$ratings = [5, 5, 4, 5, 4, 5, 3];

foreach ($roster as $i => [$tid, $skill, $skillName, $base]) {
    // Two OPEN future slots (no topic yet — bookable by anyone, first booker picks the topic).
    $makeSlot($tid, 2 + $i, 10 + ($i % 3) * 2, 1 + ($i % 2), $base, $i % 2 ? 'Online' : 'Physical', null, null);
    $makeSlot($tid, 5 + $i, 15 + ($i % 2) * 2, 1, $base + 2, $i % 2 ? 'Physical' : 'Online', null, null);

    // One topic-locked future class with a published syllabus + 2 accepted students
    // (fills the Upcoming-classes board and the "X booked" counters).
    $len = 1 + ($i % 2);
    $topics = "$skillName: core concepts, worked examples, and a Q&A at the end.";
    $slot = $makeSlot($tid, 3 + $i, 16, $len, $base, $i % 2 ? 'Online' : 'Physical', $skill, $topics);
    foreach ([0, 1] as $k) {
        $lid = $nextLearner($tid);
        $bid = $makeBooking($lid, $tid, $skill, $slot, 3 + $i, 16, $len, round($base * $len, 2), 'Accepted');
        $insertTxn->execute(['uid' => $lid, 'amount' => round($base * $len, 2), 'type' => 'Debit', 'bid' => $bid]);
    }

    // One completed past class with a review.
    $slot = $makeSlot($tid, -(3 + $i), 14, $len, $base, $i % 2 ? 'Physical' : 'Online', $skill, "$skillName: recap, common pitfalls, and practice problems.");
    $lid = $nextLearner($tid);
    $amount = round($base * $len, 2);
    $bid = $makeBooking($lid, $tid, $skill, $slot, -(3 + $i), 14, $len, $amount, 'Completed');
    $insertTxn->execute(['uid' => $lid, 'amount' => $amount, 'type' => 'Debit', 'bid' => $bid]);
    $insertTxn->execute(['uid' => $tid, 'amount' => round($amount * 0.9, 2), 'type' => 'Credit', 'bid' => $bid]);
    $insertReview->execute(['bid' => $bid, 'rating' => $ratings[$i], 'comment' => $reviews[$i]]);
}

// ── 5b. Ring pass: EVERY non-admin account gets a past + future class as
//        TUTOR (learner = the next account in the ring), which also gives
//        every account a past + future class as LEARNER — so both calendar
//        modes are populated for whoever the professor logs in as.
$offeredMap = [];
foreach ($db->query("SELECT user_id, skill_id FROM UserSkill") as $r) {
    $offeredMap[(int) $r['user_id']][] = (int) $r['skill_id'];
}
$skillNames = $db->query("SELECT skill_id, name FROM Skill")->fetchAll(PDO::FETCH_KEY_PAIR);
$allSkillIds = array_keys($skillNames);
$n = count($pool);
foreach ($pool as $i => $tid) {
    $lid = $pool[($i + 1) % $n];
    if ($lid === $tid) {
        continue;
    }
    $skill = $offeredMap[$tid][0] ?? $allSkillIds[$i % count($allSkillIds)];
    $skillName = $skillNames[$skill];
    $len = 1 + ($i % 2);
    $base = 12.0 + ($i % 3) * 2;
    $mode = ($i % 2) ? 'Online' : 'Physical';
    $amount = round($base * $len, 2);

    // Past completed class.
    $slot = $makeSlot($tid, -(2 + ($i % 15)), 9 + ($i % 4), $len, $base, $mode, $skill, "$skillName: recap, common pitfalls, and practice problems.");
    $bid = $makeBooking($lid, $tid, $skill, $slot, -(2 + ($i % 15)), 9 + ($i % 4), $len, $amount, 'Completed');
    $insertTxn->execute(['uid' => $lid, 'amount' => $amount, 'type' => 'Debit', 'bid' => $bid]);
    $insertTxn->execute(['uid' => $tid, 'amount' => round($amount * 0.9, 2), 'type' => 'Credit', 'bid' => $bid]);

    // Future accepted class.
    $slot = $makeSlot($tid, 2 + ($i % 15), 17, $len, $base, $mode, $skill, "$skillName: core concepts, worked examples, and a Q&A at the end.");
    $bid = $makeBooking($lid, $tid, $skill, $slot, 2 + ($i % 15), 17, $len, $amount, 'Accepted');
    $insertTxn->execute(['uid' => $lid, 'amount' => $amount, 'type' => 'Debit', 'bid' => $bid]);
}

// ── 6. Pending requests for tutors to approve ─────────────────────────────
// (a) First booker just picked a topic on Aisyah's slot → she must publish a
//     syllabus before anyone else can join (needs_syllabus = 1).
$slot = $makeSlot($aisyah, 4, 11, 1, 18.0, 'Online', 3, null, 1);
$makeBooking($U('sofia.kamal@graduate.utm.my'), $aisyah, 3, $slot, 4, 11, 1, 18.00, 'Pending');
$insertMsg->execute(['sender' => $U('sofia.kamal@graduate.utm.my'), 'receiver' => $aisyah,
    'body' => 'A student started your group class on "Vue.js". Add a \'Topics covered\' description so others can join.']);

// (b) Second joiner waiting for approval on Daniel's published class.
$daniel = $U('daniel.wong@graduate.utm.my');
$slot = $makeSlot($daniel, 6, 14, 2, 13.0, 'Physical', 2, 'Calculus: integration techniques, area under curves, and exam drills.');
$makeBooking($U('arjun.kumar@graduate.utm.my'), $daniel, 2, $slot, 6, 14, 2, 26.00, 'Pending');

// (c) Pending request on Priya's published Vue class.
$priya = $U('priya.nair@graduate.utm.my');
$slot = $makeSlot($priya, 7, 20, 1, 17.0, 'Online', 3, 'Vue.js: components, props, and reactive state — hands-on.');
$makeBooking($U('ethan.lee@graduate.utm.my'), $priya, 3, $slot, 7, 20, 1, 17.00, 'Pending');

// ── 7. Cancellations + admin dispute queue ────────────────────────────────
// (a) Marcus cancelled a whole class slot → auto-flagged, learner refunded + notified.
$slot = $makeSlot($marcus, 3, 9, 2, 13.0, 'Physical', 2, 'Calculus: derivatives crash course.', 0, 'Cancelled');
$bid = $makeBooking($U('meiling.chua@graduate.utm.my'), $marcus, 2, $slot, 3, 9, 2, 26.00, 'Cancelled', 'open', 'Auto-flagged: the tutor cancelled the class slot.', 0);
$insertTxn->execute(['uid' => $U('meiling.chua@graduate.utm.my'), 'amount' => 26.00, 'type' => 'Credit', 'bid' => $bid]);
$insertMsg->execute(['sender' => $marcus, 'receiver' => $U('meiling.chua@graduate.utm.my'),
    'body' => 'Your session on ' . date('Y-m-d', strtotime('+3 days')) . ' 09:00 was cancelled by the tutor. Your prepayment of RM26.00 has been refunded to your wallet. An admin has been notified.']);

// (b) Learner-filed dispute: tutor never showed up.
$hafiz = $U('hafiz.ismail@graduate.utm.my');
$slot = $makeSlot($hafiz, -2, 19, 1, 12.0, 'Online', 4, 'Photoshop: masking and compositing basics.');
$makeBooking($U('ethan.lee@graduate.utm.my'), $hafiz, 4, $slot, -2, 19, 1, 12.00, 'Accepted', 'open', 'Tutor never joined the online session. Requesting a refund.');
$insertMsg->execute(['sender' => $U('ethan.lee@graduate.utm.my'), 'receiver' => $hafiz,
    'body' => 'A dispute has been raised for one of your sessions. An admin will review and get back to you.']);

// (c) Already-resolved dispute (history in the admin queue).
$slot = $makeSlot($izzati, -6, 17, 1, 10.0, 'Physical', 5, 'Cantonese: ordering food and small talk.', 0, 'Cancelled');
$bid = $makeBooking($U('amirah.hassan@graduate.utm.my'), $izzati, 5, $slot, -6, 17, 1, 10.00, 'Cancelled', 'resolved_refund', 'Class location changed last minute and I could not attend.', 0);
$insertTxn->execute(['uid' => $U('amirah.hassan@graduate.utm.my'), 'amount' => 10.00, 'type' => 'Credit', 'bid' => $bid]);

// ── 8. Verification requests (admin → Verifications tab) ─────────────────
$insertVerif = $db->prepare("INSERT INTO VerificationRequest (user_id, document_url, status) VALUES (:uid, :doc, :status)");
$insertVerif->execute(['uid' => $priya, 'doc' => 'https://drive.google.com/file/d/demo-priya-student-card', 'status' => 'Pending']);
$insertVerif->execute(['uid' => $hafiz, 'doc' => 'https://drive.google.com/file/d/demo-hafiz-student-card', 'status' => 'Pending']);
$insertVerif->execute(['uid' => $izzati, 'doc' => 'https://drive.google.com/file/d/demo-izzati-student-card', 'status' => 'Pending']);
$insertVerif->execute(['uid' => $U('farah.aziz@graduate.utm.my'), 'doc' => 'https://drive.google.com/file/d/demo-farah-student-card', 'status' => 'Approved']);

// ── 9. Merit requests (admin → Merits tab) ────────────────────────────────
$insertMerit = $db->prepare(
    "INSERT INTO MeritRequest (user_id, classes_completed, students_helped, avg_rating, review_count, status)
     VALUES (:uid, :classes, :students, :rating, :reviews, :status)"
);
$insertMerit->execute(['uid' => $aisyah, 'classes' => 22, 'students' => 17, 'rating' => 4.8, 'reviews' => 16, 'status' => 'Pending']);
$insertMerit->execute(['uid' => $daniel, 'classes' => 20, 'students' => 21, 'rating' => 4.6, 'reviews' => 15, 'status' => 'Pending']);
$insertMerit->execute(['uid' => $marcus, 'classes' => 25, 'students' => 20, 'rating' => 4.7, 'reviews' => 18, 'status' => 'Approved']);

// ── 10. Favourites + a couple of friendly notifications ──────────────────
$insertFav = $db->prepare("INSERT IGNORE INTO Favorite (user_id, tutor_id) VALUES (:uid, :tid)");
foreach ([
    [$U('meiling.chua@graduate.utm.my'), $aisyah],
    [$U('sofia.kamal@graduate.utm.my'), $priya],
    [$U('arjun.kumar@graduate.utm.my'), $marcus],
    [$U('ethan.lee@graduate.utm.my'), $daniel],
    [$zhao, $aisyah],
] as [$uid, $tid]) {
    $insertFav->execute(['uid' => $uid, 'tid' => $tid]);
}
$insertMsg->execute(['sender' => $daniel, 'receiver' => $U('arjun.kumar@graduate.utm.my'),
    'body' => 'Your session booking was accepted by the tutor.']);
$insertMsg->execute(['sender' => $priya, 'receiver' => $U('sofia.kamal@graduate.utm.my'),
    'body' => 'How was your recent session? Leave a quick review in My Classes.']);

// Admin inbox: the automatic settlement notifications the system sends when
// a group class completes (informational — no admin action needed).
$adminId = (int) $db->query("SELECT user_id FROM User WHERE role = 'admin' ORDER BY user_id LIMIT 1")->fetchColumn();
$insertSysMsg = $db->prepare("INSERT INTO Message (sender_id, receiver_id, body, category) VALUES (:sender, :receiver, :body, 'system')");
$insertSysMsg->execute(['sender' => $U('farah.aziz@graduate.utm.my'), 'receiver' => $adminId,
    'body' => 'Auto-settlement: "Public Speaking" class completed with 3 students — final price RM11.00 each, RM9.00 refunded automatically. No action needed.']);
$insertSysMsg->execute(['sender' => $daniel, 'receiver' => $adminId,
    'body' => 'Auto-settlement: "Calculus" class completed with 2 students — final price RM24.00 each, RM4.00 refunded automatically. No action needed.']);

// ── Summary ───────────────────────────────────────────────────────────────
echo "Seed users ensured: " . count($seedIds) . " (password: password123)\n";
foreach ([
    'TutorAvailability slots' => "SELECT COUNT(*) FROM TutorAvailability WHERE series_id = '" . SEED_SERIES . "'",
    '  … open future (no topic)' => "SELECT COUNT(*) FROM TutorAvailability WHERE series_id = '" . SEED_SERIES . "' AND locked_skill_id IS NULL AND TIMESTAMP(available_date, start_time) > NOW()",
    '  … on the Upcoming board' => "SELECT COUNT(*) FROM TutorAvailability WHERE series_id = '" . SEED_SERIES . "' AND status = 'Active' AND visibility = 'Public' AND locked_skill_id IS NOT NULL AND needs_syllabus = 0 AND TIMESTAMP(available_date, start_time) > NOW()",
    'Bookings' => "SELECT COUNT(*) FROM Booking WHERE created_at = '" . SEED_MARKER . "'",
    '  … pending approval' => "SELECT COUNT(*) FROM Booking WHERE created_at = '" . SEED_MARKER . "' AND status = 'Pending'",
    'Reviews' => "SELECT COUNT(*) FROM Review r JOIN Booking b ON b.booking_id = r.booking_id WHERE b.created_at = '" . SEED_MARKER . "'",
    'Open disputes (admin queue)' => "SELECT COUNT(*) FROM Booking WHERE dispute_status = 'open'",
    'Pending verifications' => "SELECT COUNT(*) FROM VerificationRequest WHERE status = 'Pending'",
    'Pending merit requests' => "SELECT COUNT(*) FROM MeritRequest WHERE status = 'Pending'",
] as $label => $sql) {
    echo str_pad($label, 30) . $db->query($sql)->fetchColumn() . "\n";
}
echo "Done.\n";
