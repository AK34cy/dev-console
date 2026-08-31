#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

printf '[bootstrap] bootstrap.sh is retained for compatibility; use sudo ./install.sh for new installs.\n'
exec "$repository_root/install.sh" "$@"
