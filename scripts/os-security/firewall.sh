#!/bin/bash
# =============================================================================
# firewall.sh
# Halal Keeps — UFW Firewall Configuration
# =============================================================================
# Purpose:
#   Restricts the server's attack surface by denying all inbound connections
#   by default. Only the three ports required for the application are opened:
#     - Port 22  (SSH)   : remote server administration
#     - Port 80  (HTTP)  : web traffic (redirected to HTTPS by .htaccess)
#     - Port 443 (HTTPS) : secure web traffic
#   MySQL port 3306 is explicitly blocked so the database cannot be reached
#   directly from the internet — only the PHP application on the same host
#   can connect to it.
#
# Usage:
#   sudo bash firewall.sh
# =============================================================================

set -euo pipefail

# ── Colour helpers ─────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Colour

info()    { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
section() { echo -e "\n${YELLOW}=== $* ===${NC}"; }

# ── Root check ─────────────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}[ERROR]${NC} This script must be run as root (sudo bash firewall.sh)."
    exit 1
fi

# ── Install UFW if missing ─────────────────────────────────────────────────────
section "Prerequisites"
if ! command -v ufw &>/dev/null; then
    info "UFW not found — installing..."
    apt update -y
    apt install ufw -y
else
    info "UFW is already installed: $(ufw --version | head -1)"
fi

# ── Default policies ───────────────────────────────────────────────────────────
section "Default Policies"
info "Setting default: DENY all incoming traffic..."
ufw default deny incoming

info "Setting default: ALLOW all outgoing traffic..."
ufw default allow outgoing

# ── Allowed ports ──────────────────────────────────────────────────────────────
section "Allowed Incoming Ports"

info "Allowing SSH on port 22/tcp (remote administration)..."
ufw allow 22/tcp comment 'SSH - Remote Administration'

info "Allowing HTTP on port 80/tcp (web traffic)..."
ufw allow 80/tcp comment 'HTTP - Web Traffic'

info "Allowing HTTPS on port 443/tcp (secure web traffic)..."
ufw allow 443/tcp comment 'HTTPS - Secure Web Traffic'

# ── Blocked ports ──────────────────────────────────────────────────────────────
section "Blocked Ports"

info "Blocking external MySQL access on port 3306/tcp..."
ufw deny 3306/tcp comment 'MySQL - Block External Access'

info "Blocking PostgreSQL port 5432/tcp (not used, block by default)..."
ufw deny 5432/tcp comment 'PostgreSQL - Block External Access'

# ── Enable firewall ────────────────────────────────────────────────────────────
section "Enabling Firewall"
info "Enabling UFW firewall..."
ufw --force enable

# ── Verification output ────────────────────────────────────────────────────────
section "Firewall Status (Verification)"
echo ""
ufw status verbose
echo ""
ufw status numbered

echo ""
info "============================================"
info " Firewall configuration applied successfully."
info " To verify later: sudo ufw status verbose"
info "============================================"
