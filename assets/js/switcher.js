/**
 * MHM Currency Switcher — frontend dropdown interaction.
 *
 * Handles dropdown open/close, currency selection and cookie persistence,
 * and then applies the change in one of two ways:
 *
 * - Client-side, when price-converter.js is on the page: the cookie is
 *   written, `mhmcs:currency-changed` is announced, the indicator is synced
 *   and WooCommerce's cart fragments are invalidated. No page reload — the
 *   page was cached in the base currency and the converter rewrites it.
 * - By reloading, when it is not. A cart page, a logged-in visitor or a site
 *   with cache compatibility switched off is rendered per visitor and carries
 *   no converter script, so nothing would listen for the event and the
 *   switcher would appear to do nothing at all. Which of the two applies is
 *   NOT re-derived here: PHP answers it once, in Enqueue::enqueue_assets(),
 *   and passes it as `config.clientConversion`.
 *
 * On load the indicator is synced from the cookie, because in cache mode the
 * server renders the switcher NEUTRAL — showing the base currency, with no
 * `data-current` and no pre-selected option — so that the first visitor's
 * choice is not baked into the cached HTML for everybody else.
 *
 * @package MhmCurrencySwitcher
 * @since 0.3.0
 */
(function () {
	'use strict';

	/*
	 * Everything below the top level of the localized object keeps its real
	 * JSON types; Enqueue.php nests the payload one level down precisely so
	 * that `clientConversion` arrives as a boolean rather than "1"/"".
	 */
	var config = (window.mhmcsData && window.mhmcsData.config) || {};

	/*
	 * Last-resort cookie values, used only when the localized payload is
	 * absent altogether — i.e. when this file was enqueued by something other
	 * than Enqueue.php, in which case there is no contract to honour. Whenever
	 * the payload IS present, both values come from DetectionService's own
	 * constants and nothing here is hard-coded.
	 */
	var COOKIE_NAME_FALLBACK = 'mhmcs_currency';
	var COOKIE_DAYS_FALLBACK = 30;

	/**
	 * Normalise a currency code the same way the server does.
	 *
	 * DetectionService::sanitize_currency_code() uppercases, trims and
	 * requires exactly three ASCII letters.
	 *
	 * @param {*} value Raw value from a cookie or an event.
	 * @return {string|null} Three-letter code, or null.
	 */
	function sanitizeCode(value) {
		if ('string' !== typeof value) {
			return null;
		}

		var code = value.replace(/^\s+|\s+$/g, '').toUpperCase();

		return /^[A-Z]{3}$/.test(code) ? code : null;
	}

	/**
	 * Decode one percent-encoded cookie component.
	 *
	 * @param {string} raw Encoded value.
	 * @return {string} Decoded value, or the input when it is malformed.
	 */
	function decodeValue(raw) {
		try {
			return decodeURIComponent(raw.replace(/\+/g, ' '));
		} catch (error) {
			return raw;
		}
	}

	/**
	 * Read the currency cookie.
	 *
	 * @return {string|null} Raw cookie value, or null when absent.
	 */
	function readCookie() {
		var prefix = (config.cookieName || COOKIE_NAME_FALLBACK) + '=';
		var parts = document.cookie ? document.cookie.split(';') : [];
		var part;
		var i;

		for (i = 0; i < parts.length; i++) {
			part = parts[i].replace(/^\s+/, '');

			if (0 === part.indexOf(prefix)) {
				return decodeValue(part.slice(prefix.length));
			}
		}

		return null;
	}

	/**
	 * Write the currency cookie with the attributes PHP uses.
	 *
	 * 🔴 Byte-for-byte identical to price-converter.js writeCookie() and to
	 * DetectionService::set_currency() (design spec §5.3): 30 days, path `/`,
	 * SameSite=Lax, and `Secure` on https ONLY. The cookie has three writers
	 * now, so a single attribute out of step means the visitor's choice
	 * silently stops persisting in one mode and not the others — and `Secure`
	 * is the one that bites in both directions: a browser drops a `Secure`
	 * cookie set over plain http, so hard-coding it would break every
	 * non-https site. This script cannot call into price-converter.js (they
	 * are separate IIFEs and the converter is conditional), so the duplicate
	 * is deliberate; change one and change the other.
	 *
	 * @param {string} code Three-letter currency code.
	 * @return {void}
	 */
	function writeCookie(code) {
		var days = parseInt(config.cookieDays, 10) || COOKIE_DAYS_FALLBACK;
		var cookie = (config.cookieName || COOKIE_NAME_FALLBACK) +
			'=' + encodeURIComponent(code) +
			';path=/' +
			';max-age=' + (days * 24 * 60 * 60) +
			';SameSite=Lax';

		if ('https:' === window.location.protocol) {
			cookie += ';Secure';
		}

		document.cookie = cookie;
	}

	/**
	 * Point one switcher's indicator at a currency.
	 *
	 * The button's label and flag are COPIED from the matching dropdown
	 * option rather than rebuilt from the localized currency map. The option
	 * was rendered by Switcher::build_label(), which honours the admin's
	 * show_symbol / show_code / show_name settings; rebuilding the string here
	 * would mean a second implementation of that rule, free to drift from the
	 * one the same page already shows two lines below.
	 *
	 * An unknown code matches no option and is ignored — the rendered options
	 * are exactly the base currency plus the enabled ones, the same allowlist
	 * DetectionService::validate_code() applies.
	 *
	 * @param {Element} switcher One .mhm-cs-switcher element.
	 * @param {string}  code     Three-letter currency code.
	 * @return {void}
	 */
	function syncIndicator(switcher, code) {
		var options = switcher.querySelectorAll('.mhm-cs-option');
		var target = null;
		var i;

		for (i = 0; i < options.length; i++) {
			if (options[i].getAttribute('data-currency') === code) {
				target = options[i];
			}
		}

		if (!target) {
			return;
		}

		for (i = 0; i < options.length; i++) {
			options[i].classList.remove('mhm-cs-active');
		}

		target.classList.add('mhm-cs-active');
		switcher.setAttribute('data-current', code);

		var button = switcher.querySelector('.mhm-cs-selected');

		if (!button) {
			return;
		}

		var sourceLabel = target.querySelector('span');
		var buttonLabel = button.querySelector('.mhm-cs-label');

		if (sourceLabel && buttonLabel) {
			buttonLabel.textContent = sourceLabel.textContent;
		}

		var sourceFlag = target.querySelector('.mhm-cs-flag');
		var buttonFlag = button.querySelector('.mhm-cs-flag');

		if (sourceFlag && buttonFlag) {
			buttonFlag.setAttribute('src', sourceFlag.getAttribute('src'));
			buttonFlag.setAttribute('alt', sourceFlag.getAttribute('alt'));
		}
	}

	/**
	 * Point every switcher on the page at a currency.
	 *
	 * Re-queried rather than closed over, so a switcher injected after load
	 * (a widget refreshed by a cart fragment, for instance) is covered too.
	 *
	 * @param {string} code Three-letter currency code.
	 * @return {void}
	 */
	function syncAll(code) {
		var switchers = document.querySelectorAll('.mhm-cs-switcher');
		var i;

		for (i = 0; i < switchers.length; i++) {
			syncIndicator(switchers[i], code);
		}
	}

	/**
	 * Announce a currency change to price-converter.js.
	 *
	 * Dispatched on `document` and allowed to bubble, which is what reaches
	 * the converter's `window` listener as well; its scheduler collapses the
	 * pair into a single run.
	 *
	 * Returns false when the browser has no CustomEvent constructor, and the
	 * caller then falls back to reloading. That browser has no `fetch` either,
	 * so price-converter.js bailed out of its own accord and there is nothing
	 * listening in any case.
	 *
	 * @param {string} code Three-letter currency code.
	 * @return {boolean} Whether the announcement was made.
	 */
	function announceChange(code) {
		if ('function' !== typeof window.CustomEvent) {
			return false;
		}

		document.dispatchEvent(
			new window.CustomEvent('mhmcs:currency-changed', {
				bubbles: true,
				detail: { currency: code }
			})
		);

		return true;
	}

	/**
	 * Drop WooCommerce's cached cart fragments.
	 *
	 * WooCommerce caches the mini-cart markup in sessionStorage under
	 * `wc_cart_fragments_params.fragment_name`, and only reuses it when the
	 * stored `cart_hash_key` still matches the `woocommerce_cart_hash` cookie
	 * (see WooCommerce's assets/js/frontend/cart-fragments.js). A currency
	 * change does not alter the cart hash, so without this the mini-cart is
	 * restored in the PREVIOUS currency — on the next page load as well as
	 * this one, which is why the storage is cleared on the reload path too.
	 *
	 * Storage access is wrapped: it throws outright in Safari's private mode.
	 *
	 * @return {void}
	 */
	function clearFragmentStorage() {
		var params = window.wc_cart_fragments_params;

		if (!params) {
			return;
		}

		try {
			if (params.fragment_name) {
				window.sessionStorage.removeItem(params.fragment_name);
			}

			if (params.cart_hash_key) {
				window.sessionStorage.removeItem(params.cart_hash_key);
			}
		} catch (error) {
			// Nothing to do: the refresh below re-fetches regardless.
		}
	}

	/**
	 * Clear the cached fragments and ask WooCommerce for fresh ones.
	 *
	 * `wc_fragment_refresh` re-fetches through `wc-ajax=get_refreshed_fragments`,
	 * which ConversionContext classifies as a money context and therefore
	 * converts server-side — so the mini-cart comes back in the new currency
	 * without a page reload (design spec §5.4).
	 *
	 * Both guards are required and neither is paranoia. WooCommerce enqueues
	 * cart-fragments.js only where a cart widget exists, so its params object
	 * is absent on most pages; and jQuery is NOT declared as a dependency of
	 * this script on purpose, because adding one would load jQuery on every
	 * page of a shop that had shed it.
	 *
	 * @return {void}
	 */
	function refreshFragments() {
		clearFragmentStorage();

		if (window.wc_cart_fragments_params && window.jQuery) {
			window.jQuery(document.body).trigger('wc_fragment_refresh');
		}
	}

	/**
	 * Apply a currency the visitor just picked.
	 *
	 * @param {string} code Three-letter currency code.
	 * @return {void}
	 */
	function applyCurrency(code) {
		writeCookie(code);

		if (config.clientConversion && announceChange(code)) {
			syncAll(code);
			refreshFragments();

			return;
		}

		/*
		 * Server-rendered path. The fragment cache still has to go, or the
		 * reloaded page's freshly converted mini-cart is immediately
		 * overwritten from sessionStorage with the old currency.
		 */
		clearFragmentStorage();
		window.location.reload();
	}

	/**
	 * Close all open currency switcher dropdowns.
	 *
	 * @return {void}
	 */
	function closeAllDropdowns() {
		var openDropdowns = document.querySelectorAll('.mhm-cs-dropdown.mhm-cs-open');

		openDropdowns.forEach(function (dd) {
			dd.classList.remove('mhm-cs-open');
			var btn = dd.parentElement.querySelector('.mhm-cs-selected');
			if (btn) {
				btn.setAttribute('aria-expanded', 'false');
			}
		});
	}

	/**
	 * Wire every switcher on the page.
	 *
	 * @return {void}
	 */
	function init() {
		var switchers = document.querySelectorAll('.mhm-cs-switcher');

		if (!switchers.length) {
			return;
		}

		switchers.forEach(function (switcher) {
			var button = switcher.querySelector('.mhm-cs-selected');
			var dropdown = switcher.querySelector('.mhm-cs-dropdown');
			var options = switcher.querySelectorAll('.mhm-cs-option');

			if (!button || !dropdown) {
				return;
			}

			// Toggle dropdown on button click.
			button.addEventListener('click', function (e) {
				e.stopPropagation();
				var isOpen = dropdown.classList.contains('mhm-cs-open');

				// Close all other dropdowns first.
				closeAllDropdowns();

				if (!isOpen) {
					dropdown.classList.add('mhm-cs-open');
					button.setAttribute('aria-expanded', 'true');
				}
			});

			// Handle option selection.
			options.forEach(function (option) {
				option.addEventListener('click', function (e) {
					e.stopPropagation();
					var code = option.getAttribute('data-currency');

					if (!code) {
						return;
					}

					dropdown.classList.remove('mhm-cs-open');
					button.setAttribute('aria-expanded', 'false');

					applyCurrency(code);
				});
			});
		});

		// Close dropdowns on outside click.
		document.addEventListener('click', function () {
			closeAllDropdowns();
		});

		// Close dropdowns on Escape key.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				closeAllDropdowns();
			}
		});

		/*
		 * Sync the indicator from the cookie. In cache mode the server
		 * rendered this switcher showing the BASE currency, because the HTML
		 * it produced may be served to every later visitor; this is where the
		 * page becomes about the person actually looking at it.
		 */
		var stored = sanitizeCode(readCookie());

		if (stored) {
			syncAll(stored);
		}
	}

	/*
	 * The auto-detect case has no cookie to read. price-converter.js asks the
	 * server to geolocate, converts the prices and only then knows which
	 * currency this visitor got — so it announces it, and the indicator
	 * catches up here. Without this the button would read "$ USD" beside
	 * prices in ₺ on the visitor's very first page view.
	 *
	 * A separate event from `mhmcs:currency-changed` on purpose: the converter
	 * LISTENS to that one, so reusing it would have the two scripts triggering
	 * each other.
	 */
	document.addEventListener('mhmcs:currency-resolved', function (event) {
		var code = event && event.detail ? sanitizeCode(event.detail.currency) : null;

		if (code) {
			syncAll(code);
		}
	});

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
