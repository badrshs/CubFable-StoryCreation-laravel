import * as FileSystem from 'expo-file-system/legacy';
import * as Sharing from 'expo-sharing';

import { apiBaseUrl, getApiToken } from '@/lib/api/client';

/**
 * Download a book PDF with the bearer token attached and hand it to the
 * system share sheet (save to Files, AirDrop, print, email...). Uses the
 * legacy FileSystem API because the download needs an Authorization header.
 */
export async function downloadAndShareBookPdf(
  bookId: number,
  childName: string,
  variant: 'home' | 'print',
): Promise<void> {
  const token = getApiToken();

  if (token === null) {
    throw new Error('Not signed in.');
  }

  const slug = childName
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

  const fileUri = `${FileSystem.cacheDirectory}${slug === '' ? 'storybook' : slug}-cubfable-storybook-${variant}.pdf`;
  const url = `${apiBaseUrl()}/api/v1/books/${bookId}/download?variant=${variant}`;

  const result = await FileSystem.downloadAsync(url, fileUri, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/pdf',
    },
  });

  if (result.status !== 200) {
    throw new Error('The PDF could not be prepared. Please try again.');
  }

  if (await Sharing.isAvailableAsync()) {
    await Sharing.shareAsync(result.uri, {
      mimeType: 'application/pdf',
      dialogTitle: 'Save or share the storybook',
      UTI: 'com.adobe.pdf',
    });
  }
}
