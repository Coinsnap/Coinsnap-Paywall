jQuery(document).ready(function ($) {
  // The browser sends only the post ID. Price, currency and duration come from the server,
  // and access is granted on the server after the payment API confirms the invoice.
  const container = $('.paywall').first();
  if (!container.length) {
    return;
  }
  const postId = container.data('post-id');
  let checks = 0;

  function checkAccess() {
    $.post(coinsnap_paywall_ajax.ajax_url, { action: 'coinsnap_paywall_check_access', postId: postId })
      .done(function (response) {
        if (response && response.success && response.data.paid) {
          window.location.reload();
        } else if (response && response.success && response.data.pending && checks < 24) {
          checks++;
          setTimeout(checkAccess, 5000);
        }
      });
  }

  checkAccess();

  $('.paywall-payment-button').on('click', function (e) {
    e.preventDefault();
    const button = $(this).prop('disabled', true);

    $.post(coinsnap_paywall_ajax.ajax_url, { action: 'coinsnap_paywall_create_invoice', postId: postId })
      .done(function (response) {
        if (response && response.success && response.data.invoice_url) {
          window.location.href = response.data.invoice_url;
        } else {
          button.prop('disabled', false);
          alert((response && response.data && response.data.message) || 'Error creating invoice');
        }
      })
      .fail(function () {
        button.prop('disabled', false);
        alert('Error creating invoice');
      });
  });
});
