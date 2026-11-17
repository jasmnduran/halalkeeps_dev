#!/bin/bash
# =============================================================================
# security-updates.sh
# Halal Keeps — Automatic Security Updates Configuration
# =============================================================================
# Purpose:
#   Installs and configures the unattended-upgrades package so that Ubuntu
#   automatically downloads and applies security patches from the official
#   Ubuntu security repository every day without requiring manual intervention.
#   This reduces the window of exposure between a vulnerability being disclosed
#   and the fix being applied on the production server.
#
# What it configures:
#   - apt update runs daily          (Update-Package-Lists = 1)
#   - Security downloads run daily   (Download-Upgradeable-Packages = 1)
#   - Patches are auto-installed     (Unattended-Upgrade = 1)
#   - Package cache cleaned weekly   (AutocleanInterval = 7)
#
# Usage:
#   sudo bash security-updates.sh
# =============================================================================

set -euo pipefail

# ── Colour helpers ─────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

info()    { echo -e "${GREEN}[INFO]${NC}  $*"; }
section() { echo -e "\n${YELLOW}=== $* ===${NC}"; }

# ── Root check ─────────────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}[ERROR]${NC} This script must be run as root (sudo bash security-updates.sh)."
    exit 1
fi

# ── Update package lists ───────────────────────────────────────────────────────
section "Step 1 — Refresh Package Lists"
info "Running apt update..."
apt update -y
info "Package lists updated."

# ── Install unattended-upgrades ────────────────────────────────────────────────
section "Step 2 — Install unattended-upgrades"
info "Installing unattended-upgrades and apt-listchanges..."
apt install unattended-upgrades apt-listchanges -y
info "Packages installed: $(dpkg -l unattended-upgrades | grep '^ii' | awk '{print $2, $3}')"

# ── Run dpkg-reconfigure (non-interactive) ─────────────────────────────────────
section "Step 3 — Apply Default Configuration"
info "Configuring unattended-upgrades (non-interactive)..."
dpkg-reconfigure -f noninteractive unattended-upgrades
info "Default configuration applied."

# ── Write APT periodic policy ──────────────────────────────────────────────────
section "Step 4 — Write APT Automatic Update Policy"
APT_CONF="/etc/apt/apt.conf.d/20auto-upgrades"
info "Writing policy to $APT_CONF ..."

tee "$APT_CONF" > /dev/null <<'EOF'
// Halal Keeps — APT Automatic Update Policy
// Update package lists every day
APT::Periodic::Update-Package-Lists "1";
// Download available upgrades every day
APT::Periodic::Download-Upgradeable-Packages "1";
// Clean the package cache every 7 days
APT::Periodic::AutocleanInterval "7";
// Automatically install security upgrades every day
APT::Periodic::Unattended-Upgrade "1";
EOF

info "Policy file written."

# ── Enable and start the service ───────────────────────────────────────────────
section "Step 5 — Enable Service"
info "Enabling unattended-upgrades service..."
systemctl enable unattended-upgrades

info "Starting unattended-upgrades service..."
systemctl start unattended-upgrades

# ── Verification output ────────────────────────────────────────────────────────
section "Verification"

echo ""
info "--- APT policy file ($APT_CONF) ---"
cat "$APT_CONF"

echo ""
info "--- Service status ---"
systemctl status unattended-upgrades --no-pager

echo ""
info "--- Dry-run test (first 15 lines) ---"
unattended-upgrades --dry-run --debug 2>&1 | head -15

echo ""
info "============================================"
info " Automatic security updates configured."
info " To verify later:"
info "   sudo systemctl status unattended-upgrades"
info "   sudo unattended-upgrades --dry-run --debug"
info "============================================"
