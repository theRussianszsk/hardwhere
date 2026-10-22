import { Button, Spinner } from "flowbite-react";
import { useEffect, useState } from "react";
import { formatKm } from "../format";
import { Icon } from "./icons";
import { formatStoreHours } from "./logic";
import { cx } from "./util";

/** Square preview of a captured photo (Blob from the camera/photo store) or a URL. The object URL is released on unmount. */
export function PhotoThumb({ source, alt, onRemove, busy, size = 96, className }: { source: Blob | string; alt: string; onRemove?: () => void; busy?: boolean; size?: number; className?: string }) {
  const [url, setUrl] = useState<string | null>(typeof source === "string" ? source : null);
  useEffect(() => {
    if (typeof source === "string") { setUrl(source); return; }
    const u = URL.createObjectURL(source);
    setUrl(u);
    return () => URL.revokeObjectURL(u);
  }, [source]);
  return (
    <div className={cx("relative inline-block shrink-0", className)} style={{ width: size, height: size }}>
      {url ? <img src={url} alt={alt} className="h-full w-full rounded-control bg-surface-2 object-cover" /> : <div aria-hidden="true" className="h-full w-full rounded-control bg-surface-2" />}
      {busy && (
        <div role="status" className="absolute inset-0 flex items-center justify-center rounded-control bg-black/40">
          <Spinner size="md" aria-label="Working" />
        </div>
      )}
      {onRemove && (
        <button type="button" onClick={onRemove} aria-label={`Remove photo: ${alt}`} className="touch-target absolute -right-3 -top-3 inline-flex items-center justify-center rounded-full bg-surface text-ink shadow ring-1 ring-line">
          <Icon name="x" />
        </button>
      )}
    </div>
  );
}

export interface StoreInfoCardProps {
  name: string;
  /** One printable line, e.g. "123 Rizal St, Brgy. Poblacion, Cebu City". */
  address: string;
  /** The API's hours object ({"mon-sat": ["08:00","18:00"]}) or pre-formatted lines. */
  hours?: Partial<Record<string, string[]>> | string[] | null;
  phone?: string | null;
  distanceKm?: number | null;
  onDirections?: () => void;
  onCall?: () => void;
  className?: string;
}

export function StoreInfoCard({ name, address, hours, phone, distanceKm, onDirections, onCall, className }: StoreInfoCardProps) {
  const lines = Array.isArray(hours) ? hours : formatStoreHours(hours);
  const distance = formatKm(distanceKm);
  return (
    <section aria-label={name} className={cx("space-y-3 rounded-card border border-line bg-surface p-4", className)}>
      <div className="flex items-start justify-between gap-2">
        <h3 className="text-lg font-semibold leading-snug">{name}</h3>
        {distance && <span className="shrink-0 rounded-full bg-surface-2 px-2.5 py-1 text-sm font-medium text-ink-muted">{distance}</span>}
      </div>
      <p className="flex items-start gap-2 text-ink-muted"><Icon name="pin" className="mt-0.5" /><span>{address}</span></p>
      {lines.length > 0 && (
        <div className="flex items-start gap-2 text-ink-muted">
          <Icon name="clock" className="mt-0.5" />
          <ul>{lines.map((l) => <li key={l}>{l}</li>)}</ul>
        </div>
      )}
      {(onDirections || (phone && onCall)) && (
        <div className="flex gap-3 pt-1">
          {onDirections && <Button size="lg" className="touch-target flex-1" onClick={onDirections}><Icon name="pin" className="me-2" />Directions</Button>}
          {phone && onCall && <Button color="light" size="lg" className="touch-target flex-1" onClick={onCall} aria-label={`Call ${name} at ${phone}`}><Icon name="phone" className="me-2" />Call</Button>}
        </div>
      )}
    </section>
  );
}
