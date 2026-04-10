# Release ZIP Nasıl Oluşturulur

> **TL;DR:** `python bin/build-release.py` çalıştır → `build/mhm-currency-switcher.<version>.zip` hazır → doğrudan WordPress admin'den yüklenebilir.

Bu doküman mhm-currency-switcher eklentisinin **WordPress'e kurulabilir** bir release ZIP'ini nasıl ürettiğimizi, neden bu yöntemi seçtiğimizi ve ZIP'in yayınlanmadan önce nasıl doğrulandığını anlatır.

## Kullanım

Python 3.8+ gerektirir. Harici bağımlılık yoktur (sadece stdlib).

```bash
cd /path/to/mhm-currency-switcher
python bin/build-release.py
```

Betik şunları yapar:

1. `mhm-currency-switcher.php` içinden `MHM_CS_VERSION` sabitini regex ile okur.
2. `.distignore` dosyasındaki desenleri yükler.
3. `build/zip-staging/mhm-currency-switcher/` altına temiz bir kopya çıkarır.
4. `build/mhm-currency-switcher.<version>.zip` dosyasını **POSIX (eğik çizgi)** yolları ile üretir.
5. ZIP içinde **tek bir kök klasör** (`mhm-currency-switcher/`) olduğunu doğrular.

Beklenen çıktı:

```text
[build] Plugin   : mhm-currency-switcher
[build] Version  : 0.4.0
[build] Patterns : 33 from .distignore
[build] Staged   : 329 files -> .../build/zip-staging/mhm-currency-switcher
[build] SUCCESS  : .../build/mhm-currency-switcher.0.4.0.zip
[build] Size     : 0.67 MB
[build] Verified : single root 'mhm-currency-switcher/'
```

## Neden Python — Neden `Compress-Archive` veya `git archive` değil

### PowerShell `Compress-Archive` neden iş görmüyor

Windows'ta `Compress-Archive` bir ZIP oluşturur ama yol ayırıcı olarak **ters eğik çizgi (`\`)** kullanır. Bu, ZIP spec'e uygun değildir — spec POSIX (eğik çizgi `/`) ister.

Sonuçlar:

- WordPress core'un `unzip_file()` fonksiyonu dosyayı açar ama log'a **uyarı** basar.
- Bazı hosting panellerinin "Plugin Yükle" akışları bu ZIP'i **reddeder**.
- WordPress.org plugin incelemesi bunu standart dışı kabul eder.

Python'un `zipfile` modülü her platformda **POSIX** yolları üretir.

### `git archive` neden yeterli değil

`git archive` sadece git'e commit edilmiş dosyaları içerir ve `.distignore` okumaz — yalnızca `.gitattributes` `export-ignore`'a bakar. İki ayrı hariç tutma kaynağını senkronize tutmak fazla gürültü. Ayrıca `admin-app/build/` gibi pre-built runtime asset'lerin git'e commit edilmesi gerekir, bu durumda `git archive` onları da çıkarır — ama manuel exclusion riskinden kurtarmaz.

## WordPress'e Kurulabilir ZIP Neye Benzemeli

WordPress admin → Eklentiler → Yeni Ekle → Eklenti Yükle yoluyla yüklenen bir ZIP şu kurallara uymalıdır:

| Kural | Doğru | Yanlış |
|---|---|---|
| Kök klasör | **Tek** klasör, eklenti slug'ı ile (`mhm-currency-switcher/`) | Versiyonlu isim, iç içe ZIP |
| Ana dosya | `mhm-currency-switcher/mhm-currency-switcher.php` | Kök düzeyinde |
| Yol ayırıcı | `/` (POSIX) | `\` (Windows) |
| ZIP dosya adı | Serbest — **kurulum klasör adını etkilemez** | — |

**Önemli:** WordPress, plugin klasörü adını **ZIP içindeki tek kök klasörden** alır, ZIP dosya adından değil. Bu yüzden `mhm-currency-switcher.0.4.0.zip` dosya adı bir sorun teşkil etmez — içindeki tek kök `mhm-currency-switcher/` olduğu sürece WP kurulum sonrası `wp-content/plugins/mhm-currency-switcher/` olarak yerleştirir.

## `.distignore` Nasıl Çalışıyor

ZIP'te **olmaması gereken** her şey `.distignore` içine yazılır. Desen formatı WordPress.org SVN standardı:

```text
# Plain name → herhangi bir yol segmentine eşleşirse dışlanır.
.git
vendor
node_modules

# Klasör → tüm alt ağacı dışlar.
tests/
docs/

# Glob pattern → fnmatch ile eşleştirilir.
*.log
*.zip
```

### ⚠️ `build/` Tuzağı (bu eklentiye özgü)

**`build/` pattern'ini `.distignore`'a EKLEME.** Çünkü bu eklentide `admin-app/build/` React bundle'ı runtime için **zorunludur** ve `build` plain-name pattern'i tüm alt ağaçta eşleşir — `admin-app/build/` dahil.

Kök `build/` (bizim staging çıktımız) bunun yerine `bin/build-release.py` içinde manuel prune edilir:

```python
# Sadece KÖK seviyede build/ klasörünü atla — nested admin-app/build/'e dokunma
if rel_root == "" and "build" in dirs:
    dirs.remove("build")
```

Tarih: `admin-app/build/` React bundle ZIP'ten düşmüştü çünkü hem `.distignore`'da `build/` vardı hem `build-release.py` nested dirs'i de prune ediyordu. Plugin admin paneli boş yükleniyordu.

## ZIP'i Yayınlamadan Önce Doğrulama

Betik kendi kendine tek kök klasör kontrolü yapar. Daha kapsamlı manuel kontrol için:

### 1. Yapıyı incele

```bash
python -c "import zipfile; zf=zipfile.ZipFile('build/mhm-currency-switcher.0.4.0.zip'); print('\n'.join(sorted({n.split('/')[0] for n in zf.namelist()})))"
```

Tek satır çıktı olmalı: `mhm-currency-switcher`

### 2. Critical asset'lerin varlığını doğrula

```bash
python -c "import zipfile; zf=zipfile.ZipFile('build/mhm-currency-switcher.0.4.0.zip'); bundle=[n for n in zf.namelist() if 'admin-app/build/index.js' in n]; print('React bundle:', 'YES' if bundle else 'MISSING')"
```

`YES` görmelisin. `MISSING` → admin paneli çalışmaz, ZIP kırık.

### 3. WordPress'in kendi `unzip_file()` ile kurulumu simüle et

En net kanıt: WordPress'in dashboard'dan plugin yüklerken çağırdığı tam kodu manuel tetikle.

```bash
cat build/mhm-currency-switcher.0.4.0.zip | docker exec -i <wp-container> bash -c "cat > /tmp/t.zip"
docker exec <wp-container> wp --allow-root eval '
require_once ABSPATH . "wp-admin/includes/file.php";
WP_Filesystem();
$r = unzip_file( "/tmp/t.zip", "/tmp/test" );
if ( is_wp_error( $r ) ) { echo "ERROR: " . $r->get_error_message(); }
else {
    $roots = array_diff( scandir( "/tmp/test" ), array( ".", ".." ) );
    echo "Plugin folder: wp-content/plugins/" . reset( $roots ) . "/" . PHP_EOL;
    echo "React bundle: " . ( file_exists( "/tmp/test/mhm-currency-switcher/admin-app/build/index.js" ) ? "YES" : "NO" );
}
'
```

Beklenen çıktı:

```text
Plugin folder: wp-content/plugins/mhm-currency-switcher/
React bundle: YES
```

## Ne Release ZIP'e Girer, Ne Girmez

### ZIP'in içinde olanlar (~329 dosya ~ 0.67 MB)

- `mhm-currency-switcher.php` — ana plugin dosyası
- `readme.txt`, `README.md`, `composer.json`
- `src/` — tüm PHP class'ları
- `admin-app/build/` — **production React bundle (zorunlu runtime)**
- `assets/` — JS + CSS + 159 bayrak SVG
- `languages/` — çeviri dosyaları

### Dışlananlar (`.distignore` ile)

- `vendor/`, `node_modules/`, `composer.lock`, `package-lock.json`
- `bin/`, `tools/`
- `tests/`, `phpunit.xml.dist`, `.phpunit.result.cache`
- `phpcs.xml.dist`, `phpstan.neon`, `phpstan-bootstrap.php`
- `.github/`, `.git/`, `.vscode/`, `.idea/`, `.gitignore`
- `docs/`, `CHANGELOG.md`, `CONTRIBUTING.md`
- `*.log`, `*.zip`, `build_debug.txt`

## Release Yayınlama Akışı

```bash
# 1. Versiyonu bump et:
#    - mhm-currency-switcher.php (header "Version:" ve define'daki MHM_CS_VERSION)
#    - readme.txt (Stable tag)
#    - CHANGELOG.md

# 2. Testler geçsin (CI veya local)
composer test

# 3. ZIP üret
python bin/build-release.py

# 4. Commit + tag + push
git add -A && git commit -m "chore(release): v<version>"
git tag v<version>
git push origin main --tags

# 5. GitHub Release oluştur, ZIP'i ekle
gh release create v<version> build/mhm-currency-switcher.<version>.zip \
    --title "v<version>" \
    --notes-file /tmp/release-notes.md \
    --repo MaxHandMade/mhm-currency-switcher
```

Asset'i sonradan değiştirmek gerekirse:

```bash
gh release upload v<version> build/mhm-currency-switcher.<version>.zip --clobber --repo MaxHandMade/mhm-currency-switcher
```

## Hızlı Sorun Giderme

| Belirti | Sebep | Çözüm |
|---|---|---|
| ZIP içinde birden fazla kök klasör | `build-release.py` `Verified` adımında patlar | `.distignore`'da kök `build/` dışlaması olmadığına emin ol; `bin/build-release.py` nested `build/`'i prune etmiyor olmalı |
| Admin paneli beyaz ekran | ZIP'te `admin-app/build/index.js` yok | `.distignore`'dan `build/` satırını kaldır, script'te `if rel_root == "" and "build" in dirs` kontrolünün olduğunu doğrula |
| WordPress "eklenti yüklenemedi" diyor | ZIP'te `\` var (manuel `Compress-Archive`) | **`build-release.py` kullan**, PowerShell ile sıkıştırma |
| Plugin klasörü `mhm-currency-switcher.0.4.0` olarak kuruluyor | ZIP içinde tek kök `mhm-currency-switcher/` yok | Betiği yeniden çalıştır; `Verified : single root 'mhm-currency-switcher/'` satırını gör |
| `ERROR: could not find MHM_CS_VERSION` | `mhm-currency-switcher.php` içinde `define` satırı regex'e uymuyor | Regex: `define( 'MHM_CS_VERSION', 'x.y.z' );` formatına uymalı |
