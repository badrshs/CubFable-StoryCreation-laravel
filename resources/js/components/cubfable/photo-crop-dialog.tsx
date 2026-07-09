import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import Cropper from 'react-easy-crop';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/i18n';
import { cropImageToDataUrl } from '@/lib/crop-image';
import type { CropArea } from '@/lib/crop-image';
import { originalImage } from '@/lib/downscale-image';

// Let the user frame their character before the photo becomes the AI
// reference: a portrait crop centered on the person beats a wide shot with
// clutter for likeness. Drag to move, scroll/pinch or the slider to zoom.
export function PhotoCropDialog({
    file,
    quality,
    onCropped,
    onCancel,
}: {
    file: File | null;
    quality: string;
    onCropped: (dataUrl: string) => void;
    onCancel: () => void;
}) {
    const t = useT();
    const [loaded, setLoaded] = useState<{ file: File; url: string } | null>(
        null,
    );
    const [crop, setCrop] = useState({ x: 0, y: 0 });
    const [zoom, setZoom] = useState(1);
    const [area, setArea] = useState<CropArea | null>(null);
    const [saving, setSaving] = useState(false);

    // Read the chosen file (an external system) and reset the crop for it;
    // the source only counts once it belongs to the CURRENT file, so a
    // previously cropped photo never flashes.
    useEffect(() => {
        if (!file) {
            return;
        }

        let cancelled = false;

        originalImage(file).then((url) => {
            if (cancelled) {
                return;
            }

            setCrop({ x: 0, y: 0 });
            setZoom(1);
            setArea(null);
            setLoaded({ file, url });
        });

        return () => {
            cancelled = true;
        };
    }, [file]);

    const source = loaded !== null && loaded.file === file ? loaded.url : null;

    const confirm = async () => {
        if (!source || !area) {
            return;
        }

        setSaving(true);

        try {
            onCropped(await cropImageToDataUrl(source, area, quality));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open={file !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onCancel();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('crop.title')}</DialogTitle>
                    <DialogDescription>{t('crop.hint')}</DialogDescription>
                </DialogHeader>

                <div className="relative h-80 w-full overflow-hidden rounded-lg bg-muted">
                    {source ? (
                        <Cropper
                            image={source}
                            crop={crop}
                            zoom={zoom}
                            aspect={3 / 4}
                            onCropChange={setCrop}
                            onZoomChange={setZoom}
                            onCropComplete={(_, areaPixels) =>
                                setArea(areaPixels)
                            }
                        />
                    ) : (
                        <div className="flex h-full items-center justify-center">
                            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                        </div>
                    )}
                </div>

                <label className="flex items-center gap-3 text-sm text-muted-foreground">
                    {t('crop.zoom')}
                    <input
                        type="range"
                        min={1}
                        max={4}
                        step={0.05}
                        value={zoom}
                        onChange={(e) => setZoom(Number(e.target.value))}
                        className="flex-1 accent-gold"
                    />
                </label>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onCancel}>
                        {t('crop.cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={confirm}
                        disabled={!area || saving}
                    >
                        {saving && (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        )}
                        {t('crop.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
