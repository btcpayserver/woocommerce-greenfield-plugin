jQuery(document).ready(function($) {
	var processingSelect = $('select[name="btcpay_gf_order_states[Processing]"]');
	var protectOrdersCheckbox = $('#btcpay_gf_protect_order_status');
	var defaultValue = 'wc-on-hold';
	var warningId = 'btcpay-processing-state-warning';

	function updateWarning() {
		$('#' + warningId).remove();

		if (protectOrdersCheckbox.is(':checked') && processingSelect.val() !== defaultValue) {
			var warning = '<div id="' + warningId + '" class="btcpay-processing-warning" style="color: #d63638; margin-top: 5px;">' +
				BTCPayOrderStatesWarning.warningText +
				'</div>';
			processingSelect.after(warning);
		}
	}

	processingSelect.on('change', updateWarning);
	protectOrdersCheckbox.on('change', updateWarning);

	// Check on page load.
	updateWarning();
});
