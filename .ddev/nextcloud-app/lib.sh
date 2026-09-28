#!/usr/bin/env bash

#ddev-generated
## Shared helpers provided by ddev-nextcloud-app.

set -eu -o pipefail

NC_APP_ROOT="${DDEV_APPROOT:-/var/www/html}"
NC_DOCROOT="${DDEV_DOCROOT:-nextcloud}"
NC_CORE_ROOT="${NC_APP_ROOT}/${NC_DOCROOT}"
NC_RUNTIME_CONFIG="${NC_APP_ROOT}/.ddev/nextcloud-app/ddev.config.php"

nc_fail() {
  printf 'ddev-nextcloud-app: %s\n' "$*" >&2
  return 1
}

nc_app_id() {
  local info_file="${NC_APP_ROOT}/appinfo/info.xml"
  local app_id

  if [ ! -f "${info_file}" ]; then
    nc_fail "找不到 ${info_file}。"
    return 1
  fi

  app_id="$(
    php -r '
      $xml = @simplexml_load_file($argv[1]);
      if ($xml === false || trim((string) $xml->id) === "") {
          fwrite(STDERR, "appinfo/info.xml 缺少有效的 <id>。\n");
          exit(1);
      }
      echo trim((string) $xml->id);
    ' "${info_file}"
  )"

  if [[ ! "${app_id}" =~ ^[a-z][a-z0-9_-]*[a-z0-9]$ && ! "${app_id}" =~ ^[a-z]$ ]]; then
    nc_fail "app ID '${app_id}' 不符合 Nextcloud 的小寫英數、底線與連字號規則。"
    return 1
  fi

  printf '%s\n' "${app_id}"
}

nc_require_core() {
  if [ ! -f "${NC_CORE_ROOT}/version.php" ]; then
    nc_fail "找不到 Nextcloud core；請先執行 ddev nc-setup。"
    return 1
  fi
}

nc_install_runtime_config() {
  mkdir -p "${NC_CORE_ROOT}/config" "${NC_CORE_ROOT}/apps-extra"
  cp "${NC_RUNTIME_CONFIG}" "${NC_CORE_ROOT}/config/ddev.config.php"
}

nc_link_app() {
  local app_id
  local target
  local entry
  local basename

  nc_require_core
  app_id="$(nc_app_id)"
  target="${NC_CORE_ROOT}/apps-extra/${app_id}"

  case "${target}" in
    "${NC_CORE_ROOT}/apps-extra/"*)
      ;;
    *)
      nc_fail "拒絕使用未預期的 app 目標路徑：${target}"
      return 1
      ;;
  esac

  if [ -e "${target}" ] || [ -L "${target}" ]; then
    rm -rf -- "${target}"
  fi
  mkdir -p "${target}"

  while IFS= read -r -d '' entry; do
    basename="${entry##*/}"
    case "${basename}" in
      .git|.ddev|"${NC_DOCROOT}")
        continue
        ;;
    esac
    ln -s "${entry}" "${target}/${basename}"
  done < <(find "${NC_APP_ROOT}" -mindepth 1 -maxdepth 1 -print0)

  nc_install_runtime_config
  printf '已連結 app %s → %s\n' "${app_id}" "${target}"
}
