# PR3 — Feature Status

The three features originally deferred in PR3 have since been
**implemented** on the `feature/complete-proposal-features` branch,
along with the rest of the proposal's Should-Have and Stretch goals.

## Now implemented

### 1. Messaging (Tutor ↔ Learner chat) — DONE
`MessageController` with `GET/POST /api/messages` (conversations, thread,
send) and a polling-based `MessagesView`. "Message" button on tutor
profiles starts a conversation.

### 2. RecordingVault (session recording links) — DONE
`PATCH /api/bookings/{id}/recording` lets the tutor attach an unlisted
recording link to a Completed session; the learner sees a "Watch
recording" link in My Bookings.

### 3. Tutor self-service verification submission — DONE
`VerificationController` accepts a multipart document upload
(`POST /api/verification`), stored under `public/uploads`. Admins review
document requests and approve/reject them
(`/api/admin/verifications/requests`), which sets `is_verified`.

## Other proposal features completed in the same branch

- Dual-mode account (one login acts as Learner and Tutor, shared wallet)
- 10% platform commission on completed sessions and group enrolments
- Tutor skill management (UserSkill CRUD)
- Marketplace faculty + rating filters
- Forced review prompt after a Completed session
- Trending skills taxonomy
- Faculty-based tutor recommendations
- Merit conversion (credits → university merit points, admin-approved)
- Group classes (one tutor, many learners, discounted per-seat price)
- Calendar `.ics` export for booked sessions

## Note on testing

All endpoints were smoke-tested live against the local MAMP MySQL
database (every new route returns 200, and the group-class enrolment was
verified to settle the 90/10 commission split correctly). Run through
`CORE_DEMO_CHECKLIST.md` plus the new flows before presenting.
