/**
 * TokenStore implementations. The API bearer token lives ONLY here: never in SQLite, Capacitor Preferences or localStorage.
 *
 * Native: Keychain (iOS) / Android-Keystore-backed storage via @aparajita/capacitor-secure-storage.
 *   - We never call SecureStorage.setKeyPrefix(): the prefix is a plugin-global, so changing it would make other readers lose their
 *     items. Our key is namespaced by `keyPrefix` instead.
 *   - iOS keychain items survive an app uninstall; the session manager discards a token that has no matching stored profile.
 * Web (`Capacitor.getPlatform() === "web"`, dev server only): sessionStorage, `protected: false`.
 */
import { KeychainAccess, SecureStorage } from "@aparajita/capacitor-secure-storage";
import { Capacitor } from "@capacitor/core";
import type { TokenStore } from "../contracts";

export function createSecureTokenStore({ keyPrefix }: { keyPrefix: string }): TokenStore {
  const key = `${keyPrefix}:api_token`;

  if (Capacitor.getPlatform() === "web") {
    return {
      protected: false,
      get: async () => { try { return sessionStorage.getItem(key); } catch { return null; } },
      set: async (token) => sessionStorage.setItem(key, token),
      clear: async () => sessionStorage.removeItem(key),
    };
  }

  return {
    protected: true,
    // An unreadable item (Keystore key invalidated by a lock-screen change / device restore, corrupted data) must not crash app start:
    // treat it as "no token" and let the user sign in again.
    get: async () => {
      try {
        const v = await SecureStorage.get(key, false, false);
        return typeof v === "string" && v ? v : null;
      } catch { return null; }
    },
    // sync=false: never iCloud Keychain; ThisDeviceOnly: not restored onto another device from a backup.
    set: (token) => SecureStorage.set(key, token, false, false, KeychainAccess.whenUnlockedThisDeviceOnly),
    clear: async () => { await SecureStorage.remove(key, false); },
  };
}

/** For tests and storybook-style demos. NOT protected. */
export function createMemoryTokenStore(initial: string | null = null): TokenStore {
  let token = initial;
  return { protected: false, get: async () => token, set: async (t) => { token = t; }, clear: async () => { token = null; } };
}
