function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content
    || document.querySelector('input[name="_token"], input[name="csrf_token"]')?.value
    || '';
}

function showError(message) {
  const box = document.querySelector('[data-billing-error]');
  if (!box) return;
  box.textContent = message;
  box.classList.remove('is-hidden');
  box.focus?.();
}

function termsAccepted() {
  const checkbox = document.getElementById('checkout-terms');
  if (!(checkbox instanceof HTMLInputElement) || checkbox.checked) return true;
  checkbox.reportValidity();
  checkbox.focus();
  return false;
}

async function completeCheckout(endpoint, payload) {
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify(payload),
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(result.message || 'The server could not confirm this checkout.');
  if (result.redirect_url) window.location.assign(result.redirect_url);
  return result;
}

async function mountPayPal(host) {
  const status = host.querySelector('[data-provider-status]');
  const container = host.querySelector('[data-paypal-buttons]');
  const planId = host.dataset.planId;
  if (!host.dataset.clientId || !planId) {
    status.textContent = 'This membership is missing its public PayPal client or plan mapping.';
    return;
  }
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'button button-gold button-wide';
  button.textContent = 'Continue securely with PayPal';
  button.addEventListener('click', async () => {
    if (!termsAccepted()) return;
    button.disabled = true;
    status.textContent = 'Creating a provider-hosted PayPal approval…';
    try {
      await completeCheckout(host.dataset.completeEndpoint, {
        provider: 'paypal', product_id: host.dataset.productId,
        period: new URLSearchParams(location.search).get('period') || 'monthly', terms_accepted: true,
      });
    } catch (error) {
      showError(error instanceof Error ? error.message : 'PayPal checkout could not start.');
      button.disabled = false;
      status.textContent = 'No checkout was completed.';
    }
  });
  container.replaceChildren(button);
  status.textContent = 'PayPal is ready. You will review and approve on PayPal’s hosted page.';
}

async function mountSquare(host) {
  const status = host.querySelector('[data-provider-status]');
  const submit = host.querySelector('[data-square-submit]');
  if (!window.Square?.payments) {
    status.textContent = 'Square card checkout is unavailable in this browser or has not been enabled by the Adult Owner.';
    return;
  }
  const applicationId = host.dataset.applicationId;
  const locationId = host.dataset.locationId;
  if (!applicationId || !locationId) {
    status.textContent = 'Square public configuration is incomplete.';
    return;
  }
  try {
    const payments = window.Square.payments(applicationId, locationId);
    const card = await payments.card();
    await card.attach(host.querySelector('[data-square-card]'));
    submit.disabled = false;
    status.textContent = 'Secure card entry is ready. Payment details are tokenized by Square.';

    // Wallet buttons remain deliberately unmounted for this release. Google
    // Pay and Cash App Pay require distinct, amount-bearing PaymentRequest and
    // tokenization/event flows; card checkout is the only verified Square path.
    host.querySelector('[data-square-wallets]')?.replaceChildren();

    submit.addEventListener('click', async () => {
      if (!termsAccepted()) return;
      const subscription = host.dataset.kind === 'subscription';
      const recurringConsent = host.querySelector('[data-square-recurring-consent]');
      if (subscription && (!(recurringConsent instanceof HTMLInputElement) || !recurringConsent.checked)) {
        recurringConsent?.focus();
        showError('Authorize secure card storage and recurring Square charges before starting the membership.');
        return;
      }
      submit.disabled = true;
      status.textContent = 'Securely preparing payment…';
      try {
        const verificationDetails = subscription
          ? {
              intent: 'STORE',
              customerInitiated: true,
              sellerKeyedIn: false,
              billingContact: { email: host.dataset.customerEmail || '' },
            }
          : {
              intent: 'CHARGE',
              amount: (Number(host.dataset.amountCents || 0) / 100).toFixed(2),
              currencyCode: host.dataset.currency || 'USD',
              customerInitiated: true,
              sellerKeyedIn: false,
              billingContact: { email: host.dataset.customerEmail || '' },
            };
        const tokenResult = await card.tokenize(verificationDetails);
        if (tokenResult.status !== 'OK') throw new Error('Payment details were not accepted.');
        await completeCheckout(host.dataset.completeEndpoint, {
          provider: 'square',
          product_id: host.dataset.productId,
          period: host.dataset.period || 'monthly',
          source_token: tokenResult.token,
          terms_accepted: true,
        });
      } catch (error) {
        showError(error instanceof Error ? error.message : 'Square checkout failed. No entitlement was granted.');
        submit.disabled = false;
        status.textContent = 'Checkout was not completed.';
      }
    });
  } catch {
    status.textContent = 'Square could not be initialized. No charge occurred.';
  }
}

document.querySelectorAll('[data-paypal-checkout]').forEach(mountPayPal);
document.querySelectorAll('[data-square-checkout]').forEach(mountSquare);
