#!/bin/bash
# =====================================================
# SNCS — Database Migration Script
# =====================================================
# Usage: bash scripts/db-migrate.sh [--reset]
# =====================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
DB_DIR="$PROJECT_ROOT/backend/db"

# Load .env
if [ -f "$PROJECT_ROOT/.env" ]; then
    export $(grep -v '^#' "$PROJECT_ROOT/.env" | xargs)
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-sncs_db}"
DB_USER="${DB_USER:-sncs_user}"
DB_PASS="${DB_PASS:-}"

MYSQL_CMD="mysql -h $DB_HOST -P $DB_PORT -u $DB_USER"
if [ -n "$DB_PASS" ]; then
    MYSQL_CMD="$MYSQL_CMD -p$DB_PASS"
fi

echo "╔══════════════════════════════════════════════╗"
echo "║  SNCS Database Migration                     ║"
echo "╚══════════════════════════════════════════════╝"
echo "Host: $DB_HOST:$DB_PORT"
echo "Database: $DB_NAME"
echo ""

# Reset mode: drop and recreate
if [ "${1:-}" = "--reset" ]; then
    echo "WARNING: This will DROP the database '$DB_NAME' and recreate it!"
    read -p "Are you sure? (y/N): " confirm
    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        echo "Aborted."
        exit 0
    fi
    echo "Dropping database..."
    $MYSQL_CMD -e "DROP DATABASE IF EXISTS $DB_NAME;"
fi

echo "Creating database (if not exists)..."
$MYSQL_CMD -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Running schema.sql..."
$MYSQL_CMD $DB_NAME < "$DB_DIR/schema.sql"
echo "  ✓ Tables created"

echo "Running relations.sql..."
$MYSQL_CMD $DB_NAME < "$DB_DIR/relations.sql"
echo "  ✓ Constraints, indexes, triggers applied"

echo "Running procedures.sql..."
$MYSQL_CMD $DB_NAME < "$DB_DIR/procedures.sql"
echo "  ✓ Stored procedures created"

echo ""
echo "╔══════════════════════════════════════════════╗"
echo "║  Migration complete!                         ║"
echo "╚══════════════════════════════════════════════╝"
