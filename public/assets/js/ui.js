/* ==========================================================================
   TeamCode Hub Design System — UI behavior controller
   File: public/assets/js/ui.js
   Purpose: Dependency-free, accessible interactions for the design system.
   All interactions are progressive enhancements; links and forms remain native.
   ========================================================================== */

(function () {
  "use strict";

  /* Guard against duplicate initialization if the asset is included twice. */
  if (window.TCHUI) return;

  const root = document.documentElement;
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

  /* Return an element only when the supplied ID is a safe, non-empty string. */
  function byId(id) {
    if (typeof id !== "string" || id.trim() === "") return null;
    return document.getElementById(id);
  }

  /* Keep ARIA state and visual state synchronized for disclosure controls. */
  function setExpanded(trigger, expanded) {
    trigger.setAttribute("aria-expanded", expanded ? "true" : "false");
  }

  /* Close an open menu and return focus to its trigger when requested. */
  function closeDropdown(menu, restoreFocus) {
    const dropdown = menu.closest(".dropdown");
    const trigger = dropdown ? dropdown.querySelector("[data-dropdown-toggle]") : null;
    menu.hidden = true;
    if (trigger) setExpanded(trigger, false);
    if (restoreFocus && trigger) trigger.focus();
  }

  /* Dropdown menus close on outside click and Escape without stealing focus. */
  function initializeDropdowns() {
    document.querySelectorAll("[data-dropdown-toggle]").forEach(function (trigger) {
      const id = trigger.getAttribute("aria-controls");
      const menu = byId(id);
      if (!menu) return;

      trigger.addEventListener("click", function () {
        const shouldOpen = menu.hidden;
        document.querySelectorAll(".dropdown-menu:not([hidden])").forEach(function (openMenu) {
          if (openMenu !== menu) closeDropdown(openMenu, false);
        });
        menu.hidden = !shouldOpen;
        setExpanded(trigger, shouldOpen);
      });

      menu.addEventListener("click", function (event) {
        if (event.target.closest("[data-dropdown-close]")) closeDropdown(menu, false);
      });

      menu.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          event.preventDefault();
          closeDropdown(menu, true);
        }
      });
    });

    document.addEventListener("click", function (event) {
      if (event.target.closest(".dropdown")) return;
      document.querySelectorAll(".dropdown-menu:not([hidden])").forEach(function (menu) {
        closeDropdown(menu, false);
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") return;
      document.querySelectorAll(".dropdown-menu:not([hidden])").forEach(function (menu) {
        closeDropdown(menu, false);
      });
    });
  }

  /* Tabs support a button list plus panels identified by the same data key. */
  function initializeTabs() {
    document.querySelectorAll("[data-tabs]").forEach(function (tabList) {
      const tabs = Array.from(tabList.querySelectorAll("[data-tab]"));
      if (!tabs.length) return;

      function activate(selected, moveFocus) {
        const key = selected.dataset.tab;
        tabs.forEach(function (tab) {
          const active = tab === selected;
          tab.setAttribute("aria-selected", active ? "true" : "false");
          tab.tabIndex = active ? 0 : -1;
          tab.classList.toggle("is-active", active);
        });

        const scope = tabList.closest("[data-tabs-scope]") || tabList.parentElement;
        if (scope) {
          scope.querySelectorAll("[data-tab-panel]").forEach(function (panel) {
            const active = panel.dataset.tabPanel === key;
            panel.hidden = !active;
            panel.setAttribute("aria-hidden", active ? "false" : "true");
          });
        }
        if (moveFocus) selected.focus();
      }

      tabs.forEach(function (tab, index) {
        tab.addEventListener("click", function () { activate(tab, false); });
        tab.addEventListener("keydown", function (event) {
          let next = index;
          if (event.key === "ArrowRight") next = (index + 1) % tabs.length;
          else if (event.key === "ArrowLeft") next = (index - 1 + tabs.length) % tabs.length;
          else if (event.key === "Home") next = 0;
          else if (event.key === "End") next = tabs.length - 1;
          else return;
          event.preventDefault();
          activate(tabs[next], true);
        });
      });

      const initial = tabs.find(function (tab) { return tab.getAttribute("aria-selected") === "true"; }) || tabs[0];
      activate(initial, false);
    });
  }

  /* Dialogs close by explicit controls, backdrop click, or Escape. */
  let lastDialogTrigger = null;

  function openModal(modal) {
    if (!modal) return;
    lastDialogTrigger = document.activeElement;
    modal.hidden = false;
    document.body.dataset.modalOpen = "true";
    const focusTarget = modal.querySelector("[autofocus], [data-modal-autofocus], button, [href], input, select, textarea, [tabindex]:not([tabindex='-1'])");
    if (focusTarget) focusTarget.focus();
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.hidden = true;
    delete document.body.dataset.modalOpen;
    if (lastDialogTrigger && typeof lastDialogTrigger.focus === "function") lastDialogTrigger.focus();
  }

  function initializeModals() {
    document.querySelectorAll("[data-modal-open]").forEach(function (trigger) {
      trigger.addEventListener("click", function () {
        openModal(byId(trigger.dataset.modalOpen));
      });
    });

    document.querySelectorAll("[data-modal-close]").forEach(function (trigger) {
      trigger.addEventListener("click", function () {
        closeModal(trigger.closest(".modal-overlay"));
      });
    });

    document.querySelectorAll(".modal-overlay").forEach(function (overlay) {
      overlay.addEventListener("click", function (event) {
        if (event.target === overlay && overlay.dataset.dismissible !== "false") closeModal(overlay);
      });
      overlay.addEventListener("keydown", function (event) {
        if (event.key !== "Tab") return;
        const focusable = Array.from(overlay.querySelectorAll(
          "a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex='-1'])"
        )).filter(function (item) { return !item.hidden; });
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") return;
      const open = document.querySelector(".modal-overlay:not([hidden])");
      if (open) closeModal(open);
    });
  }

  /* Mobile sidebar uses the same control on every page and supports Escape. */
  function initializeSidebar() {
    const sidebar = byId("app-sidebar");
    const backdrop = byId("sidebar-backdrop");
    if (!sidebar) return;

    function setOpen(open) {
      sidebar.classList.toggle("is-open", open);
      sidebar.setAttribute("aria-hidden", open ? "false" : "true");
      document.querySelectorAll("[data-sidebar-toggle]").forEach(function (trigger) {
        setExpanded(trigger, open);
      });
      if (backdrop) backdrop.hidden = !open;
      document.body.classList.toggle("sidebar-open", open);
      if (open) {
        const firstLink = sidebar.querySelector("a, button");
        if (firstLink) firstLink.focus();
      }
    }

    document.querySelectorAll("[data-sidebar-toggle]").forEach(function (trigger) {
      trigger.addEventListener("click", function () {
        setOpen(!sidebar.classList.contains("is-open"));
      });
    });
    document.querySelectorAll("[data-sidebar-close]").forEach(function (trigger) {
      trigger.addEventListener("click", function () { setOpen(false); });
    });
    if (backdrop) backdrop.addEventListener("click", function () { setOpen(false); });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && sidebar.classList.contains("is-open")) setOpen(false);
    });
  }

  /* Theme is opt-in: light remains the default and no network font is requested. */
  function initializeTheme() {
    let saved = null;
    try { saved = window.localStorage.getItem("tch-theme"); } catch (_) { /* Storage can be disabled. */ }
    if (saved === "dark" || saved === "light") root.dataset.theme = saved;

    document.querySelectorAll("[data-theme-toggle]").forEach(function (trigger) {
      trigger.addEventListener("click", function () {
        const next = root.dataset.theme === "dark" ? "light" : "dark";
        root.dataset.theme = next;
        try { window.localStorage.setItem("tch-theme", next); } catch (_) { /* Theme still works for this page. */ }
        trigger.setAttribute("aria-pressed", next === "dark" ? "true" : "false");
        trigger.setAttribute("aria-label", next === "dark" ? "Switch to light theme" : "Switch to dark theme");
      });
    });
  }

  /* Toast content is assigned with textContent to prevent HTML injection. */
  function notify(message, options) {
    const settings = options || {};
    const region = byId("toast-region") || createToastRegion();
    const toast = document.createElement("div");
    toast.className = "toast";
    toast.setAttribute("role", settings.role || "status");
    toast.setAttribute("aria-live", settings.role === "alert" ? "assertive" : "polite");

    const text = document.createElement("div");
    text.className = "toast__message";
    text.textContent = String(message == null ? "" : message);
    toast.appendChild(text);

    const close = document.createElement("button");
    close.type = "button";
    close.className = "toast__close";
    close.setAttribute("aria-label", "Dismiss notification");
    close.textContent = "×";
    close.addEventListener("click", function () { toast.remove(); });
    toast.appendChild(close);
    region.appendChild(toast);

    const duration = Number.isFinite(settings.duration) ? Math.max(0, settings.duration) : 4500;
    if (duration > 0) window.setTimeout(function () { toast.remove(); }, duration);
    return toast;
  }

  function createToastRegion() {
    const region = document.createElement("div");
    region.id = "toast-region";
    region.className = "toast-region";
    region.setAttribute("aria-label", "Notifications");
    document.body.appendChild(region);
    return region;
  }

  /* Enhance static progress values while keeping the value readable to assistive tech. */
  function initializeProgress() {
    document.querySelectorAll("[data-progress]").forEach(function (bar) {
      const raw = Number(bar.dataset.progress);
      const value = Number.isFinite(raw) ? Math.min(100, Math.max(0, raw)) : 0;
      bar.style.setProperty("--progress", value + "%");
      bar.setAttribute("role", "progressbar");
      bar.setAttribute("aria-valuemin", "0");
      bar.setAttribute("aria-valuemax", "100");
      bar.setAttribute("aria-valuenow", String(value));
    });
  }

  /* Start only after the document is ready, whether loaded early or late. */
  function initialize() {
    initializeDropdowns();
    initializeTabs();
    initializeModals();
    initializeSidebar();
    initializeTheme();
    initializeProgress();
  }

  window.TCHUI = Object.freeze({
    notify: notify,
    openModal: function (id) { openModal(byId(id)); },
    closeModal: function (id) { closeModal(byId(id)); },
    refresh: initialize
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initialize, { once: true });
  } else {
    initialize();
  }
})();
