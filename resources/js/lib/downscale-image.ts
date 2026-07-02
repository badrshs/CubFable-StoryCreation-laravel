// Downscale an uploaded photo to a small reference image (keeps likeness, cuts
// request size and image-model input-token cost).
export function downscaleImage(file: File, maxDim = 768): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onerror = () => reject(new Error('Failed to read file'));

        reader.onloadend = () => {
            const dataUrl = reader.result as string;
            const img = new Image();

            img.onload = () => {
                const scale = Math.min(
                    1,
                    maxDim / Math.max(img.width, img.height),
                );
                const w = Math.max(1, Math.round(img.width * scale));
                const h = Math.max(1, Math.round(img.height * scale));
                const canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                const ctx = canvas.getContext('2d');

                if (!ctx) {
                    return resolve(dataUrl);
                }

                ctx.drawImage(img, 0, 0, w, h);
                resolve(canvas.toDataURL('image/jpeg', 0.85));
            };

            img.onerror = () => resolve(dataUrl);
            img.src = dataUrl;
        };

        reader.readAsDataURL(file);
    });
}
