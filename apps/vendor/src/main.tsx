import { StrictMode, useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import { HashRouter } from "react-router-dom";
import { AppProvider } from "@hardwhere/shared";
import { App } from "./App";
import { appConfig } from "./config";
import "./index.css";

/** Shown while the local database opens. If that takes unusually long (a stuck browser database), offer a reload instead of an endless spinner. */
function Starting() {
  const [slow, setSlow] = useState(false);
  useEffect(() => { const t = setTimeout(() => setSlow(true), 10_000); return () => clearTimeout(t); }, []);
  return (
    <div role="status" aria-live="polite" className="p-8 text-center text-slate-700">
      <p>Opening your store…</p>
      {slow && (
        <div className="mt-4 space-y-2">
          <p className="text-sm">This is taking longer than usual. Your saved data and unsent work are safe.</p>
          <button type="button" className="min-h-11 rounded-lg bg-orange-700 px-4 font-semibold text-white" onClick={() => window.location.reload()}>Reload</button>
        </div>
      )}
    </div>
  );
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <AppProvider config={appConfig} loading={<Starting />}>
      <HashRouter>
        <App />
      </HashRouter>
    </AppProvider>
  </StrictMode>,
);
