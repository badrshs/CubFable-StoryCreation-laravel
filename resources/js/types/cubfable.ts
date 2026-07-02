// CubFable domain types shared across the wizard, gallery, reader, and library.
// Values are the canonical strings stored on the book (camelCase props from the
// Laravel controllers).

export type AgeRange = '2-4' | '4-6' | '6-8' | '8-10';

export type ArtStyle =
    | '3d-animation'
    | 'watercolor'
    | 'geometric'
    | 'clay-animation'
    | 'sticker-art'
    | 'comic-book'
    | 'gouache'
    | 'soft-anime'
    | 'block-world'
    | 'collage';

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

export type BookStatus =
    'draft' | 'pending' | 'generating' | 'complete' | 'failed';

export type PageStatus = 'pending' | 'generating' | 'complete' | 'failed';

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
};

export type Character = {
    id: number;
    name: string;
    role: string | null;
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
    createdAt: string;
};

export type BookWithPages = Book & {
    pages: BookPage[];
    characters: Character[];
};
