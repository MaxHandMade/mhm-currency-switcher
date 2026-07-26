# Ölü Kontrol Tasfiyesi + readme Doğruluğu — Uygulama Planı (Plan A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eklentinin ayar arayüzündeki hiçbir şey yapmayan 16 kontrolü tasfiye etmek (7'sini kaldır, 9'unu gerçekten çalıştır) ve `readme.txt`'nin var olmayan özellikleri duyurmasını durdurmak.

**Architecture:** Üç kümenin kesişimiyle çıkarılan envanter (sanitizer'ın kaydettiği ↔ React'ın yazdığı ↔ `src/`'nin okuduğu) tek tek kapatılır. Kaldırma tarafında hem UI hem sanitizer hem **aktivasyon varsayılanı** temizlenir — yalnız sanitizer'dan silmek yetmez, aktivasyon hook'u anahtarı her temiz kurulumda yeniden tohumlar. İmplementasyon tarafında her ayar için "kaydet → oku → render'da etkisini gör" üçlüsü teste bağlanır.

**Tech Stack:** PHP 7.4+ · WordPress 6.0+ · WooCommerce · PHPUnit 9.6 (saf stub bootstrap) · React (`@wordpress/scripts`) · PHPCS (WPCS) · PHPStan L6

**Kaynak spec:** `docs/superpowers/specs/2026-07-26-cache-friendly-switcher-design.md` §8.2 (16 satırlık envanter tablosu) + §10 (readme düzeltmeleri). Envanter 5 turluk bağımsız denetimin ürünüdür; **tabloyu tek doğruluk kaynağı say**.

## Global Constraints

- Prefix **`mhmcs_` / `MHMCS_`** — yeni hiçbir tanımlayıcı başka prefix kullanmaz.
- Metin domaini **`mhm-currency-switcher`**; her kullanıcıya görünür string `__()`/`esc_html__()` içinde **literal** olarak yazılır (değişken interpolasyonu yasak).
- PHP tabanı **7.4** — `str_contains`, `match`, named arguments, enum **kullanma**.
- Kapılar her task sonunda: `WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage` → **0 hata**, `composer lint` → **0 ERROR** (ağaçta dosya başına dağılmış ~20 önceden var olan WARNING var; bar yalnız ERROR'dır — uyarı sayısını `| tail` ile okuma, o yalnız son dosyanın bloğunu gösterir), `vendor/bin/phpstan analyse --memory-limit=1G` → **No errors**.
- React değişikliğinden sonra **`npm run build` zorunlu** ve `admin-app/build/` commit'e dahil edilir (bundle repoda tutuluyor).
- Ayar anahtarı ekleyen/çıkaran her değişiklik **üç yeri birden** günceller: sanitizer whitelist'i (`src/Admin/RestAPI.php`), React, aktivasyon varsayılanları (`mhm-currency-switcher.php`). Biri atlanırsa anahtar ya sessizce düşer ya yeniden doğar.
- `git mv`/silme sonrası **`.distignore` gözden geçirilir** — ZIP'e giren dosya listesi değişmiş olabilir.

**Test ortamı notu:** Bu makinede `/tmp/wordpress-tests-lib` başka bir projeden mevcut olduğu için `tests/bootstrap.php:428` WP entegrasyon dalına girip mysqli eksikliğinden çöker. Saf unit koşumu için **her zaman `WP_TESTS_DIR=/nonexistent` ön eki** kullan. (Bu dalın gerçekten ayağa kaldırılması Plan B'nin konusu.)

---

### Task 1: Ölü sağlayıcı/önbellek ayarlarını kaldır (#1-#4)

Envanter #1 `provider`, #2 `provider_api_key`, #3 `cache_duration`, #4 `round_prices`. `RateProvider` sabit ExchangeRate→Fawaz zinciri ve sabit `TRANSIENT_EXPIRY = 86400` kullanıyor; dördünü de okuyan yok.

**Files:**
- Modify: `src/Admin/RestAPI.php` (sanitizer whitelist'i, ~satır 207-255)
- Modify: `admin-app/src/components/tabs/AdvancedSettings.jsx` (sağlayıcı seçici + API anahtarı + önbellek süresi alanları)
- Modify: `mhm-currency-switcher.php:147-157` (aktivasyon varsayılanları)
- Test: `tests/Unit/Admin/RestAPITest.php`

**Interfaces:**
- Consumes: yok (ilk task)
- Produces: `RestAPI::sanitize_settings()` artık yalnız `auto_detect`, `rate_update_interval`, `product_widget` kabul eder. Task 6 ve 8 bu whitelist'e **ekleme** yapacak. Ayrıca yeni `RestAPI::LEGACY_SETTING_KEYS` sabiti (`array<int,string>`) — kaldırılan anahtarların tek listesi; Task 2 ve 3 bu listeye **ekleme** yapacak.

> 🔴 **Sanitizer'dan silmek YETMEZ.** `save_settings()` şöyle biter (`src/Admin/RestAPI.php:256-287`):
> ```php
> $merged = array_merge( $existing, $sanitized );
> update_option( self::SETTINGS_KEY, $merged );
> return new WP_REST_Response( array( 'success' => true, 'settings' => $merged ), 200 );
> ```
> `array_merge` **mevcut anahtarları korur**. Yani whitelist'ten çıkarılan bir anahtar, daha önce kaydedilmişse veritabanında sonsuza dek kalır ve her yanıtta React'e geri döner. `provider_api_key` kullanıcının yazdığı bir **sır** olduğu için bu sadece çöp değil, gereksiz saklanan gizli veridir. Bu yüzden task bir de **kalıntı temizliği** içerir.

- [ ] **Step 1: Ölü anahtarların hem düştüğünü hem temizlendiğini kanıtlayan testleri yaz**

`tests/Unit/Admin/RestAPITest.php` sonuna ekle:

```php
	/**
	 * Removed settings must not be persisted when a stale client still
	 * sends them.
	 *
	 * @return void
	 */
	public function test_save_settings_drops_removed_keys(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'provider'         => 'openexchangerates',
				'provider_api_key' => 'secret-key',
				'cache_duration'   => 3600,
				'round_prices'     => true,
				'auto_detect'      => true,
			)
		);

		$settings = $api->save_settings( $request )->get_data()['settings'];

		$this->assertArrayNotHasKey( 'provider', $settings );
		$this->assertArrayNotHasKey( 'provider_api_key', $settings );
		$this->assertArrayNotHasKey( 'cache_duration', $settings );
		$this->assertArrayNotHasKey( 'round_prices', $settings );
		$this->assertTrue( $settings['auto_detect'] );
	}

	/**
	 * Keys already stored from an earlier version must be purged, not
	 * carried forward by array_merge — provider_api_key is a secret the
	 * user typed and there is no longer anything that reads it.
	 *
	 * @return void
	 */
	public function test_save_settings_purges_previously_stored_dead_keys(): void {
		update_option(
			'mhmcs_settings',
			array(
				'provider'         => 'currencylayer',
				'provider_api_key' => 'left-over-secret',
				'cache_duration'   => 3600,
				'auto_detect'      => false,
			)
		);

		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params( array( 'auto_detect' => true ) );

		$settings = $api->save_settings( $request )->get_data()['settings'];

		$this->assertArrayNotHasKey( 'provider', $settings );
		$this->assertArrayNotHasKey( 'provider_api_key', $settings );
		$this->assertArrayNotHasKey( 'cache_duration', $settings );
		$this->assertSame( array(), array_intersect( RestAPI::LEGACY_SETTING_KEYS, array_keys( get_option( 'mhmcs_settings' ) ) ) );
	}
```

> `update_option`/`get_option` stub'ları `tests/bootstrap.php` içinde mevcut. Değilseler önce oraya ekle — bu iki testin çalışması için gerçek bir option deposu taklidi gerekir.

- [ ] **Step 2: Testlerin KIRMIZI olduğunu gör**

```bash
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --filter "removed_keys|dead_keys" --no-coverage
```

Beklenen: **ikisi de FAIL.** Birincisi `provider`/`cache_duration` hâlâ whitelist'te olduğu için; ikincisi hem o sebeple hem `RestAPI::LEGACY_SETTING_KEYS` sabiti henüz var olmadığı için (bu ikinci test önce *error* verebilir — sabit tanımlanınca düzgün kırmızıya döner, o hâlini de gör).

- [ ] **Step 3: Sanitizer'dan dört anahtarı çıkar**

`src/Admin/RestAPI.php` içinde şu blokları **sil**:

```php
		if ( isset( $params['provider'] ) ) {
			$sanitized['provider'] = sanitize_text_field( $params['provider'] );
		}

		if ( isset( $params['cache_duration'] ) ) {
			$sanitized['cache_duration'] = absint( $params['cache_duration'] );
		}
```

ve `round_prices` bloğunu (aynı fonksiyonda, `$sanitized['round_prices'] = ...` satırını içeren `if`).

- [ ] **Step 4: Kalıntı temizliğini ekle**

Sınıfın başına sabit (Task 2 ve 3 buraya ekleme yapacak):

```php
	/**
	 * Setting keys that were removed once their controls turned out to be
	 * dead. Purged from stored settings so they cannot linger in the
	 * database — `provider_api_key` is a user-supplied secret.
	 *
	 * @var array<int, string>
	 */
	public const LEGACY_SETTING_KEYS = array(
		'provider',
		'provider_api_key',
		'cache_duration',
		'round_prices',
	);
```

`save_settings()` içinde, `array_merge`'ten **hemen sonra**:

```php
		$merged = array_merge( $existing, $sanitized );

		// Drop keys whose controls no longer exist; array_merge would
		// otherwise carry them forward from $existing forever.
		foreach ( self::LEGACY_SETTING_KEYS as $legacy_key ) {
			unset( $merged[ $legacy_key ] );
		}

		update_option( self::SETTINGS_KEY, $merged );
```

- [ ] **Step 5: Aktivasyon varsayılanlarından çıkar**

`mhm-currency-switcher.php:147-157` içindeki varsayılan ayar dizisinden `'provider'`, `'cache_duration'`, `'round_prices'` anahtarlarını sil. Kalan anahtarlar (`auto_detect`, `rate_update_interval`, `product_widget`) korunur.

- [ ] **Step 6: React'ten üç alanı kaldır**

`admin-app/src/components/tabs/AdvancedSettings.jsx`: sağlayıcı `SelectControl`'ünü (Open Exchange Rates / CurrencyLayer seçenekleri dahil), API anahtarı `TextControl`'ünü ve önbellek süresi alanını sil. `settings.provider*` / `settings.cache_duration` / `settings.round_prices` okuyan yardımcı satırlar da gider. Sekmede kalan içerik: otomatik tespit + kur güncelleme aralığı.

- [ ] **Step 7: Testlerin YEŞİL olduğunu gör + tüm suite**

```bash
npm run build
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage
composer lint
vendor/bin/phpstan analyse --memory-limit=1G
```

Beklenen: PHPUnit `OK`, PHPCS `0 ERRORS`, PHPStan `No errors`.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(settings): ölü sağlayıcı/API-anahtarı/önbellek/round_prices kontrollerini kaldır

Dördünü de okuyan kod yok; RateProvider sabit zincir ve sabit 24s TTL
kullanıyor. Sanitizer + React + aktivasyon varsayılanları birlikte
temizlendi (yalnız sanitizer yetmez, aktivasyon anahtarı yeniden
tohumluyordu)."
```

---

### Task 2: `multilingual_mapping` kontrolünü kaldır (#5)

**Files:**
- Modify: `admin-app/src/components/tabs/AdvancedSettings.jsx:28-38` (ve ilgili render bloğu)
- Test: `tests/Unit/Admin/RestAPITest.php`

**Interfaces:**
- Consumes: Task 1'in temizlediği sanitizer
- Produces: yok

- [ ] **Step 1: Testi genişlet** — Task 1'deki `test_save_settings_drops_removed_keys` testine `'multilingual_mapping' => array( 'tr_TR' => 'Türk Lirası' ),` parametresini ve `$this->assertArrayNotHasKey( 'multilingual_mapping', $settings );` iddiasını **ekle** (değişken adı `$settings` — Task 1'deki testle aynı).

- [ ] **Step 2: Anahtarı kalıntı listesine ekle**

`RestAPI::LEGACY_SETTING_KEYS` sabitine `'multilingual_mapping'` ekle. Bu, daha önce kaydedilmiş kurulumlarda anahtarın veritabanından temizlenmesini sağlar.

- [ ] **Step 3: KIRMIZI mı?**

```bash
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --filter "removed_keys|dead_keys" --no-coverage
```

Beklenen: **PASS.** `multilingual_mapping` sanitizer whitelist'inde hiç yoktu, yani zaten düşüyordu; Step 2 kalıntı temizliğini de kapattı. Bu iddia bir **regresyon kilidi**: ileride biri anahtarı "eksik" sanıp whitelist'e eklerse test yakalar. Kırmızı görülmeden geçilen tek adım budur; gerekçesi, kaldırma işinin PHP tarafında değil UI tarafında olması.

- [ ] **Step 4: React'ten kaldır**

`AdvancedSettings.jsx`: `const multilingual = settings.multilingual_mapping || {};` satırını, `multilingual_mapping:` yazan `update` çağrısını ve dil-eşleme render bloğunun tamamını sil.

- [ ] **Step 5: Doğrula**

```bash
npm run build
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage
composer lint
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor(settings): ölü çoklu-dil eşleme kontrolünü kaldır

Ayar hiç kaydedilmiyordu (sanitizer whitelist'inde yok) ve okuyan kod
da yoktu. readme'deki iddiası Task 9'da düzeltiliyor."
```

---

### Task 3: Checkout sekmesini ve `payment_methods` alanını kaldır (#6, #7)

Checkout sekmesi "para birimi başına ödeme yöntemi kısıtlama" vaat ediyor ama `woocommerce_available_payment_gateways` filtresi **repoda hiç yok** — özellik mevcut değil.

**Files:**
- Delete: `admin-app/src/components/tabs/CheckoutOptions.jsx`
- Modify: `admin-app/src/App.jsx` (sekme kaydı)
- Modify: `src/Admin/Settings.php:114-141` (sekmeye gateway listesi besleyen blok)
- Modify: `src/Admin/RestAPI.php:542-546` (`payment_methods` sanitize bloğu)
- Test: `tests/Unit/Admin/RestAPITest.php`

**Interfaces:**
- Consumes: Task 1
- Produces: `ensure_currency_format()` artık `payment_methods` üretmez — Task 4 aynı fonksiyondan `countries`'i kaldıracak.

- [ ] **Step 1: Testi yaz**

```php
	/**
	 * Currency configs must no longer carry the dead payment_methods
	 * field (the per-currency gateway restriction feature never existed).
	 *
	 * @return void
	 */
	public function test_save_currencies_drops_payment_methods(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'EUR', 0.85 ),
						array( 'payment_methods' => array( 'stripe' ) )
					),
				),
			)
		);

		$data = $api->save_currencies( $request )->get_data();

		$this->assertArrayNotHasKey( 'payment_methods', $data['currencies'][0] );
	}
```

- [ ] **Step 2: KIRMIZI olduğunu gör**

```bash
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --filter test_save_currencies_drops_payment_methods --no-coverage
```

Beklenen: FAIL — alan hâlâ sanitize edilip döndürülüyor.

- [ ] **Step 3: `payment_methods` sanitize bloğunu sil**

`src/Admin/RestAPI.php` içinde `ensure_currency_format()` fonksiyonundaki `$currency['payment_methods'] = ...` bloğunu tamamen kaldır.

- [ ] **Step 4: Test fixture'ından da çıkar**

`tests/Unit/Admin/RestAPITest.php` içindeki `make_currency()` yardımcısından `'payment_methods' => array( 'all' ),` satırını sil. Aynı satır başka test dosyalarında da varsa (`grep -rn "payment_methods" tests/`) hepsinden sil.

- [ ] **Step 5: `payment_restrictions`'ı kalıntı listesine ekle**

`RestAPI::LEGACY_SETTING_KEYS` sabitine `'payment_restrictions'` ekle — daha önce kaydedilmiş kurulumlarda temizlensin.

- [ ] **Step 6: Sekmeyi ve gateway beslemesini kaldır**

- `admin-app/src/components/tabs/CheckoutOptions.jsx` dosyasını sil.
- `admin-app/src/App.jsx` içinden bu sekmenin `import`'unu ve sekme listesi girdisini sil.
- `src/Admin/Settings.php` içinde `WC()->payment_gateways->get_available_payment_gateways()` çağrısını ve onun sonucunu React'e geçiren blok'u sil.

- [ ] **Step 7: Doğrula**

```bash
npm run build
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage
composer lint
vendor/bin/phpstan analyse --memory-limit=1G
```

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(settings): var olmayan ödeme-kısıtlama özelliğini kaldır

Checkout sekmesi para birimi başına gateway kısıtlaması vaat ediyordu
ama woocommerce_available_payment_gateways filtresi hiç yazılmamıştı;
ayar kaydedilmiyordu bile. Sekme + currency.payment_methods alanı +
Settings.php gateway beslemesi kaldırıldı. readme iddiası Task 9'da."
```

---

### Task 4: `currency.countries` alanını kaldır (#15)

`ensure_currency_format()` bu alanı sanitize ediyor ama UI'sı yok ve okuyan yok — `CountryCurrencyMap` statik haritadır, bu alanı okumaz. #7'nin aynı fonksiyondaki kardeşi.

**Files:**
- Modify: `src/Admin/RestAPI.php:548-552`
- Test: `tests/Unit/Admin/RestAPITest.php`

**Interfaces:**
- Consumes: Task 3 (aynı fonksiyon)
- Produces: `ensure_currency_format()` nihai alan kümesi: `code`, `enabled`, `sort_order`, `rate`, `fee`, `rounding`, `format`

- [ ] **Step 1: Testi yaz**

```php
	/**
	 * The dead per-currency countries field must not be persisted.
	 *
	 * @return void
	 */
	public function test_save_currencies_drops_countries(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'EUR', 0.85 ),
						array( 'countries' => array( 'DE', 'FR' ) )
					),
				),
			)
		);

		$data = $api->save_currencies( $request )->get_data();

		$this->assertArrayNotHasKey( 'countries', $data['currencies'][0] );
	}
```

- [ ] **Step 2: KIRMIZI** — `WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --filter test_save_currencies_drops_countries --no-coverage` → FAIL.

- [ ] **Step 3: Sanitize bloğunu sil** — `src/Admin/RestAPI.php` içindeki `$currency['countries'] = ...` bloğu.

- [ ] **Step 4: Fixture'dan çıkar** — `make_currency()` içindeki `'countries' => array(),` satırını sil (ve `grep -rn "'countries'" tests/` ile kalan varsa).

- [ ] **Step 5: YEŞİL + kapılar**

```bash
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage
composer lint
vendor/bin/phpstan analyse --memory-limit=1G
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor(settings): ölü currency.countries alanını kaldır

payment_methods ile aynı fonksiyondaki kardeşi: sanitize ediliyordu,
UI'sı ve tüketicisi yoktu (CountryCurrencyMap statik harita)."
```

---

### Task 5: Bozuk `mhmcs_currencies` aktivasyon seed'ini düzelt

Aktivasyon hook'u `mhmcs_currencies`'i **düz kod listesi** olarak yazıyor; `CurrencyStore::load()` ise `{base_currency, currencies}` şeması bekliyor → seed **sessizce etkisiz**, yeni kurulum boş para listesiyle açılıyor.

**Files:**
- Modify: `mhm-currency-switcher.php:134-144`
- Test: `tests/Unit/Core/CurrencyStoreTest.php`

**Interfaces:**
- Consumes: yok
- Produces: yok

- [ ] **Step 1: Testi yaz**

`tests/Unit/Core/CurrencyStoreTest.php` sonuna ekle — aktivasyon seed'inin ürettiği şeklin `load()` tarafından okunabildiğini kanıtlar:

```php
	/**
	 * The activation seed shape must be readable by the store.
	 *
	 * Regression: activation wrote a flat list of currency codes while
	 * load() expects {base_currency, currencies}, so the seed was a
	 * silent no-op and fresh installs started empty.
	 *
	 * @return void
	 */
	public function test_activation_seed_shape_is_loadable(): void {
		$seed = array(
			'base_currency' => 'USD',
			'currencies'    => array(
				array(
					'code'    => 'EUR',
					'enabled' => true,
					'rate'    => array(
						'type'  => 'auto',
						'value' => 0.92,
					),
				),
			),
		);

		$store = new CurrencyStore();
		$store->set_data( $seed['base_currency'], $seed['currencies'] );

		$this->assertSame( 'USD', $store->get_base_currency() );
		$this->assertCount( 1, $store->get_currencies() );
	}
```

- [ ] **Step 2: Koş** — yeşil geçmesi beklenir (şekil doğruysa). Kırmızıysa `CurrencyStore` API'sini oku ve testi koda göre düzelt; **bu test hedef şekli sabitler**, asıl düzeltme Step 3'te.

- [ ] **Step 3: Aktivasyon seed'ini doğru şekle çevir**

`mhm-currency-switcher.php` içindeki `mhmcs_currencies` varsayılanını, Step 1'deki `$seed` yapısıyla **aynı şekle** getir (düz kod listesi yerine `base_currency` + tam currency config dizisi). Varsayılan para birimleri için mağazanın WooCommerce base currency'sini `base_currency` yap, `currencies` boş dizi ile başla — uydurma kur değeri tohumlama.

- [ ] **Step 4: Kapılar + Commit**

```bash
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage
composer lint
git add -A
git commit -m "fix(activation): mhmcs_currencies seed'i CurrencyStore şemasına uydur

Seed düz kod listesi yazıyordu, load() {base_currency, currencies}
bekliyor → seed sessizce etkisizdi."
```

---

### Task 6: Switcher görünüm ayarlarını gerçekten uygula (#8-#12)

Beş kontrol (`show_flag`, `show_name`, `show_symbol`, `show_code`, `size`) kaydedilmiyor ve `Switcher.php` hiçbirini okumuyor; bayrak+sembol+kod koşulsuz basılıyor, isim hiç basılmıyor. Admin'deki **canlı önizleme** kontrole tepki verdiği için kullanıcı çalıştığını sanıyor.

**Files:**
- Modify: `src/Admin/RestAPI.php` (sanitizer'a `switcher` alt-dizisi)
- Modify: `src/Frontend/Switcher.php` (`render_shortcode`, `build_options_list`)
- Modify: `mhm-currency-switcher.php` (aktivasyon varsayılanları)
- Test: `tests/Unit/Frontend/SwitcherTest.php`, `tests/Unit/Admin/RestAPITest.php`

**Interfaces:**
- Consumes: Task 1'in sadeleştirdiği sanitizer
- Produces: `mhmcs_settings['switcher']` = `array{show_flag:bool, show_name:bool, show_symbol:bool, show_code:bool, size:string}`. Varsayılanlar bugünkü görünümü korur: `show_flag=true`, `show_symbol=true`, `show_code=true`, `show_name=false`, `size='medium'`. Task 7 aynı desende `product_widget` alt-dizisini genişletecek.

> **Neden `show_name` varsayılanı `false`:** bugün isim hiç basılmıyor. `true` yapmak her canlı switcher'ın görünümünü sessizce değiştirirdi. Beyan edilen varsayılan ile mevcut davranış çeliştiğinde **mevcut davranış kazanır** — ölü parametre canlandırmanın kuralı budur.

- [ ] **Step 1: Sanitizer testini yaz**

```php
	/**
	 * Switcher display settings must round-trip through the sanitiser.
	 *
	 * @return void
	 */
	public function test_save_settings_persists_switcher_display_options(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'switcher' => array(
					'show_flag'   => false,
					'show_name'   => true,
					'show_symbol' => false,
					'show_code'   => true,
					'size'        => 'large',
				),
			)
		);

		$saved = $api->save_settings( $request )->get_data()['settings']['switcher'];

		$this->assertFalse( $saved['show_flag'] );
		$this->assertTrue( $saved['show_name'] );
		$this->assertFalse( $saved['show_symbol'] );
		$this->assertTrue( $saved['show_code'] );
		$this->assertSame( 'large', $saved['size'] );
	}

	/**
	 * An invalid size must fall back to medium rather than reaching the
	 * CSS class name unchecked.
	 *
	 * @return void
	 */
	public function test_save_settings_rejects_invalid_switcher_size(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array( 'switcher' => array( 'size' => '"><script>' ) )
		);

		$saved = $api->save_settings( $request )->get_data()['settings']['switcher'];

		$this->assertSame( 'medium', $saved['size'] );
	}
```

- [ ] **Step 2: KIRMIZI** — `--filter switcher_display|invalid_switcher_size` → FAIL (`switcher` anahtarı sanitizer'da yok).

- [ ] **Step 3: Sanitizer'a `switcher` alt-dizisini ekle**

`src/Admin/RestAPI.php`, `sanitize_settings()` içine:

```php
		if ( isset( $params['switcher'] ) && is_array( $params['switcher'] ) ) {
			$switcher = array();

			foreach ( array( 'show_flag', 'show_name', 'show_symbol', 'show_code' ) as $toggle ) {
				if ( isset( $params['switcher'][ $toggle ] ) ) {
					$switcher[ $toggle ] = (bool) $params['switcher'][ $toggle ];
				}
			}

			if ( isset( $params['switcher']['size'] ) ) {
				$size = sanitize_key( (string) $params['switcher']['size'] );

				$switcher['size'] = in_array( $size, array( 'small', 'medium', 'large' ), true )
					? $size
					: 'medium';
			}

			$sanitized['switcher'] = $switcher;
		}
```

- [ ] **Step 4: YEŞİL** — iki test geçmeli.

- [ ] **Step 5: Render testlerini yaz**

`tests/Unit/Frontend/SwitcherTest.php` içine — mevcut test kalıbını (store/detection kurulumu) birebir izle:

```php
	/**
	 * Turning the flag off must remove flag images from the output.
	 *
	 * @return void
	 */
	public function test_render_omits_flags_when_disabled(): void {
		$switcher = $this->create_switcher( array( 'show_flag' => false ) );

		$this->assertStringNotContainsString( 'mhm-cs-flag', $switcher->render_shortcode() );
	}

	/**
	 * Turning the code off must remove the ISO code from the label.
	 *
	 * @return void
	 */
	public function test_render_omits_code_when_disabled(): void {
		$switcher = $this->create_switcher( array( 'show_code' => false ) );

		$this->assertStringNotContainsString( 'EUR', $switcher->render_shortcode() );
	}

	/**
	 * The saved size must reach the wrapper CSS class.
	 *
	 * @return void
	 */
	public function test_render_applies_saved_size(): void {
		$switcher = $this->create_switcher( array( 'size' => 'large' ) );

		$this->assertStringContainsString( 'mhm-cs-size--large', $switcher->render_shortcode() );
	}

	/**
	 * Defaults must preserve today's appearance: flag + symbol + code,
	 * no currency name.
	 *
	 * @return void
	 */
	public function test_render_defaults_match_current_appearance(): void {
		$html = $this->create_switcher( array() )->render_shortcode();

		$this->assertStringContainsString( 'mhm-cs-flag', $html );
		$this->assertStringContainsString( 'EUR', $html );
		$this->assertStringContainsString( 'mhm-cs-size--medium', $html );
	}
```

Ayrıca `create_switcher( array $display_settings )` yardımcısını dosyanın mevcut kurulum koduna dayanarak yaz: `mhmcs_settings` option'ını `array( 'switcher' => $display_settings )` ile doldurup `Switcher` örneği döndürsün.

- [ ] **Step 6: KIRMIZI** — dördü de FAIL (ayarlar okunmuyor).

- [ ] **Step 7: `Switcher::render_shortcode()`'u ayarlara bağla**

- Ayarları oku: `$display = get_option( 'mhmcs_settings', array() )['switcher'] ?? array();`
- Varsayılanlar: `show_flag`, `show_symbol`, `show_code` → `true`; `show_name` → `false`; `size` → `'medium'`.
- **Shortcode att'ı ayarı ezer:** `$atts['size']` verilmişse o kazanır (mevcut davranış korunur), verilmemişse ayardaki `size` kullanılır.
- Bayrak `<img>` yalnız `show_flag` true iken basılır (hem seçili buton hem liste öğeleri).
- Etiket metni parçalardan kurulur: `show_symbol` → sembol, `show_code` → kod, `show_name` → para birimi adı. Ad kaynağı: `get_woocommerce_currencies()[ $code ] ?? $code` — `build_options_list()`'e `'name'` anahtarı olarak eklenir. Parçalar boşlukla birleşir; **hepsi kapalıysa** kod'a düşülür (boş buton üretme).
- Escaping mevcut desende kalır: `esc_attr`/`esc_html`/`esc_url`.

- [ ] **Step 8: YEŞİL + tüm suite**

```bash
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage
composer lint
vendor/bin/phpstan analyse --memory-limit=1G
```

- [ ] **Step 9: Aktivasyon varsayılanlarına `switcher` ekle**

`mhm-currency-switcher.php` varsayılan ayarlarına Step 7'deki varsayılanlarla aynı `switcher` alt-dizisini ekle.

- [ ] **Step 10: Commit**

```bash
npm run build
git add -A
git commit -m "feat(switcher): görünüm ayarlarını gerçekten uygula

show_flag/show_name/show_symbol/show_code/size kaydedilmiyordu ve
Switcher.php hiçbirini okumuyordu; admin'deki canlı önizleme tepki
verdiği için kontroller çalışıyor sanılıyordu. Varsayılanlar bugünkü
görünümü korur (isim kapalı) — beyan edilen varsayılan mevcut
davranışla çeliştiğinde mevcut davranış kazanır."
```

---

### Task 7: Ürün widget'ı bayrak anahtarını birleştir ve uygula (#13, #14)

Üç katmanlı ölü: JSX `show_flag` (tekil) yazıyor, sanitizer `show_flags` (çoğul) bekliyor, `ProductWidget` ikisini de okumuyor ve bayrağı koşulsuz basıyor. Elementor'daki "Show Flags" kontrolü de `render()`'a hiç geçmiyor.

**Files:**
- Modify: `src/Admin/RestAPI.php` (`product_widget` alt-dizisi: anahtar adı)
- Modify: `admin-app/src/components/tabs/DisplayOptions.jsx:168-171` (anahtar adı)
- Modify: `src/Frontend/ProductWidget.php` (render + shortcode att)
- Modify: `src/Integration/Elementor/PriceDisplayWidget.php:119-123` (kontrolü render'a geçir)
- Test: `tests/Unit/Frontend/ProductWidgetTest.php`

**Interfaces:**
- Consumes: Task 6'nın deseni
- Produces: kanonik anahtar **`show_flags`** (çoğul — sanitizer'daki mevcut ad korunur, JSX ona uyar). `ProductWidget::render_shortcode()` `show_flags` att'ını kabul eder.

- [ ] **Step 1: Testi yaz**

```php
	/**
	 * The widget must honour the show_flags setting instead of always
	 * printing flags.
	 *
	 * @return void
	 */
	public function test_widget_omits_flags_when_disabled(): void {
		$widget = $this->create_widget( array( 'show_flags' => false ) );

		$this->assertStringNotContainsString( 'mhm-cs-flag', $widget->render_shortcode( array() ) );
	}

	/**
	 * Flags stay on by default (current behaviour).
	 *
	 * @return void
	 */
	public function test_widget_shows_flags_by_default(): void {
		$widget = $this->create_widget( array() );

		$this->assertStringContainsString( 'mhm-cs-flag', $widget->render_shortcode( array() ) );
	}
```

`create_widget( array $widget_settings )` yardımcısını dosyanın mevcut kurulum koduna göre yaz (`mhmcs_settings['product_widget']` doldurur, en az bir etkin para birimi seeder).

- [ ] **Step 2: KIRMIZI** → FAIL (bayrak koşulsuz basılıyor).

- [ ] **Step 3: `ProductWidget`'ı ayara bağla** — widget ayarlarından `show_flags` oku (varsayılan `true`), bayrak `<img>`'ini koşula al. `render_shortcode()` att'ı verilmişse ayarı ezer.

- [ ] **Step 4: YEŞİL.**

- [ ] **Step 5: JSX anahtarını kanonikleştir** — `DisplayOptions.jsx` içindeki `show_flag` → **`show_flags`** (hem `checked={...}` okuması hem `updateProductWidget(...)` yazımı).

- [ ] **Step 6: Elementor kontrolünü render'a geçir**

`src/Integration/Elementor/PriceDisplayWidget.php::render()` içinde:

```php
		$output = $widget->render_shortcode(
			array(
				'currencies' => $settings['currencies'] ?? '',
				'show_flags' => ! empty( $settings['show_flags'] ),
			)
		);
```

Kontrolün Elementor tarafındaki `id`'si `show_flags` değilse (`:92-102`'ye bak) ya kontrolü yeniden adlandır ya da burada doğru `id`'yi oku — **ikisini birbirine uydur**, ikinci bir anahtar-adı uyuşmazlığı yaratma.

- [ ] **Step 7: Kapılar + Commit**

```bash
npm run build
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage
composer lint
vendor/bin/phpstan analyse --memory-limit=1G
git add -A
git commit -m "feat(product-widget): bayrak toggle'ını birleştir ve uygula

JSX show_flag yazıyor, sanitizer show_flags bekliyordu, ProductWidget
ikisini de okumuyordu; Elementor kontrolü render'a hiç geçmiyordu.
Kanonik ad show_flags."
```

---

### Task 8: Para birimi başına yuvarlama arayüzünü ekle (#16)

Ters yönlü üye: motor okuyor (`Converter`), sanitizer kabul ediyor, **UI'sı yok** — yalnız REST ile elle girilebiliyor, ama `readme.txt:26` duyuruyor.

**Files:**
- Modify: `admin-app/src/components/tabs/ManageCurrencies.jsx` (komisyon hücresinin yanına yuvarlama hücresi)
- Test: `tests/Unit/Admin/RestAPITest.php`

**Interfaces:**
- Consumes: `ensure_currency_format()`'ın mevcut `rounding` sanitize bloğu (`type` ∈ {disabled, nearest, up, down}, `value`, `subtract`)
- Produces: yok

- [ ] **Step 1: Round-trip testini yaz**

```php
	/**
	 * Rounding config saved from the UI must round-trip intact.
	 *
	 * @return void
	 */
	public function test_save_currencies_round_trips_rounding(): void {
		$api = $this->create_api();

		$request = new \WP_REST_Request();
		$request->set_json_params(
			array(
				'base_currency' => 'USD',
				'currencies'    => array(
					array_merge(
						$this->make_currency( 'EUR', 0.92 ),
						array(
							'rounding' => array(
								'type'     => 'nearest',
								'value'    => 1.0,
								'subtract' => 0.01,
							),
						)
					),
				),
			)
		);

		$saved = $api->save_currencies( $request )->get_data()['currencies'][0]['rounding'];

		$this->assertSame( 'nearest', $saved['type'] );
		$this->assertSame( 1.0, $saved['value'] );
		$this->assertSame( 0.01, $saved['subtract'] );
	}
```

- [ ] **Step 2: Koş** — **PASS beklenir** (sanitizer zaten doğru). Bu, UI eklemeden önce backend sözleşmesini sabitleyen kilittir; eksik olan yalnız arayüzdür.

- [ ] **Step 3: Yuvarlama hücresini ekle**

`ManageCurrencies.jsx`: komisyon hücresinin desenini birebir izleyerek `SelectControl` (`disabled`/`nearest`/`up`/`down`) + tip `disabled` değilken görünen `value` ve `subtract` `TextControl`'leri. Değerler `currency.rounding?.*`'tan okunur, `handleRoundingChange( index, field, val )` ile yazılır — mevcut `handleFeeTypeChange` desenini kopyala.

Etiketler (yeni çeviri stringleri):

```jsx
__( 'Rounding', 'mhm-currency-switcher' )
__( 'None', 'mhm-currency-switcher' )
__( 'Nearest', 'mhm-currency-switcher' )
__( 'Round up', 'mhm-currency-switcher' )
__( 'Round down', 'mhm-currency-switcher' )
__( 'Subtract', 'mhm-currency-switcher' )
```

> `'None'` etiketi komisyon hücresinde zaten var; aynı literal tekrar kullanılabilir (gettext aynı string'i tek girdide toplar).

- [ ] **Step 4: Kapılar**

```bash
npm run build
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage
composer lint
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(currencies): para birimi başına yuvarlama arayüzü

Motor yuvarlamayı zaten uyguluyordu ve readme duyuruyordu, ama ayar
yalnız REST ile elle girilebiliyordu."
```

---

### Task 9: readme doğruluğu + i18n

**Files:**
- Modify: `readme.txt` (satır 26, 34-35, 93-94)
- Modify: `README.md` (aynı iddialar varsa — `grep -n "payment\|multilingual\|rounding" README.md`)
- Modify: `languages/*.pot`, `languages/*.po`, derlenmiş `.mo`/`.l10n.php`/`.json`

**Interfaces:**
- Consumes: Task 1-8'in nihai özellik kümesi
- Produces: yok

- [ ] **Step 1: Var olmayan özellik iddialarını kaldır**

`readme.txt`:
- **Satır 34** `* Payment gateway restrictions per currency` → **sil** (Task 3 özelliği kaldırdı; hiç var olmamıştı)
- **Satır 35** `* Multilingual currency names` → **sil** (Task 2)
- **Satır 93-94** sürüm notlarındaki aynı iki iddiayı ("per-currency payment method restrictions", "multilingual currency names") cümleden çıkar; kalan liste dilbilgisel olarak düzgün kalsın.
- **Satır 26** `Fee & rounding configuration per currency` → **kalsın** (Task 8 onu doğru hale getirdi).

- [ ] **Step 2: Doğrula — kalan sahte iddia var mı?**

```bash
grep -rn "payment gateway\|payment method restriction\|multilingual" readme.txt README.md
```

Beklenen: **hiç sonuç yok**. Sonuç varsa o satırı da temizle.

- [ ] **Step 3: Yeni/kaldırılan string'ler için i18n boru hattını koştur**

Task 8 yeni string ekledi, Task 1-3 string kaldırdı. `wp-i18n` ajanını çağır (ya da boru hattını elle koştur): `.pot` yeniden üret → her locale için `msgmerge` → TR çevirilerini tamamla → fuzzy temizle → `.mo` + `.l10n.php` + React `.json` derle.

**Kritik:** React bundle'ının JSON'u **enqueue edilen bundle yolunun md5'iyle** adlanmalı (`admin-app/build/index.js`), kaynak `.jsx` adıyla değil — `wp i18n make-json --extensions=jsx` kaynak-adlı üretir ve WP runtime onu bulamaz. `--use-map` kullan veya kaynak JSON'ları tek bundle-adlı jed dosyasında birleştir.

- [ ] **Step 4: Ölçümü format sahibiyle yap**

```bash
msgattrib --untranslated languages/mhm-currency-switcher-tr_TR.po | grep -c "^msgid" || true
msgfmt --check languages/mhm-currency-switcher-tr_TR.po -o /dev/null
```

Beklenen: çevrilmemiş **0**, `msgfmt --check` **0 hata**. Elle regex ile sayma — çok satırlı `msgstr ""` girdilerini yanlış okur.

- [ ] **Step 5: Kapılar + Commit**

```bash
WP_TESTS_DIR=/nonexistent vendor/bin/phpunit --testsuite Unit --no-coverage
composer lint
vendor/bin/phpstan analyse --memory-limit=1G
git add -A
git commit -m "docs(readme): var olmayan özellik iddialarını kaldır + i18n yenile

readme hem Key Features listesinde hem sürüm notlarında ödeme-kısıtlama
ve çoklu-dil adları duyuruyordu; ikisi de kodda yoktu. Yuvarlama iddiası
Task 8 ile doğru hale geldiği için kaldı."
```

---

### Task 10: Kapanış — envanter doğrulaması + tarayıcı

Envanter dört turda dört kez yanlış sayıldı. Bu task, planın kendisinin eksik olmadığını **mekanik olarak** kanıtlar.

- [ ] **Step 1: Üç kümeyi yeniden kesiştir**

```bash
export LC_ALL=C.UTF-8
echo "### sanitizer kabul ettikleri"
grep -oE "params\['[a-z_]+'\]" src/Admin/RestAPI.php | sed "s/params\['//;s/'\]//" | sort -u
echo "### React'in yazdiklari"
grep -rhoE "update[A-Za-z]*\( *'[a-z_]+'" admin-app/src/ | sed "s/.*( *'//;s/'//" | sort -u
echo "### src/'nin okuduklari"
grep -rhoE "settings\[ *'[a-z_]+'" src/ | sed "s/settings\[ *'//;s/'//" | sort -u
```

Beklenen: **kaydedilen her anahtarın bir okuyucusu, yazılan her anahtarın bir kaydedicisi var.** Farkı tabloya dök; açıklanamayan tek bir anahtar bile kalırsa yeni task aç.

- [ ] **Step 2: Aktivasyon varsayılanlarını envanterle karşılaştır**

```bash
grep -n -A25 "add_option( 'mhmcs_settings'" mhm-currency-switcher.php
```

Beklenen: yalnız hâlâ yaşayan anahtarlar tohumlanıyor.

- [ ] **Step 3: Tarayıcı doğrulaması (Chrome DevTools MCP)**

`wp-env` skill'inden yerel URL + admin kimlik bilgilerini al. Sırayla:
- Ayarlar → **Advanced**: sağlayıcı/API anahtarı/önbellek/dil-eşleme alanları **yok**; otomatik tespit + kur aralığı var ve kaydediliyor.
- Sekme çubuğunda **Checkout sekmesi yok**.
- **Display**: 5 switcher toggle'ı + boyut; her birini değiştir → kaydet → **ön yüzde** switcher'ın gerçekten değiştiğini gör (canlı önizleme ile ön yüz artık aynı şeyi söylüyor). ⚠ `show_symbol` önizlemede tepki vermez (bilinen sınır) — ön yüzden doğrula.
- **Currencies**: yuvarlama hücresi görünür, kaydediliyor, sayfa yenilendiğinde değer geri geliyor; komisyon açılır listesinde "Percent" seçilip kaydedildikten sonra **yine "Percent" görünüyor** (round-trip).
- Konsol **0 hata**, network **0 adet 404**.

- [ ] **Step 4: Kanıtı kullanıcıya sun** — ekran görüntüleri + birebir çıktı. "Bitti" bundan sonra denir.

- [ ] **Step 5: Push + CI yeşil doğrula**

```bash
git push origin develop
gh run list --branch develop --limit 1 --json databaseId --jq '.[0].databaseId'
gh run watch <ID> --exit-status
gh run view <ID> --json conclusion,jobs --jq '{conclusion,jobs:[.jobs[]|{name,conclusion}]}'
```

Beklenen: `conclusion: success`, 6 job'ın hepsi success. "Push edildi" bir kapı **değildir**.

---

## Kapsam dışı (bilinçli)

- **Cache-friendly switcher** (spec §3-§7) → Plan C.
- **WC entegrasyon test suite'i** (spec §9B) → Plan B; bu plandaki testlerin hepsi mevcut saf-stub bootstrap ile koşar.
- **Yüzde komisyon enum bug'ı** (spec §8.4) → `8a08223` ile **kapatıldı**, burada tekrar ele alınmaz.
- **`ConversionContext`** ve `PriceFilter`/`FormatFilter` bağlam ayrımı → Plan C.
