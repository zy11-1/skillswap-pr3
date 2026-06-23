# SkillSwap — PR3 Final Build (Frontend)

Vue 3 frontend, now wired to the real PHP Slim 4 backend instead of
mock JSON. Group: Threeway Chaos | SCSM2223-04

## What changed since PR2

| | PR2 | PR3 |
|---|---|---|
| Data source | `src/data/mockApi.js` + local JSON | `src/data/api.js` — real `axios` calls to the Slim 4 API |
| Auth | Fake token (base64, no real verification) | Real JWT issued and verified by the backend |
| Booking price | Calculated client-side | Calculated server-side from the tutor's stored rate |
| Wallet | Derived from "Completed" bookings in memory | Real `WalletTransaction` ledger via `/api/wallet/transactions` |
| Mobile build | Not set up | Capacitor + Android platform added (`android/` folder) |
| Persistence | Lost on page refresh (in-memory mock) | Real MySQL database |

The old mock files are kept (unused) in
`src/data/_pr2_mock_reference/` for reference — see that folder's
README if you want to know why.

## Running against the real backend

1. Get the PR3 backend running first (see the backend's own README —
   `composer install`, `mysql < database/schema.sql`, `composer start`).
   It should be live at `http://localhost:8080`.

2. In this folder:
   ```bash
   cp .env.example .env
   npm install
   npm run dev
   ```

3. Log in with the same demo accounts as PR2 (now backed by real
   database rows — see the backend README for the table).

## Building the Android app (Capacitor)

The `android/` folder in this delivery was already generated with
`npx cap add android`, so you don't need to run that step again.

```bash
# 1. Build the Vue app and copy it into the Android project
npm run cap:sync

# 2. Open it in Android Studio
npm run cap:open
```

From Android Studio, click Run to launch it on an emulator or a
connected device.

**Important — API URL on a real device/emulator:** `localhost` inside
the Android emulator refers to the emulator itself, not your
computer. Before testing on Android:

- Android Studio emulator: use `http://10.0.2.2:8080` instead of
  `http://localhost:8080` as your API URL (this is the emulator's
  special alias for your host machine).
- Physical device: use your computer's LAN IP, e.g.
  `http://192.168.1.50:8080`, and make sure your phone and computer
  are on the same Wi-Fi network.

Set this in `.env` before running `npm run cap:sync`, since Capacitor
bundles whatever was built into `dist/` — environment variables are
baked in at build time, not read at runtime.

Also note: since the backend runs on plain HTTP during development
(no SSL certificate), Android blocks cleartext traffic by default.
For local testing only, you'll need to allow it — uncomment the
`cleartext: true` line in `capacitor.config.ts`, or add a network
security config. **Don't ship a production APK with cleartext enabled**
— once deployed, the API should be on HTTPS (Railway gives you this
for free) and this restriction goes away naturally.

## Deployment (frontend)

Build the production bundle:
```bash
npm run build
```
Deploy the `dist/` folder to Vercel (per the PR1 technology stack —
drag-and-drop `dist/` onto Vercel, or connect the GitHub repo and set
the build command to `npm run build` and output directory to `dist`).
Set `VITE_API_URL` as an environment variable in Vercel's project
settings, pointing at your deployed Railway backend URL.
