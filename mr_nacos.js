document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("mr-nacos-form");
  const alertBox = document.getElementById("mr-form-alert");
  const submitButton = document.getElementById("mr-submit-button");
  const success = document.getElementById("mr-success");
  const photoInput = document.getElementById("mr-photo");
  const categoryCards = document.querySelectorAll(".mr-category-card");
  const drawerBackdrop = document.getElementById("mr-drawer-backdrop");
  const sideDrawer = document.getElementById("mr-side-drawer");
  const hamburgerButton = document.getElementById("mr-hamburger-btn");
  const closeDrawerButton = document.getElementById("mr-drawer-close");

  const setDrawerOpen = (open) => {
    if (!drawerBackdrop || !sideDrawer) return;
    sideDrawer.classList.toggle("open", open);
    drawerBackdrop.classList.toggle("open", open);
    sideDrawer.setAttribute("aria-hidden", String(!open));
  };

  if (hamburgerButton) {
    hamburgerButton.addEventListener("click", () => setDrawerOpen(true));
  }

  if (closeDrawerButton) {
    closeDrawerButton.addEventListener("click", () => setDrawerOpen(false));
  }

  if (drawerBackdrop) {
    drawerBackdrop.addEventListener("click", () => setDrawerOpen(false));
  }

  if (sideDrawer) {
    sideDrawer.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", () => setDrawerOpen(false));
    });
  }

  if (!form || !alertBox || !submitButton || !success || !photoInput) return;
  const photoPreview = document.getElementById("mr-photo-preview");
  const photoPreviewImage = document.getElementById("mr-photo-preview-image");
  const photoFileName = document.getElementById("mr-photo-filename");
  const unpaidReasonGroup = document.getElementById("mr-unpaid-reason-group");
  const unpaidReasonInput = document.getElementById("mr-unpaid-reason");
  const getPaymentMade = () => form.querySelector('input[name="paymentMade"]:checked')?.value || "";
  const updatePaymentReason = () => {
    const notPaid = getPaymentMade() === "No";
    if (unpaidReasonGroup) unpaidReasonGroup.hidden = !notPaid;
    if (unpaidReasonInput) unpaidReasonInput.required = notPaid;
  };
  form.querySelectorAll('input[name="paymentMade"]').forEach((input) => input.addEventListener("change", updatePaymentReason));
  updatePaymentReason();

  const uploadCard = photoInput.closest(".mr-upload-card");
  if (uploadCard) {
    ["dragenter", "dragover"].forEach((eventName) => {
      uploadCard.addEventListener(eventName, (event) => {
        event.preventDefault();
        uploadCard.classList.add("is-dragging");
      });
    });

    ["dragleave", "dragend", "drop"].forEach((eventName) => {
      uploadCard.addEventListener(eventName, (event) => {
        event.preventDefault();
        uploadCard.classList.remove("is-dragging");
      });
    });

    uploadCard.addEventListener("drop", (event) => {
      const droppedFile = event.dataTransfer.files[0];
      if (!droppedFile) return;
      const transfer = new DataTransfer();
      transfer.items.add(droppedFile);
      photoInput.files = transfer.files;
      photoInput.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }
  photoInput.addEventListener("change", () => {
    const photo = photoInput.files[0];
    if (!photo) {
      photoPreview.hidden = true;
      photoPreviewImage.removeAttribute("src");
      photoFileName.textContent = "No photo selected";
      return;
    }

    photoFileName.textContent = photo.name;
    if (!photo.type.startsWith("image/") || photo.size > 5 * 1024 * 1024) {
      photoPreview.hidden = true;
      photoPreviewImage.removeAttribute("src");
      return;
    }

    const reader = new FileReader();
    reader.addEventListener("load", () => {
      photoPreviewImage.src = reader.result;
      photoPreview.hidden = false;
    });
    reader.readAsDataURL(photo);
  });

  categoryCards.forEach((card) => {
    const radio = card.querySelector('input[type="radio"]');
    if (!radio) return;
    radio.addEventListener("change", () => {
      categoryCards.forEach((item) => item.classList.remove("is-selected"));
      if (radio.checked) card.classList.add("is-selected");
    });
  });

  const getSelectedCategory = () => {
    const checked = form.querySelector('input[name="category"]:checked');
    return checked ? checked.value : "Mr NACOS";
  };

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    alertBox.textContent = "";
    alertBox.className = "mr-form-alert";

    if (!form.checkValidity()) {
      form.reportValidity();
      alertBox.textContent = "Please complete all required fields before submitting.";
      alertBox.classList.add("is-error");
      return;
    }

    const photo = photoInput.files[0];
    if (!photo || !photo.type.startsWith("image/") || photo.size > 10 * 1024 * 1024) {
      alertBox.textContent = "Please select a JPG, PNG or WEBP image no larger than 10 MB.";
      alertBox.classList.add("is-error");
      photoInput.focus();
      return;
    }

    const fullName = document.getElementById("mr-full-name").value.trim();
    const email = document.getElementById("mr-email").value.trim();
    const phone = document.getElementById("mr-phone").value.trim();
    const institution = document.getElementById("mr-institution").value.trim();
    const department = document.getElementById("mr-department").value.trim();
    const level = document.getElementById("mr-level").value;
    const state = document.getElementById("mr-state").value.trim();
    const bio = document.getElementById("mr-bio").value.trim();
    const socialHandles = document.getElementById("mr-social").value.trim();
    const dateOfBirth = document.getElementById("mr-dob").value;
    const category = getSelectedCategory();
    const gender = form.querySelector('input[name="gender"]:checked')?.value || "";
    const conventionRegistered = form.querySelector('input[name="conventionRegistered"]:checked')?.value || "";
    const whyParticipate = document.getElementById("mr-why-participate").value.trim();
    const suitableRepresentative = document.getElementById("mr-suitable-representative").value.trim();
    const nacosMeaning = document.getElementById("mr-nacos-meaning").value.trim();
    const accuracyRulesAgreement = form.querySelector('input[name="accuracyRulesAgreement"]:checked')?.value || "";
    const mediaConsent = form.querySelector('input[name="mediaConsent"]:checked')?.value || "";
    const paymentMade = getPaymentMade();
    const reasonNotPaid = unpaidReasonInput?.value.trim() || "";

    const amount = 20000;

    if (window.NacosManualPaymentChoice) {
      const choice = await window.NacosManualPaymentChoice({
        type: "pageant",
        amount,
        errorTarget: alertBox,
        photo,
        alreadyPaid: paymentMade === "Yes",
        payload: {
          type: "pageant", category, full_name: fullName, email, phone,
          institution, department, level, state, date_of_birth: dateOfBirth,
          bio, social_handles: socialHandles, gender,
          convention_registered: conventionRegistered,
          why_participate: whyParticipate,
          suitable_representative: suitableRepresentative,
          nacos_meaning: nacosMeaning,
          accuracy_rules_agreement: accuracyRulesAgreement,
          media_promotional_consent: mediaConsent,
          have_made_registration_payment: paymentMade,
          reason_not_made_payment: reasonNotPaid
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
      alertBox.textContent = error.message || "Payments are temporarily unavailable. Please contact the NACOS team.";
      alertBox.classList.add("is-error");
      return;
    }

    submitButton.disabled = true;
    submitButton.classList.add("is-loading");

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
        alertBox.textContent = error.message || "Paystack is unavailable right now. Please refresh and try again.";
        alertBox.classList.add("is-error");
        submitButton.disabled = false;
        submitButton.classList.remove("is-loading");
        return;
      }
    }

    if (window.PaystackPop && typeof window.PaystackPop.setup === "function") {
      try {
      const handler = PaystackPop.setup({
        key: paystackPublicKey,
        email: email,
        amount: amount * 100,
        currency: "NGN",
        ref: "NACOS-MR-MISS-" + Date.now() + "-" + Math.floor(Math.random() * 100000),
        metadata: {
          custom_fields: [
            { display_name: "Full Name", variable_name: "full_name", value: fullName },
            { display_name: "Phone", variable_name: "phone", value: phone },
            { display_name: "Category", variable_name: "category", value: category },
            { display_name: "Institution", variable_name: "institution", value: institution },
            { display_name: "Department", variable_name: "department", value: department },
            { display_name: "Level", variable_name: "level", value: level }
          ]
        },
        callback: function (response) {
          window.NacosPaymentOverlay?.show("Verifying payment", "Payment received. We are verifying it and saving your application.");
          (async function () {
            try {
            const submission = {
              type: "pageant",
              reference: response.reference,
              category,
              full_name: fullName,
              email,
              phone,
              institution,
              department,
              level,
              state,
              date_of_birth: dateOfBirth,
              bio,
              social_handles: socialHandles,
              gender,
              convention_registered: conventionRegistered,
              why_participate: whyParticipate,
              suitable_representative: suitableRepresentative,
              nacos_meaning: nacosMeaning,
              accuracy_rules_agreement: accuracyRulesAgreement,
              media_promotional_consent: mediaConsent,
              have_made_registration_payment: paymentMade,
              reason_not_made_payment: reasonNotPaid
            };
            const formData = new FormData();
            formData.append("submission", JSON.stringify(submission));
            formData.append("photo", photo, photo.name);
            const saveResponse = await fetch("api/save-paid-registration.php", { method: "POST", body: formData });
            const saved = await saveResponse.json();
            if (!saveResponse.ok || !saved.ok) throw new Error(saved.error || "Payment could not be verified.");

            window.location.href = "mr-miss-confirmation?email=" + encodeURIComponent(saved.email_status || "failed");
            } catch (error) {
              console.error("Mr & Miss NACOS payment callback error:", error);
              alertBox.textContent = `${error.message || "Payment was received, but the application could not be saved."} Keep your payment reference: ${response.reference}. Please contact the NACOS team.`;
              alertBox.classList.add("is-error");
            } finally {
              window.NacosPaymentOverlay?.hide();
              submitButton.disabled = false;
              submitButton.classList.remove("is-loading");
            }
          })();
        },
        onClose: function () {
          window.NacosPaymentOverlay?.hide();
          submitButton.disabled = false;
          submitButton.classList.remove("is-loading");
          alertBox.textContent = "Payment was cancelled. Your form has not been submitted yet.";
          alertBox.classList.add("is-error");
        }
      });

      handler.openIframe();
      window.NacosPaymentOverlay?.hide();
      } catch (error) {
        console.error("Could not open Mr & Miss NACOS checkout:", error);
        window.NacosPaymentOverlay?.hide();
        alertBox.textContent = error.message || "We could not open secure checkout. Please try again.";
        alertBox.classList.add("is-error");
        submitButton.disabled = false;
        submitButton.classList.remove("is-loading");
      }
      return;
    }

    alertBox.textContent = "Paystack is unavailable right now. Please refresh and try again.";
    window.NacosPaymentOverlay?.hide();
    alertBox.classList.add("is-error");
    submitButton.disabled = false;
    submitButton.classList.remove("is-loading");
  });
});


