/**
 * About tab — what this plugin is, where to get help, who made it.
 *
 * 🔴 Every link here is plain. No tracking parameters, no campaign tags, no
 * network request from this tab at all. The plugin directory's rule about the
 * admin dashboard says advertising "should be avoided" and then draws exactly
 * one hard line: "tracking referrals via those ads is not permitted". A tab
 * the shop owner opens on purpose is not the nagging that rule is aimed at —
 * a tagged URL would be the thing it forbids.
 *
 * The sibling-plugin block renders only when that plugin is NOT active. Its
 * absence is decided in PHP (Settings::about_payload), because that is where
 * the answer lives.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';
import { ExternalLink, Notice } from '@wordpress/components';

/**
 * About tab component.
 *
 * @param {Object} props       Component props.
 * @param {Object} props.about Static payload from mhmCsAdmin.about.
 * @return {JSX.Element} About tab.
 */
const About = ( { about } ) => {
	if ( ! about ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Plugin information could not be loaded.',
					'mhm-currency-switcher'
				) }
			</Notice>
		);
	}

	return (
		<div className="mhmcs-about">
			<section className="mhmcs-about-block">
				<h3>
					{ __( 'MHM Currency Switcher', 'mhm-currency-switcher' ) }
				</h3>
				<p className="mhmcs-about-version">
					{ sprintf(
						/* translators: %s: plugin version number, for example 1.3.1. */
						__( 'Version %s', 'mhm-currency-switcher' ),
						about.version
					) }
				</p>
				<p>
					{ __(
						'Multi-currency for WooCommerce: browse, shop and check out in a chosen currency, with rates you control.',
						'mhm-currency-switcher'
					) }
				</p>
			</section>

			<section className="mhmcs-about-block">
				<h3>{ __( 'Help and resources', 'mhm-currency-switcher' ) }</h3>
				<ul className="mhmcs-about-links">
					<li>
						<ExternalLink href={ about.docsUrl }>
							{ __( 'Documentation', 'mhm-currency-switcher' ) }
						</ExternalLink>
						<span className="mhmcs-about-hint">
							{ /*
							 * Said "Written in English." until an audit
							 * measured the site: /tr/ answers 200 and
							 * declares og:locale tr_TR. The hint exists so a
							 * non-English reader knows what to expect, and it
							 * was telling exactly that reader the wrong thing.
							 */ }
							{ __(
								'English and Turkish.',
								'mhm-currency-switcher'
							) }
						</span>
					</li>
					<li>
						<ExternalLink href={ about.forumUrl }>
							{ __(
								'Support forum on WordPress.org',
								'mhm-currency-switcher'
							) }
						</ExternalLink>
					</li>
					<li>
						<ExternalLink href={ about.issuesUrl }>
							{ __(
								'Report a bug on GitHub',
								'mhm-currency-switcher'
							) }
						</ExternalLink>
					</li>
				</ul>
			</section>

			<section className="mhmcs-about-block">
				<h3>{ __( 'Developer', 'mhm-currency-switcher' ) }</h3>
				<ul className="mhmcs-about-links">
					<li>
						<ExternalLink href={ about.siteUrl }>
							{ __( 'wpalemi.com', 'mhm-currency-switcher' ) }
						</ExternalLink>
					</li>
					<li>
						<a href={ `mailto:${ about.supportEmail }` }>
							{ about.supportEmail }
						</a>
					</li>
				</ul>
			</section>

			{ ! about.siblingActive && (
				<section className="mhmcs-about-block mhmcs-about-sibling">
					<h3>
						{ __(
							'Another plugin from us',
							'mhm-currency-switcher'
						) }
					</h3>
					<p>
						{ __(
							'MHM Rentiva turns WordPress into a vehicle rental site: fleet, availability, bookings and payments. Free on WordPress.org.',
							'mhm-currency-switcher'
						) }
					</p>
					<ExternalLink href={ about.siblingUrl }>
						{ __(
							'View MHM Rentiva on WordPress.org',
							'mhm-currency-switcher'
						) }
					</ExternalLink>
				</section>
			) }
		</div>
	);
};

export default About;
