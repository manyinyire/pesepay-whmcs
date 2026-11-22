(() => {
  'use strict';
  document.querySelectorAll('[data-pesepay-direct]').forEach(root => {
    if (root.dataset.initialized) return;
    root.dataset.initialized = 'true';
    const el = name => root.querySelector('.ppd-' + name);
    const state = {attempt: null, methods: [], timer: null, busy: false, polls: 0, requestKey: null, test: false, sawUnpaid: false, due: ''};
    const message = text => { el('status').textContent = text; };
    const loading = on => { el('loader').hidden = !on; };
    const stop = () => { clearTimeout(state.timer); state.timer = null; loading(false); };
    const key = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    async function request(action, values = {}) {
      const data = new URLSearchParams({action, invoice: root.dataset.invoice, csrf: root.dataset.csrf, ...values});
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 40000);
      try {
        const response = await fetch(root.dataset.endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: data, signal: controller.signal});
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error || 'Payment status is temporarily unavailable.');
        return result;
      } finally { clearTimeout(timeout); }
    }
    // Status is polled automatically; the spinner shows for as long as polling continues.
    function schedule() {
      stop();
      if (state.polls >= 50 || !state.attempt || state.attempt.terminal || state.attempt.paid) return;
      loading(true);
      state.timer = setTimeout(check, Math.min(5000 + state.polls * 500, 20000));
    }
    function render(a) {
      state.attempt = a;
      if (!a.paid) state.sawUnpaid = true;
      el('options').hidden = true; el('alt').hidden = true;
      el('wait').hidden = false;
      el('reference').textContent = a.reference ? 'Reference: ' + a.reference : 'Payment request saved. Checking its outcome…';
      el('otp').hidden = !a.needsOtp;
      el('retry').hidden = !a.retryable;
      el('qr').hidden = true;
      el('code').textContent = '';
      const metadata = a.metadata || {};
      if (!a.paid && !a.terminal) {
        if (metadata.qrCode) el('code').textContent = 'Payment code: ' + metadata.qrCode;
        if (metadata.qrCodeUrl) {
          try {
            const url = new URL(metadata.qrCodeUrl);
            // Images come only from server-verified metadata. Never interpret it as HTML or fetch server-side.
            if (url.protocol === 'https:' && !url.username && !url.password) {
              el('qr').src = url.href; el('qr').hidden = false;
            }
          } catch (_) { /* Unsupported image URL: leave the payment code visible. */ }
        }
      }
      if (a.paid) {
        message(a.test ? 'Test payment confirmed (no real money). Your invoice has been marked paid.' : 'Payment received. Your invoice has been updated.');
        stop();
        // Reload once so WHMCS shows the invoice as Paid. Only after a payment completed on this page, so a paid invoice cannot loop.
        if (state.sawUnpaid) { state.sawUnpaid = false; message(el('status').textContent + ' Refreshing your invoice…'); setTimeout(() => window.location.reload(), 2000); }
      } else if (a.status === 'UNKNOWN' && !a.reference && state.polls < 24) {
        // PesePay's callback can still supply the reference, so keep checking for about two minutes.
        message('Confirming your payment with PesePay. Approve the prompt on your phone if you have not already.'); schedule();
      } else if (a.status === 'UNKNOWN' && !a.reference) {
        message('We could not confirm this payment request with PesePay. Please do not pay again yet — contact support with your invoice number so we can check it.'); stop();
      } else if (a.status === 'REJECTED') {
        message('PesePay could not process this payment request. Nothing was charged. You can try again.'); stop();
      } else if (a.status === 'CLEARED') {
        message('Your previous payment request was checked and nothing was charged. You can start another payment.'); stop();
      } else if (a.status === 'UNKNOWN' || a.status === 'STARTING') {
        message('We’re still confirming this payment with PesePay. Please don’t pay again while we check.'); schedule();
      } else if (a.terminal) {
        message(a.retryable ? 'Payment was not completed (' + a.status.replaceAll('_', ' ').toLowerCase() + '). You can start another payment.' : 'Payment needs review (' + a.status.replaceAll('_', ' ').toLowerCase() + '). Contact support before trying again.'); stop();
      } else if (a.needsOtp) {
        message('Enter the six-digit code sent by O’mari to confirm your payment.'); schedule();
      } else if (a.method === 'PZW212' || a.method === 'PZW210') {
        message(metadata.qrCode || metadata.qrCodeUrl ? 'Approve this payment using the code in your wallet app.' : 'Waiting for the wallet payment code. Contact support if it does not appear.'); schedule();
      } else {
        message(state.polls >= 50 ? 'Payment is still being checked in the background. You can return to this invoice later.' : 'Approve the payment on your phone. We’ll update your invoice after confirmation.'); schedule();
      }
    }
    const selectedCode = () => (root.querySelector('.ppd-wallet-list input:checked') || {}).value || state.methods[0]?.code || '';
    function selectMethod() {
      const option = state.methods.find(m => m.code === selectedCode());
      el('phone-label').hidden = !option?.phone;
      el('hint').textContent = option?.message || '';
      el('phone').placeholder = option?.phone === 'omari' ? '263771234567' : '0771234567';
    }
    function showOptions() {
      stop(); state.attempt = null; state.requestKey = key(); state.polls = 0;
      el('wait').hidden = true; el('options').hidden = state.methods.length === 0; el('alt').hidden = false;
      el('pay').disabled = false;
      message(state.methods.length ? (state.test ? 'TEST MODE — no real money will be taken.' : 'Select how you would like to pay.') : 'No PesePay payment methods are available for this invoice. Please contact support.');
      selectMethod();
    }
    async function check() {
      if (state.busy || !state.attempt) return;
      clearTimeout(state.timer); state.timer = null; state.busy = true;
      try { state.polls++; render((await request('status', {attempt: state.attempt.id})).attempt); }
      catch (error) { message(error.message + ' Your payment has not been retried.'); schedule(); }
      finally { state.busy = false; }
    }
    el('wallet-list').addEventListener('change', selectMethod);
    el('retry').addEventListener('click', showOptions);
    el('pay').addEventListener('click', async () => {
      if (state.busy) return;
      state.busy = true; el('pay').disabled = true;
      message('Sending your payment request…'); el('wait').hidden = false; loading(true);
      try {
        render((await request('start', {requestKey: state.requestKey, method: selectedCode(), phone: el('phone').value})).attempt);
      } catch (error) {
        // Reusing requestKey makes a retry of this POST idempotent, even if the first response was lost.
        loading(false); message(error.message + ' Check the invoice before starting another payment.');
        el('pay').textContent = 'Recover payment request'; el('pay').disabled = false;
      } finally { state.busy = false; }
    });
    el('submit-otp').addEventListener('click', async () => {
      if (state.busy || !state.attempt) return;
      const otp = el('otp-value').value.trim();
      if (!/^\d{6}$/.test(otp)) { message('Enter the six-digit SMS code.'); return; }
      state.busy = true; stop(); el('submit-otp').disabled = true;
      el('otp-value').value = '';
      try { render((await request('otp', {attempt: state.attempt.id, otp})).attempt); }
      catch (error) { message(error.message + ' Check payment status before continuing.'); schedule(); }
      finally { state.busy = false; /* Never automatically resend an OTP charge. */ }
    });
    request('load').then(data => {
      state.methods = data.methods; state.test = !!data.test;
      // Wallets are tappable tiles; with a single wallet the choice is just stated.
      state.methods.forEach((method, i) => {
        const tile = document.createElement('label'); tile.className = 'ppd-wallet';
        const input = document.createElement('input'); input.type = 'radio'; input.name = 'ppd-wallet-' + root.dataset.invoice; input.value = method.code; input.checked = i === 0;
        const name = document.createElement('span'); name.textContent = method.name;
        tile.append(input, name); el('wallet-list').appendChild(tile);
      });
      el('wallets').hidden = state.methods.length < 2;
      el('single').hidden = state.methods.length !== 1;
      if (state.methods.length === 1) el('single').textContent = 'Paying with ' + state.methods[0].name;
      if (data.amount && data.currency) {
        state.due = data.currency + ' ' + data.amount;
        el('amount-value').textContent = state.due; el('amount').hidden = false; el('pay').textContent = 'Pay ' + state.due;
      }
      state.requestKey = key();
      // A finished attempt that is safe to retry should not greet the customer as an error on reload.
      if (data.attempt && !data.attempt.paid && data.attempt.retryable && state.methods.length) { showOptions(); message('Your previous payment attempt did not go through and nothing was charged. You can pay now.'); }
      else if (data.attempt && !data.attempt.paid) render(data.attempt);
      // Invoice already settled: WHMCS shows it as Paid, so the payment box is not needed.
      else if (data.attempt?.paid && !state.methods.length) root.hidden = true;
      else showOptions();
    }).catch(error => message(error.message));
    window.addEventListener('pagehide', stop, {once: true});
  });
})();
