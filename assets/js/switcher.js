/**
 * MHM Currency Switcher — frontend dropdown interaction.
 *
 * Handles dropdown open/close, currency selection, cookie persistence,
 * and page reload on currency change.
 *
 * @package MhmCurrencySwitcher
 * @since 0.3.0
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
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

					// Set cookie: mhm_cs_currency={code}, path=/, max-age=30 days, SameSite=Lax.
					var maxAge = 30 * 24 * 60 * 60; // 30 days in seconds
					document.cookie = 'mhm_cs_currency=' + encodeURIComponent(code) +
						';path=/;max-age=' + maxAge +
						';SameSite=Lax';

					// Close dropdown and reload.
					dropdown.classList.remove('mhm-cs-open');
					button.setAttribute('aria-expanded', 'false');
					window.location.reload();
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

		/**
		 * Close all open currency switcher dropdowns.
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
	});
})();
