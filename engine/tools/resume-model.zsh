#!/bin/zsh -f

set -u

SCRIPT_DIZIN="${0:A:h}"
ANA_DIZIN="${SCRIPT_DIZIN:h}"
source "${SCRIPT_DIZIN}/benchforge-process-environment.zsh" || exit 78

if (( $# != 10 )); then
  echo "Kullanim:"
  echo "  $0 <run-dir> <model-folder> <model> <port> <routes> <opencode-config> <attempt-dir> <continue|validate> <session-id|-> <prompt-file|->"
  exit 64
fi

RUN_DIZIN="${1:A}"
KLASOR="$2"
MODEL="$3"
PORT="$4"
ROTA_CSV="$5"
OPENCODE_CONFIG_ARG="$6"
DENEME_DIZIN="${7:A}"
MOD="$8"
KAYNAK_SESSION="$9"
PROMPT_DOSYASI="${10}"
PROJE="${RUN_DIZIN}/${KLASOR}"
BROWSER_SMOKE="${ANA_DIZIN}/tools/browser-smoke/check.mjs"
BROWSER_SMOKE_DIZIN="${ANA_DIZIN}/tools/browser-smoke"
SESSION_SECICI="${ANA_DIZIN}/tools/select-opencode-session.mjs"
EVENT_SESSION_SECICI="${ANA_DIZIN}/tools/extract-opencode-event-session.mjs"
MODEL_POLICY="${ANA_DIZIN}/tools/benchforge-model-policy.mjs"
BOUNDARY_MONITOR="${BENCHFORGE_BOUNDARY_MONITOR:-}"

if [[ "$KLASOR" != [A-Za-z0-9]* || "$KLASOR" == *[^A-Za-z0-9._-]* ]]; then
  echo "HATA: Gecersiz model klasoru."
  exit 65
fi

if [[ "$MOD" != "continue" && "$MOD" != "validate" ]]; then
  echo "HATA: Gecersiz deneme modu."
  exit 65
fi

if [[ "$PORT" != <-> || "$PORT" -lt 1024 || "$PORT" -gt 65535 ]]; then
  echo "HATA: Gecersiz port."
  exit 65
fi

if [[ ! -d "$RUN_DIZIN" || ! -d "$PROJE" ]]; then
  echo "HATA: Kosu veya model calisma alani bulunamadi."
  exit 66
fi

if [[ "$DENEME_DIZIN" != "${RUN_DIZIN}/_attempts/${KLASOR}/"* ]]; then
  echo "HATA: Deneme klasoru kosu alaninin disinda."
  exit 65
fi

if [[ ! -f "$BROWSER_SMOKE" || ! -f "$SESSION_SECICI" || ! -f "$EVENT_SESSION_SECICI" ]]; then
  echo "HATA: Benchmark yardimci araclari bulunamadi."
  exit 66
fi

for KOMUT in node npm rsync curl python3; do
  if ! command -v "$KOMUT" >/dev/null 2>&1; then
    echo "HATA: Komut bulunamadi -> $KOMUT"
    exit 69
  fi
done

if [[ "$MOD" == "continue" ]]; then
  if ! command -v opencode >/dev/null 2>&1; then
    echo "HATA: Komut bulunamadi -> opencode"
    exit 69
  fi

  OPENCODE_CONFIG_DOSYASI="${OPENCODE_CONFIG_ARG:A}"

  if [[ "$KAYNAK_SESSION" != ses_* || ! -f "$PROMPT_DOSYASI" || ! -f "$OPENCODE_CONFIG_DOSYASI" ]]; then
    echo "HATA: Devam denemesi icin gecerli oturum ve prompt gerekli."
    exit 65
  fi

  if [[ ! -f "$MODEL_POLICY" || -z "$BOUNDARY_MONITOR" || ! -f "$BOUNDARY_MONITOR" ]]; then
    echo "HATA: Model calisma siniri araci gerekli. BENCHFORGE_BOUNDARY_MONITOR, BenchForge/bin/model-boundary-monitor.mjs dosyasinin tam yoluna ayarlanmali."
    exit 66
  fi
fi

mkdir -p "$DENEME_DIZIN"

typeset -a ROTA_DIZISI
ROTA_DIZISI=("${(@s:,:)ROTA_CSV}")

BASLANGIC=$(date +%s)
OPENCODE_RC="skip"
LINT_RC=1
BUILD_RC=1
EXPORT_RC=1
ROUTES_RC=1
RUNTIME_RC=1
SONUC_SESSION=""
GALLERY_PUBLISH_PENDING=0
GALLERY_PRIOR_PRESENT=0
OUT_BUILD_PENDING=0
OUT_PRIOR_PRESENT=0

move_new_out_to_attempt() {
  local failed_out="${DENEME_DIZIN}/out-after-failure"
  local suffix=2

  while [[ -e "$failed_out" || -L "$failed_out" ]]; do
    failed_out="${DENEME_DIZIN}/out-after-failure-${suffix}"
    ((suffix++))
  done

  mv "${PROJE}/out" "$failed_out"
}

restore_out_on_exit() {
  if [[ "$OUT_BUILD_PENDING" != "1" || -f "${DENEME_DIZIN}/result.txt" ]]; then
    return
  fi

  trap '' INT TERM HUP

  if [[ -e "$ONCEKI_OUT" || -L "$ONCEKI_OUT" ]]; then
    if [[ -e "${PROJE}/out" || -L "${PROJE}/out" ]]; then
      if ! move_new_out_to_attempt; then
        echo "HATA: Yeni export deneme klasorune tasinamadi; onceki export yedekte duruyor." >&2
        return
      fi
    fi

    if [[ -e "${PROJE}/out" || -L "${PROJE}/out" ]] || ! mv "$ONCEKI_OUT" "${PROJE}/out"; then
      echo "HATA: Onceki export geri yuklenemedi; yedek deneme klasorunde duruyor." >&2
    fi
  elif [[ "$OUT_PRIOR_PRESENT" == "0" && ( -e "${PROJE}/out" || -L "${PROJE}/out" ) ]]; then
    if ! move_new_out_to_attempt; then
      echo "HATA: Tamamlanmamis export deneme klasorune tasinamadi." >&2
    fi
  fi
}

restore_gallery_on_exit() {
  if [[ "$GALLERY_PUBLISH_PENDING" != "1" || -f "${DENEME_DIZIN}/result.txt" ]]; then
    return
  fi

  trap '' INT TERM HUP

  if [[ -e "$GALLERY_ONCEKI" || -L "$GALLERY_ONCEKI" ]]; then
    if [[ -e "$GALLERY_HEDEF" || -L "$GALLERY_HEDEF" ]]; then
      if ! mv "$GALLERY_HEDEF" "${DENEME_DIZIN}/gallery-after-failure"; then
        echo "HATA: Yeni onizleme deneme klasorune tasinamadi; onceki onizleme yedekte duruyor." >&2
        return
      fi
    fi

    if ! mv "$GALLERY_ONCEKI" "$GALLERY_HEDEF"; then
      echo "HATA: Onceki onizleme geri yuklenemedi; yedek deneme klasorunde duruyor." >&2
    fi
  elif [[ "$GALLERY_PRIOR_PRESENT" == "0" && ( -e "$GALLERY_HEDEF" || -L "$GALLERY_HEDEF" ) ]]; then
    if ! mv "$GALLERY_HEDEF" "${DENEME_DIZIN}/gallery-after-failure"; then
      echo "HATA: Yeni onizleme deneme klasorune tasinamadi." >&2
    fi
  fi

  if [[ -e "$GALLERY_GECICI" || -L "$GALLERY_GECICI" ]]; then
    if ! mv "$GALLERY_GECICI" "${DENEME_DIZIN}/gallery-staging-after-failure"; then
      echo "HATA: Tamamlanmamis onizleme deneme klasorune tasinamadi." >&2
    fi
  fi
}

trap 'restore_gallery_on_exit; restore_out_on_exit' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

echo "=========================================="
echo " BENCHFORGE MODEL DENEMESI"
echo "=========================================="
echo "Model: $MODEL"
echo "Klasor: $KLASOR"
echo "Mod: $MOD"

if [[ "$MOD" == "continue" ]]; then
  ONCEKI_CALISMA="${DENEME_DIZIN}/workspace-before"
  mkdir -p "$ONCEKI_CALISMA"
  if ! rsync -a \
    --exclude='.git' \
    --exclude='.next' \
    --exclude='node_modules' \
    --exclude='out' \
    "$PROJE/" \
    "$ONCEKI_CALISMA/"; then
    echo "HATA: Kaynak yedegi tamamlanamadi; model cagrilmadi."
    exit 74
  fi

  SESSION_TITLE="benchforge:${RUN_DIZIN:t}:${KLASOR}:${DENEME_DIZIN:t}"
  EVENT_LOG="${DENEME_DIZIN}/opencode.events.jsonl"
  STDERR_LOG="${DENEME_DIZIN}/opencode.stderr.log"
  MODEL_POLICY_DIZIN="${DENEME_DIZIN}/model-policy"

  # Deneme, ait oldugu kosunun calisma kosulunu kullanir; eski kosular eski ortamda surdurulur.
  ORTAM_PROFILI=$(awk -F= '$1 == "environment_profile" { print $2; exit }' "${RUN_DIZIN}/_meta.txt" 2>/dev/null)
  [[ "$ORTAM_PROFILI" == "isolated-v1" ]] || ORTAM_PROFILI="legacy"
  typeset -a POLICY_EKLERI=()
  SABLON_TALIMATI="${RUN_DIZIN}/_policies/template-instructions.md"

  if [[ "$ORTAM_PROFILI" == "isolated-v1" && -f "$SABLON_TALIMATI" && ! -L "$SABLON_TALIMATI" ]]; then
    POLICY_EKLERI=("$SABLON_TALIMATI")
  fi

  if ! node "$MODEL_POLICY" "$PROJE" "$KLASOR" continue "$MODEL_POLICY_DIZIN" "${POLICY_EKLERI[@]}"; then
    echo "HATA: Model calisma siniri hazirlanamadi; model cagrilmadi."
    exit 65
  fi

  (
    cd "$PROJE"
    node "$BOUNDARY_MONITOR" \
      --workspace "$PROJE" \
      --event-log "$EVENT_LOG" \
      --stderr-log "$STDERR_LOG" \
      --report "${DENEME_DIZIN}/boundary-report.json" \
      --policy-version "workspace-boundary-v1" \
      --opencode-config "$OPENCODE_CONFIG_DOSYASI" \
      --runtime-config "${MODEL_POLICY_DIZIN}/opencode-runtime.json" \
      --base-path "/${KLASOR}" \
      --environment-profile "$ORTAM_PROFILI" \
      -- opencode run \
      --dir "$PROJE" \
      --agent benchforge-workspace \
      --session "$KAYNAK_SESSION" \
      --fork \
      --model "$MODEL" \
      --title "$SESSION_TITLE" \
      --format json \
      "$(<"${MODEL_POLICY_DIZIN}/instructions.txt")"$'\n\n'"$(<"$PROMPT_DOSYASI")"
  )

  OPENCODE_RC=$?
  SONUC_SESSION=$(node "$EVENT_SESSION_SECICI" "$EVENT_LOG" 2>/dev/null)

  if [[ "$SONUC_SESSION" != ses_* ]]; then
    SONUC_SESSION=$(
      (
        cd "$PROJE"
        OPENCODE_CONFIG="$OPENCODE_CONFIG_DOSYASI" opencode session list --format json -n 200 --pure
      ) 2>/dev/null | node "$SESSION_SECICI" "$PROJE" "$SESSION_TITLE" 2>/dev/null
    )
  fi

  if [[ "$SONUC_SESSION" == ses_* ]]; then
    printf '%s\n' "$SONUC_SESSION" > "${DENEME_DIZIN}/session-id.txt"
  else
    SONUC_SESSION=""
  fi
fi

echo "Lint kontrolu basliyor."
(
  cd "$PROJE"
  npm run lint
) > "${DENEME_DIZIN}/lint.log" 2>&1
LINT_RC=$?

echo "Production ve static export build basliyor."
ONCEKI_OUT="${DENEME_DIZIN}/out-before"

if [[ -e "$ONCEKI_OUT" || -L "$ONCEKI_OUT" ]]; then
  echo "HATA: Onceki export yedek hedefi zaten dolu; build baslatilmadi."
  exit 74
fi

if [[ -e "${PROJE}/out" || -L "${PROJE}/out" ]]; then
  OUT_PRIOR_PRESENT=1
fi

OUT_BUILD_PENDING=1

if [[ "$OUT_PRIOR_PRESENT" == "1" ]]; then
  if ! mv "${PROJE}/out" "$ONCEKI_OUT"; then
    echo "HATA: Onceki export korunamadi; build baslatilmadi."
    exit 74
  fi
fi

(
  cd "$PROJE"
  NEXT_PUBLIC_BASE_PATH="/${KLASOR}" npm run build
) > "${DENEME_DIZIN}/build.log" 2>&1
BUILD_RC=$?

if [[ "$BUILD_RC" -eq 0 && -f "${PROJE}/out/index.html" && -d "${PROJE}/out/_next" ]]; then
  if grep -q "/${KLASOR}/_next/" "${PROJE}/out/index.html"; then
    EXPORT_RC=0
    : > "${DENEME_DIZIN}/export.log"
  else
    echo "Static export var fakat model-specific basePath bulunamadi." > "${DENEME_DIZIN}/export.log"
  fi
else
  echo "Gecerli Next.js static export bulunamadi." > "${DENEME_DIZIN}/export.log"
fi

if [[ "$EXPORT_RC" -eq 0 ]]; then
  SERVER_ROOT="${DENEME_DIZIN}/runtime"
  rm -rf "$SERVER_ROOT"
  mkdir -p "${SERVER_ROOT}/${KLASOR}"
  rsync -a "${PROJE}/out/" "${SERVER_ROOT}/${KLASOR}/"

  python3 -m http.server "$PORT" \
    --directory "$SERVER_ROOT" \
    > "${DENEME_DIZIN}/server.log" 2>&1 &
  SERVER_PID=$!
  BASE_URL="http://127.0.0.1:${PORT}/${KLASOR}"
  HAZIR=0

  for DENEME in {1..30}; do
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
      echo "HATA: Yerel dogrulama sunucusu erken kapandi." > "${DENEME_DIZIN}/server-error.log"
      break
    fi

    if curl -fsSL "${BASE_URL}/" >/dev/null 2>&1; then
      HAZIR=1
      break
    fi

    sleep 1
  done

  if [[ "$HAZIR" -eq 1 ]]; then
    ROUTES_RC=0
    : > "${DENEME_DIZIN}/routes.log"

    for ROTA in "${ROTA_DIZISI[@]}"; do
      if [[ "$ROTA" == "/" ]]; then
        URL="${BASE_URL}/"
      else
        URL="${BASE_URL}${ROTA}"
      fi

      HTTP_CODE=$(curl -L -s -o /dev/null -w "%{http_code}" "$URL")
      echo "$HTTP_CODE $ROTA" >> "${DENEME_DIZIN}/routes.log"

      if [[ "$HTTP_CODE" -lt 200 || "$HTTP_CODE" -ge 400 ]]; then
        ROUTES_RC=1
      fi
    done

    if [[ "${BENCHFORGE_SKIP_BROWSER_SMOKE:-0}" == "1" ]]; then
      echo "Browser runtime kontrolu bu yerel pilotta atlandi." > "${DENEME_DIZIN}/runtime.log"
      RUNTIME_RC="skip"
    else
      (
        cd "$BROWSER_SMOKE_DIZIN"
        node check.mjs "$BASE_URL" "${ROTA_DIZISI[@]}"
      ) > "${DENEME_DIZIN}/runtime.log" 2>&1
      RUNTIME_RC=$?
    fi
  fi

  kill "$SERVER_PID" >/dev/null 2>&1 || true
  wait "$SERVER_PID" >/dev/null 2>&1 || true
fi

BITIS=$(date +%s)
SURE=$((BITIS - BASLANGIC))

if [[ "$LINT_RC" == "0" && "$BUILD_RC" == "0" && "$EXPORT_RC" == "0" && "$ROUTES_RC" == "0" && "$RUNTIME_RC" == "0" \
      && ( "$MOD" == "validate" || "$OPENCODE_RC" == "0" ) ]]; then
  GALLERY_KOK="${RUN_DIZIN}/_gallery"
  GALLERY_HEDEF="${GALLERY_KOK}/${KLASOR}"
  GALLERY_GECICI="${GALLERY_KOK}/.${KLASOR}.${DENEME_DIZIN:t}.staging"
  GALLERY_ONCEKI="${DENEME_DIZIN}/gallery-before"

  if [[ -L "$GALLERY_KOK" ]]; then
    echo "HATA: Onizleme klasoru sembolik bag olamaz."
    exit 74
  fi

  if [[ -e "$GALLERY_GECICI" || -L "$GALLERY_GECICI" ]]; then
    echo "HATA: Onizleme gecici klasoru zaten var; eski onizleme korundu."
    exit 74
  fi

  if ! mkdir -p "$GALLERY_KOK" || ! mkdir "$GALLERY_GECICI" || ! rsync -a "${PROJE}/out/" "$GALLERY_GECICI/"; then
    echo "HATA: Yeni onizleme kopyalanamadi; eski onizleme korundu."
    exit 74
  fi

  if [[ -e "$GALLERY_HEDEF" || -L "$GALLERY_HEDEF" ]]; then
    GALLERY_PRIOR_PRESENT=1
  fi

  GALLERY_PUBLISH_PENDING=1

  if [[ "$GALLERY_PRIOR_PRESENT" == "1" ]]; then
    if ! mv "$GALLERY_HEDEF" "$GALLERY_ONCEKI"; then
      echo "HATA: Eski onizleme korunamadi."
      exit 74
    fi
  fi

  if ! mv "$GALLERY_GECICI" "$GALLERY_HEDEF"; then
    echo "HATA: Yeni onizleme yayinlanamadi; onceki onizleme geri yuklenecek."
    exit 74
  fi
else
  if [[ -e "${PROJE}/out" || -L "${PROJE}/out" ]]; then
    if ! move_new_out_to_attempt; then
      echo "HATA: Basarisiz export sonucu deneme klasorune tasinamadi."
      exit 74
    fi
  fi

  if [[ -e "$ONCEKI_OUT" || -L "$ONCEKI_OUT" ]]; then
    if [[ -e "${PROJE}/out" || -L "${PROJE}/out" ]] || ! mv "$ONCEKI_OUT" "${PROJE}/out"; then
      echo "HATA: Onceki export geri yuklenemedi."
      exit 74
    fi
  fi

  OUT_BUILD_PENDING=0
fi

if ! printf '%s|%s|%s|%s|%s|%s|%s|%s|%s|%s|%s\n' \
  "$MOD" \
  "$KAYNAK_SESSION" \
  "$SONUC_SESSION" \
  "$OPENCODE_RC" \
  "$LINT_RC" \
  "$BUILD_RC" \
  "$EXPORT_RC" \
  "$ROUTES_RC" \
  "$RUNTIME_RC" \
  "$SURE" \
  "$MODEL" \
  > "${DENEME_DIZIN}/result.tmp"; then
  echo "HATA: Deneme sonucu yazilamadi."
  exit 74
fi

if ! mv "${DENEME_DIZIN}/result.tmp" "${DENEME_DIZIN}/result.txt"; then
  echo "HATA: Deneme sonucu tamamlanamadi."
  exit 74
fi

GALLERY_PUBLISH_PENDING=0
OUT_BUILD_PENDING=0

echo "Deneme tamamlandi (${SURE}s)."
exit 0
