(() => {
  const emailStatus = new URLSearchParams(window.location.search).get("email");
  const note = document.getElementById("confirmation-email-note");
  if (!note || !emailStatus || emailStatus === "sent") return;
  const text = note.querySelector("span");
  if (text) {
    text.textContent = "Your payment and submission are confirmed. We could not send the email receipt just now; please keep your payment reference and contact the NACOS team if you need help.";
  }
});
