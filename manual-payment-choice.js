(() => {
  const formatNaira = (amount) => `₦${Number(amount).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

  window.NacosManualPaymentChoice = function ({ type, amount, payload, photo = null, errorTarget = null, alreadyPaid = false }) {
    return new Promise((resolve) => {
      const receiptLimitMb = type === "pageant" ? 10 : 5;
      const receiptPrompt = type === "pageant" ? "Upload Payment Receipt / Proof of Payment" : "Transfer receipt";
      const dialog = document.createElement('dialog');
      dialog.className = 'payment-choice-dialog';
      dialog.setAttribute('aria-labelledby', 'shared-payment-choice-title');
      dialog.innerHTML = `<div class="payment-choice-shell">
        <button type="button" class="payment-choice-close" data-close aria-label="Close payment options">×</button>
        <section data-step="choice"><p class="payment-eyebrow">${type === 'sponsorship' ? 'SPONSORSHIP' : 'MR & MISS NACOS'} PAYMENT</p><h2 id="shared-payment-choice-title">Choose how to pay</h2><p class="payment-intro">Select a payment method for <strong data-amount></strong>.</p><div class="payment-choice-actions"><button type="button" class="payment-option" data-automatic><strong>Pay automatically</strong><span>Continue to secure Paystack checkout.</span></button><button type="button" class="payment-option" data-manual><strong>Pay manually</strong><span>Transfer to the NACOS bank account and upload your receipt.</span></button></div><p class="manual-payment-availability" data-error role="alert"></p></section>
        <section data-step="bank" hidden><p class="payment-eyebrow">BANK TRANSFER</p><h2>Transfer the exact amount</h2><p class="payment-intro">Your registration stays pending until the NACOS team verifies the transfer.</p><p class="manual-payment-reminder">Upload your proof of payment before clicking “I have made the payment”.</p><div class="manual-payment-amount"><span>Amount to pay</span><strong data-amount></strong></div><div class="manual-bank-details"><div><span>Bank</span><strong>Signature Bank</strong></div><div><span>Account name</span><strong>NACOS SOUTH EAST</strong></div><div class="manual-account-number"><span>Account number</span><div class="manual-account-value"><strong data-account>2000557961</strong><span class="copy-account-feedback" data-copy-status aria-live="polite"></span></div><button type="button" class="copy-account" data-copy aria-label="Copy account number" title="Copy account number"><svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg></button></div></div><p class="manual-bank-instructions">Use your name as the transfer narration.</p><label class="manual-receipt-label">${receiptPrompt}</label><label class="receipt-dropzone" data-dropzone><input class="receipt-file-input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf"><span class="receipt-upload-icon" aria-hidden="true">⇧</span><span class="receipt-dropzone-copy"><strong>Choose a receipt to upload</strong><span>Drag and drop here, or <b>browse files</b></span></span><span class="receipt-file-types">JPG, PNG, WEBP or PDF · up to ${receiptLimitMb} MB</span></label><p class="manual-file-name" data-filename aria-live="polite">No file selected</p><p class="manual-payment-error" data-bank-error role="alert"></p><div class="payment-dialog-actions"><button type="button" class="payment-secondary" data-back>Back</button><button type="button" class="payment-primary" data-made>I have made the payment</button></div></section>
        <section data-step="receipt" hidden><p class="payment-eyebrow">PAYMENT RECEIPT</p><h2>Submit your transfer receipt</h2><p class="payment-intro">Your selected proof will be sent securely for NACOS admin review.</p><p class="manual-file-name has-file" data-confirm-file></p><p class="manual-payment-error" data-error role="alert"></p><div class="payment-dialog-actions"><button type="button" class="payment-secondary" data-back>Back</button><button type="button" class="payment-primary" data-submit>Submit receipt</button></div></section>
      </div>`;
      document.body.append(dialog);
      const steps = [...dialog.querySelectorAll('[data-step]')];
      const showStep = (name) => steps.forEach((step) => { step.hidden = step.dataset.step !== name; });
      const finish = (result) => {
        if (dialog.open) dialog.close();
        dialog.remove();
        resolve(result);
      };
      const receipt = dialog.querySelector('input[type=file]');
      const error = dialog.querySelector('[data-step="receipt"] [data-error]');
      const bankError = dialog.querySelector('[data-bank-error]');
      dialog.querySelectorAll('[data-amount]').forEach((node) => { node.textContent = formatNaira(amount); });
      dialog.querySelector('[data-close]').addEventListener('click', () => finish('cancelled'));
      dialog.addEventListener('cancel', (event) => { event.preventDefault(); finish('cancelled'); });
      dialog.addEventListener('click', (event) => { if (event.target === dialog) finish('cancelled'); });
      dialog.querySelector('[data-automatic]').addEventListener('click', () => finish('automatic'));
      dialog.querySelector('[data-manual]').addEventListener('click', () => showStep('bank'));
      dialog.querySelector('[data-copy]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const feedback = dialog.querySelector('[data-copy-status]');
        const announce = (message, failed = false) => {
          feedback.textContent = message;
          feedback.classList.toggle('is-error', failed);
          button.title = failed ? 'Copy failed' : 'Copied';
          if (!failed) window.setTimeout(() => { if (feedback.textContent === message) feedback.textContent = ''; }, 2200);
        };
        try {
          await navigator.clipboard.writeText('2000557961');
          announce('Copied');
        } catch {
          const field = document.createElement('textarea');
          field.value = '2000557961';
          field.style.position = 'fixed';
          field.style.opacity = '0';
          document.body.append(field);
          field.select();
          const copied = document.execCommand('copy');
          field.remove();
          announce(copied ? 'Copied' : 'Copy failed', !copied);
        }
      });
      dialog.querySelector('[data-made]').addEventListener('click', () => {
        const proof = receipt.files?.[0];
        if (!proof) { bankError.textContent = 'Choose your proof of payment before continuing.'; return; }
        if (proof.size > receiptLimitMb * 1024 * 1024) { bankError.textContent = 'The receipt must be under ' + receiptLimitMb + ' MB.'; return; }
        dialog.querySelector('[data-confirm-file]').textContent = `${receiptPrompt}: ${proof.name} · ${(proof.size / 1048576).toFixed(2)} MB`;
        showStep('receipt');
      });
      dialog.querySelectorAll('[data-back]').forEach((button) => button.addEventListener('click', () => showStep(button.closest('[data-step]').dataset.step === 'receipt' ? 'bank' : 'choice')));
      const dropzone = dialog.querySelector('[data-dropzone]');
      const fileName = dialog.querySelector('[data-filename]');
      receipt.addEventListener('change', () => {
        const file = receipt.files?.[0];
        fileName.textContent = file ? `${file.name} · ${(file.size / 1048576).toFixed(2)} MB` : 'No file selected';
        fileName.classList.toggle('has-file', Boolean(file));
        bankError.textContent = '';
        error.textContent = '';
      });
      ['dragenter', 'dragover'].forEach((name) => dropzone.addEventListener(name, (event) => { event.preventDefault(); dropzone.classList.add('is-dragging'); }));
      ['dragleave', 'drop'].forEach((name) => dropzone.addEventListener(name, (event) => { event.preventDefault(); dropzone.classList.remove('is-dragging'); }));
      dropzone.addEventListener('drop', (event) => {
        const files = event.dataTransfer?.files;
        if (!files?.length) return;
        try { receipt.files = files; receipt.dispatchEvent(new Event('change', { bubbles: true })); }
        catch { bankError.textContent = 'Please browse to select your receipt.'; }
      });
      dialog.querySelector('[data-submit]').addEventListener('click', async (event) => {
        const proof = receipt.files?.[0];
        if (!proof) { error.textContent = 'Choose your transfer receipt first.'; return; }
        if (proof.size > receiptLimitMb * 1024 * 1024) { error.textContent = 'The receipt must be under ' + receiptLimitMb + ' MB.'; return; }
        const button = event.currentTarget;
        button.disabled = true;
        button.textContent = 'Submitting receipt…';
        error.textContent = '';
        try {
          const body = new FormData();
          body.append('registration', JSON.stringify(payload));
          body.append('proof', proof);
          if (photo) body.append('photo', photo, photo.name);
          const response = await fetch('api/submit-manual-payment.php', { method: 'POST', body });
          const text = await response.text();
          let result;
          try { result = JSON.parse(text); } catch { throw new Error('The payment service returned an unexpected response. Open the site through the PHP web server and try again.'); }
          if (!response.ok || !result.ok) throw new Error(result.error || 'Receipt submission failed.');
          window.location.href = `manual-payment-pending?form=${encodeURIComponent(type)}&reference=${encodeURIComponent(result.reference)}&email=${encodeURIComponent(result.email_status || 'failed')}`;
        } catch (exception) {
          error.textContent = exception.message || 'We could not submit the receipt. Please try again.';
          button.disabled = false;
          button.textContent = 'Submit receipt';
        }
      });
      if (errorTarget) errorTarget.textContent = '';
      // Respect the admin payment switches. If only one method is enabled, use it as the default.
      fetch('api/payment-readiness.php', { cache: 'no-store' })
        .then((response) => response.json().then((methods) => {
          if (!response.ok) throw new Error('Payment options are temporarily unavailable. Please try again.');
          return methods;
        }))
        .then((methods) => {
          const automaticButton = dialog.querySelector('[data-automatic]');
          const manualButton = dialog.querySelector('[data-manual]');
          automaticButton.hidden = !methods.automatic_enabled;
          manualButton.hidden = !methods.manual_enabled;
          automaticButton.classList.toggle('is-default', methods.default_method === 'automatic');
          manualButton.classList.toggle('is-default', methods.default_method === 'manual');
          if (methods.automatic_enabled && !methods.manual_enabled) { finish('automatic'); return; }
          if (methods.manual_enabled && !methods.automatic_enabled) { showStep('bank'); dialog.showModal(); return; }
          if (!methods.automatic_enabled && !methods.manual_enabled) throw new Error('Payments are temporarily unavailable. Please contact the NACOS team.');
          showStep('choice');
          dialog.showModal();
        })
        .catch((exception) => {
          const message = dialog.querySelector('[data-error]');
          message.textContent = exception.message || 'Could not load payment options. Please try again.';
          showStep('choice');
          dialog.showModal();
        });
    });
  };
})();
