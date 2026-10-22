import { useEffect, useState } from "react";
import { useAuth, useOnlineQuery, type LocationDto, type StoreDto } from "@hardwhere/shared";
import { useStores } from "./hooks";
import { PesoInput, QuantityStepper, SelectField, TextField } from "../ui";

export interface InvForm {
  sku: string;
  cost: number | null;
  price: number | null;
  qty: number;
  reorder: number;
  storeId: number | null;
  locationId: number | null;
}
export const EMPTY_INV: InvForm = { sku: "", cost: null, price: null, qty: 1, reorder: 3, storeId: null, locationId: null };

const SKU_RE = /^[A-Za-z0-9._\-/]+$/;
/** Human-readable problems with the stock form (the server re-validates everything). */
export function invProblems(f: InvForm): Partial<Record<keyof InvForm, string>> {
  const p: Partial<Record<keyof InvForm, string>> = {};
  if (!f.sku.trim()) p.sku = "Enter a SKU — your own code for this item.";
  else if (!SKU_RE.test(f.sku.trim())) p.sku = "Use letters, numbers and . _ - / only (no spaces).";
  else if (f.sku.trim().length > 64) p.sku = "Keep the SKU under 64 characters.";
  if (f.cost === null) p.cost = "Enter what you paid per unit (0 if unknown).";
  if (f.price === null || f.price < 1) p.price = "Enter the selling price in pesos.";
  if (!Number.isInteger(f.qty) || f.qty < 0) p.qty = "Quantity can't be negative.";
  if (f.storeId === null) p.storeId = "Choose a store.";
  return p;
}

export function suggestSku(brand: string | null | undefined, model: string | null | undefined, name: string | null | undefined): string {
  const clean = (s: string) => s.replace(/[^A-Za-z0-9]+/g, "").toUpperCase();
  const b = clean(brand ?? "").slice(0, 3);
  const m = clean(model ?? "") || clean(name ?? "").slice(0, 8);
  return [b, m].filter(Boolean).join("-");
}

/** Stores + locations for the signed-in user (a store-bound role only ever sees its own store). Online first, saved stores as fallback. */
export function useStoreOptions() {
  const { profile } = useAuth();
  const cachedStores = useStores().stores;
  const s = useOnlineQuery((api) => api.stores(), []);
  const l = useOnlineQuery((api) => api.locations(), []);
  let stores: StoreDto[] = s.data?.data ?? cachedStores;
  if (profile?.storeId) stores = stores.filter((x) => x.id === profile.storeId);
  const locations: LocationDto[] = l.data?.data ?? [];
  return { stores, locations, lockedStoreId: profile?.storeId ?? null };
}

export function TenantStockForm({ value, onChange, errors, serverFields }: {
  value: InvForm; onChange: (v: InvForm) => void; errors: Partial<Record<keyof InvForm, string>>; serverFields?: Partial<Record<keyof InvForm, string | null>>;
}) {
  const { stores, locations, lockedStoreId } = useStoreOptions();
  const [dirty, setDirty] = useState(false);
  const set = (p: Partial<InvForm>) => { setDirty(true); onChange({ ...value, ...p }); };
  // Default the store: a store-bound role has exactly one; so does a single-store business.
  useEffect(() => {
    if (value.storeId === null && (lockedStoreId ?? (stores.length === 1 ? stores[0]!.id : null)) !== null) onChange({ ...value, storeId: lockedStoreId ?? stores[0]!.id });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stores.length, lockedStoreId, value.storeId]);
  const shownLocs = locations.filter((x) => x.store_id === value.storeId);
  // Required-field messages appear once the person has started editing, not on a pristine form.
  const err = (k: keyof InvForm) => serverFields?.[k] ?? (dirty ? errors[k] : undefined) ?? null;

  return (
    <div className="space-y-3">
      <TextField label="Your SKU" value={value.sku} onChange={(e) => set({ sku: e.target.value })} autoCapitalize="characters" autoComplete="off" error={err("sku")} hint="Your own item code. Must be unique in your business." />
      <div className="grid grid-cols-2 gap-3">
        <PesoInput label="Cost per unit (₱)" cents={value.cost} onChange={(c) => set({ cost: c })} error={err("cost")} />
        <PesoInput label="Selling price (₱)" cents={value.price} onChange={(c) => set({ price: c })} error={err("price")} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div className="space-y-1">
          <p className="text-sm font-medium text-slate-800" id="inv-qty">Quantity received</p>
          <QuantityStepper label="Quantity received" value={value.qty} min={0} max={1_000_000} onChange={(n) => set({ qty: n })} />
          {err("qty") && <p className="text-sm text-red-700" role="alert">{err("qty")}</p>}
        </div>
        <div className="space-y-1">
          <p className="text-sm font-medium text-slate-800" id="inv-reorder">Reorder level</p>
          <QuantityStepper label="Reorder level" value={value.reorder} min={0} max={1_000_000} onChange={(n) => set({ reorder: n })} />
        </div>
      </div>
      <SelectField label="Store" value={value.storeId ?? ""} disabled={lockedStoreId !== null} onChange={(e) => set({ storeId: e.target.value ? Number(e.target.value) : null, locationId: null })} error={err("storeId")} hint={lockedStoreId !== null ? "Your account is limited to this store." : undefined}>
        {value.storeId === null && <option value="">Choose a store…</option>}
        {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
      </SelectField>
      {shownLocs.length > 0 && (
        <SelectField label="Location in the store" value={value.locationId ?? ""} onChange={(e) => set({ locationId: e.target.value ? Number(e.target.value) : null })}>
          <option value="">Default location</option>
          {shownLocs.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
        </SelectField>
      )}
    </div>
  );
}
