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
    user: null,
    dashboard: null,
    teams: [],
    projects: [],
    tasks: [],
    notifications: [],
    page: "overview",
    selectedProject: null,
    projectTab: "overview",
    chatCursor: 0,
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
    $("#application").hidden = true;
    $("#auth-screen").hidden = false;
    $("#login-password").value = "";
    $("#login-error").hidden = true;
  }

  function showApplication() {
    $("#auth-screen").hidden = true;
    $("#application").hidden = false;
    $("#sidebar-user-name").textContent = state.user?.display_name || state.user?.email || "Your account";
    $("#sidebar-user-role").textContent = (state.user?.roles || []).includes("site_admin") ? "Site administrator" : "Workspace member";
    $("#user-avatar").textContent = initials(state.user?.display_name || state.user?.email || "User");
  }

  function initials(value) {
    return String(value || "U").trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0].toUpperCase()).join("") || "U";
  }

  async function boot() {
    // Populate stable SVG icons in the document shell.
    $$("[data-icon]").forEach(node => node.appendChild(icon(node.dataset.icon)));
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
      updateNavigationCounts();
      if (showNotice) toast("Workspace data refreshed.");
      if (state.page === "overview") renderOverview();
      else if (state.page === "teams") renderTeams();
      else if (state.page === "projects") renderProjects();
      else if (state.page === "tasks") renderTasks();
      else if (state.page === "notifications") renderNotifications();
      else if (state.page === "chat" && state.selectedProject) renderProject(state.selectedProject.id, "chat");
      setConnection(true);
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
    const unread = state.notifications.filter(item => !item.is_read).length;
    $("#nav-notification-count").textContent = String(unread);
    $("#nav-notification-count").hidden = unread === 0;
    $("#topbar-notification-dot").hidden = unread === 0;
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
    const allowed = ["overview", "teams", "projects", "tasks", "notifications", "chat", "settings"];
    navigate(allowed.includes(hash) ? hash : "overview", false);
  }

  function navigate(page, updateHash = true) {
    state.page = page;
    state.search = "";
    stopChatPolling();
    if (updateHash) history.pushState({ page }, "", page === "overview" ? "#overview" : "#" + page);
    const titles = { overview: "Overview", teams: "Teams", projects: "Projects", tasks: "My tasks", notifications: "Notifications", chat: "Project chat", settings: "Preferences" };
    $("#current-page-label").textContent = titles[page] || "Workspace";
    $$("[data-page]").forEach(node => node.classList.toggle("is-active", node.dataset.page === page));
    closeSidebar();
    if (page === "overview") renderOverview();
    else if (page === "teams") renderTeams();
    else if (page === "projects") renderProjects();
    else if (page === "tasks") renderTasks();
    else if (page === "notifications") renderNotifications();
    else if (page === "chat") renderChatLanding();
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
  }

  function taskRow(task, compact) {
    const row = el("div", "task-row");
    const check = actionButton("", "toggle-task", "task-check", { "aria-label": "Change task status", "data-task-id": task.id, "data-project-id": task.project_id });
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
      actionGroup(actionButton("＋ Create team", "create-team", "btn btn-primary"))));
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
    root.appendChild(pageHeading("TEAM WORKSPACE", team.name, "Projects and invitations for this team.",
      actionGroup(actionButton("Invite member", "invite-member", "btn btn-outline", { "data-team-id": team.id }), actionButton("New project", "create-project", "btn btn-primary", { "data-team-id": team.id }))));
    const layout = el("div", "overview-columns");
    const projectContent = el("div", "project-mini-list");
    const projectsPanel = panel("Team projects", "Accessible projects in this team", projectContent);
    const memberContent = el("div", "team-list");
    const invitePanel = panel("Team invitations", "Create a secure one-time invitation token", memberContent);
    append(layout, projectsPanel, invitePanel);
    root.appendChild(layout);
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
          } catch (error) { toast(error.message, "error"); }
          finally { submit.disabled = false; }
        });
        memberContent.appendChild(inviteForm);
      } else {
        memberContent.appendChild(el("p", "small", "Only team owners and managers can create invitations."));
      }
    } catch (error) {
      projectContent.appendChild(el("div", "inline-error", error.message));
    }
  }

  function showInvitationToken(token, email) {
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
    const project = projectById(projectId) || state.selectedProject;
    if (!project) { navigate("projects", false); return; }
    state.selectedProject = project;
    state.projectTab = tab;
    const root = pageRoot();
    const heading = el("div", "project-detail-head");
    append(heading, el("span", "project-mark", initials(project.name).slice(0, 2)),
      append(el("div"), el("div", "eyebrow", "PROJECT WORKSPACE"), el("h1", "", project.name),
        el("p", "", project.description || "Plan work, coordinate the team, and keep decisions in context.")));
    const headActions = actionGroup(actionButton("New task", "create-task", "btn btn-primary", { "data-project-id": project.id }),
      actionButton("Back to projects", "navigate-projects", "btn btn-outline"));
    append(root, heading, headActions);
    const tabs = el("div", "project-tabs");
    [["overview", "Overview", "grid"], ["tasks", "Tasks", "check-square"], ["members", "Members", "users"], ["chat", "Chat", "message"]].forEach(([key, label, iconName]) => {
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
      append(toolbar, el("p", "small", "Change a task status with the action on its right."), actionButton("＋ Add task", "create-task", "btn btn-primary btn-sm", { "data-project-id": project.id }));
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
    }
  }

  function taskTableRow(task) {
    const row = el("div", "task-table-row");
    const main = el("div", "row-main");
    append(main, el("span", "row-title", task.title), el("span", "row-subtitle", task.description || "Created by " + (task.created_by_name || "a team member")));
    const due = el("span", "task-due", task.due_at ? formatDate(task.due_at, { month: "short", day: "numeric" }) : "No due date");
    const controls = el("div", "task-controls");
    append(controls, priorityLabel(task.priority), badge(task.status), actionButton(nextTaskStatus(task.status).label, "set-task-status", "btn btn-outline btn-sm", { "data-task-id": task.id, "data-project-id": task.project_id, "data-next-status": nextTaskStatus(task.status).value }));
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
    append(header, append(el("div"), el("h2", "", "Project members"), el("p", "", "Approved access and pending membership requests.")),
      actionButton("＋ Add member", "add-project-member", "btn btn-primary btn-sm", { "data-project-id": project.id }));
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
          badge(member.status || member.access_level || "member"));
        if (member.status === "pending") row.appendChild(actionButton("Approve", "approve-member", "btn btn-outline btn-sm", { "data-project-id": project.id, "data-user-id": member.user_id }));
        if (member.status !== "revoked" && Number(member.user_id) !== Number(state.user.id)) row.appendChild(actionButton("Revoke", "revoke-member", "btn btn-ghost btn-sm", { "data-project-id": project.id, "data-user-id": member.user_id }));
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
    const note = el("div", "chat-compose-note", "Keep messages respectful. Report a message if it needs a moderator's attention.");
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
      if (fullSync) list.replaceChildren();
      messages.forEach(message => {
        if ($("#chat-message-" + message.id, list)) return;
        list.appendChild(renderChatMessage(message));
        state.chatCursor = Math.max(state.chatCursor, Number(message.id) || 0);
      });
      if (initial && !messages.length) list.appendChild(emptyState("Start the conversation", "Share a short update, ask a question, or unblock a teammate."));
      if (initial || forceScroll) list.scrollTop = list.scrollHeight;
      setConnection(true);
    } catch (error) {
      if (error.status !== 401) setConnection(false);
    } finally {
      state.chatBusy = false;
    }
  }

  function renderChatMessage(message) {
    const own = Number(message.user_id) === Number(state.user.id);
    const deleted = Boolean(message.deleted_at);
    const row = el("article", "chat-message" + (own ? " own" : "") + (deleted ? " deleted" : ""));
    row.id = "chat-message-" + message.id;
    const displayName = message.display_name || "Team member";
    const meta = el("div", "chat-message-meta");
    append(meta, el("strong", "", own ? "You" : displayName), el("span", "", formatRelative(message.created_at)));
    if (message.edited_at) meta.appendChild(el("span", "", "edited"));
    const bubble = el("div", "chat-bubble", deleted ? "This message was deleted." : message.body);
    const body = el("div", "chat-message-content");
    append(body, meta, bubble);
    if (!deleted) {
      const actions = el("div", "chat-message-actions");
      if (own) {
        actions.appendChild(actionButton("Edit", "edit-message", "chat-action", { "data-message-id": message.id }));
        actions.appendChild(actionButton("Delete", "delete-message", "chat-action", { "data-message-id": message.id }));
      } else {
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
    themeSelect.value = localStorage.getItem("tch-theme") || "system";
    themeSelect.addEventListener("change", () => setTheme(themeSelect.value));
    append(themeRow, copy, themeSelect);
    const accountRow = el("div", "settings-row");
    const accountCopy = el("div");
    append(accountCopy, el("strong", "", "Signed-in account"), el("p", "", state.user?.email || "No email available"));
    append(accountRow, accountCopy, badge("active"));
    const securityRow = el("div", "settings-row");
    const securityCopy = el("div");
    append(securityCopy, el("strong", "", "Session and access"), el("p", "", "Every workspace API request checks your current account and resource permissions on the server."));
    append(securityRow, securityCopy, append(el("span", "activity-dot"), icon("shield")));
    append(panelBox, themeRow, accountRow, securityRow);
    root.appendChild(panelBox);
    root.appendChild(el("p", "small settings-note", "TeamCode Hub does not require a downloaded font or third-party UI framework. Account recovery and invitation email delivery are not part of this development baseline."));
  }

  /* All mutation forms share one accessible modal builder and the same CSRF path. */
  function openFormModal(title, description, fields, submitLabel, onSubmit) {
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
    const error = el("div", "inline-error"); error.hidden = true;
    const controls = {};
    fields.forEach(field => {
      const wrap = el("div", "field" + (field.full ? " field-full" : ""));
      const label = el("label", "label", field.label);
      const id = "field-" + field.name + "-" + Math.random().toString(36).slice(2, 6);
      label.htmlFor = id;
      let control;
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
      if (field.value !== undefined) control.value = field.value;
      if (field.min) control.min = field.min;
      if (field.step) control.step = field.step;
      if (field.help) control.setAttribute("aria-describedby", id + "-help");
      controls[field.name] = control;
      append(wrap, label, control);
      if (field.help) wrap.appendChild(el("span", "help", field.help)).id = id + "-help";
      form.appendChild(wrap);
    });
    form.appendChild(error);
    const footer = el("div", "tch-modal-footer");
    append(footer, actionButton("Cancel", "close-modal", "btn btn-outline"));
    const submit = el("button", "btn btn-primary", submitLabel); submit.type = "submit";
    footer.appendChild(submit);
    append(modal, header, form, footer); overlay.appendChild(modal);
    $("#modal-root").replaceChildren(overlay);
    overlay.addEventListener("click", event => { if (event.target === overlay) closeModal(); });
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

  function closeModal() { $("#modal-root").replaceChildren(); }

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

  function createProjectModal(preselectedTeamId) {
    if (!state.teams.length) { toast("Create a team before creating a project.", "warning"); createTeamModal(); return; }
    const options = state.teams.map(team => ({ value: String(team.id), label: team.name + " · " + (team.role || "member") }));
    openFormModal("Create a project", "Projects belong to a team and inherit its membership boundaries.", [
      { name: "team_id", label: "Team", type: "select", options, required: true, value: preselectedTeamId ? String(preselectedTeamId) : String(state.teams[0].id), full: true },
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

  function createTaskModal(preselectedProjectId) {
    if (!state.projects.length) { toast("Create a project before adding tasks.", "warning"); createProjectModal(); return; }
    const options = state.projects.map(project => ({ value: String(project.id), label: project.name }));
    const defaultDue = new Date(Date.now() + 86400000);
    const localDue = new Date(defaultDue.getTime() - defaultDue.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    openFormModal("Create a task", "Make the next action clear, assignable, and easy to track.", [
      { name: "project_id", label: "Project", type: "select", options, required: true, value: preselectedProjectId ? String(preselectedProjectId) : String(state.projects[0].id), full: true },
      { name: "title", label: "Task title", type: "text", placeholder: "What needs to get done?", required: true, maxLength: 160, full: true },
      { name: "description", label: "Description", type: "textarea", placeholder: "Optional context or acceptance criteria", maxLength: 10000, full: true },
      { name: "priority", label: "Priority", type: "select", options: [{ value: "low", label: "Low" }, { value: "normal", label: "Normal" }, { value: "high", label: "High" }, { value: "urgent", label: "Urgent" }], value: "normal" },
      { name: "due_at", label: "Due date (your local time)", type: "datetime-local", value: localDue, help: "Converted to UTC before it is sent to the server." },
      { name: "assigned_to", label: "Assignee user ID (optional)", type: "number", min: "1", placeholder: "Member user ID", help: "Assignee must already have approved member-level project access." }
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
    openFormModal("Add a project member", "Assign an existing team member. Their access remains pending until approved.", [
      { name: "user_id", label: "User ID", type: "number", min: "1", required: true, placeholder: "Enter an existing user ID" },
      { name: "access_level", label: "Access level", type: "select", options: [{ value: "member", label: "Member — can contribute" }, { value: "viewer", label: "Viewer — read only" }], value: "member" }
    ], "Assign member", async values => {
      await request("/api/projects/" + escapePath(projectId) + "/members", { method: "POST", body: { user_id: Number(values.user_id), access_level: values.access_level } });
      toast("Membership request created.");
      await renderProject(projectId, "members");
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
    await request("/api/notifications/" + escapePath(id) + "/read", { method: "POST", body: {} });
    const item = state.notifications.find(notification => Number(notification.id) === Number(id));
    if (item) item.is_read = true;
    updateNavigationCounts();
    if (state.page === "notifications") renderNotifications();
    else if (state.page === "overview") renderOverview();
  }

  async function markAllVisibleRead() {
    const unread = state.notifications.filter(item => !item.is_read);
    for (const item of unread) {
      try {
        await request("/api/notifications/" + escapePath(item.id) + "/read", { method: "POST", body: {} });
        item.is_read = true;
      } catch (_) { /* Continue marking other visible items even if one has expired. */ }
    }
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
      else if (name === "create-project") createProjectModal(action.dataset.teamId);
      else if (name === "create-task") createTaskModal(action.dataset.projectId);
      else if (name === "invite-member") inviteMember(action.dataset.teamId);
      else if (name === "navigate-projects") navigate("projects");
      else if (name === "navigate-teams") navigate("teams");
      else if (name === "navigate-tasks") navigate("tasks");
      else if (name === "navigate-notifications") navigate("notifications");
      else if (name === "refresh") await refreshWorkspace(true);
      else if (name === "logout") await logout();
      else if (name === "close-modal") closeModal();
      else if (name === "copy-invitation-token") {
        const token = action.dataset.token || "";
        try { await navigator.clipboard.writeText(token); toast("Invitation token copied."); }
        catch (_) { const box = $("textarea", $("#modal-root")); if (box) { box.focus(); box.select(); document.execCommand("copy"); toast("Select and copy the invitation token if clipboard access is blocked.", "warning"); } }
      }
      else if (name === "open-team") await openTeam(action.dataset.teamId);
      else if (name === "open-project") {
        const project = projectById(action.dataset.projectId);
        if (project) await openProject(project);
      }
      else if (name === "open-project-chat") {
        const project = projectById(action.dataset.projectId);
        if (project) await openProject(project, true, "chat");
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
  document.addEventListener("click", event => {
    if (event.target.closest("[data-sidebar-toggle]")) openSidebar();
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
