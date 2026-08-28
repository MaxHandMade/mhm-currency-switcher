=== MHM Currency Switcher ===
Contributors: maxhandmade
Tags: woocommerce, currency, multi-currency, currency switcher, exchange rate
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce
WC requires at least: 7.4
WC tested up to: 11.0

Multi-currency support for WooCommerce. Let your customers browse, shop, and checkout in their preferred currency with real-time exchange rates.

== Description ==

MHM Currency Switcher adds multi-currency support to your WooCommerce store. Customers can browse products, add items to their cart, and complete checkout in their preferred currency with real-time exchange rates.

**Key Features**

* Add multiple currencies with real-time exchange rates
* Cache compatibility mode — works behind a page cache without serving one visitor's currency to everybody
* Currency switcher via shortcode — usable in a text widget, nav menu, or Elementor
* Product page price display widget with country flags
* Navigation menu integration — add switcher to any WordPress menu
* Automatic exchange rate fetching (ExchangeRate-API)
* Fee & rounding configuration per currency
* Cookie-based currency persistence
* WooCommerce HPOS compatible
* Elementor widgets included
* Every currency WooCommerce offers
* Scheduled automatic exchange rate updates
* Geolocation-based currency detection
* Fixed prices per product
* "How to use" tab in the settings screen, naming every way the switcher can be placed

**Cache compatibility mode**

A page cache stores the HTML your server produced for whoever asked first. When
prices are converted on the server, that means the first visitor's currency is
what every later visitor is served. Cache compatibility mode, which is on by
default, avoids this: anonymous shop, archive and product pages are rendered in
your base currency, so the same cached page is correct for everyone, and the
browser converts the prices it can see afterwards through a REST request to this
plugin.

The split is deliberate. Only *displayed* prices are converted in the browser.
Cart, checkout, order totals, order emails and the WooCommerce REST API are
always calculated on the server in the currency the customer actually chose, so
the amount charged cannot be altered from the browser.

You can switch the mode off under **WooCommerce > MHM Currency >
Advanced**, in which case prices are converted on the server as they were before
this feature existed. Read "Known limits" below before deciding either way —
both settings have consequences, and they are different ones.

== Installation ==

1. Upload the `mhm-currency-switcher` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Make sure WooCommerce is installed and activated.
4. Go to **WooCommerce > MHM Currency** to configure your currencies and exchange rates.

== Frequently Asked Questions ==

= What are the shortcodes? =

`[mhmcs_currency_switcher]` renders the currency dropdown. It accepts one
attribute, `size`, which may be `small`, `medium` or `large`; leave it out to
use the size saved in Display Options.

`[mhmcs_currency_prices]` renders the same product price in several currencies.
Its attributes are all optional:

* `currencies` — comma-separated codes, e.g. `currencies="USD,EUR"`. Without
  it, the currencies chosen in Display Options are used. Codes you have not
  configured as currencies are ignored.
* `product_id` — price a specific product instead of the one being viewed.
* `price` — price a specific amount instead of a product's.
* `show_flags` — `true` or `false`, overriding the saved Display Options
  setting.

Both are also available as Elementor widgets.

= How many currencies can I add? =

As many as your shop needs — every currency WooCommerce offers can be enabled,
and nothing is held back for a paid version. The REST API refuses a request
carrying more than 500 currency rows; that is a guard against oversized payloads
and is well above the number of codes WooCommerce itself offers, so the panel
cannot reach it.

= How are exchange rates fetched? =

Exchange rates are fetched from ExchangeRate-API in real time, either on demand or on a schedule you configure (hourly, twice daily, or daily) so your rates stay current without manual intervention.

If that source cannot be reached, the plugin falls back to the European Central Bank's daily reference rate feed before giving up and leaving your existing rates in place. Both are named, with their terms, under "External services" below. If your network blocks the fallback, the `mhmcs_fallback_rates_url` filter can point it somewhere else.

= Is the plugin compatible with WooCommerce HPOS? =

Yes. MHM Currency Switcher fully supports WooCommerce High-Performance Order Storage (HPOS / Custom Order Tables).

= Can a per-product fixed price be a sale price? =

No. A fixed price is stored per product and currency, not per price type, so the same amount is used for the regular price and the sale price. A product on sale in your base currency shows as not on sale in a currency you have given a fixed price to. If you need the sale to carry across, leave that currency to the exchange rate instead of fixing it.

= Is there a limit on how often the conversion endpoint can be called? =

Yes. When cache compatibility mode is on, prices on cached pages are converted through a public REST endpoint, and one address may call it 120 times a minute by default. Ordinary browsing is nowhere near that — a page makes one request. If your shop sits behind a reverse proxy or a CDN that makes every visitor look like the same address, raise or disable the limit with the `mhmcs_convert_rate_limit` filter. Note that the address is read from the proxy headers WooCommerce passes on, which it trusts unconditionally and which can be forged; the limit bounds accidental hammering rather than a determined attacker.

= I see a warning that cache compatibility is not being applied. What is it? =

Some themes and plugins define WooCommerce's cart constant on every page, usually to show a cart total in the header. When that happens the plugin treats every page as a checkout — the customer's money is at stake — and converts prices on the server, which is exactly what cache compatibility mode exists to avoid. Nothing looks wrong on the site: prices are still correct for whoever loads the page first, and then a page cache can serve that person's currency to everyone else. Because there is no visible symptom, the plugin says so in the admin instead. The notice clears itself as soon as a front-end page renders normally again.

= Why does the structured data show a different currency from the price on the page? =

With cache compatibility on, the page is generated in your base currency and the browser converts the prices afterwards, so the machine-readable product data a crawler reads stays in the base currency. This is deliberate and is not corrected: pinning it the other way would leave the data disagreeing with the page in the one case where the two currently agree — with cache compatibility switched off, where the page and the structured data are both converted on the server.

= Does the WooCommerce REST API return converted prices? =

Only when the request asks for a currency: `?currency=EUR` on a `wc/v3` product request converts `price`, `regular_price` and `sale_price` and adds a `currency_code` field. Without the parameter the response is pinned to your base currency, so the answer never depends on the cookies of whoever is calling. A per-product fixed price takes precedence over the exchange rate here, exactly as it does on the shop page.

One known limit: the `price_html` field is not pinned in the same way. It cannot be reached in that state by a normal `wc/v3` client — only by code that dispatches an internal REST request during a page render — so no integration sees it, but it is not consistent with the three numeric fields and is recorded here rather than left unsaid.

== Known limits ==

These are consequences of how cache compatibility mode works, not defects. They
are listed so you can decide with your eyes open.

= Turning the mode off does not restore the exact 1.0.0 behaviour =

Three fixes sit above the setting and stay in place whether it is on or off:
prices are no longer converted on admin screens or in admin AJAX (which used to
write a converted price into an order line item), `wc/v3` REST reads are pinned
to the base currency, and scheduled tasks and WP-CLI no longer convert. Switching
the mode off restores the 1.0.0 *display* behaviour — prices converted on the
server — and nothing else.

= Search engines and crawlers see your base prices =

With the mode on, the page a crawler fetches has not been through the browser,
so it carries base-currency prices, and so does the machine-readable product data
in it. See the structured data question above; the mismatch is deliberate.

= Converted prices replace the base ones a moment after the page appears =

The page arrives with your base-currency prices already on screen — nothing is
hidden waiting for JavaScript — and the browser swaps in the converted ones as
soon as its request comes back, each price fading over 200ms as it changes. On a
slow connection the base price is readable for longer before the swap. Visitors
who have asked their system for reduced motion get the swap without the fade.

= With JavaScript disabled, or the endpoint unreachable, base prices stay =

Nothing breaks and no error is shown to the visitor — the page simply keeps the
base-currency prices it was rendered with, and the reason is written to the
browser console. Cart and checkout are unaffected, because they never depended
on the browser in the first place.

= Variable products make one extra request, and some swatch plugins lose the price =

On a cacheable page the plugin forces WooCommerce to fetch variation prices over
AJAX, because the variations JSON WooCommerce would otherwise embed in the page
carries base-currency prices that its own scripts write straight into the page.
The cost is one request when a visitor picks a variation, and that
`data-product_variations` is `false`: third-party colour or size swatch plugins
that read prices out of that JSON instead of asking WooCommerce may stop showing
a price. If you use such a plugin, check a variable product before going live.

= The mini-cart relies on WooCommerce cart fragments =

A mini-cart is rendered on every page, so it cannot be classified per request.
It is rendered in the base currency and then corrected by WooCommerce's own cart
fragment refresh, which is a server-side conversion. If cart fragments are
disabled on your site — some themes and optimisation plugins dequeue them — the
cached mini-cart total stays in the base currency while the rest of the page
converts. The plugin watches for this and says so in the admin when it happens;
a site with no mini-cart at all is never warned about it.

= A cart or checkout on a page WooCommerce does not know about must be excluded from your cache =

Page caches exclude cart and checkout automatically because they recognise the
pages WooCommerce assigned. If you have put a cart or checkout shortcode or block
on some other page, exclude that page yourself. Two things go wrong otherwise:
the conversion decision flips to "convert" partway through the render, so the
rest of the page is printed already converted and without the markers the browser
looks for, and blocks-based cart and checkout embed their amounts in the page as
JSON while rendering. Either way the first visitor's currency is what the cache
then hands to everyone. Cart contents are personal anyway; such a page should not
be cached.

= `?currency=` multiplies your cache entries =

A currency can be requested in the URL, and a cache treats every distinct URL as
a separate entry, so linking to `?currency=EUR` and `?currency=GBP` stores the
same page more than once. The switcher itself does not produce these URLs — it
writes a cookie and leaves the address alone. On a cached page it converts the
prices where they stand; on the cart page, for a logged-in visitor, or with
cache compatibility switched off, it reloads instead. Either way the URL is the
one the visitor was already on, so no extra cache entry is created.

A `?currency=` link also applies to that page view only: it deliberately sets no
cookie, so the next page the visitor opens is back in your base currency unless
they use the switcher. That is not an oversight — a link that silently pinned a
currency could show one currency in the catalogue while the cart, which reads
the cookie, charged another. If you want a campaign link that sticks, send
visitors to a page carrying the switcher rather than relying on the parameter.

= WooCommerce Analytics adds different currencies together =

An order is stored in the currency the customer paid in, and WooCommerce
Analytics reports every order's figures in your store currency without
converting them back. A 4.38 USD order is counted as 4.38 in your base
currency, so once you take orders in more than one currency the revenue
figures in Analytics, and the totals in the customer panel on the order
screen, are sums of unlike amounts. The orders themselves are correct — each
one keeps its own currency, total and the exchange rate it was placed at, and
this plugin stores that rate on the order. It is the aggregate reports that
cannot be read as money. Nothing in this plugin can fix that from the outside;
if you need accurate multi-currency reporting, export the orders and convert
them using the rate recorded on each one.

= Logged-in visitors are converted on the server =

Logged-in visitors take the server-side path, which is correct as long as your
cache does what nearly all of them do and never serves cached pages to logged-in
users. An edge cache or CDN configured to cache without looking at cookies is the
exception, and there a logged-in visitor's converted page can be stored and
served on. If you cache at the edge, confirm it varies on the login cookie.

== Screenshots ==

1. A shop priced in US dollars, as a visitor sees it after choosing Turkish
   lira. The page itself was served in the shop's base currency; the prices
   were converted afterwards.
2. The switcher added to a site's navigation menu, with its list open.
3. Manage Currencies — each currency has its own rate, fee and rounding rules,
   and every row shows the converted price a customer would see.
4. Display Options — a live preview of the switcher, what it shows, how large
   it is, and whether product pages carry a multi-currency price list.
5. Advanced — geolocation, the automatic rate-update interval, cache
   compatibility mode, and whether removing the plugin deletes its data.
6. How to use — every way the switcher can be placed, including the navigation
   menu item, with copyable code and the price-list shortcode's attributes.

== External services ==

This plugin connects to two third-party services to keep currency conversion
rates up to date. What each one is sent, and when, is described separately
below because the two are not the same. Both requests are made with PHP's
`WP_Http` transport, which in WordPress's default configuration sends a
`User-Agent` header of the form `WordPress/{version}; {your site's URL}` --
so the site's own address leaves with every request to either service, not
just the data described below. That header is filterable
(`http_headers_useragent`, `http_request_args`), so a site that has changed
it will send something different.

**ExchangeRate-API**

What it is: a commercial exchange-rate API, used as the primary source of
exchange rates.

What is sent, and when: the three-letter base currency code you have
configured (for example `USD`), sent as part of the request URL --
`https://api.exchangerate-api.com/v4/latest/{BASE_CURRENCY}` -- when you
press "Sync rates" in the admin panel, and on the schedule you configure
under automatic rate updates (hourly, twice daily, or daily). No other data
from your site is included.

Terms of service and privacy policy: https://www.exchangerate-api.com/terms
(ExchangeRate-API publishes its privacy policy inside that same page rather
than on a separate one.)

**European Central Bank (ECB) daily reference rates**

What it is: the ECB's public daily reference-rate feed, used as the fallback
when ExchangeRate-API cannot be reached.

What is sent, and when: nothing beyond the User-Agent described above. The
feed is a fixed, parameter-free address --
`https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml` -- so no
currency code or other value is sent to the ECB; the same document is
returned to every requester. It is only requested when ExchangeRate-API's
request has failed. The feed is EUR-based and covers roughly thirty
currencies rather than the hundreds ExchangeRate-API carries; if your base
or a target currency is outside that set, this source returns nothing and
your existing rates are left unchanged until the next attempt.

The ECB does not publish a document titled "Terms of Service." Its terms of
use are stated on its Disclaimer & Copyright page, which is the closest
equivalent and is linked below.

Disclaimer & Copyright (terms of use): https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html
Privacy statement: https://www.ecb.europa.eu/services/data-protection/privacy-statements/html/ecb.privacy_statement_website.en.html

The ECB's reference-rates page separately states that using these rates for
transaction purposes is strongly discouraged:
https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html
That page, not the two links above, is the source of that caution. This
plugin uses the feed to convert prices for display and checkout, which is
the kind of transactional use that notice is about; if that matters for your
shop, review that page before relying on this fallback.

**Redirecting a source**

If your network blocks the ECB feed, the `mhmcs_fallback_rates_url` filter
receives the URL, the base currency code and which source is being filtered
-- always `'ecb'`, since ECB is now the only fallback -- so the request can
be pointed elsewhere.

**Visitor geolocation (through WooCommerce)**

When "Enable geolocation-based currency detection" is switched on, the plugin
asks WooCommerce which country a visitor is in, using WooCommerce's own
`WC_Geolocation` API. Depending on how your site is configured, WooCommerce
answers that either from a local MaxMind database or by contacting the remote
geolocation service it is configured to use — the request and the service are
WooCommerce's, not this plugin's, and this plugin sends nothing itself. The
setting is off unless you turn it on.

WooCommerce geolocation documentation:
https://woocommerce.com/document/maxmind-geolocation-integration/

== Source code ==

The settings screen is a React application, and what ships inside the plugin is
the compiled bundle at `admin-app/build/index.js`. The readable source it is
built from is not in the package, so here is where to find it and how to
reproduce the build.

Full source, including the unminified JavaScript:
https://github.com/MaxHandMade/mhm-currency-switcher

The source of the bundle is `admin-app/src/`. It is compiled with WordPress's
own build tooling, @wordpress/scripts, and nothing else:

`npm install`
`npm run build`

That writes `admin-app/build/index.js` together with the `index.asset.php`
dependency map the plugin reads when enqueuing the script. No other build step,
minifier or bundler is involved, and no code is generated at install time or at
runtime.

== Changelog ==

= 2.0.0 =
* BREAKING: the two shortcode tags were renamed. `[mhm_currency_switcher]`
  is now `[mhmcs_currency_switcher]`, and `[mhm_currency_prices]` is now
  `[mhmcs_currency_prices]`. The old tags are gone; a page still holding one
  will show the raw text instead of the switcher, so update any page, post or
  template that uses them. WordPress.org's prefix check splits a prefix at the
  first underscore, which read the old tags as "mhm" -- three letters, under
  the four-letter minimum -- and a plugin cannot be reviewed under a name the
  review tool cannot attribute to it. Stored data is untouched: option names,
  order meta and per-product fixed prices all keep the names they had.
* Changed: the fallback exchange rate source is served from a different host.
  Rates now fall back to `latest.currency-api.pages.dev` instead of the
  jsDelivr CDN. Same upstream project, same payload; only the host changed.
  WordPress.org's review tool keeps a fixed list of public CDN domains and
  treats a shipped source naming one as an error, whatever the URL fetches.
  If your store restricts outbound requests to an allow-list, permit the new
  host -- or redirect it with the filter below.
* Added: a SECOND fallback rate source, so a blocked network is no longer a
  dead end. The host serving the first fallback is unreachable from some
  national networks -- Turkey, measured -- and not merely by DNS: resolving it
  over DNS-over-HTTPS and connecting straight to the real addresses with the
  correct SNI still times out, while other hosts on the same infrastructure
  answer normally. A server there cannot route around it by changing
  resolvers, the old host cannot be restored (that is the rule above), and the
  upstream project documents no third mirror. The chain now ends at
  Frankfurter, a different provider serving European Central Bank reference
  rates: no API key, commercial use permitted, no attribution required. The
  trade is coverage -- around thirty currencies rather than hundreds -- which
  is the right trade for a source reached only after two others have failed,
  and the set includes the currencies shops actually price in.
* Added: a filter, `mhmcs_fallback_rates_url`, which receives the URL, the base
  currency code and which source is being filtered (`currency-api` or
  `frankfurter`), so a store can move one source without moving the others.
* Added: an About tab, with links to the documentation site, the WordPress.org
  support forum and the issue tracker, plus how to reach the developer.
* Added: the Advanced tab now says WHEN the next automatic rate update is due,
  not only how often it repeats. It gives the scheduled time in the store's own
  timezone and format, how long that is from now, and states plainly that
  WordPress runs scheduled work on the first visit after that time rather than
  exactly on the hour.
* Fixed: currencies with no minor unit, or with three, were given two decimals.
  When a currency carried no explicit decimal setting the fallback was a fixed
  2 rather than the store's own configuration, so a shop adding JPY saw
  100.00 and one adding BHD lost a digit. The table is derived from ICU's
  minor-unit data; 37 of the 163 codes WooCommerce offers are not
  two-decimal currencies. No `intl` extension is required.
* Fixed: the Advanced tab claimed nothing was scheduled right after it
  scheduled something. Switching from "Manual only" to a recurring interval
  and saving armed the event, but the panel kept the schedule it had read when
  the page loaded and showed "Automatic updates are switched on, but no update
  is scheduled. Re-save this setting to schedule one." Re-saving changed
  nothing; only reloading the page did. The save response now carries the
  schedule it armed.
* Fixed: saving unrelated settings moved the next rate update. The panel sends
  the whole settings form, and the scheduler asked whether the interval had
  been submitted rather than whether it had changed -- so toggling anything on
  that screen re-armed the event at the current moment, shifting a daily
  store's update time and making the next visit run a full sync. Saving now
  reconciles the schedule with the stored interval instead: it re-arms on a
  real change, restores an event that has gone missing while the setting still
  promises one, and clears one left standing behind "Manual only".
* Fixed: rate-limited responses (HTTP 429) from the public conversion endpoint
  carried no cache headers, so a proxy or page cache could store a rejection
  and serve it to callers who were not over the limit.
* Fixed: the admin styles for the per-currency price fields on the product and
  variation screens moved out of the markup and into a stylesheet.
* Fixed: a translator note on one admin message was placed where the linter
  could not see it, so that string had been shipping without its note attached.
* For developers: the plugin's source and build steps are now named in
  readme.txt, and every URL the About tab shows is a plain link with no
  tracking parameters.

= 1.3.1 =
* Fixed: a currency with no usable exchange rate could still be used for
  prices, and the amount and the currency it was shown in came from different
  decisions. A currency added in the panel starts at a rate of 0 until the
  first sync, and a per-product fixed price was applied under it while the
  symbol and code fell back to the base currency -- a foreign amount wearing
  the base currency's identity, on the catalogue and in the cart and the
  charge. Such a currency now resolves to the base currency everywhere, which
  is what the panel already said would happen.
* Fixed: the WooCommerce REST API (wc/v3) applied a per-product fixed price for
  that same unusable currency when one was asked for with `?currency=`, while
  every other field in the response, and the currency a client reads it under,
  stayed in the base currency. Feeds, stock syncs and marketplace integrations
  took that price as fact.
* Fixed: orders placed in the shop's own currency recorded an exchange rate of
  0. That field is the record of what the customer was charged, so anything
  reconstructing it -- a report, an accounting export, a refund -- had nothing
  to work from. New orders record a rate of 1. Orders already placed keep the
  value they were saved with.
* Fixed: a save that never reached the database was reported as a success.
  This affected the currency list, the settings screen, and all three ways
  rates are synced -- the panel button, the hourly schedule and WP-CLI -- and
  in the sync case the "last synced" time moved forward regardless, so the
  panel said the rates were current while it served the old ones. Every one of
  them now reports the failure, and saving again with nothing changed is still
  reported as a success.
* Fixed: upgrading from a pre-0.3.0 version could lose the currency
  configuration. The migration copied the old settings across, then deleted
  the originals and marked itself finished -- without checking that the copy
  had been written. If it had not, there was nothing left and nothing tried
  again. It now leaves everything in place and retries on the next request.
* Fixed: the rate, fee and rounding fields accepted numbers that cannot be
  stored -- text, a value too large to represent, or a negative rate -- and a
  single one of them discarded every other currency in the same save. They are
  now corrected and the panel names each correction, as it already did for the
  decimal count.
* Fixed: a per-product fixed price entered as a number too large to represent
  was stored in a form that reads back as zero, which offered the product for
  nothing in that currency. A negative fixed price was accepted as well. Both
  are now refused, on the product page and the variation rows alike.

= 1.3.0 =
* Added: the settings screen was rebuilt. Currencies are now one table where
  each row carries its own rate, fee and rounding controls and shows the price
  a customer would actually see in that currency — computed on the server with
  the store's own price formatter, not estimated in the browser.
* Added: a number format editor per currency — symbol, symbol position,
  decimals, and the decimal and thousand separators. WooCommerce stores one set
  of these for the whole shop because it assumes one currency; this plugin
  shows several. Until now the data was stored but there was no field to edit
  it with, so a currency saved with the wrong symbol — which happens when
  another multi-currency plugin is filtering WooCommerce at the time — could
  not be corrected from the panel at all.
* Added: a rate freshness indicator, on each currency row and on the Advanced
  tab. Where no sync has been recorded it says exactly that — "No sync
  recorded yet" — rather than claiming a sync never ran, so a shop upgrading to
  this version is not told its working rates are missing.
* Added: an option, off by default, to delete all of the plugin's data when the
  plugin is removed. Left off, your settings and the currency and exchange rate
  recorded on each order survive uninstalling. Those records are the only basis
  for multi-currency sales history and cannot be rebuilt afterwards. On a
  multisite network the switch clears the site the plugin is removed from.
* Added: the "How to use" tab now documents the navigation menu item, and says
  plainly that Appearance → Menus only appears when the active theme supports
  menus or widgets, which most block themes do not.
* Added: Turkish translations for everything above.
* Fixed: an exchange rate below 1 could not be typed into the panel. Typing
  0.0211 left 211 in the field, and 0.05 left 5, without warning. A shop whose
  base currency is weaker than the currencies it sells in has no rate above 1,
  so the field could not take a single realistic value. The rate, fee and
  rounding amounts were all affected, in 1.2.0 as well. All of them now keep
  what you type.
* Fixed: the switcher preview on Display Options left out the base currency and
  ignored the "show currency symbol" toggle, so it showed a different list from
  the one a visitor gets. It now matches. The product price widget's currency
  field, separately, shows how many of its five allowed currencies are chosen.
* Fixed: the product price list's five-currency limit was enforced only in the
  browser. A sixth currency sent to the REST API was stored and then silently
  dropped when the list rendered. The server now applies the same limit and
  reports it instead of dropping the extra quietly.
* Fixed: a variable product's advertised price range could be served from an
  old exchange rate. WooCommerce caches that range for up to 30 days, keyed by
  a hash this plugin only put the currency code into — so after a rate update
  the range kept coming from the old rate while every other price on the site
  used the new one. A shopper could read one range and be charged more than its
  top end. The key now includes the rate and rounding the amounts depend on.
* Fixed: `GET /settings` returned the whole stored option, including keys whose
  controls were removed in an earlier release. One of them, `provider_api_key`,
  is a credential you supplied. Saving settings has always dropped those keys,
  but a shop that had not pressed Save since then still held the value, and the
  read route handed it to anyone with the "manage WooCommerce" capability —
  which includes shop managers, who are not administrators. The read route now
  filters the same list the save route and the uninstaller do.
* Fixed: the currency picker's popover did not close on Escape, and closing it
  did not return keyboard focus to the button that opened it.
* Fixed: values typed into the new format fields are corrected rather than
  silently accepted — a separator longer than one character, a decimal count
  outside 0 to 4, or identical thousand and decimal separators — and the screen
  names every correction it made instead of changing your input without saying.
* Changed: the REST API now refuses a save or preview request carrying more than
  500 currency rows, rather than accepting a payload of any size. The panel
  cannot produce such a request — WooCommerce offers 163 currency codes in
  total — so the guard only fires on something that did not come from it.
* Changed: the settings screen is wider, 1200px rather than 900px. Below that
  width the currency table stacks into one card per currency, each field
  labelled, rather than being cut off at the edge.

= 1.2.0 =
* Added: a "How to use" tab in the plugin's settings screen. The plugin can be
  placed in five different ways — two shortcodes, two Elementor widgets and a
  navigation menu item — and none of them were named anywhere in the admin, so
  after adding currencies there was nothing to tell you why the switcher had
  not appeared on your site. The new tab lists every option with copyable code,
  a table of the price-list shortcode's attributes, and a note that the
  Appearance → Menus route only exists on classic themes.
* Fixed: five labels in the currency picker on the settings screen were written
  in Turkish, so they stayed Turkish in every other language, English included.
  They are English now, and the Turkish wording moved into the Turkish
  translation file.
* Fixed: the navigation menu switcher printed its CSS class twice in the menu
  item's class attribute. This was not visible on the storefront, but it was
  wrong.
* Fixed: when your browser does not allow a page to write to the clipboard, the
  copy buttons on the new tab select the code and tell you to copy it. That
  message named a Windows keystroke; it no longer names a key at all.

= 1.1.3 =
* Fixed: upgrading from a version older than 0.3.0 reset the plugin's settings,
  and on a shop that had configured currencies it lost the currency list
  entirely. The option names changed in 0.3.0 without a migration, and the
  current names are only written when the plugin is activated — which does not
  happen during an in-place update. A one-time migration now carries the old
  settings and any configured currencies onto the current names, removes the
  old rows, and cancels the pre-0.3.0 rate-update task so it cannot keep firing.
  Sites that never configured anything simply get the standard defaults: the
  four currency codes the old installer wrote were in a format the plugin could
  never read, so they were never in use and are not carried over.
* Fixed: the Advanced tab showed "Daily" as the automatic rate-update interval
  on sites that had never chosen one, but nothing was scheduled — the setting
  screen and the scheduler disagreed about what an unset interval means. The
  screen now shows "Manual only", which is what the plugin actually does until
  you pick an interval.
* Fixed: the multi-currency price display showed prices for currencies you had
  not configured, and the figure it showed was the base price wearing the other
  currency's code and flag. On a shop with nothing configured, the Elementor
  price widget did this out of the box, because its default currency list is
  USD, EUR, GBP. Currencies you have not configured are now left out.
* Documented the `[mhm_currency_switcher]` and `[mhm_currency_prices]`
  shortcodes and their attributes, which this file advertised but never named.
* Fixed: on a shop running a second currency plugin, adding a currency could
  save it with the wrong symbol — every new currency picked up the symbol of
  whichever currency was being displayed, so a US Dollar currency could print
  your base currency's sign on the storefront. New currencies now take their
  symbol from WooCommerce's own currency table. Currencies added before this
  release keep whatever symbol was stored; if one of yours shows the wrong
  sign, remove it and add it again.

= 1.1.2 =
* **Requires WordPress 6.6.** The settings screen never loaded on 6.0 to 6.5:
  the admin bundle depends on a script handle WordPress only registers from
  6.6, and an unregistered dependency makes WordPress drop the script silently.
  Every release to date claimed 6.0 and none of them could show that screen on
  it. If you are on an older WordPress, staying on 1.1.1 keeps the storefront
  working — the conversion scripts have no such dependency — but the settings
  screen will not open there either.
* Fixed: every control in the currency table was unnamed for screen readers —
  the enable toggle, both rate controls, both fee controls and both rounding
  controls. Each now says what it changes and which currency it belongs to.
* Fixed: the currency picker was announced as an unlabelled button, because its
  visible label was never associated with the control.
* Declared compatibility with the Cart & Checkout Blocks. The plugin already
  worked with them — they read their amounts from the Store API, which is
  converted on the server — but without the declaration WooCommerce warned
  shop owners about the plugin on those screens.

= 1.1.1 =
* The settings screen no longer triggers WordPress's deprecation notice for the
  36px control size; its selects and text fields opt into the 40px size that
  becomes the default in WordPress 7.1.
* Corrected the Plugin URI, which pointed at a page that does not exist.
* Declared WooCommerce as a required plugin, and corrected the tested-against
  WooCommerce range to the versions the test suite actually runs (7.4 to 10.9).

= 1.1.0 =
* Cache compatibility mode, on by default. Anonymous shop, archive and product
  pages are rendered in your base currency so a page cache can serve the same
  HTML to everyone, and the browser converts the displayed prices afterwards.
  Cart, checkout, order totals, order emails and the WooCommerce REST API are
  still converted on the server.
* The currency switcher no longer reloads the page when cache compatibility is
  on; it sets the cookie, converts the prices in place and refreshes the
  mini-cart.
* Fixed: prices were converted on admin screens and in admin AJAX, which could
  write a converted price into an order line item.
* Fixed: `wc/v3` REST reads now return the base currency unless the request
  asks for one, so the response no longer depends on the caller's cookies.
* Fixed: scheduled tasks and WP-CLI no longer convert prices.
* Fixed: cart totals are recalculated when the visitor changes currency, so the
  mini-cart can no longer show an amount from the previous currency.
* Fixed: prices inside WooCommerce block themes are no longer overwritten with
  base amounts after the page has loaded.
* Fixed: a percentage fee per currency was never applied, because the admin
  screen and the sanitiser disagreed on the stored value.
* Fixed: a currency with a zero exchange rate no longer falls back to showing
  base prices as though they were converted.
* Fixed: rounding is now applied to shipping, fees and coupon discounts as well
  as product prices.
* Fixed: shipping tax is converted along with the shipping amount.
* The plugin now warns in the admin when cache compatibility is silently not
  being applied, and when a mini-cart is left in the base currency because
  WooCommerce's cart-fragment script is not loaded.
* New public REST endpoint `POST mhmcs/v1/convert`, rate limited to 120
  requests a minute per address (`mhmcs_convert_rate_limit` filter).
* See "Known limits" above for the accepted trade-offs of cache mode.

= 1.0.0 =
* First public release.
* All features are available to everyone: unlimited currencies, automatic
  exchange rate updates, geolocation-based currency detection, fixed
  per-product prices, and a WooCommerce REST API currency filter.
* Identifiers renamed to the `mhmcs` prefix. Settings from earlier
  development builds are not carried over.
