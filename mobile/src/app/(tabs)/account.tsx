import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Alert, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Text } from '@/components/ui/text';
import { fieldError, isApiError } from '@/lib/api/client';
import { deleteAccount, forgotPassword } from '@/lib/api/endpoints';
import { useAuth } from '@/lib/auth/context';
import { successFeedback } from '@/lib/haptics';
import { restorePurchases } from '@/lib/purchases/revenuecat';
import { colors, fonts, spacing } from '@/theme';

export default function AccountScreen() {
  const insets = useSafeAreaInsets();
  const { user, logout, forgetSession } = useAuth();

  const [restoring, setRestoring] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const [deletePassword, setDeletePassword] = useState('');
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);

  const restore = async () => {
    setRestoring(true);

    try {
      await restorePurchases();
      Alert.alert('Restore purchases', 'Done. Open the book you purchased; it will pick the purchase up.');
    } catch {
      Alert.alert('Restore purchases', 'Nothing to restore right now.');
    } finally {
      setRestoring(false);
    }
  };

  const sendPasswordReset = async () => {
    if (user === null) {
      return;
    }

    await forgotPassword(user.email).catch(() => {});
    Alert.alert('Change password', `A reset link is on its way to ${user.email}.`);
  };

  const removeAccount = async () => {
    setDeleting(true);
    setDeleteError(null);

    try {
      await deleteAccount(deletePassword);
      successFeedback();
      forgetSession();
    } catch (cause) {
      setDeleteError(
        fieldError(cause, 'password') ??
          (isApiError(cause) ? cause.message : 'Could not delete the account. Try again.'),
      );
      setDeleting(false);
    }
  };

  return (
    <ScrollView
      style={styles.screen}
      contentContainerStyle={[styles.content, { paddingTop: insets.top + spacing.lg }]}
    >
      <View style={styles.header}>
        <Text variant="title">Account</Text>
      </View>

      <Card style={styles.profile}>
        <View style={styles.avatar}>
          <Text variant="display" size="2xl">
            {user?.name.charAt(0).toUpperCase()}
          </Text>
        </View>
        <View style={styles.profileText}>
          <Text variant="label" size="lg">
            {user?.name}
          </Text>
          <Text variant="caption">{user?.email}</Text>
        </View>
      </Card>

      <Card padded={false}>
        <Row
          icon="refresh"
          label="Restore purchases"
          onPress={() => void restore()}
          busy={restoring}
        />
        <Divider />
        <Row icon="key" label="Change password (email link)" onPress={() => void sendPasswordReset()} />
        <Divider />
        <Row icon="log-out" label="Sign out" onPress={() => void logout()} />
      </Card>

      <Card style={styles.dangerCard}>
        <Text variant="label" color={colors.destructive}>
          Delete account
        </Text>
        <Text variant="caption">
          Permanently removes your account, books, and characters. This cannot
          be undone.
        </Text>
        {confirmingDelete ? (
          <>
            <Input
              label="Confirm with your password"
              value={deletePassword}
              onChangeText={setDeletePassword}
              secureTextEntry
              placeholder="Your password"
              error={deleteError}
            />
            <Button
              title="Delete my account forever"
              variant="destructive"
              loading={deleting}
              disabled={deletePassword === ''}
              onPress={() => void removeAccount()}
            />
            <Button title="Keep my account" variant="ghost" onPress={() => setConfirmingDelete(false)} />
          </>
        ) : (
          <Button title="Delete account" variant="outline" onPress={() => setConfirmingDelete(true)} />
        )}
      </Card>

      <Text variant="caption" size="xs" center style={styles.version}>
        CubFable for {`${user?.emailVerified ? 'verified ' : ''}`}storytellers · v1.0.0
      </Text>
    </ScrollView>
  );
}

function Row({
  icon,
  label,
  onPress,
  busy = false,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
  busy?: boolean;
}) {
  return (
    <View style={styles.row}>
      <Ionicons name={icon} size={20} color={colors.gold} />
      <Text
        style={[styles.rowLabel, busy && { opacity: 0.5 }]}
        onPress={busy ? undefined : onPress}
        accessibilityRole="button"
      >
        {label}
      </Text>
      <Ionicons name="chevron-forward" size={16} color={colors.faint} />
    </View>
  );
}

function Divider() {
  return <View style={styles.divider} />;
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  content: {
    paddingHorizontal: spacing.xl,
    paddingBottom: spacing['4xl'],
    gap: spacing.lg,
  },
  header: {
    marginBottom: spacing.xs,
  },
  profile: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.lg,
  },
  avatar: {
    width: 60,
    height: 60,
    borderRadius: 30,
    backgroundColor: colors.primaryAlpha15,
    alignItems: 'center',
    justifyContent: 'center',
  },
  profileText: {
    gap: spacing.xs,
    flex: 1,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.lg,
  },
  rowLabel: {
    flex: 1,
    fontFamily: fonts.sansBold,
  },
  divider: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: colors.border,
    marginLeft: spacing.lg + 20 + spacing.md,
  },
  dangerCard: {
    gap: spacing.md,
    borderColor: 'rgba(230, 86, 96, 0.35)',
  },
  version: {
    marginTop: spacing.md,
  },
});
