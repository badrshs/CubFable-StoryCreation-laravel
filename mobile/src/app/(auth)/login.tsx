import { Link } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BrandMark } from '@/components/cubfable/brand-mark';
import { NightSky } from '@/components/cubfable/night-sky';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Text } from '@/components/ui/text';
import { fieldError, isApiError } from '@/lib/api/client';
import { useAuth } from '@/lib/auth/context';
import { colors, spacing } from '@/theme';

export default function LoginScreen() {
  const { login } = useAuth();
  const insets = useSafeAreaInsets();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);

    try {
      await login(email.trim(), password);
      // The (auth) layout redirects to the tabs once authed.
    } catch (cause) {
      setError(
        fieldError(cause, 'email') ??
          (isApiError(cause) ? cause.message : 'Could not sign in. Please try again.'),
      );
      setSubmitting(false);
    }
  };

  return (
    <NightSky stars={28}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          contentContainerStyle={[
            styles.screen,
            { paddingTop: insets.top + spacing['4xl'], paddingBottom: insets.bottom + spacing['2xl'] },
          ]}
          keyboardShouldPersistTaps="handled"
        >
          <View style={styles.header}>
            <BrandMark size={32} />
            <Text variant="title" center>
              Welcome back
            </Text>
            <Text variant="caption" center>
              The library kept your stories safe.
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
            />
            <Input
              label="Password"
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              autoComplete="current-password"
              placeholder="Your password"
              error={error}
              onSubmitEditing={() => void submit()}
            />
            <Button
              title="Sign in"
              variant="gold"
              loading={submitting}
              disabled={email.trim() === '' || password === ''}
              onPress={() => void submit()}
            />
            <Link href="/(auth)/forgot-password" asChild>
              <Text center color={colors.moonlit} style={styles.link}>
                Forgot your password?
              </Text>
            </Link>
          </View>

          <View style={styles.footer}>
            <Text variant="caption" center>
              New to CubFable?{' '}
              <Link href="/(auth)/register">
                <Text variant="caption" color={colors.gold}>
                  Create an account
                </Text>
              </Link>
            </Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </NightSky>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  screen: {
    flexGrow: 1,
    paddingHorizontal: spacing['2xl'],
    gap: spacing['3xl'],
  },
  header: {
    alignItems: 'center',
    gap: spacing.sm,
  },
  form: {
    gap: spacing.lg,
  },
  link: {
    paddingVertical: spacing.sm,
  },
  footer: {
    marginTop: 'auto',
  },
});
