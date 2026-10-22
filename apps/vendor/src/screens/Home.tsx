import { Link } from "react-router-dom";
import { COMMAND, formatManilaDateTime, formatPeso, useAuth, useCacheList, useOnlineQuery, useOutbox, useSyncStatus, type InventoryItemDto, type OrderDto, type TenantListingDto } from "@hardwhere/shared";
import { Screen } from "../shell";
import { Alert, Btn, Card, CachedTag, Icon } from "../ui";

function Stat({ label, value, sub, to }: { label: string; value: string; sub?: string; to?: string }) {
  const body = (
    <Card className="h-full">
      <p className="text-sm text-slate-600">{label}</p>
      <p className="text-2xl font-bold tabular-nums text-slate-900">{value}</p>
      {sub && <p className="text-xs text-slate-600">{sub}</p>}
    </Card>
  );
  return to ? <Link to={to} className="block">{body}</Link> : body;
}

export function Home() {
  const { profile, can } = useAuth();
  const sync = useSyncStatus();
  const dash = useOnlineQuery((api) => api.dashboard(), [], { enabled: can("inventory.view") });
  const { unsent } = useOutbox();
  const pendingPay = useOutbox({ kinds: [COMMAND.posCheckout], states: ["queued", "sending", "needs_review"] }).items;

  // Offline fallback: what we can derive from the local cache (sales today cannot be derived).
  const inv = useCacheList<InventoryItemDto>("inventory_item", { limit: 5000 });
  const listings = useCacheList<TenantListingDto>("listing", { limit: 5000 });
  const orders = useCacheList<OrderDto>("order", { limit: 5000 });
  const d = dash.data?.data;
  const live = !!d;
  const cachedLow = inv.rows.filter((i) => i.low_stock).length;
  const cachedOpen = orders.rows.filter((o) => ["pending", "reserved", "confirmed", "preparing", "ready_for_pickup"].includes(o.status)).length;
  const cachedPublished = new Set(listings.rows.filter((l) => l.publication_status === "published").map((l) => l.inventory_item_id)).size;

  return (
    <Screen title={profile?.tenant?.name ?? "Home"}>
      <p className="-mt-1 text-sm text-slate-600">{profile?.user.name} · {profile?.role?.replace(/_/g, " ")}{profile?.storeId ? " · one store" : ""}</p>

      {pendingPay.length > 0 && (
        <Alert tone="warn" live="assertive">
          A checkout is waiting to be confirmed by the server.{" "}
          <Link className="font-semibold underline" to={`/pos/pay/${pendingPay[0]!.id}`}>Check it now</Link>
        </Alert>
      )}

      {can("inventory.view") && (
        <section aria-label="Business today" className="space-y-2">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-semibold text-slate-900">Today</h2>
            <span className="text-xs text-slate-600" aria-live="polite">
              {live && dash.fetchedAt ? `as of ${formatManilaDateTime(new Date(dash.fetchedAt))}` : dash.loading ? "Loading…" : sync?.lastSyncedAt ? <CachedTag at={sync.lastSyncedAt} /> : "No data yet"}
            </span>
          </div>
          {!!dash.error && !!d && <Alert tone="warn">Couldn't refresh the numbers: {dash.message}. Showing the last values.</Alert>}
          <div className="grid grid-cols-2 gap-3">
            {live ? (
              <>
                <Stat label="Sales today" value={formatPeso(d.sales_today_cents)} sub={`${d.sales_today_count} sale${d.sales_today_count === 1 ? "" : "s"}`} />
                <Stat label="Low stock" value={String(d.low_stock_count)} sub="items to reorder" to="/stock?low=1" />
                <Stat label="Open orders" value={d.open_orders === null ? "—" : String(d.open_orders)} sub={d.open_orders === null ? "not for your role" : "marketplace"} to={can("orders.view") ? "/orders" : undefined} />
                <Stat label="Published" value={String(d.published_listings)} sub={`of ${d.inventory_items} items`} />
              </>
            ) : dash.error ? (
              <>
                <Stat label="Sales today" value="—" sub="needs a connection" />
                <Stat label="Low stock (cached)" value={String(cachedLow)} sub="from saved data" to="/stock?low=1" />
                <Stat label="Open orders (cached)" value={String(cachedOpen)} sub="from saved data" />
                <Stat label="Published (cached)" value={String(cachedPublished)} sub={`of ${inv.rows.length} items`} />
              </>
            ) : null}
          </div>
          {!!dash.error && !d && (
            <Alert tone="warn">Live numbers aren't available: {dash.message} <button type="button" className="font-semibold underline" onClick={() => void dash.refetch()}>Try again</button></Alert>
          )}
        </section>
      )}

      <section aria-label="Quick actions" className="space-y-2">
        <h2 className="text-base font-semibold text-slate-900">Quick actions</h2>
        <div className="grid grid-cols-2 gap-3">
          {(can("ai.analyze") || can("inventory.create")) && <Link to="/add" className="block"><Btn block className="h-full min-h-16 flex-col text-center"><Icon name="camera" className="h-6 w-6" />Add item from photo</Btn></Link>}
          {can("pos.sell") && <Link to="/pos" className="block"><Btn block variant="success" className="h-full min-h-16 flex-col text-center"><Icon name="cart" className="h-6 w-6" />New sale</Btn></Link>}
          {can("inventory.view") && <Link to="/stock" className="block"><Btn block variant="secondary" className="h-full min-h-16 flex-col text-center"><Icon name="box" className="h-6 w-6" />{can("inventory.adjust") ? "Check / adjust stock" : "Check stock"}</Btn></Link>}
          {can("orders.view") && <Link to="/orders" className="block"><Btn block variant="secondary" className="h-full min-h-16 flex-col text-center"><Icon name="list" className="h-6 w-6" />Marketplace orders</Btn></Link>}
        </div>
      </section>

      <Link to="/sync" className="block">
        <Card className="flex items-center justify-between">
          <div>
            <p className="font-semibold text-slate-900">{unsent > 0 ? `${unsent} unsent change${unsent === 1 ? "" : "s"}` : "Everything is sent"}</p>
            <p className="text-sm text-slate-600">{unsent > 0 ? "Waiting for the server to confirm." : "No changes waiting on this phone."}</p>
          </div>
          <Icon name="chevron" />
        </Card>
      </Link>
    </Screen>
  );
}
