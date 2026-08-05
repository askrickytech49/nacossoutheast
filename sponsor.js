document.addEventListener("DOMContentLoaded", () => {
  // --- HIDE PAGE LOADER ---
  const loader = document.getElementById("page-loader");
  if (loader) {
    loader.style.opacity = "0";
    setTimeout(() => {
      loader.style.display = "none";
    }, 400);
  }

  // Initialize EmailJS with Public Key
  emailjs.init("VMO83Ev6znjpADXp8");

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
  sponsorForm.addEventListener("submit", (e) => {
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

    // --- Disable submit button to prevent double clicks ---
    const submitBtn = document.getElementById("btn-sponsor-submit");
    submitBtn.disabled = true;
    submitBtn.textContent = "Processing...";

    // --- Launch Paystack Checkout (Live Keys linked) ---
    // Public Key: pk_live_2ddd7e34db28418ede6056484eb10fe3a3d55ee9
    // Secret Key (Server / .env reference): sk_live_***
    var handler = PaystackPop.setup({
      key: "pk_live_2ddd7e34db28418ede6056484eb10fe3a3d55ee9", // Using provided live key
      email: email,
      amount: amount * 100, // Paystack uses kobo (amount × 100)
      currency: "NGN",
      ref: "NACOS_SE_SPONSOR_" + Math.floor(Math.random() * 1000000000 + 1),
      metadata: {
        custom_fields: [
          { display_name: "Full Name", variable_name: "full_name", value: fullName },
          { display_name: "Phone", variable_name: "phone", value: phone },
          { display_name: "Category", variable_name: "category", value: category },
          { display_name: "Package", variable_name: "package", value: packageName }
        ]
      },
      callback: function (response) {
        try {
          // Payment successful
          const displayName = orgName ? `${fullName} (${orgName})` : fullName;
          const sponsorDetailsFormatted =
            `Name: ${displayName} | Email: ${email} | Phone: ${phone} | Category: ${category} | Package: ${packageName} | Amount: ₦${amount.toLocaleString("en-US", { minimumFractionDigits: 2 })}${message ? " | Message: " + message : ""}`;

          const templateParams = {
            reg_type: "Sponsorship",
            package_name: packageName,
            amount: amount.toLocaleString("en-US", { minimumFractionDigits: 2 }),
            tx_ref: response.reference,
            registration_details: sponsorDetailsFormatted,
            to_email: email,
            from_name: fullName,
          };

          emailjs
            .send("service_yb50t9c", "template_pz1fk73", templateParams)
            .then(() => {
              window.location.href = "thank-you.html";
            })
            .catch((err) => {
              console.error("EmailJS Error:", err);
              // Payment succeeded even if email failed — still redirect
              window.location.href = "thank-you.html";
            });
        } catch (e) {
          console.error("Callback Execution Error:", e);
          window.location.href = "thank-you.html";
        }
      },
      onClose: function () {
        console.log("Sponsorship payment popup closed.");
        submitBtn.disabled = false;
        submitBtn.textContent = "Sponsor Now";
      },
    });
    handler.openIframe();
  });

  // --- Initial summary render ---
  updateSponsorSummary();
});
