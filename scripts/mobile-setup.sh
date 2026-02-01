#!/bin/bash
# Mobile App Build Setup Script
# Run this script to set up mobile app building on this server

set -e

echo "==================================="
echo "NativePHP Mobile Build Setup"
echo "==================================="
echo ""

# Check for Java
if ! command -v java &> /dev/null; then
    echo "[!] Java not found. Installing OpenJDK 17..."
    sudo apt-get update
    sudo apt-get install -y openjdk-17-jdk
    echo "    Java installed successfully"
else
    echo "[✓] Java found: $(java -version 2>&1 | head -1)"
fi

# Check for Android SDK / command line tools
if [ -z "$ANDROID_HOME" ]; then
    echo ""
    echo "[!] Android SDK not found."
    echo "    To build Android apps locally, you need the Android SDK."
    echo ""
    echo "    Option 1: Install via command line tools:"
    echo "    wget https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip"
    echo "    unzip commandlinetools-linux-*.zip -d ~/android-sdk"
    echo "    export ANDROID_HOME=~/android-sdk"
    echo "    export PATH=\$PATH:\$ANDROID_HOME/cmdline-tools/latest/bin"
    echo ""
    echo "    Option 2: Use GitHub Actions (recommended for CI/CD)"
    echo "    Push to GitHub and trigger the workflow manually."
fi

# Create secrets directory
SECRETS_DIR="$(dirname "$0")/../.secrets"
mkdir -p "$SECRETS_DIR"

# Generate Android keystore if it doesn't exist
KEYSTORE_PATH="$SECRETS_DIR/android-release.jks"
if [ ! -f "$KEYSTORE_PATH" ]; then
    echo ""
    echo "[?] Generate Android signing keystore? (y/n)"
    read -r GENERATE_KEYSTORE

    if [ "$GENERATE_KEYSTORE" = "y" ]; then
        echo ""
        echo "Enter keystore details:"
        read -p "Key alias (e.g., apex-key): " KEY_ALIAS
        read -sp "Keystore password (min 6 chars): " KEYSTORE_PASS
        echo ""
        read -sp "Key password (min 6 chars): " KEY_PASS
        echo ""
        read -p "Your name (CN): " CN_NAME
        read -p "Organization (O): " ORG_NAME
        read -p "Country code (C, e.g., US): " COUNTRY

        keytool -genkey -v \
            -keystore "$KEYSTORE_PATH" \
            -keyalg RSA \
            -keysize 2048 \
            -validity 10000 \
            -alias "$KEY_ALIAS" \
            -storepass "$KEYSTORE_PASS" \
            -keypass "$KEY_PASS" \
            -dname "CN=$CN_NAME, O=$ORG_NAME, C=$COUNTRY"

        # Save credentials (gitignored)
        cat > "$SECRETS_DIR/android-credentials.env" << EOF
ANDROID_KEYSTORE_FILE=$KEYSTORE_PATH
ANDROID_KEYSTORE_PASSWORD=$KEYSTORE_PASS
ANDROID_KEY_ALIAS=$KEY_ALIAS
ANDROID_KEY_PASSWORD=$KEY_PASS
EOF

        echo ""
        echo "[✓] Keystore created at: $KEYSTORE_PATH"
        echo "[✓] Credentials saved to: $SECRETS_DIR/android-credentials.env"
        echo ""
        echo "    To build Android release:"
        echo "    source .secrets/android-credentials.env"
        echo "    php artisan native:package android"
    fi
else
    echo "[✓] Android keystore exists at: $KEYSTORE_PATH"
fi

echo ""
echo "==================================="
echo "Setup Complete!"
echo "==================================="
echo ""
echo "TESTING OPTIONS:"
echo ""
echo "1. Test in Browser (fastest):"
echo "   php artisan serve"
echo "   Open http://localhost:8000"
echo ""
echo "2. Test on Physical Device (via native:jump):"
echo "   php artisan native:jump"
echo "   Scan QR code with NativePHP app on your phone"
echo ""
echo "3. Build Android APK (requires Java + Android SDK):"
echo "   source .secrets/android-credentials.env"
echo "   php artisan native:package android"
echo ""
echo "4. Build via GitHub Actions (recommended):"
echo "   Push code to GitHub"
echo "   Go to Actions tab > Build Mobile Apps > Run workflow"
echo "   Download artifacts when complete"
echo ""
echo "iOS builds REQUIRE macOS. Use GitHub Actions workflow."
