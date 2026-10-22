import { HelperText, Label, Select, TextInput, Textarea, type SelectProps, type TextareaProps, type TextInputProps } from "flowbite-react";
import { useId, type ReactNode } from "react";

/** Props to spread on the control so the label, hint and error are announced with it. */
export interface FieldA11y { id: string; "aria-describedby"?: string; "aria-invalid"?: true }

/** Label + control + hint + error. Pass a render function to receive the ids; a plain node is rendered as-is (wire the ids yourself). */
export function FormField({ label, hint, error, required, id, children }: {
  label: string; hint?: ReactNode; error?: string | null; required?: boolean; id?: string; children: ReactNode | ((a: FieldA11y) => ReactNode);
}) {
  const auto = useId();
  const fid = id ?? auto;
  const describedBy = [hint ? `${fid}-hint` : "", error ? `${fid}-error` : ""].filter(Boolean).join(" ") || undefined;
  const a11y: FieldA11y = { id: fid, "aria-describedby": describedBy, "aria-invalid": error ? true : undefined };
  return (
    <div className="space-y-1.5">
      <Label htmlFor={fid} className="text-base font-medium text-ink">
        {label}
        {required && <><span aria-hidden="true" className="text-danger-ink"> *</span><span className="sr-only"> (required)</span></>}
      </Label>
      {typeof children === "function" ? children(a11y) : children}
      {hint && <HelperText id={`${fid}-hint`} className="text-sm text-ink-muted">{hint}</HelperText>}
      {error && <p id={`${fid}-error`} role="alert" className="text-sm font-medium text-danger-ink">{error}</p>}
    </div>
  );
}

type FieldShell = { label: string; hint?: ReactNode; error?: string | null };

/** Flowbite's "lg" input is 16 px only from the `sm` breakpoint up; phones would zoom the page on focus. */
export const INPUT_LG_THEME = { field: { input: { sizes: { lg: "p-4 text-base" } } } } as const;

/** 16 px text (stops iOS zooming on focus) and tall inputs by default. */
export function TextField({ label, hint, error, required, id, className, ...rest }: FieldShell & Omit<TextInputProps, "color" | "sizing">) {
  return (
    <FormField label={label} hint={hint} error={error} required={required} id={id}>
      {(a) => <TextInput {...a} {...rest} required={required} sizing="lg" color={error ? "failure" : "gray"} className={className} theme={INPUT_LG_THEME} />}
    </FormField>
  );
}

export function SelectField({ label, hint, error, required, id, className, children, ...rest }: FieldShell & Omit<SelectProps, "color" | "sizing">) {
  return (
    <FormField label={label} hint={hint} error={error} required={required} id={id}>
      {(a) => <Select {...a} {...rest} required={required} sizing="lg" color={error ? "failure" : "gray"} className={className} theme={{ field: { select: { sizes: { lg: "p-4 text-base" } } } }}>{children}</Select>}
    </FormField>
  );
}

export function TextAreaField({ label, hint, error, required, id, className, ...rest }: FieldShell & Omit<TextareaProps, "color">) {
  return (
    <FormField label={label} hint={hint} error={error} required={required} id={id}>
      {(a) => <Textarea {...a} {...rest} required={required} color={error ? "failure" : "gray"} className={`text-base ${className ?? ""}`} rows={rest.rows ?? 4} />}
    </FormField>
  );
}
