/** @hardwhere/shared/ui — Flowbite React + Tailwind 4 components for both apps. Styles: `@import "@hardwhere/shared/styles.css"` (see ui/styles.css). */
export { Icon, type IconName } from "./icons";
export { AppShell, BottomNav, PageHeader, type NavItem } from "./layout";
export { ConfidenceBadge, DEMO_AI_TEXT, DemoAiBadge, MatchBadge, Money, StatusPill, StockBadge, WarningList } from "./badges";
export { PesoInput, QuantityStepper } from "./money";
export { ConnectionBanner, EmptyState, ErrorState, LoadingState, SyncChip, type ConnectionBannerProps } from "./status";
export { ConfirmDialog, ToastHost, useToast, type ToastApi } from "./feedback";
export { FormField, INPUT_LG_THEME, SelectField, TextAreaField, TextField, type FieldA11y } from "./forms";
export { PhotoThumb, StoreInfoCard, type StoreInfoCardProps } from "./media";
export {
  clampQty, CONFIDENCE_META, confidenceLevel, confidencePercent, connectionBanner, formatStoreHours, isStatusKind, MATCH_META, STATUS_META, STOCK_META, validatePeso,
  type BannerKind, type BannerSpec, type ConfidenceLevel, type ConnectionState, type StatusKind,
} from "./logic";

/**
 * Flowbite components these files use. Put them in each app's `.flowbite-react/config.json` -> "components" (plus whatever the app
 * itself imports): flowbite-react's class generator only scans the app folder, so classes of components used solely in this package
 * would otherwise be missing from the CSS.
 */
export const UI_FLOWBITE_COMPONENTS = ["Alert", "Badge", "Button", "HelperText", "Label", "Modal", "Select", "Spinner", "TextInput", "Textarea", "Toast"] as const;
