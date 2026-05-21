/**
 * Coinsnap Core - Admin JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // --- Save toast cleanup ---
        var toast = document.getElementById('csc-save-toast');
        if (toast) {
            setTimeout(function() {
                toast.remove();
                if (window.history.replaceState) {
                    var url = new URL(window.location);
                    url.searchParams.delete('settings-updated');
                    window.history.replaceState({}, '', url);
                }
            }, 3000);
        }

        // --- Tab navigation (reusable across all plugin pages) ---
        var $tabs = $('.csc-tab');
        var $tabContents = $('.csc-tab-content');

        if ($tabs.length) {
            $tabs.on('click', function(e) {
                e.preventDefault();
                var target = $(this).data('tab');

                $tabs.removeClass('is-active');
                $(this).addClass('is-active');

                $tabContents.removeClass('is-active');
                $('#' + target).addClass('is-active');

                localStorage.setItem('csc_active_tab', target);
            });

            // Restore last active tab
            var savedTab = localStorage.getItem('csc_active_tab');
            var $savedTab = $tabs.filter('[data-tab="' + savedTab + '"]');
            if ($savedTab.length) {
                $savedTab.trigger('click');
            } else {
                $tabs.first().trigger('click');
            }
        }

        // --- Re-register Webhook button ---
        $('#csc-reregister-webhook').on('click', function() {
            var $btn = $(this);
            var $status = $('#csc-webhook-status');
            var webhookAction = (typeof CoinsnapCoreAdmin !== 'undefined' && CoinsnapCoreAdmin.webhook_action)
                ? CoinsnapCoreAdmin.webhook_action
                : '';

            if (!webhookAction) {
                $status.css('color', '#d63638').text('Webhook action not configured');
                return;
            }

            $btn.prop('disabled', true).text('Registering...');
            $status.css('color', '#757575').text('');

            $.post(CoinsnapCoreAdmin.ajax_url, {
                action: webhookAction,
                apiNonce: CoinsnapCoreAdmin.nonce
            }, function(response) {
                if (response.success) {
                    $status.css('color', '#00a32a').text('✓ ' + response.data.message + ' (ID: ' + response.data.id + ')');
                } else {
                    $status.css('color', '#d63638').text('✗ ' + (response.data || 'Failed'));
                }
                $btn.prop('disabled', false).text('Re-register Webhook');
            }).fail(function() {
                $status.css('color', '#d63638').text('✗ Request failed');
                $btn.prop('disabled', false).text('Re-register Webhook');
            });
        });

        // Read option key from localized data (set by consuming plugin).
        var optionKey = (typeof CoinsnapCoreAdmin !== 'undefined' && CoinsnapCoreAdmin.option_key)
            ? CoinsnapCoreAdmin.option_key
            : 'coinsnap_settings';

        // --- Provider toggle (radio buttons) ---
        var $providerRadios = $('input[name="' + optionKey + '[payment_provider]"]');
        var $providerPanels = $('.csc-provider-panel');

        function switchProviderPanel() {
            var selected = $providerRadios.filter(':checked').val();
            $providerPanels.removeClass('is-active');
            $('#csc-panel-' + selected).addClass('is-active');
        }

        if ($providerRadios.length) {
            switchProviderPanel();
            $providerRadios.on('change', switchProviderPanel);
        }

        // --- Connection check via AJAX ---
        var $badge = $('#csc-connection-badge');
        if ($badge.length && typeof CoinsnapCoreAdmin !== 'undefined' && CoinsnapCoreAdmin.ajax_url) {
            $.post(CoinsnapCoreAdmin.ajax_url, {
                action: CoinsnapCoreAdmin.connection_action || 'coinsnap_core_connection_handler',
                apiNonce: CoinsnapCoreAdmin.nonce || '',
                apiPost: CoinsnapCoreAdmin.post || 0
            }, function(response) {
                var data;
                try {
                    data = (typeof response === 'string') ? JSON.parse(response) : response;
                } catch(e) {
                    return;
                }
                $badge.removeClass('is-connected is-error');
                if (data.result === true) {
                    $badge.addClass('is-connected');
                    $badge.find('.csc-connection-text').text('Connected');
                } else {
                    $badge.addClass('is-error');
                    $badge.find('.csc-connection-text').text('Not connected');
                }
            });
        }

        // --- BTCPay Generate API Key button ---
        // Use class selector (.csc-btn-generate) since IDs are dynamic per-plugin.
        $('.csc-btn-generate').click(function(e) {
            e.preventDefault();
            var $wrapper = $(this).closest('.csc-generate-key-wrapper, .csc-field-row, .bif-generate-key-wrapper');
            var host = $wrapper.find('input[type="url"]').val() || $(this).siblings('input[type="url"]').val();
            if (!host || host.indexOf('http') === -1) {
                alert('Please enter a valid URL including https:// for your BTCPay Server.');
                return;
            }
            try { new URL(host); } catch(err) {
                alert('Please enter a valid URL including https:// for your BTCPay Server.');
                return;
            }
            if (typeof CoinsnapCoreAdmin === 'undefined' || !CoinsnapCoreAdmin.ajax_url) return;
            $.post(CoinsnapCoreAdmin.ajax_url, {
                action: CoinsnapCoreAdmin.btcpay_action || 'coinsnap_core_btcpay_handler',
                host: host,
                apiNonce: CoinsnapCoreAdmin.nonce || ''
            }, function(response) {
                if (response.data && response.data.url) {
                    window.location = response.data.url;
                }
            }).fail(function() {
                alert('Error processing your request. Please verify your BTCPay Server URL.');
            });
        });
    });
})(jQuery);
