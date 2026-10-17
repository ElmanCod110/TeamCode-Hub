/* ==========================================================================
   TeamCode Hub Design System Showcase — Demo-only behavior
   File: public/assets/js/design-system-demo.js
   Purpose: Wire the reference page's sample buttons without inline JavaScript.
   ========================================================================== */

(function () {
  "use strict";

  /* Do not fail silently if the main interaction controller is unavailable. */
  function initializeShowcase() {
    const toastButton = document.getElementById("show-toast");
    const emptyAction = document.getElementById("empty-action");
    const year = document.getElementById("year");

    if (toastButton) {
      toastButton.addEventListener("click", function () {
        if (window.TCHUI && typeof window.TCHUI.notify === "function") {
          window.TCHUI.notify("This is a sample notification from the design system.");
        }
      });
    }

    if (emptyAction) {
      emptyAction.addEventListener("click", function () {
        if (window.TCHUI && typeof window.TCHUI.notify === "function") {
          window.TCHUI.notify("The projects page will be connected when its UI is implemented.");
        }
      });
    }

    /* Keep the footer year accurate without embedding script in the HTML page. */
    if (year) year.textContent = String(new Date().getFullYear());
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeShowcase, { once: true });
  } else {
    initializeShowcase();
  }
})();
