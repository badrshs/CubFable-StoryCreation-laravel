import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react';

import type {
  AgeRange,
  ArtStyle,
  BookFont,
  BookInput,
  BookWithPages,
  CastMemberInput,
  StoryLanguage,
  Template,
} from '@/lib/api/types';

export type WizardCastMember = {
  /** Local list key; stable across edits. */
  key: string;
  characterId: number | null;
  name: string;
  role: string;
  ageGroup: 'adult' | 'child';
  description: string;
  /** Freshly picked photo, ready for upload. */
  photoDataUrl: string | null;
  /** What to show: the picked photo or the stored one. */
  photoPreviewUri: string | null;
};

type WizardState = {
  template: Template;
  /** Set when editing an existing unpaid draft. */
  bookId: number | null;
  ageRange: AgeRange;
  theme: string;
  subject: string;
  lifeLesson: string;
  artStyle: ArtStyle;
  font: BookFont;
  language: StoryLanguage;
  hero: WizardCastMember;
  cast: WizardCastMember[];
};

type WizardContextValue = {
  state: WizardState | null;
  startFromTemplate: (template: Template) => void;
  startFromDraft: (template: Template, book: BookWithPages) => void;
  update: (patch: Partial<Omit<WizardState, 'template' | 'bookId'>>) => void;
  reset: () => void;
  buildPayload: () => BookInput;
};

const WizardContext = createContext<WizardContextValue | null>(null);

let keyCounter = 0;

export function newCastKey(): string {
  keyCounter += 1;

  return `cast-${keyCounter}`;
}

export function emptyCastMember(ageGroup: 'adult' | 'child' = 'adult'): WizardCastMember {
  return {
    key: newCastKey(),
    characterId: null,
    name: '',
    role: '',
    ageGroup,
    description: '',
    photoDataUrl: null,
    photoPreviewUri: null,
  };
}

export function WizardProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<WizardState | null>(null);

  const startFromTemplate = useCallback((template: Template) => {
    setState({
      template,
      bookId: null,
      ageRange: template.ageMin <= 4 ? '2-4' : template.ageMin <= 6 ? '4-6' : '6-8',
      theme: template.theme,
      subject: template.subjects[0] ?? '',
      lifeLesson: template.lifeLessons[0] ?? '',
      artStyle: '3d-animation',
      font: 'classic',
      language: 'en',
      hero: emptyCastMember('child'),
      cast: [],
    });
  }, []);

  const startFromDraft = useCallback((template: Template, book: BookWithPages) => {
    const toMember = (character: BookWithPages['characters'][number]): WizardCastMember => ({
      key: newCastKey(),
      characterId: character.id,
      name: character.name,
      role: character.role ?? '',
      ageGroup: character.ageGroup ?? 'adult',
      description: character.description ?? '',
      photoDataUrl: null,
      photoPreviewUri: character.photoUrl,
    });

    const heroCharacter = book.characters.find((character) => character.isMain) ?? book.characters[0];
    const supporting = book.characters.filter((character) => character !== heroCharacter);

    setState({
      template,
      bookId: book.id,
      ageRange: book.ageRange,
      theme: book.theme,
      subject: book.subject ?? '',
      lifeLesson: book.lifeLesson,
      artStyle: book.artStyle as ArtStyle,
      font: book.font,
      language: book.language,
      hero: heroCharacter !== undefined ? { ...toMember(heroCharacter), ageGroup: heroCharacter.ageGroup ?? 'child' } : emptyCastMember('child'),
      cast: supporting.map(toMember),
    });
  }, []);

  const update = useCallback((patch: Partial<Omit<WizardState, 'template' | 'bookId'>>) => {
    setState((current) => (current === null ? current : { ...current, ...patch }));
  }, []);

  const reset = useCallback(() => {
    setState(null);
  }, []);

  const buildPayload = useCallback((): BookInput => {
    if (state === null) {
      throw new Error('Wizard not initialized');
    }

    const toInput = (member: WizardCastMember, isMain: boolean): CastMemberInput => ({
      characterId: member.characterId,
      name: member.name.trim(),
      role: member.role.trim() === '' ? null : member.role.trim(),
      ageGroup: member.ageGroup,
      description: member.description.trim() === '' ? null : member.description.trim(),
      // Only a freshly picked photo travels; the server keeps a stored one.
      ...(member.photoDataUrl !== null ? { photoUrl: member.photoDataUrl } : {}),
      isMain,
    });

    return {
      templateId: state.template.id,
      ageRange: state.ageRange,
      theme: state.theme.trim(),
      subject: state.subject.trim(),
      lifeLesson: state.lifeLesson.trim(),
      artStyle: state.artStyle,
      font: state.font,
      language: state.language,
      characters: [
        toInput(state.hero, true),
        ...state.cast.filter((member) => member.name.trim() !== '').map((member) => toInput(member, false)),
      ],
    };
  }, [state]);

  const value = useMemo(
    () => ({ state, startFromTemplate, startFromDraft, update, reset, buildPayload }),
    [state, startFromTemplate, startFromDraft, update, reset, buildPayload],
  );

  return <WizardContext.Provider value={value}>{children}</WizardContext.Provider>;
}

export function useWizard(): WizardContextValue {
  const context = useContext(WizardContext);

  if (context === null) {
    throw new Error('useWizard must be used within WizardProvider');
  }

  return context;
}
