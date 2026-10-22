<p align="center">
  <img src="public/brand/benchforge-mark.svg" alt="BenchForge logosu" width="88">
</p>

# BenchForge

[![CI](https://github.com/mehmet-bozkurt/BenchForge/actions/workflows/ci.yml/badge.svg)](https://github.com/mehmet-bozkurt/BenchForge/actions/workflows/ci.yml)

BenchForge, yapay zekâ kodlama modellerini gerçek ürün geliştirmeye benzeyen görevlerde karşılaştıran, yerelde çalışan bir değerlendirme ortamıdır. Aynı görev metni ve aynı başlangıç projesiyle birden fazla model çalıştırılır. Teslimler teknik kontroller, süre, token, maliyet ve insan puanıyla yan yana incelenir.

> **Durum:** Erken aşamada, tek kullanıcılı yerel bir araç. Arayüz Türkçedir.

## Ne yapar?

- **Kod benchmarkı:** Seçtiğiniz 1–16 modeli [OpenCode](https://opencode.ai) üzerinden aynı görevle çalıştırır; aynı anda en fazla 5 model çalışır. Her teslim altı aşamada doğrulanır: model adımı, lint, production build, static export, rota kontrolü ve tarayıcıda çalışma testi.
- **Görev promptları ve model grupları:** **Promptlar** ve **Model Grupları** ekranlarından eklenir, düzenlenir ve silinir. Promptlar veri kökündeki `prompts/`, gruplar `config/` klasöründe dosya olarak durur. Silinen veya yeniden adlandırılan dosya kalıcı silinmez, `.silinen/` alt klasörüne taşınır. Çalışan bir koşunun kullandığı prompt değiştirilemez.
- **Model katalogları:** OpenRouter ve OpenCode Go kataloglarından model seçimi; OpenRouter için sağlayıcı yönlendirme tercihleri (otomatik, fiyat, hız, sabit endpoint).
- **Sonuç tablosu:** Her model tek satırdır. Aşama işaretleri logu açar, sonuç düz bir cümleyle açıklanır, satırlar süreye ve maliyete göre sıralanabilir. Renklerin ve kodların anlamı [Sonuçları okumak](#sonuçları-okumak) bölümündedir.
- **Önizleme ve karşılaştırma:** Üretilen siteleri aynı rota ve ekran genişliğinde yan yana görüntüleme. Önizlemeler sandbox'lı çerçevede ve CSP sandbox başlığıyla sunulur.
- **İnsan puanlaması:** İlk teslimin çıktısı yedi ölçütte ve genel olarak 1–10 puanlanır. Kurtarma denemesiyle düzeltilmiş çıktı ayrı görünümde incelenir, puanlanmaz.
- **Kurtarma denemeleri:** Başarısız bir modeli kaldığı oturumdan sürdürme ("devam") veya yalnız doğrulamaları tekrarlama. Aynı anda en fazla iki farklı model kurtarılabilir. Son denemenin sonucu sonuç tablosunda modelin satırında görünür; ilk teslimin sonucu ve puanı değişmez.
- **Liderlik tablosu:** Model kimliğine göre gruplanır; %60 teknik başarı (koşu başına aşama oranlarının ortalaması) ve %40 insan puanı. Farklı çalışma koşullarında üretilmiş sonuçlar karıştırılmaz.
- **Kullanım ve maliyet:** Token ve maliyet OpenCode'un yerel veritabanından okunur. Eksik veri sıfır diye gösterilmez.
- **Hazırlık paketi karşılaştırması:** Aynı müşteri şartnamesinden modellerin ürettiği başlangıç promptu, gereksinimler, görev planı, hafıza bankası ve varsayımları yan yana karşılaştırma. Bu akışta model araç kullanmaz, yalnız metin üretir.
- **Sistem ve Kurulum ekranı:** Araç sürümleri, yollar, yazma izinleri ve veritabanı kontrolleri. Benchmark kökü ve araç yolları arayüzden ayarlanabilir.
- **Alan yönetimi:** Her koşunun kapladığı alan ölçülür. Çalışma alanları, sonuç arşivi ve koşunun tamamı ayrı ayrı silinebilir.
- **Tema:** Açık, koyu ve işletim sistemini izleyen tema seçenekleri.

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

1. Arayüz koşuyu başlatır; işçi süreçleri arka planda, kendi süreç grubunda çalışır. Aynı anda yalnız bir koşu çalışır.
2. Her model için başlangıç şablonu ayrı bir çalışma klasörüne kopyalanır.
3. Model, OpenCode ile yalnız kendi klasöründe çalışacak şekilde izin politikası ve sınır izleyicisiyle çalıştırılır.
   - Model başına 60 dakika süre sınırı vardır.
   - Sağlayıcının takıldığı süre bu sınıra geri eklenir (en fazla 45 dakika).
   - Model çıktı sınırına takılıp hiçbir dosya yazamadıysa aynı oturumda bir kez otomatik olarak devam ettirilir.
4. Teslim doğrulanır, sonuçlar `runs/<koşu>/` altına yazılır ve veritabanına senkronlanır.
   - Bağımlılık kurulumu, lint ve build adımlarının her biri en fazla 15 dakika sürebilir. Süre dolunca komut ve başlattığı bütün süreçler durdurulur; böylece modelin build betiğine eklediği ve kapanmayan bir süreç koşuyu kilitleyemez.
   - Tarayıcı testi Chromium'u WebGPU ve GPU destekli WebGL açık çalıştırır (macOS'ta Metal). Böylece 3D çıktılar da gerçek sahneleriyle denetlenir.
5. Doğrulamadan hemen sonra modelin çıktısı önizleme arşivine (`_gallery/`) kopyalanır ve çalışma alanı hafifletilir: yeniden kurulabilen `node_modules` ve `.next` silinir, kaynak kod ve `out/` kalır. Bir model koşusu böylece yaklaşık 500 MB yerine birkaç MB yer kaplar. Kurtarma denemesi başlarken bağımlılıklar kendiliğinden yeniden kurulur. Eski koşular için: `php artisan benchforge:slim` (`--dry-run` ile önce ne kazanılacağını görün).

### Çalışma koşulu

Kod üreten modeller `isolated-v1` koşulunda çalışır: her model kendi boş HOME ve OpenCode ayar klasörünü kullanır, böylece sizin global OpenCode ayarlarınız, eklentileriniz ve talimat dosyalarınız sonuçlara karışmaz. Giriş bilgileri, oturumlar ve kullanım verisi paylaşılan OpenCode veri klasöründe kalır. Başlangıç şablonunun `AGENTS.md` talimatı modele güvenilir bir bölüm olarak verilir. Koşulun adı ve talimat özeti her koşunun `_meta.txt` dosyasına kaydedilir.

## Sonuçları okumak

**Aşama işaretleri:** ✓ geçti, × kaldı, — çalışmadı (önceki aşama kaldığı için). İşaretler ilk teslimi gösterir.

**Renkler:** Kırmızı yalnız modelin kendi hatasıdır.
- **Turuncu:** Sorun modelden değil dışarıdan gelmiştir. Sağlayıcı, hesap veya ölçüm kaynaklıdır; örneğin aynı kod yeniden doğrulandığında geçen bir aşama.
- **Gri:** Puanlanmayan bir modelin işaretleridir. Model hiç çalışamadığı için yalnız başlangıç şablonu doğrulanmıştır.

Başlıktaki **Sorunlu** sayısı modelin kendi hatalarını, **Dış etken** sayısı turuncu satırları gösterir.

**Model adımının sonuç kodları:**

| Kod | Anlamı | Puanlama |
| --- | --- | --- |
| 0 | Model işini bitirdi. | Puanlanır |
| 124 | Süre doldu; o ana kadar yazılanlar doğrulandı. | Puanlanır |
| 75 | Sağlayıcı veya hesap hatası ve teslim yok. Örnekler: sunucu hatası, istek sınırı, süresi dolmuş API anahtarı, abonelik yok. | Puanlanmaz |
| 77 | Sağlayıcı hatası geldi ama model bir teslim bıraktı. | Puanlanır |
| 86 | Teslim yok: model başlangıç şablonunda hiçbir dosyayı değiştirmedi. | Teknik puanı 0 |

**Lint ve build'de 124:** Kod 124 lint veya build aşamasında görünürse adım 15 dakikalık sınırı aşmış demektir. Koşu sayfası bunu "Build süre sınırını aştı" diye açıklar.

**Sağlayıcı takılması:**
- **Tanım:** OpenCode bir adımı bitirmeden en az 120 saniye sonra yeniden başlatırsa bu bir sağlayıcı takılması sayılır.
- **Telafi:** Takılan süre modelin süresine geri eklenir.
- **Durdurma:** Üç takılmadan sonra çağrı durdurulur. Teslim yoksa sonuç 75, varsa 77 olur.
- **Süre aşımı:** Sürenin en az yarısı takılmayla geçen bir süre aşımı da sağlayıcıdan sayılır.

**Kurtarma:**
- Bir kurtarma denemesinin son sonucu satırda küçük bir etiketle görünür; örneğin "✓ Kurtarma 1 · Tüm kontroller geçti".
- Deneme süresi 2 saat 45 dakika ile sınırlıdır. Bilgisayarın uykuda geçirdiği süre bu sınırdan sayılmaz.

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
   WebGPU kullanan çıktıların önizlemesi tarayıcıda güvenli bağlam (HTTPS veya `localhost`) ister. Herd ile siteyi HTTPS'e almak için:
   ```bash
   herd secure benchforge
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
- **Tek koşu:** Aynı anda yalnız bir koşu çalışır. Birden fazla görevi denemek için koşular sırayla başlatılır.
- **Liderlik evrensel bir sıralama değildir.** Görevler ve aşama setleri koşudan koşuya değişebilir; tablo yalnız aynı çalışma koşulundaki koşuları birleştirir.
- **İnsan puanlaması kör değildir.** Değerlendirici model adını görür.

## Teknolojiler

Laravel 13, Blade, Tailwind CSS 4, Vite, SQLite, Pest, Node.js, zsh, OpenCode, Playwright.

## Lisans

[MIT](LICENSE)
