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
GALLERY_DIZIN="${CALISMA_DIZIN}/_gallery"

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

rm -rf "$GALLERY_DIZIN"
mkdir -p "$GALLERY_DIZIN"

cat > "${GALLERY_DIZIN}/index.html" <<HTML
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${TEST_ADI} — Benchmark Gallery</title>
<style>
* { box-sizing: border-box; }

body {
  margin: 0;
  background: #090a0c;
  color: #f5f5f5;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

main {
  width: min(1100px, calc(100% - 40px));
  margin: 0 auto;
  padding: 64px 0;
}

h1 {
  margin: 0;
  font-size: 36px;
  letter-spacing: -0.04em;
}

.subtitle {
  margin: 10px 0 40px;
  color: #8d929c;
}

.notice {
  padding: 14px 16px;
  margin-bottom: 30px;
  border: 1px solid #2b2d33;
  border-radius: 10px;
  color: #b8bdc7;
  background: #111216;
  font-size: 14px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 14px;
}

.card {
  display: block;
  padding: 20px;
  border: 1px solid #25272d;
  border-radius: 14px;
  background: #111216;
  color: inherit;
  text-decoration: none;
}

.card:hover {
  border-color: #525866;
  background: #15161b;
}

.model {
  font-size: 17px;
  font-weight: 650;
}

.path {
  margin-top: 9px;
  color: #8f96a3;
  font-size: 13px;
}

.status {
  display: inline-block;
  margin-top: 16px;
  padding: 5px 8px;
  border-radius: 6px;
  background: #10261b;
  color: #6ee7a8;
  font-size: 12px;
  font-weight: 700;
}
</style>
</head>
<body>
<main>
<h1>${TEST_ADI}</h1>
<p class="subtitle">Production benchmark gallery</p>

<div class="notice">
Bu katalog file:// üzerinden her zaman acilabilir.
Model uygulamalarini calistirmak icin serve-results.zsh kullanin.
</div>

<div class="grid">
HTML

while IFS='|' read -r KLASOR MODEL PORT; do
  [[ -z "$KLASOR" ]] && continue
  [[ "$KLASOR" == \#* ]] && continue

  KAYNAK="${CALISMA_DIZIN}/${KLASOR}"
  HEDEF="${GALLERY_DIZIN}/${KLASOR}"
  MODEL_GOSTERIM="${MODEL#opencode-go/}"

  if [[ ! -d "$KAYNAK" ]]; then
    echo "[$KLASOR] proje bulunamadi, atlandi."
    continue
  fi

  mkdir -p "$HEDEF"

  echo ""
  echo "[$KLASOR] static export hazirlaniyor..."

  # Orijinal benchmark koduna dokunmamak icin gecici kopya.
  GECICI="${CALISMA_DIZIN}/_export_tmp_${KLASOR}"
  rm -rf "$GECICI"

  rsync -a \
    --exclude='.git' \
    --exclude='.next' \
    "$KAYNAK/" \
    "$GECICI/"

  cat > "${GECICI}/next.config.ts" <<CONFIG
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
  basePath: "/${KLASOR}",
  assetPrefix: "/${KLASOR}",
};

export default nextConfig;
CONFIG

  (
    cd "$GECICI"

    if [[ ! -d node_modules ]]; then
      npm ci --prefer-offline --no-audit --no-fund
    fi

    npm run build
  )

  if [[ $? -eq 0 && -d "${GECICI}/out" ]]; then
    rsync -a "${GECICI}/out/" "$HEDEF/"
    echo "✓ $KLASOR export edildi"

    cat >> "${GALLERY_DIZIN}/index.html" <<HTML
<a class="card" href="http://localhost:8080/${KLASOR}/">
  <div class="model">${MODEL_GOSTERIM}</div>
  <div class="path">/${KLASOR}/</div>
  <span class="status">EXPORTED</span>
</a>
HTML

  else
    echo "✗ $KLASOR static export basarisiz"
  fi

  rm -rf "$GECICI"

done < "$MODEL_DOSYASI"

cat >> "${GALLERY_DIZIN}/index.html" <<'HTML'
</div>
</main>
</body>
</html>
HTML

echo ""
echo "=========================================="
echo " EXPORT TAMAMLANDI"
echo "=========================================="
echo ""
echo "Gallery:"
echo "${GALLERY_DIZIN}/index.html"
echo ""
echo "Kalici katalog:"
echo "file://${GALLERY_DIZIN}/index.html"
echo ""
