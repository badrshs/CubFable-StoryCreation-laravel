import { Redirect, Stack } from 'expo-router';

import { useAuth } from '@/lib/auth/context';
import { colors } from '@/theme';

export const unstable_settings = {
  initialRouteName: 'welcome',
};

export default function AuthLayout() {
  const { status } = useAuth();

  if (status === 'authed') {
    return <Redirect href="/(tabs)/home" />;
  }

  return (
    <Stack
      screenOptions={{
        headerShown: false,
        contentStyle: { backgroundColor: colors.bg },
        animation: 'slide_from_right',
      }}
    />
  );
}
