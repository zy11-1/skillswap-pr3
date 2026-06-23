import { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'my.utm.skillswap',
  appName: 'SkillSwap',
  webDir: 'dist',
  server: {
    // During development, point the Android emulator/device at your
    // computer's LAN IP (not localhost — the emulator has its own
    // localhost that is NOT your machine). Example:
    //   url: 'http://192.168.1.50:5173',
    //   cleartext: true
    //
    // For the production build, remove `server` entirely — the app
    // will load the bundled dist/ files instead, and all API calls
    // go to whatever VITE_API_URL was baked in at build time.
  }
}

export default config
