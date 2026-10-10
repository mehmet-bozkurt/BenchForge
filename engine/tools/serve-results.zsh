#!/bin/zsh -f

set -u
source "${0:A:h}/benchforge-process-environment.zsh" || exit 78

# Veri koku: BENCHFORGE_DATA_ROOT, yoksa bu deponun engine/ klasoru.
ANA_DIZIN="${BENCHFORGE_DATA_ROOT:-${0:A:h:h}}"
PORT=8080

if (( $# < 1 )); then
  echo "Kullanim:"
  echo "  $0 <test-adi>"
  exit 1
fi

TEST_ADI="$1"
GALLERY_DIZIN="${ANA_DIZIN}/runs/${TEST_ADI}/_gallery"

if [[ ! -d "$GALLERY_DIZIN" ]]; then
  echo "HATA: Gallery bulunamadi."
  echo ""
  echo "Once:"
  echo "  ./tools/export-results.zsh ${TEST_ADI}"
  exit 1
fi

if lsof -nP -iTCP:${PORT} -sTCP:LISTEN >/dev/null 2>&1; then
  echo "Port ${PORT} zaten kullanimda."
  echo "http://localhost:${PORT}"
  open "http://localhost:${PORT}"
  exit 0
fi

echo ""
echo "Production gallery baslatiliyor..."
echo ""

cd "$GALLERY_DIZIN"

python3 -m http.server "$PORT" \
  > "${GALLERY_DIZIN}/server.log" 2>&1 &

PID=$!

echo "$PID" > "${GALLERY_DIZIN}/server.pid"

sleep 1

if lsof -nP -iTCP:${PORT} -sTCP:LISTEN >/dev/null 2>&1; then
  echo "✓ Gallery hazir"
  echo ""
  echo "http://localhost:${PORT}"
  echo ""
  open "http://localhost:${PORT}"
else
  echo "HATA: Server baslatilamadi."
  exit 1
fi
