import { useCallback, useEffect, useMemo } from "react";
import { useAuth, type TenantListingDto } from "@hardwhere/shared";
import { useDraft, useOutboxItem } from "./hooks";

export interface CartLine {
  listingId: number;
  inventoryItemId: number;
  sku: string;
  name: string;
  /** Price the cashier saw when adding (sale price if any). Sent as expected_unit_price_cents so a changed price is caught by the server. */
  unitPriceCents: number;
  qty: number;
  discountCents: number;
}
export interface Cart {
  storeId: number | null;
  lines: CartLine[];
  /** Idempotency key of the checkout attempt in progress. Generated once, reused for "check again", dropped when the cart changes after a refusal. */
  checkoutKey: string | null;
}
export const EMPTY_CART: Cart = { storeId: null, lines: [], checkoutKey: null };

export const listingName = (l: TenantListingDto) => l.title_override ?? l.title ?? l.product?.name ?? l.sku;
export const listingPrice = (l: TenantListingDto) => l.sale_price_cents ?? l.price_cents;

export function cartTotals(lines: CartLine[], vatRateBps: number) {
  const gross = lines.reduce((s, l) => s + l.unitPriceCents * l.qty, 0);
  const discount = lines.reduce((s, l) => s + l.discountCents, 0);
  const total = gross - discount;
  // Prices are VAT-inclusive: the VAT inside the total = total * rate / (1 + rate). The server's figure on the receipt is authoritative.
  const vat = Math.round((total * vatRateBps) / (10_000 + vatRateBps));
  return { gross, discount, total, vat, count: lines.reduce((s, l) => s + l.qty, 0) };
}

/** The persisted POS cart (SQLite draft kind "pos-cart"): survives restarts, never touches stock. */
export function useCart() {
  const { value: cart, set, ready, clear } = useDraft<Cart>("pos-cart", "current", EMPTY_CART, 60);
  const { profile } = useAuth();
  const vatBps = profile?.tenant?.vat_rate_bps ?? 1200;
  // Any edit invalidates a previous checkout key: a different cart must never reuse an old idempotency key.
  const edit = useCallback((fn: (c: Cart) => Cart) => set((c) => ({ ...fn(c), checkoutKey: null })), [set]);

  const add = useCallback((l: TenantListingDto) => edit((c) => {
    const i = c.lines.findIndex((x) => x.listingId === l.id);
    if (i >= 0) return { ...c, lines: c.lines.map((x, j) => (j === i ? { ...x, qty: Math.min(9999, x.qty + 1) } : x)) };
    return { ...c, storeId: c.storeId ?? l.store_id, lines: [...c.lines, { listingId: l.id, inventoryItemId: l.inventory_item_id, sku: l.sku, name: listingName(l), unitPriceCents: listingPrice(l), qty: 1, discountCents: 0 }] };
  }), [edit]);
  const patch = useCallback((listingId: number, p: Partial<CartLine>) => edit((c) => ({ ...c, lines: c.lines.map((x) => (x.listingId === listingId ? { ...x, ...p } : x)) })), [edit]);
  const remove = useCallback((listingId: number) => edit((c) => ({ ...c, lines: c.lines.filter((x) => x.listingId !== listingId) })), [edit]);
  const setStore = useCallback((storeId: number) => edit((c) => ({ ...c, storeId })), [edit]);
  const setKey = useCallback((checkoutKey: string | null) => set((c) => ({ ...c, checkoutKey })), [set]);
  const totals = useMemo(() => cartTotals(cart.lines, vatBps), [cart.lines, vatBps]);

  // If the sale for this cart was confirmed (even while the user was on another screen), the cart is spent: clear it so it can't be rung up twice.
  const { item: attempt } = useOutboxItem(cart.checkoutKey);
  useEffect(() => { if (attempt?.state === "confirmed" && cart.checkoutKey === attempt.id) void clear(); }, [attempt?.state, attempt?.id, cart.checkoutKey, clear]);

  return { cart, ready, add, patch, remove, setStore, setKey, clear, totals, vatBps };
}
