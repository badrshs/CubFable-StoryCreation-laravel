import { Redirect } from 'expo-router';

import { useAuth } from '@/lib/auth/context';

export default function Index() {
  const { status } = useAuth();

  if (status === 'authed') {
    return <Redirect href="/(tabs)/home" />;
  }

  return <Redirect href="/(auth)/welcome" />;
}
