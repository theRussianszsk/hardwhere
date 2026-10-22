import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { HashRouter } from "react-router-dom";
import { AppProvider } from "@hardwhere/shared";
import { ToastHost } from "@hardwhere/shared/ui";
import { App } from "./App";
import { CartProvider } from "./cart";
import { appConfig } from "./config";
import { LoadingState } from "./components/ui";
import "./index.css";

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <AppProvider config={appConfig} loading={<div className="min-h-dvh bg-canvas"><LoadingState label="Starting Hardwhere" /></div>}>
      <HashRouter>
        <CartProvider>
          <ToastHost>
            <App />
          </ToastHost>
        </CartProvider>
      </HashRouter>
    </AppProvider>
  </StrictMode>,
);
