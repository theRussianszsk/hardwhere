import type { CapacitorConfig } from "@capacitor/cli";

/**
 * Hardwhere Vendor — distinct Capacitor application id so the vendor and customer apps install side by side.
 * webDir is the static Vite output (contains index.html). The API origin is NOT configured here: it is baked in at build time
 * from VITE_API_BASE_URL (public HTTPS origin of the Laravel API). No database credentials or AI keys exist in this bundle.
 */
const config: CapacitorConfig = {
  appId: "ph.hardwhere.vendor",
  appName: "Hardwhere Vendor",
  webDir: "dist",
  server: {
    // Android WebView origin becomes https://localhost (matches the API's CORS allow-list). iOS uses capacitor://localhost.
    androidScheme: "https",
    // Dev only: lets a debug build call a plain-http LAN API (e.g. http://10.0.2.2:8787). Production builds must use HTTPS.
    cleartext: process.env.CAP_ALLOW_CLEARTEXT === "1",
  },
  plugins: {
    CapacitorSQLite: {
      // SQLCipher encryption; the passphrase lives in the platform keystore (Keychain / Android Keystore), not in app code.
      iosIsEncryption: true,
      iosKeychainPrefix: "hardwhere-vendor",
      iosBiometric: { biometricAuth: false },
      androidIsEncryption: true,
      androidBiometric: { biometricAuth: false },
    },
  },
};

export default config;
