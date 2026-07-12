import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';

export type PickedPhoto = {
  /** data:image/jpeg;base64,... ready for the API. */
  dataUrl: string;
  /** Local uri for previewing with expo-image. */
  previewUri: string;
};

// ~15MB string budget keeps us safely under the API's 16MB data URL cap.
const MAX_DATA_URL_LENGTH = 15 * 1024 * 1024;

/**
 * Pick (or take) a photo, crop it with the native 3:4-ish editor, downscale,
 * and return a base64 data URL matching what the web wizard uploads. The
 * `quality` mirrors the photoUploadQuality setting from GET meta: 'optimized'
 * downscales to 768px (like the web), 'original' keeps more resolution.
 */
export async function pickPhoto(
  source: 'library' | 'camera',
  quality: 'original' | 'optimized',
): Promise<PickedPhoto | null> {
  if (source === 'camera') {
    const permission = await ImagePicker.requestCameraPermissionsAsync();

    if (!permission.granted) {
      return null;
    }
  }

  const pickerOptions: ImagePicker.ImagePickerOptions = {
    mediaTypes: 'images',
    allowsEditing: true,
    aspect: [3, 4],
    quality: 1,
  };

  const result =
    source === 'camera'
      ? await ImagePicker.launchCameraAsync(pickerOptions)
      : await ImagePicker.launchImageLibraryAsync(pickerOptions);

  if (result.canceled || result.assets.length === 0) {
    return null;
  }

  const asset = result.assets[0]!;

  return await encodeForUpload(asset.uri, quality === 'optimized' ? 768 : 1600, 0.85);
}

async function encodeForUpload(
  uri: string,
  maxWidth: number,
  compress: number,
): Promise<PickedPhoto | null> {
  const context = ImageManipulator.manipulate(uri);
  context.resize({ width: maxWidth });

  const rendered = await context.renderAsync();
  const saved = await rendered.saveAsync({
    base64: true,
    compress,
    format: SaveFormat.JPEG,
  });

  if (saved.base64 == null) {
    return null;
  }

  const dataUrl = `data:image/jpeg;base64,${saved.base64}`;

  // A photo that still busts the budget gets one harder pass, then gives up.
  if (dataUrl.length > MAX_DATA_URL_LENGTH) {
    if (maxWidth > 768) {
      return await encodeForUpload(uri, 768, 0.7);
    }

    return null;
  }

  return { dataUrl, previewUri: saved.uri };
}
