jQuery(document).ready(function ($){
    
    if($('#provider').length){
        const providerSelector = $('#provider');
        const coinsnapWrapper = $('#coinsnap-settings-wrapper');
        const btcpayWrapper = $('#btcpay-settings-wrapper');
        const checkConnectionCoisnanpButton = $('#check_connection_coinsnap_button');
        const checkConnectionBtcPayButton = $('#check_connection_btcpay_button');

        // Function to toggle visibility based on selected provider
        function toggleProviderSettings() {
            const selectedProvider = providerSelector.val();
            if(selectedProvider==='btcpay'){
                coinsnapWrapper.hide();
                btcpayWrapper.show();
            }
            else {
                coinsnapWrapper.show();
                btcpayWrapper.hide();
            }
        }

        // Initial toggle on page load
        toggleProviderSettings();

        // Listen for changes to the provider dropdown
        providerSelector.on('change', toggleProviderSettings);
        
        // The connection is checked on the server (admin AJAX); API keys never go to the browser.
        checkConnectionCoisnanpButton.on('click', function (event) { event.preventDefault(); serverConnectionCheck(); });
        checkConnectionBtcPayButton.on('click', function (event) { event.preventDefault(); serverConnectionCheck(); });
    }

    $('#coinsnap_paywall_btcpay_wizard_button').click(function(e) {
        e.preventDefault();
        const host = $('#btcpay_url').val();
	if (isPaywallValidUrl(host)) {
            let data = {
                'action': 'coinsnap_paywall_btcpay_apiurl_handler',
                'host': host,
                'apiNonce': coinsnap_paywall_ajax.nonce
            };
            
            $.post(coinsnap_paywall_ajax.ajax_url, data, function(response) {
                if (response.data.url) {
                    window.location = response.data.url;
		}
            }).fail( function() {
		alert('Error processing your request. Please make sure to enter a valid BTCPay Server instance URL.')
            });
	}
        else {
            alert('Please enter a valid url including https:// in the BTCPay Server URL input field.')
        }
    });

    function isPaywallValidUrl(serverUrl) {
        if(serverUrl.indexOf('http') > -1){
            try {
                const url = new URL(serverUrl);
                if (url.protocol !== 'https:' && url.protocol !== 'http:') {
                    return false;
                }
            }
            catch (e) {
                return false;
            }
            return true;
        }
        else {
            return false;
        }
    }
    
    if($('#coinsnap_paywall_currency').length){
        
        setStep();
        $('#coinsnap_paywall_currency').change(
            function(){
                setStep();
            }    
        );
        
    }
    
    function serverConnectionCheck(){
        let data = {
            action: 'coinsnap_paywall_connection_handler',
            apiNonce: coinsnap_paywall_ajax.nonce,
            apiPost: coinsnap_paywall_ajax.post
        };

        jQuery.post( coinsnap_paywall_ajax.ajax_url, data, function( response ){
            let connectionCheckResponse = (typeof response === 'string') ? JSON.parse(response) : response;
            let resultClass = (connectionCheckResponse.result === true)? 'success' : 'error';
            $('.coinsnapConnectionStatus').empty().append($('<span>').addClass(resultClass).text(connectionCheckResponse.message));
            const provider = $('#provider').val() === 'btcpay' ? 'btcpay' : 'coinsnap';
            $('#check_connection_' + provider).css({ color: resultClass === 'success' ? 'green' : 'red' }).text(connectionCheckResponse.message);
        });
    }

    if($('.coinsnapConnectionStatus').length){
        serverConnectionCheck();
    }
    
    function setStep(){
        let step = 0.01;
        let currency = $('#coinsnap_paywall_currency').val();
        if(currency === 'RUB' || currency === 'JPY' || currency === 'SATS'){
            step = 1;
        }
        $('#coinsnap_paywall_price').attr('step', step);
}
    
});
  

    


