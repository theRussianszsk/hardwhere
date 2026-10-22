import { createContext, Component, useContext, useEffect, useState, type ErrorInfo, type ReactNode } from "react";
import { bootApp, type AppConfig, type AppServices } from "./runtime";

const Ctx = createContext<AppServices | null>(null);

export function useServices(): AppServices {
  const s = useContext(Ctx);
  if (!s) throw new Error("useServices must be used inside <AppProvider>");
  return s;
}

interface Props { config: AppConfig; children: ReactNode; loading?: ReactNode }

/** Boots the local database + session once, then renders children. Boot failures are shown, never swallowed. */
export function AppProvider({ config, children, loading }: Props) {
  const [state, setState] = useState<{ services?: AppServices; error?: unknown }>({});
  useEffect(() => {
    let live = true;
    bootApp(config).then((services) => live && setState({ services }), (error) => live && setState({ error }));
    return () => { live = false; };
  }, [config]);

  if (state.error) return <BootFailure error={state.error} />;
  if (!state.services) return <>{loading ?? <div role="status" className="p-6 text-center text-slate-600">Starting…</div>}</>;
  return <Ctx.Provider value={state.services}><Boundary>{children}</Boundary></Ctx.Provider>;
}

function BootFailure({ error }: { error: unknown }) {
  const msg = error instanceof Error ? error.message : String(error);
  return (
    <div role="alert" className="mx-auto mt-16 max-w-md rounded-xl border border-red-200 bg-red-50 p-5 text-red-900">
      <h1 className="text-lg font-semibold">The app couldn't start its local storage</h1>
      <p className="mt-2 text-sm">{msg}</p>
      <p className="mt-2 text-sm">Close and reopen the app. If this keeps happening, reinstalling clears the local cache (unsent changes on this device would be lost).</p>
    </div>
  );
}

class Boundary extends Component<{ children: ReactNode }, { error: Error | null }> {
  override state = { error: null as Error | null };
  static getDerivedStateFromError(error: Error) { return { error }; }
  override componentDidCatch(error: Error, info: ErrorInfo) { console.error("UI error", error, info.componentStack); }
  override render() {
    return this.state.error ? (
      <div role="alert" className="m-4 rounded-xl border border-red-200 bg-red-50 p-4 text-red-900">
        <p className="font-semibold">Something went wrong on this screen.</p>
        <p className="mt-1 text-sm">{this.state.error.message}</p>
        <button className="touch-target mt-3 rounded-lg bg-red-700 px-4 text-white" onClick={() => this.setState({ error: null })}>Try again</button>
      </div>
    ) : this.props.children;
  }
}
