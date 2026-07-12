import { Platform } from 'react-native';

/**
 * Base URL of the Laravel API. EXPO_PUBLIC_API_URL wins; the fallback suits
 * local development (the Android emulator reaches the host via 10.0.2.2).
 */
export function apiBaseUrl(): string {
  const configured = process.env.EXPO_PUBLIC_API_URL;

  if (configured && configured !== '') {
    return configured.replace(/\/+$/, '');
  }

  return Platform.OS === 'android' ? 'http://10.0.2.2:8000' : 'http://127.0.0.1:8000';
}

export type ApiError =
  | { kind: 'validation'; message: string; fieldErrors: Record<string, string[]> }
  | { kind: 'auth'; message: string }
  | { kind: 'notFound'; message: string }
  | { kind: 'conflict'; message: string; code: string }
  | { kind: 'paymentRequired'; message: string }
  | { kind: 'network'; message: string }
  | { kind: 'server'; message: string; status: number };

export class ApiRequestError extends Error {
  constructor(public readonly error: ApiError) {
    super(error.message);
    this.name = 'ApiRequestError';
  }
}

export function isApiError(error: unknown, kind?: ApiError['kind']): error is ApiRequestError {
  if (!(error instanceof ApiRequestError)) {
    return false;
  }

  return kind === undefined || error.error.kind === kind;
}

/** First field error message, for showing under an input. */
export function fieldError(error: unknown, field: string): string | null {
  if (isApiError(error, 'validation') && error.error.kind === 'validation') {
    return error.error.fieldErrors[field]?.[0] ?? null;
  }

  return null;
}

// The bearer token lives in memory for request speed; AuthProvider hydrates
// it from SecureStore at boot and keeps it in sync.
let currentToken: string | null = null;
let onUnauthorized: (() => void) | null = null;

export function setApiToken(token: string | null): void {
  currentToken = token;
}

export function getApiToken(): string | null {
  return currentToken;
}

export function setOnUnauthorized(handler: (() => void) | null): void {
  onUnauthorized = handler;
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  signal?: AbortSignal;
};

/**
 * JSON request against /api/v1. Normalizes every failure into ApiRequestError
 * so screens can switch on error.kind instead of status codes.
 */
export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const url = `${apiBaseUrl()}/api/v1/${path.replace(/^\/+/, '')}`;

  let response: Response;

  try {
    response = await fetch(url, {
      method: options.method ?? 'GET',
      headers: {
        Accept: 'application/json',
        ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(currentToken ? { Authorization: `Bearer ${currentToken}` } : {}),
      },
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
    });
  } catch (cause) {
    throw new ApiRequestError({
      kind: 'network',
      message: 'Could not reach CubFable. Check your connection and try again.',
    });
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const payload = (await response.json().catch(() => ({}))) as Record<string, unknown>;

  if (response.ok) {
    return payload as T;
  }

  const message = typeof payload.message === 'string' && payload.message !== ''
    ? payload.message
    : 'Something went wrong. Please try again.';

  if (response.status === 401) {
    onUnauthorized?.();
    throw new ApiRequestError({ kind: 'auth', message });
  }

  if (response.status === 404) {
    throw new ApiRequestError({ kind: 'notFound', message });
  }

  if (response.status === 402) {
    throw new ApiRequestError({ kind: 'paymentRequired', message });
  }

  if (response.status === 409) {
    throw new ApiRequestError({
      kind: 'conflict',
      message,
      code: typeof payload.code === 'string' ? payload.code : 'conflict',
    });
  }

  if (response.status === 422) {
    throw new ApiRequestError({
      kind: 'validation',
      message,
      fieldErrors: (payload.errors ?? {}) as Record<string, string[]>,
    });
  }

  throw new ApiRequestError({ kind: 'server', message, status: response.status });
}
