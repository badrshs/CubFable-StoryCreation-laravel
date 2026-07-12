import { router } from 'expo-router';

import { CharacterForm } from '@/components/wizard/character-form';
import { useCreateCharacter } from '@/lib/api/mutations';

export default function NewCharacterScreen() {
  const createCharacter = useCreateCharacter();

  return (
    <CharacterForm
      title="New character"
      submitLabel="Save character"
      onSubmit={async (input) => {
        await createCharacter.mutateAsync(input);
        router.back();
      }}
    />
  );
}
