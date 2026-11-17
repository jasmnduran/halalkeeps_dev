#!/bin/bash
# =============================================================================
# db-backup.sh
# Halal Keeps — Automated Daily Database Backup
# =============================================================================
# Purpose:
#   Exports a full compressed dump of the halal_system MySQL database, stores
#   it in a root-only readable directory, and maintains a 30-day rolling
#   archive. Each run is logged with a timestamp.
#
#   Protects against:
#     - Server hardware failure
#     - Accidental data deletion
#     - Database corruption
#     - Ransomware (offsite copy of backup recommended)
#
# Schedule (run as root via cron):
#   crontab -e
#   0 2 * * * /opt/halal_keeps/scripts/os-security/db-backup.sh
#
# Usage (manual run):
#   sudo bash db-backup.sh
# =============================================================================

set -euo pipefail

# ── Colour helpers ─────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

info()    { echo -e "${GREEN}[INFO]${NC}  $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
err()     { echo -e "${RED}[ERROR]${NC} $*"; }
section() { echo -e "\n${YELLOW}=== $* ===${NC}"; }

# ── Configuration ──────────────────────────────────────────────────────────────
# Edit these values to match your server's database credentials.
DB_NAME="${DB_NAME:-halal_system}"
DB_USER="${DB_USER:-halal_user}"
DB_PASS="${DB_PASS:-your_db_password_here}"
DB_HOST="${DB_HOST:-127.0.0.1}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/halal_keeps}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/halal_system_${TIMESTAMP}.sql.gz"
LOG_FILE="${BACKUP_DIR}/backup.log"

# ── Root check ─────────────────────────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    err "This script must be run as root (sudo bash db-backup.sh)."
    exit 1
fi

# ── Prepare backup directory ───────────────────────────────────────────────────
section "Step 1 — Prepare Backup Directory"
if [[ ! -d "$BACKUP_DIR" ]]; then
    info "Creating backup directory: $BACKUP_DIR"
    mkdir -p "$BACKUP_DIR"
fi

# Lock down permissions — only root can read the backup files
chmod 700 "$BACKUP_DIR"
ok() { echo -e "${GREEN}[OK]${NC}    $*"; }
ok "Backup directory secured: $BACKUP_DIR (chmod 700)"
ls -ld "$BACKUP_DIR"

# ── Log start ──────────────────────────────────────────────────────────────────
section "Step 2 — Starting Backup"
START_TIME=$(date '+%Y-%m-%d %H:%M:%S')
echo "[$START_TIME] ===== Backup started =====" | tee -a "$LOG_FILE"
info "Database : $DB_NAME"
info "Host     : $DB_HOST"
info "Output   : $BACKUP_FILE"

# ── Verify MySQL is reachable ──────────────────────────────────────────────────
section "Step 3 — Verify Database Connection"
if ! mysqladmin --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" ping &>/dev/null; then
    err "Cannot connect to MySQL. Check DB_USER, DB_PASS, DB_HOST in this script."
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: MySQL connection failed." | tee -a "$LOG_FILE"
    exit 1
fi
info "MySQL connection verified."

# ── Run mysqldump and compress ─────────────────────────────────────────────────
section "Step 4 — Dump and Compress Database"
info "Running mysqldump (single-transaction, with routines and triggers)..."

mysqldump \
    --user="$DB_USER" \
    --password="$DB_PASS" \
    --host="$DB_HOST" \
    --single-transaction \
    --routines \
    --triggers \
    --databases "$DB_NAME" \
    | gzip > "$BACKUP_FILE"

FILESIZE=$(du -sh "$BACKUP_FILE" | cut -f1)
DONE_TIME=$(date '+%Y-%m-%d %H:%M:%S')
echo "[$DONE_TIME] Backup saved: $BACKUP_FILE ($FILESIZE)" | tee -a "$LOG_FILE"
info "Backup file size: $FILESIZE"

# ── Verify the backup file is not empty ───────────────────────────────────────
section "Step 5 — Verify Backup Integrity"
if [[ ! -s "$BACKUP_FILE" ]]; then
    err "Backup file is empty! Something went wrong."
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: Backup file is empty." | tee -a "$LOG_FILE"
    exit 1
fi

# Check the gzip file is valid
if gzip -t "$BACKUP_FILE" 2>/dev/null; then
    info "Backup file integrity check passed (valid gzip)."
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Integrity check: PASSED" | tee -a "$LOG_FILE"
else
    err "Backup file is corrupted!"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Integrity check: FAILED" | tee -a "$LOG_FILE"
    exit 1
fi

# ── Remove old backups beyond retention period ─────────────────────────────────
section "Step 6 — Remove Old Backups (>${RETENTION_DAYS} days)"
DELETED=$(find "$BACKUP_DIR" -name "halal_system_*.sql.gz" \
    -mtime +"$RETENTION_DAYS" -print -delete | wc -l)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Removed $DELETED old backup(s) older than ${RETENTION_DAYS} days." \
    | tee -a "$LOG_FILE"
info "Removed $DELETED old backup(s)."

# ── Log completion ─────────────────────────────────────────────────────────────
echo "[$(date '+%Y-%m-%d %H:%M:%S')] ===== Backup complete =====" | tee -a "$LOG_FILE"

# ── Verification output ────────────────────────────────────────────────────────
section "Verification — Current Backups"
echo ""
info "--- Backup directory contents ---"
ls -lh "$BACKUP_DIR"/*.sql.gz 2>/dev/null || warn "No .sql.gz files found yet."

echo ""
info "--- Last 10 log entries ---"
tail -10 "$LOG_FILE"

echo ""
info "============================================"
info " Backup completed successfully."
info " File : $BACKUP_FILE ($FILESIZE)"
info ""
info " To schedule daily backups at 2 AM, run:"
info "   sudo crontab -e"
info " Then add this line:"
info "   0 2 * * * /opt/halal_keeps/scripts/os-security/db-backup.sh"
info ""
info " To verify later:"
info "   ls -lh $BACKUP_DIR"
info "   tail -20 $LOG_FILE"
info "============================================"
