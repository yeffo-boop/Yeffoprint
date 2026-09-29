# Dose Tracker for Android

A [Capacitor](https://capacitorjs.com) app that opens the live Dose Tracker
(`https://yeffodesign.com/tracker/`) full screen, with native reminders
scheduled on the phone. How it fits the web tracker is in
[`docs/dose-tracker.md`](../docs/dose-tracker.md#android-app).

- App id: `com.yeffodesign.dosetracker` (permanent once published to Google Play)
- Name on the phone: **Dose Tracker**
- Settings: `capacitor.config.json` (the site address, splash colors,
  notification icon). Run `npm run sync` after changing it.
- Icons and splash come from `assets/` via `npx capacitor-assets generate --android`.

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
   keytool -genkeypair -v -keystore ~/yeffodesign-upload.jks -alias upload \
     -keyalg RSA -keysize 2048 -validity 10000
   ```

2. Create `tracker-android/android/keystore.properties` (git-ignored):

   ```properties
   storeFile=/full/path/to/yeffodesign-upload.jks
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
- Privacy policy URL, the Data safety form (account info, health info the
  customer enters, encrypted, deletable), the Health apps declaration, and
  content rating.
- Account deletion: Play requires a way to delete the account (not just
  tracker data) from inside the app and from a web page.
- Policy risk: Google restricts apps that promote or facilitate
  unapproved substances. Describe the app as a medication and supplement
  tracker, and keep peptide shopping out of the listing and screenshots.
