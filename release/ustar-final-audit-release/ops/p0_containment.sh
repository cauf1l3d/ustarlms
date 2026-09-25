#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_ROOT="/opt/ustar/apps/moodle/moodle/public/local/ustar/data"
BACKUP_ID="2026-08-22_22-17-34"
QUARANTINE_ROOT="/var/backups/ustar/p0-containment-${BACKUP_ID}"
MANIFEST="${QUARANTINE_ROOT}/manifest.sha256"

declare -A EXPECTED=(
  ["staff_position_map_2026-08-13.csv"]="2b28a281ec49b22c5bd9de80d5823374f83b4e6189f8a57c81979e4525c9c14a"
  ["structure_staffmap_2026-08-13.json"]="af29e0e42e81ecf64f1fb662e392d94811effa5d121f12551a5bd1f108f1a3e0"
)

install -d -o root -g root -m 0700 "${QUARANTINE_ROOT}"
: > "${MANIFEST}"
chmod 0600 "${MANIFEST}"

for name in "${!EXPECTED[@]}"; do
  source_path="${SOURCE_ROOT}/${name}"
  destination_path="${QUARANTINE_ROOT}/${name}"

  resolved_source="$(realpath -- "${source_path}")"
  if [[ "${resolved_source}" != "${source_path}" ]]; then
    echo "Refusing unexpected source path: ${resolved_source}" >&2
    exit 1
  fi
  if [[ ! -f "${source_path}" || -L "${source_path}" ]]; then
    echo "Refusing non-regular or symlink source: ${source_path}" >&2
    exit 1
  fi
  if [[ -e "${destination_path}" ]]; then
    echo "Refusing existing destination: ${destination_path}" >&2
    exit 1
  fi

  actual_hash="$(sha256sum "${source_path}" | awk '{print $1}')"
  if [[ "${actual_hash}" != "${EXPECTED[${name}]}" ]]; then
    echo "Checksum mismatch for ${source_path}" >&2
    exit 1
  fi

  mv -- "${source_path}" "${destination_path}"
  chown root:root "${destination_path}"
  chmod 0600 "${destination_path}"
  printf '%s  %s\n' "${actual_hash}" "${name}" >> "${MANIFEST}"
done

(
  cd "${QUARANTINE_ROOT}"
  sha256sum -c "${MANIFEST}"
)

for name in "${!EXPECTED[@]}"; do
  test ! -e "${SOURCE_ROOT}/${name}"
done

cat <<EOF
P0 containment complete.
Quarantine: ${QUARANTINE_ROOT}
Rollback: move both files back to ${SOURCE_ROOT}, restore www-data:www-data and mode 0644, then re-run the recorded SHA-256 checks.
EOF
