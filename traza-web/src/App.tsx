import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './hooks/useAuth';
import { Layout } from './components/Layout';
import { Login } from './pages/Login';
import { Dashboard } from './pages/Dashboard';
import { Alerts } from './pages/Alerts';
import { Portal } from './pages/Portal';
import { Devices } from './pages/Devices';
import { CycleList } from './pages/inventory/CycleList';
import { CycleLive } from './pages/inventory/CycleLive';
import { StockList } from './pages/stock/StockList';
import { TagList } from './pages/tags/TagList';
import { TagDetail } from './pages/tags/TagDetail';
import { Spinner } from './components/ui';

function Protected() {
  const { user, isLoading } = useAuth();

  if (isLoading) return <Spinner label="Comprobando sesión…" />;
  if (!user) return <Navigate to="/acceso" replace />;

  return <Layout />;
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/acceso" element={<Login />} />

          <Route element={<Protected />}>
            <Route index element={<Dashboard />} />
            <Route path="inventario" element={<CycleList />} />
            <Route path="inventario/:id" element={<CycleLive />} />
            <Route path="stock" element={<StockList />} />
            <Route path="prendas" element={<TagList />} />
            <Route path="prendas/:epc" element={<TagDetail />} />
            <Route path="alertas" element={<Alerts />} />
            <Route path="portal" element={<Portal />} />
            <Route path="dispositivos" element={<Devices />} />
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
