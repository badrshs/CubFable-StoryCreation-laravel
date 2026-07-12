import { LinearGradient } from 'expo-linear-gradient';
import type { ReactNode } from 'react';
import { StyleSheet } from 'react-native';

import { Starfield } from '@/components/cubfable/starfield';
import { nightGradient } from '@/theme';

type NightSkyProps = {
  children: ReactNode;
  stars?: number;
  aurora?: boolean;
};

/** Full-bleed night-sky backdrop: the reader gradient plus the starfield. */
export function NightSky({ children, stars = 36, aurora = true }: NightSkyProps) {
  return (
    <LinearGradient colors={nightGradient} style={StyleSheet.absoluteFill}>
      <Starfield count={stars} aurora={aurora} />
      {children}
    </LinearGradient>
  );
}
