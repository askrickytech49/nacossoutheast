document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('convention-form');
  const dialog = document.getElementById('payment-choice-dialog');
  const submitButton = document.getElementById('btn-pay-submit');
  if (!form || !dialog || !submitButton) return;

  const steps = [...dialog.querySelectorAll('[data-payment-step]')];
  const amountChoice = document.getElementById('payment-choice-amount');
  const amountManual = document.getElementById('manual-payment-amount');
  const accountNumber = document.getElementById('manual-account-number');
  const availability = document.getElementById('manual-payment-availability');
  const receiptInput = document.getElementById('manual-payment-proof');
  const receiptError = document.getElementById('manual-payment-error');
  const bankReceiptError = document.getElementById('manual-payment-bank-error');
  const receiptConfirmation = document.getElementById('manual-payment-confirm-file');
  const originalButtonText = submitButton.textContent.trim();
  let paymentData = null;
  const bankDetails = {
    bank_name: 'Signature Bank',
    account_name: 'NACOS SOUTH EAST',
    account_number: '2000557961',
    instructions: 'Use your full name as the transfer narration.',
  };

  const setStep = (name) => steps.forEach((step) => { step.hidden = step.dataset.paymentStep !== name; });
  const restoreButton = () => {
    submitButton.disabled = false;
    submitButton.textContent = originalButtonText;
  };
  const closeDialog = () => {
    if (dialog.open) dialog.close();
    restoreButton();
  };
  const formatNaira = (amount) => `₦${Number(amount).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  async function readApiJson(response) {
    const contentType = response.headers.get('content-type') || '';
    if (!contentType.toLowerCase().includes('application/json')) {
      const body = await response.text();
      if (body.includes('<?php') || contentType.includes('text/html')) {
        throw new Error('The PHP backend is not running. Open this site through XAMPP Apache (localhost:8080), not the static Live Server on port 5500.');
      }
      throw new Error(`The server returned an unexpected response (HTTP ${response.status}).`);
    }
    return response.json();
  }

  function collectRegistration() {
    const isSingle = document.getElementById('type-single-radio').checked;
    const packageInput = document.querySelector('input[name="package"]:checked');
    if (!packageInput) throw new Error('Please select a registration package.');
    const packageName = packageInput.dataset.name || '';
    const unitPrice = Number(packageInput.value);
    const attendees = [];
    let institution = '';
    let state = '';

    if (isSingle) {
      const name = document.getElementById('single-fullname').value.trim();
      const email = document.getElementById('single-email').value.trim();
      const phone = document.getElementById('single-phone').value.trim();
      const institutionSelect = document.getElementById('single-institution');
      institution = institutionSelect.value === 'other' ? document.getElementById('single-other-inst').value.trim() : institutionSelect.value.trim();
      state = document.getElementById('single-state').value.trim();
      if (!name || !email || !phone || !institution || !state) throw new Error('Please fill in all required single registration fields.');
      if (!document.getElementById('single-email').checkValidity()) throw new Error('Enter a valid email address.');
      attendees.push({ name, email, phone });
    } else {
      const institutionSelect = document.getElementById('bulk-institution');
      institution = institutionSelect.value === 'other' ? document.getElementById('bulk-other-inst').value.trim() : institutionSelect.value.trim();
      const rows = [...document.querySelectorAll('#students-table-body tr')];
      if (!institution || rows.length < 1) throw new Error('Select an institution and add at least one student.');
      if (rows.length > 100) throw new Error('A single manual transfer can include at most 100 students.');
      rows.forEach((row) => {
        const nameInput = row.querySelector('.bulk-name');
        const emailInput = row.querySelector('.bulk-email');
        const phoneInput = row.querySelector('.bulk-phone');
        const name = nameInput.value.trim();
        const email = emailInput.value.trim();
        const phone = phoneInput.value.trim();
        if (!name || !email || !phone || !emailInput.checkValidity()) throw new Error('Complete each student’s name, valid email, and phone number.');
        attendees.push({ name, email, phone });
      });
    }

    const amount = unitPrice * attendees.length;
    if (!Number.isFinite(amount) || amount < 1) throw new Error('The payment amount could not be calculated.');
    return {
      payload: { type: 'convention', package_name: packageName, institution, state, attendees },
      firstAttendee: attendees[0],
      amount,
    };
  }

  function openPaymentChoice(event) {
    event?.preventDefault();
    event?.stopImmediatePropagation();
    try {
      paymentData = collectRegistration();
      amountChoice.textContent = formatNaira(paymentData.amount);
      amountManual.textContent = formatNaira(paymentData.amount);
      availability.textContent = '';
      receiptError.textContent = '';
      bankReceiptError.textContent = '';
      fetch('api/payment-readiness.php', { cache: 'no-store' }).then(readApiJson).then((methods) => {
        const automatic = document.getElementById('pay-automatically');
        const manual = document.getElementById('pay-manually');
        automatic.hidden = !methods.automatic_enabled;
        manual.hidden = !methods.manual_enabled;
        automatic.classList.toggle('is-default', methods.default_method === 'automatic');
        manual.classList.toggle('is-default', methods.default_method === 'manual');
        if (methods.automatic_enabled && !methods.manual_enabled) { automatic.click(); return; }
        if (methods.manual_enabled && !methods.automatic_enabled) { manual.click(); dialog.showModal(); return; }
        if (!methods.automatic_enabled && !methods.manual_enabled) throw new Error('Payments are temporarily unavailable. Please contact the NACOS team.');
        setStep('choice');
        dialog.showModal();
      }).catch((error) => { availability.textContent = error.message || 'Could not load payment options. Please try again.'; setStep('choice'); dialog.showModal(); });
    } catch (error) {
      alert(error.message || 'Check your registration details and try again.');
    }
  }

  // Open the choice dialog directly from the button. This prevents the legacy
  // form submit handler from opening Paystack before the payer chooses a method.
  submitButton.addEventListener('click', openPaymentChoice);

  // Also support pressing Enter in a form field.
  form.addEventListener('submit', (event) => {
    openPaymentChoice(event);
  }, true);

  document.getElementById('payment-choice-close').addEventListener('click', closeDialog);
  dialog.addEventListener('cancel', () => restoreButton());
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closeDialog();
  });

  document.getElementById('pay-automatically').addEventListener('click', async () => {
    if (!paymentData) return;
    dialog.close();
    submitButton.disabled = true;
    submitButton.textContent = 'Connecting to payment…';
    window.NacosPaymentOverlay?.show('Connecting to payment', 'Opening secure checkout. Please wait.');
    try {
      const readinessResponse = await fetch('api/payment-readiness.php', { cache: 'no-store' });
      const readiness = await readApiJson(readinessResponse);
      if (!readinessResponse.ok || !readiness.enabled || !readiness.public_key) throw new Error('Payment verification is not configured yet. Please contact the NACOS team.');
      if (!window.PaystackPop || typeof window.PaystackPop.setup !== 'function') {
        await new Promise((resolve, reject) => {
          const checkoutScript = document.createElement('script');
          const timeout = window.setTimeout(() => reject(new Error('Secure checkout took too long to load. Please try again.')), 12000);
          checkoutScript.src = 'https://js.paystack.co/v1/inline.js';
          checkoutScript.async = true;
          checkoutScript.onload = () => { window.clearTimeout(timeout); resolve(); };
          checkoutScript.onerror = () => { window.clearTimeout(timeout); reject(new Error('Secure checkout could not load. Check your connection and try again.')); };
          document.head.appendChild(checkoutScript);
        });
      }
      if (!window.PaystackPop || typeof window.PaystackPop.setup !== 'function') throw new Error('Secure checkout is unavailable. Refresh and try again.');
      const handler = window.PaystackPop.setup({
        key: readiness.public_key,
        email: paymentData.firstAttendee.email,
        amount: paymentData.amount * 100,
        currency: 'NGN',
        ref: 'NACOS-SE-' + Math.floor(Math.random() * 1000000000 + 1),
        metadata: { custom_fields: [
          { display_name: 'Full Name', variable_name: 'full_name', value: paymentData.firstAttendee.name },
          { display_name: 'Phone', variable_name: 'phone', value: paymentData.firstAttendee.phone },
          { display_name: 'Package', variable_name: 'package', value: paymentData.payload.package_name },
        ] },
        callback: (response) => {
          window.NacosPaymentOverlay?.show('Verifying payment', 'Payment received. We are verifying it and saving your registration.');
          (async () => {
            try {
              const savedResponse = await fetch('api/save-paid-registration.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...paymentData.payload, reference: response.reference }),
              });
              const saved = await readApiJson(savedResponse);
              if (!savedResponse.ok || !saved.ok) throw new Error(saved.error || 'Payment could not be verified.');
              window.location.href = 'thank-you?email=' + encodeURIComponent(saved.email_status || 'failed');
            } catch (error) {
              alert(`${error.message || 'We could not save your registration.'} Keep payment reference ${response.reference} and contact NACOS.`);
              restoreButton();
              window.NacosPaymentOverlay?.hide();
            }
          })();
        },
        onClose: () => { window.NacosPaymentOverlay?.hide(); restoreButton(); },
      });
      handler.openIframe();
      window.NacosPaymentOverlay?.hide();
    } catch (error) {
      window.NacosPaymentOverlay?.hide();
      restoreButton();
      alert(error.message || 'We could not open secure checkout. Please try again.');
    }
  });

  document.getElementById('pay-manually').addEventListener('click', () => {
    availability.textContent = '';
    document.getElementById('manual-bank-name').textContent = bankDetails.bank_name;
    document.getElementById('manual-account-name').textContent = bankDetails.account_name;
    accountNumber.textContent = bankDetails.account_number;
    document.getElementById('manual-bank-instructions').textContent = bankDetails.instructions;
    setStep('bank');
  });

  document.querySelectorAll('[data-copy-account]').forEach((button) => button.addEventListener('click', async () => {
    const value = accountNumber.textContent.trim();
    const feedback = document.getElementById('copy-account-feedback');
    const showFeedback = (message, isError = false) => {
      feedback.textContent = message;
      feedback.classList.toggle('is-error', isError);
      window.clearTimeout(feedback.hideTimer);
      feedback.hideTimer = window.setTimeout(() => {
        feedback.textContent = '';
        feedback.classList.remove('is-error');
      }, 1800);
    };
    try {
      await navigator.clipboard.writeText(value);
      button.setAttribute('aria-label', 'Account number copied');
      button.title = 'Copied';
      showFeedback('Copied');
      window.setTimeout(() => { button.setAttribute('aria-label', 'Copy account number'); button.title = 'Copy account number'; }, 1500);
    } catch {
      const helper = document.createElement('textarea');
      helper.value = value;
      helper.style.position = 'fixed'; helper.style.opacity = '0';
      document.body.appendChild(helper); helper.select();
      const copied = document.execCommand('copy');
      helper.remove();
      if (copied) {
        button.title = 'Copied';
        showFeedback('Copied');
      } else {
        showFeedback('Copy failed', true);
      }
    }
  }));

  document.getElementById('manual-payment-made').addEventListener('click', () => {
    const file = receiptInput.files?.[0];
    if (!file) { bankReceiptError.textContent = 'Choose your proof of payment before continuing.'; return; }
    if (file.size < 1 || file.size > 5 * 1024 * 1024) { bankReceiptError.textContent = 'The receipt must be under 5 MB.'; return; }
    receiptConfirmation.textContent = `Transfer receipt: ${file.name} · ${(file.size / (1024 * 1024)).toFixed(2)} MB`;
    receiptError.textContent = '';
    setStep('receipt');
  });
  document.querySelectorAll('[data-payment-back]').forEach((button) => button.addEventListener('click', () => {
    receiptError.textContent = '';
    setStep(button.closest('[data-payment-step]').dataset.paymentStep === 'receipt' ? 'bank' : 'choice');
  }));
  const receiptDropzone = document.getElementById('manual-receipt-dropzone');
  const receiptFileName = document.getElementById('manual-payment-file-name');
  receiptInput.addEventListener('change', () => {
    const file = receiptInput.files?.[0];
    receiptFileName.textContent = file ? `${file.name} · ${(file.size / (1024 * 1024)).toFixed(2)} MB` : 'No file selected';
    receiptFileName.classList.toggle('has-file', Boolean(file));
    bankReceiptError.textContent = '';
    receiptError.textContent = '';
  });
  ['dragenter', 'dragover'].forEach((eventName) => receiptDropzone.addEventListener(eventName, (event) => {
    event.preventDefault();
    receiptDropzone.classList.add('is-dragging');
  }));
  ['dragleave', 'drop'].forEach((eventName) => receiptDropzone.addEventListener(eventName, (event) => {
    event.preventDefault();
    receiptDropzone.classList.remove('is-dragging');
  }));
  receiptDropzone.addEventListener('drop', (event) => {
    const files = event.dataTransfer?.files;
    if (!files?.length) return;
    try {
      receiptInput.files = files;
      receiptInput.dispatchEvent(new Event('change', { bubbles: true }));
    } catch {
      bankReceiptError.textContent = 'Please use Browse files to select your receipt.';
    }
  });

  document.getElementById('manual-payment-submit').addEventListener('click', async () => {
    const file = receiptInput.files?.[0];
    if (!file) { receiptError.textContent = 'Choose your transfer receipt first.'; return; }
    if (file.size < 1 || file.size > 5 * 1024 * 1024) { receiptError.textContent = 'The receipt must be under 5 MB.'; return; }
    if (!paymentData) { receiptError.textContent = 'Your form details expired. Close this window and try again.'; return; }
    const submit = document.getElementById('manual-payment-submit');
    submit.disabled = true;
    submit.textContent = 'Submitting receipt…';
    receiptError.textContent = '';
    try {
      const body = new FormData();
      body.append('registration', JSON.stringify(paymentData.payload));
      body.append('proof', file);
      const response = await fetch('api/submit-manual-payment.php', { method: 'POST', body });
      const result = await readApiJson(response);
      if (!response.ok || !result.ok) throw new Error(result.error || 'Receipt submission failed.');
      window.location.href = 'manual-payment-pending?reference=' + encodeURIComponent(result.reference) + '&email=' + encodeURIComponent(result.email_status || 'failed');
    } catch (error) {
      receiptError.textContent = error.message || 'We could not submit the receipt. Please try again.';
      submit.disabled = false;
      submit.textContent = 'Submit receipt';
    }
  });
});
