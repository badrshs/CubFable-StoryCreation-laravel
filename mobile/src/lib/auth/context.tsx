import { useQueryClient } from '@tanstack/react-query';
import * as Device from 'expo-device';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { Platform } from 'react-native';

import { setApiToken, setOnUnauthorized } from '@/lib/api/client';
import * as endpoints from '@/lib/api/endpoints';
import type { User } from '@/lib/api/types';
import { identifyPurchaser, resetPurchaser } from '@/lib/purchases/revenuecat';

import { clearStoredToken, readStoredToken, storeToken } from './storage';

type AuthState =
  | { status: 'loading'; user: null }
  | { status: 'guest'; user: null }
  | { status: 'authed'; user: User };

type AuthContextValue = AuthState & {
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
  setUser: (user: User) => void;
  forgetSession: () => void;
};

const AuthContext = createContext<AuthContextValue | null>(null);

function deviceName(): string {
  return Device.deviceName ?? (Platform.OS === 'ios' ? 'iPhone' : 'Android phone');
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: 'loading', user: null });
  const queryClient = useQueryClient();

  const forgetSession = useCallback(() => {
    setApiToken(null);
    void clearStoredToken();
    void resetPurchaser();
    queryClient.clear();
    setState({ status: 'guest', user: null });
  }, [queryClient]);

  // Boot: hydrate the stored token and validate it against the API. The
  // splash screen stays up until this resolves (see the root layout).
  useEffect(() => {
    let cancelled = false;

    setOnUnauthorized(forgetSession);

    (async () => {
      const token = await readStoredToken();

      if (token === null) {
        if (!cancelled) {
          setState({ status: 'guest', user: null });
        }

        return;
      }

      setApiToken(token);

      try {
        const user = await endpoints.fetchMe();

        if (!cancelled) {
          await identifyPurchaser(user.id);
          setState({ status: 'authed', user });
        }
      } catch {
        // An invalid token already triggered forgetSession via 401; a network
        // failure should not silently sign the user out.
        if (!cancelled) {
          setState((current) => (current.status === 'loading' ? { status: 'guest', user: null } : current));
        }
      }
    })();

    return () => {
      cancelled = true;
      setOnUnauthorized(null);
    };
  }, [forgetSession]);

  const acceptSession = useCallback(async (user: User, token: string) => {
    setApiToken(token);
    await storeToken(token);
    await identifyPurchaser(user.id);
    setState({ status: 'authed', user });
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      const session = await endpoints.login({ email, password, deviceName: deviceName() });
      await acceptSession(session.user, session.token);
    },
    [acceptSession],
  );

  const register = useCallback(
    async (name: string, email: string, password: string) => {
      const session = await endpoints.register({
        name,
        email,
        password,
        password_confirmation: password,
        deviceName: deviceName(),
      });
      await acceptSession(session.user, session.token);
    },
    [acceptSession],
  );

  const logout = useCallback(async () => {
    await endpoints.logout().catch(() => {});
    forgetSession();
  }, [forgetSession]);

  const refreshUser = useCallback(async () => {
    const user = await endpoints.fetchMe();
    setState({ status: 'authed', user });
  }, []);

  const setUser = useCallback((user: User) => {
    setState({ status: 'authed', user });
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({ ...state, login, register, logout, refreshUser, setUser, forgetSession }),
    [state, login, register, logout, refreshUser, setUser, forgetSession],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);

  if (context === null) {
    throw new Error('useAuth must be used within AuthProvider');
  }

  return context;
}
