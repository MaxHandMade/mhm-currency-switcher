## i18n Kataloglarını Yenileme

Kullanıcıya görünür bir string ekleyip/değiştirip/kaldırdıktan sonra:

```bash
bin/make-i18n.sh
```

`wp i18n make-pot`'u doğrudan elle çağırma — bu betik `--exclude=admin-app/build,build,node_modules,vendor,tests,bin,docs,.superpowers` bayrağını sabitler. Bayrak olmadan, bu proje `bin/build-release.py` her koştuğunda `build/zip-staging/` altına eklentinin tam bir kopyasını yazdığı için (gitignored, ama diskte kalıcı) `make-pot` o kopyayı da tarar ve katalog `build/zip-staging/...` yollarına işaret eden yüzlerce yinelenen/ölü `#:` referansıyla kirlenir — 2026-07-26'da tam olarak bu oldu (119 kirli referans + aylar önce kaldırılmış 4 kontrolün string'leri). Betik çalıştıktan sonra `git diff languages/` ile gerçekten yeni olan string'lerin TR çevirisini elle tamamla; script yalnız çıkarır/birleştirir/derler, çevirmez.

---

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

1. `mhm-currency-switcher.php` içinden `MHMCS_VERSION` sabitini regex ile okur.
2. `.distignore` dosyasındaki desenleri yükler.
3. `build/zip-staging/mhm-currency-switcher/` altına temiz bir kopya çıkarır.
4. `build/mhm-currency-switcher.<version>.zip` dosyasını **POSIX (eğik çizgi)** yolları ile üretir.
5. ZIP içinde **tek bir kök klasör** (`mhm-currency-switcher/`) olduğunu doğrular.

Beklenen çıktı:

```text
[build] Plugin   : mhm-currency-switcher
[build] Version  : 1.1.1
[build] Patterns : 39 from .distignore
[build] Staged   : 331 files -> .../build/zip-staging/mhm-currency-switcher
[build] SUCCESS  : .../build/mhm-currency-switcher.1.1.1.zip
[build] Size     : 0.73 MB
[build] Verified : single root 'mhm-currency-switcher/'
```

Betik ZIP'i `build/` altına yazar — orası **staging**. Yayına giden kopyanın kanonik
yeri `c:/tmp/plugin-builds/<slug>.<version>.zip`; build sonrası oraya kopyala ve repo'nun
`build/` dizinini sil (bırakılırsa `wp i18n make-pot` o kopyayı da tarar — bkz. yukarıdaki
i18n bölümü).

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

**Önemli:** WordPress, plugin klasörü adını **ZIP içindeki tek kök klasörden** alır, ZIP dosya adından değil. Bu yüzden `mhm-currency-switcher.<version>.zip` dosya adı bir sorun teşkil etmez — içindeki tek kök `mhm-currency-switcher/` olduğu sürece WP kurulum sonrası `wp-content/plugins/mhm-currency-switcher/` olarak yerleştirir.

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
python -c "import zipfile; zf=zipfile.ZipFile('build/mhm-currency-switcher.<version>.zip'); print('\n'.join(sorted({n.split('/')[0] for n in zf.namelist()})))"
```

Tek satır çıktı olmalı: `mhm-currency-switcher`

### 2. Critical asset'lerin varlığını doğrula

```bash
python -c "import zipfile; zf=zipfile.ZipFile('build/mhm-currency-switcher.<version>.zip'); bundle=[n for n in zf.namelist() if 'admin-app/build/index.js' in n]; print('React bundle:', 'YES' if bundle else 'MISSING')"
```

`YES` görmelisin. `MISSING` → admin paneli çalışmaz, ZIP kırık.

### 3. WordPress'in kendi `unzip_file()` ile kurulumu simüle et

En net kanıt: WordPress'in dashboard'dan plugin yüklerken çağırdığı tam kodu manuel tetikle.

```bash
cat build/mhm-currency-switcher.<version>.zip | docker exec -i <wp-container> bash -c "cat > /tmp/t.zip"
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

### ZIP'in içinde olanlar (331 dosya ~ 0.73 MB — v1.1.1'de ölçüldü)

Kök seviyede **yalnız beş dosya**: `mhm-currency-switcher.php`, `readme.txt`,
`README.md`, `uninstall.php`, `LICENSE`. Geri kalanı dizinler:

- `src/` — tüm PHP class'ları
- `admin-app/build/` — **production React bundle (zorunlu runtime)**
- `assets/` — JS + CSS + 283 bayrak SVG
- `languages/` — `.pot`, `.po`, `.mo`, `.l10n.php` ve md5-adlı React `.json`

### Dışlananlar (`.distignore` ile)

- `vendor/`, `node_modules/`, **`composer.json`**, `composer.lock`, `package.json`, `package-lock.json`
- `bin/`, `tools/`
- `tests/`, `phpunit.xml.dist`, `.phpunit.result.cache`
- `phpcs.xml.dist`, `phpstan.neon`, `phpstan-bootstrap.php`
- `.github/`, `.git/`, `.vscode/`, `.idea/`, `.gitignore`
- `docs/`, `CHANGELOG.md`, `CONTRIBUTING.md`
- `*.log`, `*.zip`, `build_debug.txt`

## Release Yayınlama Akışı

**Sıra önemli:** kapı → ZIP → local doğrulama → canlı doğrulama → **EN SON** release.
Release'i öne almak, doğrulanmamış bir asset'i indirilebilir yapar.

```bash
# 1. Versiyonu bump et — ALTI yer, hiçbirini atlama:
#    - mhm-currency-switcher.php  header "Version:"
#    - mhm-currency-switcher.php  define MHMCS_VERSION
#    - readme.txt                 Stable tag
#    - readme.txt                 "== Changelog ==" altına yeni "= X.Y.Z =" bloğu
#    - CHANGELOG.md               yeni "## [X.Y.Z]" girdisi
#    - languages/*.pot ve *.po    Project-Id-Version
#      Sonra kataloğu YENİDEN DERLE (make-mo + make-php + make-json): .mo ve
#      .l10n.php sürümü kendi içlerinde taşır ve türetilmiş oldukları için
#      elle bump edilmez — derlenmezlerse .po 1.1.2 derken .l10n.php 1.1.1'de kalır.
#      (make-pot .pot'u günceller, .po'yu GÜNCELLEMEZ — v1.1.1'de .po 0.2.0'da kalmıştı
#       ve languages/ ZIP'e giriyor)
#
#    package.json'a ELLE dokunma: CI `npm ci` koşuyor, lock ayrışırsa kırılır.
#    npm version <X.Y.Z> --no-git-tag-version   # iki dosyayı birlikte günceller

# 2. Kapılar geçsin — çıktıyı `tail` ile okuma, POZİTİF eşleşme ara
composer test 2>&1 | grep -E '^(OK|FAILURES|ERRORS|Tests: )'
vendor/bin/phpcs --report=summary
vendor/bin/phpstan analyse --memory-limit=2G
npm run test:js

# 3. ZIP üret + kanonik konuma taşı
python bin/build-release.py

# ZIP üretilir üretilmez, staging dizini silinmeden: --source `src/` gibi
# köke bakar, ama admin-app/build/ ve languages/ gibi ZIP'e GİREN iki yüzeyi
# göremez -- bu yüzden ayrı bir --zip taraması gerekir, --source'un yerine
# değil, ONA EK olarak.
bash bin/check-legacy-tokens.sh --zip build/zip-staging/mhm-currency-switcher

mkdir -p /c/tmp/plugin-builds
cp build/mhm-currency-switcher.<version>.zip /c/tmp/plugin-builds/
rm -rf build/

# 4. ZIP içerik denetimi — tek desen grep'i YETMEZ, önceki release'e karşı tam diff
gh release download v<onceki> --pattern "*.zip" --dir /tmp/prev
unzip -l /tmp/prev/*.zip            | awk '{print $4}' | sort > /tmp/zip-prev.txt
unzip -l /c/tmp/plugin-builds/*.zip | awk '{print $4}' | sort > /tmp/zip-new.txt
comm -23 /tmp/zip-prev.txt /tmp/zip-new.txt   # kaldırılanlar — niyetli mi?
comm -13 /tmp/zip-prev.txt /tmp/zip-new.txt   # eklenenler  — kapsama uygun mu?
# ayrıca: 0 ters bölü, tek kök, admin-app/build/index.js var, .l10n.php'de 0 CRLF

# 5. release.localhost'ta doğrula (Plugin Check + tarayıcı)
#    "0 hata" TEK BAŞINA yeterli değil: kurulu kopyaya kasıtlı bir ihlal enjekte edip
#    kapının kırmızıya dönebildiğini gör, sonra geri al ve tekrar temiz al.

# 6. Commit + tag + push  (dosyaları TEK TEK ekle — `git add -A` kullanıcının yarım
#    işini de commit'ler)
git add mhm-currency-switcher.php readme.txt CHANGELOG.md languages/ package.json package-lock.json
git commit -m "chore(release): v<version>"
git tag v<version>
git push origin develop --tags        # default dal develop, main DEĞİL

# 7. EN SON: GitHub Release oluştur, kanonik ZIP'i ekle
gh release create v<version> /c/tmp/plugin-builds/mhm-currency-switcher.<version>.zip \
    --title "v<version>" \
    --notes-file /tmp/release-notes.md \
    --repo MaxHandMade/mhm-currency-switcher
```

Asset'i sonradan değiştirmek gerekirse (`--clobber` yalnız **aynı isimli** asset'i ezer —
isim kayarsa sessizce ikinci bir asset oluşur):

```bash
gh release upload v<version> /c/tmp/plugin-builds/mhm-currency-switcher.<version>.zip --clobber --repo MaxHandMade/mhm-currency-switcher
gh release view v<version> --repo MaxHandMade/mhm-currency-switcher --json assets --jq '.assets[] | "\(.name) — \(.size) bytes"'
```

## Hızlı Sorun Giderme

| Belirti | Sebep | Çözüm |
|---|---|---|
| ZIP içinde birden fazla kök klasör | `build-release.py` `Verified` adımında patlar | `.distignore`'da kök `build/` dışlaması olmadığına emin ol; `bin/build-release.py` nested `build/`'i prune etmiyor olmalı |
| Admin paneli beyaz ekran | ZIP'te `admin-app/build/index.js` yok | `.distignore`'dan `build/` satırını kaldır, script'te `if rel_root == "" and "build" in dirs` kontrolünün olduğunu doğrula |
| WordPress "eklenti yüklenemedi" diyor | ZIP'te `\` var (manuel `Compress-Archive`) | **`build-release.py` kullan**, PowerShell ile sıkıştırma |
| Plugin klasörü `mhm-currency-switcher.<version>` olarak kuruluyor | ZIP içinde tek kök `mhm-currency-switcher/` yok | Betiği yeniden çalıştır; `Verified : single root 'mhm-currency-switcher/'` satırını gör |
| `ERROR: could not find MHMCS_VERSION` | `mhm-currency-switcher.php` içinde `define` satırı regex'e uymuyor | Regex: `define( 'MHMCS_VERSION', 'x.y.z' );` formatına uymalı |
