/**
 * CubFable "Enchanted Twilight" palette, ported from the web design system
 * (resources/css/app.css). The mobile app is night-first: the cinematic
 * night mood is the brand's most magical face and suits a bedtime product.
 * Hex values are resolved from the web's HSL tokens.
 */
export const colors = {
  // Night sky surfaces (the reader gradient's exact stops)
  bg: '#14112F',
  bgDeep: '#0C0A22',
  bgTop: '#241D5A',

  // Panels and outlines
  card: '#1C1839',
  cardRaised: '#221D45',
  border: '#333055',
  borderSoft: '#2A2650',

  // Text
  foreground: '#EDEBFA',
  mutedForeground: '#B3AECB',
  faint: '#8B86A8',

  // Brand
  primary: '#8473E8', // periwinkle (night primary)
  primaryDeep: '#5F4CD6', // twilight indigo (day primary)
  gold: '#F5BE45', // lamplight gold (night)
  goldDay: '#F2B23E',
  goldDeep: '#E08E1F',
  goldForeground: '#0F0D26',
  rose: '#F38577',
  moonlit: '#99BEEA',

  // States
  destructive: '#E65660',
  success: '#4ADE80',

  // Book paper (reader page surfaces)
  paper: '#FBF3E3',
  paperInk: '#3A2A1A',
  paperGilt: '#A08A63',
  paperRule: '#E0CFA8',
  artMat: '#1A1440',

  // Cover frame (gold-inset indigo, from the web reader)
  frameStart: '#2A2170',
  frameEnd: '#171338',
  frameRing: 'rgba(242, 178, 62, 0.12)',

  // Starfield
  starWhite: 'hsl(220, 70%, 92%)',
  starGold: 'hsl(42, 95%, 68%)',

  // Translucent fills
  whiteAlpha05: 'rgba(255, 255, 255, 0.05)',
  whiteAlpha10: 'rgba(255, 255, 255, 0.10)',
  whiteAlpha25: 'rgba(255, 255, 255, 0.25)',
  goldAlpha15: 'rgba(245, 190, 69, 0.15)',
  primaryAlpha15: 'rgba(132, 115, 232, 0.15)',
  blackAlpha40: 'rgba(0, 0, 0, 0.4)',
} as const;

/** The night-sky backdrop, top to bottom (approximates the web radial). */
export const nightGradient = [colors.bgTop, colors.bg, colors.bgDeep] as const;

/** Gold CTA gradient (the web .text-lamplight ramp, used as a fill). */
export const goldGradient = ['#F6CD66', colors.goldDay, colors.goldDeep] as const;

/** Cover / featured-art frame gradient. */
export const frameGradient = [colors.frameStart, colors.frameEnd] as const;
