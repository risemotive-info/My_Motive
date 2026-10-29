<?php
/**
 * NEW FILE: includes/digital_payment_ui.php
 *
 * The Digital Payment pop-up, shared by modules/sales/create.php (new sale) and modules/sales/pay_credit.php (credit payment).
 * Include it once, inside the page, after the page's own script. It adds  window.DigitalPay  with:
 *
 *   DigitalPay.enabled                 true when XentriPay is set up
 *   DigitalPay.pay({...})              the pop-up: amount + the customer's MTN MoMo number, then waits for the customer
 *   DigitalPay.ask({...})              the question "does the customer want to pay using Digital Payment?" (credit payments)
 *
 * The customer approves on their OWN phone, in MTN's own prompt. This pop-up never asks for, shows or sends a PIN.
 */
require_once __DIR__ . '/digital_payment_helpers.php';
$dpConfig = [
    'enabled'  => xentripay_configured(),
    'endpoint' => 'digital_payment.php',
    'csrf'     => dp_csrf_token(),
    'pollMs'   => 3000,
    'waitSecs' => 150,
];
?>
<style>
.dp-overlay { position: fixed; inset: 0; z-index: 2000; background: rgba(20,24,40,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
.dp-box { background: #fff; border-radius: 14px; width: 100%; max-width: 440px; box-shadow: 0 20px 60px rgba(0,0,0,.3); padding: 22px; font-size: 14px; color: #1E2333; }
.dp-box h3 { font-size: 18px; margin: 0 0 4px; }
.dp-sub { color: #6b7185; font-size: 13px; margin-bottom: 14px; }
.dp-label { display: block; font-size: 12px; font-weight: 600; color: #6b7185; margin: 12px 0 4px; }
.dp-input { width: 100%; border: 1px solid #cfd4e2; border-radius: 9px; padding: 10px 12px; font-size: 15px; }
.dp-input:focus { outline: 2px solid #4C6FFF55; border-color: #4C6FFF; }
.dp-note { background: #EEF1FE; color: #34408a; border-radius: 9px; padding: 9px 11px; font-size: 12.5px; margin-top: 14px; }
.dp-err { background: #FDECEC; color: #B3261E; border-radius: 9px; padding: 9px 11px; font-size: 13px; margin-top: 12px; }
.dp-ok { background: #E7F6EC; color: #1B6B3A; border-radius: 9px; padding: 12px; font-size: 14px; margin-top: 12px; }
.dp-warn { background: #FFF4E0; color: #8A5300; border-radius: 9px; padding: 11px; font-size: 13px; margin-top: 12px; }
.dp-row { display: flex; gap: 10px; margin-top: 18px; }
.dp-btn { flex: 1; border: 0; border-radius: 9px; padding: 11px 14px; font-weight: 600; font-size: 14px; cursor: pointer; background: #4C6FFF; color: #fff; }
.dp-btn:hover { filter: brightness(.95); }
.dp-btn.light { background: #EEF0F6; color: #333b55; }
.dp-btn:disabled { opacity: .55; cursor: not-allowed; }
.dp-spin { width: 38px; height: 38px; border: 4px solid #dfe4f6; border-top-color: #4C6FFF; border-radius: 50%; animation: dpspin 1s linear infinite; margin: 8px auto 12px; }
@keyframes dpspin { to { transform: rotate(360deg); } }
.dp-center { text-align: center; }
.dp-big { font-size: 22px; font-weight: 700; margin: 4px 0; }
.dp-list { list-style: none; padding: 0; margin: 10px 0 0; }
.dp-list li { border: 1px solid #e3e6f0; border-radius: 9px; padding: 10px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
</style>
<script>
window.DP_CONFIG = <?= json_encode($dpConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
(function () {
    var cfg = window.DP_CONFIG;
    var overlay = null, pollTimer = null, tickTimer = null;

    function h(tag, attrs, kids) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { e.textContent = attrs[k]; } else if (k === 'class') { e.className = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
        });
        (kids || []).forEach(function (c) { if (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return e;
    }
    function money(n) { return 'RWF ' + Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 }); }

    function call(method, params) {
        var url = cfg.endpoint, init = { method: method, credentials: 'same-origin', headers: {} };
        var qs = new URLSearchParams(params).toString();
        if (method === 'GET') { url += '?' + qs; }
        else { init.headers['Content-Type'] = 'application/x-www-form-urlencoded'; init.headers['X-DP-CSRF'] = cfg.csrf; init.body = qs; }
        return fetch(url, init).then(function (r) {
            return r.text().then(function (t) {
                try { return JSON.parse(t); } catch (e) { throw new Error('Your session may have expired. Please reload the page and sign in again.'); }
            });
        });
    }

    function stopTimers() { clearTimeout(pollTimer); clearInterval(tickTimer); pollTimer = tickTimer = null; }
    function close() { stopTimers(); if (overlay) { overlay.remove(); overlay = null; } }
    function show(box) {
        stopTimers();
        if (!overlay) {
            overlay = h('div', { 'class': 'dp-overlay', role: 'presentation' });
            document.body.appendChild(overlay);
        }
        box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true');
        overlay.innerHTML = '';
        overlay.appendChild(box);
        var first = box.querySelector('input, button'); if (first) { first.focus(); }
    }

    // ── "Does the customer want to pay using Digital Payment?" (credit payments) ──
    function ask(o) {
        var box = h('div', { 'class': 'dp-box dp-center' }, [
            h('h3', { text: 'Pay using Digital Payment?' }),
            h('div', { 'class': 'dp-big', text: money(o.amount) }),
            h('div', { 'class': 'dp-sub', text: 'Does the customer want to pay this amount using Digital Payment (MTN MoMo)?' }),
            h('div', { 'class': 'dp-row' }, [
                h('button', { 'class': 'dp-btn light', type: 'button', id: 'dpNo', text: 'No, record it manually' }),
                h('button', { 'class': 'dp-btn', type: 'button', id: 'dpYes', text: 'Yes, Digital Payment' })
            ])
        ]);
        show(box);
        box.querySelector('#dpNo').onclick = function () { close(); if (o.onNo) { o.onNo(); } };
        box.querySelector('#dpYes').onclick = function () { pay(o); };
    }

    // ── the pop-up: amount + phone ──
    function pay(o) {
        if (!cfg.enabled) { return fail(o, 'Digital Payment is not set up yet. Please ask the Admin to finish its setup.', false); }
        // a confirmed payment that was never used for a sale (the page was closed, the save failed...): offer it first
        if (o.purpose === 'sale' && o.resume && o.resume.length && !o.skipResume) { return resume(o); }
        var amountInput = h('input', { 'class': 'dp-input', type: 'number', id: 'dpAmount', min: '1', step: '1', inputmode: 'numeric', value: o.amount ? String(o.amount) : '' });   // the EXACT amount: never rounded, so cents are refused, not silently changed
        if (o.amountLocked) { amountInput.setAttribute('readonly', 'readonly'); }
        var phoneInput = h('input', { 'class': 'dp-input', type: 'tel', id: 'dpPhone', placeholder: '07xx xxx xxx', autocomplete: 'off', value: o.phone || '' });
        var err = h('div', { 'class': 'dp-err', style: 'display:none' });
        var go = h('button', { 'class': 'dp-btn', type: 'button', id: 'dpGo', text: 'Send payment request' });
        var cancel = h('button', { 'class': 'dp-btn light', type: 'button', id: 'dpCancel', text: 'Cancel' });
        var box = h('div', { 'class': 'dp-box' }, [
            h('h3', { text: 'Digital Payment (MTN MoMo)' }),
            h('div', { 'class': 'dp-sub', text: o.purpose === 'credit' ? 'Payment on a credit sale' : 'Payment for this sale' }),
            h('label', { 'class': 'dp-label', 'for': 'dpAmount', text: 'Amount to Pay (RWF)' }), amountInput,
            h('label', { 'class': 'dp-label', 'for': 'dpPhone', text: "Customer's MTN MoMo phone number" }), phoneInput,
            h('div', { 'class': 'dp-note', text: "The customer receives a prompt on their phone and approves it with their MoMo PIN there, in MTN's own screen. Never ask the customer for their PIN, and never write it down." }),
            err,
            h('div', { 'class': 'dp-row' }, [cancel, go])
        ]);
        show(box);
        cancel.onclick = function () { close(); if (o.onClose) { o.onClose(); } };
        function problem(msg) { err.textContent = msg; err.style.display = 'block'; }
        go.onclick = function () {
            var amount = parseFloat(amountInput.value), phone = phoneInput.value.trim();
            err.style.display = 'none';
            if (!amount || amount <= 0) { return problem('Enter the amount to pay.'); }
            if (Math.abs(amount - Math.round(amount)) > 0.004) { return problem('A Digital Payment must be a whole number of RWF (no cents). Pay the cents another way.'); }
            if (o.maxAmount && amount > o.maxAmount + 0.009) { return problem('The amount cannot be more than ' + money(o.maxAmount) + '.'); }
            if (!phone) { return problem("Enter the customer's MTN MoMo phone number."); }
            go.disabled = true; cancel.disabled = true; go.textContent = 'Sending…';
            call('POST', { action: 'start', purpose: o.purpose, amount: String(Math.round(amount)), phone: phone, sale_id: o.saleId || 0, customer_id: o.customerId || 0 })
                .then(function (r) {
                    if (!r.ok) { go.disabled = false; cancel.disabled = false; go.textContent = 'Send payment request'; return problem(r.message || 'The request could not be sent.'); }
                    wait(o, r.attempt);
                })
                .catch(function (e) { go.disabled = false; cancel.disabled = false; go.textContent = 'Send payment request'; problem(e.message || 'The request could not be sent.'); });
        };
    }

    // ── a confirmed payment that was not used yet ──
    function resume(o) {
        var list = h('ul', { 'class': 'dp-list' });
        o.resume.forEach(function (p) {
            var use = h('button', { 'class': 'dp-btn', type: 'button', text: 'Use this payment' });
            use.style.flex = '0 0 auto';
            use.onclick = function () { close(); if (o.onSuccess) { o.onSuccess(p); } };
            list.appendChild(h('li', {}, [h('div', {}, [h('strong', { text: money(p.amount) }), h('div', { 'class': 'dp-sub', style: 'margin:0', text: 'from ' + p.phone + ' · ' + p.reference })]), use]));
        });
        var fresh = h('button', { 'class': 'dp-btn light', type: 'button', text: 'Send a new payment request instead' });
        var box = h('div', { 'class': 'dp-box' }, [
            h('h3', { text: 'A payment was already received' }),
            h('div', { 'class': 'dp-sub', text: 'MTN MoMo confirmed these payments, but no sale was recorded with them yet. Use one for this sale, so the customer is not charged twice.' }),
            list, h('div', { 'class': 'dp-row' }, [fresh])
        ]);
        show(box);
        fresh.onclick = function () { o.skipResume = true; pay(o); };
    }

    // ── waiting for the customer ──
    function wait(o, attempt) {
        var started = Date.now(), id = attempt.id, polls = 0;
        var left = h('div', { 'class': 'dp-sub dp-center', text: '' });
        var msg = h('div', { 'class': 'dp-warn', style: 'display:none' });
        var check = h('button', { 'class': 'dp-btn light', type: 'button', text: 'Check now' });
        var stop = h('button', { 'class': 'dp-btn light', type: 'button', text: 'Stop waiting' });
        var box = h('div', { 'class': 'dp-box dp-center' }, [
            h('div', { 'class': 'dp-spin' }),
            h('h3', { text: 'Waiting for the customer' }),
            h('div', { 'class': 'dp-big', text: money(attempt.amount) }),
            h('div', { 'class': 'dp-sub', text: 'A request was sent to ' + attempt.phone + '. The customer must approve it on their phone with their MoMo PIN.' }),
            left, msg,
            h('div', { 'class': 'dp-row' }, [stop, check])
        ]);
        show(box);

        function tick() {
            var s = Math.floor((Date.now() - started) / 1000);
            left.textContent = s < cfg.waitSecs ? 'Waiting… ' + s + 's' : 'Still waiting… ' + s + 's';
            if (s >= cfg.waitSecs) { msg.style.display = 'block'; msg.textContent = 'The customer has not approved yet. You can keep waiting, or stop waiting and ask a Manager to follow it up in Digital Payments. If the customer approves later, the money is still recorded as received.'; }
        }
        tickTimer = setInterval(tick, 1000); tick();

        function poll() {
            polls++;
            call('GET', { action: 'status', id: id }).then(function (r) {
                if (!r.ok) { throw new Error(r.message || 'Could not check the payment.'); }
                var a = r.attempt;
                if (a.status === 'success') {
                    if (a.purpose === 'credit' && !a.applied && !a.needs_attention && polls < 8) { return again(1500); }
                    return done(o, a, r);
                }
                if (a.status === 'failed' || a.status === 'expired') { return fail(o, a.reason || 'The payment was not approved.', true); }
                if (a.status === 'cancelled') { return fail(o, 'You stopped waiting for this payment.', true); }
                again(cfg.pollMs);
            }).catch(function (e) {
                msg.style.display = 'block'; msg.textContent = (e && e.message ? e.message : 'Connection problem.') + ' Trying again…';
                again(cfg.pollMs * 2);
            });
        }
        function again(ms) { clearTimeout(pollTimer); pollTimer = setTimeout(poll, ms); }
        check.onclick = function () { clearTimeout(pollTimer); poll(); };
        stop.onclick = function () {
            if (!window.confirm('Stop waiting? If the customer still approves the request on their phone, the money will be received and a Manager will see it in Digital Payments.')) { return; }
            clearTimeout(pollTimer);
            call('POST', { action: 'cancel', id: id }).finally(function () { fail(o, 'You stopped waiting for this payment. Make sure the customer does not approve it before you send another request.', true); });
        };
        again(1500);
    }

    // ── the end of the story ──
    function done(o, a, r) {
        stopTimers();
        var warn = a.needs_attention;
        var box = h('div', { 'class': 'dp-box dp-center' }, [
            h('h3', { text: warn ? 'Payment received: needs attention' : 'Payment confirmed' }),
            h('div', { 'class': 'dp-big', text: money(a.amount) }),
            h('div', { 'class': warn ? 'dp-warn' : 'dp-ok', text: warn ? (a.note || 'The money was received but could not be recorded automatically. Please ask the Admin.') : (r.message || (o.purpose === 'sale' ? 'MTN MoMo confirmed the payment. Recording the sale…' : 'MTN MoMo confirmed the payment.')) })
        ]);
        show(box);
        if (warn) {
            var okb = h('button', { 'class': 'dp-btn', type: 'button', text: 'OK' });
            box.appendChild(h('div', { 'class': 'dp-row' }, [okb]));
            okb.onclick = function () { close(); if (r.redirect) { window.location.href = r.redirect; } else if (o.onClose) { o.onClose(); } };
            return;
        }
        setTimeout(function () {
            if (o.purpose === 'credit') { window.location.href = r.redirect || 'index.php'; }
            else { close(); if (o.onSuccess) { o.onSuccess(a); } }
        }, 1200);
    }

    function fail(o, reason, canRetry) {
        var retry = h('button', { 'class': 'dp-btn', type: 'button', text: 'Try again' });
        var closeBtn = h('button', { 'class': 'dp-btn light', type: 'button', text: 'Close' });
        var box = h('div', { 'class': 'dp-box dp-center' }, [
            h('h3', { text: 'Payment not completed' }),
            h('div', { 'class': 'dp-err', text: reason }),
            h('div', { 'class': 'dp-sub', style: 'margin-top:10px', text: 'Nothing was recorded as paid.' }),
            h('div', { 'class': 'dp-row' }, canRetry ? [closeBtn, retry] : [closeBtn])
        ]);
        show(box);
        closeBtn.onclick = function () { close(); if (o.onClose) { o.onClose(); } };
        retry.onclick = function () { o.skipResume = true; pay(o); };
    }

    window.DigitalPay = { enabled: !!cfg.enabled, pay: pay, ask: ask, close: close };
})();
</script>