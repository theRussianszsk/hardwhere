import { Button, TextInput } from "flowbite-react";
import { useEffect, useRef, useState } from "react";
import { FormField, INPUT_LG_THEME } from "./forms";
import { Icon } from "./icons";
import { clampQty, validatePeso } from "./logic";
import { cx } from "./util";

const toText = (cents: number | null) => (cents === null ? "" : (cents / 100).toFixed(2));

/**
 * Peso amount entry. Controlled in centavos: `valueCents` is null while empty or invalid, so the parent can never save a bad amount.
 * Typing is kept as text ("1,2" mid-edit is fine); a different `valueCents` set by the parent (e.g. a form reset) replaces the text.
 */
export function PesoInput({ label, valueCents, onChange, min, max, required, disabled, hint, name, id }: {
  label: string; valueCents: number | null; onChange: (cents: number | null) => void; min?: number; max?: number; required?: boolean; disabled?: boolean; hint?: string; name?: string; id?: string;
}) {
  const [text, setText] = useState(toText(valueCents));
  const [touched, setTouched] = useState(false);
  const lastEmitted = useRef<number | null>(valueCents);
  useEffect(() => {
    if (valueCents !== lastEmitted.current) { lastEmitted.current = valueCents; setText(toText(valueCents)); }
  }, [valueCents]);

  const { cents, error } = validatePeso(text, { min, max, required });
  const shown = error && (touched || text.trim() !== "") ? error : null;
  return (
    <FormField label={label} hint={hint} error={shown} required={required} id={id}>
      {(a11y) => (
        <TextInput
          {...a11y} name={name} type="text" inputMode="decimal" autoComplete="off" disabled={disabled} sizing="lg" theme={INPUT_LG_THEME}
          color={shown ? "failure" : "gray"} addon="₱" placeholder="0.00" value={text}
          onChange={(e) => {
            const next = e.target.value;
            setText(next);
            const r = validatePeso(next, { min, max, required });
            lastEmitted.current = r.cents;
            onChange(r.cents);
          }}
          onBlur={() => { setTouched(true); if (cents !== null) setText(toText(cents)); }}
        />
      )}
    </FormField>
  );
}

/** − / + with 48 px targets and a typeable count. `label` names the thing being counted ("Quantity of Stanley hammer"). */
export function QuantityStepper({ value, onChange, min = 0, max, step = 1, label, disabled, className }: {
  value: number; onChange: (n: number) => void; min?: number; max?: number; step?: number; label: string; disabled?: boolean; className?: string;
}) {
  const [text, setText] = useState(String(value));
  useEffect(() => setText(String(value)), [value]);
  const set = (n: number) => onChange(clampQty(n, min, max));
  return (
    <div role="group" aria-label={label} className={cx("inline-flex items-center gap-1", className)}>
      <Button color="light" size="lg" className="touch-target w-12 px-0" aria-label={`Decrease ${label}`} disabled={disabled || value <= min} onClick={() => set(value - step)}>
        <Icon name="minus" className="h-6 w-6" />
      </Button>
      <input
        type="text" inputMode="numeric" pattern="[0-9]*" aria-label={label} disabled={disabled} value={text}
        onChange={(e) => { const digits = e.target.value.replace(/\D/g, ""); setText(digits); if (digits !== "") set(Number(digits)); }}
        onBlur={() => setText(String(value))}
        className="h-12 w-16 rounded-control border border-line bg-surface text-center text-lg font-semibold tabular-nums text-ink disabled:opacity-50"
      />
      <Button color="light" size="lg" className="touch-target w-12 px-0" aria-label={`Increase ${label}`} disabled={disabled || (max !== undefined && value >= max)} onClick={() => set(value + step)}>
        <Icon name="plus" className="h-6 w-6" />
      </Button>
    </div>
  );
}
