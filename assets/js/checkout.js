/* Sokolipa Checkout: classic checkout helpers (network pick -> phone hint, light number formatting). */
(function ($) {
	'use strict';

	function hint() {
		var $picked = $('input[name="sokolipa_network"]:checked');
		var $hint = $('#sokolipa-phone-hint');
		var $phone = $('#sokolipa_phone');
		if (!$picked.length || !$hint.length) {
			return;
		}
		var label = $picked.closest('label').find('span').text();
		var example = $picked.data('example') || '';
		var text = (window.sokolipaData && sokolipaData.i18n && sokolipaData.i18n.phoneHint) || 'Enter the number registered on %1$s, for example %2$s';
		$hint.text(text.replace('%1$s', label).replace('%2$s', example));
		if ($phone.length) {
			$phone.attr('placeholder', example);
		}
	}

	function copyBillingPhone() {
		var $phone = $('#sokolipa_phone');
		var $billing = $('#billing_phone');
		if ($phone.length && $billing.length && !$phone.val()) {
			$phone.val($billing.val());
		}
	}

	$(document.body).on('change', 'input[name="sokolipa_network"]', hint);
	$(document.body).on('updated_checkout payment_method_selected', function () {
		hint();
		copyBillingPhone();
	});
	$(document.body).on('change', '#billing_phone', copyBillingPhone);

	$(function () {
		hint();
		copyBillingPhone();
	});
})(jQuery);
