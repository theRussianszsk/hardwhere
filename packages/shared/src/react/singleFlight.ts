/**
 * Coalesces concurrent calls into one run, and allows the next run as soon as the current one settles.
 * The reset is attached AFTER the promise exists: resetting inside `run` would clear the slot before it was assigned when `run`
 * returns early (offline, signed out), leaving a finished promise that silently blocks every later call.
 */
export function singleFlight(run: () => Promise<void>): () => Promise<void> {
  let inflight: Promise<void> | null = null;
  return () => {
    if (inflight) return inflight;
    inflight = run().finally(() => { inflight = null; });
    return inflight;
  };
}
