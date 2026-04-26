/**
 * REST API client for MHM Currency Switcher admin.
 *
 * @package
 */

import apiFetch from '@wordpress/api-fetch';

const BASE = '/mhm-currency/v1';

/**
 * Fetch plugin settings.
 *
 * @return {Promise<Object>} Settings data.
 */
export const getSettings = () => apiFetch( { path: `${ BASE }/settings` } );

/**
 * Save plugin settings.
 *
 * @param {Object} data Settings to save.
 * @return {Promise<Object>} Save response.
 */
export const saveSettings = ( data ) =>
	apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } );

/**
 * Fetch configured currencies.
 *
 * @return {Promise<Object>} Currencies data.
 */
export const getCurrencies = () => apiFetch( { path: `${ BASE }/currencies` } );

/**
 * Save currencies configuration.
 *
 * @param {Object} data Currencies payload.
 * @return {Promise<Object>} Save response.
 */
export const saveCurrencies = ( data ) =>
	apiFetch( { path: `${ BASE }/currencies`, method: 'POST', data } );

/**
 * Trigger exchange rate synchronisation.
 *
 * @return {Promise<Object>} Sync response with rates.
 */
export const syncRates = () =>
	apiFetch( { path: `${ BASE }/rates/sync`, method: 'POST' } );

/**
 * Fetch rate preview (raw + effective rates).
 *
 * @return {Promise<Object>} Preview data.
 */
export const getRatesPreview = () =>
	apiFetch( { path: `${ BASE }/rates/preview` } );
