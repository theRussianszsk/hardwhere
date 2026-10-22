/**
 * UI glue for the customer app. Shared components come from @hardwhere/shared/ui (Flowbite + design tokens); this file keeps the call sites
 * short with thin adapters (e.g. `cls`/`children` props) plus the few app-specific bits the kit does not have (Btn, Card, Notice, PhotoThumb).
 */
import { useId, useState, type ButtonHTMLAttributes, type ReactNode } from "react";
import {
  ConfidenceBadge, ConfirmDialog as KitConfirm, DemoAiBadge as KitDemo, EmptyState as KitEmpty, ErrorState as KitError, FormField, LoadingState as KitLoading, MatchBadge as KitMatch,
  Money, QuantityStepper as KitQty, StatusPill as KitStatus, StockBadge as KitStock, WarningList as KitWarn,
} from "@hardwhere/shared/ui";
import type { AvailabilityStatus, MatchClass } from "@hardwhere/shared";
import { imgUrl } from "../config";

export { ConfidenceBadge, FormField, Money };
export const cx = (...a: (string | false | null | undefined)[]) => a.filter(Boolean).join(" ");

// ───────── buttons / containers ─────────
type Variant = "primary" | "secondary" | "danger" | "ghost";
const VARIANTS: Record<Variant, string> = {
  primary: "bg-primary-600 text-white hover:bg-primary-700 disabled:bg-primary-600/40",
  secondary: "bg-surface text-ink border border-line hover:bg-surface-2 disabled:text-ink-muted",
  danger: "bg-danger text-white hover:opacity-90 disabled:opacity-40",
  ghost: "bg-transparent text-brand-ink hover:bg-surface-2 disabled:text-ink-muted",
};
export function Btn({ variant = "primary", block, className, busy, children, ...p }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; block?: boolean; busy?: boolean }) {
  return (
    <button
      type="button"
      {...p}
      disabled={p.disabled || busy}
      aria-busy={busy || undefined}
      className={cx("touch-target inline-flex items-center justify-center gap-2 rounded-control px-4 text-base font-semibold transition-colors disabled:cursor-not-allowed", VARIANTS[variant], block && "w-full", className)}
    >
      {busy && <Spinner />}
      {children}
    </button>
  );
}
export function Spinner() {
  return <span aria-hidden className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />;
}
export const Card = ({ children, className }: { children: ReactNode; className?: string }) => (
  <div className={cx("rounded-card border border-line bg-surface p-3 shadow-sm", className)}>{children}</div>
);
export function Notice({ tone = "info", children, role }: { tone?: "info" | "warn" | "error" | "ok"; children: ReactNode; role?: "alert" | "status" }) {
  const t = { info: "bg-info-soft text-info-ink", warn: "bg-warn-soft text-warn-ink", error: "bg-danger-soft text-danger-ink", ok: "bg-success-soft text-success-ink" }[tone];
  return <div role={role ?? (tone === "error" ? "alert" : "status")} className={cx("rounded-control px-3 py-2 text-sm", t)}>{children}</div>;
}
export const inputCls = "block touch-target w-full rounded-control border border-line bg-surface px-3 text-base text-ink placeholder:text-ink-muted aria-[invalid=true]:border-danger";

// ───────── kit adapters ─────────
export const LoadingState = ({ label = "Loading" }: { label?: string }) => <KitLoading label={label.replace(/…$/, "")} rows={3} />;
export const EmptyState = ({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) => <KitEmpty title={title} body={children as string | undefined} action={action} />;
export const ErrorState = ({ title, message, onRetry }: { title?: string; message?: string | null; onRetry?: () => void }) => <KitError title={title} error={new Error(message ?? "Something went wrong.")} onRetry={onRetry} />;

/** Outbox/order states. "Sending"/"queued" from the outbox are shown to shoppers as "Submitted" (sent, but NOT reserved yet). */
export function StatusPill({ status, label }: { status: string; label?: string }) {
  return <KitStatus status={label === "Submitted" && ["sending", "queued", "draft"].includes(status) ? "submitted" : status} />;
}
export const StockBadge = ({ status, quantity, cachedAsOf = null }: { status: AvailabilityStatus; quantity?: number | null; cachedAsOf?: string | null }) => <KitStock status={status} quantity={quantity} cachedAsOf={cachedAsOf} />;
export const MatchBadge = ({ cls }: { cls: MatchClass }) => <KitMatch classification={cls} />;
export const DemoAiBadge = ({ label }: { label?: string }) => (label ? <span title={label}><KitDemo isMock /></span> : <KitDemo isMock />);
export const WarningList = ({ warnings }: { warnings: string[] }) => <KitWarn warnings={warnings} />;

export const QuantityStepper = ({ value, min = 1, max, onChange, label = "Quantity" }: { value: number; min?: number; max?: number | null; onChange: (n: number) => void; label?: string }) => (
  <KitQty value={value} min={min} max={max ?? undefined} onChange={onChange} label={label} />
);

export function ConfirmDialog({ open, title, children, confirmLabel = "Confirm", cancelLabel = "Cancel", danger, busy, onConfirm, onCancel, extra }: {
  open: boolean; title: string; children?: ReactNode; confirmLabel?: string; cancelLabel?: string; danger?: boolean; busy?: boolean; onConfirm: () => void; onCancel: () => void; extra?: ReactNode;
}) {
  return (
    <KitConfirm open={open} title={title} confirmLabel={confirmLabel} cancelLabel={cancelLabel} destructive={danger} busy={busy} onConfirm={onConfirm} onCancel={onCancel}>
      {children}{extra && <div className="flex flex-col">{extra}</div>}
    </KitConfirm>
  );
}

/** Product image from an API-relative URL, with a neutral fallback. */
export function PhotoThumb({ src, alt, size = 64, className }: { src: string | null | undefined; alt: string; size?: number; className?: string }) {
  const url = imgUrl(src);
  const [bad, setBad] = useState(false);
  return (
    <div className={cx("flex shrink-0 items-center justify-center overflow-hidden rounded-control bg-surface-2", className)} style={{ width: size, height: size }}>
      {url && !bad ? <img src={url} alt={alt} loading="lazy" onError={() => setBad(true)} className="h-full w-full object-cover" /> : <span aria-hidden className="text-xl">🔧</span>}
    </div>
  );
}

export function PageTitle({ title, back, right }: { title: string; back?: () => void; right?: ReactNode }) {
  const id = useId();
  return (
    <div className="flex min-h-12 items-center gap-1 px-2">
      {back && <button type="button" aria-label="Back" onClick={back} className="touch-target rounded-control text-xl hover:bg-surface-2">‹</button>}
      <h1 id={id} className="flex-1 truncate px-2 text-lg font-bold text-ink">{title}</h1>
      {right}
    </div>
  );
}
