/**
 * Generates every app icon and splash asset from one SVG brand mark:
 * a lamplight-gold crescent moon with a sparkle, on ink night.
 *
 * Usage: node scripts/generate-brand-assets.cjs
 */
const sharp = require('sharp');
const path = require('path');

const INK = '#14112F';
const GOLD = '#F2B23E';
const GOLD_LIGHT = '#F6CD66';
const STAR = '#D9E4F7';

/**
 * The mark: crescent open to the top-right, a four-point sparkle in the
 * hollow, two faint stars. `background` false leaves it transparent.
 */
function markSvg({ background, scale = 1, monochrome = false }) {
  const gold = monochrome ? '#FFFFFF' : GOLD;
  const goldLight = monochrome ? '#FFFFFF' : GOLD_LIGHT;
  const star = monochrome ? '#FFFFFF' : STAR;
  const hole = background ? INK : 'transparent';

  return `<svg width="1024" height="1024" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg">
  ${background ? `<rect width="1024" height="1024" fill="${INK}"/>` : ''}
  <g transform="translate(512 512) scale(${scale}) translate(-512 -512)">
    <path fill="${gold}" d="M 512 202
      A 310 310 0 1 0 512 822
      A 310 310 0 0 0 745 715
      A 262 262 0 0 1 512 322
      A 262 262 0 0 1 745 309
      A 310 310 0 0 0 512 202 Z"/>
    <path fill="${goldLight}" d="M 690 380
      Q 706 452 778 468
      Q 706 484 690 556
      Q 674 484 602 468
      Q 674 452 690 380 Z"/>
    <circle cx="234" cy="230" r="13" fill="${star}"/>
    <circle cx="800" cy="700" r="10" fill="${star}"/>
  </g>
</svg>`;
}

async function render(svg, size, file) {
  await sharp(Buffer.from(svg)).resize(size, size).png().toFile(path.join(__dirname, '..', 'assets', 'images', file));
  console.log(`${file} (${size}x${size})`);
}

(async () => {
  // Full-bleed app icon (iOS + fallback).
  await render(markSvg({ background: true, scale: 0.78 }), 1024, 'icon.png');

  // Splash mark on transparent; app.json paints the ink background.
  await render(markSvg({ background: false, scale: 1 }), 1024, 'splash-icon.png');

  // Android adaptive icon: foreground content inside the safe zone,
  // solid background layer, and a white monochrome layer for themed icons.
  await render(markSvg({ background: false, scale: 0.62 }), 1024, 'android-icon-foreground.png');
  await sharp({ create: { width: 1024, height: 1024, channels: 4, background: INK } })
    .png()
    .toFile(path.join(__dirname, '..', 'assets', 'images', 'android-icon-background.png'));
  console.log('android-icon-background.png (1024x1024)');
  await render(markSvg({ background: false, scale: 0.62, monochrome: true }), 1024, 'android-icon-monochrome.png');

  // Web favicon for expo web.
  await render(markSvg({ background: true, scale: 0.9 }), 64, 'favicon.png');
})();
