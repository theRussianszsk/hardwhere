/**
 * Outbox command handlers shared by the apps. Each sends the command id as Idempotency-Key (the processor passes it in),
 * so a retry after a lost response has exactly one business effect on the server.
 *
 * Only NON-FINAL work is queued automatically (photo uploads, stock-adjustment requests, cart sync). Stock-changing checkouts
 * are online-only: they are still written to the outbox first (so the key survives an app kill) but with autoRetry:false,
 * which turns an interrupted attempt into "needs review" instead of silently re-sending a sale.
 */
import type { CommandHandler } from "../contracts";
import type { CheckoutInput, PlaceOrderInput } from "../api/types";
import type { HandlerDeps } from "./runtime";

export const COMMAND = {
  aiAnalyze: "ai.analyze",
  stockAdjust: "stock.adjust",
  posCheckout: "pos.checkout",
  photoSearch: "search.photo",
  orderPlace: "order.place",
  cartReplace: "cart.replace",
} as const;

export interface AiAnalyzePayload { photoRef: string; hint?: string }
export interface StockAdjustPayload { itemId: number; locationId: number; movement_type: string; quantity_delta: number; note?: string }
export interface PosCheckoutPayload { input: CheckoutInput }
export interface PhotoSearchPayload { photoRef: string; hint?: string }
export interface OrderPlacePayload { input: PlaceOrderInput }
export interface CartReplacePayload { items: { listing_id: number; quantity: number }[] }

export function vendorHandlers({ api, photos }: HandlerDeps): Record<string, CommandHandler> {
  return {
    [COMMAND.aiAnalyze]: { autoRetry: true, run: async (p: AiAnalyzePayload, { idempotencyKey }) => (await api.aiAnalyze(await photos.load(p.photoRef), idempotencyKey, p.hint)).data },
    [COMMAND.stockAdjust]: { autoRetry: true, run: async (p: StockAdjustPayload, { idempotencyKey }) => (await api.stockAdjust(p.itemId, { location_id: p.locationId, movement_type: p.movement_type, quantity_delta: p.quantity_delta, note: p.note }, idempotencyKey)).data },
    [COMMAND.posCheckout]: { autoRetry: false, run: async (p: PosCheckoutPayload, { idempotencyKey }) => (await api.posCheckout(p.input, idempotencyKey)).data },
  };
}

export function customerHandlers({ api, photos }: HandlerDeps): Record<string, CommandHandler> {
  return {
    [COMMAND.photoSearch]: { autoRetry: true, run: async (p: PhotoSearchPayload, { idempotencyKey }) => (await api.customerPhotoSearch(await photos.load(p.photoRef), idempotencyKey, p.hint)).data },
    [COMMAND.orderPlace]: { autoRetry: false, run: async (p: OrderPlacePayload, { idempotencyKey }) => (await api.ordersPlace(p.input, idempotencyKey)).data },
    [COMMAND.cartReplace]: { autoRetry: true, run: async (p: CartReplacePayload) => (await api.cartReplace(p.items)).data },
  };
}
