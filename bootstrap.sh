#!/usr/bin/env bash
set -euo pipefail

SERVICE_NAME="iovon-dev-console.service"
REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_SERVICE_FILE="$REPOSITORY_ROOT/systemd/$SERVICE_NAME"
TARGET_SERVICE_FILE="/etc/systemd/system/$SERVICE_NAME"
ENVIRONMENT_FILE="/etc/iovon-dev-console.env"
REQUIRED_PACKAGES=()

log() {
  printf '[bootstrap] %s\n' "$*"
}

fail() {
  printf '[bootstrap] ERROR: %s\n' "$*" >&2
  exit 1
}

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    fail "Run this script as root: sudo ./bootstrap.sh"
  fi
}

verify_ubuntu() {
  log "Verifying Ubuntu host..."

  if [[ ! -r /etc/os-release ]]; then
    fail "Cannot read /etc/os-release."
  fi

  # shellcheck disable=SC1091
  . /etc/os-release

  if [[ "${ID:-}" != "ubuntu" ]]; then
    fail "Unsupported operating system '${PRETTY_NAME:-unknown}'. Ubuntu is required."
  fi

  log "Ubuntu detected: ${PRETTY_NAME:-ubuntu}"
}

verify_systemd() {
  log "Verifying systemd availability..."

  command -v systemctl >/dev/null 2>&1 || fail "systemctl is not installed."
  systemctl --version >/dev/null 2>&1 || fail "systemctl is installed but not usable."

  if [[ ! -d /run/systemd/system ]]; then
    fail "systemd does not appear to be the active init system on this host."
  fi

  log "systemd is available."
}

detect_missing_packages() {
  log "Checking required packages..."

  if ! command -v php >/dev/null 2>&1; then
    REQUIRED_PACKAGES+=("php-cli")
  fi

  if ((${#REQUIRED_PACKAGES[@]} == 0)); then
    log "All required packages are already installed."
  else
    log "Missing required packages: ${REQUIRED_PACKAGES[*]}"
  fi
}

install_missing_packages() {
  if ((${#REQUIRED_PACKAGES[@]} == 0)); then
    return
  fi

  command -v apt-get >/dev/null 2>&1 || fail "apt-get is required to install missing packages."

  log "Updating apt package index..."
  apt-get update

  log "Installing missing required packages: ${REQUIRED_PACKAGES[*]}"
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${REQUIRED_PACKAGES[@]}"
}

verify_php_cli() {
  log "Verifying PHP CLI..."

  command -v php >/dev/null 2>&1 || fail "PHP CLI is not installed."
  php -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1 || fail "The php command is not running the CLI SAPI."

  log "PHP CLI is available: $(php -r 'echo PHP_VERSION;')"
}

verify_project_files() {
  log "Verifying project files..."

  [[ -f "$SOURCE_SERVICE_FILE" ]] || fail "Missing service file: $SOURCE_SERVICE_FILE"
  [[ -d "$REPOSITORY_ROOT/console" ]] || fail "Missing console directory: $REPOSITORY_ROOT/console"

  if [[ ! -f "$ENVIRONMENT_FILE" ]]; then
    fail "Missing environment file: $ENVIRONMENT_FILE"
  fi

  log "Project files are present."
}

install_service_file() {
  log "Copying service file to $TARGET_SERVICE_FILE..."
  install -o root -g root -m 0644 "$SOURCE_SERVICE_FILE" "$TARGET_SERVICE_FILE"
}

reload_and_start_service() {
  log "Reloading systemd..."
  systemctl daemon-reload

  log "Enabling $SERVICE_NAME..."
  systemctl enable "$SERVICE_NAME"

  log "Starting $SERVICE_NAME..."
  systemctl start "$SERVICE_NAME"
}

verify_service_active() {
  log "Verifying $SERVICE_NAME is active..."

  if ! systemctl is-active --quiet "$SERVICE_NAME"; then
    systemctl status "$SERVICE_NAME" --no-pager >&2 || true
    fail "$SERVICE_NAME is not active."
  fi

  log "$SERVICE_NAME is active."
}

main() {
  require_root
  verify_ubuntu
  verify_systemd
  detect_missing_packages
  install_missing_packages
  verify_php_cli
  verify_project_files
  install_service_file
  reload_and_start_service
  verify_service_active
  log "Bootstrap completed successfully."
}

main "$@"
