# SkillSwap API — PR3 Backend

PHP Slim 4 + PDO + MySQL + JWT backend for the SkillSwap peer tutoring
marketplace. Group: Threeway Chaos | SCSM2223-04

## Tech stack

- PHP 8.1+, Slim 4 (routing/middleware), Slim PSR-7
- MySQL via PDO, **prepared statements only** (no string-built SQL anywhere)
- Hand-rolled JWT (HS256) - zero extra dependency, see `src/Utils/Jwt.php`
- bcrypt password hashing (`password_hash` / `password_verify`)

## Local setup (Laragon / XAMPP)

1. **Install dependencies**
   ```bash
   composer install
   ```

2. **Create the database**

   Open phpMyAdmin (or the MySQL CLI) and run:
   ```bash
   mysql -u root -p < database/schema.sql
   ```
   This creates the `skillswap` database, all 8 tables, and seeds demo
   data (same accounts as PR2, so existing demo logins still work).

3. **Configure environment**
   ```bash
   cp .env.example .env
   ```
   Edit `.env` and set `DB_USER` / `DB_PASS` to match your local MySQL
   setup (Laragon default is usually `root` with no password).
   **Generate a real `JWT_SECRET`** - don't ship the placeholder value:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```

4. **Run the API**
   ```bash
   composer start
   ```
   This starts PHP's built-in server at `http://localhost:8080`.
   In Laragon, you can alternatively point a virtual host at `public/`.

5. **Verify it's running**
   ```bash
   curl http://localhost:8080/api/health
   # {"status":"ok"}
   ```

## Demo accounts (same as PR2)

| Role | Email | Password |
|---|---|---|
| Tutor (verified) | aisyah@graduate.utm.my | password123 |
| Tutor (verified) | marcus@graduate.utm.my | password123 |
| Tutor (unverified) | izzati@graduate.utm.my | password123 |
| Learner | zhengyi@graduate.utm.my | password123 |
| Admin | admin@skillswap.my | admin123 |

## API endpoints

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `/api/health` | - | Health check |
| POST | `/api/auth/register` | - | Create account |
| POST | `/api/auth/login` | - | Login, returns JWT |
| GET | `/api/auth/me` | JWT | Current user profile |
| GET | `/api/tutors` | - | Marketplace listing (search/filter via query params) |
| GET | `/api/tutors/{id}` | - | Tutor profile + offerings + reviews |
| GET | `/api/skills` | - | Skill list (for filter dropdown) |
| GET | `/api/bookings` | JWT | My bookings (as learner or tutor) |
| POST | `/api/bookings` | JWT | Create a booking request |
| PATCH | `/api/bookings/{id}/status` | JWT (tutor) | Accept / decline / complete a booking |
| GET | `/api/wallet` | JWT | My wallet balance |
| GET | `/api/wallet/transactions` | JWT | My transaction history |
| GET | `/api/admin/users` | JWT (admin) | All users |
| GET | `/api/admin/verifications/pending` | JWT (admin) | Tutors awaiting verification |
| PATCH | `/api/admin/users/{id}/verify` | JWT (admin) | Approve a tutor |

Protected routes expect `Authorization: Bearer <token>`.

## Security notes (what's implemented and why)

- **Passwords**: bcrypt via `password_hash()`, never stored or logged in
  plaintext.
- **SQL injection**: every query uses PDO prepared statements with bound
  parameters - no user input is ever concatenated into SQL.
- **JWT**: signed with HS256, includes an `exp` claim, signature is
  verified with a constant-time comparison (`hash_equals`) to resist
  timing attacks.
- **RBAC**: `JwtAuthMiddleware` decodes the token and attaches
  `user_id`/`role` to the request; `RoleMiddleware` then restricts
  admin-only routes. Booking/wallet endpoints additionally filter every
  query by the authenticated user's own `user_id`, so one user can never
  read another user's bookings or balance.
- **Price tampering**: booking cost is always calculated server-side
  from the tutor's stored hourly rate - the client never gets to send
  a price.
- **State machine**: bookings can only move Pending -> Accepted/Cancelled
  -> Completed. Skipping states or reversing them is rejected.

## What was tested before delivery

Since this sandbox can't install Composer packages (no network access
to Packagist), the SQL schema and business logic were verified against
a local SQLite copy of the schema using plain PDO:

- All 8 tables create cleanly with foreign keys enforced
- Marketplace join query (UserSkill + User + Skill + Review aggregation)
- Booking creation with server-side price calculation
- Full booking state machine, including rejected illegal transitions
- Wallet settlement math on completion (tutor credited, learner debited)
- SQL injection attempt via a crafted email string (blocked)
- bcrypt password verification (correct password accepted, wrong
  password rejected)

You'll still want to `composer install` and run it for real once on
your own machine before the PR3 demo, just to confirm Slim's routing
behaves the same way in your local environment. **See
`CORE_DEMO_CHECKLIST.md` for the exact 6 flows to verify, with
expected results for each.**

## Connecting the Vue frontend

In the PR2 Vue project, set `VITE_API_URL=http://localhost:8080` in a
`.env` file, then swap `mockApi.js` calls for real `axios` calls (see
the updated frontend in this delivery - `src/data/api.js` is the new
real-backend version, kept alongside the original mock for reference).
