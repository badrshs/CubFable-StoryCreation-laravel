import { router, useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { CharacterForm } from '@/components/wizard/character-form';
import { Text } from '@/components/ui/text';
import { useDeleteCharacter, useUpdateCharacter } from '@/lib/api/mutations';
import { useCharacters } from '@/lib/api/queries';
import { colors } from '@/theme';

export default function EditCharacterScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { data: characters, isPending } = useCharacters();
  const updateCharacter = useUpdateCharacter();
  const deleteCharacter = useDeleteCharacter();

  const character = characters?.find((item) => item.id === Number(id));

  if (isPending) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.gold} />
      </View>
    );
  }

  if (character === undefined) {
    return (
      <View style={styles.center}>
        <Text variant="caption">This character could not be found.</Text>
      </View>
    );
  }

  return (
    <CharacterForm
      title={`Edit ${character.name}`}
      initial={character}
      submitLabel="Save changes"
      onSubmit={async (input) => {
        await updateCharacter.mutateAsync({ id: character.id, input });
        router.back();
      }}
      onDelete={async () => {
        await deleteCharacter.mutateAsync(character.id);
        router.back();
      }}
    />
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
