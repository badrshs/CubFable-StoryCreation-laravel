import { useState } from 'react';
import { StyleSheet, TextInput, View, type TextInputProps } from 'react-native';

import { Text } from '@/components/ui/text';
import { colors, fonts, radii, spacing } from '@/theme';

type InputProps = TextInputProps & {
  label: string;
  error?: string | null;
};

export function Input({ label, error, style, ...props }: InputProps) {
  const [focused, setFocused] = useState(false);

  return (
    <View style={styles.field}>
      <Text variant="caption" style={styles.label}>
        {label}
      </Text>
      <TextInput
        placeholderTextColor={colors.faint}
        selectionColor={colors.gold}
        {...props}
        onFocus={(event) => {
          setFocused(true);
          props.onFocus?.(event);
        }}
        onBlur={(event) => {
          setFocused(false);
          props.onBlur?.(event);
        }}
        style={[
          styles.input,
          focused && styles.focused,
          error != null && styles.errored,
          style,
        ]}
      />
      {error != null && (
        <Text variant="caption" color={colors.destructive}>
          {error}
        </Text>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  field: {
    gap: spacing.xs + 2,
  },
  label: {
    marginLeft: spacing.xs,
    textTransform: 'uppercase',
    letterSpacing: 1.2,
    fontSize: 11,
  },
  input: {
    minHeight: 52,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    color: colors.foreground,
    paddingHorizontal: spacing.lg,
    fontFamily: fonts.sansSemiBold,
    fontSize: 16,
  },
  focused: {
    borderColor: colors.gold,
  },
  errored: {
    borderColor: colors.destructive,
  },
});
