import { router } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { NightSky } from '@/components/cubfable/night-sky';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Text } from '@/components/ui/text';
import { fieldError, isApiError } from '@/lib/api/client';
import { forgotPassword } from '@/lib/api/endpoints';
import { colors, spacing } from '@/theme';

export default function ForgotPasswordScreen() {
  const insets = useSafeAreaInsets();

  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);

    try {
      await forgotPassword(email.trim());
      setSent(true);
    } catch (cause) {
      setError(
        fieldError(cause, 'email') ??
          (isApiError(cause) ? cause.message : 'Could not send the email. Please try again.'),
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <NightSky stars={28}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <View
          style={[
            styles.screen,
            { paddingTop: insets.top + spacing['4xl'], paddingBottom: insets.bottom + spacing['2xl'] },
          ]}
        >
          {sent ? (
            <View style={styles.header}>
              <Text style={styles.emoji}>📨</Text>
              <Text variant="title" center>
                Check your inbox
              </Text>
              <Text center color={colors.mutedForeground}>
                If an account exists for {email.trim()}, a reset link is on its
                way. Open it, choose a new password, then sign in here.
              </Text>
              <Button title="Back to sign in" variant="outline" onPress={() => router.back()} style={styles.backButton} />
            </View>
          ) : (
            <>
              <View style={styles.header}>
                <Text variant="title" center>
                  Forgot your password?
                </Text>
                <Text variant="caption" center>
                  We will email you a link to choose a new one.
                </Text>
              </View>

              <View style={styles.form}>
                <Input
                  label="Email"
                  value={email}
                  onChangeText={setEmail}
                  autoCapitalize="none"
                  autoComplete="email"
                  keyboardType="email-address"
                  placeholder="you@example.com"
                  error={error}
                  onSubmitEditing={() => void submit()}
                />
                <Button
                  title="Send reset link"
                  variant="gold"
                  loading={submitting}
                  disabled={email.trim() === ''}
                  onPress={() => void submit()}
                />
                <Button title="Back" variant="ghost" onPress={() => router.back()} />
              </View>
            </>
          )}
        </View>
      </KeyboardAvoidingView>
    </NightSky>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  screen: {
    flex: 1,
    paddingHorizontal: spacing['2xl'],
    gap: spacing['3xl'],
  },
  header: {
    alignItems: 'center',
    gap: spacing.md,
  },
  emoji: {
    fontSize: 44,
  },
  form: {
    gap: spacing.lg,
  },
  backButton: {
    alignSelf: 'stretch',
    marginTop: spacing.lg,
  },
});
