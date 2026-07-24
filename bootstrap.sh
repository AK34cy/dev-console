#!/usr/bin/env bash
set -euo pipefail

SERVICE_NAME="iovon-dev-console.service"
REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_SERVICE_FILE="$REPOSITORY_ROOT/systemd/$SERVICE_NAME"
TARGET_SERVICE_FILE="/etc/systemd/system/$SERVICE_NAME"
ENVIRONMENT_FILE="/etc/iovon-dev-console.env"
REQUIRED_PACKAGES=()
SELECTED_USER=""
SELECTED_GROUP=""
PHP_BIN=""

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

usage() {
  cat <<'USAGE'
Usage:
  sudo ./bootstrap.sh [--user <existing-linux-user>]

Options:
  --user  Linux account that should run the systemd service.
  -h, --help  Show this help.
USAGE
}

parse_args() {
  while (($# > 0)); do
    case "$1" in
      --user)
        [[ $# -ge 2 ]] || fail "--user requires a value."
        SELECTED_USER="$2"
        shift 2
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        fail "Unknown argument: $1"
        ;;
    esac
  done

  if [[ -z "$SELECTED_USER" ]]; then
    if [[ -n "${SUDO_USER:-}" && "${SUDO_USER:-}" != "root" ]]; then
      SELECTED_USER="$SUDO_USER"
    else
      SELECTED_USER="$(id -un)"
    fi
  fi
}

verify_selected_user() {
  log "Verifying service user '$SELECTED_USER'..."

  id "$SELECTED_USER" >/dev/null 2>&1 || fail "Linux user does not exist: $SELECTED_USER"
  SELECTED_GROUP="$(id -gn "$SELECTED_USER")"

  if [[ -z "$SELECTED_GROUP" ]]; then
    fail "Could not determine primary group for user: $SELECTED_USER"
  fi

  log "Service will run as $SELECTED_USER:$SELECTED_GROUP"
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
  PHP_BIN="$(command -v php)"
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

verify_project_readable_by_user() {
  local unreadable_path

  log "Verifying $SELECTED_USER can read project files..."

  command -v runuser >/dev/null 2>&1 || fail "runuser is required to verify access for $SELECTED_USER."

  runuser -u "$SELECTED_USER" -- test -x "$REPOSITORY_ROOT" || fail "$SELECTED_USER cannot access directory: $REPOSITORY_ROOT"
  unreadable_path="$(
    runuser -u "$SELECTED_USER" -- find "$REPOSITORY_ROOT" \
      -path "$REPOSITORY_ROOT/.git" -prune \
      -o \( -type d ! -executable -o -type f ! -readable \) \
      -print -quit
  )"

  if [[ -n "$unreadable_path" ]]; then
    fail "$SELECTED_USER cannot read or traverse project path: $unreadable_path"
  fi

  log "$SELECTED_USER can read the project files."
}

install_service_file() {
  local console_dir="$REPOSITORY_ROOT/console"
  local rendered_service
  rendered_service="$(mktemp)"

  log "Rendering service file to $TARGET_SERVICE_FILE..."

  while IFS= read -r line || [[ -n "$line" ]]; do
    case "$line" in
      User=*)
        printf 'User=%s\n' "$SELECTED_USER"
        ;;
      Group=*)
        printf 'Group=%s\n' "$SELECTED_GROUP"
        ;;
      WorkingDirectory=*)
        printf 'WorkingDirectory=%s\n' "$console_dir"
        ;;
      ExecStart=*)
        printf 'ExecStart=%s -S 127.0.0.1:8090 -t %s %s/index.php\n' "$PHP_BIN" "$console_dir" "$console_dir"
        ;;
      *)
        printf '%s\n' "$line"
        ;;
    esac
  done < "$SOURCE_SERVICE_FILE" > "$rendered_service"

  install -o root -g root -m 0644 "$rendered_service" "$TARGET_SERVICE_FILE"
  rm -f "$rendered_service"
}

reload_and_start_service() {
  log "Reloading systemd..."
  systemctl daemon-reload

  log "Enabling $SERVICE_NAME..."
  systemctl enable "$SERVICE_NAME"

  if systemctl is-active --quiet "$SERVICE_NAME"; then
    log "Restarting $SERVICE_NAME to apply the installed unit..."
    systemctl restart "$SERVICE_NAME"
  else
    log "Starting $SERVICE_NAME..."
    systemctl start "$SERVICE_NAME"
  fi
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
  parse_args "$@"
  require_root
  verify_ubuntu
  verify_systemd
  verify_selected_user
  detect_missing_packages
  install_missing_packages
  verify_php_cli
  verify_project_files
  verify_project_readable_by_user
  install_service_file
  reload_and_start_service
  verify_service_active
  log "Bootstrap completed successfully."
}

main "$@"
