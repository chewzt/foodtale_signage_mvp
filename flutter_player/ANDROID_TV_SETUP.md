# Android TV box setup

1. Create a Flutter project:
   ```bash
   flutter create .
   ```
   Then keep the `lib/` and `pubspec.yaml` from this MVP.

2. In `android/app/src/main/AndroidManifest.xml` add internet permission:
   ```xml
   <uses-permission android:name="android.permission.INTERNET" />
   ```

3. For an Android TV launcher icon, add the LEANBACK category to the main activity:
   ```xml
   <category android:name="android.intent.category.LEANBACK_LAUNCHER" />
   ```

4. Run:
   ```bash
   flutter pub get
   flutter run --dart-define=API_BASE_URL=http://YOUR_SERVER_IP:8000
   ```

5. For production, use HTTPS and configure the box to auto-launch the app after boot.
