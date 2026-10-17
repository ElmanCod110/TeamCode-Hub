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

    /* Keep removable chip examples interactive without touching application data. */
    document.querySelectorAll(".chip .x").forEach(function (button) {
      button.addEventListener("click", function () {
        const chip = button.closest(".chip");
        if (chip) chip.remove();
        if (window.TCHUI && typeof window.TCHUI.notify === "function") {
          window.TCHUI.notify("The example chip was removed locally.");
        }
      });
    });

    /* Make the showcase pager update its selected page and boundary states. */
    document.querySelectorAll(".pager").forEach(function (pager) {
      const pageButtons = Array.from(pager.querySelectorAll("button"))
        .filter(function (button) {
          return !button.hasAttribute("aria-label") && /^\\d+$/.test(button.textContent.trim());
        });
      const previous = pager.querySelector('[aria-label="Previous page"]');
      const next = pager.querySelector('[aria-label="Next page"]');

      function selectPage(index) {
        if (!pageButtons.length) return;
        const boundedIndex = Math.max(0, Math.min(pageButtons.length - 1, index));
        pageButtons.forEach(function (button, buttonIndex) {
          const selected = buttonIndex === boundedIndex;
          button.classList.toggle("active", selected);
          if (selected) button.setAttribute("aria-current", "page");
          else button.removeAttribute("aria-current");
        });
        if (previous) previous.disabled = boundedIndex === 0;
        if (next) next.disabled = boundedIndex === pageButtons.length - 1;
      }

      pageButtons.forEach(function (button, index) {
        button.addEventListener("click", function () { selectPage(index); });
      });
      if (previous) previous.addEventListener("click", function () {
        const activeIndex = pageButtons.findIndex(function (button) { return button.classList.contains("active"); });
        selectPage(activeIndex - 1);
      });
      if (next) next.addEventListener("click", function () {
        const activeIndex = pageButtons.findIndex(function (button) { return button.classList.contains("active"); });
        selectPage(activeIndex + 1);
      });
    });

    /* File selection is preview-only; no bytes leave the browser in this showcase. */
    const uploadInput = document.getElementById("demo-upload");
    if (uploadInput) {
      uploadInput.addEventListener("change", function () {
        const file = uploadInput.files && uploadInput.files[0];
        const dropzone = uploadInput.closest(".dropzone");
        const title = dropzone ? dropzone.querySelector("b") : null;
        if (title && file) title.textContent = "Selected locally: " + file.name;
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
