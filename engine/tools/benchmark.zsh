#!/bin/zsh -f

set -u

SCRIPT_DIZIN="${0:A:h}"
# ANA_DIZIN: BenchForge deposundaki engine/ (araclar ve sablon).
# VERI_DIZIN: kosular, gorev metinleri ve model gruplari (BENCHFORGE_DATA_ROOT; yoksa engine/).
ANA_DIZIN="${SCRIPT_DIZIN:h}"
VERI_DIZIN="${BENCHFORGE_DATA_ROOT:-$ANA_DIZIN}"
source "${SCRIPT_DIZIN}/benchforge-process-environment.zsh" || exit 78
# Benchmark sirasinda Next.js anonim kullanim verisi gondermesin.
export NEXT_TELEMETRY_DISABLED=1

# Model adiminin ozel cikis kodlari:
#   75 saglayici hatasi (sunucu/kapasite); model puanlanmaz.
#   77 baglanti sonda koptu: saglayici hatasi geldi ama model bir teslim birakti; teslim puanlanir.
#   86 teslim yok: model sablonda hicbir dosyayi degistirmedi.
# Sinir izleyicisinin ozel kodu:
#   79 saglayici tekrar tekrar takildi (adim baslayip token gelmeden yeniden baslatildi); 75 veya 77'ye cevrilir.
SAGLAYICI_HATASI_KODU=75
SAGLAYICI_TAKILDI_KODU=79
BAGLANTI_SONDA_KOPTU_KODU=77
TESLIM_YOK_KODU=86
# Cikti sinirina takilip hicbir dosya yazmayan modele, ayni oturumda bir kez verilen standart devam mesaji.
OTOMATIK_DEVAM_MESAJI="Onceki yanitin cikti sinirina takildi ve calisma alaninda hicbir dosya degismedi. Gorevi surdur: dosyalari simdi, kucuk parcalar halinde (her yazma veya duzenlemede en fazla yaklasik 300 satir) olustur ve calisan kodu erkenden kaydet."

# Dogrulama bittikten sonra calisma alani hafifletilir: yeniden kurulabilen bagimliliklar (node_modules) ve
# build onbellegi (.next) silinir; modelin kaynak kodu, out/ ve kilit dosyasi kalir. Kurtarma denemesi
# baslarken bagimliliklar yeniden kurulur.
calisma_alanini_hafiflet() {
  rm -rf "${1:?}/node_modules" "${1:?}/.next"
}

# Sinir izleyicisinin "BENCHFORGE_STALLS" satirlarini toplar: "<adet> <saniye> <sureye_eklenen_saniye>".
saglayici_takilmalari() {
  local DOSYA SATIR ADET=0 SANIYE=0 TELAFI=0
  for DOSYA in "$@"; do
    [[ -f "$DOSYA" ]] || continue
    SATIR=$(grep -a -o 'BENCHFORGE_STALLS count=[0-9]* seconds=[0-9]* compensated=[0-9]*' "$DOSYA" | tail -1)
    [[ -n "$SATIR" ]] || continue
    ADET=$(( ADET + ${${SATIR##*count=}%% *} ))
    SANIYE=$(( SANIYE + ${${SATIR##*seconds=}%% *} ))
    TELAFI=$(( TELAFI + ${SATIR##*compensated=} ))
  done
  echo "$ADET $SANIYE $TELAFI"
}

# Calisma alani baslangic sablonuyla ayniysa (model hicbir sey teslim etmediyse) basarili doner.
sablon_ayni() {
  diff -rq -x node_modules -x .next -x out -x next-env.d.ts -x '*.tsbuildinfo' -x .DS_Store \
    "$SABLON" "$1" >/dev/null 2>&1
}

if [[ "$VERI_DIZIN" != /* || ! -d "$VERI_DIZIN" ]]; then
  echo "HATA: Veri koku mutlak ve var olan bir klasor olmali -> $VERI_DIZIN"
  exit 1
fi

if (( $# < 4 )); then
  echo "Kullanim:"
  echo "  $0 <test-adi> <prompt-dosyasi> <model-config> <route-listesi> [opencode-config]"
  echo ""
  echo 'Ornek route listesi: "/,/motorcycles,/configure,/cart"'
  exit 1
fi

TEST_ADI="$1"
PROMPT_GIRIS="$2"
CONFIG_GIRIS="$3"
ROTA_CSV="$4"
OPENCODE_CONFIG_GIRIS="${5:-${VERI_DIZIN}/config/opencode-benchmark.json}"

[[ "$PROMPT_GIRIS" = /* ]] \
  && PROMPT_DOSYASI="$PROMPT_GIRIS" \
  || PROMPT_DOSYASI="${VERI_DIZIN}/${PROMPT_GIRIS}"

[[ "$CONFIG_GIRIS" = /* ]] \
  && MODEL_CONFIG="$CONFIG_GIRIS" \
  || MODEL_CONFIG="${VERI_DIZIN}/${CONFIG_GIRIS}"

[[ "$OPENCODE_CONFIG_GIRIS" = /* ]] \
  && OPENCODE_CONFIG_DOSYASI="$OPENCODE_CONFIG_GIRIS" \
  || OPENCODE_CONFIG_DOSYASI="${VERI_DIZIN}/${OPENCODE_CONFIG_GIRIS}"

SABLON="${ANA_DIZIN}/sablon"
RUN_DIZIN="${VERI_DIZIN}/runs/${TEST_ADI}"
LOG_DIZIN="${RUN_DIZIN}/_logs"
RESULT_DIZIN="${RUN_DIZIN}/_results"
RUNTIME_DIZIN="${RUN_DIZIN}/_runtime"
GALLERY_DIZIN="${RUN_DIZIN}/_gallery"
SESSION_DIZIN="${RUN_DIZIN}/_sessions"
BROWSER_SMOKE="${ANA_DIZIN}/tools/browser-smoke/check.mjs"
BROWSER_SMOKE_DIZIN="${ANA_DIZIN}/tools/browser-smoke"
SESSION_SECICI="${ANA_DIZIN}/tools/select-opencode-session.mjs"
MODEL_POLICY="${ANA_DIZIN}/tools/benchforge-model-policy.mjs"
HATA_SINIFLANDIRICI="${ANA_DIZIN}/tools/classify-opencode-failure.mjs"
# Takilan bir model tek kosu yuvasini tutmasin ve token harcamaya devam etmesin.
MODEL_SURE_SINIRI_SANIYE=3600
# Modelin calisma kosulu: kullanicinin global OpenCode ayarlarindan yalitilmis ortam ve sablon talimati.
ORTAM_PROFILI="isolated-v1"
BOUNDARY_MONITOR="${BENCHFORGE_BOUNDARY_MONITOR:-}"
POLICY_DIZIN="${RUN_DIZIN}/_policies"
BOUNDARY_DIZIN="${RUN_DIZIN}/_boundary"

ROTA_DIZISI=("${(@s:,:)ROTA_CSV}")

for DOSYA in "$SABLON" "$PROMPT_DOSYASI" "$MODEL_CONFIG" "$OPENCODE_CONFIG_DOSYASI"; do
  if [[ ! -e "$DOSYA" ]]; then
    echo "HATA: Bulunamadi -> $DOSYA"
    exit 1
  fi
done

for KOMUT in node npm opencode rsync curl python3 shasum; do
  if ! command -v "$KOMUT" >/dev/null 2>&1; then
    echo "HATA: Komut bulunamadi -> $KOMUT"
    exit 1
  fi
done

if [[ ! -f "$BROWSER_SMOKE" || ! -f "$SESSION_SECICI" || ! -f "$MODEL_POLICY" || ! -f "$HATA_SINIFLANDIRICI" ]]; then
  echo "HATA: Benchmark yardimci araci bulunamadi."
  exit 1
fi

if [[ -z "$BOUNDARY_MONITOR" || ! -f "$BOUNDARY_MONITOR" ]]; then
  echo "HATA: BENCHFORGE_BOUNDARY_MONITOR, BenchForge/bin/model-boundary-monitor.mjs dosyasinin tam yoluna ayarlanmali."
  exit 1
fi

mkdir -p "$RUN_DIZIN" "$LOG_DIZIN" "$RESULT_DIZIN" "$RUNTIME_DIZIN" "$SESSION_DIZIN" "$POLICY_DIZIN" "$BOUNDARY_DIZIN"

cp "$PROMPT_DOSYASI" "${RUN_DIZIN}/_prompt.txt"
cp "$MODEL_CONFIG" "${RUN_DIZIN}/_modeller.conf"
cp "$OPENCODE_CONFIG_DOSYASI" "${RUN_DIZIN}/_opencode.json"

# Sablon talimatinin bu kosuya ait kopyasi; kurtarma denemeleri de ayni metni kullanir.
SABLON_TALIMATI="${POLICY_DIZIN}/template-instructions.md"
SABLON_TALIMATI_SHA="none"

if [[ -f "${SABLON}/AGENTS.md" && ! -L "${SABLON}/AGENTS.md" ]]; then
  cp "${SABLON}/AGENTS.md" "$SABLON_TALIMATI"
  chmod 600 "$SABLON_TALIMATI"
  SABLON_TALIMATI_SHA=$(shasum -a 256 "$SABLON_TALIMATI" | awk '{print $1}')
fi

typeset -a KLASORLER
typeset -a MODELLER
typeset -a PORTLAR
typeset -a PIDLER

while IFS='|' read -r KLASOR MODEL PORT; do
  [[ -z "$KLASOR" ]] && continue
  [[ "$KLASOR" == \#* ]] && continue

  KLASORLER+=("$KLASOR")
  MODELLER+=("$MODEL")
  PORTLAR+=("$PORT")
done < "$MODEL_CONFIG"

MAX_PARALEL=$(awk -F= '/^#[[:space:]]*max_parallel=/ {gsub(/[[:space:]]/, "", $2); print $2; exit}' "$MODEL_CONFIG")

if [[ -z "$MAX_PARALEL" ]]; then
  MAX_PARALEL=${#KLASORLER}
fi

if [[ "$MAX_PARALEL" != <-> ]] || (( MAX_PARALEL < 1 )); then
  echo "HATA: Gecersiz max_parallel degeri -> $MAX_PARALEL"
  exit 1
fi

{
  echo "test=${TEST_ADI}"
  echo "date=$(date)"
  echo "node=$(node -v)"
  echo "npm=$(npm -v)"
  echo "opencode=$(opencode --version)"
  echo "routes=${ROTA_CSV}"
  echo "prompt_sha256=$(shasum -a 256 "$PROMPT_DOSYASI" | awk '{print $1}')"
  echo "models_sha256=$(shasum -a 256 "$MODEL_CONFIG" | awk '{print $1}')"
  echo "opencode_config_sha256=$(shasum -a 256 "$OPENCODE_CONFIG_DOSYASI" | awk '{print $1}')"
  echo "providers=$(printf '%s\n' "${MODELLER[@]}" | cut -d/ -f1 | sort -u | paste -sd, -)"
  echo "max_parallel=${MAX_PARALEL}"
  echo "environment_profile=${ORTAM_PROFILI}"
  echo "template_instructions_sha256=${SABLON_TALIMATI_SHA}"
  echo "model_timeout_seconds=${MODEL_SURE_SINIRI_SANIYE}"
  awk '/^#[[:space:]]*openrouter_(routing|requested_providers)=/ { sub(/^#[[:space:]]*/, ""); print }' "$MODEL_CONFIG"
} > "${RUN_DIZIN}/_meta.txt"

echo ""
echo "=========================================="
echo " FRONTEND MODEL BENCHMARK V2"
echo "=========================================="
echo ""
echo "Test:      $TEST_ADI"
echo "Node:      $(node -v)"
echo "OpenCode:  $(opencode --version)"
echo "Model:     ${#KLASORLER} adet"
echo "Kontrol:   lint + export build + routes + browser runtime"
echo ""

echo "Projeler hazirlaniyor..."

for (( i = 1; i <= ${#KLASORLER}; i++ )); do
  KLASOR="${KLASORLER[$i]}"
  PROJE="${RUN_DIZIN}/${KLASOR}"

  echo "[$KLASOR] npm ci"

  rm -rf "$PROJE"
  mkdir -p "$PROJE"

  rsync -a \
    --exclude='.git' \
    --exclude='.next' \
    --exclude='node_modules' \
    "$SABLON/" \
    "$PROJE/"

  (
    cd "$PROJE"
    npm ci --prefer-offline --no-audit --no-fund
  ) > "${LOG_DIZIN}/${KLASOR}.npm-ci.log" 2>&1

  if [[ $? -ne 0 ]]; then
    echo "HATA: $KLASOR npm ci basarisiz."
    exit 1
  fi
done

PROMPT_METNI="$(cat "$PROMPT_DOSYASI")"

model_calistir() {
  local KLASOR="$1"
  local MODEL="$2"
  local PORT="$3"

  local PROJE="${RUN_DIZIN}/${KLASOR}"
  local BASLANGIC=$(date +%s)

  local OPENCODE_RC=1
  local LINT_RC=1
  local BUILD_RC=1
  local EXPORT_RC=1
  local ROUTES_RC=1
  local RUNTIME_RC=1

  echo "[$(date +%H:%M:%S)] $KLASOR basladi."

  local SESSION_TITLE="benchforge:${TEST_ADI}:${KLASOR}:initial"
  local MODEL_POLICY_DIZIN="${POLICY_DIZIN}/${KLASOR}"
  local EVENT_LOG="${LOG_DIZIN}/${KLASOR}.opencode.events.jsonl"
  local STDERR_LOG="${LOG_DIZIN}/${KLASOR}.opencode.stderr.log"

  local -a POLICY_EKLERI=()
  [[ -f "$SABLON_TALIMATI" ]] && POLICY_EKLERI=("$SABLON_TALIMATI")

  if node "$MODEL_POLICY" "$PROJE" "$KLASOR" initial "$MODEL_POLICY_DIZIN" "${POLICY_EKLERI[@]}" \
    > "${LOG_DIZIN}/${KLASOR}.model-policy.log" 2>&1; then
    (
      cd "$PROJE"
      node "$BOUNDARY_MONITOR" \
        --workspace "$PROJE" \
        --event-log "$EVENT_LOG" \
        --stderr-log "$STDERR_LOG" \
        --report "${BOUNDARY_DIZIN}/${KLASOR}.json" \
        --policy-version "workspace-boundary-v1" \
        --opencode-config "$OPENCODE_CONFIG_DOSYASI" \
        --runtime-config "${MODEL_POLICY_DIZIN}/opencode-runtime.json" \
        --base-path "/${KLASOR}" \
        --timeout-seconds "$MODEL_SURE_SINIRI_SANIYE" \
        --environment-profile "$ORTAM_PROFILI" \
        -- opencode run \
        --agent benchforge-workspace \
        --model "$MODEL" \
        --title "$SESSION_TITLE" \
        --format json \
        "$(<"${MODEL_POLICY_DIZIN}/instructions.txt")"$'\n\n'"$PROMPT_METNI"
    ) > "${LOG_DIZIN}/${KLASOR}.opencode.log" 2>&1

    OPENCODE_RC=$?
  else
    echo "HATA: $KLASOR calisma siniri hazirlanamadi; model cagrilmadi."
    OPENCODE_RC=65
  fi

  local SESSION_ID
  SESSION_ID=$(
    (
      cd "$PROJE"
      OPENCODE_CONFIG="$OPENCODE_CONFIG_DOSYASI" opencode session list --format json -n 200 --pure
    ) 2>/dev/null | node "$SESSION_SECICI" "$PROJE" "$SESSION_TITLE" 2>/dev/null
  )

  if [[ "$SESSION_ID" == ses_* ]]; then
    printf '%s\n' "$SESSION_ID" > "${SESSION_DIZIN}/${KLASOR}.session"
  fi

  # Model cikti sinirina takilip hicbir sey yazmadan durduysa ayni oturumda bir kez otomatik devam edilir.
  # Kural her modele ayni uygulanir; kaydi <model>.auto-continue.log dosyasindadir.
  if [[ "$OPENCODE_RC" == "0" && "$SESSION_ID" == ses_* ]] && sablon_ayni "$PROJE" \
      && [[ "$(node "$HATA_SINIFLANDIRICI" --stop-reason "$EVENT_LOG" 2>/dev/null)" == "length" ]]; then
    # Saglayici takilmasinda gecen sure modelin suresinden sayilmaz.
    local -a ILK_TAKILMA=(${=$(saglayici_takilmalari "${LOG_DIZIN}/${KLASOR}.opencode.log")})
    local KALAN_SURE=$(( MODEL_SURE_SINIRI_SANIYE - ($(date +%s) - BASLANGIC) + ILK_TAKILMA[3] ))

    if (( KALAN_SURE >= 120 )); then
      echo "[$KLASOR] Cikti siniri: model hicbir dosya yazmadan durdu; ayni oturumda bir kez otomatik devam ediliyor."
      printf 'reason=output-limit\nsession=%s\nremaining_seconds=%s\nmessage=%s\n' \
        "$SESSION_ID" "$KALAN_SURE" "$OTOMATIK_DEVAM_MESAJI" > "${LOG_DIZIN}/${KLASOR}.auto-continue.log"
      EVENT_LOG="${LOG_DIZIN}/${KLASOR}.opencode.continue.events.jsonl"
      (
        cd "$PROJE"
        node "$BOUNDARY_MONITOR" \
          --workspace "$PROJE" \
          --event-log "$EVENT_LOG" \
          --stderr-log "${LOG_DIZIN}/${KLASOR}.opencode.continue.stderr.log" \
          --report "${BOUNDARY_DIZIN}/${KLASOR}.continue.json" \
          --policy-version "workspace-boundary-v1" \
          --opencode-config "$OPENCODE_CONFIG_DOSYASI" \
          --runtime-config "${MODEL_POLICY_DIZIN}/opencode-runtime.json" \
          --base-path "/${KLASOR}" \
          --timeout-seconds "$KALAN_SURE" \
          --environment-profile "$ORTAM_PROFILI" \
          -- opencode run \
          --agent benchforge-workspace \
          --model "$MODEL" \
          --session "$SESSION_ID" \
          --format json \
          "$OTOMATIK_DEVAM_MESAJI"
      ) > "${LOG_DIZIN}/${KLASOR}.opencode.continue.log" 2>&1

      OPENCODE_RC=$?
    fi
  fi

  # Saglayici takilmalari: tekrar eden takilma (79) ya da suresinin yarisindan fazlasi takilmayla gecen
  # sure asimi modelin hatasi sayilmaz.
  local -a TAKILMA=(${=$(saglayici_takilmalari "${LOG_DIZIN}/${KLASOR}.opencode.log" "${LOG_DIZIN}/${KLASOR}.opencode.continue.log")})
  local MODEL_GECEN=$(( $(date +%s) - BASLANGIC ))
  if (( TAKILMA[1] > 0 )); then
    echo "[$KLASOR] Saglayici ${TAKILMA[1]} kez yanit vermedi (${TAKILMA[2]} sn); ${TAKILMA[3]} sn model suresine eklendi."
  fi
  if [[ "$OPENCODE_RC" == "$SAGLAYICI_TAKILDI_KODU" ]] \
    || { [[ "$OPENCODE_RC" == "124" ]] && (( TAKILMA[2] * 2 >= MODEL_GECEN )); }; then
    if sablon_ayni "$PROJE"; then
      echo "[$KLASOR] Saglayici takildi: model cagrisi saglayici tarafinda yanit alamadi; puanlanmaz."
      OPENCODE_RC=$SAGLAYICI_HATASI_KODU
    else
      echo "[$KLASOR] Saglayici takildi ama model bir teslim birakti; teslim puanlanir."
      OPENCODE_RC=$BAGLANTI_SONDA_KOPTU_KODU
    fi
  elif [[ "$OPENCODE_RC" != "0" && "$OPENCODE_RC" != "65" && "$OPENCODE_RC" != "78" && "$OPENCODE_RC" != "124" ]] \
    && [[ "$(node "$HATA_SINIFLANDIRICI" "$EVENT_LOG" 2>/dev/null)" == "provider" ]]; then
    if sablon_ayni "$PROJE"; then
      echo "[$KLASOR] Saglayici hatasi: model cagrisi saglayici tarafinda basarisiz oldu; puanlanmaz."
      OPENCODE_RC=$SAGLAYICI_HATASI_KODU
    else
      echo "[$KLASOR] Baglanti sonda koptu: saglayici hatasi geldi ama model bir teslim birakti; teslim puanlanir."
      OPENCODE_RC=$BAGLANTI_SONDA_KOPTU_KODU
    fi
  elif [[ "$OPENCODE_RC" == "0" ]] && sablon_ayni "$PROJE"; then
    echo "[$KLASOR] Teslim yok: model sablonda hicbir dosyayi degistirmedi."
    OPENCODE_RC=$TESLIM_YOK_KODU
  fi

  echo "[$(date +%H:%M:%S)] $KLASOR: OpenCode tamamlandi. Kontroller basliyor."

  (
    cd "$PROJE"
    npm run lint
  ) > "${LOG_DIZIN}/${KLASOR}.lint.log" 2>&1

  LINT_RC=$?

  (
    cd "$PROJE"
    NEXT_PUBLIC_BASE_PATH="/${KLASOR}" npm run build
  ) > "${LOG_DIZIN}/${KLASOR}.build.log" 2>&1

  BUILD_RC=$?

  if [[ "$BUILD_RC" -eq 0 \
        && -f "${PROJE}/out/index.html" \
        && -d "${PROJE}/out/_next" ]]; then

    if grep -q "/${KLASOR}/_next/" "${PROJE}/out/index.html"; then
      EXPORT_RC=0
    else
      echo "Static export var fakat model-specific basePath bulunamadi." \
        > "${LOG_DIZIN}/${KLASOR}.export.log"
      EXPORT_RC=1
    fi
  else
    echo "Gecerli Next.js static export bulunamadi." \
      > "${LOG_DIZIN}/${KLASOR}.export.log"
    EXPORT_RC=1
  fi

  if [[ "$EXPORT_RC" -eq 0 ]]; then
    local SERVER_ROOT="${RUNTIME_DIZIN}/${KLASOR}"

    rm -rf "$SERVER_ROOT"
    mkdir -p "$SERVER_ROOT"

    ln -s "${PROJE}/out" "${SERVER_ROOT}/${KLASOR}"

    python3 -m http.server "$PORT" \
      --directory "$SERVER_ROOT" \
      > "${LOG_DIZIN}/${KLASOR}.server.log" 2>&1 &

    local SERVER_PID=$!
    local BASE_URL="http://127.0.0.1:${PORT}/${KLASOR}"

    local HAZIR=0

    for DENEME in {1..30}; do
      if curl -fsSL "${BASE_URL}/" >/dev/null 2>&1; then
        HAZIR=1
        break
      fi

      sleep 1
    done

    if [[ "$HAZIR" -eq 1 ]]; then
      ROUTES_RC=0

      : > "${LOG_DIZIN}/${KLASOR}.routes.log"

      for ROTA in "${ROTA_DIZISI[@]}"; do
        if [[ "$ROTA" == "/" ]]; then
          URL="${BASE_URL}/"
        else
          URL="${BASE_URL}${ROTA}"
        fi

        HTTP_CODE=$(curl -L -s \
          -o /dev/null \
          -w "%{http_code}" \
          "$URL")

        echo "$HTTP_CODE $ROTA" \
          >> "${LOG_DIZIN}/${KLASOR}.routes.log"

        if [[ "$HTTP_CODE" -lt 200 || "$HTTP_CODE" -ge 400 ]]; then
          ROUTES_RC=1
        fi
      done

      (
        cd "$BROWSER_SMOKE_DIZIN"
        node check.mjs "$BASE_URL" "${ROTA_DIZISI[@]}"
      ) > "${LOG_DIZIN}/${KLASOR}.runtime.log" 2>&1

      RUNTIME_RC=$?
    fi

    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
  fi

  local BITIS=$(date +%s)
  local SURE=$((BITIS - BASLANGIC))

  echo "${KLASOR}|${MODEL}|${OPENCODE_RC}|${LINT_RC}|${BUILD_RC}|${EXPORT_RC}|${ROUTES_RC}|${RUNTIME_RC}|${SURE}" \
    > "${RESULT_DIZIN}/${KLASOR}.result"

  # Ilk teslimin onizlemesi, asama sonucu ne olursa olsun sonuc arsivine (_gallery) kopyalanir; calisma alani
  # hafifletildiginde ya da silindiginde onizleme ve puanlama bu kopyadan devam eder.
  if [[ -f "${PROJE}/out/index.html" ]]; then
    mkdir -p "${GALLERY_DIZIN}/${KLASOR}" && rsync -a --delete "${PROJE}/out/" "${GALLERY_DIZIN}/${KLASOR}/"
  fi

  calisma_alanini_hafiflet "$PROJE"

  echo "[$(date +%H:%M:%S)] $KLASOR bitti (${SURE}s)."
}

echo ""
echo "=========================================="
echo " ${#KLASORLER} MODEL PARALEL BASLATILIYOR (LIMIT: ${MAX_PARALEL})"
echo "=========================================="
echo ""

for (( i = 1; i <= ${#KLASORLER}; i++ )); do
  model_calistir \
    "${KLASORLER[$i]}" \
    "${MODELLER[$i]}" \
    "${PORTLAR[$i]}" &

  PIDLER+=($!)

  if (( ${#PIDLER} >= MAX_PARALEL )); then
    for PID in "${PIDLER[@]}"; do
      wait "$PID"
    done

    PIDLER=()
  elif (( i < ${#KLASORLER} )); then
    sleep 2
  fi
done

echo ""
echo "Tum modeller calisiyor."
echo "Terminali kapatmayin."

for PID in "${PIDLER[@]}"; do
  wait "$PID"
done

SUMMARY="${RUN_DIZIN}/_summary.tsv"

echo "klasor|model|opencode|lint|build|export|routes|runtime|sure_sn" \
  > "$SUMMARY"

for (( i = 1; i <= ${#KLASORLER}; i++ )); do
  KLASOR="${KLASORLER[$i]}"

  if [[ -f "${RESULT_DIZIN}/${KLASOR}.result" ]]; then
    cat "${RESULT_DIZIN}/${KLASOR}.result" >> "$SUMMARY"
  fi
done

mkdir -p "$GALLERY_DIZIN"

cat > "${GALLERY_DIZIN}/index.html" <<HTML
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>${TEST_ADI} — Benchmark Gallery</title>
<style>
body{margin:0;background:#0b0c0f;color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
main{width:min(1100px,calc(100% - 40px));margin:auto;padding:64px 0}
h1{font-size:36px;margin:0 0 8px}
p{color:#9298a3;margin:0 0 36px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}
a,.fail{display:block;padding:20px;border:1px solid #282b32;border-radius:12px;background:#121318;color:inherit;text-decoration:none}
a:hover{border-color:#5c6472}
strong{display:block;font-size:17px}
small{display:block;margin-top:8px;color:#9298a3}
.ok{color:#64d998}.bad{color:#ef7777}
</style>
</head>
<body>
<main>
<h1>${TEST_ADI}</h1>
<p>Production/static benchmark results</p>
<div class="grid">
HTML

for (( i = 1; i <= ${#KLASORLER}; i++ )); do
  KLASOR="${KLASORLER[$i]}"
  MODEL="${MODELLER[$i]}"
  RESULT="${RESULT_DIZIN}/${KLASOR}.result"

  [[ ! -f "$RESULT" ]] && continue

  IFS='|' read -r _ _ OP LI BU EX RO RU SU < "$RESULT"

  if [[ "$OP" -eq 0 && "$LI" -eq 0 && "$BU" -eq 0 && "$EX" -eq 0 && "$RO" -eq 0 && "$RU" -eq 0 ]]; then
    cat >> "${GALLERY_DIZIN}/index.html" <<HTML
<a href="/${KLASOR}/">
<strong>${MODEL#*/}</strong>
<small class="ok">PASS · ${SU}s</small>
</a>
HTML
  else
    cat >> "${GALLERY_DIZIN}/index.html" <<HTML
<div class="fail">
<strong>${MODEL#*/}</strong>
<small class="bad">FAIL · O:${OP} L:${LI} B:${BU} E:${EX} R:${RO} RT:${RU}</small>
</div>
HTML
  fi
done

cat >> "${GALLERY_DIZIN}/index.html" <<'HTML'
</div>
</main>
</body>
</html>
HTML

echo ""
echo "=========================================="
echo " BENCHMARK TAMAMLANDI"
echo "=========================================="
echo ""

column -t -s '|' "$SUMMARY"

echo ""
echo "Sonuclar:"
echo "$RUN_DIZIN"
echo ""
echo "Gallery:"
echo "$GALLERY_DIZIN"
echo ""
echo "0 = basarili"
echo "0 disindaki deger = ilgili asama basarisiz"
