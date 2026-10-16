(function () {
  let overlay;

  function ensureOverlay() {
    if (overlay) return overlay;

    const style = document.createElement("style");
    style.textContent = `
      .nacos-payment-overlay {
        position: fixed;
        inset: 0;
        z-index: 2147483000;
        display: grid;
        place-items: center;
        padding: 24px;
        background: rgba(3, 24, 13, .72);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
      }
      .nacos-payment-overlay[hidden] { display: none; }
      .nacos-payment-overlay__panel {
        width: min(100%, 360px);
        padding: 30px 26px;
        border: 1px solid rgba(255,255,255,.2);
        border-radius: 18px;
        background: #fff;
        color: #14251a;
        box-shadow: 0 24px 80px rgba(0,0,0,.28);
        text-align: center;
        font: 500 15px/1.5 Poppins, Inter, system-ui, sans-serif;
      }
      .nacos-payment-overlay__spinner {
        width: 42px;
        height: 42px;
        margin: 0 auto 18px;
        border: 4px solid #dcebe0;
        border-top-color: #08712c;
        border-radius: 50%;
        animation: nacos-payment-spin .8s linear infinite;
      }
      .nacos-payment-overlay__title {
        margin: 0 0 6px;
        color: #075c26;
        font-size: 18px;
        font-weight: 700;
      }
      .nacos-payment-overlay__message { margin: 0; color: #637268; }
      @keyframes nacos-payment-spin { to { transform: rotate(360deg); } }
      @media (prefers-reduced-motion: reduce) {
        .nacos-payment-overlay__spinner { animation-duration: 2s; }
      }
    `;
    document.head.appendChild(style);

    overlay = document.createElement("div");
    overlay.className = "nacos-payment-overlay";
    overlay.hidden = true;
    overlay.setAttribute("role", "status");
    overlay.setAttribute("aria-live", "assertive");
    overlay.innerHTML = `
      <div class="nacos-payment-overlay__panel">
        <div class="nacos-payment-overlay__spinner" aria-hidden="true"></div>
        <h2 class="nacos-payment-overlay__title">Connecting to Paystack</h2>
        <p class="nacos-payment-overlay__message">Please wait while we open secure checkout. Do not refresh this page.</p>
      </div>`;
    document.body.appendChild(overlay);
    return overlay;
  }

  window.NacosPaymentOverlay = {
    show(title, message) {
      const element = ensureOverlay();
      const titleElement = element.querySelector(".nacos-payment-overlay__title");
      const messageElement = element.querySelector(".nacos-payment-overlay__message");
      if (titleElement && title) titleElement.textContent = title;
      if (messageElement && message) messageElement.textContent = message;
      element.hidden = false;
      document.documentElement.style.overflow = "hidden";
    },
    hide() {
      if (overlay) overlay.hidden = true;
      document.documentElement.style.overflow = "";
    }
  };
})();
