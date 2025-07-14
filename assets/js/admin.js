jQuery(document).ready(function ($) {
    
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
        
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2){
                return parts.pop().split(';').shift();
            }
        }

        function setCookie(name, value, seconds) {
            const d = new Date();
            d.setTime(d.getTime() + (seconds * 1000));
            const expires = "expires=" + d.toUTCString();
            document.cookie = name + "=" + value + ";" + expires + ";path=/";
        }

        async function handleCheckConnection(isSubmit = false) {
            //event.preventDefault();
            var connection = false;
      
            if (providerSelector.val() === 'coinsnap') {
                const coinsnapStoreId = $('#coinsnap_store_id').val();
                const coinsnapApiKey = $('#coinsnap_api_key').val();
                connection = await checkConnection(coinsnapStoreId, coinsnapApiKey);
            }
            else {
                const btcpayStoreId = $('#btcpay_store_id').val();
                const btcpayApiKey = $('#btcpay_api_key').val();
                const btcpayUrl = $('#btcpay_url').val();
                connection = await checkConnection(btcpayStoreId, btcpayApiKey, btcpayUrl);
            }
    
            setCookie('coinsnap_connection_', JSON.stringify({ 'connection': connection }), 20)
            if (!isSubmit) {
                $('#submit').click();
            }
        }

        $('#submit').click(async function (event) {
            
            await handleCheckConnection();
            $('#submit').click();
        });

        checkConnectionCoisnanpButton.on('click', async (event) => { await handleCheckConnection(); })
        checkConnectionBtcPayButton.on('click', async (event) => { await handleCheckConnection(); });

        const connectionCookie = getCookie('coinsnap_connection_')
        if (connectionCookie) {
            const connectionState = JSON.parse(connectionCookie)?.connection;
            const checkConnection = $(`#check_connection_${providerSelector?.val()}`);
            connectionState
                ? checkConnection.css({ color: 'green' }).text('Connection successful')
                : checkConnection.css({ color: 'red' }).text('Connection failed');
        }
    }
    
    function checkConnection(storeId, apiKey, btcpayUrl) {
      const headers = btcpayUrl ? { 'Authorization': `token ${apiKey}` } : { 'x-api-key': apiKey, };
      const url = btcpayUrl? `${btcpayUrl}/api/v1/stores/${storeId}`  : `https://app.coinsnap.io/api/v1/stores/${storeId}`;

      return $.ajax({
        url: url,
        method: 'GET',
        contentType: 'application/json',
        headers: headers
      })
        .then(() => true)
        .catch(() => false);
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
                console.error(e);
                return false;
            }
            return true;
        }
        else {
            return false;
        }
    }
});


