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

  form.addEventListener("submit", (event) => {
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
    if (!photo || !photo.type.startsWith("image/") || photo.size > 5 * 1024 * 1024) {
      alertBox.textContent = "Please select a JPG, PNG or WEBP image no larger than 5 MB.";
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
    const category = getSelectedCategory();

    const amount = 20000;

    submitButton.disabled = true;
    submitButton.classList.add("is-loading");

    if (window.PaystackPop) {
      const handler = PaystackPop.setup({
        key: "pk_live_2ddd7e34db28418ede6056484eb10fe3a3d55ee9",
        email: email,
        amount: amount * 100,
        currency: "NGN",
        ref: "NACOS_MR_MISS_" + Date.now() + "_" + Math.floor(Math.random() * 100000),
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
          try {
            const templateParams = {
              reg_type: "Mr & Miss NACOS",
              package_name: category,
              amount: amount.toLocaleString("en-US", { minimumFractionDigits: 2 }),
              tx_ref: response.reference,
              registration_details: `
                <p><strong>Category:</strong> ${category}</p>
                <p><strong>Full Name:</strong> ${fullName}</p>
                <p><strong>Email:</strong> ${email}</p>
                <p><strong>Phone:</strong> ${phone}</p>
                <p><strong>Institution:</strong> ${institution}</p>
                <p><strong>Department:</strong> ${department}</p>
                <p><strong>Level:</strong> ${level}</p>
                <p><strong>State:</strong> ${state}</p>
                <p><strong>Bio:</strong> ${bio}</p>
                <p><strong>Social Handles:</strong> ${socialHandles || "Not provided"}</p>
              `,
              to_email: email,
              from_name: fullName
            };

            if (window.emailjs) {
              emailjs.init("VMO83Ev6znjpADXp8");
              emailjs.send("service_yb50t9c", "template_pz1fk73", templateParams)
                .catch((error) => console.error("EmailJS Error:", error));
            }

            form.hidden = true;
            success.hidden = false;
            success.scrollIntoView({ behavior: "smooth", block: "center" });
            alertBox.textContent = "";
          } catch (error) {
            console.error("Mr & Miss NACOS payment callback error:", error);
            alertBox.textContent = "Payment was received, but the final confirmation could not be completed. Please contact the NACOS team.";
            alertBox.classList.add("is-error");
          } finally {
            submitButton.disabled = false;
            submitButton.classList.remove("is-loading");
          }
        },
        onClose: function () {
          submitButton.disabled = false;
          submitButton.classList.remove("is-loading");
          alertBox.textContent = "Payment was cancelled. Your form has not been submitted yet.";
          alertBox.classList.add("is-error");
        }
      });

      handler.openIframe();
      return;
    }

    alertBox.textContent = "Paystack is unavailable right now. Please refresh and try again.";
    alertBox.classList.add("is-error");
    submitButton.disabled = false;
    submitButton.classList.remove("is-loading");
  });
});
