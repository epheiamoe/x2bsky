#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"

show_help() {
    echo "Usage: $0 [--password PASSWORD]"
    echo ""
    echo "Set or update the x2bsky admin password."
    echo ""
    echo "Options:"
    echo "  --password PASSWORD   Set password directly (visible in process list)"
    echo "  --help                Show this help"
    echo ""
    echo "If --password is not provided, you will be prompted interactively"
    echo "with hidden input."
    exit 0
}

detect_web_user() {
    for user in www www-data nginx apache httpd; do
        if id "$user" &>/dev/null; then
            echo "$user"
            return
        fi
    done
    # Fallback: check owner of project root
    stat -c '%U' "$PROJECT_ROOT" 2>/dev/null || echo "www"
}

PASSWORD=""
while [[ $# -gt 0 ]]; do
    case $1 in
        --password)
            PASSWORD="$2"
            shift 2
            ;;
        --help|-h)
            show_help
            ;;
        *)
            echo "Unknown option: $1"
            show_help
            ;;
    esac
done

if [[ -z "$PASSWORD" ]]; then
    if [[ -t 0 ]]; then
        read -rsp "Enter new admin password: " PASSWORD
        echo ""
        read -rsp "Confirm password:         " PASSWORD_CONFIRM
        echo ""
        if [[ "$PASSWORD" != "$PASSWORD_CONFIRM" ]]; then
            echo "Error: Passwords do not match."
            exit 1
        fi
    else
        echo "Error: No password provided and not running interactively."
        echo "Use --password PASSWORD or run interactively."
        exit 1
    fi
fi

if [[ ${#PASSWORD} -lt 1 ]]; then
    echo "Error: Password cannot be empty."
    exit 1
fi

if ! command -v php &>/dev/null; then
    echo "Error: PHP CLI is required but not found."
    exit 1
fi

HASH=$(php -r "echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT);" <<< "$PASSWORD")

if [[ -z "$HASH" ]]; then
    echo "Error: Failed to generate password hash."
    exit 1
fi

DATA_DIR="$PROJECT_ROOT/data"
mkdir -p "$DATA_DIR"

HASH_FILE="$DATA_DIR/.password_hash"
echo "$HASH" > "$HASH_FILE"
chmod 600 "$HASH_FILE"

WEB_USER=$(detect_web_user)
echo "Detected web user: $WEB_USER"

if [[ $(id -u) -eq 0 ]]; then
    chown "$WEB_USER:$WEB_USER" "$HASH_FILE" 2>/dev/null || true
    chown "$WEB_USER:$WEB_USER" "$DATA_DIR" 2>/dev/null || true

    SESSION_FILE="$DATA_DIR/bsky_session.json"
    if [[ -f "$SESSION_FILE" ]]; then
        chown "$WEB_USER:$WEB_USER" "$SESSION_FILE" 2>/dev/null || true
        chmod 600 "$SESSION_FILE" 2>/dev/null || true
        echo "Fixed bsky_session.json ownership"
    fi
else
    echo "Warning: Not running as root. Cannot fix file ownership."
    echo "If the web server cannot read the password file, run:"
    echo "  sudo chown $WEB_USER:$WEB_USER $HASH_FILE"
fi

echo "Password updated successfully."
