# İş Akışları modülü: tasarım notu (Aşama 0)

Durum: **Aşama 0 onaylandı (22 Ekim 2026), Aşama 1 uygulandı.** Bu not uygulama başlamadan önce yazıldı; Aşama 1'de kesinleşen ayrıntılar ilgili bölümlere işlendi.

**Onaylanan kararlar:**
1. `jsonrepair` sabit sürümle (3.15.0) eklendi.
2. Model seçimi sunucu tarafında `ModelSelection` servisinde paylaşıldı; iş akışı formu yeni `x-model-picker` bileşenini kullanır. Kod benchmarkı formu değişmedi.
3. Bir iş akışı bir benchmark koşusuyla aynı anda çalışabilir; aynı anda tek iş akışı çalışır.
4. 5'ten fazla model içeren gruplar iş akışında seçilemez.

Amaç: çok adımlı yapay zekâ iş akışlarını (fikir üret → değerlendir → her fikir için demo yaz → test et → galeride karşılaştır) JSON şablonlarıyla tanımlayıp BenchForge içinde 1–5 modelle yan yana çalıştırmak ve canlı izlemek. İlk şablon Oyun Fikir Fabrikası'dır.

## 1. Mevcut akışlardan ne öğrendik

| Konu | Hazırlık paketi akışı | Kod benchmark akışı |
| --- | --- | --- |
| Başlatma | `PreparationRunner::start`, `benchforge:preparation:lifecycle` kilidi, `active.lock` | `BenchmarkRunner::start`, `benchforge:runner:start` kilidi, tek aktif koşu |
| İşçi | `bin/benchmark-launcher.mjs` → `bin/preparation-worker.mjs`; ayrık süreç grubu, `BENCHFORGE_RUNNER=1` kapısı | Aynı başlatıcı → `bin/benchmark-worker.mjs` → `engine/tools/benchmark.zsh` |
| Durum | `state.json`, geçici dosya ve `rename` ile atomik; yazımlar kuyrukla sıraya alınır | Aynı |
| Uzlaştırma | Her yoklamada `reconcile()`: süreç canlılığı, ardından durum okuma | Aynı; ilerleme `_results` ve log işaretlerinden okunur |
| Durdurma | Süreç grubuna SIGTERM; işçi OpenCode'a SIGTERM, 1,5 sn sonra SIGKILL | Süreç grubuna SIGTERM; izleyici OpenCode'a 4 sn sonra SIGKILL |
| Model çağrısı | `opencode run --standalone --format json --agent benchforge-preparation`; araçlar ve izinler kapalı, prompt stdin'den | İzleyici (`model-boundary-monitor.mjs`) üzerinden `opencode run --standalone --agent benchforge-workspace`; `isolated-v1` ortamı, izin politikası, süre sınırı, takılma bekçisi |
| Maliyet | `step_finish` olaylarındaki `cost` ve token toplamı; bilinmeyen `null` kalır | `OpenCodeUsageTracker`, opencode.db'den oturum kimliğiyle; koşu ve model klasör düzenine bağlı |
| Canlı log | Yok | `readLogTail` (son 32 KB) |
| Model seçimi | Tek sağlayıcı, 1–5 model, gruplar ve yönlendirme seçimi yok | Grup, OpenRouter, Go modları; yönlendirme modları; 1–16 model, aynı anda en fazla 5 |
| Testler | Sahte `opencode` ikili dosyası; işçi JS testleri; koşu klasörleri sandbox içinde | Aynı yaklaşım; motor uçtan uca testleri sahte `npm` ve `opencode` kullanır |

Önemli kısıtlar:

- **Sınır izleyicinin klasör düzeni sabit.** İzleyici ve politika üreteci, çalışma alanının doğrudan koşu klasörünün altında olmasını ister. Raporlar `_boundary/<slug>.json`, loglar `_logs/` altında durur. `code_agent` bu düzene uyacak, izleyici değiştirilmeyecek.
- **Benchmark model seçicisi yeniden kullanılmaya hazır değil.**
  - Seçim mantığı `BenchmarkRunner` içinde private metotlarda.
  - Blade işaretlemesi `runner/create.blade.php` içinde satır içi.
  - JavaScript, 820 satırlık tek bir `initializeRunner` fonksiyonu.
- **`OpenCodeUsageTracker` benchmark modellerine bağlı.** `BenchmarkRun` ve `ModelRun` modellerini ve `runs/<koşu>/<model>` düzenini bekliyor.
- **Tarayıcı kontrolü sınırlı.** `check.mjs` ekran görüntüsü, etkileşim ya da ekran boyutu desteklemiyor. Sabit porttan bir `python3 http.server` ile sunulan dosyayı açıyor.
- **İşçiler yalnız Node'un yerleşik modüllerini kullanıyor.** Kök `package.json` yalnız geliştirme bağımlılıkları içeriyor.
- **OpenRouter kataloğu eski.** Kayıtlı katalog 3 Ekim tarihli ve içinde `minimax/minimax-m3.1-flash:free` yok; elle deneme öncesinde katalog yenilenmeli.

## 2. Yeniden kullanılacaklar ve yeni yazılacaklar

**Olduğu gibi yeniden kullanılacaklar:**

- **Süreç ve ortam:**
  - `bin/benchmark-launcher.mjs`.
  - `bin/process-environment.mjs` (`subprocessEnvironment`, `isolatedModelEnvironment`).
  - `BenchmarkProcessEnvironment`, `BenchForgeSettings::apply()`.
- **Model çağrısı:**
  - `bin/model-boundary-monitor.mjs`: `runMonitoredModel`, takılma bekçisi, süre sınırı.
  - `engine/tools/benchforge-model-policy.mjs`.
  - `engine/tools/run-with-timeout.mjs`.
  - `engine/tools/classify-opencode-failure.mjs`: sağlayıcı ve hesap hatası ayrımı, 429 tespiti.
- **Model seçimi ve bağlantı:**
  - `OpenRouterCatalog`, `OpenCodeGoCatalog` (`selectableModels`).
  - `OpenRouterProviderRouting` (`routingForModels`, `endpoints`).
  - `ModelGroupStore`, `ProviderConnectionStatus`.
- **Güvenlik:**
  - `EnsureLocalPreparationAccess`.
  - Önizleme başlıkları: `Content-Security-Policy: sandbox allow-scripts`, `no-store`, `nosniff`, `no-referrer`.
  - `sandbox="allow-scripts"` iframe.
- **Arayüz:** `status-*` sınıfları ve tema değişkenleri; `resources/js/openrouter-catalog.js` içindeki saf yardımcılar.

**Testlerle güvenceye alınarak paylaşılacaklar:**

- **`bin/opencode-text.mjs`:** Hazırlık işçisindeki araçsız OpenCode çağrısı, kısıtlı ayar denetimi, yasaklı olay kontrolü ve metin/maliyet toplama buraya taşınır. Hazırlık işçisi ve yeni işçi bunu birlikte kullanır. Mevcut `preparation-worker.test.js` testleri davranışın değişmediğini korur.
- **`App\Services\ModelSelection`:** `BenchmarkRunner` içindeki özel seçim mantığı buraya taşınır: grup ayrıştırma, OpenRouter/Go seçimi, yönlendirme, `max_parallel`. `BenchmarkRunner` bu servisi kullanır; mevcut `BenchmarkRunnerTest` testleri değişmeden geçmeli.
- **Tarayıcı açma ayarları:** `check.mjs`'teki GPU bayrakları ortak bir modüle (`engine/tools/browser-smoke/browser.mjs`) alınır. `check.mjs`'in davranışı aynı kalır.

**Yeni yazılacaklar:**

- **Node işçisi ve modülleri:**
  - `bin/workflow-worker.mjs` ve `bin/workflow/` altındaki modüller: şablon yorumlama, değişken yerleştirme, JSON ayıklama/onarma, şema doğrulama, kod çıkarma ve statik kontroller, adım yürütücüleri.
  - `engine/tools/browser-smoke/workflow-check.mjs`: tek HTML dosyasını rastgele boş bir porttan (127.0.0.1:0) sunar. Ağı kapatır, sayfa ve konsol hatalarını toplar, isteğe bağlı sürükleme senaryosunu uygular ve ekran görüntüsü alır.
- **Laravel tarafı:**
  - Servisler: `WorkflowTemplateStore`, `WorkflowTemplateValidator`, `WorkflowRunner`, `WorkflowRunFiles`.
  - Modeller, migration'lar, factory'ler.
  - Form Request'ler, controller'lar, Blade görünümleri ve `resources/js/workflows.js`.

## 3. Klasör ve dosya düzeni

```
engine/workflow-templates/<şablon>/v001.json  yerleşik şablonlar (Git'te, salt okunur)
engine/workflow-templates/<şablon>/starter/   code_agent başlangıç klasörü (Aşama 2)
<veri kökü>/workflows/<şablon>/vNNN.json   kullanıcı şablonları; her kayıt yeni sürüm
<veri kökü>/workflow-runs/<çalıştırma>/
  job.json  state.json  events.jsonl  template.json (anlık kopya)  inputs.json
  models/<model-slug>/
    summary.json
    steps/<adım>/[items/<nnn>-<anahtar>/]
      result.json  attempt-<k>/{prompt.txt, response.txt, output.json | demo.html, checks.json}
      screenshot.png
  <model-slug>-<adım>-<nnn>/        code_agent çalışma alanı (izleyicinin istediği yerde)
  models/<model-slug>/{opencode.json, workspace/, .benchforge-home/}   araçsız çağrıların kısıtlı ayarı, boş çalışma klasörü ve özel HOME
  _boundary/  _logs/  _policies/  _opencode-home/        (code_agent, Aşama 2)
```

- **Yerleşik şablonlar:** Yerleşik şablon düzenlenip kaydedilince veri köküne kullanıcı şablonu olarak yeni bir sürüm yazılır. `engine/` altındaki dosya değişmez. Yerleşik klasörün adı `engine/workflow-templates/` oldu: `BENCHFORGE_ROOT` ayarlı değilken veri kökü `engine/` olduğu için `engine/workflows/` kullanıcı şablonlarıyla çakışırdı. `engine/workflows/` ve `engine/workflow-runs/` Git dışında tutulur.
- **Klasörlerin oluşturulması:** `workflows/` ve `workflow-runs/` klasörleri ilk kullanımda oluşturulur. Veri kökünün geçerlilik kuralı (`prompts/`, `config/`, `runs/`) değişmez.
- **Yol güvenliği:** Bütün yollar mevcut kök sınırı denetimlerinden geçer: `realpath`, sembolik bağlantı reddi ve isim kalıpları.

## 4. Veri modeli (SQLite)

- **`workflow_runs`:**
  - Kimlik ve şablon: `id`, `slug` (benzersiz), `template_id`, `template_version`, `template_sha256`, `title`.
  - Durum: `status` (`starting`, `running`, `stopping`, `completed`, `partial`, `failed`, `stopped`).
  - Ayarlar: `inputs` (json), `settings` (json; eş zamanlılık, süre sınırları, adım modları).
  - Süreç: `working_directory`, `runner_pid`, `message`, `started_at`, `finished_at`.
- **`workflow_run_models`:**
  - Kimlik: `workflow_run_id` (silinince alt kayıtlar da silinir), `model`, `model_name`, `slug`.
  - Durum: `status`, `error_message`.
  - Toplamlar: `duration_seconds`, `input_tokens`, `output_tokens`, `cost_usd` (hepsi boş olabilir; bilinmeyen değer boş kalır), `items_total`, `items_completed`, `items_failed`.
  - Kısıt: model başına tek kayıt (benzersiz `(workflow_run_id, model)`).
- **`workflow_evaluations` (Aşama 3):**
  - Alanlar: `workflow_run_model_id`, `item_key`, `kind` (`idea` / `demo`), `score` (1–10), `notes`.
  - Kısıt: Her öğe ve tür için tek kayıt (benzersiz `(workflow_run_model_id, item_key, kind)`).
  - Kod benchmarkının liderlik tablosuna karışmaz.

Öğe düzeyindeki ayrıntılar (prompt, yanıt, denemeler, kontroller) veritabanında değil dosyalarda tutulur; veritabanında yalnız model düzeyindeki özetler bulunur. Uzlaştırma bu özetleri `state.json` ve `summary.json` dosyalarından eşitler.

## 5. Şablon biçimi (öneri: `benchforge-workflow/1`)

```json
{
  "schema": "benchforge-workflow/1",
  "id": "game-idea-factory",
  "name": "Oyun Fikir Fabrikası",
  "description": "…",
  "version": 1,
  "constants": {
    "categories": [{ "key": "existing", "label": "Var olan oyun", "brief": "EXISTING GAME: …" }],
    "platformBrief": "TARGET PLATFORMS …",
    "demoRules": "DEMO REQUIREMENTS …"
  },
  "inputs": [
    { "id": "ideasPerCategory", "type": "integer", "label": "Kategori başına fikir", "default": 5, "min": 1, "max": 10 },
    { "id": "docLanguage", "type": "string", "label": "Belge dili", "default": "Türkçe", "maxLength": 40 },
    { "id": "extraBrief", "type": "text", "label": "Ek açıklama", "default": "", "required": false, "maxLength": 4000 }
  ],
  "steps": [
    {
      "id": "ideas", "type": "foreach", "title": "Fikir üret",
      "over": "constants.categories", "itemLabel": "{{item.label}}", "concurrency": 1,
      "steps": [{
        "id": "generate", "type": "llm_json", "title": "Kategori fikirleri",
        "prompt": "{{item.brief}}\n{{constants.platformBrief}}\nDo not repeat: {{loop.previous[*].generate.output.ideas[*].title}}\n…",
        "schema": { "type": "object", "required": ["ideas"], "properties": { "ideas": { "type": "array", "minItems": 1, "items": { "…": "…" } } } },
        "retry": { "attempts": 3, "backoffSeconds": 10 },
        "timeoutSeconds": 600, "maxOutputChars": 60000
      }]
    },
    {
      "id": "score", "type": "llm_json", "title": "Fikirleri puanla", "dependsOn": ["ideas"],
      "prompt": "… {{steps.ideas.items[*].generate.output.ideas[*]}} …",
      "schema": { "…": "…" }
    },
    {
      "id": "demos", "type": "foreach", "title": "Demolar", "dependsOn": ["ideas"],
      "over": "steps.ideas.items[*].generate.output.ideas[*]", "itemLabel": "{{item.title}}", "concurrency": 2,
      "steps": [
        { "id": "build", "type": "code_text", "modes": ["code_text", "code_agent"], "title": "Demo yaz",
          "prompt": "{{item.demoSpec}}\n{{constants.demoRules}}", "repairAttempts": 2,
          "checks": { "requireListeners": ["pointerdown", "touchstart", "mousedown"], "maxBytes": 204800 },
          "timeoutSeconds": 1200, "maxOutputChars": 400000 },
        { "id": "check", "type": "browser_check", "target": "build", "dependsOn": ["build"],
          "interactions": [{ "type": "drag", "from": [0.5, 0.65], "to": [0.5, 0.35] }], "screenshot": true }
      ]
    }
  ],
  "gallery": { "from": "demos", "title": "{{item.title}}", "file": "build", "check": "check" }
}
```

**Kesin biçim:** Aşama 1'deki doğrulayıcının uyguladığı kurallar `app/Services/WorkflowTemplateValidator.php` dosyasındadır. Yerleşik örnek `engine/workflow-templates/game-idea-factory/v001.json` dosyasıdır. Örnekteki `"…"` anahtarları yalnız kısaltmadır; bilinmeyen anahtarlar doğrulamada hata verir.

**Değişkenler:**
- Desteklenen biçimler: `{{inputs.x}}`, `{{constants.yol}}`, `{{steps.<id>.output[.yol]}}`, `{{item.yol}}`, `{{loop.index}}`, `{{loop.previous…}}`.
- Yol söz dizimi yalnız alan adı, `[n]` ve `[*]` (düzleştirme) içerir.
- **Yerleştirme düz metin değiştirmedir:** Metin olduğu gibi girer. Sayılar JSON olarak yazılır. Nesneler girintili JSON olur. `[*]` ile seçilen metin listeleri madde işaretli satırlar olur.
- Hiçbir koşulda ifade değerlendirilmez veya kod çalıştırılmaz. Yerleştirilmiş prompt 256 KB ile sınırlıdır.

**Doğrulayıcı** (kaydetmeden ve başlatmadan önce, PHP tarafında):
- Şema sürümü, benzersiz adım kimlikleri ve yalnız bilinen adım türleri.
- `dependsOn` adımlarının daha önce tanımlanmış olması ve döngü olmaması.
- Girdi kısıtları.
- Her `{{…}}` başvurusunun var olan bir girdiye, sabite, önceki bir adıma ya da (`llm_json` şemasından çıkarılan) var olan bir alana gitmesi.
- `item` ve `loop` başvurularının yalnız `foreach` içinde kullanılması.
- JSON şemasının desteklenen alt kümeden oluşması: `type`, `properties`, `required`, `items`, `enum`, `minItems`/`maxItems`, `minLength`/`maxLength`, `minimum`/`maximum`.

**Adım türleri ve çıktıları:**

| Tür | Çıktı |
| --- | --- |
| `llm_text` | Metin |
| `llm_json` | Doğrulanmış JSON |
| `foreach` | Öğe sonuçlarının dizisi |
| `code_text` / `code_agent` | `demo.html` yolu ve statik kontrol sonucu |
| `browser_check` | Geçti/kaldı, hatalar, ekran görüntüsü yolu |

Galeri ve özet bir model adımı değildir. Şablondaki `gallery` alanına göre arayüz bunları kendisi üretir.

## 6. Çalıştırma motoru

**Başlatma:**
- `WorkflowRunner::start` girdileri ve model seçimini doğrular; seçim `ModelSelection` ile yapılır, 1–5 model.
- Bağlantı `ProviderConnectionStatus` ile kontrol edilir.
- Çalıştırma klasörü oluşturulur: `job.json`, şablonun anlık kopyası, girdiler ve modelin araçsız çağrılar için kısıtlı `opencode.json` dosyası.
- İşçi, mevcut başlatıcıyla ayrık süreç grubunda başlar.
- Aynı anda tek aktif iş akışı çalışır (`benchforge:workflow:lifecycle` kilidi). `BenchForgeSettings::busy()` aktif iş akışlarını da sayar.

**Yürütme:**
- Seçilen her model iş akışının tamamını bağımsız yürütür. Modeller `max_parallel` kadar aynı anda çalışır; koşucu kayan pencereyle çalışır.
- Adımlar dizideki sırayla ve `dependsOn` kurallarıyla ilerler.
- `foreach` öğeleri kendi `concurrency` sınırıyla çalışır.
- Çalıştırma formundaki genel "eş zamanlı model çağrısı" sınırı ücretsiz modellerin dakika sınırına karşı bütün çağrıları kapsar.

**Çağrı türleri:**
- **`llm_text` / `llm_json`:**
  - Kısıtlı ajanla, araçsız `opencode run --standalone --format json` çağrısı yapılır. `--standalone` çağrıyı kullanıcının arka plandaki OpenCode servisine değil, bu ortamla açılan ayrı bir sunucuya gönderir.
  - Her çağrı yeni bir oturumdur. Oturum başlığı `benchforge:workflow:<çalıştırma>:<model>:<adım>:<öğe>:<deneme>` biçimindedir.
- **`code_text`:**
  - Aynı çağrı yapılır. Yanıttaki ` ```html ` bloğu çıkarılır ve statik kontroller uygulanır.
  - Kritik bir sorun varsa onarım istenir; hata metni prompt'a eklenir.
- **`code_agent`:**
  - Şablonun `starter/` klasörü izleyicinin istediği yere kopyalanır.
  - Model `runMonitoredModel` ile `isolated-v1` ortamında, izin politikası, süre sınırı ve takılma bekçisiyle çalışır.
  - Çıktı dosyaları toplanır.

**`llm_json` ayrıştırma:**
1. ` ```json ` bloğu ayıklanır.
2. `JSON.parse` denenir.
3. Olmazsa onarım denenir.
4. Sonuç şemaya göre doğrulanır.
5. Doğrulanamazsa hata metni ve modelin kendi bozuk cevabının kısaltılmış hali prompt'a eklenerek yeniden denenir. Prompt'a "metin içinde çift tırnak kullanma" kuralı da eklenir.

**Yeniden deneme:**
- İçerik hataları adımın `retry.attempts` sayısıyla sınırlıdır.
- Geçici sağlayıcı hataları (429, 5xx) ayrıca artan beklemeyle denenir: 15 sn, 30 sn, 60 sn…, en fazla 5 dk. Bunlar içerik denemesinden sayılmaz.
- Hesap hataları (401, 403) çalıştırmayı hemen durdurur.

**Durum dosyaları:**
- **`state.json`:**
  - Model, adım ve öğe bazında durum ve sayaçlar, deneme sayısı, hatalar ve zamanlar.
  - Token ve maliyet; bilinmiyorsa `null`.
  - Etkin çağrılar için modelin yazdığı metnin son 4 KB'ı.
  - Atomik yazılır; en sık saniyede bir.
  - Öğe ayrıntıları `result.json` dosyalarındadır, `state.json` küçük kalır.
- **`events.jsonl`:** En fazla 5 MB olabilir; dolunca dosya döndürülür.

**Devam ettirme ve yeniden deneme:**
- **Devam et:** `result.json` dosyası "tamamlandı" olan adım ve öğeler atlanır.
- **Başarısızları yeniden dene:** Yalnız başarısız öğeler sıfırlanır.
- **Tek öğe:** Seçilen bir öğenin klasörü yeni bir deneme numarasıyla yeniden üretilir. Eski denemeler silinmez.

**Durdurma:** Süreç grubuna SIGTERM gönderilir. İşçi etkin OpenCode çağrılarına SIGTERM, 1,5 sn sonra SIGKILL gönderir ve devam ettirilebilir bir durum yazar.

**Token ve maliyet:**
- Hazırlık akışındaki gibi `step_finish` olaylarından toplanır; bilinmeyen değer `null` kalır.
- `code_agent` için oturum kimliği saklanır. `OpenCodeUsageTracker`'a yalnız bir oturum kimliği ve klasörle çalışan yeni bir public metot eklenebilir. Mevcut metotlar ve testleri değişmez.

**Süreler:** Model çağrısı için `performance.now()` kullanılır (uyku sayılmaz). Varsayılanlar:
- `llm_json`: 10 dk.
- `code_text`: 20 dk, yaklaşık 400 bin karakter.
- `code_agent`: 30 dk.
- `browser_check`: 60 sn.

## 7. Arayüz

- **Menü:** Sol menüde Promptlar'dan sonra yeni bir öğe: "İş Akışları".
- **İş Akışları sayfası:** Şablonlar (yerleşik ve kullanıcı) ve son çalıştırmalar.
- **Yeni çalıştırma formu:**
  - Girdiler şablonun `inputs` alanlarından üretilir.
  - Model seçici yeni bir Blade bileşeni (`x-model-picker`) olarak yazılır: grup, OpenRouter, Go, yönlendirme, 1–5 model. Sunucu tarafını `ModelSelection` sağlar.
  - Kod benchmarkı formu ilk aşamada değişmez; onu bu bileşene taşımak ayrı bir iştir.
  - Formda ayrıca kod adımları için mod seçimi, eş zamanlılık ve süre sınırı bulunur.
- **Canlı sayfa:**
  - Adım grafiği: sıralı kutular, her kutuda durum, süre, token ve `foreach` ilerlemesi. Modeller sekme olarak ayrılır.
  - Adıma tıklayınca yan panel açılır: yerleştirilmiş prompt, çıktı (JSON ise biçimlendirilmiş), denemeler, hatalar, süre, token ve maliyet. Bu bilgiler ayrı bir uç noktadan, istek üzerine yüklenir.
  - Canlı günlük, etkin çağrılar ve modelin son yanıtı. (Aşama 1'de görüldü; OpenCode 2.2.5'te de aynı: `--format json` metni yalnız bir yanıt parçası bitince bildiriyor; bu yüzden "o an yazılan metin" yerine çağrı bitince son yanıt gösteriliyor.)
  - Model bazında ve genel toplamlar; bilinmeyen değerler "bilinmiyor" olarak gösterilir.
  - Düğmeler: Durdur, Devam et, Başarısızları yeniden dene, Tek öğeyi yeniden çalıştır.
  - Sayfa 2 sn arayla yoklanır; çalıştırma bitince yoklama durur (`preparations.js` gibi). Sayfanın tamamı değil, yalnız değişen bölgeler güncellenir.
- **Galeri:**
  - Model ve öğe başına bir kart: ekran görüntüsü, başlık, kontrol sonucu.
  - "Oyna" düğmesi demoyu mevcut önizleme başlıklarıyla açar; ayrı bir rota kullanılır, oturum ara katmanları devre dışıdır.
- **Aşama 3:**
  - Modeller arası karşılaştırma, fikir ve demo puanlama, zip dışa aktarma (PHP `zip` eklentisi kurulu).
  - Şablon editörü ve modele şablon ürettirme. Öneri editöre açılır, otomatik kaydedilmez. Bu üretim de yerleşik tek adımlı bir iş akışıyla yapılır.

**Rotalar:**
- `/workflows` altında, `EnsureLocalPreparationAccess` ile korunur.
- İstek sınırları: başlatma 5/dk, durdurma, devam ve yeniden deneme 10/dk, şablon kaydetme 20/dk.

## 8. Riskler ve açık sorular

1. **Çağrı başına çıktı sınırı (OpenCode 2.2.5'te yeniden çözüldü):**
   - `opencode run` komutunda böyle bir seçenek yok. OpenCode 1.19.3'ün kullandığı `OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX` ortam değişkeni 2.2.5'te yok.
   - 2.2.5 istekteki `max_completion_tokens` değerini modelin `limit.output` değerinden alır (bağlam penceresinin yaklaşık %90'ına kırpılır). Katalog modellerinde 32.000'lik varsayılan sınır yoktur.
   - Çıktıyı sınırlamanın tek yolu modelin `limit` ayarını geçersiz kılmaktır. Bu ayar hem `context` hem `output` ister; yalnız `output` verilirse model kullanılamaz olur.
   - İşçi bir modelin gerçek sınırlarını modelin ilk çağrısından önce bir kez, çağrının ortamıyla `opencode api --standalone GET /api/model` komutundan okur (yaklaşık 1 sn).
   - Adımın `maxOutputTokens` değeri modelin kendi `limit.output` değerinin altındaysa çağrının ayarına `limit {context, output: maxOutputTokens}` eklenir. Değilse ayar olduğu gibi kalır.
   - Sınırlar okunamazsa çağrı modelin kendi sınırıyla çalışır ve model başına bir kez `output_limit_unknown` uyarısı yazılır.
   - `maxOutputChars` sınırını işçi kendisi uygular.
   - Gerçek 2.2.5 ve yerel sahte bir sağlayıcıyla denendi: 5.000'lik sınır istekte `max_completion_tokens=5000` olarak gitti, yönlendirme ayarı korundu. Gerçek bir ücretli modelde denenmedi.
2. **Hız sınırı:** Ücretsiz OpenRouter modelleri dakikada 20 istek ile sınırlı. Kategori başına 5 fikirle model başına yaklaşık 30–70 çağrı yapılır. Bunu genel eş zamanlılık sınırı ve artan bekleme karşılar.
3. **Süre:** 20 demo tek tek yaklaşık 2–2,5 saat sürer; eş zamanlılık 2–3 olunca model başına yaklaşık 1 saate iner. Aşama 1 kabul testi kategori başına 1 fikirle (4 demo) yapılır.
4. **`jsonrepair` bağımlılığı (karar: a, 3.15.0):** İşçilerin ilk npm bağımlılığı oldu. Değerlendirilen seçenekler:
   - (a) `jsonrepair` sabit bir sürümle eklenir. Önerim bu: iyi test edilmiş ve kendi bağımlılığı yok.
   - (b) Kendi küçük onarıcımızı yazarız: kod bloğu ayıklama, sondaki virgüller, akıllı tırnaklar, kesilmiş dizileri kapatma.
5. **Model seçici:** Benchmark formunu bileşene taşımak riskli. Bu yüzden önce sunucu tarafını paylaşıyorum, arayüz bileşeni yeni yazılıyor. Form taşıması sonraki bir iş olarak kalır.
6. **Kod benchmarkıyla aynı anda çalışma:** Port çakışması olmaz (`browser_check` rastgele port kullanır), ancak işlemci yükü benchmark sürelerini biraz etkileyebilir. Önerim: iş akışı bir benchmark koşusuyla aynı anda çalışabilsin; aynı anda tek iş akışı çalıştırma olsun.
7. **Kayıtlı gruplar:** Bir grup 16 modele kadar içerebilir. İş akışında 5'ten fazla model içeren grup seçilemez; formda açık bir hata gösterilir.
8. **OpenCode oturumları:** Her çağrı opencode.db'ye bir oturum ekler (bir çalıştırmada yüzlerce). Başlıklar `benchforge:workflow:` ile başlar, böylece ayırt edilebilir ve benchmark aramalarına karışmaz.
9. **Güvenlik:**
   - Demolar her yerde yalnız sandbox içinde açılır.
   - `browser_check` sayfanın ağ isteklerini engeller; yalnız kendi yerel sunucusuna izin verir.
   - Şablon yerleştirmesinde kod çalıştırılmaz.
   - Bütün dosya okumaları kök sınırı denetiminden geçer.
10. **README:** Her aşamanın sonunda README'ye "İş Akışları" bölümü eklenir veya güncellenir. Aşama 0'da henüz özellik olmadığı için eklenmedi.

## 9. Test planı

- **Pest:**
  - Şablon doğrulayıcı: geçerli şablon; bozuk başvuru, döngü, bilinmeyen tür ve şema alt kümesi dışı durumlar.
  - Şablon deposu: sürüm oluşturma, yol güvenliği.
  - `WorkflowRunner`: başlatma, uzlaştırma, durdurma, devam etme; sahte işçi ve durum dosyalarıyla.
  - Controller'lar: yalnız yerel erişim (403), istek sınırları.
  - Önizleme: başlıklar ve yol kaçışı.
  - `ModelSelection` taşımasından sonra mevcut `BenchmarkRunnerTest` testleri değişmeden geçmeli.
- **JavaScript:** Sahte `opencode` ikili dosyasıyla:
  - `llm_json` onarım ve yeniden deneme, `foreach` eş zamanlılığı.
  - `code_text` çıkarma ve statik kontroller.
  - 429 sonrası bekleme, durdurma ve devam ettirme, atomik `state.json`.
  - `workflow-check.mjs` için fikstür HTML'ler: çalışan, sayfa hatası veren, dış istek yapan.
  - `opencode-text.mjs` taşımasından sonra `preparation-worker.test.js` testleri değişmeden geçmeli.
- **Elle deneme:** Testlerde gerçek model çağrısı yapılmaz. Elle uçtan uca deneme yalnız onayınızla, `openrouter/minimax/minimax-m3.1-flash:free` ile yapılır; öncesinde OpenRouter kataloğu yenilenir.

## 10. Aşama 1 kapsamı (onaydan sonra)

1. Çıktı sınırının doğrulanması ve `opencode-text.mjs` / `ModelSelection` paylaşımı, mevcut testlerle.
2. Şablon biçimi, doğrulayıcı ve depo; Oyun Fikir Fabrikası şablonu.
3. İşçi: `llm_text`, `llm_json`, `foreach`, `code_text`; `state.json` ve olay günlüğü; durdurma.
4. Veri modeli, `WorkflowRunner`, rotalar ve controller'lar.
5. Arayüz: liste, çalıştırma formu (çoklu model), canlı sayfa (adım grafiği, durumlar, canlı günlük, Durdur), sandbox'lı galeri.
6. README'de "İş Akışları" bölümü; `composer check`.

Kabul ölçütü: Oyun Fikir Fabrikası kategori başına 1 fikir ve 2 modelle uçtan uca çalışır, canlı izlenir ve demolar galeride oynanır.

## 11. Aşama 1'de kesinleşen davranışlar

- **Hata türleri** (`providerErrorKind`). OpenCode 1.x hatayı `error.name` ve `error.data` altında, 2.x `error.type`, `error.status` ve `error.message` alanlarında yazar; ikisi de okunur:
  - **Başlatma yarışı:** Birkaç OpenCode süreci aynı anda başlarken sunucu süreci ara sıra hazır olmadan kapanır ("Standalone server exited before reporting readiness", `sessionID` boş). Modele hiçbir şey sorulmadığı için `runOpenCodeText` çağrıyı 300, 900 ve 2000 ms beklemeyle en fazla üç kez yeniden başlatır (bekleme `backoffScale` ile ölçeklenir); bunlar deneme sayılmaz. Üç yeniden denemeden sonra da başlamazsa hata geçici sayılır ve aşağıdaki beklemeye girer.
  - **Geçici (429, 5xx, ağ hatası; 2.x türleri `provider.rate-limit`, `provider.transport`, `provider.timeout`, `provider.internal`, `provider.unknown` (OpenCode'un 4xx durumu olmayan hatalar için genel türü; OpenCode kendisi de yeniden dener), "Transport: …" iletisi):**
    - Artan beklemeyle yeniden denenir: 15, 30, 60, 120, 240 ve 300 sn, çağrı başına en fazla 6 bekleme.
    - "Unexpected server error" hatasında çağrı başına en fazla 2 bekleme yapılır.
    - Bir model üst üste iki çağrıda bekleme hakkını bitirirse yalnız o model durur.
  - **Hesap (401, 403, `ProviderAuthError`; 2.x türleri `provider.auth`, `provider.quota`, `provider.free-limit`, `provider.go-limit`, `provider.budget-limit`, `provider.chatgpt-limit`):** Bütün çalıştırma durur.
  - **Kullanılamaz (404, 410, `ModelDeprecated`; 2.x türü `provider.no-route`; OpenRouter'ın var olmayan model kimliğine verdiği 400 "… is not a valid model ID"):** Yalnız o model başarısız olur. Gerçek denemede bu son hata tanınmayınca çağrı her adımda 10 sn aralıkla 2–3 kez yeniden deneniyordu (5 çağrı, 36 sn); şimdi tek çağrıda duruyor.
  - **Model harcama sınırı (2.x `provider.model-budget-limit`, 429 durumuyla gelir):** Yalnız o model başarısız olur ("Bu model için harcama sınırı doldu."); diğer modeller sürer. Hesabın sınırları (`provider.go-limit`, `provider.free-limit` gibi) bütün çalıştırmayı durdurur.
  - **Gizlilik reddi (400 veya 404, "trains on request data" ya da "data policy"):** Yalnız o model başarısız olur. Gerçek loglarda bu ret modele özgüdür; aynı anahtarla diğer modeller çalışmaya devam eder.
  - **Modelin kendi hatası (tür yok):** `provider.content-filter`, `provider.invalid-output`, `provider.unsupported-operation`, `tool.*`, `permission.rejected`, `context-overflow` ve `compaction.failed` sağlayıcı hatası sayılmaz: çağrı başarısız sayılır ve adımın normal deneme hakkı içinde (`llm_text` 2, `llm_json` 3, `code_text` 1) yeniden denenir; sağlayıcı bekleme ve durdurma kuralları uygulanmaz.
- **Çıktı sınırı:**
  - Çağrının herhangi bir adımı `length` ile bittiyse `llm_text` ve `llm_json` denemeleri "Çıktı token sınırına ulaşıldı; yanıt kesildi." mesajıyla başarısız olur. OpenCode 2.2.5 kesilen yanıtı kendisi iki kez sürdürür ve son adım `stop` ile bitebilir; yine de çağrı kesilmiş sayılır, çünkü birleşen metin tam bir yanıt değildir. Yanıt üçüncü kez kesilirse 2.2.5 `output-limit` hata olayıyla 1 koduyla biter; bu da aynı biçimde kesilmiş yanıttır. Yanıt `jsonrepair` ile onarılabilse bile kabul edilmez.
  - `code_text` adımında yanıt kesilmiş sayılır ve onarım prompt'u daha küçük bir sürüm ister.
  - Her denemenin `stopReason` alanı `result.json` dosyasına yazılır.
- **Token:**
  - İş akışlarında girdi token'ı = `input` + `cache.read` + `cache.write`.
  - Çıktı token'ı = `output` + `reasoning`.
  - Hazırlık paketi akışının toplamları değişmedi.
- **Canlı metin:** OpenCode `--format json` metni yalnız bir yanıt parçası bitince bildirir (1.19.3'te ve 2.2.5'te görüldü). Bu yüzden durum dosyası her model için çağrı bitince `lastOutput` alanını tutar: son yanıtın son 4096 karakteri. Sayfa bunu "Son yanıt" olarak gösterir; etkin çağrılar için geçen süreyi gösterir.
- **Demolar:**
  - Kısıtlayıcı bir CSP ile sunulur: `sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval'; …; connect-src 'none'`.
  - WebRTC (`RTCPeerConnection`) ve sayfayı dışarı yönlendirme CSP kapsamında değildir; statik kontrol bunları kritik sorun sayar.
  - Üst düzeydeki bir kod adımının demosu `…/demos/{model}/{step}` rotasıyla açılır. Galeri yalnız `gallery.from` döngüsünün öğelerini gösterir.
- **Zamanlar:** Durum yanıtındaki bütün zamanlar uygulamanın saat dilimine çevrilir.
- **Başlatma koruması:** Başlatılmakta olan bir çalıştırma (`starting`, henüz süreç kimliği yok, 30 sn'den yeni) uzlaştırmada başarısız sayılmaz.
- **Görüntü durumları:** Çalıştırma bittikten sonra:
  - hiç başlamamış adım ve öğeler "Çalıştırılmadı" görünür;
  - işçinin yarıda bıraktıkları "Yarıda kaldı" görünür.

  Diskteki `pending` durumu, Aşama 2'deki devam ettirme için olduğu gibi kalır.
- **Şablon nesneleri:** PHP boş nesneleri `[]` olarak yazabildiği için işçi, nesne beklenen yerlerde boş diziyi boş nesne sayar. Uzunluklar iki tarafta da Unicode kod noktası olarak ölçülür.
