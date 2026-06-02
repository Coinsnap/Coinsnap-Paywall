jQuery(document).ready(function ($) {
  const container = $('.cscp-wrap');
  const postId = container.data('post-id');
  const duration = container.data('duration');
  const cookie = getCookie('coinsnap_initiated_' + postId);
  let id = false;
  let pollTimer = null;
  const POLL_INTERVAL = 5000;  // 5 seconds
  const POLL_MAX     = 12;     // stop after 60 seconds
  let pollCount = 0;

  // Check if the initiated cookie has the post ID same as the current post
  if (cookie) {
    const dataString = decodeURIComponent(cookie);
    const data = JSON.parse(dataString);
    id = data.invoice_id;

    if (String(data.post_id) === String(postId)) {
      // Initial check, then poll until Settled or timeout.
      checkInvoiceStatus(id, postId, duration, false);
      pollTimer = setInterval(function () {
        pollCount++;
        if (pollCount >= POLL_MAX) {
          clearInterval(pollTimer);
          return;
        }
        checkInvoiceStatus(id, postId, duration, false);
      }, POLL_INTERVAL);
    }
  }

  $('.cscp-pay-btn').on('click', function (e) {
    e.preventDefault();
    const container = $(this).closest('.cscp-wrap');
    const price = container.data('price');
    const currency = container.data('currency');
    const postId = container.data('post-id');
    const currentPage = window.location.href;

    if (id) {
      checkInvoiceStatus(id, postId, duration, true);
    }

    $.ajax({
      url: coinsnap_paywall_ajax.ajax_url,
      method: 'POST',
      data: {
        action: 'coinsnap_create_invoice',
        amount: price,
        currency: currency,
        postId: postId,
        currentPage: currentPage,
      },
      success: function (response) {
        if (response.success) {
          const invoiceUrl = response.data.invoice_url;
          const invoiceId = response.data.invoice_url.split('/').pop();
          window.location.href = invoiceUrl;
        } else {
          alert(response.data.message || 'Error creating invoice');
        }
      },
      error: function (e) {
        console.error(e.message);
        alert('AJAX request failed');
      }
    });
  });

  function checkInvoiceStatus(invoiceId, postId, duration, redirect) {

    $.ajax({
      url: coinsnap_paywall_ajax.ajax_url,
      method: 'POST',
      data: {
        action: 'check_invoice_status',
        invoice_id: invoiceId
      },
      success: function (response) {
        if (response.success && response.data.status === 'Settled') {
          clearInterval(pollTimer);
          grantAccess(postId, duration);
        } else if (response.success && response.data.status === 'New' && redirect) {

          window.location.href = response.data.checkoutUrl;
        }
      },
      error: function (xhr, status, error) {
        console.error('AJAX Error:', status, error);  // Log the error to the console
        alert('Error checking invoice status');
      }
    });
  }

  function grantAccess(postId, duration) {
    $.ajax({
      url: coinsnap_paywall_ajax.ajax_url,
      method: 'POST',
      data: {
        action: 'coinsnap_paywall_grant_access',
        post_id: postId,
        duration: duration,
      },
      success: function (response) {
        if (response.success) {
          deleteCookie('coinsnap_initiated_' + postId);
          window.location.reload();
        } else {
          alert('Failed to grant access: ' + (response.data || ''));
        }
      },
      error: function (xhr) {
        alert('Failed to grant access');
      }
    });
  }

  function deleteCookie(name) {
    document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/;';
  }

  function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
  }
});
