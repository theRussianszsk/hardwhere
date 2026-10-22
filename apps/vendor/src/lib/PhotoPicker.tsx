import { useState } from "react";
import { CancelledError, capturePhoto, PermissionDeniedError, UnsupportedError } from "@hardwhere/shared";
import { Alert, Btn, Icon, SelectField } from "../ui";

export const DEMO_SCENARIOS: { hint: string; label: string }[] = [
  { hint: "makita-hp1630", label: "Makita HP1630 hammer drill" },
  { hint: "makita-hp1630k", label: "Makita HP1630K (with case)" },
  { hint: "bosch-gsb13", label: "Bosch GSB 13 RE impact drill" },
  { hint: "barcode-visible", label: "Product with a visible barcode" },
  { hint: "unbarcoded-tek-screw", label: "Tek screws (no barcode)" },
  { hint: "ambiguous-cordless-drill", label: "Ambiguous cordless drill" },
  { hint: "cordless-drill-12v", label: "Cordless drill 12V" },
  { hint: "conflicting-barcode", label: "Conflicting barcode" },
  { hint: "unknown-product", label: "Unknown product" },
  { hint: "blurry", label: "Blurry / unusable photo" },
];

/** A generated placeholder image that names its scenario. The server's demo AI keys off the `hint`, not the pixels. */
export async function makeDemoPhoto(hint: string): Promise<Blob> {
  const label = DEMO_SCENARIOS.find((d) => d.hint === hint)?.label ?? hint;
  const c = document.createElement("canvas");
  c.width = 800; c.height = 600;
  const g = c.getContext("2d");
  if (!g) throw new Error("This browser can't draw the demo photo.");
  const grad = g.createLinearGradient(0, 0, 800, 600);
  grad.addColorStop(0, "#1e3a8a"); grad.addColorStop(1, "#0f766e");
  g.fillStyle = grad; g.fillRect(0, 0, 800, 600);
  if (hint === "blurry") g.filter = "blur(8px)";
  g.fillStyle = "#ffffff"; g.font = "bold 44px sans-serif"; g.textAlign = "center";
  g.fillText("DEMO PHOTO", 400, 230);
  g.font = "bold 36px sans-serif"; g.fillText(label, 400, 310);
  g.font = "24px monospace"; g.fillText(`scenario: ${hint}`, 400, 370);
  g.filter = "none";
  return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error("Could not make the demo photo."))), "image/jpeg", 0.88));
}

export function photoErrorText(e: unknown): string | null {
  if (e instanceof CancelledError) return null;
  if (e instanceof PermissionDeniedError) return e.message;
  if (e instanceof UnsupportedError) return "This device has no camera. Choose a photo from the library or use a demo photo.";
  return e instanceof Error ? e.message : "Could not get the photo.";
}

/** Camera / library buttons plus a clearly labelled demo-photo chooser. `onPhoto(blob, hint)`: hint is only set for demo photos. */
export function PhotoPicker({ onPhoto, busy, cameraLabel = "Take photo" }: { onPhoto: (blob: Blob, hint?: string) => void | Promise<void>; busy?: boolean; cameraLabel?: string }) {
  const [error, setError] = useState<string | null>(null);
  const [demo, setDemo] = useState(DEMO_SCENARIOS[0]!.hint);
  const [working, setWorking] = useState(false);

  const run = async (get: () => Promise<{ blob: Blob; hint?: string }>) => {
    setError(null); setWorking(true);
    try { const { blob, hint } = await get(); await onPhoto(blob, hint); } catch (e) { setError(photoErrorText(e)); } finally { setWorking(false); }
  };
  const disabled = busy || working;

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-2">
        <Btn disabled={disabled} onClick={() => void run(async () => ({ blob: await capturePhoto("camera") }))}><Icon name="camera" />{cameraLabel}</Btn>
        <Btn variant="secondary" disabled={disabled} onClick={() => void run(async () => ({ blob: await capturePhoto("library") }))}><Icon name="image" />From library</Btn>
      </div>
      <details className="rounded-lg border border-violet-300 bg-violet-50 p-3">
        <summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold text-violet-950">Demo photos (for the live demo only)</summary>
        <div className="mt-2 space-y-2">
          <p className="text-sm text-violet-950">Uploads a generated placeholder image tagged with the chosen scenario so the demo AI returns a predictable answer. Real photos send no scenario.</p>
          <SelectField label="Demo scenario" value={demo} onChange={(e) => setDemo(e.target.value)}>
            {DEMO_SCENARIOS.map((d) => <option key={d.hint} value={d.hint}>{d.label}</option>)}
          </SelectField>
          <Btn variant="secondary" block disabled={disabled} onClick={() => void run(async () => ({ blob: await makeDemoPhoto(demo), hint: demo }))}>Use this demo photo</Btn>
        </div>
      </details>
      {error && <Alert tone="error">{error}</Alert>}
    </div>
  );
}
