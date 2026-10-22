/**
 * Vendor-local UI primitives. Names mirror the shared kit (packages/shared/src/ui) so a swap is a one-file import change.
 * Every control is >= 44px tall, labelled, and light-theme only.
 */
import { useEffect, useId, useState, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from "react";
import { Modal, ModalBody, ModalHeader, Spinner } from "flowbite-react";
import { formatAge, parsePesoToCents, useSyncStatus, type MatchClass, type OutboxState } from "@hardwhere/shared";
import {
  ConfidenceBadge as KitConfidence, ConfirmDialog as KitConfirm, DemoAiBadge as KitDemo, MatchBadge as KitMatch, Money as KitMoney,
  StatusPill as KitStatus, StockBadge as KitStock, WarningList as KitWarn,
} from "@hardwhere/shared/ui";

export const cx = (...a: (string | false | null | undefined)[]) => a.filter(Boolean).join(" ");

// ───────────────────────── icons ─────────────────────────
const ICONS: Record<string, string> = {
  home: "M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10",
  box: "M21 8l-9-5-9 5v8l9 5 9-5V8zM3 8l9 5 9-5M12 13v8",
  plus: "M12 5v14M5 12h14",
  cart: "M3 4h2l2.4 10.2a2 2 0 002 1.8h7.6a2 2 0 002-1.5L21 8H6M9 20.5a.5.5 0 100 .01M17 20.5a.5.5 0 100 .01",
  list: "M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01",
  sync: "M21 12a9 9 0 01-15.5 6.2M3 12A9 9 0 0118.5 5.8M18.5 2v4h-4M5.5 22v-4h4",
  back: "M15 5l-7 7 7 7",
  camera: "M4 8h3l2-3h6l2 3h3v11H4V8zM12 17a4 4 0 100-8 4 4 0 000 8z",
  image: "M4 5h16v14H4V5zM4 16l5-5 4 4 3-3 4 4M9 9.5a.5.5 0 100 .01",
  scan: "M4 8V5a1 1 0 011-1h3M16 4h3a1 1 0 011 1v3M20 16v3a1 1 0 01-1 1h-3M8 20H5a1 1 0 01-1-1v-3M7 12h10",
  search: "M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.3-4.3",
  x: "M6 6l12 12M18 6L6 18",
  check: "M5 13l4 4L19 7",
  warn: "M12 3l10 18H2L12 3zM12 10v5M12 18v.01",
  phone: "M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z",
  wifi: "M2 9a15 15 0 0120 0M5 12.5a10 10 0 0114 0M8.5 16a5 5 0 017 0M12 19.5v.01",
  print: "M7 9V3h10v6M7 17H4v-7h16v7h-3M7 14h10v7H7v-7z",
  trash: "M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3",
  chevron: "M9 6l6 6-6 6",
};
export function Icon({ name, className = "h-5 w-5" }: { name: keyof typeof ICONS | string; className?: string }) {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
      <path d={ICONS[name] ?? ""} />
    </svg>
  );
}

// ───────────────────────── buttons ─────────────────────────
type BtnVariant = "primary" | "secondary" | "danger" | "ghost" | "success";
const BTN: Record<BtnVariant, string> = {
  primary: "bg-orange-700 text-white hover:bg-orange-800 disabled:bg-orange-300",
  success: "bg-emerald-700 text-white hover:bg-emerald-800 disabled:bg-emerald-300",
  secondary: "bg-white text-slate-900 border border-slate-300 hover:bg-slate-50 disabled:text-slate-400",
  danger: "bg-red-700 text-white hover:bg-red-800 disabled:bg-red-300",
  ghost: "bg-transparent text-orange-800 hover:bg-orange-50 disabled:text-slate-400",
};
export function Btn({ variant = "primary", busy, block, className, children, disabled, ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: BtnVariant; busy?: boolean; block?: boolean }) {
  return (
    <button
      type="button"
      {...rest}
      disabled={disabled || busy}
      aria-busy={busy || undefined}
      className={cx("inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold transition-colors disabled:cursor-not-allowed", BTN[variant], block && "w-full", className)}
    >
      {busy && <Spinner size="sm" aria-label="Working" />}
      {children}
    </button>
  );
}

// ───────────────────────── form fields ─────────────────────────
const inputCls = "block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-base text-slate-900 placeholder:text-slate-400 disabled:bg-slate-100";

export function Field({ label, error, hint, children, htmlFor }: { label: string; error?: string | null; hint?: string; children: (a: { id: string; describedBy?: string; invalid: boolean }) => ReactNode; htmlFor?: string }) {
  const uid = useId();
  const id = htmlFor ?? uid;
  const msgId = `${id}-msg`;
  return (
    <div className="space-y-1">
      <label htmlFor={id} className="block text-sm font-medium text-slate-800">{label}</label>
      {children({ id, describedBy: error || hint ? msgId : undefined, invalid: !!error })}
      {(error || hint) && <p id={msgId} className={cx("text-sm", error ? "text-red-700" : "text-slate-600")} role={error ? "alert" : undefined}>{error || hint}</p>}
    </div>
  );
}

export function TextField({ label, error, hint, className, ...rest }: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string | null; hint?: string }) {
  return (
    <Field label={label} error={error} hint={hint} htmlFor={rest.id}>
      {({ id, describedBy, invalid }) => <input {...rest} id={id} aria-invalid={invalid || undefined} aria-describedby={describedBy} className={cx(inputCls, invalid && "border-red-600", className)} />}
    </Field>
  );
}
export function SelectField({ label, error, hint, children, ...rest }: SelectHTMLAttributes<HTMLSelectElement> & { label: string; error?: string | null; hint?: string }) {
  return (
    <Field label={label} error={error} hint={hint} htmlFor={rest.id}>
      {({ id, describedBy, invalid }) => <select {...rest} id={id} aria-invalid={invalid || undefined} aria-describedby={describedBy} className={cx(inputCls, invalid && "border-red-600")}>{children}</select>}
    </Field>
  );
}
export function TextAreaField({ label, error, hint, ...rest }: TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string | null; hint?: string }) {
  return (
    <Field label={label} error={error} hint={hint} htmlFor={rest.id}>
      {({ id, describedBy, invalid }) => <textarea rows={3} {...rest} id={id} aria-invalid={invalid || undefined} aria-describedby={describedBy} className={cx(inputCls, "py-2", invalid && "border-red-600")} />}
    </Field>
  );
}

/** Pesos typed by a person <-> integer centavos. Shows a human error for garbage instead of guessing. */
export function PesoInput({ label, cents, onChange, error, hint, optional, id, disabled }: { label: string; cents: number | null; onChange: (c: number | null) => void; error?: string | null; hint?: string; optional?: boolean; id?: string; disabled?: boolean }) {
  const [text, setText] = useState(cents === null ? "" : (cents / 100).toFixed(2));
  const [local, setLocal] = useState<string | null>(null);
  // Re-sync when the parent replaces the value (e.g. reload after a version conflict).
  useEffect(() => { setText(cents === null ? "" : (cents / 100).toFixed(2)); setLocal(null); }, [cents]);
  const commit = (v: string) => {
    setText(v);
    if (v.trim() === "") { setLocal(optional ? null : "Enter an amount in pesos."); onChange(null); return; }
    const c = parsePesoToCents(v);
    if (c === null) { setLocal("Use numbers only, like 349 or 349.50."); onChange(null); } else { setLocal(null); onChange(c); }
  };
  return (
    <TextField id={id} label={label} inputMode="decimal" autoComplete="off" value={text} onChange={(e) => commit(e.target.value)} error={error ?? local} hint={hint} disabled={disabled} placeholder="0.00" />
  );
}

export function QuantityStepper({ value, onChange, min = 1, max = 9999, label }: { value: number; onChange: (n: number) => void; min?: number; max?: number; label: string }) {
  return (
    <div className="inline-flex items-center rounded-lg border border-slate-300 bg-white" role="group" aria-label={label}>
      <button type="button" aria-label={`Decrease ${label}`} disabled={value <= min} onClick={() => onChange(Math.max(min, value - 1))} className="min-h-11 min-w-11 text-xl font-semibold text-slate-800 disabled:text-slate-300">−</button>
      <input
        aria-label={label} inputMode="numeric" value={value}
        onChange={(e) => { const n = Number.parseInt(e.target.value.replace(/\D/g, ""), 10); if (Number.isFinite(n)) onChange(Math.min(max, Math.max(min, n))); }}
        className="h-11 w-14 border-x border-slate-200 text-center text-base"
      />
      <button type="button" aria-label={`Increase ${label}`} disabled={value >= max} onClick={() => onChange(Math.min(max, value + 1))} className="min-h-11 min-w-11 text-xl font-semibold text-slate-800 disabled:text-slate-300">+</button>
    </div>
  );
}

// ───────────────────────── display ─────────────────────────
export function Card({ children, className, ...rest }: { children: ReactNode; className?: string } & React.HTMLAttributes<HTMLDivElement>) {
  return <div {...rest} className={cx("rounded-xl border border-slate-200 bg-white p-4 shadow-sm", className)}>{children}</div>;
}
export const Money = ({ cents, className }: { cents: number | null | undefined; className?: string }) => <KitMoney cents={cents} className={className} />;

export const StatusPill = ({ state }: { state: OutboxState }) => <KitStatus status={state} />;

export function Pill({ tone = "slate", children }: { tone?: "slate" | "green" | "amber" | "red" | "blue" | "purple"; children: ReactNode }) {
  const t = { slate: "bg-slate-100 text-slate-800", green: "bg-emerald-100 text-emerald-900", amber: "bg-amber-100 text-amber-900", red: "bg-red-100 text-red-900", blue: "bg-blue-100 text-blue-900", purple: "bg-violet-100 text-violet-900" }[tone];
  return <span className={cx("inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold", t)}>{children}</span>;
}

export function ConfidenceBadge({ value }: { value: number | null | undefined; label?: string }) {
  return value === null || value === undefined ? null : <KitConfidence value={value} compact />;
}
export const MatchBadge = ({ value }: { value: MatchClass }) => <KitMatch classification={value} />;

/** The kit badge (always says "Demo AI") plus the server's own provider_label, so what produced the result is never hidden. */
export function DemoAiBadge({ label }: { label: string }) {
  return <span className="inline-flex flex-col items-start gap-0.5"><KitDemo isMock /><span className="text-xs text-slate-600">{label}</span></span>;
}

export function WarningList({ warnings, title = "Check before you continue" }: { warnings: string[]; title?: string }) {
  return <KitWarn warnings={[...new Set(warnings)]} title={title} />;
}

export function Alert({ tone = "info", children, live = "polite", className }: { tone?: "info" | "warn" | "error" | "success"; children: ReactNode; live?: "polite" | "assertive" | "off"; className?: string }) {
  const t = { info: "border-blue-200 bg-blue-50 text-blue-950", warn: "border-amber-300 bg-amber-50 text-amber-950", error: "border-red-300 bg-red-50 text-red-950", success: "border-emerald-300 bg-emerald-50 text-emerald-950" }[tone];
  return <div role={tone === "error" ? "alert" : "status"} aria-live={live} className={cx("rounded-lg border p-3 text-sm", t, className)}>{children}</div>;
}

export function CachedTag({ at, now }: { at: string | number | null | undefined; now?: number }) {
  return <span className="inline-flex items-center rounded-md bg-slate-200 px-1.5 py-0.5 text-xs font-medium text-slate-800">cached · {formatAge(at, now)}</span>;
}

export function StockBadge({ available, low, cached }: { available: number | null | undefined; low?: boolean | null; cached?: boolean }) {
  const sync = useSyncStatus();
  if (available === null || available === undefined) return <Pill>Stock unknown</Pill>;
  const status = available <= 0 ? "out_of_stock" : low ? "low_stock" : "in_stock";
  return <KitStock status={status} quantity={available} cachedAsOf={cached ? formatAge(sync?.lastSyncedAt) : null} />;
}

export function LoadingState({ label = "Loading…" }: { label?: string }) {
  return <div role="status" aria-live="polite" className="flex items-center justify-center gap-3 py-10 text-slate-700"><Spinner aria-label={label} />{label}</div>;
}
export function EmptyState({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center">
      <p className="font-semibold text-slate-900">{title}</p>
      {children && <div className="mt-1 text-sm text-slate-600">{children}</div>}
      {action && <div className="mt-3 flex justify-center">{action}</div>}
    </div>
  );
}
export function ErrorState({ message, onRetry, title = "Couldn't load this" }: { message: string; onRetry?: () => void; title?: string }) {
  return (
    <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-5 text-center text-red-950">
      <p className="font-semibold">{title}</p>
      <p className="mt-1 text-sm">{message}</p>
      {onRetry && <div className="mt-3 flex justify-center"><Btn variant="secondary" onClick={onRetry}>Try again</Btn></div>}
    </div>
  );
}

// ───────────────────────── dialogs ─────────────────────────
export function ConfirmDialog({ open, title, children, confirmLabel = "Confirm", cancelLabel = "Cancel", danger, busy, onConfirm, onCancel }: {
  open: boolean; title: string; children?: ReactNode; confirmLabel?: string; cancelLabel?: string; danger?: boolean; busy?: boolean; onConfirm: () => void; onCancel: () => void;
}) {
  return <KitConfirm open={open} title={title} confirmLabel={confirmLabel} cancelLabel={cancelLabel} destructive={danger} busy={busy} onConfirm={onConfirm} onCancel={onCancel}>{children}</KitConfirm>;
}

export function Sheet({ open, title, onClose, children }: { open: boolean; title: string; onClose: () => void; children: ReactNode }) {
  return (
    <Modal show={open} onClose={onClose} size="md" position="bottom-center">
      <ModalHeader>{title}</ModalHeader>
      <ModalBody><div className="space-y-4">{children}</div></ModalBody>
    </Modal>
  );
}

export function Segmented<T extends string>({ value, onChange, options, label }: { value: T; onChange: (v: T) => void; options: { value: T; label: ReactNode }[]; label: string }) {
  return (
    <div role="radiogroup" aria-label={label} className="flex gap-1 rounded-lg bg-slate-200 p-1">
      {options.map((o) => (
        <button key={o.value} type="button" role="radio" aria-checked={value === o.value} onClick={() => onChange(o.value)}
          className={cx("min-h-11 flex-1 rounded-md px-2 text-sm font-semibold", value === o.value ? "bg-white text-orange-900 shadow" : "text-slate-700")}>{o.label}</button>
      ))}
    </div>
  );
}
