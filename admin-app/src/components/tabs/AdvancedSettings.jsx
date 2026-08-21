/**
 * AdvancedSettings tab — geolocation, auto-update, cache, data retention.
 *
 * @package
 */

import { ToggleControl, RadioControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { FRESHNESS, freshnessState, formatHumanAge } from '../../lib/freshness';

/**
 * AdvancedSettings tab component.
 *
 * @param {Object}   props              Component props.
 * @param {Object}   props.settings     Current plugin settings.
 * @param {Function} props.onChange     Callback when settings change.
 * @param {Array}    props.currencies   Currency rows, used only to decide
 *                                      whether a sync signal has anything to
 *                                      say (see freshnessState()).
 * @param {?Object}  props.lastSync     Stored { time, base } from the last
 *                                      successful sync, or null when none has
 *                                      ever been recorded.
 * @param {string}   props.baseCurrency WooCommerce base currency code.
 * @return {JSX.Element} AdvancedSettings tab.
 */
const AdvancedSettings = ( {
	settings,
	onChange,
	currencies,
	lastSync,
	baseCurrency,
} ) => {
	const update = ( key, value ) => {
		onChange( { ...settings, [ key ]: value } );
	};

	/*
	 * 'manual', not 'daily': Plugin.php reads this same absent key as
	 * 'manual' and schedules nothing, so showing 'daily' told the shop
	 * owner rates were updating on a site with no cron event at all.
	 */
	const interval = settings.rate_update_interval || 'manual';

	// Computed once per render, the same way ManageCurrencies computes its
	// header pill: a sync timestamps every automatic currency alike, so
	// there is exactly one "how long ago" to say here too.
	const nowSeconds = Math.floor( Date.now() / 1000 );
	const humanAge = lastSync?.time
		? formatHumanAge( Math.max( 0, nowSeconds - lastSync.time ) )
		: '';

	const syncState = freshnessState(
		currencies,
		lastSync,
		baseCurrency,
		interval,
		nowSeconds
	);

	// MANUAL_ONLY has no entry here on purpose, mirroring ManageCurrencies: a
	// shop with no automatic currency has nothing for a sync signal to say,
	// and the lookup below resolves to undefined for it. Guarded with
	// `{ syncLine && ( … ) }`.
	const syncLine = {
		[ FRESHNESS.NO_RECORD ]: {
			tone: 'warn',
			text: __( 'No sync recorded yet', 'mhm-currency-switcher' ),
		},
		[ FRESHNESS.STALE_BASE ]: {
			tone: 'warn',
			text: __(
				"The store's base currency changed since the last sync — rates need re-syncing.",
				'mhm-currency-switcher'
			),
		},
		[ FRESHNESS.STALE_AGE ]: {
			tone: 'warn',
			text: sprintf(
				/* translators: %s: a human-readable interval, for example "3 days". */
				__( 'Rates last updated %s ago', 'mhm-currency-switcher' ),
				humanAge
			),
		},
		[ FRESHNESS.FRESH ]: {
			tone: 'ok',
			text: sprintf(
				/* translators: %s: a human-readable interval, for example "2 hours". */
				__( 'Rates updated %s ago', 'mhm-currency-switcher' ),
				humanAge
			),
		},
	}[ syncState ];

	return (
		<div className="mhm-cs-tab-content">
			<h3>{ __( 'Geolocation Detection', 'mhm-currency-switcher' ) }</h3>

			<div className="mhm-cs-settings-group">
				<ToggleControl
					label={ __(
						'Enable geolocation-based currency detection',
						'mhm-currency-switcher'
					) }
					help={ __(
						'Automatically detect visitor country and show matching currency.',
						'mhm-currency-switcher'
					) }
					checked={ settings.auto_detect || false }
					onChange={ ( val ) => update( 'auto_detect', val ) }
					__nextHasNoMarginBottom
				/>

				{ settings.auto_detect && (
					<p className="description">
						{ __(
							'CloudFlare sites are detected automatically. Other sites use WooCommerce MaxMind GeoIP database.',
							'mhm-currency-switcher'
						) }
					</p>
				) }
			</div>

			<hr />

			<h3>{ __( 'Automatic Rate Updates', 'mhm-currency-switcher' ) }</h3>

			<div className="mhm-cs-settings-group">
				<div className="mhm-cs-interval-control">
					<RadioControl
						label={ __(
							'Update interval',
							'mhm-currency-switcher'
						) }
						/*
						 * 'manual', not 'daily': Plugin.php reads this same
						 * absent key as 'manual' and schedules nothing, so
						 * showing 'daily' told the shop owner rates were
						 * updating on a site with no cron event at all.
						 */
						selected={ interval }
						options={ [
							{
								label: __(
									'Manual only',
									'mhm-currency-switcher'
								),
								value: 'manual',
							},
							{
								label: __( 'Hourly', 'mhm-currency-switcher' ),
								value: 'hourly',
							},
							{
								label: __(
									'Twice daily',
									'mhm-currency-switcher'
								),
								value: 'twicedaily',
							},
							{
								label: __( 'Daily', 'mhm-currency-switcher' ),
								value: 'daily',
							},
						] }
						onChange={ ( val ) =>
							update( 'rate_update_interval', val )
						}
					/>
				</div>

				{ syncLine && (
					<p className="mhm-cs-sync-line">
						<span
							className={ `mhm-cs-status mhm-cs-status--${ syncLine.tone }` }
						>
							{ syncLine.text }
						</span>
					</p>
				) }
			</div>

			<hr />

			<h3>{ __( 'Cache Compatibility', 'mhm-currency-switcher' ) }</h3>

			<div className="mhm-cs-settings-group">
				<ToggleControl
					label={ __(
						'Cache pages in the base currency',
						'mhm-currency-switcher'
					) }
					help={ __(
						'Pages are cached in the store base currency and displayed prices are converted in the browser, so page caching plugins work without any configuration. Cart, checkout and order totals are always converted on the server. Turning this off makes the storefront convert prices on the server again, which caches badly. It does not restore the 1.0.0 behaviour exactly: the admin, REST API and scheduled-task fixes stay active either way.',
						'mhm-currency-switcher'
					) }
					/*
					 * An absent key means ON — exactly how ConversionContext
					 * decision 4 and the activation default read it. Writing
					 * `|| false` here would show OFF while the server behaved
					 * as ON.
					 */
					checked={ settings.cache_compat !== false }
					onChange={ ( val ) => update( 'cache_compat', val ) }
					__nextHasNoMarginBottom
				/>
			</div>

			<hr />

			<div className="mhm-cs-card">
				<div className="mhm-cs-card__label">
					{ __( 'Data', 'mhm-currency-switcher' ) }
				</div>

				<div className="mhm-cs-row">
					<ToggleControl
						label={ __(
							'Delete all data when the plugin is removed',
							'mhm-currency-switcher'
						) }
						help={ __(
							'When off, your settings and the currency and exchange rate recorded on each order stay in place. Those records are the only basis for multi-currency sales history and cannot be recovered.',
							'mhm-currency-switcher'
						) }
						/*
						 * Absent means false, and that is the decision: a shop's
						 * multi-currency sales history is not something a plugin
						 * removes because nobody said otherwise.
						 */
						checked={ settings.delete_all_data === true }
						onChange={ ( val ) => update( 'delete_all_data', val ) }
						__nextHasNoMarginBottom
					/>

					{ settings.delete_all_data === true && (
						<div className="mhm-cs-danger-note">
							{ /* The warning glyph and the emphasis are markup, not part of the sentence a translator receives. */ }
							<span aria-hidden="true">⚠</span>
							<p>
								{ __(
									'When on, deleting the plugin also deletes each order’s currency code and applied exchange rate, per-product fixed prices, and all settings.',
									'mhm-currency-switcher'
								) }
							</p>
						</div>
					) }
				</div>
			</div>
		</div>
	);
};

export default AdvancedSettings;
