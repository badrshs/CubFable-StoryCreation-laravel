/** TanStack Query keys, one factory so invalidation stays typo-proof. */
export const queryKeys = {
  me: ['me'] as const,
  meta: ['meta'] as const,
  templates: ['templates'] as const,
  books: ['books'] as const,
  book: (id: number) => ['books', id] as const,
  bookStatus: (id: number) => ['books', id, 'status'] as const,
  characters: ['characters'] as const,
};
