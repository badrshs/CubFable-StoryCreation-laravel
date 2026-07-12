// CubFable domain types, mirroring the Laravel API resources (which mirror
// resources/js/types/cubfable.ts on the web, so all three stay in lockstep).

export type AgeRange = '2-4' | '4-6' | '6-8' | '8-10';

export type ArtStyle =
  | '3d-animation'
  | 'cartoon'
  | 'storybook'
  | 'watercolor'
  | 'soft-anime'
  | 'comic-book';

export type BookFont = 'playful' | 'classic' | 'handwritten' | 'bold';

export type StoryLanguage =
  | 'en'
  | 'ar'
  | 'tr'
  | 'es'
  | 'fr'
  | 'de'
  | 'it'
  | 'pt'
  | 'ru'
  | 'hi'
  | 'ur'
  | 'zh';

export type BookStatus = 'draft' | 'pending' | 'generating' | 'complete' | 'failed';

export type PageStatus = 'pending' | 'generating' | 'complete' | 'failed';

export type User = {
  id: number;
  name: string;
  email: string;
  emailVerified: boolean;
  createdAt: string | null;
};

export type Template = {
  id: number;
  title: string;
  description: string;
  theme: string;
  coverImageUrl: string | null;
  pageCount: number;
  ageMin: number;
  ageMax: number;
  lifeLessons: string[];
  subjects: string[];
};

export type CharacterAgeGroup = 'adult' | 'child';

export type Character = {
  id: number;
  name: string;
  role: string | null;
  ageGroup: CharacterAgeGroup | null;
  description: string | null;
  photoUrl: string | null;
  isMain?: boolean;
};

export type BookPage = {
  id: number;
  pageNumber: number;
  text: string;
  imageUrl: string | null;
  status: PageStatus;
};

export type Book = {
  id: number;
  childName: string;
  ageRange: AgeRange;
  theme: string;
  subject: string | null;
  lifeLesson: string;
  artStyle: string;
  font: BookFont;
  language: StoryLanguage;
  status: BookStatus;
  coverImageUrl: string | null;
  coverStatus: string | null;
  createdAt: string;
};

export type BookSummary = Book & {
  pagesTotal: number;
  pagesDone: number;
};

export type BookWithPages = Book & {
  pages: BookPage[];
  characters: Character[];
};

/** GET books/{id}/status: the lightweight 3s generation-progress payload. */
export type BookStatusPayload = {
  status: BookStatus;
  coverStatus: string | null;
  coverImageUrl: string | null;
  pagesTotal: number;
  pagesDone: number;
  pages: {
    id: number;
    pageNumber: number;
    status: PageStatus;
    imageUrl: string | null;
  }[];
};

/** GET meta: the wizard option catalog. */
export type Meta = {
  ageRanges: AgeRange[];
  artStyles: ArtStyle[];
  fonts: BookFont[];
  languages: { code: StoryLanguage; label: string; rtl: boolean }[];
  maxCast: number;
  photoUploadQuality: 'original' | 'optimized';
  price: number;
  currency: string;
};

/** A wizard cast entry as the API accepts it. */
export type CastMemberInput = {
  characterId?: number | null;
  name: string;
  role?: string | null;
  ageGroup?: CharacterAgeGroup | null;
  description?: string | null;
  /** A data: URL uploads a new photo; omit to keep the stored one. */
  photoUrl?: string | null;
  isMain?: boolean;
};

export type BookInput = {
  templateId: number;
  ageRange: AgeRange;
  theme: string;
  subject: string;
  lifeLesson: string;
  artStyle: ArtStyle;
  font: BookFont;
  language: StoryLanguage;
  characters: CastMemberInput[];
};

export type CharacterInput = {
  name: string;
  role?: string | null;
  ageGroup?: CharacterAgeGroup | null;
  description?: string | null;
  photoUrl?: string | null;
};
