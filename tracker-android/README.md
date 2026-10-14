# YeffoHealth for Android and iPhone

A [Capacitor](https://capacitorjs.com) app (Android in `android/`, iPhone
in `ios/`; the folder name predates the iPhone app) that opens the live YeffoHealth
tracker (`https://yeffodesign.com/tracker/`) full screen, with native reminders
scheduled on the phone. How it fits the web tracker is in
[`docs/dose-tracker.md`](../docs/dose-tracker.md#android-app).

- App id: `com.yeffohealth.app` (permanent once published to Google Play)
- Name on the phone: **YeffoHealth**
- Settings: `capacitor.config.json` (the site address, splash colors,
  notification icon). Run `npm run sync` after changing it.
- Icons and splash come from `assets/` (the same striped vial as the web
  app's icons) via `npx capacitor-assets generate --android --iconBackgroundColor '#FAF9F6' --iconBackgroundColorDark '#FAF9F6' --splashBackgroundColor '#FAF9F6' --splashBackgroundColorDark '#141414'`.

The app has no copy of the tracker inside it. The web tracker is the app,
so tracker changes go live in the Android app as soon as they deploy,
without a new Play Store release. A new release is only needed when
something in this folder changes.

## Building

Needs Node 20+, JDK 21 and the Android SDK (Android Studio installs it).

```sh
cd tracker-android
npm install
npm run build:debug        # android/app/build/outputs/apk/debug/app-debug.apk
```

Install that APK on a phone to try it: copy it over and open it (allow
"Install unknown apps" when Android asks), or `adb install app-debug.apk`.

### Release build for Google Play

1. Make an upload key once and keep it somewhere safe outside the repo
   (losing it means asking Google to reset it):

   ```sh
   keytool -genkeypair -v -keystore ~/yeffohealth-upload.jks -alias upload \
     -keyalg RSA -keysize 2048 -validity 10000
   ```

2. Create `tracker-android/android/keystore.properties` (git-ignored):

   ```properties
   storeFile=/full/path/to/yeffohealth-upload.jks
   storePassword=...
   keyAlias=upload
   keyPassword=...
   ```

3. Raise `versionCode` (and `versionName`) in `android/app/build.gradle`
   for every upload, then `npm run build:release`. Upload
   `android/app/build/outputs/bundle/release/app-release.aab` in Play Console.

## Publishing checklist (Play Console)

- Google Play developer account ($25 once). A new *personal* account must
  run a closed test with at least 12 testers for 14 days before it can
  publish to everyone; an *organization* account (needs a D-U-N-S number)
  skips that.
- Store listing: short and full description, 512×512 icon
  (`../yeffoprint-core/assets/tracker/icons/icon-512.png`), a 1024×500
  feature graphic, and at least two phone screenshots.
- Privacy policy URL: https://yeffodesign.com/privacy-policy/. Then the
  Data safety form (account info, health info the customer enters,
  encrypted, deletable), the Health apps declaration, and content rating.
- Account deletion: Me › Privacy in the app links to "Delete my account"
  (My Account › Account details, `class-account-deletion.php`); give Play
  https://yeffodesign.com/my-account/edit-account/ as the web link.
- Reviewer access: Play needs a test login (email and password) to get
  past the sign-in page.
- Health Connect (app 1.1+): fill in the Health apps declaration for the
  four read permissions (weight, body fat, nutrition, hydration) and say
  they're used to show the customer's own progress and food totals.
- Camera (app 1.2+): only for scanning food barcodes in the tracker. The
  picture stays on the phone; only the barcode number is looked up.
- Policy risk: Google restricts apps that promote or facilitate
  unapproved substances. Describe the app as a medication and supplement
  tracker, and keep peptide shopping out of the listing and screenshots.

## iPhone (ios/)

Same app, same id (`com.yeffohealth.app`), iPhone only (no iPad layout),
portrait. It has reminders (local notifications, at most the 64 soonest,
iPhone's limit), the camera for barcode scanning and photos, and Me ›
Privacy › Delete my account. Health Connect and the widget are Android
only for now (tracker.js hides them when the native plugin isn't there).

Building needs a Mac with Xcode 26, or no Mac at all with the GitHub
workflow `.github/workflows/ios-testflight.yml`: it archives on a GitHub
Mac runner and uploads to App Store Connect (TestFlight). Run it from
Actions › "iPhone app to TestFlight" › Run workflow. It needs four repo
secrets, `APPLE_TEAM_ID`, `ASC_KEY_ID`, `ASC_ISSUER_ID` and `ASC_KEY_P8`
(an App Store Connect API key with the Admin role, so Xcode can create
the signing certificate itself). The build number is the run number; the
version shown in the App Store is `MARKETING_VERSION` in
`ios/App/App.xcodeproj/project.pbxproj`.

On a Mac instead: `npm install && npx cap sync ios`, open
`ios/App/App.xcodeproj`, pick your team under Signing & Capabilities, then
Product › Archive › Distribute App › App Store Connect.

Icons and splash: the same `npx capacitor-assets generate` command as
above with `--ios` instead of `--android`. The App Store icon must have no
transparency (the generated one doesn't).

App Review notes: give a test login, say reminders are scheduled on the
phone, and that accounts can be deleted at Me › Privacy. Sign in with
Apple is on the sign-in page (needed because Discord sign-in is offered).
