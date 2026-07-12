import { useMutation, useQueryClient } from '@tanstack/react-query';

import * as endpoints from './endpoints';
import { queryKeys } from './keys';
import { invalidateBook } from './queries';
import type { BookInput, BookWithPages, CharacterInput } from './types';

export function useCreateBook() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: BookInput) => endpoints.createBook(input),
    onSuccess: (book) => {
      queryClient.setQueryData(queryKeys.book(book.id), book);
      void queryClient.invalidateQueries({ queryKey: queryKeys.books });
      void queryClient.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}

export function useUpdateBook(bookId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: Omit<BookInput, 'templateId'>) => endpoints.updateBook(bookId, input),
    onSuccess: (book) => {
      queryClient.setQueryData(queryKeys.book(book.id), book);
      void queryClient.invalidateQueries({ queryKey: queryKeys.books });
      void queryClient.invalidateQueries({ queryKey: queryKeys.characters });
    },
  });
}

export function useDeleteBook() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (bookId: number) => endpoints.deleteBook(bookId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.books });
    },
  });
}

/** Optimistically writes edited page text into the cached book. */
export function useUpdatePageText(bookId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ pageId, text }: { pageId: number; text: string }) =>
      endpoints.updatePageText(bookId, pageId, text),
    onMutate: async ({ pageId, text }) => {
      await queryClient.cancelQueries({ queryKey: queryKeys.book(bookId) });

      const previous = queryClient.getQueryData<BookWithPages>(queryKeys.book(bookId));

      if (previous !== undefined) {
        queryClient.setQueryData<BookWithPages>(queryKeys.book(bookId), {
          ...previous,
          pages: previous.pages.map((page) => (page.id === pageId ? { ...page, text } : page)),
        });
      }

      return { previous };
    },
    onError: (_error, _variables, context) => {
      if (context?.previous !== undefined) {
        queryClient.setQueryData(queryKeys.book(bookId), context.previous);
      }
    },
  });
}

export function useRegeneratePage(bookId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (pageId: number) => endpoints.regeneratePage(bookId, pageId),
    onSuccess: () => void invalidateBook(queryClient, bookId),
  });
}

export function useRegenerateCover(bookId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => endpoints.regenerateCover(bookId),
    onSuccess: () => void invalidateBook(queryClient, bookId),
  });
}

export function useRestyleBook(bookId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (artStyle: string) => endpoints.restyleBook(bookId, artStyle),
    onSuccess: () => void invalidateBook(queryClient, bookId),
  });
}

export function useCreateCharacter() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: CharacterInput) => endpoints.createCharacter(input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: queryKeys.characters }),
  });
}

export function useUpdateCharacter() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: CharacterInput }) =>
      endpoints.updateCharacter(id, input),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: queryKeys.characters }),
  });
}

export function useDeleteCharacter() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => endpoints.deleteCharacter(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: queryKeys.characters }),
  });
}
