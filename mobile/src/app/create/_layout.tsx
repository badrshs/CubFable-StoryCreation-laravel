import { Stack } from 'expo-router';

import { WizardProvider } from '@/lib/wizard-context';
import { colors } from '@/theme';

export default function CreateLayout() {
  return (
    <WizardProvider>
      <Stack
        screenOptions={{
          headerShown: false,
          contentStyle: { backgroundColor: colors.bg },
          animation: 'slide_from_right',
        }}
      />
    </WizardProvider>
  );
}
