import * as SecureStore from 'expo-secure-store';

import type { User } from '@/lib/api/types';

const TOKEN_KEY = 'cubfable.token';
const USER_KEY = 'cubfable.user';

export async function readStoredToken(): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(TOKEN_KEY);
  } catch {
    return null;
  }
}

export async function storeToken(token: string): Promise<void> {
  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

export async function clearStoredToken(): Promise<void> {
  await SecureStore.deleteItemAsync(TOKEN_KEY).catch(() => {});
  await SecureStore.deleteItemAsync(USER_KEY).catch(() => {});
}

/**
 * The last known profile, so a launch without network (or with the API
 * briefly down) boots straight into the signed-in app instead of the login
 * screen. Only a real 401 signs the user out.
 */
export async function storeUser(user: User): Promise<void> {
  await SecureStore.setItemAsync(USER_KEY, JSON.stringify(user)).catch(() => {});
}

export async function readStoredUser(): Promise<User | null> {
  try {
    const raw = await SecureStore.getItemAsync(USER_KEY);

    return raw === null ? null : (JSON.parse(raw) as User);
  } catch {
    return null;
  }
}
