import type { ReactNode } from "react";
import { Icon, renderIcon, type IconName } from "./icons";
import { cx } from "./util";

/**
 * Full-height phone column: [header][banner] / scrolling content / [bottom nav]. Safe-area aware.
 * Needs `viewport-fit=cover` in the app's index.html for env(safe-area-inset-*) to be non-zero.
 */
export function AppShell({ header, banner, nav, children, className }: { header?: ReactNode; banner?: ReactNode; nav?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <div className={cx("flex h-dvh flex-col bg-canvas text-ink", className)}>
      <a href="#hw-main" className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-[70] focus:rounded-control focus:bg-surface focus:px-4 focus:py-3 focus:text-ink focus:shadow-lg">
        Skip to content
      </a>
      <div className="shrink-0 bg-surface pl-safe pr-safe pt-safe">
        {header}
        {banner}
      </div>
      <main id="hw-main" tabIndex={-1} className="min-h-0 flex-1 overflow-y-auto overscroll-contain pl-safe pr-safe outline-none">
        {children}
      </main>
      {nav}
    </div>
  );
}

export function PageHeader({ title, subtitle, onBack, backLabel = "Back", actions }: { title: string; subtitle?: string; onBack?: () => void; backLabel?: string; actions?: ReactNode }) {
  return (
    <header className="flex min-h-14 items-center gap-1 border-b border-line bg-surface px-2">
      {onBack && (
        <button type="button" onClick={onBack} aria-label={backLabel} className="touch-target inline-flex items-center justify-center rounded-control text-ink hover:bg-surface-2">
          <Icon name="chevronLeft" className="h-6 w-6" />
        </button>
      )}
      <div className={cx("min-w-0 flex-1 py-2", !onBack && "ps-2")}>
        <h1 className="truncate text-lg font-semibold leading-tight">{title}</h1>
        {subtitle && <p className="truncate text-sm text-ink-muted">{subtitle}</p>}
      </div>
      {actions && <div className="flex shrink-0 items-center gap-1">{actions}</div>}
    </header>
  );
}

export interface NavItem {
  key: string;
  label: string;
  icon: IconName | ReactNode;
  /** Count shown in a red bubble (e.g. unsent changes, new orders). */
  badge?: number;
}

export function BottomNav({ items, activeKey, onSelect, label = "Main" }: { items: NavItem[]; activeKey: string; onSelect: (key: string) => void; label?: string }) {
  return (
    <nav aria-label={label} className="shrink-0 border-t border-line bg-surface pb-safe pl-safe pr-safe">
      <ul className="flex">
        {items.map((it) => {
          const active = it.key === activeKey;
          const badge = it.badge ?? 0;
          return (
            <li key={it.key} className="flex-1">
              <button
                type="button"
                onClick={() => onSelect(it.key)}
                aria-current={active ? "page" : undefined}
                aria-label={badge > 0 ? `${it.label}, ${badge} waiting` : undefined}
                className={cx("touch-target relative flex min-h-14 w-full flex-col items-center justify-center gap-0.5 px-1 text-xs", active ? "font-bold text-brand-ink" : "font-medium text-ink-muted hover:text-ink")}
              >
                {active && <span aria-hidden="true" className="absolute inset-x-5 top-0 h-1 rounded-b bg-primary-600 dark:bg-primary-400" />}
                <span className="relative">
                  {renderIcon(it.icon)}
                  {badge > 0 && (
                    <span aria-hidden="true" className="absolute -right-3 -top-2 min-w-5 rounded-full bg-danger px-1 text-center text-[11px] font-bold leading-5 text-white">
                      {badge > 99 ? "99+" : badge}
                    </span>
                  )}
                </span>
                <span>{it.label}</span>
              </button>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
