# CubFable Mobile

The iOS/Android companion to the CubFable web app: create a personalized
AI-illustrated storybook, pay with an in-app purchase, watch it generate
live, and read it in a night-sky reader. Built with Expo (SDK 57),
expo-router, TypeScript, TanStack Query, and RevenueCat.

## Running locally

1. Start the Laravel API from the repo root: `composer run dev` (the queue
   worker must be running for book generation).
2. Set the API address in `.env.development`:
   - iOS simulator: leave empty (defaults to `http://127.0.0.1:8000`)
   - Android emulator: leave empty (defaults to `http://10.0.2.2:8000`)
   - Physical device: `EXPO_PUBLIC_API_URL=http://<your-LAN-IP>:8000`
3. `npm install` then `npm start` inside `mobile/`, and open in Expo Go or a
   development build.

Everything except purchases works in Expo Go. The RevenueCat SDK needs a
development build: `npx eas build --profile development`.

## In-app purchases (one-time setup)

1. Create the app in App Store Connect and Play Console (bundle id
   `com.cubfable.app`) and add a **consumable** in-app product with id
   `cubfable_book` in both stores.
2. Create a free RevenueCat project with an iOS and an Android app, attach
   the store product to an Offering, and copy the two public SDK keys into
   `EXPO_PUBLIC_REVENUECAT_IOS_KEY` / `EXPO_PUBLIC_REVENUECAT_ANDROID_KEY`.
3. In RevenueCat, add a webhook pointing at
   `https://<api-host>/api/v1/webhooks/revenuecat` with an Authorization
   header value of your choosing, and put the same value in the Laravel
   `.env` as `REVENUECAT_WEBHOOK_SECRET`. Also set `REVENUECAT_API_KEY`
   (secret key) and, outside production, `REVENUECAT_ALLOW_SANDBOX=true`.
4. Sandbox testing: iOS sandbox tester account / Play license testers.

## How the purchase maps to a book

Before showing the store sheet the app calls `POST /books/{id}/iap/intent`
(creates the pending order), stamps `book_id` + `order_id` as RevenueCat
subscriber attributes, then purchases. The backend webhook activates the
book; the app also calls `POST /books/{id}/iap/reconcile` right after the
purchase (and from Restore purchases) so a slow webhook never blocks the
customer.
