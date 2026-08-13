import { createContext, useContext, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchUser, login as apiLogin, logout as apiLogout, type CurrentUser } from '../lib/api';

interface AuthValue {
  user: CurrentUser | null;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['user'],
    queryFn: fetchUser,
    retry: false,
    // Sin reintentos ni refresco al enfocar: si no hay sesión, no la hay.
    refetchOnWindowFocus: false,
    staleTime: 5 * 60_000,
  });

  const loginMutation = useMutation({
    mutationFn: ({ email, password }: { email: string; password: string }) =>
      apiLogin(email, password),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['user'] }),
  });

  const logoutMutation = useMutation({
    mutationFn: apiLogout,
    onSuccess: () => queryClient.clear(),
  });

  return (
    <AuthContext.Provider
      value={{
        user: data ?? null,
        isLoading,
        login: async (email, password) => {
          await loginMutation.mutateAsync({ email, password });
        },
        logout: async () => {
          await logoutMutation.mutateAsync();
        },
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext);

  if (value === null) {
    throw new Error('useAuth debe usarse dentro de AuthProvider.');
  }

  return value;
}
