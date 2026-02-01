# Mobile App Builds

This document explains how to build and test the NativePHP mobile app.

## Quick Start - Testing Without Building

### Option 1: Test in Browser (Fastest)

```bash
php artisan serve
# Open http://localhost:8000
```

The app works as a regular web app - test all features here first.

### Option 2: Test on Physical Device (native:jump)

This lets you test on a real phone WITHOUT building an APK/IPA:

1. Install the **NativePHP Preview** app on your phone:
   - Android: [Play Store](https://play.google.com/store/apps/details?id=com.nativephp.preview)
   - iOS: [App Store](https://apps.apple.com/app/nativephp-preview/id6503696606)

2. Run the jump server:
   ```bash
   php artisan native:jump --platform=android
   # or
   php artisan native:jump --platform=ios
   ```

3. Scan the QR code with the NativePHP Preview app

4. Your app runs on your phone with hot reload!

## Building Apps

### Android (on this server)

**First-time setup:**

```bash
# Install Java (required)
sudo apt-get install -y openjdk-17-jdk

# Run setup script
./scripts/mobile-setup.sh
```

**Build debug APK:**

```bash
php artisan native:install --no-interaction
cd nativephp/android
./gradlew assembleDebug
# APK at: nativephp/android/app/build/outputs/apk/debug/app-debug.apk
```

**Build signed release APK:**

```bash
source .secrets/android-credentials.env
php artisan native:package android
```

### iOS (requires macOS)

iOS apps can ONLY be built on macOS with Xcode. Use one of these options:

#### Option A: GitHub Actions (Recommended)

1. Push your code to GitHub
2. Go to **Actions** tab
3. Select **Build Mobile Apps** workflow
4. Click **Run workflow**
5. Choose `ios` or `both`
6. Download the artifact when complete

#### Option B: NativePHP Bifrost

Cloud build service at [bifrost.nativephp.com](https://bifrost.nativephp.com)

#### Option C: Rent a Mac

- [MacStadium](https://www.macstadium.com/) - Cloud Mac rental
- [AWS EC2 Mac](https://aws.amazon.com/ec2/instance-types/mac/) - Mac instances on AWS

## GitHub Actions Setup

The workflow is at `.github/workflows/build-mobile.yml`.

### Required Secrets

Go to GitHub repo → Settings → Secrets and variables → Actions

**For Android:**
- `ANDROID_KEYSTORE_BASE64` - Base64-encoded keystore file
- `ANDROID_KEYSTORE_PASSWORD` - Keystore password
- `ANDROID_KEY_ALIAS` - Key alias
- `ANDROID_KEY_PASSWORD` - Key password

**For iOS:**
- `NATIVEPHP_APP_ID` - Your app bundle ID (e.g., `com.apexnegotiations.app`)

**To encode keystore for GitHub:**
```bash
base64 -w 0 .secrets/android-release.jks > keystore-base64.txt
# Copy contents of keystore-base64.txt to ANDROID_KEYSTORE_BASE64 secret
```

### Manual Trigger

1. Go to **Actions** tab in GitHub
2. Select **Build Mobile Apps**
3. Click **Run workflow**
4. Choose platform (ios/android/both) and build type (debug/release)
5. Wait for build to complete
6. Download artifacts from the workflow run

## App Configuration

Edit `config/nativephp.php`:

```php
return [
    'version' => env('NATIVEPHP_APP_VERSION', '1.0.0'),
    'version_code' => env('NATIVEPHP_APP_VERSION_CODE', 1),
    'app_id' => env('NATIVEPHP_APP_ID', 'com.apexnegotiations.app'),
    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME', 'apex'),
    'start_url' => env('NATIVEPHP_START_URL', '/'),
];
```

## Environment Variables

Add to `.env`:

```env
NATIVEPHP_APP_VERSION=1.0.0
NATIVEPHP_APP_VERSION_CODE=1
NATIVEPHP_APP_ID=com.apexnegotiations.app
NATIVEPHP_DEEPLINK_SCHEME=apex
```

## Troubleshooting

### "Failed to create ZIP file"

The build timed out. We've patched this locally, but if you reinstall NativePHP, run:
```bash
# Add timeout to ZIP creation in vendor file
sed -i 's/Process::run(\$command)/Process::timeout(600)->run(\$command)/' \
  vendor/nativephp/mobile/src/Commands/BuildIosAppCommand.php
```

### Permission denied errors

```bash
sudo chown -R forge:forge /home/forge/apexnegotiations.com/vendor/
sudo chown -R forge:forge /home/forge/apexnegotiations.com/nativephp/
sudo chown forge:forge /home/forge/apexnegotiations.com/native
```

### iOS build fails on Linux

iOS builds require macOS. Use GitHub Actions or Bifrost.

### Android build needs Java

```bash
sudo apt-get install -y openjdk-17-jdk
```
