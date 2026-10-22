import { Alert, Badge, Spinner } from "flowbite-react";
import type { AnalysisClass, AvailabilityStatus } from "../api/types";
import { formatPeso } from "../format";
import { CONFIDENCE_META, confidenceLevel, confidencePercent, isStatusKind, MATCH_META, STATUS_META, STOCK_META, type StatusKind } from "./logic";
import { Icon } from "./icons";
import { cx } from "./util";

export function Money({ cents, strike, className }: { cents: number | null | undefined; strike?: boolean; className?: string }) {
  return <span className={cx("tabular-nums", strike && "line-through opacity-70", className)}>{formatPeso(cents)}</span>;
}

/** 0..1 -> "High confidence · 92%". Word + number + icon, never colour alone. */
export function ConfidenceBadge({ value, compact, className }: { value: number; compact?: boolean; className?: string }) {
  const m = CONFIDENCE_META[confidenceLevel(value)];
  return (
    <Badge color={m.color} size="sm" className={cx("w-fit", className)}>
      <Icon name={m.icon} className="h-4 w-4" />
      <span>{m.label}{compact ? "" : " confidence"} · {confidencePercent(value)}%</span>
    </Badge>
  );
}

export function MatchBadge({ classification, showHint, className }: { classification: AnalysisClass; showHint?: boolean; className?: string }) {
  const m = MATCH_META[classification];
  return (
    <span className={cx("inline-flex flex-col items-start gap-1", className)}>
      <Badge color={m.color} size="sm" className="w-fit">
        <Icon name={m.icon} className="h-4 w-4" />
        <span>{m.label}</span>
      </Badge>
      {showHint && <span className="text-sm text-ink-muted">{m.hint}</span>}
    </span>
  );
}

/** AI warnings (e.g. "Pack size could not be read"). Polite live region: informative, not an interruption. */
export function WarningList({ warnings, title = "Please check", className }: { warnings: readonly string[]; title?: string; className?: string }) {
  if (warnings.length === 0) return null;
  return (
    <Alert color="warning" role="status" rounded className={className}>
      <p className="font-semibold">{title}</p>
      <ul className="mt-1 list-disc space-y-0.5 ps-5">
        {warnings.map((w, i) => <li key={`${i}-${w}`}>{w}</li>)}
      </ul>
    </Alert>
  );
}

export const DEMO_AI_TEXT = "Demo AI — deterministic mock, not live inference";
/** Pass the analysis' `is_mock` flag; renders nothing for live inference. */
export function DemoAiBadge({ isMock, className }: { isMock: boolean; className?: string }) {
  if (!isMock) return null;
  return (
    <Badge color="purple" size="sm" className={cx("w-fit whitespace-normal text-start", className)}>
      <Icon name="flask" className="h-4 w-4" />
      <span>{DEMO_AI_TEXT}</span>
    </Badge>
  );
}

/**
 * Availability bucket. `cachedAsOf` is REQUIRED on purpose: pass the age text ("3 min ago") when the number came from the local
 * cache, or null only when it was just read from the server. Cached stock is therefore never shown as if it were live.
 */
export function StockBadge({ status, quantity, cachedAsOf, className }: { status: AvailabilityStatus; quantity?: number | null; cachedAsOf: string | null; className?: string }) {
  const m = STOCK_META[status];
  return (
    <span className={cx("inline-flex flex-wrap items-center gap-x-2 gap-y-0.5", className)}>
      <Badge color={m.color} size="sm" className="w-fit">
        <Icon name={m.icon} className="h-4 w-4" />
        <span>{m.label}{quantity !== null && quantity !== undefined && status !== "out_of_stock" ? ` · ${quantity}` : ""}</span>
      </Badge>
      {cachedAsOf !== null && <span className="text-xs text-ink-muted">cached · as of {cachedAsOf}</span>}
    </span>
  );
}

/** Order, outbox and reservation states. Every state has its own colour, icon and wording. Unknown server strings render as plain text. */
export function StatusPill({ status, className }: { status: StatusKind | (string & {}); className?: string }) {
  if (!isStatusKind(status)) {
    return <Badge color="gray" size="sm" className={cx("w-fit", className)}>{status.replace(/_/g, " ")}</Badge>;
  }
  const m = STATUS_META[status];
  return (
    <Badge color={m.color} size="sm" className={cx("w-fit", m.solid, className)} data-status={status}>
      {m.icon === "spinner" ? <Spinner size="xs" aria-hidden="true" /> : <Icon name={m.icon} className="h-4 w-4" />}
      <span>{m.label}</span>
    </Badge>
  );
}
