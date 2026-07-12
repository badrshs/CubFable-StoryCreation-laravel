import { useQuery, type QueryClient } from '@tanstack/react-query';

import * as endpoints from './endpoints';
import { queryKeys } from './keys';
import type { BookStatusPayload, BookWithPages } from './types';

export function useMeta() {
  return useQuery({
    queryKey: queryKeys.meta,
    queryFn: endpoints.fetchMeta,
    staleTime: 24 * 60 * 60 * 1000,
  });
}

export function useTemplates() {
  return useQuery({
    queryKey: queryKeys.templates,
    queryFn: endpoints.fetchTemplates,
    staleTime: 60 * 60 * 1000,
  });
}

export function useBooks() {
  return useQuery({
    queryKey: queryKeys.books,
    queryFn: endpoints.fetchBooks,
  });
}

export function useBook(id: number) {
  return useQuery({
    queryKey: queryKeys.book(id),
    queryFn: () => endpoints.fetchBook(id),
  });
}

/** True while the book (or any page) is still being generated. */
export function isGenerationInFlight(
  book: Pick<BookWithPages, 'status'> & { pages?: { status: string }[] },
): boolean {
  return (
    book.status === 'pending' ||
    book.status === 'generating' ||
    (book.pages ?? []).some((page) => page.status === 'pending' || page.status === 'generating')
  );
}

function statusInFlight(status: BookStatusPayload): boolean {
  return (
    status.status === 'pending' ||
    status.status === 'generating' ||
    status.coverStatus === 'generating' ||
    status.pages.some((page) => page.status === 'pending' || page.status === 'generating')
  );
}

/**
 * The 3-second generation poll, mirroring the web reader: polls only while
 * something is still pending or generating, then goes quiet.
 */
export function useBookStatus(id: number, enabled = true) {
  return useQuery({
    queryKey: queryKeys.bookStatus(id),
    queryFn: () => endpoints.fetchBookStatus(id),
    enabled,
    refetchInterval: (query) => {
      const status = query.state.data;

      if (status === undefined || statusInFlight(status)) {
        return 3000;
      }

      return false;
    },
  });
}

export function useCharacters() {
  return useQuery({
    queryKey: queryKeys.characters,
    queryFn: endpoints.fetchCharacters,
  });
}

/** Refresh everything that describes one book (detail, status, library). */
export async function invalidateBook(queryClient: QueryClient, bookId: number): Promise<void> {
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: queryKeys.book(bookId) }),
    queryClient.invalidateQueries({ queryKey: queryKeys.bookStatus(bookId) }),
    queryClient.invalidateQueries({ queryKey: queryKeys.books }),
  ]);
}
