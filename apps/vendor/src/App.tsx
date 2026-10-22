import { useEffect } from "react";
import { Navigate, Outlet, Route, Routes } from "react-router-dom";
import { useActive, useAuth } from "@hardwhere/shared";
import { BottomNav, Gate } from "./shell";
import { LoadingState } from "./ui";
import { Login } from "./screens/Login";
import { Home } from "./screens/Home";
import { StockList } from "./screens/Stock";
import { ItemDetail } from "./screens/ItemDetail";
import { IntakeStart } from "./screens/IntakeStart";
import { IntakeReview } from "./screens/IntakeReview";
import { ManualEntry } from "./screens/ManualEntry";
import { Pos } from "./screens/Pos";
import { Checkout } from "./screens/Checkout";
import { PaymentStatus } from "./screens/PaymentStatus";
import { OrdersList, OrderDetail } from "./screens/Orders";
import { SyncScreen } from "./screens/Sync";

function RequireAuth() {
  const { status } = useAuth();
  if (status === "restoring") return <LoadingState label="Opening your store…" />;
  if (status === "signed_out") return <Navigate to="/login" replace />;
  return <Outlet />;
}

function Shell() {
  const { status } = useAuth();
  const active = useActive();
  // After signing back in (expired session), refresh data and send whatever was waiting: the runtime only does this on a scope change.
  useEffect(() => {
    if (status === "signed_in" && active) void active.syncNow().then(() => active.kickOutbox());
  }, [status, active]);
  return (
    <div className="mx-auto min-h-dvh max-w-md bg-slate-50 pb-20 shadow-sm">
      <Outlet />
      <BottomNav />
    </div>
  );
}

export function App() {
  const addPerms = ["ai.analyze", "inventory.create"];
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<RequireAuth />}>
        <Route element={<Shell />}>
          <Route index element={<Home />} />
          <Route path="stock" element={<Gate perm="inventory.view"><StockList /></Gate>} />
          <Route path="stock/:id" element={<Gate perm="inventory.view"><ItemDetail /></Gate>} />
          <Route path="add" element={<Gate anyOf={addPerms}><IntakeStart /></Gate>} />
          <Route path="add/manual" element={<Gate perm="inventory.create"><ManualEntry /></Gate>} />
          <Route path="add/review/:id" element={<Gate perm="ai.analyze"><IntakeReview /></Gate>} />
          <Route path="pos" element={<Gate perm="pos.sell"><Pos /></Gate>} />
          <Route path="pos/checkout" element={<Gate perm="pos.sell"><Checkout /></Gate>} />
          <Route path="pos/pay/:id" element={<Gate perm="pos.sell"><PaymentStatus /></Gate>} />
          <Route path="orders" element={<Gate perm="orders.view"><OrdersList /></Gate>} />
          <Route path="orders/:id" element={<Gate perm="orders.view"><OrderDetail /></Gate>} />
          <Route path="sync" element={<SyncScreen />} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
