import { Platform } from 'react-native';
import Purchases, { LOG_LEVEL, type PurchasesPackage } from 'react-native-purchases';

let configured = false;

function apiKey(): string | null {
  const key = Platform.select({
    ios: process.env.EXPO_PUBLIC_REVENUECAT_IOS_KEY,
    android: process.env.EXPO_PUBLIC_REVENUECAT_ANDROID_KEY,
  });

  return key && key !== '' ? key : null;
}

/**
 * Configure the RevenueCat SDK once per app launch. Quietly does nothing when
 * no key is configured (e.g. running in Expo Go before the store setup), so
 * the rest of the app keeps working without purchases.
 */
export function configurePurchases(): void {
  const key = apiKey();

  if (configured || key === null) {
    return;
  }

  try {
    if (__DEV__) {
      void Purchases.setLogLevel(LOG_LEVEL.DEBUG);
    }

    Purchases.configure({ apiKey: key });
    configured = true;
  } catch {
    configured = false;
  }
}

export function purchasesAvailable(): boolean {
  return configured;
}

/** Tie the store identity to the backend user id (the webhook mapping key). */
export async function identifyPurchaser(userId: number): Promise<void> {
  if (!configured) {
    return;
  }

  await Purchases.logIn(String(userId)).catch(() => {});
}

export async function resetPurchaser(): Promise<void> {
  if (!configured) {
    return;
  }

  await Purchases.logOut().catch(() => {});
}

/** The package for the one-time book product, from the current offering. */
export async function fetchBookPackage(productId: string): Promise<PurchasesPackage | null> {
  if (!configured) {
    return null;
  }

  const offerings = await Purchases.getOfferings();
  const packages = offerings.current?.availablePackages ?? [];

  return (
    packages.find((pkg) => pkg.product.identifier === productId) ??
    packages[0] ??
    null
  );
}

/**
 * Stamp the target book and order onto the purchaser right before buying, so
 * the webhook can map the store transaction back to the draft book.
 */
export async function tagPurchaseTarget(bookId: number, orderId: number): Promise<void> {
  if (!configured) {
    return;
  }

  await Purchases.setAttributes({
    book_id: String(bookId),
    order_id: String(orderId),
  });
}

export async function purchasePackage(pkg: PurchasesPackage): Promise<void> {
  await Purchases.purchasePackage(pkg);
}

export async function restorePurchases(): Promise<void> {
  if (!configured) {
    return;
  }

  await Purchases.restorePurchases();
}

export function isPurchaseCancelled(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'userCancelled' in error &&
    Boolean((error as { userCancelled?: boolean }).userCancelled)
  );
}
