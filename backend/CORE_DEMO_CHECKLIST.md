# Core Demo Checklist — Run This Before Presenting

The Slim framework itself (`vendor/`) couldn't be installed and run in
the sandbox this was built in (no internet access to Packagist there).
Everything in the controllers was logic-tested against a local SQLite
copy of the schema, but **the actual Slim routing layer has not been
run end-to-end yet.** Run through this checklist on your own machine
once `composer install` is done, before presenting.

Use your browser's dev tools (F12 → Network tab) or Postman to watch
these, or just use the frontend UI and confirm what you'd expect to
see at each step. curl examples are given so you can test the backend
in isolation, without the frontend in the way, if something looks
wrong.

## 1. Register

**UI:** Go to `/register`, fill the form, choose Learner or Tutor, submit.

**Expect:** Redirected straight into the marketplace, logged in. Check
phpMyAdmin → `User` table → your new row should be there with a
bcrypt hash (starts with `$2y$`) in `password_hash`, never your real
password in plaintext.

**curl:**
```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test1@graduate.utm.my","password":"test1234","role":"learner","faculty":"Computing"}'
```
Expect a `201` with `{"token": "...", "user": {...}}`. Try it twice
with the same email — second time should `409` with "Email is already
registered."

## 2. Login

**UI:** Go to `/login`, use a demo account, submit.

**Expect:** Redirected to marketplace, navbar shows your name/avatar.

**curl:**
```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"aisyah@graduate.utm.my","password":"password123"}'
```
Expect `200` with a token. Try a wrong password — expect `401` with a
generic "Invalid email or password" (not "wrong password" — this is
intentional, see backend README's security notes).

## 3. JWT works end-to-end

**Check:** Copy the `token` from the login response above, then:
```bash
curl http://localhost:8080/api/auth/me \
  -H "Authorization: Bearer PASTE_TOKEN_HERE"
```
Expect `200` with your user profile. Now try with no header, or a
garbage token — expect `401`.

**In the UI:** open dev tools → Application/Storage → Local Storage →
confirm `ss_token` is set after login. Refresh the page — you should
stay logged in (token persists).

## 4. Booking creation

**UI:** Log in as a learner (`zhengyi@graduate.utm.my`), go to
Marketplace, click into a tutor profile, click Book on an offering,
pick a future date/time, submit.

**Expect:** Success message, redirected to My Bookings, the new
booking shows with status "Pending". Check `Booking` table in
phpMyAdmin — `total_amount` should equal the tutor's `hourly_rate` ×
the duration you picked (this is calculated server-side — try editing
the request in dev tools to send a fake total_amount and confirm it's
ignored).

## 5. Booking status update (the state machine)

**UI:** Log out, log in as the tutor for that booking
(`aisyah@graduate.utm.my`), go to Tutor Dashboard, find the Pending
booking, click Accept. Status should flip to "Accepted". Click "Mark
as Completed" — status flips to "Completed".

**Expect:** After marking Completed, check the `User` table —
the tutor's `wallet_balance` should have gone up by the booking's
`total_amount`, and the learner's should have gone down by the same
amount. Check `WalletTransaction` table — two new rows (one Credit,
one Debit) tied to that `booking_id`.

**Edge case worth showing if asked about robustness:** try calling
the status update twice in a row to force an illegal transition
(e.g. Completed → Accepted) via curl:
```bash
curl -X PATCH http://localhost:8080/api/bookings/1/status \
  -H "Authorization: Bearer TUTOR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"Pending"}'
```
Expect `422` with an error like "Cannot move booking from 'Completed'
to 'Pending'."

## 6. Admin verify tutor

**UI:** Log in as admin (`admin@skillswap.my`), go to `/admin`. The
unverified demo tutor (Izzati) should appear in "Pending Tutor
Verifications". Click Verify.

**Expect:** Row disappears from the pending list, and her badge
in the marketplace (if you log back in as a learner and look her up)
now shows the verified checkmark. Check `User` table —
`is_verified` should be `1` for her row now.

**Also confirm the role gate works:** try hitting an admin endpoint
while logged in as a non-admin:
```bash
curl http://localhost:8080/api/admin/users \
  -H "Authorization: Bearer LEARNER_TOKEN"
```
Expect `403` — "You do not have permission to access this resource."

---

## If something fails

Most likely causes, in order of probability:

1. **`.env` not configured** — did you `copy .env.example .env` and
   fill in your real DB credentials + a generated `JWT_SECRET`?
2. **Database not seeded** — did `database/schema.sql` actually run
   without errors? Re-check phpMyAdmin for all 8 tables and that
   `User` has 5 rows.
3. **CORS** — if the frontend shows a network error in the browser
   console mentioning CORS, check that `CORS_ORIGIN` in the backend's
   `.env` matches the frontend's actual URL (`http://localhost:5173`
   by default).
4. **Backend not running** — `composer start` window needs to stay
   open in its own terminal the whole time you're testing.
