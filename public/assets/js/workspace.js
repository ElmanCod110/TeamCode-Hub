/* ==========================================================================
   TeamCode Hub Workspace Controller
   File: public/assets/js/workspace.js
   Purpose: Connect the English workspace UI to authenticated JSON endpoints.
   Security: Render user-controlled values with textContent and DOM APIs only.
   ========================================================================== */
(function () {
  "use strict";

  if (window.TCHWorkspace) return;

  const $ = (selector, scope = document) => scope.querySelector(selector);
  const $$ = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));
  const state = {
    csrf: "",
    verificationToken: "",
    passwordResetToken: "",
    user: null,
    dashboard: null,
    teams: [],
    projects: [],
    tasks: [],
    notifications: [],
    unreadNotificationCount: null,
    notificationTimer: null,
    notificationBusy: false,
    github: null,
    githubError: null,
    page: "overview",
    selectedProject: null,
    projectTab: "overview",
    chatCursor: 0,
    chatPollCount: 0,
    chatTimer: null,
    chatBusy: false,
    refreshing: false,
    search: "",
    taskFilter: "all"
  };

  const iconPaths = {
    grid: '<rect x="3" y="3" width="7" height="7" rx="1.4"/><rect x="14" y="3" width="7" height="7" rx="1.4"/><rect x="3" y="14" width="7" height="7" rx="1.4"/><rect x="14" y="14" width="7" height="7" rx="1.4"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    folder: '<path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H10l2 2h6.5A2.5 2.5 0 0 1 21 9.5v8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z"/>',
    'check-square': '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m7.5 12 3 3 6-6"/>',
    bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
    message: '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.7-1.4-2.4 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.7 1.4 2.4-1.4 1.1a7 7 0 0 1 0 2z"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    refresh: '<path d="M20 7v5h-5"/><path d="M4 17v-5h5"/><path d="M5.5 9a7 7 0 0 1 11.7-2L20 12M4 12l2.8 5a7 7 0 0 0 11.7-2"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/>',
    moon: '<path d="M20.9 13A8.5 8.5 0 0 1 11 3.1 8.5 8.5 0 1 0 20.9 13Z"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    arrow: '<path d="M5 12h14m-6-6 6 6-6 6"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    spark: '<path d="m12 3 1.9 5.8L20 11l-6.1 2.2L12 19l-2-5.8L4 11l6-2.2z"/>',
    chart: '<path d="M3 3v18h18"/><path d="m7 14 4-4 4 3 5-7"/>',
    close: '<path d="m18 6-12 12M6 6l12 12"/>',
    external: '<path d="M14 3h7v7M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    shield: '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m9 12 2 2 4-4"/>',
    dots: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>'
  };

  /* Build icons from trusted path constants; no user-supplied markup is parsed. */
  function icon(name, className) {
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("fill", "none");
    svg.setAttribute("stroke", "currentColor");
    svg.setAttribute("stroke-width", "1.7");
    svg.setAttribute("stroke-linecap", "round");
    svg.setAttribute("stroke-linejoin", "round");
    svg.setAttribute("aria-hidden", "true");
    if (className) svg.setAttribute("class", className);
    const holder = document.createElementNS("http://www.w3.org/2000/svg", "g");
    // The path strings above are application constants, never API response values.
    holder.innerHTML = iconPaths[name] || iconPaths.spark;
    svg.appendChild(holder);
    return svg;
  }

  /* Create text nodes rather than interpolating user data into HTML. */
  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }
  function append(parent, ...children) {
    children.flat().forEach(child => { if (child) parent.appendChild(child); });
    return parent;
  }
  function actionButton(label, action, className = "btn btn-outline btn-sm", attrs = {}) {
    const button = el("button", className, label);
    button.type = "button";
    button.dataset.action = action;
    Object.entries(attrs).forEach(([key, value]) => button.setAttribute(key, String(value)));
    return button;
  }
  function emptyState(title, description, actionLabel, actionName) {
    const box = el("div", "empty-state");
    append(box, append(el("div", "empty-state-icon"), icon("spark")),
      el("h3", "", title), el("p", "", description));
    if (actionLabel && actionName) box.appendChild(actionButton(actionLabel, actionName, "btn btn-primary btn-sm"));
    return box;
  }
  function badge(status) {
    const safe = String(status || "unknown").toLowerCase().replace(/[^a-z0-9_-]/g, "");
    return el("span", "badge-status status-" + safe, String(status || "Unknown").replace(/_/g, " "));
  }
  function priorityLabel(priority) {
    const safe = String(priority || "normal").toLowerCase().replace(/[^a-z0-9_-]/g, "");
    return el("span", "priority-label priority-" + safe, String(priority || "normal"));
  }
  function avatar(name, extraClass) {
    const parts = String(name || "User").trim().split(/\s+/).filter(Boolean).slice(0, 2);
    return el("span", "avatar " + (extraClass || ""), parts.map(part => part.charAt(0).toUpperCase()).join("") || "U");
  }
  function formatDate(value, options) {
    if (!value) return "No date set";
    const date = new Date(String(value).replace(" ", "T") + (String(value).includes("Z") ? "" : "Z"));
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat(undefined, options || { month: "short", day: "numeric", year: "numeric" }).format(date);
  }
  function formatRelative(value) {
    if (!value) return "Recently";
    const date = new Date(String(value).replace(" ", "T") + (String(value).includes("Z") ? "" : "Z"));
    if (Number.isNaN(date.getTime())) return String(value);
    const delta = Math.round((date.getTime() - Date.now()) / 60000);
    if (Math.abs(delta) < 1) return "Just now";
    if (Math.abs(delta) < 60) return delta < 0 ? Math.abs(delta) + "m ago" : "In " + delta + "m";
    const hours = Math.round(delta / 60);
    if (Math.abs(hours) < 24) return hours < 0 ? Math.abs(hours) + "h ago" : "In " + hours + "h";
    const days = Math.round(hours / 24);
    return days < 0 ? Math.abs(days) + "d ago" : "In " + days + "d";
  }
  function dateForApi(localValue) {
    if (!localValue) return null;
    const date = new Date(localValue);
    if (Number.isNaN(date.getTime())) return null;
    return date.toISOString().slice(0, 19).replace("T", " ");
  }
  function escapePath(value) { return encodeURIComponent(String(value)); }
  function projectById(id) { return state.projects.find(project => Number(project.id) === Number(id)); }
  function teamById(id) { return state.teams.find(team => Number(team.id) === Number(id)); }
  function canManageTeam(id) { return ["owner", "manager"].includes(String(teamById(id)?.role || "")); }

  /* Keep the CSRF token in memory and send it only with same-origin mutations. */
  async function loadCsrf() {
    const result = await request("/api/auth/csrf", { method: "GET", noCsrf: true });
    state.csrf = result.csrf_token || "";
    if (!state.csrf) throw new Error("The security token could not be initialized. Reload and try again.");
  }

  async function request(url, options = {}) {
    const method = String(options.method || "GET").toUpperCase();
    const headers = new Headers(options.headers || {});
    headers.set("Accept", "application/json");
    if (options.body !== undefined && !(options.body instanceof FormData)) headers.set("Content-Type", "application/json");
    if (!["GET", "HEAD", "OPTIONS"].includes(method) && !options.noCsrf && state.csrf) headers.set("X-Csrf-Token", state.csrf);
    const response = await fetch(url, {
      method,
      credentials: "same-origin",
      cache: "no-store",
      headers,
      body: options.body === undefined ? undefined : options.body instanceof FormData ? options.body : JSON.stringify(options.body),
      signal: options.signal
    });
    const type = response.headers.get("content-type") || "";
    let data = {};
    if (type.includes("application/json")) {
      try { data = await response.json(); } catch (_) { data = {}; }
    } else {
      const text = await response.text();
      if (text) data = { error: text.slice(0, 240) };
    }
    if (response.status === 401 && !options.allowUnauthenticated) {
      showAuth();
      throw new Error("Your session has expired. Please sign in again.");
    }
    if (!response.ok) {
      const error = new Error(typeof data.error === "string" ? data.error : "Request failed (" + response.status + ").");
      error.status = response.status;
      error.data = data;
      throw error;
    }
    return data;
  }

  function toast(message, kind = "success") {
    const region = $("#toast-region");
    const item = el("div", "tch-toast");
    item.dataset.kind = kind;
    const copy = el("div", "toast-copy", message);
    const close = el("button", "", "×");
    close.type = "button";
    close.setAttribute("aria-label", "Dismiss notification");
    close.addEventListener("click", () => item.remove());
    append(item, copy, close);
    region.appendChild(item);
    window.setTimeout(() => item.remove(), 5200);
  }

  function showGlobalMessage(message, kind = "info") {
    const box = $("#global-message");
    box.textContent = message;
    box.dataset.kind = kind;
    box.hidden = false;
    window.setTimeout(() => { if (box.textContent === message) box.hidden = true; }, 6500);
  }

  function setConnection(connected) {
    $("#connection-label").textContent = connected ? "Connected" : "Connection issue";
    $(".connection-indicator").classList.toggle("is-disconnected", !connected);
  }

  function showAuth() {
    stopChatPolling();
    stopNotificationPolling();
    $("#application").hidden = true;
    $("#auth-screen").hidden = false;
    $("#login-password").value = "";
    $("#login-error").hidden = true;
    showAuthMode("login");
  }

  /** Display one authentication flow at a time and keep keyboard focus predictable. */
  function showAuthMode(mode) {
    const views = {
      login: $("#login-form"),
      register: $("#register-form"),
      resend: $("#verification-resend-form"),
      verify: $("#verify-screen"),
      "password-reset-request": $("#password-reset-request-form"),
      "password-reset": $("#password-reset-form")
    };
    Object.entries(views).forEach(([name, view]) => { view.hidden = name !== mode; });
    const focusTargets = {
      login: $("#login-email"),
      register: $("#register-name"),
      resend: $("#resend-email"),
      verify: $("#verify-submit"),
      "password-reset-request": $("#password-reset-email"),
      "password-reset": $("#password-reset-new-password")
    };
    if (focusTargets[mode] && !focusTargets[mode].disabled) focusTargets[mode].focus();
  }

  function showApplication() {
    $("#auth-screen").hidden = true;
    $("#application").hidden = false;
    startNotificationPolling();
    $("#sidebar-user-name").textContent = state.user?.display_name || state.user?.email || "Your account";
    $("#sidebar-user-role").textContent = (state.user?.roles || []).includes("site_admin") ? "Site administrator" : "Workspace member";
    $("#user-avatar").textContent = initials(state.user?.display_name || state.user?.email || "User");
  }

  function initials(value) {
    return String(value || "U").trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0].toUpperCase()).join("") || "U";
  }

  async function boot() {
    // Populate stable SVG icons in the document shell.
    $("[data-icon]").forEach(node => node.appendChild(icon(node.dataset.icon)));

    // Email tokens live in the fragment, which browsers do not send to the web server.
    const hash = location.hash.replace(/^#/, "");
    if (hash.startsWith("reset-password?")) {
      state.passwordResetToken = new URLSearchParams(hash.slice("reset-password?".length)).get("token") || "";
      // Retain the link until submission so a temporary CSRF/network failure can be retried after reload.
      showAuth();
      showAuthMode("password-reset");
      if (!state.passwordResetToken) {
        $("#password-reset-status").textContent = "This reset link is missing its token. Request a new link and try again.";
        $("#password-reset-status").className = "alert alert-danger auth-status";
        $("#password-reset-status").hidden = false;
        $("#password-reset-submit").hidden = true;
      }
      try {
        await loadCsrf();
      } catch (_) {
        $("#password-reset-error").textContent = "The password reset service is unavailable. Check the connection and try again.";
        $("#password-reset-error").hidden = false;
        $("#password-reset-submit").disabled = true;
      }
      return;
    }

    if (hash.startsWith("verify-email?")) {
      state.verificationToken = new URLSearchParams(hash.slice("verify-email?".length)).get("token") || "";
      $("#verify-submit").disabled = true;
      showAuth();
      showAuthMode("verify");
      if (!state.verificationToken) {
        $("#verify-status").textContent = "This verification link is missing its token. Request a new link and try again.";
      }
      try {
        await loadCsrf();
        $("#verify-submit").disabled = !state.verificationToken;
      } catch (_) {
        $("#verify-status").textContent = "The verification service is unavailable. Check the database setup and reload.";
        $("#verify-submit").disabled = true;
      }
      return;
    }

    try {
      const me = await request("/api/auth/me", { method: "GET", allowUnauthenticated: true, noCsrf: true });
      if (!me.authenticated) {
        showAuth();
        await loadCsrf();
        return;
      }
      state.user = me.user;
      showApplication();
      await loadCsrf();
      await refreshWorkspace(false);
      routeFromHash();
    } catch (error) {
      if (error.status === 401) {
        showAuth();
        try { await loadCsrf(); } catch (_) { showLoginError("The service is not ready yet. Check the database setup and reload."); }
        return;
      }
      showAuth();
      showLoginError(error.message || "The workspace could not be initialized.");
    }
  }

  async function login(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = $("#login-submit");
    const errorBox = $("#login-error");
    errorBox.hidden = true;
    const email = $("#login-email").value.trim();
    const password = $("#login-password").value;
    if (!email || !password) {
      showLoginError("Enter your email address and password.");
      return;
    }
    submit.disabled = true;
    const original = submit.innerHTML;
    submit.textContent = "Signing in…";
    try {
      if (!state.csrf) await loadCsrf();
      await request("/api/auth/login", { method: "POST", body: { email, password } });
      // Login rotates the server-side CSRF token; fetch the new value before any write.
      await loadCsrf();
      const me = await request("/api/auth/me", { method: "GET", allowUnauthenticated: true, noCsrf: true });
      state.user = me.user;
      showApplication();
      await refreshWorkspace(false);
      navigate("overview", false);
      toast("Welcome back. Your workspace is ready.");
      form.reset();
    } catch (error) {
      showLoginError(error.message || "Sign-in failed. Please try again.");
    } finally {
      submit.disabled = false;
      submit.innerHTML = original;
    }
  }

  async function registerAccount(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = $("#register-submit");
    const error = $("#register-error");
    const status = $("#register-status");
    error.hidden = true;
    status.hidden = true;
    if (!form.reportValidity()) return;
    const email = $("#register-email").value.trim();
    const displayName = $("#register-name").value.trim();
    const password = $("#register-password").value;
    const confirmation = $("#register-confirm-password").value;
    if (password !== confirmation) {
      error.textContent = "Password confirmation does not match.";
      error.hidden = false;
      return;
    }
    submit.disabled = true;
    submit.textContent = "Creating account…";
    try {
      if (!state.csrf) await loadCsrf();
      const result = await request("/api/auth/register", {
        method: "POST",
        body: { email, display_name: displayName, password, confirm_password: confirmation }
      });
      status.textContent = result.message || "If the request can be processed, a verification email will be sent.";
      status.hidden = false;
      $("#resend-email").value = email;
      form.reset();
    } catch (failure) {
      error.textContent = failure.message || "Registration could not be processed. Try again later.";
      error.hidden = false;
    } finally {
      submit.disabled = false;
      submit.textContent = "Create account";
    }
  }

  async function resendVerification(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = $("#resend-submit");
    const error = $("#resend-error");
    const status = $("#resend-status");
    error.hidden = true;
    status.hidden = true;
    if (!form.reportValidity()) return;
    submit.disabled = true;
    submit.textContent = "Sending…";
    try {
      if (!state.csrf) await loadCsrf();
      const result = await request("/api/auth/verification/resend", {
        method: "POST",
        body: { email: $("#resend-email").value.trim() }
      });
      status.textContent = result.message || "If the request can be processed, a verification email will be sent.";
      status.hidden = false;
    } catch (failure) {
      error.textContent = failure.message || "The request could not be processed. Try again later.";
      error.hidden = false;
    } finally {
      submit.disabled = false;
      submit.textContent = "Send verification link";
    }
  }

  /** Request a reset link without revealing whether an account exists. */
  async function requestPasswordReset(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = $("#password-reset-request-submit");
    const error = $("#password-reset-request-error");
    const status = $("#password-reset-request-status");
    error.hidden = true;
    status.hidden = true;
    if (!form.reportValidity()) return;

    submit.disabled = true;
    submit.textContent = "Sending…";
    try {
      if (!state.csrf) await loadCsrf();
      const result = await request("/api/auth/password-reset/request", {
        method: "POST",
        body: { email: $("#password-reset-email").value.trim() }
      });
      status.textContent = result.message || "If an active verified account matches that address, password reset instructions will be sent.";
      status.hidden = false;
    } catch (failure) {
      error.textContent = failure.message || "The request could not be processed. Try again later.";
      error.hidden = false;
    } finally {
      submit.disabled = false;
      submit.textContent = "Send reset link";
    }
  }

  /** Consume a reset link only after the user submits a matching new password. */
  async function completePasswordReset(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = $("#password-reset-submit");
    const error = $("#password-reset-error");
    const status = $("#password-reset-status");
    error.hidden = true;
    status.hidden = true;
    if (!form.reportValidity()) return;

    if (!state.passwordResetToken) {
      status.textContent = "This reset link is missing its token. Request a new password reset link.";
      status.className = "alert alert-danger auth-status";
      status.hidden = false;
      submit.hidden = true;
      return;
    }
    const password = $("#password-reset-new-password").value;
    const confirmation = $("#password-reset-confirm-password").value;
    if (password !== confirmation) {
      error.textContent = "Password confirmation does not match.";
      error.hidden = false;
      return;
    }

    submit.disabled = true;
    submit.textContent = "Updating password…";
    try {
      // Remove the bearer token from browser history before sending it to the API.
      history.replaceState({}, "", "/reset-password");
      if (!state.csrf) await loadCsrf();
      const result = await request("/api/auth/password-reset/complete", {
        method: "POST",
        body: { token: state.passwordResetToken, password, confirm_password: confirmation }
      });
      state.passwordResetToken = "";
      form.reset();
      status.className = "alert alert-success auth-status";
      status.textContent = result.message || "Your password has been updated. You can now sign in.";
      status.hidden = false;
      submit.hidden = true;
    } catch (failure) {
      if (failure.status === 419) {
        try { await loadCsrf(); } catch (_) {}
        error.textContent = "Your security token expired. Please submit again.";
        error.hidden = false;
      } else if (failure.status === 400) {
        state.passwordResetToken = "";
        error.textContent = failure.message || "This reset link is invalid, expired, or already used. Request a new link.";
        error.hidden = false;
        submit.hidden = true;
      } else {
        error.textContent = failure.message || "The password could not be updated. Check your connection and try again.";
        error.hidden = false;
      }
    } finally {
      submit.disabled = false;
      submit.textContent = "Update password";
    }
  }

  async function verifyEmail() {
    const token = state.verificationToken;
    const submit = $("#verify-submit");
    const status = $("#verify-status");
    if (!token || !state.csrf) {
      status.textContent = "This verification link is invalid or expired. Request a new link.";
      status.className = "alert alert-danger auth-status";
      submit.disabled = true;
      return;
    }

    // Remove the bearer token from browser history before sending the verification request.
    history.replaceState({}, "", "/#overview");
    submit.disabled = true;
    submit.textContent = "Verifying…";
    status.textContent = "Verifying your email address…";
    try {
      const result = await request("/api/auth/verify-email", { method: "POST", body: { token } });
      state.verificationToken = "";
      status.className = "alert alert-success auth-status";
      status.textContent = result.message || "Email verified. You can now sign in.";
      submit.hidden = true;
    } catch (failure) {
      status.className = "alert alert-danger auth-status";
      if (failure.status === 400) {
        state.verificationToken = "";
        status.textContent = failure.message || "This verification link is invalid, expired, or already used.";
        submit.hidden = true;
      } else {
        if (failure.status === 419) {
          try { await loadCsrf(); } catch (_) {}
        }
        status.textContent = "The verification request could not be completed. Check your connection and try again.";
        // Keep the token in memory so a transient network failure does not strand the user.
        submit.hidden = false;
      }
    } finally {
      submit.disabled = false;
      submit.textContent = "Verify email";
    }
  }

  function showLoginError(message) {
    const box = $("#login-error");
    box.textContent = message;
    box.hidden = false;
  }

  async function logout() {
    try {
      await request("/api/auth/logout", { method: "POST", body: {} });
    } catch (error) {
      if (error.status !== 401) toast(error.message, "error");
    }
    state.user = null;
    state.dashboard = null;
    state.teams = [];
    state.projects = [];
    state.tasks = [];
    state.notifications = [];
    state.csrf = "";
    showAuth();
    try { await loadCsrf(); } catch (_) {}
    toast("You have signed out.");
  }

  /* Refresh dashboard data from authenticated APIs without a full page reload. */
  async function refreshWorkspace(showNotice = true) {
    if (state.refreshing) return;
    state.refreshing = true;
    setConnection(true);
    try {
      const [dashboard, teams] = await Promise.all([
        request("/api/dashboard"),
        request("/api/teams")
      ]);
      state.dashboard = dashboard.dashboard || {};
      state.teams = Array.isArray(teams.teams) ? teams.teams : [];
      state.projects = Array.isArray(state.dashboard.projects) ? state.dashboard.projects : [];
      state.tasks = await loadAllTasks();
      const notifications = await request("/api/notifications?limit=20");
      state.notifications = Array.isArray(notifications.notifications) ? notifications.notifications : [];
      state.unreadNotificationCount = Number.isFinite(Number(notifications.unread_count)) ? Number(notifications.unread_count) : state.notifications.filter(item => !item.is_read).length;
      updateNavigationCounts();
      if (showNotice) toast("Workspace data refreshed.");
      if (state.page === "overview") renderOverview();
      else if (state.page === "teams") renderTeams();
      else if (state.page === "projects") renderProjects();
      else if (state.page === "tasks") renderTasks();
      else if (state.page === "notifications") renderNotifications();
      else if (state.page === "github") renderGitHubActivity();
      else if (state.page === "chat" && state.selectedProject) renderProject(state.selectedProject.id, "chat");
      setConnection(true);
      // Start the external integration only after core workspace data has rendered.
      void loadGitHubActivity(false);
    } catch (error) {
      setConnection(false);
      if (error.status !== 401) {
        showGlobalMessage(error.message || "Some workspace data could not be refreshed.", "error");
        if (!state.dashboard) renderFatal(error.message);
      }
    } finally {
      state.refreshing = false;
    }
  }

  async function loadGitHubActivity(notify = false) {
    try {
      const result = await request("/api/github/activity");
      state.github = result.activity || null;
      state.githubError = null;
    } catch (error) {
      if (error.status === 401) return;
      state.github = null;
      state.githubError = error.message || "GitHub activity is currently unavailable.";
    }

    // Refresh only the relevant integration surface; do not rebuild the whole dashboard.
    if (state.page === "github") {
      renderGitHubActivity();
    } else if (state.page === "overview") {
      const preview = $("#github-overview-panel");
      if (preview) preview.replaceWith(renderGitHubPreview());
    }
    if (notify) toast(state.github?.stale ? "Showing the last successful GitHub snapshot." : "GitHub cache status checked.");
  }

  async function loadAllTasks() {
    const responses = await Promise.all(state.projects.map(async project => {
      try {
        const data = await request("/api/projects/" + escapePath(project.id) + "/tasks");
        return (data.tasks || []).map(task => ({ ...task, project_name: task.project_name || project.name, team_id: project.team_id }));
      } catch (_) {
        return [];
      }
    }));
    return responses.flat();
  }

  function updateNavigationCounts() {
    $("#sidebar-team-count").textContent = state.teams.length + (state.teams.length === 1 ? " team" : " teams");
    $("#nav-team-count").textContent = String(state.teams.length);
    $("#nav-project-count").textContent = String(state.projects.length);
    const openTasks = state.tasks.filter(task => task.status !== "done").length;
    $("#nav-task-count").textContent = String(openTasks);
    const unread = Number.isFinite(state.unreadNotificationCount)
      ? state.unreadNotificationCount
      : state.notifications.filter(item => !item.is_read).length;
    $("#nav-notification-count").textContent = String(unread);
    $("#nav-notification-count").hidden = unread === 0;
    $("#topbar-notification-dot").hidden = unread === 0;
    const canReview = (state.user?.roles || []).includes("site_admin") || state.teams.some(team => canManageTeam(team.id));
    $("#nav-moderation-item").hidden = !canReview;
    $("#nav-admin-users-item").hidden = !(state.user?.roles || []).includes("site_admin");
    $("#nav-report-count").textContent = "Open";
  }

  function routeFromHash() {
    const hash = location.hash.replace(/^#/, "");
    if (hash.startsWith("team/")) {\n      const teamId = Number(hash.split("/")[1]);\n      if (teamById(teamId)) openTeam(teamId);\n      else navigate("teams", false);\n      return;\n    }\n    if (hash.startsWith("project/")) {
      const id = Number(hash.split("/")[1]);
      const project = projectById(id);
      if (project) openProject(project, false);
      else navigate("projects", false);
      return;
    }
    const allowed = ["overview", "teams", "projects", "tasks", "notifications", "chat", "github", "moderation", "admin-users", "settings"];
    navigate(allowed.includes(hash) ? hash : "overview", false);
  }

  function navigate(page, updateHash = true) {
    if (page === "admin-users" && !(state.user?.roles || []).includes("site_admin")) {
      toast("Site administrator role required.", "warning");
      page = "overview";
      updateHash = false;
    }
    state.page = page;
    state.search = "";
    stopChatPolling();
    if (updateHash) history.pushState({ page }, "", page === "overview" ? "#overview" : "#" + page);
    const titles = { overview: "Overview", teams: "Teams", projects: "Projects", tasks: "My tasks", notifications: "Notifications", chat: "Project chat", github: "GitHub activity", moderation: "Moderation", "admin-users": "User directory", settings: "Preferences" };
    $("#current-page-label").textContent = titles[page] || "Workspace";
    $$("[data-page]").forEach(node => node.classList.toggle("is-active", node.dataset.page === page));
    closeSidebar();
    if (page === "overview") renderOverview();
    else if (page === "teams") renderTeams();
    else if (page === "projects") renderProjects();
    else if (page === "tasks") renderTasks();
    else if (page === "notifications") renderNotifications();
    else if (page === "chat") renderChatLanding();
    else if (page === "github") renderGitHubActivity();
    else if (page === "moderation") renderModeration();
    else if (page === "admin-users") renderAdminUsers();
    else if (page === "settings") renderSettings();
    $("#workspace-main").focus({ preventScroll: true });
  }

  function pageRoot() {
    const root = el("div", "view-enter");
    $("#page-content").replaceChildren(root);
    $("#page-content").setAttribute("aria-busy", "false");
    return root;
  }

  function pageHeading(eyebrow, title, description, actions) {
    const header = el("header", "page-heading");
    const copy = el("div", "page-heading-copy");
    append(copy, el("div", "eyebrow", eyebrow), el("h1", "", title), el("p", "", description));
    append(header, copy);
    if (actions) header.appendChild(actions);
    return header;
  }

  function actionGroup(...items) {
    const group = el("div", "page-heading-actions");
    items.forEach(item => item && group.appendChild(item));
    return group;
  }

  function metricCard(label, value, detail, iconName) {
    const card = el("article", "metric-card");
    const top = el("div", "metric-top");
    append(top, el("span", "", label), append(el("span", "metric-icon"), icon(iconName)));
    append(card, top, el("div", "metric-value", value), el("div", "metric-bottom", detail));
    return card;
  }

  function panel(title, subtitle, content, action) {
    const box = el("section", "surface-panel");
    const head = el("div", "panel-heading");
    const copy = el("div");
    append(copy, el("h2", "", title));
    if (subtitle) copy.appendChild(el("p", "", subtitle));
    append(head, copy, action);
    append(box, head, content);
    return box;
  }

  function renderOverview() {
    const root = pageRoot();
    const dash = state.dashboard || {};
    const counts = dash.counts || {};
    const hour = new Date().getHours();
    const greeting = hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";
    const hero = el("section", "overview-hero");
    const copy = el("div", "overview-hero-copy");
    append(copy, el("div", "eyebrow", greeting + " · YOUR WORKSPACE"),
      el("h1", "", "Make room for great work."),
      el("p", "", "A clear view of your teams, projects, and the next things that matter."));
    const status = el("span", "hero-status");
    append(status, el("i"), "All systems ready");
    const actions = actionGroup(actionButton("Create a team", "create-team", "btn btn-outline"),
      actionButton("New project", "create-project", "btn btn-primary"));
    append(hero, copy, append(el("div", "overview-hero-actions"), actions.children ? Array.from(actions.children) : []));
    // Move action buttons into the hero action group without cloning handlers.
    const heroActions = $(".overview-hero-actions", hero);
    heroActions.replaceChildren(status, actionButton("Create a team", "create-team", "btn btn-outline"), actionButton("New project", "create-project", "btn btn-primary"));
    root.appendChild(hero);

    const metrics = el("div", "metric-grid");
    append(metrics,
      metricCard("Active teams", counts.teams ?? state.teams.length, "Your collaborative spaces", "users"),
      metricCard("Projects", counts.projects ?? state.projects.length, "Projects you can access", "folder"),
      metricCard("Due today", (dash.tasks_due_today || []).length, "Tasks that need attention", "calendar"),
      metricCard("Overdue tasks", (dash.overdue_tasks || []).length, "Open items past their due date", "clock"));
    root.appendChild(metrics);

    const columns = el("div", "overview-columns");
    const projectList = el("div", "project-mini-list");
    if (state.projects.length) {
      state.projects.slice(0, 5).forEach(project => {
        const row = el("button", "project-mini-row");
        row.type = "button";
        row.dataset.openProject = project.id;
        const mark = el("span", "project-mark", initials(project.name).slice(0, 2));
        const main = el("span", "row-main");
        append(main, el("span", "row-title", project.name), el("span", "row-subtitle", teamById(project.team_id)?.name || "Team project"));
        const trailing = el("span", "row-trailing");
        append(trailing, badge(project.status || "active"), el("small", "", "Updated " + formatRelative(project.updated_at)));
        append(row, mark, main, trailing);
        projectList.appendChild(row);
      });
    } else {
      projectList.appendChild(emptyState("Your first project starts here", "Create a team and add a project to give your work a home.", "Create a team", "create-team"));
    }
    const projectPanel = panel("Your projects", "Pick up where your team left off", projectList, actionButton("View all", "navigate-projects", "btn btn-ghost btn-sm"));

    const taskList = el("div", "task-list");
    const due = [...(dash.overdue_tasks || []), ...(dash.tasks_due_today || [])].slice(0, 6);
    if (due.length) {
      due.forEach(task => taskList.appendChild(taskRow(task, true)));
    } else {
      taskList.appendChild(emptyState("Nothing urgent on your plate", "No overdue or due-today tasks were found for your account.", "View all tasks", "navigate-tasks"));
    }
    const taskPanel = panel("Needs your attention", "Due today and overdue", taskList, actionButton("All tasks", "navigate-tasks", "btn btn-ghost btn-sm"));
    append(columns, projectPanel, taskPanel);
    root.appendChild(columns);

    const lower = el("div", "overview-columns");
    const teamRows = el("div", "team-list");
    if (state.teams.length) {
      state.teams.slice(0, 4).forEach(team => {
        const row = el("button", "team-row");
        row.type = "button";
        row.dataset.openTeam = team.id;
        append(row, append(el("span", "project-mark"), icon("users")),
          append(el("span", "row-main"), el("span", "row-title", team.name), el("span", "row-subtitle", team.role || "Member")),
          el("span", "row-trailing", "↗"));
        teamRows.appendChild(row);
      });
    } else teamRows.appendChild(emptyState("No teams yet", "Create a team to invite collaborators and start a project.", "Create team", "create-team"));
    const teamPanel = panel("Your teams", "Spaces you are part of", teamRows, actionButton("Manage teams", "navigate-teams", "btn btn-ghost btn-sm"));

    const activityRows = el("div", "activity-list");
    const recent = (dash.recent_notifications || []).slice(0, 4);
    if (recent.length) recent.forEach(item => activityRows.appendChild(notificationRow(item, false)));
    else activityRows.appendChild(emptyState("A quiet inbox", "Team invitations, task assignments, and project updates will appear here."));
    const activityPanel = panel("Recent updates", "A quick pulse on your workspace", activityRows, actionButton("Notifications", "navigate-notifications", "btn btn-ghost btn-sm"));
    append(lower, teamPanel, activityPanel);
    root.appendChild(lower);
    root.appendChild(renderGitHubPreview());
  }

  function taskRow(task, compact) {
    const row = el("div", "task-row");
    const canEdit = projectById(task.project_id)?.access_level !== "viewer";
    const check = canEdit
      ? actionButton("", "toggle-task", "task-check", { "aria-label": "Change task status", "data-task-id": task.id, "data-project-id": task.project_id })
      : el("span", "task-check");
    if (task.status === "done") check.appendChild(icon("check"));
    const main = el("div", "row-main");
    append(main, el("span", "row-title", task.title), el("span", "row-subtitle", task.project_name || projectById(task.project_id)?.name || "Project"));
    const trailing = el("div", "row-trailing");
    if (task.due_at) {
      const overdue = new Date(String(task.due_at).replace(" ", "T") + "Z").getTime() < Date.now() && task.status !== "done";
      trailing.appendChild(el("small", overdue ? "due-urgent" : "", formatDate(task.due_at, { month: "short", day: "numeric" })));
    }
    if (!compact) trailing.appendChild(badge(task.status));
    append(row, check, main, trailing);
    return row;
  }

  function renderTeams() {
    const root = pageRoot();
    root.appendChild(pageHeading("COLLABORATE", "Your teams", "Organize people around shared goals, manage invitations, and keep each project connected.",
      actionGroup(actionButton("Accept invitation", "accept-invitation", "btn btn-outline"), actionButton("＋ Create team", "create-team", "btn btn-primary"))));
    const toolbar = el("div", "view-toolbar");
    const search = el("div", "search-control");
    search.append(icon("search"));
    const input = el("input", "input");
    input.type = "search"; input.placeholder = "Search teams…"; input.setAttribute("aria-label", "Search teams");
    input.addEventListener("input", () => { state.search = input.value.toLowerCase(); renderTeamCards(list); });
    search.appendChild(input);
    append(toolbar, search, el("span", "small", state.teams.length + (state.teams.length === 1 ? " team" : " teams")));
    root.appendChild(toolbar);
    const list = el("div", "grid grid-auto");
    root.appendChild(list);
    renderTeamCards(list);
  }

  function renderTeamCards(list) {
    list.replaceChildren();
    const teams = state.teams.filter(team => String(team.name).toLowerCase().includes(state.search));
    if (!teams.length) {
      list.appendChild(emptyState(state.search ? "No matching teams" : "Build your team space", state.search ? "Try a different search term." : "Create your first team, then add projects and invite collaborators.", state.search ? null : "Create a team", state.search ? null : "create-team"));
      return;
    }
    teams.forEach(team => {
      const card = el("article", "team-card surface-panel");
      const top = el("div", "team-card-top");
      append(top, append(el("span", "project-mark team-card-mark"), icon("users")), badge(team.role || "member"));
      const body = el("div", "team-card-body");
      append(body, el("h2", "", team.name), el("p", "", "A shared space for project planning, tasks, and team communication."));
      const foot = el("div", "team-card-foot");
      append(foot, el("span", "small", "Role: " + (team.role || "member")), actionButton("Open team →", "open-team", "btn btn-outline btn-sm", { "data-team-id": team.id }));
      append(card, top, body, foot);
      list.appendChild(card);
    });
  }

  async function openTeam(teamId) {
    const team = teamById(teamId);
    if (!team) return;
    state.page = "teams";
    state.search = "";
    stopChatPolling();
    history.pushState({ teamId }, "", "#team/" + team.id);
    $("#current-page-label").textContent = team.name;
    $$("[data-page]").forEach(node => node.classList.toggle("is-active", node.dataset.page === "teams"));
    closeSidebar();
    const root = pageRoot();
    const teamActions = actionGroup();
    if (canManageTeam(team.id)) append(teamActions, actionButton("Invite member", "invite-member", "btn btn-outline", { "data-team-id": team.id }), actionButton("New project", "create-project", "btn btn-primary", { "data-team-id": team.id }));
    if (team.role === "owner") teamActions.appendChild(actionButton("Edit team", "edit-team", "btn btn-outline", { "data-team-id": team.id }));
    root.appendChild(pageHeading("TEAM WORKSPACE", team.name, "Projects and invitations for this team.", teamActions));
    const layout = el("div", "overview-columns");
    const projectContent = el("div", "project-mini-list");
    const projectsPanel = panel("Team projects", "Accessible projects in this team", projectContent);
    const memberContent = el("div", "team-list");
    const invitePanel = panel("Team invitations", "Create a secure one-time invitation token", memberContent);
    append(layout, projectsPanel, invitePanel);
    root.appendChild(layout);
    const archivedContent = canManageTeam(team.id) ? el("div", "project-mini-list") : null;
    if (archivedContent) {
      root.appendChild(panel("Archived projects", "Restore archived work without losing its tasks or conversation history", archivedContent));
    }
    try {
      const data = await request("/api/teams/" + escapePath(team.id) + "/projects");
      const projects = data.projects || [];
      if (!projects.length) projectContent.appendChild(emptyState("No projects in this team", "Create a project to start organizing work.", "Create project", "create-project"));
      projects.forEach(project => {
        const row = el("button", "project-mini-row"); row.type = "button"; row.dataset.openProject = project.id;
        append(row, el("span", "project-mark", initials(project.name).slice(0, 2)),
          append(el("span", "row-main"), el("span", "row-title", project.name), el("span", "row-subtitle", project.description || "Project workspace")),
          el("span", "row-trailing", "↗"));
        projectContent.appendChild(row);
      });
      if ((team.role === "owner" || team.role === "manager")) {
        const inviteForm = el("form", "invite-inline-form");
        const field = el("div", "field");
        const label = el("label", "label", "Member email"); label.htmlFor = "team-invite-email";
        const email = el("input", "input"); email.type = "email"; email.id = "team-invite-email"; email.name = "email"; email.placeholder = "colleague@example.com"; email.required = true;
        append(field, label, email);
        const submit = el("button", "btn btn-primary btn-sm", "Create invitation"); submit.type = "submit";
        append(inviteForm, field, submit);
        inviteForm.addEventListener("submit", async event => {
          event.preventDefault(); submit.disabled = true;
          try {
            const result = await request("/api/teams/" + escapePath(team.id) + "/invitations", { method: "POST", body: { email: email.value.trim() } });
            const invitation = result.invitation || {};
            showInvitationToken(invitation.token || "", email.value.trim());
            inviteForm.reset();
            toast("Invitation created. Copy the token now; it is shown only once.");
            await renderPendingInvitations(team, memberContent);
          } catch (error) { toast(error.message, "error"); }
          finally { submit.disabled = false; }
        });
        memberContent.appendChild(inviteForm);
        await renderPendingInvitations(team, memberContent);
      } else {
        memberContent.appendChild(el("p", "small", "Only team owners and managers can create or revoke invitations."));
      }

      if (archivedContent) {
        const archivedData = await request("/api/teams/" + escapePath(team.id) + "/archived-projects");
        const archivedProjects = archivedData.projects || [];
        if (!archivedProjects.length) {
          archivedContent.appendChild(emptyState("No archived projects", "Projects archived by your team will appear here and can be restored."));
        }
        archivedProjects.forEach(project => {
          const row = el("div", "project-mini-row archived-project-row");
          append(row, el("span", "project-mark", initials(project.name).slice(0, 2)),
            append(el("span", "row-main"), el("span", "row-title", project.name), el("span", "row-subtitle", project.description || "Archived project")),
            actionButton("Restore", "restore-project", "btn btn-outline btn-sm", { "data-project-id": project.id, "data-team-id": team.id }));
          archivedContent.appendChild(row);
        });
      }
    } catch (error) {
      projectContent.appendChild(el("div", "inline-error", error.message));
    }
  }

  async function renderPendingInvitations(team, container) {
    const previous = $("#pending-invitations-list", container);
    if (previous) previous.remove();
    const section = el("div", "pending-invitations");
    section.id = "pending-invitations-list";
    const heading = el("div", "pending-invitations-heading");
    append(heading, el("strong", "", "Pending invitations"), el("span", "small", "Tokens are never shown again"));
    section.appendChild(heading);
    try {
      const data = await request("/api/teams/" + escapePath(team.id) + "/invitations");
      const invitations = data.invitations || [];
      if (!invitations.length) section.appendChild(el("p", "small", "No pending invitations."));
      invitations.forEach(invitation => {
        const row = el("div", "invitation-row");
        const main = el("div", "row-main");
        append(main, el("span", "row-title", invitation.email), el("span", "row-subtitle", "Expires " + formatDate(invitation.expires_at)));
        append(row, main, actionButton("Revoke", "revoke-invitation", "btn btn-ghost btn-sm", { "data-team-id": team.id, "data-invitation-id": invitation.id }));
        section.appendChild(row);
      });
    } catch (error) {
      section.appendChild(el("p", "small", error.status === 404 ? "Invitation management is restricted to team owners and managers." : error.message));
    }
    container.appendChild(section);
  }

  function acceptInvitationModal() {
    openFormModal("Accept a team invitation", "Paste the one-time token you received. Sign in using the email address the invitation was issued to.", [
      { name: "token", label: "Invitation token", type: "text", placeholder: "Paste the 64-character token", required: true, maxLength: 64, full: true }
    ], "Accept invitation", async values => {
      const token = values.token.trim();
      if (!/^[a-f0-9]{64}$/.test(token)) throw new Error("Invitation tokens must contain exactly 64 lowercase hexadecimal characters.");
      const result = await request("/api/team-invitations/accept", { method: "POST", body: { token } });
      await refreshWorkspace(false);
      toast("You have joined the team.");
      const team = teamById(result.team_id);
      if (team) await openTeam(team.id);
      else navigate("teams", false);
    });
  }

  function showInvitationToken(token, email) {
    modalReturnFocus = document.activeElement;
    const overlay = el("div", "tch-modal-overlay");
    overlay.dataset.dismissible = "false";
    const modal = el("section", "tch-modal");
    modal.setAttribute("role", "dialog"); modal.setAttribute("aria-modal", "true"); modal.setAttribute("aria-labelledby", "invitation-modal-title");
    const header = el("div", "tch-modal-header");
    const invitationTitle = el("h2", "", "Invitation token created"); invitationTitle.id = "invitation-modal-title";\n    append(header, append(el("div"), invitationTitle, el("p", "", "Share this token with " + email + " through a trusted channel. The token is only shown once.")),
      actionButton("×", "close-modal", "btn btn-ghost btn-icon", { "aria-label": "Close dialog" }));
    const body = el("div", "tch-modal-body");
    const tokenBox = el("textarea", "textarea"); tokenBox.readOnly = true; tokenBox.rows = 3; tokenBox.value = token; tokenBox.setAttribute("aria-label", "One-time invitation token");
    append(body, el("div", "modal-inline-note", "This baseline does not send invitation email automatically. The recipient must sign in to the matching email account before accepting the invitation."), tokenBox);
    const footer = el("div", "tch-modal-footer");
    append(footer, actionButton("Close", "close-modal", "btn btn-outline"), actionButton("Copy token", "copy-invitation-token", "btn btn-primary", { "data-token": token }));
    append(modal, header, body, footer); overlay.appendChild(modal);
    $("#modal-root").replaceChildren(overlay);
    overlay.addEventListener("keydown", event => handleDialogKeydown(event, overlay));
    tokenBox.focus(); tokenBox.select();
  }

  function renderProjects() {
    const root = pageRoot();
    root.appendChild(pageHeading("PROJECTS", "Project spaces", "Everything your teams are building, with access scoped to your approved membership.",
      actionGroup(actionButton("＋ New project", "create-project", "btn btn-primary"))));
    const toolbar = el("div", "view-toolbar");
    const search = el("div", "search-control"); search.appendChild(icon("search"));
    const input = el("input", "input"); input.type = "search"; input.placeholder = "Search projects…"; input.setAttribute("aria-label", "Search projects");
    input.addEventListener("input", () => { state.search = input.value.toLowerCase(); renderProjectCards(list); });
    search.appendChild(input);
    append(toolbar, search, el("span", "small", state.projects.length + " accessible projects"));
    root.appendChild(toolbar);
    const list = el("div", "grid grid-auto");
    root.appendChild(list);
    renderProjectCards(list);
  }

  function renderProjectCards(list) {
    list.replaceChildren();
    const projects = state.projects.filter(project => String(project.name).toLowerCase().includes(state.search));
    if (!projects.length) {
      list.appendChild(emptyState(state.search ? "No matching projects" : "No projects yet", state.search ? "Try a different search." : "Create a team first, then create a project inside that team.", state.search ? null : "Create a team", state.search ? null : "create-team"));
      return;
    }
    projects.forEach(project => {
      const card = el("article", "project-card surface-panel");
      const top = el("div", "project-card-top");
      append(top, el("span", "project-mark", initials(project.name).slice(0, 2)), badge(project.status || "active"));
      const body = el("div", "project-card-body");
      const title = el("h2", "", project.name);
      const description = el("p", "", project.description || "A focused space for your team's work.");
      append(body, title, description);
      const team = teamById(project.team_id);
      const foot = el("div", "project-card-foot");
      append(foot, el("span", "small", team ? team.name : "Team #" + project.team_id),
        actionButton("Open project →", "open-project", "btn btn-outline btn-sm", { "data-project-id": project.id }));
      append(card, top, body, foot);
      list.appendChild(card);
    });
  }

  async function openProject(project, updateHash = true, tab = "overview") {
    state.selectedProject = project;
    state.page = "project";
    state.projectTab = tab;
    state.search = "";
    stopChatPolling();
    if (updateHash) history.pushState({ projectId: project.id }, "", "#project/" + project.id);
    $("#current-page-label").textContent = project.name;
    $$("[data-page]").forEach(node => node.classList.toggle("is-active", ["projects", "tasks", "chat"].includes(node.dataset.page)));
    closeSidebar();
    await renderProject(project.id, tab);
  }

  async function renderProject(projectId, tab = state.projectTab) {
    // Stop any prior room poller before replacing the project view or its message list.
    stopChatPolling();
    let project = state.selectedProject && Number(state.selectedProject.id) === Number(projectId)
      ? state.selectedProject
      : projectById(projectId);
    if (!project) { navigate("projects", false); return; }

    // Dashboard summaries omit descriptions; load the authorized detail record before rendering project metadata.
    if (typeof project.description === "undefined") {
      const detail = await request("/api/projects/" + escapePath(projectId));
      project = detail.project || project;
    }
    state.selectedProject = project;
    state.projectTab = tab;
    const root = pageRoot();
    const heading = el("div", "project-detail-head");
    append(heading, el("span", "project-mark", initials(project.name).slice(0, 2)),
      append(el("div"), el("div", "eyebrow", "PROJECT WORKSPACE"), el("h1", "", project.name),
        el("p", "", project.description || "Plan work, coordinate the team, and keep decisions in context.")));
    const headActions = actionGroup();
    if (project.access_level !== "viewer") headActions.appendChild(actionButton("New task", "create-task", "btn btn-primary", { "data-project-id": project.id }));
    if (canManageTeam(project.team_id)) headActions.appendChild(actionButton("Edit project", "edit-project", "btn btn-outline", { "data-project-id": project.id }));
    if (canManageTeam(project.team_id)) headActions.appendChild(actionButton("Archive project", "archive-project", "btn btn-danger-outline", { "data-project-id": project.id }));
    headActions.appendChild(actionButton("Back to projects", "navigate-projects", "btn btn-outline"));
    append(root, heading, headActions);
    const tabs = el("div", "project-tabs");
    [["overview", "Overview", "grid"], ["tasks", "Tasks", "check-square"], ["members", "Members", "users"], ["chat", "Chat", "message"], ["repository", "Repository", "chart"]].forEach(([key, label, iconName]) => {
      const button = el("button", "project-tab" + (tab === key ? " is-active" : ""));
      button.type = "button"; button.dataset.projectTab = key;
      append(button, icon(iconName), label);
      tabs.appendChild(button);
    });
    root.appendChild(tabs);
    const content = el("div", "project-tab-panel");
    root.appendChild(content);
    tabs.addEventListener("click", event => {
      const button = event.target.closest("[data-project-tab]");
      if (!button) return;
      openProject(project, true, button.dataset.projectTab);
    });
    if (tab === "overview") {
      const tasks = state.tasks.filter(task => Number(task.project_id) === Number(project.id));
      const done = tasks.filter(task => task.status === "done").length;
      const layout = el("div", "grid grid-3");
      append(layout, metricCard("Total tasks", tasks.length, "Tasks in this project", "check-square"),
        metricCard("Completed", done, tasks.length ? Math.round(done / tasks.length * 100) + "% complete" : "No tasks yet", "check"),
        metricCard("Team access", project.access_level || "member", "Your current access level", "shield"));
      const panelContent = el("div", "task-list");
      if (tasks.length) tasks.slice(0, 6).forEach(task => panelContent.appendChild(taskRow(task, false)));
      else panelContent.appendChild(emptyState("Give this project its first task", "Break the next milestone into a clear, actionable task.", "Create task", "create-task"));
      append(content, layout, el("div", "view-section"), panel("Project tasks", "A quick look at active work", panelContent, actionButton("All tasks", "project-tab", "btn btn-ghost btn-sm", { "data-project-tab": "tasks" })));
    } else if (tab === "tasks") {
      const toolbar = el("div", "view-toolbar");
      append(toolbar, el("p", "small", project.access_level === "viewer" ? "You have read-only access to this project." : "Change a task status with the action on its right."));
      if (project.access_level !== "viewer") toolbar.appendChild(actionButton("＋ Add task", "create-task", "btn btn-primary btn-sm", { "data-project-id": project.id }));
      content.appendChild(toolbar);
      const tasks = state.tasks.filter(task => Number(task.project_id) === Number(project.id));
      const list = el("div", "surface-panel task-list");
      if (!tasks.length) list.appendChild(emptyState("No tasks in this project", "Create a task to make the next step clear.", "Create task", "create-task"));
      tasks.forEach(task => list.appendChild(taskTableRow(task)));
      content.appendChild(list);
    } else if (tab === "members") {
      await renderProjectMembers(content, project);
    } else if (tab === "chat") {
      await renderProjectChat(content, project);
    } else if (tab === "repository") {
      await renderProjectGitHub(content, project);
    }
  }


  /**
   * Render a project-scoped public GitHub repository view using the server's shared daily cache.
   * Only canonical GitHub links and validated short commit hashes are used to build external links.
   */
  async function renderProjectGitHub(content, project) {
    content.appendChild(el("div", "panel-body page-loading", "Loading linked repository…"));
    try {
      const response = await request("/api/projects/" + escapePath(project.id) + "/github");
      content.replaceChildren();
      const header = el("div", "project-github-header surface-panel");
      const copy = el("div", "project-github-copy");
      append(copy, el("span", "eyebrow", "SOURCE CONTROL"), el("h2", "", "GitHub repository"),
        el("p", "", "Public repository metadata and the five most recent commits are shared from a bounded server cache."));
      const actions = el("div", "project-github-actions");
      if (response.can_manage) {
        actions.appendChild(actionButton(response.linked ? "Change repository" : "Connect repository", "connect-project-github", "btn btn-primary btn-sm", {
          "data-project-id": project.id,
          "data-repository": response.linked ? response.repository.repository : ""
        }));
        if (response.linked) actions.appendChild(actionButton("Unlink", "unlink-project-github", "btn btn-outline btn-sm", {
          "data-project-id": project.id,
          "data-repository": response.repository.repository
        }));
      }
      append(header, copy, actions);
      content.appendChild(header);

      if (!response.linked) {
        const empty = emptyState("No repository linked", response.can_manage
          ? "Connect this project to a public GitHub repository to show its summary and recent commits."
          : "A team owner or manager can connect a public GitHub repository to this project.");
        if (response.can_manage) empty.appendChild(actionButton("Connect GitHub repository", "connect-project-github", "btn btn-primary", {
          "data-project-id": project.id,
          "data-repository": ""
        }));
        content.appendChild(empty);
        return;
      }

      const snapshot = response.repository || {};
      const data = snapshot.data;
      const status = el("div", "alert project-github-status");
      append(status, icon("info"), el("span", "", snapshot.message || "Repository cache status is unavailable."));
      content.appendChild(status);
      if (!snapshot.available || !data) {
        content.appendChild(emptyState("Repository snapshot unavailable", "The server preserves the last successful snapshot and limits refresh attempts to protect the public GitHub API."));
        return;
      }

      const repositoryName = /^[A-Za-z0-9-]+\/[A-Za-z0-9_.-]+$/.test(snapshot.repository || "") ? snapshot.repository : "";
      const repositoryLink = el("a", "project-github-external", "Open repository");
      repositoryLink.href = "https://github.com/" + repositoryName;
      repositoryLink.target = "_blank";
      repositoryLink.rel = "noopener noreferrer";
      const summary = el("div", "project-github-summary surface-panel");
      const summaryCopy = el("div", "project-github-summary-copy");
      append(summaryCopy, el("span", "eyebrow", "PUBLIC REPOSITORY"),
        el("h3", "", data.full_name || snapshot.repository),
        el("p", "", data.description || "This repository has no public description."));
      const branch = el("span", "badge-status", "Default branch · " + (data.default_branch || "main"));
      append(summaryCopy, branch, repositoryLink);
      const language = el("div", "project-github-language");
      append(language, el("span", "small", "Primary language"), el("strong", "", data.language || "Not specified"));
      append(summary, summaryCopy, language);
      content.appendChild(summary);

      const stats = el("div", "grid grid-3 project-github-metrics");
      append(stats,
        metricCard("Stars", Number(data.stars || 0).toLocaleString(), "Public repository stars", "chart"),
        metricCard("Forks", Number(data.forks || 0).toLocaleString(), "Repository forks", "users"),
        metricCard("Open issues", Number(data.open_issues || 0).toLocaleString(), "Issues reported by GitHub", "message"));
      content.appendChild(stats);
      const activity = el("div", "project-github-activity surface-panel");
      const activityHead = el("div", "panel-heading");
      append(activityHead, append(el("div"), el("h2", "", "Recent commits"), el("p", "", "Latest five public commits available from GitHub.")));
      activity.appendChild(activityHead);
      const commits = Array.isArray(data.commits) ? data.commits : [];
      if (!commits.length) {
        activity.appendChild(emptyState("No recent commits returned", "GitHub did not provide commits for this repository."));
      } else {
        const list = el("div", "project-github-commit-list");
        commits.forEach(commit => {
          const row = el("article", "project-github-commit");
          const main = el("div", "project-github-commit-copy");
          append(main, el("strong", "", commit.message || "Commit message unavailable"),
            el("span", "small", (commit.author || "Unknown contributor") + " · " + formatAdminDate(commit.committed_at)));
          const commitLink = el("a", "btn btn-ghost btn-sm", "View commit");
          if (repositoryName && /^[a-f0-9]{7}$/i.test(commit.sha || "")) {
            commitLink.href = "https://github.com/" + repositoryName + "/commit/" + commit.sha;
            commitLink.target = "_blank";
            commitLink.rel = "noopener noreferrer";
          } else {
            commitLink.removeAttribute("href");
            commitLink.setAttribute("aria-disabled", "true");
          }
          append(row, main, commitLink);
          list.appendChild(row);
        });
        activity.appendChild(list);
      }
      content.appendChild(activity);
      content.appendChild(el("p", "small project-github-footnote", "Last successful fetch: " + formatAdminDate(snapshot.fetched_at) + ". Public data only; no GitHub token is stored."));
    } catch (error) {
      content.replaceChildren(el("div", "alert alert-danger", error.message || "The repository details could not be loaded."));
    }
  }

  /** Link a project to a public GitHub URL through the manager-authorized endpoint. */
  function connectProjectRepositoryModal(projectId, currentRepository) {
    const project = projectById(projectId) || state.selectedProject;
    if (!project || !canManageTeam(project.team_id)) {
      toast("Only an authorized team owner or manager can change this repository link.", "warning");
      return;
    }
    const currentUrl = currentRepository ? "https://github.com/" + currentRepository : "";
    openFormModal("Connect GitHub repository", "Use a direct HTTPS URL to a public github.com repository. Private repositories and other hosts are not supported.", [
      { name: "repository_url", label: "Public repository URL", type: "url", required: true, maxLength: 255, value: currentUrl, placeholder: "https://github.com/owner/repository", help: "The server validates the host and stores only owner/repository. No token or webhook is required.", full: true }
    ], "Save repository link", async values => {
      const result = await request("/api/projects/" + escapePath(project.id) + "/github", {
        method: "POST",
        body: { repository_url: values.repository_url.trim() }
      });
      await refreshWorkspace(false);
      const updated = projectById(project.id) || result.project || project;
      toast("GitHub repository linked. Public metadata is cached and shared for this repository.");
      await openProject(updated, true, "repository");
    });
  }

  async function unlinkProjectRepository(projectId) {
    const project = projectById(projectId) || state.selectedProject;
    if (!project || !canManageTeam(project.team_id)) {
      toast("Only an authorized team owner or manager can change this repository link.", "warning");
      return;
    }
    if (!window.confirm("Unlink this repository from the project? The cached public snapshot remains available for other projects that use the same repository.")) return;
    await request("/api/projects/" + escapePath(project.id) + "/github", {
      method: "POST",
      body: { repository_url: null }
    });
    await refreshWorkspace(false);
    const updated = projectById(project.id) || project;
    toast("GitHub repository unlinked from this project.");
    await openProject(updated, true, "repository");
  }

  function taskTableRow(task) {
    const row = el("div", "task-table-row");
    const main = el("div", "row-main");
    append(main, el("span", "row-title", task.title), el("span", "row-subtitle", task.description || "Created by " + (task.created_by_name || "a team member")));
    const due = el("span", "task-due", task.due_at ? formatDate(task.due_at, { month: "short", day: "numeric" }) : "No due date");
    const controls = el("div", "task-controls");
    append(controls, priorityLabel(task.priority), badge(task.status));
    if (projectById(task.project_id)?.access_level !== "viewer") {
      controls.appendChild(actionButton(nextTaskStatus(task.status).label, "set-task-status", "btn btn-outline btn-sm", { "data-task-id": task.id, "data-project-id": task.project_id, "data-next-status": nextTaskStatus(task.status).value }));
    }
    controls.appendChild(actionButton("Details", "task-details", "btn btn-ghost btn-sm", { "data-task-id": task.id, "data-project-id": task.project_id }));
    append(row, main, due, controls);
    return row;
  }

  function nextTaskStatus(status) {
    if (status === "todo") return { value: "in_progress", label: "Start" };
    if (status === "in_progress") return { value: "done", label: "Complete" };
    if (status === "blocked") return { value: "in_progress", label: "Resume" };
    return { value: "todo", label: "Reopen" };
  }

  async function renderProjectMembers(content, project) {
    const panelBox = el("section", "surface-panel");
    const header = el("div", "panel-heading");
    append(header, append(el("div"), el("h2", "", "Project members"), el("p", "", "Approved access and pending membership requests.")));
    if (canManageTeam(project.team_id)) header.appendChild(actionButton("＋ Add member", "add-project-member", "btn btn-primary btn-sm", { "data-project-id": project.id }));
    const body = el("div", "panel-body");
    append(panelBox, header, body); content.appendChild(panelBox);
    try {
      const result = await request("/api/projects/" + escapePath(project.id) + "/members");
      const members = result.members || [];
      if (!members.length) body.appendChild(emptyState("No membership records", "Members will appear here after they are assigned to this project."));
      const list = el("div", "member-list");
      members.forEach(member => {
        const row = el("div", "member-row");
        append(row, avatar(member.display_name || member.email), append(el("div", "row-main"), el("span", "row-title", member.display_name || member.email), el("span", "row-subtitle", member.email || ("User #" + member.user_id))),
          badge(member.status === "approved" ? (member.access_level || "member") : (member.status || "pending")));
        if (canManageTeam(project.team_id) && member.status === "pending") row.appendChild(actionButton("Approve", "approve-member", "btn btn-outline btn-sm", { "data-project-id": project.id, "data-user-id": member.user_id }));
        if (canManageTeam(project.team_id) && member.status === "approved" && !member.is_project_creator && Number(member.user_id) !== Number(state.user.id)) {
          const nextLevel = member.access_level === "member" ? "viewer" : "member";
          row.appendChild(actionButton(nextLevel === "viewer" ? "Make viewer" : "Grant edit access", "change-member-access", "btn btn-outline btn-sm", {
            "data-project-id": project.id, "data-user-id": member.user_id, "data-access-level": nextLevel
          }));
        }
        if (canManageTeam(project.team_id) && member.status !== "revoked" && Number(member.user_id) !== Number(state.user.id)) row.appendChild(actionButton("Revoke", "revoke-member", "btn btn-ghost btn-sm", { "data-project-id": project.id, "data-user-id": member.user_id }));
        list.appendChild(row);
      });
      body.appendChild(list);
    } catch (error) {
      body.appendChild(el("div", "inline-error", error.status === 404 ? "Member management is available to team owners and managers." : error.message));
    }
  }

  async function renderProjectChat(content, project) {
    const layout = el("div", "chat-layout");
    const chatPanel = el("section", "surface-panel chat-panel");
    const head = el("div", "chat-panel-header");
    const headCopy = el("div");
    append(headCopy, el("strong", "", "Project conversation"), el("small", "", "Short-lived text chat · messages expire after 24 hours"));
    const live = el("span", "chat-live"); append(live, el("i"), "Live polling");
    append(head, headCopy, live);
    const messages = el("div", "chat-messages");
    messages.id = "chat-messages";
    messages.setAttribute("aria-live", "polite");
    messages.setAttribute("aria-label", "Project messages");
    const form = el("form", "chat-compose");
    const textarea = el("textarea", "textarea"); textarea.name = "body"; textarea.rows = 1; textarea.maxLength = 2000; textarea.placeholder = "Write a message to your project…"; textarea.setAttribute("aria-label", "Message"); textarea.required = true;
    const send = el("button", "btn btn-primary", "Send"); send.type = "submit";
    append(form, textarea, send);
    const note = el("div", "chat-compose-note", project.access_level === "viewer" ? "You have read-only access to this conversation." : "Keep messages respectful. Report a message if it needs a moderator's attention.");
    if (project.access_level === "viewer") form.hidden = true;
    append(chatPanel, head, messages, form, note);
    const side = el("aside", "surface-panel chat-side-panel");
    append(side, el("h3", "", "Conversation details"), el("p", "", "This is a lightweight project room. The latest 50 messages load first, and new messages are checked in the background while this view is open."));
    const stat1 = el("div", "chat-side-stat"); append(stat1, el("span", "", "Project"), el("strong", "", project.name));
    const stat2 = el("div", "chat-side-stat"); append(stat2, el("span", "", "Retention"), el("strong", "", "24 hours"));
    const stat3 = el("div", "chat-side-stat"); append(stat3, el("span", "", "Access"), el("strong", "", project.access_level || "member"));
    append(side, stat1, stat2, stat3);
    append(layout, chatPanel, side);
    content.appendChild(layout);
    form.addEventListener("submit", async event => {
      event.preventDefault();
      const body = textarea.value.trim();
      if (!body || send.disabled) return;
      send.disabled = true;
      try {
        await request("/api/projects/" + escapePath(project.id) + "/chat", { method: "POST", body: { body } });
        textarea.value = "";
        await pollChat(true);
      } catch (error) { toast(error.message, "error"); }
      finally { send.disabled = false; textarea.focus(); }
    });
    await pollChat(true, true);
    state.chatTimer = window.setInterval(() => {
      if (document.hidden || state.chatBusy || state.page !== "project" || state.projectTab !== "chat") return;
      pollChat(false);
    }, 8000);
  }

  async function pollChat(initial, forceScroll = false) {
    const project = state.selectedProject;
    const list = $("#chat-messages");
    if (!project || !list || state.chatBusy) return;
    state.chatBusy = true;
    try {
      // Periodically reconcile the latest bounded window so edits and deletions
      // to existing messages become visible even though the API cursor is ID-based.
      const fullSync = initial || (!forceScroll && (++state.chatPollCount % 4 === 0));
      const wasNearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 48;
      const previousScrollHeight = list.scrollHeight;
      const previousScrollTop = list.scrollTop;
      const query = fullSync ? "" : "?after_id=" + encodeURIComponent(state.chatCursor);
      const data = await request("/api/projects/" + escapePath(project.id) + "/chat" + query);
      const messages = Array.isArray(data.messages) ? data.messages : [];
      if (messages.length > 0) {
        // Remove the initial empty state when the first new message arrives on an
        // incremental poll, not only during a full-window reconciliation.
        const placeholder = $(".empty-state", list);
        if (placeholder) placeholder.remove();
      }
      if (fullSync) {
        // Reconcile the bounded window instead of rebuilding every message. This
        // makes edits and deletions visible without re-announcing unchanged chat.
        const incomingIds = new Set(messages.map(message => String(message.id)));
        $(".chat-message", list).forEach(row => {
          if (!incomingIds.has(row.id.replace("chat-message-", ""))) row.remove();
        });
        if (messages.length === 0) {
          $(".chat-message", list).forEach(row => row.remove());
          if (!$(".empty-state", list)) list.appendChild(emptyState("Start the conversation", "Share a short update, ask a question, or unblock a teammate."));
        } else {
          const placeholder = $(".empty-state", list);
          if (placeholder) placeholder.remove();
        }
      }

      messages.forEach(message => {
        const existing = $("#chat-message-" + message.id, list);
        if (existing) {
          if (fullSync && existing.dataset.messageVersion !== chatMessageVersion(message)) {
            existing.replaceWith(renderChatMessage(message));
          }
        } else {
          list.appendChild(renderChatMessage(message));
        }
        state.chatCursor = Math.max(state.chatCursor, Number(message.id) || 0);
      });

      if (initial || forceScroll || wasNearBottom) {
        list.scrollTop = list.scrollHeight;
      } else if (fullSync) {
        // Keep the visible part of the conversation stable if a message changed height.
        list.scrollTop = Math.max(0, previousScrollTop + list.scrollHeight - previousScrollHeight);
      }
      setConnection(true);
    } catch (error) {
      if (error.status !== 401) setConnection(false);
    } finally {
      state.chatBusy = false;
    }
  }

  function chatMessageVersion(message) {
    return JSON.stringify([message.body, message.edited_at, message.deleted_at, message.display_name, message.created_at]);
  }

  function renderChatMessage(message) {
    const own = Number(message.user_id) === Number(state.user.id);
    const deleted = Boolean(message.deleted_at);
    const row = el("article", "chat-message" + (own ? " own" : "") + (deleted ? " deleted" : ""));
    row.id = "chat-message-" + message.id;
    row.dataset.messageVersion = chatMessageVersion(message);
    const displayName = message.display_name || "Team member";
    const meta = el("div", "chat-message-meta");
    append(meta, el("strong", "", own ? "You" : displayName), el("span", "", formatRelative(message.created_at)));
    if (message.edited_at) meta.appendChild(el("span", "", "edited"));
    const bubble = el("div", "chat-bubble", deleted ? "This message was deleted." : message.body);
    const body = el("div", "chat-message-content");
    append(body, meta, bubble);
    if (!deleted) {
      const actions = el("div", "chat-message-actions");
      if (own && state.selectedProject?.access_level !== "viewer") {
        actions.appendChild(actionButton("Edit", "edit-message", "chat-action", { "data-message-id": message.id }));
        actions.appendChild(actionButton("Delete", "delete-message", "chat-action", { "data-message-id": message.id }));
      } else if (!own) {
        actions.appendChild(actionButton("Report", "report-message", "chat-action", { "data-message-id": message.id }));
      }
      body.appendChild(actions);
    }
    append(row, avatar(displayName), body);
    return row;
  }

  function stopChatPolling() {
    if (state.chatTimer) window.clearInterval(state.chatTimer);
    state.chatTimer = null;
    state.chatCursor = 0;
    state.chatPollCount = 0;
    state.chatBusy = false;
  }

  function renderChatLanding() {
    const root = pageRoot();
    root.appendChild(pageHeading("COMMUNICATION", "Project chat", "Lightweight, text-only conversations scoped to approved project members.",
      actionGroup(actionButton("Browse projects", "navigate-projects", "btn btn-primary"))));
    if (!state.projects.length) {
      root.appendChild(emptyState("Chat lives inside a project", "Create or join a project first. The chat room inherits project access permissions."));
      return;
    }
    const list = el("div", "grid grid-auto");
    state.projects.forEach(project => {
      const card = el("article", "surface-panel team-card");
      append(card, append(el("div", "team-card-top"), el("span", "project-mark", initials(project.name).slice(0, 2)), badge("active")),
        append(el("div", "team-card-body"), el("h2", "", project.name), el("p", "", "Open the conversation for this project.")),
        append(el("div", "team-card-foot"), el("span", "small", "Text chat · 24-hour retention"), actionButton("Open chat →", "open-project-chat", "btn btn-outline btn-sm", { "data-project-id": project.id })));
      list.appendChild(card);
    });
    root.appendChild(list);
  }

  function utcToLocalInput(value) {
    if (!value) return "";
    const date = new Date(String(value).replace(" ", "T") + (String(value).includes("Z") ? "" : "Z"));
    if (Number.isNaN(date.getTime())) return "";
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  }

  async function openTaskDetails(projectId, taskId) {
    const details = await request("/api/projects/" + escapePath(projectId) + "/tasks/" + escapePath(taskId) + "/details");
    const task = details.task;
    if (!task) throw new Error("Task details were not returned by the server.");
    modalReturnFocus = document.activeElement;
    const overlay = el("div", "tch-modal-overlay");
    const modal = el("section", "tch-modal task-details-modal");
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "task-details-title");
    const header = el("div", "tch-modal-header task-details-header");
    const headerCopy = el("div");
    const title = el("h2", "", task.title); title.id = "task-details-title";
    append(headerCopy, el("div", "eyebrow", "TASK DETAILS · #" + task.id), title,
      el("p", "", projectById(projectId)?.name || "Project workspace"));
    append(header, headerCopy, actionButton("×", "close-modal", "btn btn-ghost btn-icon", { "aria-label": "Close task details" }));
    const body = el("div", "task-details-body");
    const summary = el("div", "task-detail-summary");
    append(summary, badge(task.status), priorityLabel(task.priority),
      el("span", "task-detail-meta", task.due_at ? "Due " + formatDate(task.due_at) : "No due date"),
      el("span", "task-detail-meta", task.assigned_to_name ? "Assigned to " + task.assigned_to_name : "Unassigned"));
    body.appendChild(summary);

    const writable = task.access_level !== "viewer";
    if (writable) {
      const editSection = el("section", "task-detail-section");
      const sectionHead = el("div", "task-detail-section-head");
      append(sectionHead, el("h3", "", "Task information"), el("span", "small", "Changes are saved to this project"));
      const form = el("form", "task-edit-form");
      const grid = el("div", "form-grid");
      const titleField = makeTaskField("title", "Task title", "text", task.title, true, 160);
      const priorityField = makeTaskField("priority", "Priority", "select", task.priority, true);
      [["low","Low"],["normal","Normal"],["high","High"],["urgent","Urgent"]].forEach(([value,label]) => {
        const option = el("option", "", label); option.value = value; priorityField.control.appendChild(option);
      });
      priorityField.control.value = task.priority;
      const descriptionField = makeTaskField("description", "Description", "textarea", task.description || "", false, 10000);
      descriptionField.control.rows = 3;
      descriptionField.wrapper.classList.add("field-full");
      const dueField = makeTaskField("due_at", "Due date (local time)", "datetime-local", utcToLocalInput(task.due_at), false);
      const assigneeField = makeAssigneeField("assigned_to", "Assignee (optional)", projectId,
        task.assigned_to ? { user_id: task.assigned_to, display_name: task.assigned_to_name || "", email: "" } : null,
        "Only approved member-level project users can be assigned. Leave empty to unassign this task.");
      append(grid, titleField.wrapper, priorityField.wrapper, descriptionField.wrapper, dueField.wrapper, assigneeField.wrapper);
      const saveRow = el("div", "task-detail-save-row");
      const save = el("button", "btn btn-primary btn-sm", "Save changes"); save.type = "submit";
      const error = el("div", "inline-error"); error.hidden = true; error.setAttribute("role", "alert");
      append(saveRow, save);
      append(form, grid, error, saveRow);
      form.addEventListener("submit", async event => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        save.disabled = true; save.textContent = "Saving…"; error.hidden = true;
        try {
          await request("/api/projects/" + escapePath(projectId) + "/tasks/" + escapePath(taskId) + "/edit", {
            method: "POST",
            body: {
              title: titleField.control.value.trim(),
              description: descriptionField.control.value.trim() || null,
              priority: priorityField.control.value,
              due_at: dateForApi(dueField.control.value),
              assigned_to: assigneeField.control.value.trim() ? Number(assigneeField.control.value) : null
            }
          });
          await refreshWorkspace(false);
          toast("Task details updated.");
          await openTaskDetails(projectId, taskId);
        } catch (errorValue) {
          error.textContent = errorValue.message || "Task details could not be saved.";
          error.hidden = false;
        } finally {
          save.disabled = false;
          save.textContent = "Save changes";
        }
      });
      append(editSection, sectionHead, form);
      body.appendChild(editSection);
    } else {
      const readOnly = el("section", "task-detail-section");
      append(readOnly, el("h3", "", "Task information"), el("p", "task-readonly-description", task.description || "No description has been added."));
      body.appendChild(readOnly);
    }

    const checklist = el("section", "task-detail-section");
    const checklistHead = el("div", "task-detail-section-head");
    const completedCount = details.subtasks.filter(item => item.is_done).length;
    append(checklistHead, el("h3", "", "Checklist"), el("span", "small", completedCount + " of " + details.subtasks.length + " complete"));
    checklist.appendChild(checklistHead);
    if (details.subtasks.length) {
      const list = el("div", "task-checklist");
      details.subtasks.forEach(item => list.appendChild(renderTaskSubtask(item, task, projectId)));
      checklist.appendChild(list);
    } else checklist.appendChild(el("p", "small task-section-empty", "No checklist items yet."));
    if (writable) {
      const subtaskForm = el("form", "task-inline-form");
      const input = el("input", "input"); input.name = "title"; input.type = "text"; input.maxLength = 160; input.required = true; input.placeholder = "Add a checklist item…"; input.setAttribute("aria-label", "New checklist item");
      const add = el("button", "btn btn-outline btn-sm", "Add item"); add.type = "submit";
      append(subtaskForm, input, add);
      subtaskForm.addEventListener("submit", async event => {
        event.preventDefault();
        if (!input.value.trim()) return;
        add.disabled = true;
        try {
          await request("/api/projects/" + escapePath(projectId) + "/tasks/" + escapePath(taskId) + "/subtasks", { method: "POST", body: { title: input.value.trim() } });
          await openTaskDetails(projectId, taskId);
        } catch (error) { toast(error.message, "error"); add.disabled = false; }
      });
      checklist.appendChild(subtaskForm);
    }
    body.appendChild(checklist);

    const discussion = el("section", "task-detail-section");
    const discussionHead = el("div", "task-detail-section-head");
    append(discussionHead, el("h3", "", "Discussion"), el("span", "small", details.comments.length + " comments"));
    discussion.appendChild(discussionHead);
    const comments = el("div", "task-comments");
    if (details.comments.length) details.comments.forEach(comment => comments.appendChild(renderTaskComment(comment, task, projectId)));
    else comments.appendChild(el("p", "small task-section-empty", "No comments yet. Add context or leave a useful update for your team."));
    discussion.appendChild(comments);
    if (writable) {
      const commentForm = el("form", "task-comment-form");
      const textarea = el("textarea", "textarea"); textarea.rows = 3; textarea.maxLength = 4000; textarea.required = true; textarea.placeholder = "Write a comment…"; textarea.setAttribute("aria-label", "Task comment");
      const actions = el("div", "task-comment-form-actions");
      const submitComment = el("button", "btn btn-primary btn-sm", "Post comment"); submitComment.type = "submit";
      append(actions, el("span", "small", "Up to 4,000 bytes"), submitComment);
      append(commentForm, textarea, actions);
      commentForm.addEventListener("submit", async event => {
        event.preventDefault();
        const submit = $("button[type='submit']", commentForm);
        const value = textarea.value.trim();
        if (!value) return;
        submit.disabled = true;
        try {
          await request("/api/projects/" + escapePath(projectId) + "/tasks/" + escapePath(taskId) + "/comments", { method: "POST", body: { body: value } });
          await refreshWorkspace(false);
          toast("Comment posted.");
          await openTaskDetails(projectId, taskId);
        } catch (error) { toast(error.message, "error"); submit.disabled = false; }
      });
      discussion.appendChild(commentForm);
    } else {
      discussion.appendChild(el("p", "small task-section-empty", "You have read-only access to this task."));
    }
    body.appendChild(discussion);
    append(modal, header, body);
    overlay.appendChild(modal);
    overlay.addEventListener("click", event => { if (event.target === overlay) closeModal(); });
    overlay.addEventListener("keydown", event => handleDialogKeydown(event, overlay));
    $("#modal-root").replaceChildren(overlay);
    const firstInput = $("input, textarea, select, button", modal);
    if (firstInput) firstInput.focus();
  }

  function makeAssigneeField(name, labelText, projectId, initialAssignee, helpText) {
    const wrapper = el("div", "field field-full");
    const label = el("label", "label", labelText);
    const id = "assignee-field-" + Math.random().toString(36).slice(2, 8);
    const control = el("input", "input");
    control.type = "hidden";
    control.name = name;
    const search = el("input", "input assignee-picker-search");
    search.type = "search";
    search.id = id;
    search.maxLength = 80;
    search.autocomplete = "off";
    search.placeholder = "Search by name, email, or user ID";
    search.setAttribute("aria-label", labelText);
    label.htmlFor = id;
    const results = el("div", "assignee-picker-results");
    results.setAttribute("role", "listbox");
    results.setAttribute("aria-label", "Eligible task assignees");
    results.setAttribute("aria-live", "polite");
    const selection = el("div", "assignee-picker-selection");
    append(wrapper, label, control, search, results, selection, el("span", "help", helpText));
    mountAssigneePicker({
      field: { name, projectField: "__project_id", initialAssignee },
      control, search, results, selection
    }, { __project_id: { value: projectId } });
    return { wrapper, control };
  }

  function makeTaskField(name, labelText, type, value, required, maxLength) {
    const wrapper = el("div", "field");
    const label = el("label", "label", labelText);
    const id = "task-field-" + name;
    label.htmlFor = id;
    let control;
    if (type === "textarea") control = el("textarea", "textarea");
    else if (type === "select") control = el("select", "select");
    else { control = el("input", "input"); control.type = type; }
    control.id = id; control.name = name;
    control.value = value == null ? "" : String(value);
    if (required) control.required = true;
    if (maxLength) control.maxLength = maxLength;
    append(wrapper, label, control);
    return { wrapper, control };
  }

  function renderTaskSubtask(item, task, projectId) {
    const row = el("div", "task-subtask-row" + (item.is_done ? " is-done" : ""));
    const toggle = el("button", "task-subtask-toggle" + (item.is_done ? " is-checked" : ""), item.is_done ? "✓" : "");
    toggle.type = "button"; toggle.dataset.action = "toggle-subtask"; toggle.dataset.projectId = projectId; toggle.dataset.taskId = task.id; toggle.dataset.subtaskId = item.id;
    toggle.setAttribute("aria-label", (item.is_done ? "Mark incomplete: " : "Mark complete: ") + item.title);
    toggle.setAttribute("aria-pressed", item.is_done ? "true" : "false");
    const title = el("span", "task-subtask-title", item.title);
    const actions = el("div", "task-subtask-actions");
    if (Number(item.created_by) === Number(state.user.id) || Number(task.created_by) === Number(state.user.id)) {
      actions.appendChild(actionButton("Remove", "delete-subtask", "btn btn-ghost btn-xs", { "data-project-id": projectId, "data-task-id": task.id, "data-subtask-id": item.id }));
    }
    append(row, toggle, title, actions);
    return row;
  }

  function renderTaskComment(comment, task, projectId) {
    const own = Number(comment.user_id) === Number(state.user.id);
    const deleted = Boolean(comment.deleted_at);
    const row = el("article", "task-comment-row" + (deleted ? " is-deleted" : ""));
    const top = el("div", "task-comment-top");
    const author = comment.author_name || comment.author_email || "Team member";
    append(top, avatar(author, "sm"), append(el("div", "task-comment-author"), el("strong", "", own ? "You" : author), el("span", "", formatRelative(comment.created_at))));
    const body = el("p", "task-comment-body", deleted ? "This comment was deleted." : comment.body);
    append(row, top, body);
    if (own && !deleted) {
      const actions = el("div", "task-comment-actions");
      const created = new Date(String(comment.created_at).replace(" ", "T") + (String(comment.created_at).includes("Z") ? "" : "Z"));
      const canEdit = !Number.isNaN(created.getTime()) && Date.now() - created.getTime() <= 15 * 60 * 1000;
      if (canEdit) actions.appendChild(actionButton("Edit", "edit-task-comment", "btn btn-ghost btn-xs", { "data-project-id": projectId, "data-task-id": task.id, "data-comment-id": comment.id }));
      actions.appendChild(actionButton("Delete", "delete-task-comment", "btn btn-ghost btn-xs", { "data-project-id": projectId, "data-task-id": task.id, "data-comment-id": comment.id }));
      row.appendChild(actions);
    }
    return row;
  }

  function renderTasks() {
    const root = pageRoot();
    root.appendChild(pageHeading("PERSONAL WORK", "My tasks", "Track deadlines, update progress, and see work across every project you can access.",
      actionGroup(actionButton("＋ New task", "create-task", "btn btn-primary"))));
    const toolbar = el("div", "view-toolbar");
    const search = el("div", "search-control"); search.appendChild(icon("search"));
    const input = el("input", "input"); input.type = "search"; input.placeholder = "Search tasks or projects…"; input.setAttribute("aria-label", "Search tasks");
    input.addEventListener("input", () => { state.search = input.value.toLowerCase(); renderTaskRows(list); });
    search.appendChild(input);
    const filter = el("select", "select"); filter.setAttribute("aria-label", "Filter tasks by status");
    [["all","All statuses"],["todo","To do"],["in_progress","In progress"],["blocked","Blocked"],["done","Completed"]].forEach(([value,label]) => {
      const option = el("option","",label); option.value=value; filter.appendChild(option);
    });
    filter.value = state.taskFilter;
    filter.addEventListener("change", () => { state.taskFilter = filter.value; renderTaskRows(list); });
    append(toolbar, search, filter, el("span", "small", state.tasks.length + " tasks"));
    root.appendChild(toolbar);
    const list = el("div", "surface-panel task-list");
    root.appendChild(list);
    renderTaskRows(list);
  }

  function renderTaskRows(list) {
    list.replaceChildren();
    const tasks = state.tasks.filter(task => {
      const matches = (String(task.title).toLowerCase().includes(state.search) || String(task.project_name || "").toLowerCase().includes(state.search));
      return matches && (state.taskFilter === "all" || task.status === state.taskFilter);
    });
    if (!tasks.length) {
      list.appendChild(emptyState("No tasks match this view", "Try another status or search term, or create a task in a project.", state.projects.length ? "Create task" : "Create a team", state.projects.length ? "create-task" : "create-team"));
      return;
    }
    tasks.forEach(task => list.appendChild(taskTableRow(task)));
  }

  function renderNotifications() {
    const root = pageRoot();
    const unread = state.notifications.filter(item => !item.is_read).length;
    root.appendChild(pageHeading("UPDATES", "Notifications", "Stay up to date with project access, task assignments, and team activity.",
      actionGroup(actionButton("Refresh", "refresh", "btn btn-outline"))));
    const panelBody = el("div", "notification-list");
    if (!state.notifications.length) panelBody.appendChild(emptyState("You're all caught up", "New invitations, project membership updates, and task changes will show up here."));
    state.notifications.forEach(item => {
      const row = el("div", "notification-row" + (item.is_read ? "" : " is-unread"));
      const mark = el("span", "activity-dot");
      mark.appendChild(icon(item.type && item.type.includes("task") ? "check-square" : "bell"));
      const main = el("div", "row-main");
      append(main, el("span", "row-title", item.title || "Workspace update"), el("span", "row-subtitle", item.body || "There is a new update in your workspace."));
      const trailing = el("div", "row-trailing");
      append(trailing, el("small", "", formatRelative(item.created_at)));
      if (!item.is_read) trailing.appendChild(actionButton("Mark read", "mark-notification-read", "btn btn-outline btn-sm", { "data-notification-id": item.id }));
      append(row, mark, main, trailing);
      panelBody.appendChild(row);
    });
    root.appendChild(panel("Recent notifications", unread + " unread updates", panelBody,
      unread ? actionButton("Mark visible as read", "mark-all-visible-read", "btn btn-outline btn-sm") : null));
  }

  function githubEventRow(event, compact = false) {
    const row = el("article", "github-event-row");
    const iconName = event.type === "PushEvent" ? "chart"
      : event.type === "PullRequestEvent" ? "external"
      : event.type === "IssuesEvent" || event.type === "IssueCommentEvent" ? "message"
      : event.type === "ReleaseEvent" ? "spark" : "folder";
    const mark = el("span", "github-event-icon");
    mark.appendChild(icon(iconName));
    const main = el("div", "row-main");
    append(main, el("span", "row-title", event.summary || "Public repository activity"),
      el("span", "row-subtitle", (event.actor ? event.actor + " · " : "") + (event.repo || "GitHub repository")));
    const trailing = el("div", "row-trailing");
    trailing.appendChild(el("small", "", formatRelative(event.created_at)));
    const repoLink = el("a", "github-repo-link", event.repo || "Open repository");
    repoLink.href = event.repo_url || "https://github.com/" + (event.repo || "");
    repoLink.target = "_blank";
    repoLink.rel = "noopener noreferrer";
    repoLink.setAttribute("aria-label", "Open " + (event.repo || "repository") + " on GitHub in a new tab");
    const content = el("div", "github-event-content");
    append(content, main, repoLink);
    append(row, mark, content, trailing);
    if (compact) row.classList.add("is-compact");
    return row;
  }

  function renderGitHubPreview() {
    const content = el("div", "github-preview-list");
    const activity = state.github;
    if (activity?.available && activity.events?.length) {
      activity.events.slice(0, 3).forEach(event => content.appendChild(githubEventRow(event, true)));
    } else {
      content.appendChild(el("p", "small github-preview-empty", activity?.message || state.githubError || "Public GitHub activity will appear here when the cache is available."));
    }
    const freshness = activity?.fetched_at ? "Updated " + formatRelative(activity.fetched_at) : "Daily public activity cache";
    const preview = panel("GitHub activity", freshness, content, actionButton("View activity", "navigate-github", "btn btn-ghost btn-sm"));
    preview.id = "github-overview-panel";
    return preview;
  }

  function renderGitHubActivity() {
    const root = pageRoot();
    const activity = state.github;
    const username = activity?.username || "ElmanCod110";
    const profile = el("a", "btn btn-outline btn-sm", "Open GitHub profile ↗");
    profile.href = activity?.profile_url || "https://github.com/" + encodeURIComponent(username);
    profile.target = "_blank";
    profile.rel = "noopener noreferrer";
    profile.setAttribute("aria-label", "Open " + username + "'s GitHub profile in a new tab");
    root.appendChild(pageHeading("INTEGRATIONS", "GitHub activity", "A daily cached view of public events for @" + username + ". Visiting this page does not trigger a new outbound request when today's refresh has already been attempted.",
      actionGroup(actionButton("Check cache status", "refresh-github", "btn btn-outline"), profile)));

    const summary = el("section", "github-summary surface-panel");
    const profileMark = el("span", "github-profile-mark", initials(username).slice(0, 2));
    const summaryCopy = el("div", "github-summary-copy");
    append(summaryCopy, el("div", "eyebrow", "PUBLIC ACTIVITY"), el("h2", "", "@" + username),
      el("p", "", activity?.fetched_at ? "Last successful update " + formatDate(activity.fetched_at, { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }) + " UTC" : "No successful snapshot has been saved yet."));
    const status = el("div", "github-summary-status");
    status.appendChild(badge(activity?.stale ? "stale cache" : activity?.available ? "updated today" : "unavailable"));
    const eventCount = el("div", "github-summary-count");
    append(eventCount, el("strong", "", activity?.events?.length ?? 0), el("span", "", "cached events"));
    append(summary, profileMark, summaryCopy, eventCount, status);
    root.appendChild(summary);

    if (activity?.message || state.githubError) {
      const notice = el("div", "global-message");
      notice.textContent = activity?.message || state.githubError || "";
      root.appendChild(notice);
    }
    const body = el("div", "surface-panel github-feed");
    const heading = el("div", "panel-heading");
    append(heading, append(el("div"), el("h2", "", "Recent public events"), el("p", "", "Latest events returned by GitHub's public events API, normalized for this workspace.")),
      el("span", "small", "Maximum 30 events"));
    body.appendChild(heading);
    if (!activity?.available) {
      body.appendChild(emptyState("No GitHub snapshot available", state.githubError || activity?.message || "The cache will show the result of the daily refresh attempt. No automatic retry occurs today.", "Check cache status", "refresh-github"));
    } else if (!activity.events.length) {
      body.appendChild(emptyState("No recent public events", "GitHub returned a successful response, but there are no public events in the cached window."));
    } else {
      const list = el("div", "github-feed-list");
      activity.events.forEach(event => list.appendChild(githubEventRow(event)));
      body.appendChild(list);
    }
    root.appendChild(body);
    root.appendChild(el("p", "small github-footnote", "Only public events are requested. No GitHub token is stored. The cache allows one outbound fetch attempt per configured username per UTC day and preserves the last successful snapshot if a refresh fails."));
  }


  /**
   * Render the site-admin user directory with bounded server-side search.
   * The UI is intentionally read-only; user status and roles are not changed here.
   */
  async function renderAdminUsers() {
    const root = pageRoot();
    root.appendChild(pageHeading("SITE ADMINISTRATION", "User directory",
      "Search account records and review status, sign-in activity, and administrator flags.",
      actionGroup(actionButton("Refresh", "refresh-admin-users", "btn btn-outline"))));

    const toolbar = el("form", "admin-users-toolbar surface-panel");
    const query = el("input", "input");
    query.type = "search";
    query.name = "q";
    query.maxLength = 100;
    query.placeholder = "Search name, email, or exact user ID";
    query.setAttribute("aria-label", "Search users by name, email, or exact user ID");
    const status = el("select", "select");
    status.setAttribute("aria-label", "Filter users by account status");
    [["all", "All statuses"], ["active", "Active"], ["pending", "Pending"], ["disabled", "Disabled"]].forEach(([value, label]) => {
      const option = el("option", "", label);
      option.value = value;
      status.appendChild(option);
    });
    const submit = el("button", "btn btn-primary", "Search users");
    submit.type = "submit";
    append(toolbar, query, status, submit);
    root.appendChild(toolbar);

    const results = el("section", "admin-users-results surface-panel");
    results.setAttribute("aria-live", "polite");
    results.setAttribute("aria-busy", "true");
    results.appendChild(el("div", "panel-body page-loading", "Loading user directory…"));
    root.appendChild(results);

    let sequence = 0;
    let controller = null;
    let timer = null;
    let page = 1;
    const load = async () => {
      const current = ++sequence;
      if (controller) controller.abort();
      controller = new AbortController();
      results.setAttribute("aria-busy", "true");
      results.replaceChildren(el("div", "panel-body page-loading", "Loading user directory…"));
      const params = new URLSearchParams();
      if (query.value.trim()) params.set("q", query.value.trim());
      params.set("status", status.value);
      params.set("page", String(page));
      try {
        const data = await request("/api/admin/users?" + params.toString(), { signal: controller.signal });
        if (current !== sequence) return;
        results.replaceChildren();
        const users = Array.isArray(data.users) ? data.users : [];
        const heading = el("div", "panel-heading");
        append(heading, append(el("div"), el("h2", "", "Accounts"), el("p", "", users.length + " result" + (users.length === 1 ? "" : "s") + (" · page " + page + (data.has_more ? " · more results available" : "")))), badge(status.value === "all" ? "all statuses" : status.value));
        results.appendChild(heading);
        if (!users.length) {
          results.appendChild(emptyState("No accounts found", "Try a different name, email address, or exact user ID."));
        } else {
          const list = el("div", "admin-user-list");
          users.forEach(user => list.appendChild(renderAdminUserRow(user)));
          results.appendChild(list);
        }

        // Keep each response bounded while allowing administrators to move through large directories.
        const pagination = el("div", "admin-users-pagination");
        const previous = el("button", "btn btn-outline btn-sm", "Previous");
        previous.type = "button";
        previous.disabled = page <= 1;
        previous.setAttribute("aria-label", "Go to previous user directory page");
        previous.addEventListener("click", () => {
          if (page > 1) { page -= 1; load(); }
        });
        const pageLabel = el("span", "small", "Page " + page);
        pageLabel.setAttribute("aria-live", "polite");
        const next = el("button", "btn btn-outline btn-sm", "Next");
        next.type = "button";
        next.disabled = !data.has_more;
        next.setAttribute("aria-label", "Go to next user directory page");
        next.addEventListener("click", () => {
          if (data.has_more) { page += 1; load(); }
        });
        append(pagination, previous, pageLabel, next);
        results.appendChild(pagination);
      } catch (error) {
        if (error.name === "AbortError" || current !== sequence) return;
        results.replaceChildren(el("div", "panel-body inline-error", error.message || "The user directory could not be loaded."));
      } finally {
        if (current === sequence) results.setAttribute("aria-busy", "false");
      }
    };
    toolbar.addEventListener("submit", event => { event.preventDefault(); page = 1; load(); });
    query.addEventListener("input", () => {
      window.clearTimeout(timer);
      page = 1;
      timer = window.setTimeout(load, 250);
    });
    status.addEventListener("change", () => { page = 1; load(); });
    await load();
  }

  /** Render public account metadata and site-admin-only account actions. */
  function renderAdminUserRow(user) {
    const row = el("article", "admin-user-row");
    const identity = el("div", "admin-user-identity");
    append(identity, avatar(user.display_name || user.email, "md"),
      append(el("div"), el("strong", "", user.display_name || "Unnamed account"),
        el("span", "admin-user-email", user.email),
        el("span", "small", "User #" + user.id)));
    const accountState = el("div", "admin-user-state");
    append(accountState, badge(user.status),
      user.is_site_admin ? badge("site admin") : null,
      user.email_verified ? badge("email verified") : badge("email unverified"));
    const activity = el("div", "admin-user-activity");
    append(activity,
      el("span", "small", "Created " + formatAdminDate(user.created_at)),
      el("span", "small", "Last sign-in " + formatAdminDate(user.last_login_at)));
    const actions = el("div", "admin-user-actions");
    const isCurrentUser = Number(user.id) === Number(state.user?.id);
    if (isCurrentUser) {
      actions.appendChild(el("span", "small", "Current account"));
    } else if (user.status === "disabled") {
      if (user.email_verified) {
        actions.appendChild(actionButton("Reactivate", "admin-user-status", "btn btn-outline btn-sm", {
          "data-user-id": user.id,
          "data-next-status": "active",
          "data-display-name": user.display_name || user.email
        }));
      } else {
        actions.appendChild(el("span", "small", "Unverified accounts cannot be reactivated"));
      }
    } else if (user.status === "active" || user.status === "pending") {
      actions.appendChild(actionButton("Disable", "admin-user-status", "btn btn-danger-outline btn-sm", {
        "data-user-id": user.id,
        "data-next-status": "disabled",
        "data-display-name": user.display_name || user.email
      }));
    }
    actions.appendChild(actionButton("History", "admin-user-audit", "btn btn-ghost btn-sm", {
      "data-user-id": user.id,
      "data-display-name": user.display_name || user.email
    }));
    append(row, identity, accountState, activity, actions);
    return row;
  }

  /**
   * Require an audit reason before changing account access, then refresh the bounded directory.
   * The server prevents self-disable and reactivation of unverified accounts independently.
   */
  function changeAdminUserStatusModal(userId, nextStatus, displayName) {
    if (!(state.user?.roles || []).includes("site_admin")) {
      toast("Site administrator access is required.", "warning");
      return;
    }
    if (Number(userId) === Number(state.user?.id) && nextStatus === "disabled") {
      toast("You cannot disable your own account.", "warning");
      return;
    }
    const reactivating = nextStatus === "active";
    const actionWord = reactivating ? "Reactivate" : "Disable";
    openFormModal(actionWord + " account", reactivating
      ? "Restore sign-in access for " + displayName + ". The reason is retained in the administrator audit history."
      : "Block sign-in access for " + displayName + ". Existing membership and project history are preserved. The reason is retained in the audit history.", [
      { name: "reason", label: "Reason for this change", type: "textarea", rows: 4, required: true, minLength: 5, maxLength: 500, placeholder: "Explain the operational reason for this status change.", help: "Use 5–500 UTF-8 bytes. Never include passwords, tokens, or sensitive personal details.", full: true }
    ], actionWord + " account", async values => {
      const result = await request("/api/admin/users/" + escapePath(userId) + "/status", {
        method: "POST",
        body: { status: nextStatus, reason: values.reason.trim() }
      });
      toast(result.changed === false ? "The account already has that status." : "Account status updated and audit event recorded.");
      await renderAdminUsers();
    });
  }

  /** Show the immutable status-change audit history for one account. */
  async function showAdminUserAuditModal(userId, displayName) {
    if (!(state.user?.roles || []).includes("site_admin")) {
      toast("Site administrator access is required.", "warning");
      return;
    }
    modalReturnFocus = document.activeElement;
    const overlay = el("div", "tch-modal-overlay");
    const modal = el("section", "tch-modal admin-audit-modal");
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    const titleId = "admin-audit-title";
    modal.setAttribute("aria-labelledby", titleId);
    const header = el("div", "tch-modal-header");
    const heading = el("div");
    const title = el("h2", "", "Account audit history"); title.id = titleId;
    append(heading, title, el("p", "", "Recent status changes for " + displayName + "."));
    append(header, heading, actionButton("×", "close-modal", "btn btn-ghost btn-icon", { "aria-label": "Close audit history" }));
    const body = el("div", "tch-modal-body admin-audit-list");
    body.setAttribute("aria-live", "polite");
    body.appendChild(el("p", "small", "Loading audit history…"));
    const footer = el("div", "tch-modal-footer");
    footer.appendChild(actionButton("Close", "close-modal", "btn btn-outline"));
    append(modal, header, body, footer);
    overlay.appendChild(modal);
    $("#modal-root").replaceChildren(overlay);
    overlay.addEventListener("click", event => { if (event.target === overlay) closeModal(); });
    overlay.addEventListener("keydown", event => handleDialogKeydown(event, overlay));
    const close = $("button", modal);
    if (close) close.focus();
    try {
      const data = await request("/api/admin/users/" + escapePath(userId) + "/audit");
      body.replaceChildren();
      const events = Array.isArray(data.events) ? data.events : [];
      if (!events.length) {
        body.appendChild(emptyState("No account changes recorded", "Future account status changes and their reasons will appear here."));
      } else {
        events.forEach(item => {
          const entry = el("article", "admin-audit-event");
          const transition = el("div", "admin-audit-transition");
          append(transition, badge(item.previous_status), el("span", "small", "→"), badge(item.new_status));
          append(entry, transition,
            el("p", "admin-audit-reason", item.reason),
            el("span", "small", (item.actor_name || "Administrator") + " · " + formatAdminDate(item.created_at)));
          body.appendChild(entry);
        });
      }
    } catch (error) {
      body.replaceChildren(el("div", "inline-error", error.message || "Audit history could not be loaded."));
    }
  }

  /** Format a timestamp without assuming that every account has signed in. */
  function formatAdminDate(value) {
    if (!value) return "Never";
    const date = new Date(String(value).replace(" ", "T") + (String(value).endsWith("Z") ? "" : "Z"));
    return Number.isNaN(date.getTime()) ? "Unknown" : date.toLocaleString();
  }

  async function renderModeration() {
    const root = pageRoot();
    root.appendChild(pageHeading("TRUST & SAFETY", "Moderation queue", "Review reports with their preserved conversation context. A report is an allegation, not proof; review each case on its own merits.",
      actionGroup(actionButton("Refresh queue", "refresh-moderation", "btn btn-outline"))));
    const note = el("div", "alert moderation-guidance");
    append(note, icon("shield"), append(el("div"), el("strong", "", "Review with care"), el("p", "", "Do not review a case if you are the reported person or the reporter. Actions are recorded by the server and may be restricted by the report's assigned review scope.")));
    root.appendChild(note);
    const container = el("div", "moderation-list surface-panel");
    container.appendChild(el("div", "panel-body page-loading", "Loading moderation reports…"));
    root.appendChild(container);
    const requests = [];
    if ((state.user?.roles || []).includes("site_admin")) {
      requests.push(request("/api/admin/reports").then(data => data.reports || []).catch(error => {
        if (error.status !== 403) throw error;
        return [];
      }));
    }
    state.teams.filter(team => canManageTeam(team.id)).forEach(team => {
      requests.push(request("/api/teams/" + escapePath(team.id) + "/reports").then(data => data.reports || []).catch(error => {
        if (error.status !== 404 && error.status !== 403) throw error;
        return [];
      }));
    });
    try {
      const groups = await Promise.all(requests);
      const reports = Array.from(new Map(groups.flat().map(report => [Number(report.id), report])).values())
        .sort((a, b) => String(a.created_at || "").localeCompare(String(b.created_at || "")));
      container.replaceChildren();
      if (!reports.length) {
        container.appendChild(emptyState("No reports need your review", "When a report is assigned to your moderation scope, it will appear here with its saved message context."));
        return;
      }
      const header = el("div", "panel-heading");
      append(header, append(el("div"), el("h2", "", "Open cases"), el("p", "", reports.length + " report" + (reports.length === 1 ? "" : "s") + " available in your review scope")), badge("pending"));
      container.appendChild(header);
      const list = el("div", "moderation-cases");
      reports.forEach(report => list.appendChild(renderReportCard(report)));
      container.appendChild(list);
    } catch (error) {
      container.replaceChildren(el("div", "panel-body inline-error", error.message || "The moderation queue could not be loaded."));
    }
  }

  function renderReportCard(report) {
    const card = el("article", "moderation-case");
    const top = el("div", "moderation-case-top");
    const caseLabel = el("div", "moderation-case-id");
    append(caseLabel, el("span", "eyebrow", "REPORT #" + report.id), badge(report.status || "pending"));
    const scope = el("span", "small", report.reviewer_scope === "site_admin" ? "Site administration" : "Team manager review");
    append(top, caseLabel, scope);
    const facts = el("div", "moderation-facts");
    const fact1 = el("div", "moderation-fact");
    append(fact1, el("span", "", "Reported user"), el("strong", "", report.reported_name || ("User #" + report.reported_user_id)));
    const fact2 = el("div", "moderation-fact");
    append(fact2, el("span", "", "Reported by"), el("strong", "", report.reporter_name || ("User #" + report.reporter_user_id)));
    const fact3 = el("div", "moderation-fact");
    append(fact3, el("span", "", "Submitted"), el("strong", "", formatDate(report.created_at, { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" })));
    append(facts, fact1, fact2, fact3);
    const reason = el("div", "moderation-reason");
    append(reason, el("span", "label", "Report reason"), el("p", "", report.reason || "No reason was provided."));
    const context = el("div", "moderation-context");
    const contextHeading = el("div", "moderation-context-heading");
    append(contextHeading, el("strong", "", "Saved conversation context"), el("span", "small", "Adjacent messages captured when the report was submitted"));
    context.appendChild(contextHeading);
    const entries = Array.isArray(report.context) ? report.context : [];
    if (!entries.length) context.appendChild(el("p", "small", "No context snapshot is available."));
    entries.forEach(message => {
      const line = el("div", "moderation-context-message" + (Number(message.id) === Number(report.message_id) ? " is-reported" : ""));
      const meta = el("div", "moderation-context-meta");
      append(meta, el("strong", "", message.display_name || "Team member"), el("span", "", formatRelative(message.created_at)),
        Number(message.id) === Number(report.message_id) ? badge("reported message") : null);
      append(line, meta, el("p", "", message.deleted_at ? "This message was deleted after the report." : (message.body || "")));
      context.appendChild(line);
    });
    const footer = el("div", "moderation-case-footer");
    const actions = el("div", "moderation-actions");
    if (report.status === "pending") {
      actions.appendChild(actionButton("Mark valid", "review-report", "btn btn-outline btn-sm", { "data-report-id": report.id, "data-review-action": "valid" }));
      actions.appendChild(actionButton("Reject report", "review-report", "btn btn-ghost btn-sm", { "data-report-id": report.id, "data-review-action": "rejected" }));
    } else if (report.status === "valid") {
      actions.appendChild(actionButton("Resolve case", "review-report", "btn btn-primary btn-sm", { "data-report-id": report.id, "data-review-action": "resolved" }));
      actions.appendChild(actionButton("Reject report", "review-report", "btn btn-ghost btn-sm", { "data-report-id": report.id, "data-review-action": "rejected" }));
    }
    append(footer, el("span", "small", "Project #" + report.project_id + " · Message #" + report.message_id), actions);
    append(card, top, facts, reason, context, footer);
    return card;
  }

  function renderSettings() {
    const root = pageRoot();
    root.appendChild(pageHeading("PERSONAL", "Preferences", "Adjust how the workspace looks and review your account session.",
      actionGroup(actionButton("Sign out", "logout", "btn btn-outline"))));
    const panelBox = el("section", "surface-panel panel-body");
    const themeRow = el("div", "settings-row");
    const copy = el("div");
    append(copy, el("strong", "", "Color theme"), el("p", "", "Choose a light or dark workspace. Your choice is saved in this browser when storage is available."));
    const themeSelect = el("select", "select");
    [["light","Light"],["dark","Dark"],["system","System preference"]].forEach(([value,label]) => {
      const option = el("option","",label); option.value=value; themeSelect.appendChild(option);
    });
    let savedTheme = "system";\n    try { savedTheme = localStorage.getItem("tch-theme") || "system"; } catch (_) { /* The selector still works when storage is disabled. */ }\n    themeSelect.value = savedTheme;
    themeSelect.addEventListener("change", () => setTheme(themeSelect.value));
    append(themeRow, copy, themeSelect);
    const accountRow = el("div", "settings-row");
    const accountCopy = el("div");
    append(accountCopy, el("strong", "", "Account profile"),
      el("p", "", (state.user?.display_name || "Workspace member") + " · " + (state.user?.email || "No email available")),
      el("span", "small", "Your email address and system roles cannot be changed from this page."));
    append(accountRow, accountCopy, actionButton("Edit profile", "edit-profile", "btn btn-outline"));
    const passwordRow = el("div", "settings-row");
    const passwordCopy = el("div");
    append(passwordCopy, el("strong", "", "Password and sign-in"),
      el("p", "", "Change your password after verifying your current credential. Use 12–72 bytes."));
    append(passwordRow, passwordCopy, actionButton("Change password", "change-password", "btn btn-outline"));
    const securityRow = el("div", "settings-row");
    const securityCopy = el("div");
    append(securityCopy, el("strong", "", "Session and access"), el("p", "", "Every workspace API request checks your current account and resource permissions on the server."));
    append(securityRow, securityCopy, append(el("span", "activity-dot"), icon("shield")));
    append(panelBox, themeRow, accountRow, passwordRow, securityRow);
    root.appendChild(panelBox);
    root.appendChild(el("p", "small settings-note", "Password changes rotate the current session identifier and security token. Account recovery and invitation email delivery are not part of this development baseline."));
  }


  /**
   * Let a signed-in user update only their display name.
   * The API never accepts client-supplied user IDs or role changes.
   */
  function editProfileModal() {
    openFormModal("Edit account profile", "Choose the name your teammates see across the workspace.", [
      { name: "display_name", label: "Display name", type: "text", value: state.user?.display_name || "", required: true, maxLength: 80, full: true }
    ], "Save profile", async values => {
      const result = await request("/api/auth/profile", {
        method: "POST",
        body: { display_name: values.display_name.trim() }
      });
      if (result.user) state.user = result.user;
      const sidebarName = $("#sidebar-user-name");
      if (sidebarName) sidebarName.textContent = state.user?.display_name || "Your account";
      const sidebarRole = $("#sidebar-user-role");
      if (sidebarRole) sidebarRole.textContent = state.user?.email || "Workspace member";
      renderSettings();
      toast("Account profile updated.");
    });
  }

  /**
   * Require the current password and matching confirmation before changing credentials.
   * The server rotates the session ID and CSRF token after a successful update.
   */
  function changePasswordModal() {
    openFormModal("Change password", "Enter your current password, then choose a new password between 12 and 72 bytes.", [
      { name: "current_password", label: "Current password", type: "password", required: true, maxLength: 4096, full: true },
      { name: "new_password", label: "New password", type: "password", required: true, maxLength: 72, full: true, help: "Use a long, unique password that you do not use elsewhere." },
      { name: "confirm_password", label: "Confirm new password", type: "password", required: true, maxLength: 72, full: true }
    ], "Update password", async values => {
      const result = await request("/api/auth/password", {
        method: "POST",
        body: {
          current_password: values.current_password,
          new_password: values.new_password,
          confirm_password: values.confirm_password
        }
      });
      if (result.csrf_token) state.csrf = result.csrf_token;
      toast("Password changed. Your current session has been secured.");
    });
  }

  /* All mutation forms share one accessible modal builder and the same CSRF path. */
  let modalReturnFocus = null;

  function mountAssigneePicker(picker, controls) {
    const { field, control, search, results, selection } = picker;
    let timer = null;
    let controller = null;
    let sequence = 0;

    const showState = (message, kind = "") => {
      results.replaceChildren(el("p", "assignee-picker-state" + (kind ? " is-" + kind : ""), message));
    };
    const showSelection = person => {
      selection.replaceChildren();
      if (!person) {
        selection.appendChild(el("p", "small", "No assignee selected. The task can remain unassigned."));
        return;
      }
      const copy = el("div", "assignee-picker-selected-copy");
      append(copy, el("strong", "", person.display_name || person.email),
        el("span", "", person.email || ("User #" + person.user_id)));
      const clear = el("button", "btn btn-ghost btn-xs", "Clear");
      clear.type = "button";
      append(selection, avatar(person.display_name || person.email, "sm"), copy, clear);
      clear.addEventListener("click", () => {
        control.value = "";
        search.value = "";
        sequence++;
        if (controller) controller.abort();
        showSelection(null);
        showState("Search for an eligible project member, or leave this task unassigned.");
        search.focus();
      });
    };

    async function runSearch(query, expectedSequence) {
      const projectId = controls[field.projectField]?.value;
      if (!projectId) {
        showState("Select a project before searching for an assignee.");
        return;
      }
      controller = new AbortController();
      showState("Searching approved project members…", "loading");
      try {
        const data = await request("/api/projects/" + escapePath(projectId) + "/assignees?q=" + encodeURIComponent(query), { signal: controller.signal });
        if (expectedSequence !== sequence) return;
        const assignees = Array.isArray(data.assignees) ? data.assignees : [];
        results.replaceChildren();
        if (!assignees.length) {
          showState("No eligible member-level project users matched this search.");
          return;
        }
        assignees.forEach(person => {
          const button = el("button", "assignee-picker-option");
          button.type = "button";
          button.setAttribute("role", "option");
          button.setAttribute("aria-selected", control.value && Number(control.value) === Number(person.user_id) ? "true" : "false");
          append(button, avatar(person.display_name || person.email, "sm"),
            append(el("div", "assignee-picker-option-copy"),
              el("strong", "", person.display_name || person.email),
              el("span", "", person.email),
              el("small", "", "User #" + person.user_id)));
          button.addEventListener("click", () => {
            control.value = String(person.user_id);
            search.value = person.display_name || person.email;
            showSelection(person);
            $(".assignee-picker-option", results).forEach(option => {
              option.setAttribute("aria-selected", option === button ? "true" : "false");
            });
          });
          results.appendChild(button);
        });
      } catch (error) {
        if (controller?.signal.aborted || expectedSequence !== sequence) return;
        showState(error.message || "Assignee search failed. Try again.", "error");
      }
    }

    search.addEventListener("input", () => {
      sequence++;
      if (controller) controller.abort();
      control.value = "";
      showSelection(null);
      const query = search.value.trim();
      if (timer) window.clearTimeout(timer);
      if (!query) {
        showState("Search for an eligible project member, or leave this task unassigned.");
        return;
      }
      showState("Updating search…", "loading");
      const expectedSequence = sequence;
      timer = window.setTimeout(() => runSearch(query, expectedSequence), 250);
    });

    const projectControl = controls[field.projectField];
    if (projectControl && typeof projectControl.addEventListener === "function") {
      projectControl.addEventListener("change", () => {
        sequence++;
        if (controller) controller.abort();
        if (timer) window.clearTimeout(timer);
        control.value = "";
        search.value = "";
        showSelection(null);
        showState("Search for an eligible member of the selected project.");
      });
    }

    if (field.initialAssignee) {
      const initial = field.initialAssignee;
      control.value = String(initial.user_id);
      search.value = initial.display_name || initial.email || ("User #" + initial.user_id);
      showSelection(initial);
    } else {
      showSelection(null);
      showState("Search for an eligible project member, or leave this task unassigned.");
    }
  }

  function openFormModal(title, description, fields, submitLabel, onSubmit) {
    modalReturnFocus = document.activeElement;
    const overlay = el("div", "tch-modal-overlay");
    const modal = el("section", "tch-modal");
    modal.setAttribute("role", "dialog"); modal.setAttribute("aria-modal", "true");
    const dialogId = "modal-title-" + Math.random().toString(36).slice(2, 8);
    modal.setAttribute("aria-labelledby", dialogId);
    const header = el("div", "tch-modal-header");
    const titleBlock = el("div");
    const heading = el("h2", "", title); heading.id = dialogId;
    append(titleBlock, heading, el("p", "", description));
    const close = actionButton("×", "close-modal", "btn btn-ghost btn-icon", { "aria-label": "Close dialog" });
    append(header, titleBlock, close);
    const form = el("form", "tch-modal-body");
    const error = el("div", "inline-error"); error.hidden = true; error.setAttribute("role", "alert");
    const controls = {};
    const memberPickers = [];
    fields.forEach(field => {
      const wrap = el("div", "field" + (field.full ? " field-full" : ""));
      const label = el("label", "label", field.label);
      const id = "field-" + field.name + "-" + Math.random().toString(36).slice(2, 6);
      let control;
      if (field.type === "member-picker") {
        control = el("input", "input");
        control.type = "hidden";
        control.id = id;
        control.name = field.name;
        control.value = field.value === undefined ? "" : String(field.value);
        const search = el("input", "input assignee-picker-search");
        search.type = "search";
        search.id = id + "-search";
        search.maxLength = 80;
        search.autocomplete = "off";
        search.placeholder = field.placeholder || "Search by name, email, or user ID";
        search.setAttribute("aria-label", field.label);
        const results = el("div", "assignee-picker-results");
        results.setAttribute("role", "listbox");
        results.setAttribute("aria-label", "Eligible task assignees");
        results.setAttribute("aria-live", "polite");
        const selection = el("div", "assignee-picker-selection");
        selection.appendChild(el("p", "small", "No assignee selected. The task can remain unassigned."));
        label.htmlFor = search.id;
        append(wrap, label, control, search, results, selection);
        memberPickers.push({ field, control, search, results, selection });
        if (field.help) wrap.appendChild(el("span", "help", field.help)).id = id + "-help";
        search.setAttribute("aria-describedby", id + "-help");
        controls[field.name] = control;
        form.appendChild(wrap);
        return;
      }
      label.htmlFor = id;
      if (field.type === "select") {
        control = el("select", "select");
        (field.options || []).forEach(optionData => {
          const option = el("option", "", optionData.label); option.value = optionData.value;
          control.appendChild(option);
        });
      } else if (field.type === "textarea") {
        control = el("textarea", "textarea"); control.rows = field.rows || 4;
      } else {
        control = el("input", "input"); control.type = field.type || "text";
      }
      control.id = id; control.name = field.name;
      if (field.placeholder) control.placeholder = field.placeholder;
      if (field.required) control.required = true;
      if (field.maxLength) control.maxLength = field.maxLength;
      if (field.minLength) control.minLength = field.minLength;
      if (field.value !== undefined) control.value = field.value;
      if (field.min) control.min = field.min;
      if (field.step) control.step = field.step;
      if (field.help) control.setAttribute("aria-describedby", id + "-help");
      controls[field.name] = control;
      append(wrap, label, control);
      if (field.help) wrap.appendChild(el("span", "help", field.help)).id = id + "-help";
      form.appendChild(wrap);
    });
    memberPickers.forEach(picker => mountAssigneePicker(picker, controls));
    form.appendChild(error);
    const footer = el("div", "tch-modal-footer");
    append(footer, actionButton("Cancel", "close-modal", "btn btn-outline"));
    const submit = el("button", "btn btn-primary", submitLabel); submit.type = "submit";
    footer.appendChild(submit);
    append(modal, header, form, footer); overlay.appendChild(modal);
    $("#modal-root").replaceChildren(overlay);
    overlay.addEventListener("click", event => { if (event.target === overlay) closeModal(); });
    overlay.addEventListener("keydown", event => handleDialogKeydown(event, overlay));
    form.addEventListener("submit", async event => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      submit.disabled = true; submit.textContent = "Saving…"; error.hidden = true;
      const values = {};
      Object.entries(controls).forEach(([name, control]) => { values[name] = control.value; });
      try {
        await onSubmit(values);
        closeModal();
      } catch (failure) {
        error.textContent = failure.message || "The request could not be completed.";
        error.hidden = false;
      } finally {
        submit.disabled = false; submit.textContent = submitLabel;
      }
    });
    const first = Object.values(controls)[0];
    if (first) first.focus();
    return { overlay, modal, form, controls };
  }

  function handleDialogKeydown(event, overlay) {
    if (event.key === "Escape") {
      event.preventDefault();
      closeModal();
      return;
    }
    if (event.key !== "Tab") return;
    const focusable = Array.from(overlay.querySelectorAll("a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex='-1'])"))
      .filter(node => !node.hidden && node.getAttribute("aria-hidden") !== "true");
    if (!focusable.length) { event.preventDefault(); return; }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }

  function closeModal() {
    $("#modal-root").replaceChildren();
    if (modalReturnFocus && modalReturnFocus.isConnected && typeof modalReturnFocus.focus === "function") modalReturnFocus.focus();
    modalReturnFocus = null;
  }

  function createTeamModal() {
    openFormModal("Create a team", "Give your collaborators a shared space. You will become its owner.", [
      { name: "name", label: "Team name", type: "text", placeholder: "e.g. Platform Engineering", required: true, maxLength: 100, full: true }
    ], "Create team", async values => {
      const result = await request("/api/teams", { method: "POST", body: { name: values.name.trim() } });
      await refreshWorkspace(false);
      toast("Team created successfully.");
      if (result.team) await openTeam(result.team.id);
      else navigate("teams");
    });
  }

  function editTeamModal(teamId) {
    const team = teamById(teamId);
    if (!team || team.role !== "owner") {
      toast("Only the team owner can rename this team.", "warning");
      return;
    }
    openFormModal("Edit team", "Update the team name. Existing project links and team membership remain unchanged.", [
      { name: "name", label: "Team name", type: "text", required: true, maxLength: 100, value: team.name, full: true }
    ], "Save team", async values => {
      await request("/api/teams/" + escapePath(team.id) + "/edit", { method: "POST", body: { name: values.name.trim() } });
      await refreshWorkspace(false);
      const updated = teamById(team.id);
      toast("Team details updated.");
      if (updated) await openTeam(updated.id);
    });
  }

  function createProjectModal(preselectedTeamId) {
    const manageableTeams = state.teams.filter(team => canManageTeam(team.id));
    if (!manageableTeams.length) { toast("Project creation requires a team owner or manager. Create a new team or ask a manager for access.", "warning"); if (!state.teams.length) createTeamModal(); return; }
    const options = manageableTeams.map(team => ({ value: String(team.id), label: team.name + " · " + (team.role || "member") }));
    openFormModal("Create a project", "Projects belong to a team and inherit its membership boundaries.", [
      { name: "team_id", label: "Team", type: "select", options, required: true, value: preselectedTeamId && manageableTeams.some(team => Number(team.id) === Number(preselectedTeamId)) ? String(preselectedTeamId) : String(manageableTeams[0].id), full: true },
      { name: "name", label: "Project name", type: "text", placeholder: "e.g. Website redesign", required: true, maxLength: 120, full: true },
      { name: "description", label: "Description", type: "textarea", placeholder: "What are you building together?", maxLength: 5000, full: true }
    ], "Create project", async values => {
      const result = await request("/api/teams/" + escapePath(values.team_id) + "/projects", { method: "POST", body: { name: values.name.trim(), description: values.description.trim() || null } });
      await refreshWorkspace(false);
      toast("Project created successfully.");
      if (result.project) await openProject(result.project, true);
      else navigate("projects");
    });
  }

  async function editProjectModal(projectId) {
    let project = state.selectedProject && Number(state.selectedProject.id) === Number(projectId)
      ? state.selectedProject
      : projectById(projectId);
    if (!project || !canManageTeam(project.team_id)) {
      toast("Project editing requires an active team owner or manager role.", "warning");
      return;
    }
    try {
      // Fetch the current authorized record so a summary response never overwrites its description with an empty value.
      const detail = await request("/api/projects/" + escapePath(project.id));
      project = detail.project || project;
      state.selectedProject = project;
    } catch (error) {
      toast(error.message || "Project details could not be loaded.", "error");
      return;
    }
    const tab = state.projectTab;
    openFormModal("Edit project", "Update project details without changing its members, tasks, or conversation history.", [
      { name: "name", label: "Project name", type: "text", required: true, maxLength: 120, value: project.name, full: true },
      { name: "description", label: "Description", type: "textarea", maxLength: 5000, value: project.description || "", full: true }
    ], "Save project", async values => {
      const result = await request("/api/projects/" + escapePath(project.id) + "/edit", {
        method: "POST",
        body: { name: values.name.trim(), description: values.description.trim() || null }
      });
      await refreshWorkspace(false);
      const updated = projectById(project.id) || result.project;
      toast("Project details updated.");
      if (updated) await openProject(updated, true, tab);
    });
  }

  function createTaskModal(preselectedProjectId) {
    const writableProjects = state.projects.filter(project => project.access_level !== "viewer");
    if (!writableProjects.length) { toast("Task creation requires member-level project access.", "warning"); return; }
    const options = writableProjects.map(project => ({ value: String(project.id), label: project.name }));
    const defaultDue = new Date(Date.now() + 86400000);
    const localDue = new Date(defaultDue.getTime() - defaultDue.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    openFormModal("Create a task", "Make the next action clear, assignable, and easy to track.", [
      { name: "project_id", label: "Project", type: "select", options, required: true, value: preselectedProjectId && writableProjects.some(project => Number(project.id) === Number(preselectedProjectId)) ? String(preselectedProjectId) : String(writableProjects[0].id), full: true },
      { name: "title", label: "Task title", type: "text", placeholder: "What needs to get done?", required: true, maxLength: 160, full: true },
      { name: "description", label: "Description", type: "textarea", placeholder: "Optional context or acceptance criteria", maxLength: 10000, full: true },
      { name: "priority", label: "Priority", type: "select", options: [{ value: "low", label: "Low" }, { value: "normal", label: "Normal" }, { value: "high", label: "High" }, { value: "urgent", label: "Urgent" }], value: "normal" },
      { name: "due_at", label: "Due date (your local time)", type: "datetime-local", value: localDue, help: "Converted to UTC before it is sent to the server." },
      { name: "assigned_to", label: "Assignee (optional)", type: "member-picker", projectField: "project_id", placeholder: "Search by name, email, or user ID", help: "Only approved member-level project users can be assigned. Leave empty to keep this task unassigned." }
    ], "Create task", async values => {
      await request("/api/projects/" + escapePath(values.project_id) + "/tasks", {
        method: "POST",
        body: { title: values.title.trim(), description: values.description.trim() || null, priority: values.priority, due_at: dateForApi(values.due_at), assigned_to: values.assigned_to ? Number(values.assigned_to) : null }
      });
      await refreshWorkspace(false);
      toast("Task created successfully.");
      if (state.page === "project" && state.selectedProject) await renderProject(state.selectedProject.id, "tasks");
      else navigate("tasks", false);
    });
  }

  function addProjectMemberModal(projectId) {
    modalReturnFocus = document.activeElement;
    let selected = null;
    let searchTimer = null;
    let searchController = null;
    let searchSequence = 0;

    const overlay = el("div", "tch-modal-overlay");
    const modal = el("section", "tch-modal member-picker-modal");
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "member-picker-title");
    const header = el("div", "tch-modal-header");
    const headingBlock = el("div");
    const heading = el("h2", "", "Add a project member");
    heading.id = "member-picker-title";
    append(headingBlock, heading, el("p", "", "Find an active team member by name, email, or exact user ID. Their access remains pending until approved."));
    append(header, headingBlock, actionButton("×", "close-modal", "btn btn-ghost btn-icon", { "aria-label": "Close member search" }));

    const form = el("form", "tch-modal-body member-picker-body");
    const searchField = el("div", "field field-full");
    const searchLabel = el("label", "label", "Search team members");
    const search = el("input", "input");
    search.type = "search";
    search.name = "member_search";
    search.maxLength = 80;
    search.autocomplete = "off";
    search.placeholder = "Name, email address, or user ID";
    search.required = true;
    search.setAttribute("aria-label", "Search active team members");
    searchLabel.htmlFor = "member-search-input";
    search.id = "member-search-input";
    const searchHelp = el("span", "help", "Only active members of this project's team are shown. Approved project members are excluded.");
    searchHelp.id = "member-search-help";
    search.setAttribute("aria-describedby", "member-search-help");
    append(searchField, searchLabel, search, searchHelp);

    const resultHeading = el("div", "member-picker-results-heading");
    append(resultHeading, el("strong", "", "Matching members"), el("span", "small", "Up to 12 results"));
    const results = el("div", "member-picker-results");
    results.setAttribute("role", "listbox");
    results.setAttribute("aria-label", "Assignable team members");
    results.setAttribute("aria-live", "polite");
    const selection = el("div", "member-picker-selection");
    selection.setAttribute("aria-live", "polite");
    selection.appendChild(el("p", "small", "Choose a person from the search results."));
    const accessField = el("div", "field field-full");
    const accessLabel = el("label", "label", "Project access");
    const access = el("select", "select");
    accessLabel.htmlFor = "member-picker-access";
    access.id = "member-picker-access";
    [["member", "Member — can contribute"], ["viewer", "Viewer — read only"]].forEach(([value, label]) => {
      const option = el("option", "", label);
      option.value = value;
      access.appendChild(option);
    });
    append(accessField, accessLabel, access);
    const error = el("div", "inline-error");
    error.hidden = true;
    error.setAttribute("role", "alert");
    const footer = el("div", "tch-modal-footer");
    append(footer, actionButton("Cancel", "close-modal", "btn btn-outline"));
    const submit = el("button", "btn btn-primary", "Request access");
    submit.type = "submit";
    submit.disabled = true;
    footer.appendChild(submit);
    append(form, searchField, resultHeading, results, selection, accessField, error, footer);
    append(modal, header, form);
    overlay.appendChild(modal);
    $("#modal-root").replaceChildren(overlay);
    overlay.addEventListener("click", event => { if (event.target === overlay) closeModal(); });
    overlay.addEventListener("keydown", event => handleDialogKeydown(event, overlay));
    search.focus();

    function showSearchState(message, kind) {
      results.replaceChildren(el("p", "member-picker-state" + (kind ? " is-" + kind : ""), message));
    }

    function renderSelection() {
      selection.replaceChildren();
      if (!selected) {
        selection.appendChild(el("p", "small", "Choose a person from the search results."));
        submit.disabled = true;
        return;
      }
      const person = el("div", "member-picker-selected");
      append(person, avatar(selected.display_name || selected.email, "md"),
        append(el("div", "row-main"), el("strong", "", selected.display_name || selected.email),
          el("span", "row-subtitle", selected.email + " · User #" + selected.user_id)));
      const statusText = selected.existing_status === "pending"
        ? "A pending request exists. Submitting will update its access level."
        : "Ready to request project access.";
      append(selection, person, el("p", "small", statusText));
      submit.disabled = false;
    }

    async function searchCandidates(value) {
      const query = value.trim();
      const sequence = ++searchSequence;
      if (searchController) searchController.abort();
      if (query.length < 1) {
        showSearchState("Type a name, email, or user ID to search.", "");
        return;
      }
      searchController = new AbortController();
      showSearchState("Searching active team members…", "loading");
      try {
        const data = await request("/api/projects/" + escapePath(projectId) + "/members/candidates?q=" + encodeURIComponent(query), { signal: searchController.signal });
        if (sequence !== searchSequence) return;
        const candidates = Array.isArray(data.candidates) ? data.candidates : [];
        results.replaceChildren();
        if (!candidates.length) {
          showSearchState("No assignable team members matched this search.", "");
          return;
        }
        candidates.forEach(person => {
          const button = el("button", "member-picker-option");
          button.type = "button";
          button.setAttribute("role", "option");
          button.setAttribute("aria-selected", selected && Number(selected.user_id) === Number(person.user_id) ? "true" : "false");
          const copy = el("div", "member-picker-option-copy");
          append(copy, el("strong", "", person.display_name || person.email),
            el("span", "", person.email),
            el("small", "", "Team " + person.team_role + " · User #" + person.user_id + (person.existing_status === "pending" ? " · Pending request" : "")));
          append(button, avatar(person.display_name || person.email, "md"), copy, el("span", "member-picker-chevron", "＋"));
          button.addEventListener("click", () => {
            selected = person;
            error.hidden = true;
            renderSelection();
            $(".member-picker-option", results).forEach(option => {
              option.setAttribute("aria-selected", option === button ? "true" : "false");
            });
          });
          results.appendChild(button);
        });
      } catch (errorValue) {
        if (searchController?.signal.aborted || sequence !== searchSequence) return;
        showSearchState(errorValue.message || "Team member search failed. Try again.", "error");
      }
    }

    search.addEventListener("input", () => {
      // Invalidate an in-flight response immediately, before the debounce timer runs.
      searchSequence++;
      if (searchController) searchController.abort();
      selected = null;
      renderSelection();
      error.hidden = true;
      showSearchState(search.value.trim() ? "Updating search…" : "Type a name, email, or user ID to search.", search.value.trim() ? "loading" : "");
      if (searchTimer) window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(() => searchCandidates(search.value), 250);
    });
    form.addEventListener("submit", async event => {
      event.preventDefault();
      if (!selected || submit.disabled) return;
      submit.disabled = true;
      submit.textContent = "Requesting…";
      error.hidden = true;
      try {
        await request("/api/projects/" + escapePath(projectId) + "/members", {
          method: "POST",
          body: { user_id: Number(selected.user_id), access_level: access.value }
        });
        closeModal();
        toast("Membership request created.");
        await renderProject(projectId, "members");
      } catch (errorValue) {
        error.textContent = errorValue.message || "The membership request could not be created.";
        error.hidden = false;
      } finally {
        if ($("#member-picker-title")) {
          submit.disabled = !selected;
          submit.textContent = "Request access";
        }
      }
    });
  }

  async function setTaskStatus(button, nextStatus) {
    const projectId = button.dataset.projectId;
    const taskId = button.dataset.taskId;
    button.disabled = true;
    try {
      await request("/api/projects/" + escapePath(projectId) + "/tasks/" + escapePath(taskId) + "/status", { method: "POST", body: { status: nextStatus } });
      await refreshWorkspace(false);
      toast("Task status updated.");
      if (state.page === "project" && state.selectedProject) await renderProject(state.selectedProject.id, state.projectTab);
    } catch (error) { toast(error.message, "error"); }
    finally { button.disabled = false; }
  }

  async function markNotificationRead(id) {
    const result = await request("/api/notifications/" + escapePath(id) + "/read", { method: "POST", body: {} });
    if (Number.isFinite(Number(result.unread_count))) state.unreadNotificationCount = Number(result.unread_count);
    const item = state.notifications.find(notification => Number(notification.id) === Number(id));
    if (item) item.is_read = true;
    updateNavigationCounts();
    if (state.page === "notifications") renderNotifications();
    else if (state.page === "overview") renderOverview();
  }

  function startNotificationPolling() {
    stopNotificationPolling();
    state.notificationTimer = window.setInterval(() => {
      if (!document.hidden) void pollNotifications();
    }, 30000);
  }

  function stopNotificationPolling() {
    if (state.notificationTimer) window.clearInterval(state.notificationTimer);
    state.notificationTimer = null;
    state.notificationBusy = false;
  }

  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) void pollNotifications();
  });

  async function pollNotifications() {
    if (!state.user || document.hidden || $("#application").hidden || state.notificationBusy) return;
    state.notificationBusy = true;
    try {
      const result = await request("/api/notifications?limit=20");
      const incoming = Array.isArray(result.notifications) ? result.notifications : [];
      const previousSnapshot = JSON.stringify(state.notifications.map(item => [item.id, item.is_read, item.title, item.message]));
      const nextSnapshot = JSON.stringify(incoming.map(item => [item.id, item.is_read, item.title, item.message]));
      state.notifications = incoming;
      state.unreadNotificationCount = Number.isFinite(Number(result.unread_count))
        ? Number(result.unread_count)
        : incoming.filter(item => !item.is_read).length;
      updateNavigationCounts();
      if (state.page === "notifications" && previousSnapshot !== nextSnapshot) renderNotifications();
    } catch (error) {
      // A single notification poll failure should not interrupt the active workspace.
      if (error.status === 401) showAuth();
    } finally {
      state.notificationBusy = false;
    }
  }

  async function markAllVisibleRead() {
    const unread = state.notifications.filter(item => !item.is_read);
    for (const item of unread) {
      try {
        const result = await request("/api/notifications/" + escapePath(item.id) + "/read", { method: "POST", body: {} });
        item.is_read = true;
        if (Number.isFinite(Number(result.unread_count))) state.unreadNotificationCount = Number(result.unread_count);
      } catch (_) { /* Continue marking other visible items even if one has expired. */ }
    }
    // Refresh the authoritative unread count, including unread items outside this visible page.
    try {
      const refreshed = await request("/api/notifications?limit=20");
      state.notifications = Array.isArray(refreshed.notifications) ? refreshed.notifications : [];
      state.unreadNotificationCount = Number.isFinite(Number(refreshed.unread_count))
        ? Number(refreshed.unread_count)
        : state.notifications.filter(item => !item.is_read).length;
    } catch (_) { /* Keep the latest locally confirmed state if refresh fails. */ }
    updateNavigationCounts();
    renderNotifications();
    toast("Visible notifications marked as read.");
  }

  async function inviteMember(teamId) {
    if (teamId) { await openTeam(teamId); return; }
    const teamOptions = state.teams.map(team => ({ value: String(team.id), label: team.name }));
    openFormModal("Invite a team member", "Create a time-limited invitation for an existing email address.", [
      { name: "team_id", label: "Team", type: "select", options: teamOptions, required: true },
      { name: "email", label: "Email address", type: "email", placeholder: "colleague@example.com", required: true, maxLength: 254, full: true }
    ], "Create invitation", async values => {
      const result = await request("/api/teams/" + escapePath(values.team_id) + "/invitations", { method: "POST", body: { email: values.email.trim() } });
      closeModal();
      showInvitationToken(result.invitation?.token || "", values.email.trim());
    });
  }

  /* Delegate UI actions centrally so newly rendered rows work without rebinding. */
  document.addEventListener("click", async event => {
    const pageButton = event.target.closest("[data-page]");
    if (pageButton) { navigate(pageButton.dataset.page); return; }
    const openProjectButton = event.target.closest("[data-open-project]");
    if (openProjectButton) {
      const project = projectById(openProjectButton.dataset.openProject);
      if (project) openProject(project);
      return;
    }
    const openTeamButton = event.target.closest("[data-open-team]");
    if (openTeamButton) { openTeam(openTeamButton.dataset.openTeam); return; }
    const action = event.target.closest("[data-action]");
    if (!action) return;
    const name = action.dataset.action;
    try {
      if (name === "create-team") createTeamModal();
      else if (name === "refresh-admin-users") await renderAdminUsers();
      else if (name === "connect-project-github") connectProjectRepositoryModal(action.dataset.projectId, action.dataset.repository || "");
      else if (name === "unlink-project-github") await unlinkProjectRepository(action.dataset.projectId);
      else if (name === "admin-user-status") changeAdminUserStatusModal(action.dataset.userId, action.dataset.nextStatus, action.dataset.displayName || "this account");
      else if (name === "admin-user-audit") await showAdminUserAuditModal(action.dataset.userId, action.dataset.displayName || "this account");
      else if (name === "edit-profile") editProfileModal();
      else if (name === "change-password") changePasswordModal();
      else if (name === "edit-team") editTeamModal(action.dataset.teamId);
      else if (name === "edit-project") await editProjectModal(action.dataset.projectId);
      else if (name === "archive-project") {
        const project = state.selectedProject;
        if (!project || !window.confirm("Archive this project? It will leave active lists, but its tasks, memberships, and chat history will be preserved. A team owner or manager can restore it later.")) return;
        await request("/api/projects/" + escapePath(action.dataset.projectId) + "/archive", { method: "POST", body: {} });
        const team = teamById(project.team_id);
        await refreshWorkspace(false);
        toast("Project archived. Its history is preserved.");
        if (team) await openTeam(team.id);
        else navigate("projects");
      }
      else if (name === "restore-project") {
        const teamId = action.dataset.teamId;
        if (!window.confirm("Restore this project to the active project list? The team's active project quota will be checked.")) return;
        await request("/api/projects/" + escapePath(action.dataset.projectId) + "/restore", { method: "POST", body: {} });
        await refreshWorkspace(false);
        toast("Project restored successfully.");
        if (teamId) await openTeam(teamId);
        else navigate("projects");
      }
      else if (name === "accept-invitation") acceptInvitationModal();
      else if (name === "create-project") createProjectModal(action.dataset.teamId);
      else if (name === "create-task") createTaskModal(action.dataset.projectId);
      else if (name === "invite-member") inviteMember(action.dataset.teamId);
      else if (name === "navigate-projects") navigate("projects");
      else if (name === "navigate-github") navigate("github");
      else if (name === "navigate-teams") navigate("teams");
      else if (name === "navigate-tasks") navigate("tasks");
      else if (name === "navigate-notifications") navigate("notifications");
      else if (name === "refresh") await refreshWorkspace(true);
      else if (name === "refresh-github") await loadGitHubActivity(true);
      else if (name === "refresh-moderation") await renderModeration();
      else if (name === "review-report") {
        const reviewAction = action.dataset.reviewAction;
        const labels = { valid: "mark this report as valid", rejected: "reject this report", resolved: "resolve this case" };
        if (!window.confirm("Are you sure you want to " + labels[reviewAction] + "?")) return;
        await request("/api/reports/" + escapePath(action.dataset.reportId) + "/review", { method: "POST", body: { action: reviewAction } });
        toast("Report review saved.");
        await renderModeration();
      }
      else if (name === "logout") await logout();
      else if (name === "close-modal") closeModal();
      else if (name === "copy-invitation-token") {
        const token = action.dataset.token || "";
        try { await navigator.clipboard.writeText(token); toast("Invitation token copied."); }
        catch (_) { const box = $("textarea", $("#modal-root")); if (box) { box.focus(); box.select(); document.execCommand("copy"); toast("Select and copy the invitation token if clipboard access is blocked.", "warning"); } }
      }
      else if (name === "open-team") await openTeam(action.dataset.teamId);
      else if (name === "revoke-invitation") {
        if (!window.confirm("Revoke this pending invitation? The token will no longer work.")) return;
        await request("/api/teams/" + escapePath(action.dataset.teamId) + "/invitations/" + escapePath(action.dataset.invitationId), { method: "DELETE" });
        const team = teamById(action.dataset.teamId);
        if (team) await renderPendingInvitations(team, $("#pending-invitations-list")?.parentElement || $("#page-content"));
        toast("Invitation revoked.");
      }
      else if (name === "open-project") {
        const project = projectById(action.dataset.projectId);
        if (project) await openProject(project);
      }
      else if (name === "open-project-chat") {
        const project = projectById(action.dataset.projectId);
        if (project) await openProject(project, true, "chat");
      }
      else if (name === "task-details") {
        await openTaskDetails(action.dataset.projectId, action.dataset.taskId);
      }
      else if (name === "toggle-subtask") {
        await request("/api/projects/" + escapePath(action.dataset.projectId) + "/tasks/" + escapePath(action.dataset.taskId) + "/subtasks/" + escapePath(action.dataset.subtaskId) + "/toggle", { method: "POST", body: {} });
        await openTaskDetails(action.dataset.projectId, action.dataset.taskId);
      }
      else if (name === "delete-subtask") {
        if (!window.confirm("Remove this checklist item?")) return;
        await request("/api/projects/" + escapePath(action.dataset.projectId) + "/tasks/" + escapePath(action.dataset.taskId) + "/subtasks/" + escapePath(action.dataset.subtaskId) + "/delete", { method: "POST", body: {} });
        await openTaskDetails(action.dataset.projectId, action.dataset.taskId);
        toast("Checklist item removed.");
      }
      else if (name === "edit-task-comment") {
        const row = action.closest(".task-comment-row");
        const current = $(".task-comment-body", row)?.textContent || "";
        const body = window.prompt("Edit your comment (within the 15-minute edit window):", current);
        if (body === null || !body.trim()) return;
        await request("/api/projects/" + escapePath(action.dataset.projectId) + "/tasks/" + escapePath(action.dataset.taskId) + "/comments/" + escapePath(action.dataset.commentId) + "/edit", { method: "POST", body: { body: body.trim() } });
        await openTaskDetails(action.dataset.projectId, action.dataset.taskId);
        toast("Comment updated.");
      }
      else if (name === "delete-task-comment") {
        if (!window.confirm("Delete your comment? The thread will retain a deletion marker.")) return;
        await request("/api/projects/" + escapePath(action.dataset.projectId) + "/tasks/" + escapePath(action.dataset.taskId) + "/comments/" + escapePath(action.dataset.commentId) + "/delete", { method: "POST", body: {} });
        await openTaskDetails(action.dataset.projectId, action.dataset.taskId);
        toast("Comment deleted.");
      }
      else if (name === "project-tab") {
        if (state.selectedProject) await openProject(state.selectedProject, true, action.dataset.projectTab);
      }
      else if (name === "toggle-task") {
        const task = state.tasks.find(item => Number(item.id) === Number(action.dataset.taskId));
        if (task) await setTaskStatus(action, task.status === "done" ? "todo" : "done");
      }
      else if (name === "set-task-status") await setTaskStatus(action, action.dataset.nextStatus);
      else if (name === "mark-notification-read") await markNotificationRead(action.dataset.notificationId);
      else if (name === "mark-all-visible-read") await markAllVisibleRead();
      else if (name === "add-project-member") addProjectMemberModal(action.dataset.projectId);
      else if (name === "approve-member") {
        await request("/api/projects/" + escapePath(action.dataset.projectId) + "/members/" + escapePath(action.dataset.userId) + "/approve", { method: "POST", body: {} });
        toast("Project membership approved."); await renderProject(action.dataset.projectId, "members");
      }
      else if (name === "change-member-access") {
        const accessLevel = action.dataset.accessLevel;
        const explanation = accessLevel === "viewer"
          ? "Make this member read-only? They will no longer be able to edit project tasks or send chat messages."
          : "Grant this member edit access to project tasks and chat?";
        if (!window.confirm(explanation)) return;
        const result = await request("/api/projects/" + escapePath(action.dataset.projectId) + "/members/" + escapePath(action.dataset.userId) + "/access", {
          method: "POST", body: { access_level: accessLevel }
        });
        toast(result.changed === false ? "This member already has that access level." : (accessLevel === "viewer" ? "Member is now read-only." : "Member edit access granted."));
        await renderProject(action.dataset.projectId, "members");
      }
      else if (name === "revoke-member") {
        if (!window.confirm("Revoke this member's project access?")) return;
        await request("/api/projects/" + escapePath(action.dataset.projectId) + "/members/" + escapePath(action.dataset.userId) + "/revoke", { method: "POST", body: {} });
        toast("Project access revoked."); await renderProject(action.dataset.projectId, "members");
      }
      else if (name === "edit-message") {
        const messageId = action.dataset.messageId;
        const current = $("#chat-message-" + messageId + " .chat-bubble")?.textContent || "";
        openFormModal("Edit message", "You can edit your own message within 15 minutes, unless it has an unresolved report.", [
          { name: "body", label: "Message", type: "textarea", value: current, required: true, maxLength: 2000, full: true }
        ], "Save changes", async values => {
          await request("/api/projects/" + escapePath(state.selectedProject.id) + "/chat/" + escapePath(messageId) + "/edit", { method: "POST", body: { body: values.body.trim() } });
          await pollChat(true); toast("Message updated.");
        });
      }
      else if (name === "delete-message") {
        if (!window.confirm("Delete this message? A reported message's saved moderation context is retained.")) return;
        await request("/api/projects/" + escapePath(state.selectedProject.id) + "/chat/" + escapePath(action.dataset.messageId) + "/delete", { method: "POST", body: {} });
        await pollChat(true); toast("Message deleted.");
      }
      else if (name === "report-message") {
        const reason = window.prompt("Briefly explain why this message should be reviewed (maximum 500 characters):");
        if (!reason || !reason.trim()) return;
        await request("/api/projects/" + escapePath(state.selectedProject.id) + "/chat/" + escapePath(action.dataset.messageId) + "/report", { method: "POST", body: { reason: reason.trim().slice(0, 500) } });
        toast("Report submitted for review.");
      }
    } catch (error) {
      toast(error.message || "The action could not be completed.", "error");
    }
  });

  /* Theme preference is local and never changes server-side permissions. */
  function setTheme(value) {
    let theme = value;
    if (theme === "system") theme = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    document.documentElement.dataset.theme = theme === "dark" ? "dark" : "light";
    try { localStorage.setItem("tch-theme", value); } catch (_) { /* Private browsing can disable storage. */ }
    $$("[data-theme-toggle] svg").forEach(svg => svg.replaceWith(icon(theme === "dark" ? "moon" : "sun")));
  }

  function initializeTheme() {
    let saved = "system";
    try { saved = localStorage.getItem("tch-theme") || "system"; } catch (_) {}
    setTheme(saved);
    const button = $("[data-theme-toggle]");
    if (button) button.addEventListener("click", () => setTheme(document.documentElement.dataset.theme === "dark" ? "light" : "dark"));
    const passwordToggle = $("[data-password-toggle]");
    if (passwordToggle) passwordToggle.addEventListener("click", () => {
      const input = $("#login-password");
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      passwordToggle.textContent = show ? "Hide" : "Show";
      passwordToggle.setAttribute("aria-label", show ? "Hide password" : "Show password");
      passwordToggle.setAttribute("aria-pressed", show ? "true" : "false");
    });
  }

  function openSidebar() {
    $("#app-sidebar").classList.add("is-open");
    $("#sidebar-backdrop").hidden = false;
    $("[data-sidebar-toggle]").setAttribute("aria-expanded", "true");
  }
  function closeSidebar() {
    $("#app-sidebar").classList.remove("is-open");
    $("#sidebar-backdrop").hidden = true;
    const trigger = $("[data-sidebar-toggle]");
    if (trigger) trigger.setAttribute("aria-expanded", "false");
  }

  function renderFatal(message) {
    const root = pageRoot();
    root.appendChild(emptyState("Workspace data is unavailable", message || "Check the database configuration and applied migrations, then try again.", "Retry", "refresh"));
  }

  $("#login-form").addEventListener("submit", login);
  $("#register-form").addEventListener("submit", registerAccount);
  $("#verification-resend-form").addEventListener("submit", resendVerification);
  $("#password-reset-request-form").addEventListener("submit", requestPasswordReset);
  $("#password-reset-form").addEventListener("submit", completePasswordReset);
  $("#show-password-reset-request").addEventListener("click", () => {
    $("#password-reset-email").value = $("#login-email").value.trim();
    showAuthMode("password-reset-request");
  });
  $("#show-login-from-password-reset-request").addEventListener("click", () => showAuthMode("login"));
  $("#show-login-from-password-reset").addEventListener("click", () => {
    state.passwordResetToken = "";
    history.replaceState({}, "", "/login");
    showAuthMode("login");
  });
  $("#verify-submit").addEventListener("click", verifyEmail);
  $("#show-register").addEventListener("click", () => showAuthMode("register"));
  $("#show-resend-verification").addEventListener("click", () => showAuthMode("resend"));
  $("#show-login-from-register").addEventListener("click", () => showAuthMode("login"));
  $("#show-login-from-resend").addEventListener("click", () => showAuthMode("login"));
  $("#show-login-from-verify").addEventListener("click", () => {
    state.verificationToken = "";
    history.replaceState({}, "", "/login");
    showAuthMode("login");
  });
  document.addEventListener("click", event => {
    if (event.target.closest("[data-sidebar-close]")) closeSidebar();
  });
  window.addEventListener("hashchange", routeFromHash);
  window.addEventListener("popstate", routeFromHash);
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) return;
    if (state.page === "project" && state.projectTab === "chat") pollChat(false);
  });
  initializeTheme();
  window.TCHWorkspace = { refresh: refreshWorkspace, navigate, state };
  boot();
})();
