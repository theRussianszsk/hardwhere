import { Alert, Button, Spinner } from "flowbite-react";
import { humanizeError } from "../api/errors";
import { formatAge } from "../format";
import { Icon, type IconName } from "./icons";
import { connectionBanner, type ConnectionState } from "./logic";
import { cx } from "./util";
import type { ReactNode } from "react";

export interface ConnectionBannerProps extends ConnectionState {
  onRetry?: () => void;
  /** For deterministic rendering in tests/stories. */
  now?: number;
}

/** The ONE banner that matters right now (offline > server down > sync error > syncing > unsent changes > stale). Renders nothing when all is well. */
export function ConnectionBanner({ onRetry, now, ...state }: ConnectionBannerProps) {
  const b = connectionBanner(state, now);
  if (!b) return null;
  return (
    <Alert color={b.color} rounded={false} role="status" aria-live="polite" data-banner={b.kind}>
      <div className="flex items-center gap-3">
        {b.icon === "spinner" ? <Spinner size="sm" aria-hidden="true" /> : <Icon name={b.icon} className="h-6 w-6" />}
        <div className="min-w-0 flex-1">
          <p className="font-semibold">{b.title}</p>
          {b.detail && <p className="text-sm">{b.detail}</p>}
        </div>
        {b.retry && onRetry && <Button color="light" size="lg" className="touch-target shrink-0" onClick={onRetry}>{b.retry}</Button>}
      </div>
    </Alert>
  );
}

/** Small "Updated 3 min ago" with a spinner while syncing; a button when `onClick` is given. */
export function SyncChip({ lastSyncedAt, syncing, onClick, now, className }: { lastSyncedAt: string | null; syncing: boolean; onClick?: () => void; now?: number; className?: string }) {
  const text = syncing ? "Updating…" : lastSyncedAt ? `Updated ${formatAge(lastSyncedAt, now)}` : "Not updated yet";
  const inner = (
    <>
      {syncing ? <Spinner size="xs" aria-hidden="true" /> : <Icon name="refresh" className="h-4 w-4" />}
      <span>{text}</span>
    </>
  );
  const cls = cx("inline-flex items-center gap-1.5 rounded-full bg-surface-2 px-3 py-1 text-sm text-ink-muted", className);
  return onClick
    ? <button type="button" onClick={onClick} className={cx(cls, "touch-target")} aria-live="polite">{inner}</button>
    : <span role="status" className={cls}>{inner}</span>;
}

export function EmptyState({ icon = "box", title, body, action, className }: { icon?: IconName | ReactNode; title: string; body?: string; action?: ReactNode; className?: string }) {
  return (
    <div className={cx("mx-auto flex max-w-sm flex-col items-center gap-3 px-6 py-12 text-center", className)}>
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-surface-2 text-ink-muted">{typeof icon === "string" ? <Icon name={icon as IconName} className="h-8 w-8" /> : icon}</div>
      <h2 className="text-lg font-semibold">{title}</h2>
      {body && <p className="text-ink-muted">{body}</p>}
      {action}
    </div>
  );
}

export function ErrorState({ error, title = "Something went wrong", onRetry, className }: { error: unknown; title?: string; onRetry?: () => void; className?: string }) {
  return (
    <div role="alert" className={cx("mx-auto flex max-w-sm flex-col items-center gap-3 px-6 py-12 text-center", className)}>
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-danger-soft text-danger-ink"><Icon name="alert" className="h-8 w-8" /></div>
      <h2 className="text-lg font-semibold">{title}</h2>
      <p className="text-ink-muted">{humanizeError(error)}</p>
      {onRetry && <Button size="lg" className="touch-target" onClick={onRetry}>Try again</Button>}
    </div>
  );
}

export function LoadingState({ rows = 4, label = "Loading", className }: { rows?: number; label?: string; className?: string }) {
  return (
    <div role="status" aria-busy="true" className={cx("space-y-3 p-4", className)}>
      <span className="sr-only">{label}…</span>
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} aria-hidden="true" className="flex animate-pulse items-center gap-3 rounded-card bg-surface p-3">
          <div className="h-14 w-14 shrink-0 rounded-control bg-surface-2" />
          <div className="flex-1 space-y-2">
            <div className="h-4 w-3/4 rounded bg-surface-2" />
            <div className="h-3 w-1/2 rounded bg-surface-2" />
          </div>
        </div>
      ))}
    </div>
  );
}
