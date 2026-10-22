import { Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner, Toast } from "flowbite-react";
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { Icon } from "./icons";
import { cx } from "./util";

/**
 * Confirmation before anything that changes stock or money. Focus is trapped (Flowbite Modal / floating-ui) and starts on the
 * SAFE button: Cancel for destructive actions, so a stray Enter cannot confirm them. Esc / backdrop cancel unless `busy`.
 */
export function ConfirmDialog({ open, title, children, confirmLabel, cancelLabel = "Cancel", destructive, busy, onConfirm, onCancel }: {
  open: boolean; title: string; children?: ReactNode; confirmLabel: string; cancelLabel?: string; destructive?: boolean; busy?: boolean; onConfirm: () => void; onCancel: () => void;
}) {
  const cancelRef = useRef<HTMLButtonElement | null>(null);
  const confirmRef = useRef<HTMLButtonElement | null>(null);
  return (
    <Modal show={open} size="md" position="center" dismissible={!busy} onClose={busy ? undefined : onCancel} initialFocus={destructive ? cancelRef : confirmRef}>
      <ModalHeader>{title}</ModalHeader>
      <ModalBody>{children && <div className="space-y-3 text-base text-gray-700 dark:text-gray-300">{children}</div>}</ModalBody>
      <ModalFooter className="flex gap-3">
        <Button ref={cancelRef} color="light" size="lg" className="touch-target flex-1" disabled={busy} onClick={onCancel}>{cancelLabel}</Button>
        <Button ref={confirmRef} color={destructive ? "red" : undefined} size="lg" className="touch-target flex-1" disabled={busy} aria-busy={busy || undefined} onClick={onConfirm}>
          {busy && <Spinner size="sm" className="me-2" aria-hidden="true" />}
          {confirmLabel}
        </Button>
      </ModalFooter>
    </Modal>
  );
}

// ───────── toasts ─────────
export interface ToastApi {
  success(message: string): number;
  error(message: string): number;
  dismiss(id: number): void;
}
interface ToastItem { id: number; kind: "success" | "error"; message: string }
const ToastContext = createContext<ToastApi | null>(null);
const LIFETIME_MS = { success: 3500, error: 7000 } as const;
const MAX_VISIBLE = 3;

export function ToastHost({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>());
  const seq = useRef(0);

  const dismiss = useCallback((id: number) => {
    clearTimeout(timers.current.get(id));
    timers.current.delete(id);
    setItems((l) => l.filter((t) => t.id !== id));
  }, []);
  const push = useCallback((kind: ToastItem["kind"], message: string) => {
    const id = ++seq.current;
    setItems((l) => [...l.slice(-(MAX_VISIBLE - 1)), { id, kind, message }]);
    timers.current.set(id, setTimeout(() => dismiss(id), LIFETIME_MS[kind]));
    return id;
  }, [dismiss]);
  useEffect(() => { const t = timers.current; return () => { t.forEach(clearTimeout); t.clear(); }; }, []);

  const api = useMemo<ToastApi>(() => ({ success: (m) => push("success", m), error: (m) => push("error", m), dismiss }), [push, dismiss]);
  return (
    <ToastContext.Provider value={api}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed inset-x-0 top-0 z-[60] flex flex-col items-center gap-2 px-4 pb-2 pt-[calc(env(safe-area-inset-top)+0.5rem)]">
        {items.map((t) => (
          <Toast key={t.id} role={t.kind === "error" ? "alert" : "status"} className="pointer-events-auto max-w-sm gap-3 !text-ink">
            <span className={cx("inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg", t.kind === "success" ? "bg-success-soft text-success-ink" : "bg-danger-soft text-danger-ink")}>
              <Icon name={t.kind === "success" ? "check" : "alert"} />
            </span>
            <span className="flex-1 text-base font-medium">{t.message}</span>
            <button type="button" aria-label="Dismiss message" onClick={() => dismiss(t.id)} className="touch-target -my-2 -me-2 inline-flex items-center justify-center rounded-control text-ink-muted hover:bg-surface-2">
              <Icon name="x" />
            </button>
          </Toast>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastApi {
  const api = useContext(ToastContext);
  if (!api) throw new Error("useToast() must be used inside <ToastHost>.");
  return api;
}
