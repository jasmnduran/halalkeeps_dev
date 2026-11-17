#!/bin/bash
# =============================================================================
# user-security.sh
# Halal Keeps — User Account & SSH Security Hardening
# =============================================================================
# Purpose:
#   Hardens OS-level user accounts and SSH access to prevent unauthorized
#   privilege escalation and brute-force login attacks. Specifically:
#
#   1. Restricts /home directory permissions so users cannot browse each
#      other's files.
#   2. Locks the guest account (removes the anonymous entry point).
#   3. Disables root SSH login — forces admins to log in as a named user
#      and use sudo, creating an audit trail for all privileged actions.
#   4. Enforces SSH Protocol 2 (Protocol 1 has known vulnerabilities).
#   5. Installs libpam-pwquality and enforces a minimum password strength
#      policy that matches the same requirements enforced by the Halal Keeps
#      web application (8+ chars, uppercase, lowercase, digit, special char).
#   6. Applies a 90-day password expiry policy for all OS accounts.
#
# Usage:
#   sudo bash user-security.sh
# =============================================================================

set -euo pipefail

# ── Colour helpers ─────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

info()    { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
ok()      { echo -e "${GREEN}[OK]${NC}    $*"; }
section() { echo -e "\n${YELLOW}=== $* ===${NC}"; }

# ── Root check ─────────────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}[ERROR]${NC} This script must be run as root (sudo bash user-security.sh)."
    exit 1
fi

# ── 1. Restrict /home permissions ──────────────────────────────────────────────
section "Step 1 — Restrict /home Directory Permissions"
CURRENT_PERM=$(stat -c "%a" /home)
info "Current /home permissions: $CURRENT_PERM"
chmod 750 /home
ok "/home permissions set to 750 (owner=rwx, group=r-x, others=no access)."
ls -ld /home

# ── 2. Lock guest account ──────────────────────────────────────────────────────
section "Step 2 — Lock Guest Account"
if id "guest" &>/dev/null; then
    passwd -l guest
    ok "Guest account locked."
    passwd -S guest
else
    warn "No 'guest' account found on this system — skipping."
fi

# ── 3. Disable root SSH login ──────────────────────────────────────────────────
section "Step 3 — Disable Root SSH Login"
SSHD_CONF="/etc/ssh/sshd_config"
info "Backing up $SSHD_CONF to ${SSHD_CONF}.bak ..."
cp "$SSHD_CONF" "${SSHD_CONF}.bak"

info "Setting PermitRootLogin no ..."
if grep -q "^PermitRootLogin" "$SSHD_CONF"; then
    sed -i 's/^PermitRootLogin.*/PermitRootLogin no/' "$SSHD_CONF"
elif grep -q "^#PermitRootLogin" "$SSHD_CONF"; then
    sed -i 's/^#PermitRootLogin.*/PermitRootLogin no/' "$SSHD_CONF"
else
    echo "PermitRootLogin no" >> "$SSHD_CONF"
fi
ok "Root SSH login disabled."

# ── 4. Enforce SSH Protocol 2 ──────────────────────────────────────────────────
section "Step 4 — Enforce SSH Protocol 2"
if grep -q "^Protocol" "$SSHD_CONF"; then
    sed -i 's/^Protocol.*/Protocol 2/' "$SSHD_CONF"
elif grep -q "^#Protocol" "$SSHD_CONF"; then
    sed -i 's/^#Protocol.*/Protocol 2/' "$SSHD_CONF"
else
    echo "Protocol 2" >> "$SSHD_CONF"
fi
ok "SSH Protocol 2 enforced."

# ── 5. Set LoginGraceTime and MaxAuthTries ─────────────────────────────────────
section "Step 5 — SSH Brute-Force Limits"
info "Setting LoginGraceTime to 30 seconds..."
if grep -q "^LoginGraceTime" "$SSHD_CONF"; then
    sed -i 's/^LoginGraceTime.*/LoginGraceTime 30/' "$SSHD_CONF"
else
    echo "LoginGraceTime 30" >> "$SSHD_CONF"
fi

info "Setting MaxAuthTries to 4..."
if grep -q "^MaxAuthTries" "$SSHD_CONF"; then
    sed -i 's/^MaxAuthTries.*/MaxAuthTries 4/' "$SSHD_CONF"
else
    echo "MaxAuthTries 4" >> "$SSHD_CONF"
fi
ok "SSH brute-force limits applied."

# ── Restart SSH ────────────────────────────────────────────────────────────────
info "Restarting SSH service to apply configuration..."
systemctl restart ssh
ok "SSH service restarted."

# ── 6. Install and configure password quality ──────────────────────────────────
section "Step 6 — Password Quality Policy (libpam-pwquality)"
info "Installing libpam-pwquality..."
apt update -y -qq
apt install libpam-pwquality -y

PWQUALITY_CONF="/etc/security/pwquality.conf"
info "Writing password quality rules to $PWQUALITY_CONF ..."

tee "$PWQUALITY_CONF" > /dev/null <<'EOF'
# Halal Keeps — Password Quality Policy
# Matches the same requirements enforced by the web application.

# Minimum 8 characters
minlen = 8

# Require at least 4 character classes:
# uppercase, lowercase, digits, and special characters
minclass = 4

# Maximum 3 consecutive identical characters
maxrepeat = 3

# Reject passwords that contain the username
gecoscheck = 1

# Reject passwords found in common password dictionaries
dictcheck = 1
EOF

ok "Password quality policy written."

# ── 7. Password aging policy ───────────────────────────────────────────────────
section "Step 7 — Password Aging Policy (/etc/login.defs)"
LOGIN_DEFS="/etc/login.defs"

info "Setting PASS_MAX_DAYS to 90 (passwords expire after 90 days)..."
sed -i 's/^PASS_MAX_DAYS.*/PASS_MAX_DAYS   90/' "$LOGIN_DEFS"

info "Setting PASS_MIN_DAYS to 1 (must wait 1 day before changing again)..."
sed -i 's/^PASS_MIN_DAYS.*/PASS_MIN_DAYS   1/' "$LOGIN_DEFS"

info "Setting PASS_WARN_AGE to 7 (warn 7 days before expiry)..."
sed -i 's/^PASS_WARN_AGE.*/PASS_WARN_AGE   7/' "$LOGIN_DEFS"

ok "Password aging policy applied."

# ── Verification output ────────────────────────────────────────────────────────
section "Verification Summary"

echo ""
info "--- /home directory permissions ---"
ls -ld /home

echo ""
info "--- SSH config (key security settings) ---"
grep -E "^(PermitRootLogin|Protocol|LoginGraceTime|MaxAuthTries)" "$SSHD_CONF"

echo ""
info "--- SSH service status ---"
systemctl status ssh --no-pager | head -5

echo ""
info "--- Password quality config ---"
grep -v "^#" "$PWQUALITY_CONF" | grep -v "^$"

echo ""
info "--- Password aging policy ---"
grep -E "^PASS_(MAX|MIN|WARN)" "$LOGIN_DEFS"

echo ""
ok "============================================"
ok " User account security hardening complete."
ok " To verify later:"
ok "   grep PermitRootLogin /etc/ssh/sshd_config"
ok "   cat /etc/security/pwquality.conf"
ok "   grep PASS_ /etc/login.defs"
ok "============================================"
