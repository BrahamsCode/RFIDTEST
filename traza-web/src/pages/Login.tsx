import { useState, type FormEvent } from 'react';
import { useAuth } from '../hooks/useAuth';
import { messageFrom } from '../lib/api';
import { Button } from '../components/ui';

export function Login() {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setBusy(true);

    try {
      await login(email, password);
    } catch (e) {
      setError(messageFrom(e, 'No se pudo iniciar sesión. Revisa el correo y la contraseña.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <form onSubmit={submit} className="w-full max-w-sm rounded border border-slate-200 bg-white p-6">
        <h1 className="text-xl font-semibold">TRAZA</h1>
        <p className="mt-0.5 text-sm text-slate-500">Control de stock por RFID</p>

        <label className="mt-6 block text-sm font-medium text-slate-700">
          Correo
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoComplete="username"
            className="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
          />
        </label>

        <label className="mt-4 block text-sm font-medium text-slate-700">
          Contraseña
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            autoComplete="current-password"
            className="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
          />
        </label>

        {error && (
          <p className="mt-4 text-sm text-red-700" role="alert">
            {error}
          </p>
        )}

        <div className="mt-6">
          <Button type="submit" variant="primary" disabled={busy}>
            {busy ? 'Entrando…' : 'Entrar'}
          </Button>
        </div>
      </form>
    </div>
  );
}
