import { createElement as h } from "react";
import { renderToStaticMarkup as html } from "react-dom/server";
import { describe, expect, it } from "vitest";
import {
  AppShell, BottomNav, ConfidenceBadge, ConnectionBanner, DemoAiBadge, EmptyState, ErrorState, FormField, LoadingState, MatchBadge, Money, PageHeader, PesoInput, QuantityStepper,
  StatusPill, StockBadge, StoreInfoCard, SyncChip, TextField, WarningList,
} from "../src/ui";
import type { FieldA11y } from "../src/ui";
import { ApiError } from "../src/api/errors";

const NOW = Date.parse("2026-03-01T12:00:00Z");

describe("UI smoke render (server-side, catches Flowbite wiring + a11y regressions)", () => {
  it("Money / badges say it in words", () => {
    expect(html(h(Money, { cents: 449900 }))).toContain("₱4,499.00");
    expect(html(h(ConfidenceBadge, { value: 0.92 }))).toContain("High confidence · 92%");
    expect(html(h(ConfidenceBadge, { value: 0.4, compact: true }))).toContain("Low · 40%");
    expect(html(h(MatchBadge, { classification: "likely_variant", showHint: true }))).toContain("Likely variant");
    expect(html(h(DemoAiBadge, { isMock: true }))).toContain("Demo AI — deterministic mock, not live inference");
    expect(html(h(DemoAiBadge, { isMock: false }))).toBe("");
  });
  it("StockBadge never hides that a number is cached", () => {
    const cached = html(h(StockBadge, { status: "low_stock", quantity: 4, cachedAsOf: "3 min ago" }));
    expect(cached).toContain("Low stock · 4");
    expect(cached).toContain("cached · as of 3 min ago");
    expect(html(h(StockBadge, { status: "in_stock", cachedAsOf: null }))).not.toContain("cached");
    expect(html(h(StockBadge, { status: "out_of_stock", quantity: 0, cachedAsOf: null }))).toContain("Out of stock");
  });
  it("StatusPill renders each state's wording; unknown strings fall back to plain text", () => {
    expect(html(h(StatusPill, { status: "ready_for_pickup" }))).toContain("Ready for pickup");
    expect(html(h(StatusPill, { status: "needs_review" }))).toContain("Needs review");
    expect(html(h(StatusPill, { status: "something_new" }))).toContain("something new");
  });
  it("ConnectionBanner: a polite status region, silent when fine", () => {
    const base = { deviceOnline: true, serverReachable: true, stale: false, lastSyncedAt: null, syncing: false, queuedCount: 0, now: NOW };
    expect(html(h(ConnectionBanner, base))).toBe("");
    const off = html(h(ConnectionBanner, { ...base, deviceOnline: false, queuedCount: 2 }));
    expect(off).toContain('role="status"');
    expect(off).toContain("You&#x27;re offline");
    const server = html(h(ConnectionBanner, { ...base, serverReachable: false, onRetry: () => undefined }));
    expect(server).toContain("Can&#x27;t reach the server");
    expect(server).toContain("Try again");
    expect(html(h(ConnectionBanner, { ...base, serverReachable: false }))).not.toContain("<button");
  });
  it("WarningList / states", () => {
    expect(html(h(WarningList, { warnings: [] }))).toBe("");
    expect(html(h(WarningList, { warnings: ["Pack size not readable"] }))).toContain("Pack size not readable");
    expect(html(h(SyncChip, { lastSyncedAt: "2026-03-01T11:57:00Z", syncing: false, now: NOW }))).toContain("Updated 3 min ago");
    expect(html(h(SyncChip, { lastSyncedAt: null, syncing: true }))).toContain("Updating…");
    expect(html(h(EmptyState, { title: "No products yet", body: "Add your first item.", icon: "box" }))).toContain("No products yet");
    const err = html(h(ErrorState, { error: new ApiError({ kind: "network", message: "x" }), onRetry: () => undefined }));
    expect(err).toContain('role="alert"');
    expect(err).toContain("offline or the server can&#x27;t be reached");
    const loading = html(h(LoadingState, { rows: 2 }));
    expect(loading).toContain('role="status"');
    expect(loading).toContain("Loading…");
  });
  it("layout: nav marks the current page and announces badges; header back button is labelled", () => {
    const nav = html(h(BottomNav, { activeKey: "orders", onSelect: () => undefined, items: [{ key: "home", label: "Home", icon: "home" }, { key: "orders", label: "Orders", icon: "list", badge: 3 }] }));
    expect(nav).toContain('aria-current="page"');
    expect(nav).toContain('aria-label="Orders, 3 waiting"');
    expect(html(h(PageHeader, { title: "Receive stock", onBack: () => undefined }))).toContain('aria-label="Back"');
    const shell = html(h(AppShell, { header: "H", banner: "B", nav: "N", children: "content" }));
    expect(shell).toContain("pt-safe");
    expect(shell).toContain("Skip to content");
  });
  it("forms: label is tied to the control, errors are announced, required is spoken", () => {
    const f = html(h(TextField, { label: "Store name", required: true, error: "Name is required", hint: "As shown to customers", defaultValue: "" }));
    expect(f).toMatch(/<label[^>]*for="([^"]+)"/);
    expect(f).toContain('aria-invalid="true"');
    expect(f).toContain('role="alert"');
    expect(f).toContain("(required)");
    expect(f).toContain("text-base");
    expect(html(h(FormField, { label: "Notes", children: (a: FieldA11y) => h("input", a) }))).toContain("<input");
    const peso = html(h(PesoInput, { label: "Price", valueCents: 125050, onChange: () => undefined }));
    expect(peso).toContain('value="1250.50"');
    expect(peso).toContain("₱");
    const qty = html(h(QuantityStepper, { value: 2, min: 1, max: 5, label: "Quantity of Stanley hammer", onChange: () => undefined }));
    expect(qty).toContain('aria-label="Increase Quantity of Stanley hammer"');
    expect(qty).toContain('role="group"');
  });
  it("StoreInfoCard shows address, formatted hours and action buttons only when wired", () => {
    const full = html(h(StoreInfoCard, { name: "Reyes Hardware", address: "12 Rizal St, Cebu City", hours: { "mon-sat": ["08:00", "18:00"] }, phone: "+639171234567", distanceKm: 0.4, onDirections: () => undefined, onCall: () => undefined }));
    expect(full).toContain("Mon–Sat 8:00 AM – 6:00 PM");
    expect(full).toContain("400 m");
    expect(full).toContain("Directions");
    expect(full).toContain("Call");
    const bare = html(h(StoreInfoCard, { name: "Reyes Hardware", address: "12 Rizal St" }));
    expect(bare).not.toContain("<button");
  });
});
