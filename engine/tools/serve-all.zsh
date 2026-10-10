#!/bin/zsh -f

set -u
source "${0:A:h}/benchforge-process-environment.zsh" || exit 78

# Veri koku: BENCHFORGE_DATA_ROOT, yoksa bu deponun engine/ klasoru.
ANA_DIZIN="${BENCHFORGE_DATA_ROOT:-${0:A:h:h}}"

if (( $# < 1 )); then
  echo "Kullanim:"
  echo "  $0 <test-adi>"
  exit 1
fi

TEST_ADI="$1"
CALISMA_DIZIN="${ANA_DIZIN}/runs/${TEST_ADI}"
MODEL_DOSYASI="${CALISMA_DIZIN}/_modeller.conf"
LOG_DIZIN="${CALISMA_DIZIN}/_serve_logs"
INDEX_DOSYASI="${CALISMA_DIZIN}/_index.html"

if [[ ! -d "$CALISMA_DIZIN" ]]; then
  echo "HATA: Test bulunamadi:"
  echo "$CALISMA_DIZIN"
  exit 1
fi

if [[ ! -f "$MODEL_DOSYASI" ]]; then
  echo "HATA: Model config bulunamadi:"
  echo "$MODEL_DOSYASI"
  exit 1
fi

mkdir -p "$LOG_DIZIN"

echo ""
echo "=========================================="
echo " RED QUEEN BENCHMARK SERVERS"
echo "=========================================="
echo ""
echo "Dev serverlar baslatiliyor..."
echo ""

while IFS='|' read -r KLASOR MODEL PORT; do
  [[ -z "$KLASOR" ]] && continue
  [[ "$KLASOR" == \#* ]] && continue

  DIZIN="${CALISMA_DIZIN}/${KLASOR}"

  if lsof -nP -iTCP:"${PORT}" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "[$KLASOR] Port ${PORT} zaten kullanimda. Simdilik atlandi."
    continue
  fi

  (
    cd "$DIZIN"
    npm run dev -- --port "$PORT"
  ) > "${LOG_DIZIN}/${KLASOR}.log" 2>&1 &

  echo "[$KLASOR] baslatiliyor -> http://localhost:${PORT}"

done < "$MODEL_DOSYASI"

echo ""
echo "Serverlarin hazirlanmasi bekleniyor..."
sleep 5

cat > "$INDEX_DOSYASI" <<'HTML'
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>RED QUEEN Benchmark</title>
<style>
  * { box-sizing: border-box; }

  body {
    margin: 0;
    background: #090a0c;
    color: #f3f4f6;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  }

  main {
    width: min(1100px, calc(100% - 40px));
    margin: 0 auto;
    padding: 64px 0;
  }

  h1 {
    margin: 0 0 8px;
    font-size: 34px;
    letter-spacing: -0.04em;
  }

  .subtitle {
    margin: 0 0 40px;
    color: #8b919c;
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 14px;
  }

  .card {
    display: block;
    padding: 20px;
    border: 1px solid #24262b;
    border-radius: 14px;
    background: #111216;
    color: inherit;
    text-decoration: none;
  }

  a.card:hover {
    border-color: #7f1d1d;
    background: #151216;
  }

  .model {
    font-size: 17px;
    font-weight: 650;
    margin-bottom: 10px;
  }

  .meta {
    color: #9ca3af;
    font-size: 13px;
    line-height: 1.6;
  }

  .status {
    display: inline-block;
    margin-top: 16px;
    padding: 5px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
  }

  .ok {
    background: #10261b;
    color: #6ee7a8;
  }

  .error {
    background: #2b1214;
    color: #fca5a5;
  }

  .blocked {
    background: #2a2110;
    color: #fcd34d;
  }
</style>
</head>
<body>
<main>
  <h1>RED QUEEN Benchmark</h1>
  <p class="subtitle">Model çıktıları ve yerel geliştirme sunucuları</p>
  <div class="grid">
HTML

while IFS='|' read -r KLASOR MODEL PORT; do
  [[ -z "$KLASOR" ]] && continue
  [[ "$KLASOR" == \#* ]] && continue

  DIZIN="${CALISMA_DIZIN}/${KLASOR}"
  MODEL_GOSTERIM="${MODEL#opencode-go/}"

  HAZIR=0
  CAKISMA=0

  PIDLER=("${(@f)$(lsof -tiTCP:"${PORT}" -sTCP:LISTEN 2>/dev/null | sort -u)}")

  for PID in "${PIDLER[@]}"; do
    [[ -z "$PID" ]] && continue

    CWD="$(lsof -a -p "$PID" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p' | head -1)"

    if [[ "$CWD" == "$DIZIN"* ]]; then
      HAZIR=1
    elif [[ -n "$CWD" ]]; then
      CAKISMA=1
    fi
  done

  if (( HAZIR == 1 )); then

    cat >> "$INDEX_DOSYASI" <<HTML
<a class="card" href="http://localhost:${PORT}" target="_blank">
  <div class="model">${MODEL_GOSTERIM}</div>
  <div class="meta">
    Klasor: ${KLASOR}<br>
    Port: ${PORT}
  </div>
  <span class="status ok">READY</span>
</a>
HTML

  elif (( CAKISMA == 1 )); then

    cat >> "$INDEX_DOSYASI" <<HTML
<div class="card">
  <div class="model">${MODEL_GOSTERIM}</div>
  <div class="meta">
    Klasor: ${KLASOR}<br>
    Port: ${PORT}
  </div>
  <span class="status blocked">PORT CONFLICT</span>
</div>
HTML

  else

    cat >> "$INDEX_DOSYASI" <<HTML
<div class="card">
  <div class="model">${MODEL_GOSTERIM}</div>
  <div class="meta">
    Klasor: ${KLASOR}<br>
    Port: ${PORT}<br>
    Log: _serve_logs/${KLASOR}.log
  </div>
  <span class="status error">FAILED</span>
</div>
HTML

  fi

done < "$MODEL_DOSYASI"

cat >> "$INDEX_DOSYASI" <<'HTML'
  </div>
</main>
</body>
</html>
HTML

echo ""
echo "=========================================="
echo " SERVER DURUMLARI"
echo "=========================================="
echo ""

while IFS='|' read -r KLASOR MODEL PORT; do
  [[ -z "$KLASOR" ]] && continue
  [[ "$KLASOR" == \#* ]] && continue

  DIZIN="${CALISMA_DIZIN}/${KLASOR}"
  MODEL_GOSTERIM="${MODEL#opencode-go/}"

  HAZIR=0

  PIDLER=("${(@f)$(lsof -tiTCP:"${PORT}" -sTCP:LISTEN 2>/dev/null | sort -u)}")

  for PID in "${PIDLER[@]}"; do
    [[ -z "$PID" ]] && continue

    CWD="$(lsof -a -p "$PID" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p' | head -1)"

    if [[ "$CWD" == "$DIZIN"* ]]; then
      HAZIR=1
    fi
  done

  if (( HAZIR == 1 )); then
    echo "✓ ${MODEL_GOSTERIM} -> http://localhost:${PORT}"
  else
    echo "✗ ${MODEL_GOSTERIM} -> ${PORT}"
  fi

done < "$MODEL_DOSYASI"

echo ""
echo "Benchmark index:"
echo "$INDEX_DOSYASI"
echo ""

open "$INDEX_DOSYASI"

echo "Index tarayicida acildi."
echo ""
