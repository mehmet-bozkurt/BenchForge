# Source before invoking any external tool. Do not inherit application secrets,
# provider keys, shell startup hooks or runtime-injection settings from the caller.
benchforge_sanitize_environment() {
  local environment_name
  local skip_browser_smoke="${BENCHFORGE_SKIP_BROWSER_SMOKE:-}"

  for environment_name in ${(k)parameters}; do
    [[ "${parameters[$environment_name]}" == *export* ]] || continue

    case "$environment_name" in
      PATH|HOME|TMPDIR|TMP|TEMP|LANG|LANGUAGE|LC_ALL|LC_CTYPE|LC_NUMERIC|LC_TIME|LC_COLLATE|LC_MONETARY|LC_MESSAGES|LC_PAPER|LC_NAME|LC_ADDRESS|LC_TELEPHONE|LC_MEASUREMENT|LC_IDENTIFICATION|TZ|TERM|COLORTERM|NO_COLOR|XDG_DATA_HOME|XDG_CONFIG_HOME|XDG_CACHE_HOME|XDG_STATE_HOME|BENCHFORGE_BOUNDARY_MONITOR)
        ;;
      *)
        # Unexport shell-maintained values (PWD, SHLVL, etc.) as well as secrets.
        # Their local shell behavior is retained, but child tools never receive them.
        typeset -g +x "$environment_name" || return 1
        ;;
    esac
  done

  if [[ "$skip_browser_smoke" == "1" ]]; then
    export BENCHFORGE_SKIP_BROWSER_SMOKE=1
  fi
}

benchforge_sanitize_environment || {
  print -u2 -- "HATA: Benchmark surec ortami guvenli bicimde sinirlandirilamadi."
  exit 78
}
unfunction benchforge_sanitize_environment
