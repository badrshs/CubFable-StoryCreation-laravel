import { downscaleImage } from '@/lib/downscale-image';

export type CropArea = {
    x: number;
    y: number;
    width: number;
    height: number;
};

/**
 * Cut the selected region out of the source image at native resolution and
 * return it as a JPEG data URL, honoring the photo-quality setting
 * ('optimized' downscales the crop to 768px like plain uploads).
 */
export async function cropImageToDataUrl(
    src: string,
    area: CropArea,
    quality: string,
): Promise<string> {
    const image = await loadImage(src);

    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(area.width));
    canvas.height = Math.max(1, Math.round(area.height));

    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return src;
    }

    ctx.drawImage(
        image,
        area.x,
        area.y,
        area.width,
        area.height,
        0,
        0,
        canvas.width,
        canvas.height,
    );

    const cropped = canvas.toDataURL('image/jpeg', 0.92);

    if (quality === 'original') {
        return cropped;
    }

    const file = await dataUrlToFile(cropped);

    return downscaleImage(file);
}

function loadImage(src: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();

        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('Failed to load image'));
        image.src = src;
    });
}

async function dataUrlToFile(dataUrl: string): Promise<File> {
    const blob = await (await fetch(dataUrl)).blob();

    return new File([blob], 'crop.jpg', { type: 'image/jpeg' });
}
