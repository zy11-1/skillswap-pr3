# PR3 — Deferred Features (Read Before Presentation)

PR1's proposal covered more ground than PR3's time budget allowed.
This is intentional scope management, not something missed by
accident — but you should be ready to say so if asked, rather than
let the examiner discover a gap mid-demo.

## What's deferred to a future iteration

### 1. Messaging (Tutor ↔ Learner chat)

**Status:** Database table only. No controller, no route, no UI.

The `Message` table exists in `schema.sql` (sender_id, receiver_id,
body, sent_at — fully normalized, FKs in place), but there is no
`/api/messages` endpoint and no chat UI in the frontend.

**If asked:** "Messaging was scoped in PR1 as a stretch feature. We
prioritized the core booking lifecycle — search, book, accept,
complete, pay — for PR3, since that's the transaction backbone of the
marketplace. The schema is ready; the next iteration would add a
`MessageController` with `GET/POST /api/messages` and a simple
polling-based chat view, no WebSocket needed for an MVP."

### 2. RecordingVault (session recording links)

**Status:** Single column only (`Booking.recording_url`), unused by
the API or UI.

There's no upload/storage mechanism and no endpoint to set or fetch
it. The column exists so the data model doesn't need a migration
later, but nothing reads or writes it yet.

**If asked:** "RecordingVault was meant to store a link to the video
call recording after a session. We deferred it because it depends on
a third-party video integration (e.g. Google Meet/Zoom recording
links) that adds setup complexity outside the core booking flow. The
column is reserved in the schema so a future PR can add it without
changing the table structure."

### 3. Tutor self-service verification submission

**Status:** Admin can approve (`PATCH /api/admin/users/{id}/verify`),
but there's no `POST /api/verification` for a tutor to submit
documents in the first place.

Right now a tutor just registers and shows up in the admin's pending
queue automatically (any unverified tutor). There's no document
upload step.

**If asked:** "The approval side is implemented and demoable — admin
can see pending tutors and verify them, and verified status correctly
gates trust signals in the marketplace UI. What's missing is the
tutor-facing submission step (uploading a document via
`VerificationRequest`). We treated 'is_verified' as the
MVP signal and deferred the document-upload workflow, since file
upload/storage (and validation of what counts as proof) is a
separate, non-trivial feature."

## Why this is a reasonable scope cut, not a red flag

All three deferred features are **secondary to the core transaction
loop** that PR3 actually demonstrates end-to-end: register → login →
browse → book → accept/decline → complete → get paid (wallet). That
loop is fully wired, JWT-protected, and backed by real MySQL queries —
see `CORE_DEMO_CHECKLIST.md` for exactly what to click through.

If you have spare time before the demo and want one of these closed
instead of explained away, **Tutor self-service verification
submission** is the cheapest to add (it's a single INSERT + a
pending-list read you already half have). Messaging and RecordingVault
both need more new surface area (UI + possibly file storage) and are
riskier to bolt on last-minute.
