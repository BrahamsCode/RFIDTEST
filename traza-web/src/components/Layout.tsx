import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

const SECTIONS = [
  { to: '/', label: 'Panel', end: true },
  { to: '/inventario', label: 'Inventario' },
  { to: '/stock', label: 'Stock' },
  { to: '/prendas', label: 'Prendas' },
  { to: '/alertas', label: 'Alertas' },
  { to: '/portal', label: 'Portal' },
  { to: '/dispositivos', label: 'Dispositivos' },
];

export function Layout() {
  const { user, logout } = useAuth();

  return (
    <div className="min-h-screen bg-slate-50">
      <nav className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center gap-6 px-6">
          <span className="py-3 text-lg font-semibold tracking-tight">TRAZA</span>

          <div className="flex flex-1 gap-1">
            {SECTIONS.map((section) => (
              <NavLink
                key={section.to}
                to={section.to}
                end={section.end}
                className={({ isActive }) =>
                  `border-b-2 px-3 py-3 text-sm font-medium transition-colors ${
                    isActive
                      ? 'border-slate-900 text-slate-900'
                      : 'border-transparent text-slate-500 hover:text-slate-800'
                  }`
                }
              >
                {section.label}
              </NavLink>
            ))}
          </div>

          {user && (
            <div className="flex items-center gap-3 text-sm">
              <span className="text-slate-500">{user.name}</span>
              <button
                type="button"
                onClick={() => void logout()}
                className="text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline"
              >
                Salir
              </button>
            </div>
          )}
        </div>
      </nav>

      <main>
        <Outlet />
      </main>
    </div>
  );
}
