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

export default function RegisterScreen() {
  const { register } = useAuth();
  const insets = useSafeAreaInsets();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<{ name?: string; email?: string; password?: string; general?: string }>({});

  const submit = async () => {
    setSubmitting(true);
    setErrors({});

    try {
      await register(name.trim(), email.trim(), password);
    } catch (cause) {
      setErrors({
        name: fieldError(cause, 'name') ?? undefined,
        email: fieldError(cause, 'email') ?? undefined,
        password: fieldError(cause, 'password') ?? undefined,
        general:
          fieldError(cause, 'name') === null &&
          fieldError(cause, 'email') === null &&
          fieldError(cause, 'password') === null &&
          isApiError(cause)
            ? cause.message
            : undefined,
      });
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
            { paddingTop: insets.top + spacing['3xl'], paddingBottom: insets.bottom + spacing['2xl'] },
          ]}
          keyboardShouldPersistTaps="handled"
        >
          <View style={styles.header}>
            <BrandMark size={32} />
            <Text variant="title" center>
              Begin the adventure
            </Text>
            <Text variant="caption" center>
              One account for every story you make.
            </Text>
          </View>

          <View style={styles.form}>
            <Input
              label="Your name"
              value={name}
              onChangeText={setName}
              autoComplete="name"
              placeholder="How should we greet you?"
              error={errors.name ?? null}
            />
            <Input
              label="Email"
              value={email}
              onChangeText={setEmail}
              autoCapitalize="none"
              autoComplete="email"
              keyboardType="email-address"
              placeholder="you@example.com"
              error={errors.email ?? null}
            />
            <Input
              label="Password"
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              autoComplete="new-password"
              placeholder="At least 8 characters"
              error={errors.password ?? null}
            />
            {errors.general !== undefined && (
              <Text variant="caption" color={colors.destructive} center>
                {errors.general}
              </Text>
            )}
            <Button
              title="Create account"
              variant="gold"
              loading={submitting}
              disabled={name.trim() === '' || email.trim() === '' || password === ''}
              onPress={() => void submit()}
            />
          </View>

          <View style={styles.footer}>
            <Text variant="caption" center>
              Already have an account?{' '}
              <Link href="/(auth)/login">
                <Text variant="caption" color={colors.gold}>
                  Sign in
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
  footer: {
    marginTop: 'auto',
  },
});
