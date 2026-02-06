#!/bin/bash
# Apex Python Service Setup Script
# Provisions the Python virtual environment, installs dependencies,
# and optionally installs the systemd service (requires sudo).
#
# Usage:
#   bash python/setup.sh           # Setup venv + deps only
#   sudo bash python/setup.sh      # Setup venv + deps + systemd service

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "==> Setting up Apex Python service..."

# Create virtual environment if needed
if [ ! -d "venv" ]; then
    echo "==> Creating Python virtual environment..."
    python3 -m venv venv
fi

# Install/update dependencies
echo "==> Installing Python dependencies..."
source venv/bin/activate
pip install -q --upgrade pip
pip install -q -r requirements.txt
deactivate

echo "==> Python dependencies installed."

# Install systemd service if running with appropriate permissions
if [ -f "apex-python.service" ]; then
    if [ "$(id -u)" -eq 0 ] || sudo -n true 2>/dev/null; then
        echo "==> Installing systemd service..."
        sudo cp apex-python.service /etc/systemd/system/
        sudo systemctl daemon-reload
        sudo systemctl enable apex-python
        sudo systemctl restart apex-python
        echo "==> Python service installed and started."
        sudo systemctl status apex-python --no-pager -l
    else
        echo ""
        echo "==> Systemd service not installed (no sudo access)."
        echo "    To install manually, run:"
        echo "    sudo cp $SCRIPT_DIR/apex-python.service /etc/systemd/system/"
        echo "    sudo systemctl daemon-reload"
        echo "    sudo systemctl enable apex-python"
        echo "    sudo systemctl start apex-python"
        echo ""
        echo "    Or start Python directly:"
        echo "    cd $SCRIPT_DIR && source venv/bin/activate && uvicorn api.main:app --host 127.0.0.1 --port 8000"
    fi
fi

echo "==> Setup complete."
