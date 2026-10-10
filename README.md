# BenchForge

BenchForge, yapay zekâ kodlama modellerini gerçek ürün geliştirmeye benzeyen görevlerde karşılaştıran, yerelde çalışan bir değerlendirme ortamıdır. Aynı görev metni ve aynı başlangıç projesiyle birden fazla model çalıştırılır. Teslimler teknik kontroller, süre, token, maliyet ve insan puanıyla yan yana incelenir.

> **Durum:** Erken aşamada, tek kullanıcılı yerel bir araç. Modelleri çalıştıran zsh araçları henüz bu depoda yayımlanmadı (bkz. [Sınırlamalar](#sınırlamalar)). Arayüz Türkçedir.

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
Laravel arayüzü ──► Node işçileri (bin/) ──► zsh araçları (benchmark.zsh) ──► OpenCode CLI ──► model
      ▲                                              │
      └──── SQLite ◄── arşiv senkronu ◄── runs/<koşu>/ (çalışma alanları, günlükler, sonuçlar)
```

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
2. **Benchmark araçlarının yolunu ayarlayın.** `.env` içinde `BENCHFORGE_ROOT` değişkenini veya uygulamadaki **Sistem ve Kurulum** ekranını kullanın. Bu kök; `tools/`, `config/`, `prompts/`, `sablon/` ve `runs/` klasörlerini içermelidir.
3. **Uygulamayı açın.** [Laravel Herd](https://herd.laravel.com) ile `http://benchforge.test` adresinden veya geliştirme sunucusuyla:
   ```bash
   composer run dev
   ```

Var olan bir arşivi veritabanına aktarmak için:

```bash
php artisan benchforge:sync
```

## Testler

```bash
php artisan test --compact
```

```bash
npm run test:js
```

JavaScript entegrasyon testleri benchmark araçlarının bu deponun iki üst klasörünün yanında (`../../frontend-model-benchmark`) olmasını bekler.

## Güvenlik notları

- **Yalnız yerel kullanım içindir.** Uygulamada kullanıcı girişi yoktur; ağa açmayın.
- **Bu bir güvenlik sandbox'ı değildir.** Modelin yazdığı kod, lint ve build sırasında sizin kullanıcı hesabınızın yetkileriyle çalışır. Güvenmediğiniz modelleri ayrı bir kullanıcı hesabında, sanal makinede veya konteynerde çalıştırmanız önerilir.
- **Model çağrıları ücretlidir.** Koşu başlatmak seçilen sağlayıcıda maliyet oluşturur. Gösterilen tutarlar OpenCode kayıtlarına dayanır; sağlayıcı faturasının yerine geçmez.
- Süreç ortamı izin listesiyle temizlenir: uygulamanın gizli anahtarları ve ortam değişkenlerindeki API anahtarları model süreçlerine aktarılmaz.

## Sınırlamalar

- **Benchmark araçları bu depoda değil.** `benchmark.zsh`, `resume-model.zsh`, model politikası ve başlangıç şablonu ayrı bir klasörde duruyor ve henüz yayımlanmadı. Bu yüzden depo şu an tek başına benchmark çalıştıramaz.
- **Tek örnek:** Her model bir görevde bir kez çalışır; sonuçların istatistiksel güveni sınırlıdır.
- **Liderlik evrensel bir sıralama değildir.** Görevler ve aşama setleri koşudan koşuya değişebilir; tablo yalnız aynı çalışma koşulundaki koşuları birleştirir.
- **İnsan puanlaması kör değildir.** Değerlendirici model adını görür.

## Teknolojiler

Laravel 13, Blade, Tailwind CSS 4, Vite, SQLite, Pest, Node.js, zsh, OpenCode.

## Lisans

[MIT](LICENSE)
