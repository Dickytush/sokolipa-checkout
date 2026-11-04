/* Sokolipa Checkout: block checkout payment method (no build step; plain wp.element). */
(function () {
	'use strict';

	if (!window.wc || !wc.wcBlocksRegistry || !wc.wcSettings || !window.wp || !wp.element) {
		return;
	}

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = wc.wcSettings.getSetting;
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var decode = wp.htmlEntities.decodeEntities;

	var settings = getSetting('sokolipa_data', {});
	var networks = settings.networks || [];
	var i18n = settings.i18n || {};

	function Content(props) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var onPaymentSetup = eventRegistration.onPaymentSetup || eventRegistration.onPaymentProcessing;

		var first = networks.length ? networks[0].id : '';
		var networkState = useState(first);
		var network = networkState[0];
		var setNetwork = networkState[1];
		var phoneState = useState('');
		var phone = phoneState[0];
		var setPhone = phoneState[1];

		useEffect(function () {
			var unsubscribe = onPaymentSetup(function () {
				if (!phone.replace(/\D/g, '').length) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: i18n.invalid || 'Enter the mobile money number to charge.'
					};
				}
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							sokolipa_network: network,
							sokolipa_phone: phone
						}
					}
				};
			});
			return unsubscribe;
		}, [onPaymentSetup, emitResponse, network, phone]);

		var picked = networks.filter(function (n) { return n.id === network; })[0] || networks[0] || {};

		return el(
			'div',
			{ className: 'sokolipa-fields sokolipa-fields--blocks' },
			settings.description ? el('p', { className: 'sokolipa-description' }, decode(settings.description)) : null,
			el(
				'div',
				{ className: 'sokolipa-network-row' },
				el('label', null, i18n.network || 'Network'),
				el(
					'div',
					{ className: 'sokolipa-networks' },
					networks.map(function (n) {
						return el(
							'label',
							{ key: n.id, className: 'sokolipa-network sokolipa-network--' + n.id },
							el('input', {
								type: 'radio',
								name: 'sokolipa_network',
								value: n.id,
								checked: network === n.id,
								onChange: function () { setNetwork(n.id); }
							}),
							' ',
							el('span', null, n.label)
						);
					})
				)
			),
			el(
				'div',
				{ className: 'sokolipa-phone-row' },
				el('label', { htmlFor: 'sokolipa_phone_block' }, i18n.phone || 'Mobile money number'),
				el('input', {
					id: 'sokolipa_phone_block',
					type: 'tel',
					inputMode: 'tel',
					autoComplete: 'tel',
					className: 'wc-block-components-text-input sokolipa-phone-input',
					placeholder: picked.example || '',
					value: phone,
					onChange: function (e) { setPhone(e.target.value); }
				})
			),
			el('p', { className: 'sokolipa-note' }, i18n.note || '')
		);
	}

	var label = el(
		'span',
		{ className: 'sokolipa-label' },
		settings.icon ? el('img', { src: settings.icon, alt: '', className: 'sokolipa-label__icon' }) : null,
		decode(settings.title || 'Mobile money')
	);

	registerPaymentMethod({
		name: 'sokolipa',
		label: label,
		ariaLabel: decode(settings.title || 'Mobile money'),
		content: el(Content, null),
		edit: el(Content, null),
		canMakePayment: function () { return networks.length > 0; },
		supports: { features: settings.supports || ['products'] }
	});
})();
