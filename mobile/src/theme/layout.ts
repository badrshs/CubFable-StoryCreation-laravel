import { colors } from './colors';

/** 4pt spacing scale. */
export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 20,
  '2xl': 24,
  '3xl': 32,
  '4xl': 48,
} as const;

/** Radii: web --radius is 1.1rem (~18) with pill CTAs. */
export const radii = {
  sm: 10,
  md: 14,
  lg: 18,
  xl: 24,
  pill: 999,
} as const;

export const shadows = {
  lift: {
    shadowColor: '#0A0724',
    shadowOffset: { width: 0, height: 18 },
    shadowOpacity: 0.45,
    shadowRadius: 28,
    elevation: 12,
  },
  goldGlow: {
    shadowColor: colors.gold,
    shadowOffset: { width: 0, height: 0 },
    shadowOpacity: 0.38,
    shadowRadius: 20,
    elevation: 8,
  },
} as const;

/** Every book cover and page illustration is 3:4 portrait. */
export const ART_ASPECT_RATIO = 3 / 4;
