#!/bin/zsh

set -u

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

if [[ ! -f "$MODEL_DOSYASI" ]]; then
  echo "HATA: Test config bulunamadi:"
  echo "$MODEL_DOSYASI"
  exit 1
fi

echo ""
echo "Benchmark dev serverlari durduruluyor..."
echo ""

while IFS='|' read -r KLASOR MODEL PORT; do
  [[ -z "$KLASOR" ]] && continue
  [[ "$KLASOR" == \#* ]] && continue

  PIDLER=("${(@f)$(lsof -tiTCP:"${PORT}" -sTCP:LISTEN 2>/dev/null | sort -u)}")

  if (( ${#PIDLER} == 0 )); then
    continue
  fi

  for PID in "${PIDLER[@]}"; do
    [[ -z "$PID" ]] && continue

    CWD="$(lsof -a -p "$PID" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p' | head -1)"

    BEKLENEN="${CALISMA_DIZIN}/${KLASOR}"

    if [[ "$CWD" == "$BEKLENEN"* ]]; then
      kill "$PID" 2>/dev/null || true
      echo "✓ $KLASOR durduruldu (PID $PID)"
    else
      echo "! Port $PORT baska bir projeye ait. Dokunulmadi."
      echo "  PID: $PID"
      echo "  CWD: $CWD"
    fi
  done

done < "$MODEL_DOSYASI"

echo ""
echo "Tamamlandi."
echo ""
