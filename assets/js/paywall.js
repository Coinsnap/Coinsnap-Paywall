jQuery(document).ready(function ($) {
  const container = $('.paywall');
  const postId = container.data('post-id');
  const duration = container.data('duration');
  const cookie = getCookie('coinsnap_initiated_' + postId) ;
  let id = false

  // Check if the initiated cookie has the post ID same as the current post
  if (cookie) {
    const dataString = decodeURIComponent(cookie)
    const data = JSON.parse(dataString);
    id = data.invoice_id;

    if (data.post_id = postId) {
      checkInvoiceStatus(id, postId, duration, false)
    }
  }

  $('.paywall-payment-button').on('click', function (e) {
    e.preventDefault();
    const container = $(this).closest('.paywall');
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
          grantAccess(postId, duration);
        } else if (response.success && response.data.status === 'New' && redirect) {

          window.location.href = response.data.checkoutLink;
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
      success: function () {
        deleteCookie('coinsnap_initiated_' + postId);
        window.location.reload();
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
