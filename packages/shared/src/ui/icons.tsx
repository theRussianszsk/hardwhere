import type { ReactNode, SVGProps } from "react";
import { cx } from "./util";

/** Inline 24x24 stroke icons (no icon font / network fetch: the apps must work offline). Decorative by default: pair with visible text. */
const PATHS = {
  home: "M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z",
  box: "M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8",
  cart: "M3 4h2l2.4 11h10.2l2-8H6.2M9 20h.01M17 20h.01",
  receipt: "M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6",
  search: "M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4",
  store: "M4 9l1.5-5h13L20 9M4 9v11h16V9M4 9a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 6 0 2.5 2.5 0 0 0 5 0M10 20v-6h4v6",
  pin: "M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z",
  phone: "M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z",
  clock: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2",
  alert: "M12 3l10 18H2zM12 10v5M12 18h.01",
  info: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 11v5M12 8h.01",
  check: "M5 12l5 5L20 7",
  checks: "M2 13l4 4L14 8M10 16l1 1L21 8",
  x: "M6 6l12 12M18 6L6 18",
  xCircle: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9 9l6 6M15 9l-6 6",
  ban: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM5.6 5.6l12.8 12.8",
  wifiOff: "M2 8.8a15 15 0 0 1 4-2.4M22 8.8a15 15 0 0 0-8.5-3.6M5 12.5a10 10 0 0 1 3-2M19 12.5a10 10 0 0 0-3.5-2.4M8.5 16a5 5 0 0 1 7 0M12 20h.01M3 3l18 18",
  serverOff: "M4 6h16v5H4zM4 14h16v5H4zM7 8.5h.01M7 16.5h.01M3 3l18 18",
  refresh: "M20 11a8 8 0 0 0-14.5-4M4 4v4h4M4 13a8 8 0 0 0 14.5 4M20 20v-4h-4",
  chevronLeft: "M15 5l-7 7 7 7",
  plus: "M12 5v14M5 12h14",
  minus: "M5 12h14",
  camera: "M4 8h3l2-3h6l2 3h3v12H4zM12 17.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z",
  trash: "M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3",
  bag: "M5 8h14l-1 13H6zM9 8V6a3 3 0 0 1 6 0v2",
  user: "M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0",
  list: "M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01",
  flask: "M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3M8 15h8",
  pencil: "M4 20l1-4L16 5l3 3L8 19zM14 7l3 3",
  send: "M21 3L3 10l7 3 3 7zM10 13l11-10",
  lock: "M7 11h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2zM8 11V8a4 4 0 0 1 8 0v3",
  hourglass: "M7 3h10M7 21h10M7 3c0 5 5 6 5 9s-5 4-5 9M17 3c0 5-5 6-5 9s5 4 5 9",
  flag: "M5 21V4M5 4h12l-2 4 2 4H5",
  wrench: "M14.7 6.3a4 4 0 0 0-5 5L3 18l3 3 6.7-6.7a4 4 0 0 0 5-5l-2.5 2.5-2.5-.5-.5-2.5z",
} as const;

export type IconName = keyof typeof PATHS;

export function Icon({ name, className, ...rest }: { name: IconName } & Omit<SVGProps<SVGSVGElement>, "name">) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false" className={cx("h-5 w-5 shrink-0", className)} {...rest}>
      <path d={PATHS[name]} />
    </svg>
  );
}

/** Lets props accept either an icon name or a ready-made node. */
export const renderIcon = (icon: IconName | ReactNode): ReactNode => (typeof icon === "string" ? <Icon name={icon as IconName} /> : icon);
