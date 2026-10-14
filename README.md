# BenchForge

[![CI](https://github.com/mehmet-bozkurt/BenchForge/actions/workflows/ci.yml/badge.svg)](https://github.com/mehmet-bozkurt/BenchForge/actions/workflows/ci.yml)

BenchForge, yapay zekâ kodlama modellerini gerçek ürün geliştirmeye benzeyen görevlerde karşılaştıran, yerelde çalışan bir değerlendirme ortamıdır. Aynı görev metni ve aynı başlangıç projesiyle birden fazla model çalıştırılır. Teslimler teknik kontroller, süre, token, maliyet ve insan puanıyla yan yana incelenir.

> **Durum:** Erken aşamada, tek kullanıcılı yerel bir araç. Arayüz Türkçedir.

## Ne yapar?

- **Kod benchmarkı:** Seçtiğiniz 1–5 modeli [OpenCode](https://opencode.ai) üzerinden aynı görevle çalıştırır. Her teslim altı aşamada doğrulanır: model adımı, lint, production build, static export, rota kontrolü ve tarayıcıda çalışma testi.
- **Model katalogları:** OpenRouter ve OpenCode Go kataloglarından model seçimi; OpenRouter için sağlayıcı yönlendirme tercihleri (otomatik, fiyat, hız, sabit endpoint).
- **Önizleme ve karşılaştırma:** Üretilen siteleri aynı rota ve ekran genişliğinde yan yana görüntüleme. Önizlemeler sandbox'lı çerçevede ve CSP sandbox başlığıyla sunulur.
- **İnsan puanlaması:** İlk teslimin çıktısı yedi ölçütte ve genel olarak 1–10 puanlanır. Kurtarma denemesiyle düzeltilmiş çıktı ayrı görünümde incelenir, puanlanmaz.
- **Kurtarma denemeleri:** Başarısız bir modeli kaldığı oturumdan sürdürme ("devam") veya yalnız doğrulamaları tekrarlama. İlk teslimin sonucu ve puanı değişmez.
- **Liderlik tablosu:** Model kimliğine göre gruplanır; %60 teknik başarı (koşu başına aşama oranlarının ortalaması) ve %40 insan puanı. Farklı çalışma koşullarında üretilmiş sonuçlar karıştırılmaz.
- **Kullanım ve maliyet:** Token ve maliyet OpenCode'un yerel veritabanından okunur. Eksik veri sıfır diye gösterilmez.
- **Hazırlık paketi karşılaştırması:** Aynı müşteri şartnamesinden modellerin ürettiği başlangıç promptu, gereksinimler, görev planı, hafıza bankası ve varsayımları yan yana karşılaştırma. Bu akışta model araç kullanmaz, yalnız metin üretir.
- **Sistem ve Kurulum ekranı:** Araç sürümleri, yollar, yazma izinleri ve veritabanı kontrolleri. Benchmark kökü ve araç yolları arayüzden ayarlanabilir.

## Nasıl çalışır?

```
Laravel arayüzü ──► Node işçileri (bin/) ──► engine/tools/benchmark.zsh ──► OpenCode CLI ──► model
      ▲                                              │
      └──── SQLite ◄── arşiv senkronu ◄── <veri kökü>/runs/<koşu>/ (çalışma alanları, günlükler, sonuçlar)
```

### Klasör yapısı

| Yol | İçerik |
| --- | --- |
| `app/`, `resources/`, `routes/` | Laravel arayüzü |
| `bin/` | Arka plan işçileri ve model sınır izleyicisi (Node.js) |
| `engine/tools/` | Koşucu ve doğrulama betikleri (zsh, Node.js, Playwright) |
| `engine/sablon/` | Her modele kopyalanan başlangıç projesi (Next.js) |
| `engine/config/` | OpenCode ayarı ve örnek model grubu |
| `engine/prompts/` | Örnek görev metni |
| `engine/runs/` | Varsayılan koşu klasörü (Git dışında) |

**Veri kökü:** Koşular, görev metinleri ve model grupları "veri kökü"nde durur. Varsayılan veri kökü `engine/` klasörüdür. Kendi görev metinlerinizi ve büyük koşu verilerinizi depodan ayrı tutmak için `.env` içindeki `BENCHFORGE_ROOT` ile başka bir klasör gösterebilirsiniz; o klasörde `prompts/`, `config/` ve `runs/` bulunmalıdır. Betikler ve şablon her durumda depodaki `engine/` klasöründen kullanılır.

1. Arayüz koşuyu başlatır; işçi süreçleri arka planda, kendi süreç grubunda çalışır.
2. Her model için başlangıç şablonu ayrı bir çalışma klasörüne kopyalanır.
3. Model, OpenCode ile yalnız kendi klasöründe çalışacak şekilde izin politikası ve sınır izleyicisiyle çalıştırılır. Model başına 60 dakika süre sınırı vardır.
4. Teslim doğrulanır, sonuçlar `runs/<koşu>/` altına yazılır ve veritabanına senkronlanır.

### Çalışma koşulu

Kod üreten modeller `isolated-v1` koşulunda çalışır: her model kendi boş HOME ve OpenCode ayar klasörünü kullanır, böylece sizin global OpenCode ayarlarınız, eklentileriniz ve talimat dosyalarınız sonuçlara karışmaz. Giriş bilgileri, oturumlar ve kullanım verisi paylaşılan OpenCode veri klasöründe kalır. Başlangıç şablonunun `AGENTS.md` talimatı modele güvenilir bir bölüm olarak verilir. Koşulun adı ve talimat özeti her koşunun `_meta.txt` dosyasına kaydedilir.

## Gereksinimler

| Araç | Sürüm |
| --- | --- |
| PHP | 8.3 veya üzeri |
| Composer | 2.x |
| Node.js | 20.19+ veya 22.12+ |
| zsh | Koşucu betikleri için |
| OpenCode CLI | 1.x |
| rsync, curl, python3, shasum | Doğrulama aşamaları için |
| Playwright + Chromium | Tarayıcı çalışma testi için |

macOS üzerinde geliştirilip test edildi. Linux denenmedi; Windows desteklenmiyor.

## Kurulum

```bash
git clone https://github.com/mehmet-bozkurt/BenchForge.git
cd BenchForge
composer run setup
```

`setup` betiği bağımlılıkları kurar, `.env` dosyasını oluşturur, uygulama anahtarını üretir, SQLite veritabanını hazırlar ve arayüzü derler.

Ardından:

1. **Model sağlayıcısına bağlanın.** Anahtarlar OpenCode'un kendi kimlik deposunda tutulur; BenchForge onları okumaz veya kopyalamaz.
   ```bash
   opencode auth login
   ```
2. **Tarayıcı testi için Chromium'u kurun** (bir kez):
   ```bash
   npx --prefix engine/tools/browser-smoke playwright install chromium
   ```
3. **İsteğe bağlı: veri kökünü ayırın.** Varsayılan olarak koşular `engine/runs/` altına yazılır. Başka bir klasör için `.env` içinde `BENCHFORGE_ROOT` değişkenini veya uygulamadaki **Sistem ve Kurulum** ekranını kullanın.
4. **İlk koşu:** Yeni Benchmark ekranında `prompts/example.txt` görevini ve `config/example.conf` model grubunu seçebilirsiniz. Model kimliğini kendi OpenCode bağlantınıza göre düzenleyin.
5. **Uygulamayı açın.** [Laravel Herd](https://herd.laravel.com) ile `http://benchforge.test` adresinden veya geliştirme sunucusuyla:
   ```bash
   composer run dev
   ```

Var olan bir arşivi veritabanına aktarmak için:

```bash
php artisan benchforge:sync
```

## Testler

Tüm kontroller (Pint biçim kontrolü, zsh ve Node sözdizimi, PHP ve JavaScript testleri) tek komutla çalışır:

```bash
composer check
```

Aynı kontroller her pull request'te ve `main` dalına her gönderimde GitHub Actions ile macOS ve Linux üzerinde çalışır.

PHP testlerinde BenchForge'un veri kökü, koşucu, ayar, home ve OpenCode veritabanı yolları her test için `storage/framework/testing/` altında yeniden oluşturulan geçici bir klasöre yönlendirilir; böylece testler gerçek koşularınıza ve OpenCode verinize dokunmaz, bir benchmark çalışırken de güvenle çalıştırılabilir. Bu yollardan biri testten önce ya da sonra klasörün dışını gösterirse test başarısız olur. JavaScript testleri kendi geçici klasörlerini kullanır.

## Güvenlik notları

- **Yalnız yerel kullanım içindir.** Uygulamada kullanıcı girişi yoktur; ağa açmayın.
- **Bu bir güvenlik sandbox'ı değildir.** Modelin yazdığı kod, lint ve build sırasında sizin kullanıcı hesabınızın yetkileriyle çalışır. Güvenmediğiniz modelleri ayrı bir kullanıcı hesabında, sanal makinede veya konteynerde çalıştırmanız önerilir.
- **Model çağrıları ücretlidir.** Koşu başlatmak seçilen sağlayıcıda maliyet oluşturur. Gösterilen tutarlar OpenCode kayıtlarına dayanır; sağlayıcı faturasının yerine geçmez.
- Süreç ortamı izin listesiyle temizlenir: uygulamanın gizli anahtarları ve ortam değişkenlerindeki API anahtarları model süreçlerine aktarılmaz.

## Sınırlamalar

- **Tek örnek:** Her model bir görevde bir kez çalışır; sonuçların istatistiksel güveni sınırlıdır.
- **Liderlik evrensel bir sıralama değildir.** Görevler ve aşama setleri koşudan koşuya değişebilir; tablo yalnız aynı çalışma koşulundaki koşuları birleştirir.
- **İnsan puanlaması kör değildir.** Değerlendirici model adını görür.

## Teknolojiler

Laravel 13, Blade, Tailwind CSS 4, Vite, SQLite, Pest, Node.js, zsh, OpenCode.

## Lisans

[MIT](LICENSE)
