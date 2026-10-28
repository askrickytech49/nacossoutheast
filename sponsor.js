document.addEventListener("DOMContentLoaded", () => {
  // --- HIDE PAGE LOADER ---
  const loader = document.getElementById("page-loader");
  if (loader) {
    loader.style.opacity = "0";
    setTimeout(() => {
      loader.style.display = "none";
    }, 400);
  }

  // --- ELEMENT REFERENCES ---

  // Form
  const sponsorForm = document.getElementById("sponsor-form");

  // Step 1 — Sponsor Details
  const sponsorFullname   = document.getElementById("sponsor-fullname");
  const sponsorEmail      = document.getElementById("sponsor-email");
  const sponsorPhone      = document.getElementById("sponsor-phone");
  const sponsorCategory   = document.getElementById("sponsor-category");
  const orgNameGroup      = document.getElementById("org-name-group");
  const sponsorOrgName    = document.getElementById("sponsor-org-name");

  // Step 2 — Package cards & custom amount
  const packageCards        = document.querySelectorAll(".package-card");
  const customAmountWrapper = document.getElementById("custom-amount-wrapper");
  const customAmountInput   = document.getElementById("custom-amount-input");
  const customPriceDisplay  = document.getElementById("custom-price-display");

  // Sidebar summary nodes
  const summarySponsorName     = document.getElementById("summary-sponsor-name");
  const summarySponsorCategory = document.getElementById("summary-sponsor-category");
  const summaryPkgName         = document.getElementById("summary-pkg-name");
  const summaryTotal           = document.getElementById("summary-total");

  // --- 1. ORG NAME TOGGLE (show when Corporate / NGO / Government selected) ---
  const categoriesWithOrg = ["Corporate", "NGO", "Government"];

  sponsorCategory.addEventListener("change", () => {
    if (categoriesWithOrg.includes(sponsorCategory.value)) {
      orgNameGroup.style.display = "flex";
    } else {
      orgNameGroup.style.display = "none";
      sponsorOrgName.value = "";
    }
    updateSponsorSummary();
  });

  // --- 2. LIVE SUMMARY UPDATE — Sponsor Name & Category ---
  sponsorFullname.addEventListener("input", updateSponsorSummary);

  // --- 3. PACKAGE TILE SELECTION ---
  packageCards.forEach((card) => {
    card.addEventListener("click", () => {
      packageCards.forEach((c) => c.classList.remove("active"));
      card.classList.add("active");

      const radio = card.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;

      // Toggle custom amount input visibility
      if (radio && radio.value === "custom") {
        customAmountWrapper.classList.add("visible");
        customAmountInput.focus();
      } else {
        customAmountWrapper.classList.remove("visible");
        customAmountInput.value = "";
        customPriceDisplay.textContent = "Enter below";
      }

      updateSponsorSummary();
    });
  });

  // --- 4. LIVE UPDATE CUSTOM AMOUNT DISPLAY ---
  customAmountInput.addEventListener("input", () => {
    const val = parseFloat(customAmountInput.value);
    if (!isNaN(val) && val > 0) {
      customPriceDisplay.textContent = "₦" + val.toLocaleString("en-US", { minimumFractionDigits: 2 });
    } else {
      customPriceDisplay.textContent = "Enter below";
    }
    updateSponsorSummary();
  });

  // --- 5. ORDER SUMMARY UPDATER ---
  function updateSponsorSummary() {
    const selectedRadio = document.querySelector('input[name="sponsor-package"]:checked');
    if (!selectedRadio) return;

    const isCustom     = selectedRadio.value === "custom";
    const packageName  = selectedRadio.getAttribute("data-name") || "HEADLINE SPONSOR";
    let   amount       = 0;

    if (isCustom) {
      amount = parseFloat(customAmountInput.value) || 0;
    } else {
      amount = parseFloat(selectedRadio.value) || 0;
    }

    // Name
    const nameVal = sponsorFullname.value.trim();
    if (summarySponsorName) {
      summarySponsorName.textContent = nameVal || "—";
    }

    // Category
    const catVal = sponsorCategory.value;
    if (summarySponsorCategory) {
      summarySponsorCategory.textContent = catVal || "—";
    }

    // Package
    if (summaryPkgName) summaryPkgName.textContent = packageName;

    // Total
    if (summaryTotal) {
      summaryTotal.textContent = amount > 0
        ? "₦" + amount.toLocaleString("en-US", { minimumFractionDigits: 2 })
        : "₦0.00";
    }

    return { packageName, amount, isCustom };
  }

  // --- 6. FORM SUBMIT — VALIDATE → FLUTTERWAVE → EMAILJS ---
  sponsorForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    // Gather values
    const fullName    = sponsorFullname.value.trim();
    const email       = sponsorEmail.value.trim();
    const phone       = sponsorPhone.value.trim();
    const category    = sponsorCategory.value;
    const orgName     = sponsorOrgName.value.trim();
    const message     = document.getElementById("sponsor-message").value.trim();

    const selectedRadio = document.querySelector('input[name="sponsor-package"]:checked');
    const isCustom      = selectedRadio && selectedRadio.value === "custom";
    const packageName   = selectedRadio ? selectedRadio.getAttribute("data-name") : "HEADLINE SPONSOR";
    let   amount        = 0;

    if (isCustom) {
      amount = parseFloat(customAmountInput.value) || 0;
    } else {
      amount = parseFloat(selectedRadio ? selectedRadio.value : 0) || 0;
    }

    // --- Validation ---
    if (!fullName) {
      alert("Please enter your full name.");
      sponsorFullname.focus();
      return;
    }

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      alert("Please enter a valid email address.");
      sponsorEmail.focus();
      return;
    }

    if (!phone) {
      alert("Please enter your phone number.");
      sponsorPhone.focus();
      return;
    }

    if (!category) {
      alert("Please select a sponsor category.");
      sponsorCategory.focus();
      return;
    }

    if (categoriesWithOrg.includes(category) && !orgName) {
      alert("Please enter your organisation or company name.");
      sponsorOrgName.focus();
      return;
    }

    if (isCustom && (isNaN(amount) || amount < 1000)) {
      alert("Please enter a valid custom sponsorship amount (minimum ₦1,000).");
      customAmountInput.focus();
      return;
    }

    if (amount <= 0) {
      alert("Please select a valid sponsorship package.");
      return;
    }

    const submitBtn = document.getElementById("btn-sponsor-submit");
    if (window.NacosManualPaymentChoice) {
      const choice = await window.NacosManualPaymentChoice({
        type: "sponsorship",
        amount,
        payload: {
          type: "sponsorship", package_name: packageName,
          custom_amount: isCustom ? Math.round(amount) : null,
          full_name: fullName, email, phone, category,
          organisation: orgName, message
        }
      });
      if (choice !== "automatic") return;
    }

    window.NacosPaymentOverlay?.show();
    let paystackPublicKey = "";
    try {
      const readinessResponse = await fetch("api/payment-readiness.php", { cache: "no-store" });
      const readiness = await readinessResponse.json();
      if (!readinessResponse.ok || !readiness.enabled || !readiness.public_key) throw new Error("Payment verification is not configured yet. Please contact the NACOS team.");
      paystackPublicKey = readiness.public_key;
    } catch (error) {
      window.NacosPaymentOverlay?.hide();
      alert(error.message || "Payments are temporarily unavailable. Please contact the NACOS team.");
      return;
    }

    // --- Disable submit button to prevent double clicks ---
    submitBtn.disabled = true;
    submitBtn.textContent = "Processing...";

    if (!window.PaystackPop || typeof window.PaystackPop.setup !== "function") {
      try {
        await new Promise((resolve, reject) => {
          const checkoutScript = document.createElement("script");
          const loadTimeout = window.setTimeout(() => reject(new Error("The secure payment checkout took too long to load. Please try again.")), 12000);
          checkoutScript.src = "https://js.paystack.co/v1/inline.js";
          checkoutScript.async = true;
          checkoutScript.onload = () => { window.clearTimeout(loadTimeout); resolve(); };
          checkoutScript.onerror = () => {
            window.clearTimeout(loadTimeout);
            reject(new Error("The secure payment checkout could not load. Check your internet connection and try again."));
          };
          document.head.appendChild(checkoutScript);
        });
      } catch (error) {
        window.NacosPaymentOverlay?.hide();
        alert(error.message || "Paystack is unavailable right now. Please refresh and try again.");
        submitBtn.disabled = false;
        submitBtn.textContent = "Sponsor Now";
        return;
      }
    }

    if (!window.PaystackPop || typeof window.PaystackPop.setup !== "function") {
      window.NacosPaymentOverlay?.hide();
      alert("Paystack is unavailable right now. Please refresh and try again.");
      submitBtn.disabled = false;
      submitBtn.textContent = "Sponsor Now";
      return;
    }

    // --- Launch Paystack Checkout (test mode) ---
    try {
    var handler = PaystackPop.setup({
      key: paystackPublicKey,
      email: email,
      amount: amount * 100, // Paystack uses kobo (amount × 100)
      currency: "NGN",
      ref: "NACOS-SE-SPONSOR-" + Math.floor(Math.random() * 1000000000 + 1),
      metadata: {
        custom_fields: [
          { display_name: "Full Name", variable_name: "full_name", value: fullName },
          { display_name: "Phone", variable_name: "phone", value: phone },
          { display_name: "Category", variable_name: "category", value: category },
          { display_name: "Package", variable_name: "package", value: packageName }
        ]
      },
      callback: function (response) {
        window.NacosPaymentOverlay?.show("Verifying payment", "Payment received. We are verifying it and saving your sponsorship.");
        (async function () {
          try {
          const saveResponse = await fetch("api/save-paid-registration.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              type: "sponsorship",
              reference: response.reference,
              package_name: packageName,
              custom_amount: isCustom ? Math.round(amount) : null,
              full_name: fullName,
              email,
              phone,
              category,
              organisation: orgName,
              message
            })
          });
          const saved = await saveResponse.json();
          if (!saveResponse.ok || !saved.ok) throw new Error(saved.error || "Payment could not be verified.");

          // Payment is verified and notification emails are sent by the server.
          window.location.href = "sponsor-confirmation?email=" + encodeURIComponent(saved.email_status || "failed");
          } catch (e) {
            console.error("Callback Execution Error:", e);
            alert(`${e.message || "We could not save your sponsorship."} Your payment reference is ${response.reference}. Please contact the NACOS team and keep this reference.`);
            window.NacosPaymentOverlay?.hide();
            submitBtn.disabled = false;
            submitBtn.textContent = "Sponsor Now";
          }
        })();
      },
      onClose: function () {
        console.log("Sponsorship payment popup closed.");
        window.NacosPaymentOverlay?.hide();
        submitBtn.disabled = false;
        submitBtn.textContent = "Sponsor Now";
      },
    });
    handler.openIframe();
    window.NacosPaymentOverlay?.hide();
    } catch (error) {
      console.error("Could not open Paystack checkout:", error);
      window.NacosPaymentOverlay?.hide();
      alert(error.message || "We could not open secure checkout. Please try again.");
      submitBtn.disabled = false;
      submitBtn.textContent = "Sponsor Now";
    }
  });

  // --- Initial summary render ---
  updateSponsorSummary();
});
