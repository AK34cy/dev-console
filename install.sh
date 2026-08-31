#!/usr/bin/env bash
set -euo pipefail

SERVICE_NAME="iovon-dev-console.service"
REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONSOLE_ROOT="$REPOSITORY_ROOT/console"
SOURCE_SERVICE_FILE="$REPOSITORY_ROOT/systemd/$SERVICE_NAME"
TARGET_SERVICE_FILE="/etc/systemd/system/$SERVICE_NAME"
ENVIRONMENT_FILE="/etc/iovon-dev-console.env"
PROJECT_REPOSITORY_ROOT="/var/www/git"
SELECTED_USER=""
SELECTED_GROUP=""
PHP_BIN=""

MANDATORY_PACKAGES=(
  ca-certificates
  git
  openssh-client
  php-cli
  php-curl
  php-gd
  php-intl
  php-mbstring
  php-xml
  php-zip
  rsync
  sudo
)

log() {
  printf '[install] %s\n' "$*"
}

fail() {
  printf '[install] ERROR: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage:
  sudo ./install.sh [--user <existing-linux-user>]

Options:
  --user      Existing Linux account that should run Dev Console.
  -h, --help  Show this help.
USAGE
}

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    fail "Run this script as root: sudo ./install.sh"
  fi
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
      fail "Could not determine a non-root service user. Run sudo ./install.sh --user <existing-linux-user>."
    fi
  fi

  if [[ "$SELECTED_USER" == "root" ]]; then
    fail "Dev Console must run as an existing non-root Linux user."
  fi
}

verify_selected_user() {
  log "Verifying service user '$SELECTED_USER'..."
  id "$SELECTED_USER" >/dev/null 2>&1 || fail "Linux user does not exist: $SELECTED_USER"
  SELECTED_GROUP="$(id -gn "$SELECTED_USER")"
  [[ -n "$SELECTED_GROUP" ]] || fail "Could not determine primary group for $SELECTED_USER."
  log "Service will run as $SELECTED_USER:$SELECTED_GROUP"
}

verify_ubuntu() {
  log "Verifying Ubuntu host..."
  [[ -r /etc/os-release ]] || fail "Cannot read /etc/os-release."
  # shellcheck disable=SC1091
  . /etc/os-release
  [[ "${ID:-}" == "ubuntu" ]] || fail "Unsupported operating system '${PRETTY_NAME:-unknown}'. Ubuntu is required."
  log "Ubuntu detected: ${PRETTY_NAME:-ubuntu}"
}

verify_systemd() {
  log "Verifying systemd availability..."
  command -v systemctl >/dev/null 2>&1 || fail "systemctl is not installed."
  systemctl --version >/dev/null 2>&1 || fail "systemctl is installed but not usable."
  [[ -d /run/systemd/system ]] || fail "systemd does not appear to be the active init system on this host."
  log "systemd is available."
}

missing_packages() {
  local package
  for package in "${MANDATORY_PACKAGES[@]}"; do
    if ! dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q 'install ok installed'; then
      printf '%s\n' "$package"
    fi
  done
}

install_missing_packages() {
  local missing=()
  mapfile -t missing < <(missing_packages)
  if ((${#missing[@]} == 0)); then
    log "All mandatory packages are already installed."
    return
  fi

  command -v apt-get >/dev/null 2>&1 || fail "apt-get is required to install missing packages."
  log "Updating apt package index..."
  apt-get update
  log "Installing mandatory packages: ${missing[*]}"
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${missing[@]}"
}

verify_php() {
  local missing_extensions=()
  PHP_BIN="$(command -v php || true)"
  [[ -n "$PHP_BIN" ]] || fail "PHP CLI is not installed."
  php -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1 || fail "The php command is not running the CLI SAPI."

  local extension
  for extension in ctype curl dom fileinfo gd intl json mbstring openssl pcntl posix session simplexml tokenizer xml xmlreader xmlwriter zip zlib; do
    if ! php -m | tr '[:upper:]' '[:lower:]' | grep -qx "$extension"; then
      missing_extensions+=("$extension")
    fi
  done

  if ((${#missing_extensions[@]} > 0)); then
    fail "PHP is missing required extensions: ${missing_extensions[*]}"
  fi

  log "PHP CLI is available: $(php -r 'echo PHP_VERSION;')"
}

verify_project_files() {
  log "Verifying Dev Console checkout..."
  [[ -f "$SOURCE_SERVICE_FILE" ]] || fail "Missing service template: $SOURCE_SERVICE_FILE"
  [[ -x "$REPOSITORY_ROOT/bin/run-dev-console" ]] || fail "Missing executable runner: $REPOSITORY_ROOT/bin/run-dev-console"
  [[ -f "$CONSOLE_ROOT/index.php" ]] || fail "Missing console entrypoint: $CONSOLE_ROOT/index.php"
  log "Dev Console checkout is present."
}

ensure_environment_file() {
  if [[ -e "$ENVIRONMENT_FILE" ]]; then
    log "Preserving existing environment file: $ENVIRONMENT_FILE"
  else
    log "Creating environment file: $ENVIRONMENT_FILE"
    umask 077
    token="$(php -r 'echo bin2hex(random_bytes(32));')"
    printf 'IOVON_DEV_CONSOLE_TOKEN=%s\n' "$token" > "$ENVIRONMENT_FILE"
    unset token
  fi

  chown root:root "$ENVIRONMENT_FILE"
  chmod 0600 "$ENVIRONMENT_FILE"
}

ensure_runtime_directories() {
  log "Ensuring local runtime directories..."
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0755 "$PROJECT_REPOSITORY_ROOT"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0750 "$REPOSITORY_ROOT/config"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0750 "$CONSOLE_ROOT/config"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0700 "$CONSOLE_ROOT/runtime"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0700 "$CONSOLE_ROOT/runtime/server-tool-operations"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0700 "$CONSOLE_ROOT/runtime/managed-server-operations"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0700 "$CONSOLE_ROOT/runtime/preview-deployments"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0700 "$CONSOLE_ROOT/runtime/production-deployments"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0750 "$CONSOLE_ROOT/runs"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0750 "$CONSOLE_ROOT/runs/projects"
  install -d -o "$SELECTED_USER" -g "$SELECTED_GROUP" -m 0700 "$REPOSITORY_ROOT/.local"

  for path in "$REPOSITORY_ROOT/config" "$CONSOLE_ROOT/config" "$CONSOLE_ROOT/runtime" "$CONSOLE_ROOT/runs" "$REPOSITORY_ROOT/.local"; do
    chown -R "$SELECTED_USER:$SELECTED_GROUP" "$path"
  done
}

verify_project_readable_by_user() {
  local unreadable_path
  log "Verifying $SELECTED_USER can read the checkout..."
  command -v runuser >/dev/null 2>&1 || fail "runuser is required to verify access for $SELECTED_USER."
  runuser -u "$SELECTED_USER" -- test -x "$REPOSITORY_ROOT" || fail "$SELECTED_USER cannot access directory: $REPOSITORY_ROOT"
  unreadable_path="$(
    runuser -u "$SELECTED_USER" -- find "$REPOSITORY_ROOT" \
      -path "$REPOSITORY_ROOT/.git" -prune \
      -o \( -type d ! -executable -o -type f ! -readable \) \
      -print -quit
  )"

  [[ -z "$unreadable_path" ]] || fail "$SELECTED_USER cannot read or traverse project path: $unreadable_path"
  log "$SELECTED_USER can read the checkout."
}

install_service_file() {
  local rendered_service
  rendered_service="$(mktemp)"
  log "Rendering systemd unit to $TARGET_SERVICE_FILE..."

  while IFS= read -r line || [[ -n "$line" ]]; do
    case "$line" in
      User=*) printf 'User=%s\n' "$SELECTED_USER" ;;
      Group=*) printf 'Group=%s\n' "$SELECTED_GROUP" ;;
      WorkingDirectory=*) printf 'WorkingDirectory=%s\n' "$CONSOLE_ROOT" ;;
      ExecStart=*) printf 'ExecStart=%s/bin/run-dev-console\n' "$REPOSITORY_ROOT" ;;
      EnvironmentFile=*) printf 'EnvironmentFile=%s\n' "$ENVIRONMENT_FILE" ;;
      *) printf '%s\n' "$line" ;;
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
    log "Restarting $SERVICE_NAME..."
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

verify_health_endpoint() {
  log "Checking local health endpoint..."
  php -r '
    $context = stream_context_create(["http" => ["timeout" => 5, "ignore_errors" => true]]);
    $body = @file_get_contents("http://127.0.0.1:8090/health", false, $context);
    if ($body === false) {
        fwrite(STDERR, "Could not connect to http://127.0.0.1:8090/health\n");
        exit(1);
    }
    $status = $http_response_header[0] ?? "";
    if (strpos($status, "200") === false) {
        fwrite(STDERR, "Unexpected health response: " . $status . "\n");
        exit(1);
    }
    $json = json_decode($body, true);
    if (!is_array($json) || ($json["status"] ?? "") !== "ok") {
        fwrite(STDERR, "Health endpoint did not return status=ok\n");
        exit(1);
    }
  ' || fail "Health check failed."
  log "Health endpoint returned OK."
}

main() {
  parse_args "$@"
  require_root
  verify_ubuntu
  verify_systemd
  verify_selected_user
  install_missing_packages
  verify_php
  verify_project_files
  ensure_environment_file
  ensure_runtime_directories
  verify_project_readable_by_user
  install_service_file
  reload_and_start_service
  verify_service_active
  verify_health_endpoint
  log "Installation completed successfully. Open http://127.0.0.1:8090 through a private/local connection."
}

main "$@"
