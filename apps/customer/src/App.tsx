import { useEffect } from "react";
import { NavLink, Navigate, Route, Routes, useLocation } from "react-router-dom";
import { useActive, useAuth, useConnectivity, useOutbox, useServices, useSyncStatus } from "@hardwhere/shared";
import { ConnectionBanner } from "@hardwhere/shared/ui";
import { useCart } from "./cart";
import { cx } from "./components/ui";
import { useOnline } from "./hooks";
import { AccountScreen, LoginScreen } from "./screens/Account";
import { CartScreen } from "./screens/Cart";
import { CheckoutScreen, AttemptScreen } from "./screens/Checkout";
import { OrderDetailScreen, OrdersScreen } from "./screens/Orders";
import { PhotoSearchScreen } from "./screens/PhotoSearch";
import { ProductScreen } from "./screens/Product";
import { SearchScreen } from "./screens/Search";
import { DetailedSearchScreen } from "./screens/DetailedSearch";
import { StoreScreen, StoresScreen } from "./screens/Stores";

function Banners() {
  const conn = useConnectivity();
  const { status } = useAuth();
  const sync = useSyncStatus();
  const { refresh } = useServices();
  const { unsent } = useOutbox();
  const firstLoad = sync != null && !sync.snapshotComplete;
  return (
    <div>
      <ConnectionBanner
        deviceOnline={conn.deviceOnline}
        serverReachable={conn.serverReachable}
        stale={sync?.stale ?? false}
        lastSyncedAt={sync?.lastSyncedAt ?? null}
        syncing={sync?.phase === "syncing" && !firstLoad}
        queuedCount={unsent}
        errorMessage={sync?.phase === "error" ? sync.error?.message ?? "Sync failed." : null}
        onRetry={() => void refresh()}
      />
      {status === "expired" && (
        <div role="alert" className="flex items-center justify-between gap-2 bg-warn-soft px-3 py-2 text-sm text-warn-ink">
          <span>Your session expired. Sign in again to keep ordering. Your cart is saved.</span>
          <NavLink to="/login?next=/account" className="touch-target inline-flex items-center rounded-control bg-primary-600 px-3 font-semibold text-white">Sign in</NavLink>
        </div>
      )}
      {firstLoad && sync.phase !== "error" && (
        <div role="status" aria-live="polite" className="bg-info-soft px-3 py-2 text-sm text-info-ink">Downloading catalogue… Search works online meanwhile.</div>
      )}
    </div>
  );
}

const icons = {
  search: "M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z",
  stores: "M3 10l1.5-6h15L21 10M3 10h18M3 10a3 3 0 006 0 3 3 0 006 0 3 3 0 006 0M5 13v8h14v-8M10 21v-5h4v5",
  cart: "M3 4h2l2.4 11h10.2L20 7H6.2M9 20a1 1 0 100-2 1 1 0 000 2zm8 0a1 1 0 100-2 1 1 0 000 2z",
  orders: "M7 3h10a1 1 0 011 1v17l-3-2-3 2-3-2-3 2V4a1 1 0 011-1zm2 5h6m-6 4h6",
  account: "M12 12a4 4 0 100-8 4 4 0 000 8zm-8 9a8 8 0 0116 0",
};
function NavItem({ to, label, icon, badge }: { to: string; label: string; icon: keyof typeof icons; badge?: number }) {
  return (
    <NavLink to={to} end={to === "/"} className={({ isActive }) => cx("relative flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 text-xs font-medium", isActive ? "text-brand-ink" : "text-ink-muted")}>
      <svg aria-hidden viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={icons[icon]} /></svg>
      {label}
      {!!badge && <span aria-label={`${badge} items`} className="absolute top-1 left-1/2 ml-2 min-w-5 rounded-full bg-danger px-1 text-center text-[11px] leading-5 font-bold text-white">{badge}</span>}
    </NavLink>
  );
}

/**
 * Sends queued commands whenever the app is online and signed in, and whenever the outbox changes. This replaces `active.kickOutbox()`:
 * in runtime.ts that function can latch a resolved promise in `kicking` when it returns early (offline / not signed in / connectivity not known
 * yet) and then never processes the outbox again. The processor itself serialises passes, so running it from here is safe.
 */
function useOutboxPump() {
  const active = useActive();
  const online = useOnline();
  const { session } = useServices();
  const { status } = useAuth();
  useEffect(() => {
    if (!active || !online || status !== "signed_in") return;
    let debounce: ReturnType<typeof setTimeout> | undefined;
    let wake: ReturnType<typeof setTimeout> | undefined;
    const run = async () => {
      try {
        const r = await active.outbox.processDue();
        if (r.authRequired) await session.markExpired();
        if (r.confirmed > 0) void active.syncNow();
        if (r.nextWakeAt) { clearTimeout(wake); wake = setTimeout(() => void run(), Math.max(1000, r.nextWakeAt - Date.now())); }
      } catch { /* the next outbox change or reconnect tries again */ }
    };
    void run();
    const off = active.store.onChange((e) => { if (e.area === "outbox") { clearTimeout(debounce); debounce = setTimeout(() => void run(), 200); } });
    return () => { off(); clearTimeout(debounce); clearTimeout(wake); };
  }, [active, online, status, session]);
}

export function App() {
  const cart = useCart();
  const loc = useLocation();
  useOutboxPump();
  return (
    <div className="mx-auto flex min-h-dvh max-w-[430px] flex-col bg-canvas text-ink shadow-sm">
      <header className="sticky top-0 z-30 flex items-center gap-2 border-b border-line bg-surface px-4 py-2">
        <span aria-hidden className="grid h-8 w-8 place-items-center rounded-control bg-danger text-sm font-black text-white">H</span>
        <span className="text-base font-extrabold tracking-tight">Hardwhere</span>
        <span className="ml-auto text-xs text-ink-muted">Find hardware near you</span>
      </header>
      <Banners />
      <main key={loc.pathname.split("/")[1]} className="flex-1 pb-24">
        <Routes>
          <Route path="/" element={<SearchScreen />} />
          <Route path="/search/detailed" element={<DetailedSearchScreen />} />
          <Route path="/photo" element={<PhotoSearchScreen />} />
          <Route path="/stores" element={<StoresScreen />} />
          <Route path="/store/:id" element={<StoreScreen />} />
          <Route path="/product/:id" element={<ProductScreen />} />
          <Route path="/cart" element={<CartScreen />} />
          <Route path="/checkout/:storeId" element={<CheckoutScreen />} />
          <Route path="/attempt/:id" element={<AttemptScreen />} />
          <Route path="/orders" element={<OrdersScreen />} />
          <Route path="/orders/:id" element={<OrderDetailScreen />} />
          <Route path="/account" element={<AccountScreen />} />
          <Route path="/login" element={<LoginScreen />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
      <nav aria-label="Main" className="fixed bottom-0 left-1/2 z-30 flex w-full max-w-[430px] -translate-x-1/2 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)]">
        <NavItem to="/" label="Search" icon="search" />
        <NavItem to="/stores" label="Stores" icon="stores" />
        <NavItem to="/cart" label="Cart" icon="cart" badge={cart.count} />
        <NavItem to="/orders" label="Orders" icon="orders" />
        <NavItem to="/account" label="Account" icon="account" />
      </nav>
    </div>
  );
}
