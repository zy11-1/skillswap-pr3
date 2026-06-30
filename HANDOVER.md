# SkillSwap — What I Built & How To Use It

_A handover guide: what changed from the original repo, which professor/proposal
requirements were missing and are now fixed, the full feature list, and a
screen-by-screen user manual._

---

## 0. Short description (the 30-second version)

I started from the original SkillSwap repo (the shared link). It had the **core
loop** — register/login, browse tutors, book a session, accept/complete, a basic
wallet, and admin tutor-verification — but most of the proposal's Should-Have and
Stretch features were **missing or only half-built**, and several Must-Haves were
incomplete.

I built the project out into a **complete, working peer-tutoring marketplace**:
one account that acts as both **Learner and Tutor** (with a mode toggle and shared
wallet), a full **Review & Rating** system, **rich availability** (Solo/Group
slots, capacity, auto-accept vs approval, online/physical, public/private invite
links, prepay/postpay), a **notification centre**, **in-app chat**, a unified
**calendar**, **favourite tutors**, **document verification**, a **performance-based
university-merit** application flow, the **10% platform commission** economy, and
mode-based **theming**. Everything runs on the required stack (Vue 3 + Pinia +
Router, PHP Slim 4 + PDO + MySQL + JWT, Capacitor-ready) with prepared statements
throughout.

---

## 1. What the original was missing vs what it is now

Cross-checked against the professor's brief (P2 SkillSwap + Common Requirements)
and our PR1 proposal.

| Requirement (professor / proposal) | Original repo | Now |
|---|---|---|
| User profile (photo, bio, faculty, **year**) | partial (no year) | ✅ year added |
| Tutor settings (rate, **availability**, descriptions) | basic slots only | ✅ full slot system (below) |
| Search & filter (skill, **faculty**, fees, **rating**) | skill/price only | ✅ faculty + rating filters added |
| Booking workflow request→accept→complete | basic | ✅ + auto-accept option, capacity, notifications |
| **Review & rating** (CRUD, timed) | missing | ✅ full CRUD + timed availability + delayed reminder |
| JWT roles + **single account = learner & tutor** | role was fixed | ✅ dual-mode account, shared wallet |
| Wallet / mock ledger (+ **10% commission**) | balance only | ✅ ledger, earned/spent, 10% commission to platform |
| **In-app messaging** | missing | ✅ chat (polling) |
| **Calendar (.ics)** | missing | ✅ .ics export + full calendar view |
| **Trending skills / taxonomy** | missing | ✅ trending (by real bookings) + categories |
| Document verification (multipart upload) | admin-approve only | ✅ tutor upload + admin review |
| **Recording vault** | unused column | ✅ tutor adds link, learner notified + watches |
| **Recommendations** | missing | ✅ same-faculty tutor recommendations |
| **Group sessions** | missing | ✅ Group slots (capacity, discounted per seat) |
| **Merit** | proposal: credits→merit | ✅ reworked to **performance-based** (see below) |

**Extra things I added that go beyond the brief:** notification centre with
filters + delayed notifications, favourite tutors, public/private invite-link
slots, slot edit/cancel with a **priority re-grab** (cancelled students get 12h
first dibs on the next slot), online/physical mode, prepay/postpay payment timing
with refunds, and mode-based colour theming.

---

## 2. The full feature set (what's in the app right now)

### Accounts & access
- One unified **student account** can both learn and tutor — switch with the
  **Learner / Tutor toggle** in the navbar. Admin is separate.
- Passwords hashed with bcrypt; every protected route behind JWT; role-based
  access (learner/tutor = user, admin separate).
- The whole UI **re-colours by mode**: 🟢 Learner = green, 🔵 Tutor = blue,
  ⬛ Admin = slate.

### Marketplace (learner)
- Search + filters: skill, category, **faculty**, **min rating**, max price.
- **Trending skills** chips, **Recommended for you** (same faculty), and a pinned
  **Favourite tutors** panel (❤️ to favourite from a card or profile).

### Availability (tutor) — each slot can set:
- **Solo or Group** (capacity), **Online or Physical** (+ meeting link / location),
- **Auto-accept** (instant) **or** "I approve each request" (booking goes Pending),
- **Public** (browsable) **or** **Private** (invite link only),
- **Prepay** (charged at booking) **or** **Postpay** (charged on completion),
- Resources/links and **learning outcomes**.
- Slots can be **edited** (capacity/details, not time) or **cancelled** (students
  notified; optional **priority** so they get 12h first dibs on the next slot).

### Booking & sessions
- Learner books a real slot; **instant** slots confirm immediately, **approval**
  slots wait Pending for the tutor. Prepay shows a **confirmation + charge**.
- **My Classes** is mode-aware: learner sees sessions booked; tutor sees sessions
  taught with Accept / Decline / Mark-completed / add-recording.
- **Reviews** become available the moment the class time ends; a **reminder
  notification** arrives ~1 day later.
- **Recording vault**: tutor pastes a session link → learner is notified and gets
  a "Watch recording" link.

### Money
- Mock wallet with a transaction ledger; **Earned / Spent** summary.
- On completion: learner pays, tutor gets **90%**, platform keeps **10%**
  (visible as commission on the admin wallet).
- Prepay holds the money at booking and **refunds** if the tutor declines/cancels.

### University Merit (reworked)
- No longer "buy merits with credits." Now **performance-based**: a tutor's
  **Merit Standing** tracks classes completed, distinct students helped, average
  rating, and review count. When all thresholds are cleared, they can **apply for
  a UTM merit transfer**; the **admin reviews the record** and approves/rejects.

### Communication
- **In-app chat** between any two users (polling).
- **Notification centre** (🔔): aggregates everything — chat, booking updates,
  cancellations, priority offers, recordings, merit outcomes — with **All /
  Messages / Bookings** filters and "mark all read." Some reminders are **delayed**
  (e.g. review reminders show ~1 day later).

### Calendar
- One **unified calendar** showing all your sessions in both roles at once,
  colour-coded: **blue = teaching, green = learning**. Plus per-booking **.ics**
  download from My Classes.

### Admin
- Verify tutors, review **document verification** uploads, and review **UTM merit
  transfer applications** (sees the performance snapshot).

---

## 3. User manual — how to navigate

**Demo logins** (password `password123` unless noted):
- Learner/tutor: `zhengyi@graduate.utm.my`, `aisyah@graduate.utm.my`, `marcus@graduate.utm.my`
- Admin: `admin@skillswap.my` / `admin123`

### The top bar (every screen)
- **Left:** *My Classes* (always first), then *Marketplace* (learner mode) or
  *Tutor Dashboard* (tutor mode).
- **Right (icons):** 📅 Calendar · 👛 Wallet · 🔔 Notifications · the
  **Learner | Tutor toggle** · your avatar (logout).
- The colour theme tells you which mode you're in.

### As a LEARNER
1. **Marketplace** → search/filter, or use Favourites / Recommended / Trending.
   Tap a tutor → **profile** (skills, rating, reviews, ❤️ favourite, 💬 message).
2. **Book**: click *Book* on a skill → pick a slot. Each slot shows Solo/Group,
   Online/Physical, seats left, price, and **Instant / Needs approval / Pay now**.
   Prepay asks you to **confirm the charge**.
3. **My Classes**: your booked sessions. After a class **ends**, a **Review & rate**
   button appears; you also get a reminder in the bell ~1 day later. Online sessions
   show a **Join meeting** link; completed ones may show **Watch recording**.
4. **Calendar / Wallet / Messages**: via the top-bar icons / bell.

### As a TUTOR (toggle to Tutor mode)
1. **Tutor Dashboard** = your setup hub:
   - **My Skills & Rates** — add skills (this is what makes you appear in the
     marketplace; availability alone doesn't).
   - **My Availability** — add slots with all the options above; **Edit** or
     **Cancel** (with the priority option) each one; **Copy invite link** for
     private slots.
   - **Verification** — upload a document to earn the Verified badge.
   - **University Merit Standing** — progress bars toward each threshold; **Apply**
     when eligible.
2. **My Classes** (tutor mode) = your taught sessions: **Accept / Decline** pending
   requests, **Mark completed**, and **add a recording link**.

### As an ADMIN (`admin@skillswap.my`)
- **Admin** panel: pending tutor verifications, **document verification** requests,
  **UTM merit transfer applications** (Approve/Reject), and the full user list.

---

## 4. Running the project (setup)

1. **Database:** create it once with `mysql -u root -p < backend/database/schema.sql`
   (it builds every table and seeds demo accounts).
2. **Backend env:** copy `backend/.env.example` → `backend/.env` and fill in your
   MySQL credentials + a `JWT_SECRET`. (`.env` is gitignored — it's not in the repo.)
3. **Install + run:**
   - Backend: `cd backend && composer install && composer start` (→ `localhost:8080`)
   - Frontend: `cd frontend && npm install && npm run dev` (→ `localhost:5173`)
4. **Android build:** `npm run build` then Capacitor wraps `frontend/` (config present).

> Note: live test data (extra demo students, etc.) lives only in the original
> author's database, not in the code — a fresh `schema.sql` install gives the clean
> demo accounts above.
