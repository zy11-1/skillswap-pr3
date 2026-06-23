# SkillSwap — PR3 Final Demo

Peer-to-peer tutoring marketplace for UTM students.
Group: Threeway Chaos | SCSM2223-04

This delivery has two folders:

- **`backend/`** — PHP Slim 4 + PDO + MySQL + JWT REST API
- **`frontend/`** — Vue 3 app (now talking to the real backend instead
  of PR2's mock JSON), plus the Capacitor Android project

## Quick start (run both together)

```bash
# 1. Backend
cd backend
composer install
mysql -u root -p < database/schema.sql
cp .env.example .env          # edit DB credentials + JWT_SECRET
composer start                # runs on http://localhost:8080

# 2. Frontend (separate terminal)
cd frontend
cp .env.example .env          # points VITE_API_URL at the backend above
npm install
npm run dev                   # runs on http://localhost:5173
```

Open the frontend URL and log in with any demo account (see
`backend/README.md` for the list — same accounts as PR2).

## What's new vs PR2

PR2 was UI-only with mock JSON data and a fake login token. PR3 adds:

- A real PHP Slim 4 REST API (`backend/`)
- A real MySQL database (8 tables, matching PR1's data dictionary)
- Real JWT authentication, issued and verified server-side
- Server-side price calculation for bookings (never trusts the client)
- A real wallet ledger (`WalletTransaction` table) instead of a
  computed-on-the-fly summary
- Role-based access control enforced on the backend, not just hidden
  in the UI
- A Capacitor-wrapped Android project (`frontend/android/`)

See `backend/README.md` and `frontend/README.md` for full details on
each half, including the security notes and what was tested.

## Before you present

Two documents in `backend/` you should read before the demo:

- **`backend/CORE_DEMO_CHECKLIST.md`** — the 6 flows your demo
  actually depends on (Register, Login, JWT, Booking create, Booking
  status update, Admin verify), with exact steps and what to expect
  at each one. Run through this once end-to-end before presenting.
- **`backend/DEFERRED_FEATURES.md`** — Messaging, RecordingVault, and
  tutor self-service verification submission were scoped in PR1 but
  not built in PR3. This doc has ready answers for if you're asked
  about them, plus the reasoning for why the cut was reasonable.

## Deployment targets (per PR1's tech stack)

- Frontend → Vercel
- Backend + MySQL → Railway

Both READMEs have a short deployment section. The full PR3 deliverable
also requires the deployed public URL and a runnable Android build —
budget time for that after local testing is solid.
