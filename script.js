document.addEventListener("DOMContentLoaded", () => {
  // --- HIDE PAGE LOADER ---
  const loader = document.getElementById("page-loader");
  if (loader) {
    loader.style.opacity = "0";
    setTimeout(() => {
      loader.style.display = "none";
    }, 400);
  }

  // Initialize EmailJS with your Public Key
  emailjs.init("VMO83Ev6znjpADXp8");

  // Navigation & Toggle Elements
  const conventionForm = document.getElementById("convention-form");
  const singleForm = document.getElementById("single-form");
  const bulkForm = document.getElementById("bulk-form");
  const singleRadio = document.getElementById("type-single-radio");
  const bulkRadio = document.getElementById("type-bulk-radio");
  const tileSingle = document.getElementById("tile-single");
  const tileBulk = document.getElementById("tile-bulk");

  // Single Form Input Elements
  const singleFullName = document.getElementById("single-fullname");
  const singleEmail = document.getElementById("single-email");
  const singlePhone = document.getElementById("single-phone");
  const singleInstSelect = document.getElementById("single-institution");
  const singleOtherInst = document.getElementById("single-other-inst");
  const singleOtherInstGroup = document.getElementById("single-other-inst-group");
  const singleState = document.getElementById("single-state");

  // Bulk Form Input Elements
  const bulkInstSelect = document.getElementById("bulk-institution");
  const bulkOtherInst = document.getElementById("bulk-other-inst");
  const bulkOtherInstGroup = document.getElementById("bulk-other-inst-group");
  const tableBody = document.getElementById("students-table-body");
  const addStudentBtn = document.getElementById("add-student-btn");

  // Order Summary Display Nodes
  const summaryPkgName = document.getElementById("summary-pkg-name");
  const summaryQty = document.getElementById("summary-qty");
  const summaryTotal = document.getElementById("summary-total");

  let studentCount = 0;

  // --- 1. DYNAMIC INSTITUTION MANUAL INPUT TOGGLE ---
  function handleInstitutionToggle(selectEl, groupEl) {
    if (!selectEl || !groupEl) return;
    selectEl.addEventListener("change", () => {
      if (selectEl.value === "other") {
        groupEl.style.display = "flex";
      } else {
        groupEl.style.display = "none";
      }
    });
  }

  handleInstitutionToggle(singleInstSelect, singleOtherInstGroup);
  handleInstitutionToggle(bulkInstSelect, bulkOtherInstGroup);

  // --- 2. REGISTRATION TYPE TOGGLE ---
  function setRegType(type) {
    if (type === "single") {
      if (singleForm) singleForm.style.display = "block";
      if (bulkForm) bulkForm.style.display = "none";
      tileSingle.classList.add("active");
      tileBulk.classList.remove("active");
      singleRadio.checked = true;
    } else {
      if (singleForm) singleForm.style.display = "none";
      if (bulkForm) bulkForm.style.display = "block";
      tileBulk.classList.add("active");
      tileSingle.classList.remove("active");
      bulkRadio.checked = true;

      if (tableBody && tableBody.children.length === 0) {
        addStudentRow();
      }
    }
    updateOrderSummary();
  }

  if (singleRadio && bulkRadio) {
    singleRadio.addEventListener("change", () => setRegType("single"));
    bulkRadio.addEventListener("change", () => setRegType("bulk"));
  }

  // --- 3. DYNAMIC BULK TABLE ROW HANDLING ---
  function addStudentRow() {
    if (!tableBody) return;

    studentCount++;
    const row = document.createElement("tr");
    row.id = `student-row-${studentCount}`;
    row.innerHTML = `
      <td data-label="S/N" data-index="${studentCount}">#${studentCount}</td>
      <td data-label="Full Name"><input type="text" class="bulk-name" placeholder="Full Name" required /></td>
      <td data-label="Email Address"><input type="email" class="bulk-email" placeholder="Email Address" required /></td>
      <td data-label="Phone Number"><input type="tel" class="bulk-phone" placeholder="Phone Number" required /></td>
      <td data-label="Action">
        <button type="button" class="btn-danger" onclick="removeStudentRow(${studentCount})">Remove</button>
      </td>
    `;
    tableBody.appendChild(row);
    updateRowNumbers();
    updateOrderSummary();
  }

  window.removeStudentRow = function (id) {
    const row = document.getElementById(`student-row-${id}`);
    if (row) {
      row.remove();
      updateRowNumbers();
      updateOrderSummary();
    }
  };

  function updateRowNumbers() {
    if (!tableBody) return;
    const rows = tableBody.querySelectorAll("tr");
    rows.forEach((row, index) => {
      const snCell = row.cells[0];
      if (snCell) {
        snCell.innerText = `#${index + 1}`;
        snCell.setAttribute("data-index", index + 1);
      }
    });
  }

  if (addStudentBtn) {
    addStudentBtn.addEventListener("click", addStudentRow);
  }

  // --- 4. PACKAGE SELECTION & DYNAMIC FEE CALCULATION ---
  const packageCards = document.querySelectorAll(".package-card");

  packageCards.forEach((card) => {
    card.addEventListener("click", () => {
      packageCards.forEach((c) => c.classList.remove("active"));
      card.classList.add("active");
      const radio = card.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;
      updateOrderSummary();
    });
  });

  function updateOrderSummary() {
    const selectedPkgRadio = document.querySelector('input[name="package"]:checked');
    if (!selectedPkgRadio) return;

    const unitPrice = parseFloat(selectedPkgRadio.value) || 0;
    const packageName = selectedPkgRadio.getAttribute("data-name") || "HOME NACOSITE";

    let quantity = 1;
    if (bulkRadio && bulkRadio.checked && tableBody) {
      const rows = tableBody.querySelectorAll("tr");
      quantity = rows.length > 0 ? rows.length : 1;
    }

    const totalAmount = unitPrice * quantity;

    if (summaryPkgName) summaryPkgName.innerText = packageName;
    if (summaryQty) summaryQty.innerText = quantity;
    if (summaryTotal) summaryTotal.innerText = `₦${totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;

    return { unitPrice, packageName, quantity, totalAmount };
  }

  // --- 5. PAYMENT AND EMAIL TRIGGER LOGIC ---
  conventionForm.addEventListener("submit", (e) => {
    e.preventDefault();

    const isSingle = singleRadio.checked;
    const summary = updateOrderSummary();

    let primaryName = "";
    let primaryEmail = "";
    let primaryPhone = "";
    let institution = "";
    let registrationDetailsFormatted = "";

    if (isSingle) {
      primaryName = singleFullName.value.trim();
      primaryEmail = singleEmail.value.trim();
      primaryPhone = singlePhone.value.trim();
      institution = singleInstSelect.value === "other" ? singleOtherInst.value.trim() : singleInstSelect.value;
      const state = singleState.value;

      if (!primaryName || !primaryEmail || !primaryPhone || !institution || !state) {
        alert("Please fill in all required single registration fields.");
        return;
      }

      registrationDetailsFormatted = `
        <p><strong>Institution:</strong> ${institution}</p>
        <p><strong>State:</strong> ${state}</p>
        <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px;">
          <thead>
            <tr style="background-color: #f2f2f2;">
              <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Full Name</th>
              <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Email</th>
              <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Phone</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td style="border: 1px solid #ddd; padding: 8px;">${primaryName}</td>
              <td style="border: 1px solid #ddd; padding: 8px;">${primaryEmail}</td>
              <td style="border: 1px solid #ddd; padding: 8px;">${primaryPhone}</td>
            </tr>
          </tbody>
        </table>
      `;
    } else {
      institution = bulkInstSelect.value === "other" ? bulkOtherInst.value.trim() : bulkInstSelect.value;
      const rows = tableBody.querySelectorAll("tr");

      if (rows.length === 0) {
        alert("Please add at least one student for bulk registration.");
        return;
      }

      let studentsRowsHTML = "";
      let hasError = false;

      rows.forEach((row, index) => {
        const name = row.querySelector(".bulk-name").value.trim();
        const email = row.querySelector(".bulk-email").value.trim();
        const phone = row.querySelector(".bulk-phone").value.trim();

        if (!name || !email || !phone) {
          hasError = true;
        }

        if (index === 0) {
          primaryName = name;
          primaryEmail = email;
          primaryPhone = phone;
        }

        studentsRowsHTML += `
          <tr>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${index + 1}</td>
            <td style="border: 1px solid #ddd; padding: 8px;">${name}</td>
            <td style="border: 1px solid #ddd; padding: 8px;">${email}</td>
            <td style="border: 1px solid #ddd; padding: 8px;">${phone}</td>
          </tr>
        `;
      });

      if (hasError) {
        alert("Please complete all input fields for every student added in the table.");
        return;
      }

      registrationDetailsFormatted = `
        <p><strong>Institution:</strong> ${institution}</p>
        <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px;">
          <thead>
            <tr style="background-color: #f2f2f2;">
              <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">S/N</th>
              <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Full Name</th>
              <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Email</th>
              <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Phone</th>
            </tr>
          </thead>
          <tbody>
            ${studentsRowsHTML}
          </tbody>
        </table>
      `;
    }

    // Paystack Configuration (Live Keys linked)
    // Public Key: pk_live_2ddd7e34db28418ede6056484eb10fe3a3d55ee9
    // Secret Key (Server / .env reference): sk_live_***
    var handler = PaystackPop.setup({
      key: "pk_live_2ddd7e34db28418ede6056484eb10fe3a3d55ee9", // Using provided live key
      email: primaryEmail,
      amount: summary.totalAmount * 100, // Paystack uses kobo (amount × 100)
      currency: "NGN",
      ref: "NACOS_SE_" + Math.floor(Math.random() * 1000000000 + 1),
      metadata: {
        custom_fields: [
          { display_name: "Full Name", variable_name: "full_name", value: primaryName },
          { display_name: "Phone", variable_name: "phone", value: primaryPhone },
          { display_name: "Package", variable_name: "package", value: summary.packageName }
        ]
      },
      callback: function (response) {
        try {
          // Payment successful
          const templateParams = {
            reg_type: isSingle ? "Single Registration" : "Bulk Registration",
            package_name: summary.packageName,
            amount: summary.totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2 }),
            tx_ref: response.reference,
            registration_details: registrationDetailsFormatted,
            to_email: isSingle ? primaryEmail : "nacosoutheast@gmail.com",
            from_name: primaryName
          };

          emailjs.send("service_yb50t9c", "template_pz1fk73", templateParams)
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
      onClose: function() {
        console.log("Payment popup closed.");
      }
    });
    handler.openIframe();
  });

  // Initial Calculation
  updateOrderSummary();
});
