import { useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { formatAge, useActive, useAuth, useCacheList, useNow, useSyncStatus, type InventoryItemDto } from "@hardwhere/shared";
import { useOnline } from "../lib/hooks";
import { Screen } from "../shell";
import { Alert, Btn, Card, CachedTag, EmptyState, Icon, LoadingState, Segmented, StockBadge, TextField } from "../ui";

export function StockList() {
  const [params, setParams] = useSearchParams();
  const low = params.get("low") === "1";
  const [search, setSearch] = useState("");
  const { rows, loading } = useCacheList<InventoryItemDto>("inventory_item", { search, orderBy: "search", limit: 300 });
  const sync = useSyncStatus();
  const active = useActive();
  const { can } = useAuth();
  const { online } = useOnline();
  const now = useNow(30_000);
  const [syncing, setSyncing] = useState(false);

  const shown = low ? rows.filter((r) => r.low_stock) : rows;
  const syncNow = async () => { setSyncing(true); try { await active?.syncNow(); } finally { setSyncing(false); } };
  const firstLaunch = !!sync && !sync.snapshotComplete && rows.length === 0;

  return (
    <Screen title="Stock" right={can("ai.analyze") ? <Link to="/add" aria-label="Add item" className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-orange-800"><Icon name="plus" /></Link> : undefined}>
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm text-slate-700" aria-live="polite">Saved on this phone · synced {formatAge(sync?.lastSyncedAt, now)}</p>
        <Btn variant="secondary" className="min-h-11 shrink-0 whitespace-nowrap" busy={syncing || sync?.phase === "syncing"} disabled={!online} onClick={() => void syncNow()}>Sync now</Btn>
      </div>

      <TextField label="Search stock" type="search" placeholder="Name, brand, SKU or barcode" value={search} onChange={(e) => setSearch(e.target.value)} />
      <Segmented label="Filter" value={low ? "low" : "all"} onChange={(v) => setParams(v === "low" ? { low: "1" } : {}, { replace: true })} options={[{ value: "all", label: `All${low ? "" : ` (${rows.length})`}` }, { value: "low", label: `Low stock (${rows.filter((r) => r.low_stock).length})` }]} />

      {loading ? <LoadingState label="Reading saved stock…" />
        : firstLaunch ? (online ? <LoadingState label="Downloading your catalogue…" /> : <EmptyState title="No saved stock yet">You're offline and nothing has been downloaded on this phone. Connect to the internet once to download your catalogue.</EmptyState>)
        : shown.length === 0 ? <EmptyState title={search || low ? "Nothing matches" : "No stock items yet"} action={!search && !low && can("ai.analyze") ? <Link to="/add"><Btn>Add your first item</Btn></Link> : undefined}>{search ? "Try a different word, SKU or barcode." : low ? "Nothing is below its reorder level." : "Add items by photo to start tracking stock."}</EmptyState>
        : (
          <ul className="space-y-2" aria-label="Stock items">
            {shown.map((i) => (
              <li key={i.id}>
                <Link to={`/stock/${i.id}`} className="block">
                  <Card className="min-h-14 space-y-1">
                    <p className="font-semibold text-slate-900">{i.name ?? i.product_name ?? i.sku}</p>
                    <p className="text-sm text-slate-600">{[i.brand, i.sku].filter(Boolean).join(" · ")}</p>
                    <StockBadge available={i.available ?? i.on_hand} low={i.low_stock} cached />
                  </Card>
                </Link>
              </li>
            ))}
          </ul>
        )}
      {sync?.lastSyncedAt && <p className="text-center text-xs text-slate-600"><CachedTag at={sync.lastSyncedAt} now={now} /> Quantities are as of the last sync; open an item for live numbers.</p>}
      {sync?.error && <Alert tone="warn">Last sync problem: {sync.error.message}</Alert>}
    </Screen>
  );
}
