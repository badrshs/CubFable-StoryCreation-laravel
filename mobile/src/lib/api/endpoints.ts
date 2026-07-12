import { api } from './client';
import type {
  Book,
  BookInput,
  BookStatus,
  BookStatusPayload,
  BookSummary,
  BookWithPages,
  Character,
  CharacterInput,
  Meta,
  Template,
  User,
} from './types';

type Wrapped<T> = { data: T };

// ---- Auth ----

export async function register(input: {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  deviceName: string;
}): Promise<{ user: User; token: string }> {
  const response = await api<Wrapped<User> & { token: string }>('auth/register', {
    method: 'POST',
    body: input,
  });

  return { user: response.data, token: response.token };
}

export async function login(input: {
  email: string;
  password: string;
  deviceName: string;
}): Promise<{ user: User; token: string }> {
  const response = await api<Wrapped<User> & { token: string }>('auth/login', {
    method: 'POST',
    body: input,
  });

  return { user: response.data, token: response.token };
}

export async function logout(): Promise<void> {
  await api<void>('auth/logout', { method: 'POST' });
}

export async function forgotPassword(email: string): Promise<void> {
  await api<{ message: string }>('auth/forgot-password', { method: 'POST', body: { email } });
}

export async function fetchMe(): Promise<User> {
  return (await api<Wrapped<User>>('me')).data;
}

export async function updateProfile(input: { name: string; email: string }): Promise<User> {
  return (await api<Wrapped<User>>('me', { method: 'PATCH', body: input })).data;
}

export async function updatePassword(input: {
  current_password: string;
  password: string;
  password_confirmation: string;
}): Promise<void> {
  await api<void>('me/password', { method: 'PUT', body: input });
}

export async function deleteAccount(password: string): Promise<void> {
  await api<void>('account', { method: 'DELETE', body: { password } });
}

// ---- Catalog ----

export async function fetchMeta(): Promise<Meta> {
  return (await api<Wrapped<Meta>>('meta')).data;
}

export async function fetchTemplates(): Promise<{ templates: Template[]; themes: string[] }> {
  const response = await api<Wrapped<Template[]> & { meta: { themes: string[] } }>('templates');

  return { templates: response.data, themes: response.meta.themes };
}

// ---- Books ----

export async function fetchBooks(): Promise<BookSummary[]> {
  return (await api<Wrapped<BookSummary[]>>('books')).data;
}

export async function fetchBook(id: number): Promise<BookWithPages> {
  return (await api<Wrapped<BookWithPages>>(`books/${id}`)).data;
}

export async function fetchBookStatus(id: number): Promise<BookStatusPayload> {
  return (await api<Wrapped<BookStatusPayload>>(`books/${id}/status`)).data;
}

export async function createBook(input: BookInput): Promise<BookWithPages> {
  return (await api<Wrapped<BookWithPages>>('books', { method: 'POST', body: input })).data;
}

export async function updateBook(id: number, input: Omit<BookInput, 'templateId'>): Promise<BookWithPages> {
  return (await api<Wrapped<BookWithPages>>(`books/${id}`, { method: 'PATCH', body: input })).data;
}

export async function deleteBook(id: number): Promise<void> {
  await api<void>(`books/${id}`, { method: 'DELETE' });
}

export async function regenerateCover(id: number): Promise<void> {
  await api<Wrapped<{ status: BookStatus; coverStatus: string | null }>>(`books/${id}/regenerate-cover`, {
    method: 'POST',
  });
}

export async function restyleBook(id: number, artStyle: string): Promise<void> {
  await api<Wrapped<{ status: BookStatus; coverStatus: string | null }>>(`books/${id}/restyle`, {
    method: 'POST',
    body: { artStyle },
  });
}

export async function updatePageText(bookId: number, pageId: number, text: string): Promise<void> {
  await api<Wrapped<unknown>>(`books/${bookId}/pages/${pageId}`, { method: 'PATCH', body: { text } });
}

export async function regeneratePage(bookId: number, pageId: number): Promise<void> {
  await api<Wrapped<unknown>>(`books/${bookId}/pages/${pageId}/regenerate`, { method: 'POST' });
}

// ---- In-app purchases ----

export async function createIapIntent(bookId: number): Promise<{ orderId: number; productId: string }> {
  return (await api<Wrapped<{ orderId: number; productId: string }>>(`books/${bookId}/iap/intent`, {
    method: 'POST',
  })).data;
}

export async function reconcileIap(bookId: number): Promise<BookStatus> {
  return (await api<Wrapped<{ status: BookStatus }>>(`books/${bookId}/iap/reconcile`, {
    method: 'POST',
  })).data.status;
}

// ---- Characters ----

export async function fetchCharacters(): Promise<Character[]> {
  return (await api<Wrapped<Character[]>>('characters')).data;
}

export async function createCharacter(input: CharacterInput): Promise<Character> {
  return (await api<Wrapped<Character>>('characters', { method: 'POST', body: input })).data;
}

export async function updateCharacter(id: number, input: CharacterInput): Promise<Character> {
  return (await api<Wrapped<Character>>(`characters/${id}`, { method: 'PATCH', body: input })).data;
}

export async function deleteCharacter(id: number): Promise<void> {
  await api<void>(`characters/${id}`, { method: 'DELETE' });
}

// ---- Downloads ----

export function bookDownloadUrl(bookId: number, variant: 'home' | 'print'): string {
  return `books/${bookId}/download?variant=${variant}`;
}
