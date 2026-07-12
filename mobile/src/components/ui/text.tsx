import { Text as RNText, type TextProps as RNTextProps, type TextStyle } from 'react-native';

import { colors, fonts, typeScale, type TypeSize } from '@/theme';

type Variant = 'body' | 'label' | 'title' | 'display' | 'caption';

type TextProps = RNTextProps & {
  variant?: Variant;
  size?: TypeSize;
  color?: string;
  bold?: boolean;
  center?: boolean;
};

const variantStyles: Record<Variant, TextStyle> = {
  body: { fontFamily: fonts.sans, color: colors.foreground },
  label: { fontFamily: fonts.sansBold, color: colors.foreground },
  // Screen titles speak in the storybook serif, like web headings.
  title: { fontFamily: fonts.serifSemiBold, color: colors.foreground, letterSpacing: 0.2 },
  display: { fontFamily: fonts.display, color: colors.foreground },
  caption: { fontFamily: fonts.sansSemiBold, color: colors.mutedForeground },
};

const variantDefaultSize: Record<Variant, TypeSize> = {
  body: 'base',
  label: 'base',
  title: '2xl',
  display: 'xl',
  caption: 'sm',
};

export function Text({
  variant = 'body',
  size,
  color,
  bold = false,
  center = false,
  style,
  ...props
}: TextProps) {
  const resolvedSize = typeScale[size ?? variantDefaultSize[variant]];

  return (
    <RNText
      {...props}
      style={[
        variantStyles[variant],
        resolvedSize,
        bold && variant === 'body' ? { fontFamily: fonts.sansBold } : null,
        color ? { color } : null,
        center ? { textAlign: 'center' } : null,
        style,
      ]}
    />
  );
}
