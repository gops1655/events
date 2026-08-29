function openModal(id) {
  document.getElementById(id)?.classList.add("open");
}
function closeModal(id) {
  document.getElementById(id)?.classList.remove("open");
}
document.addEventListener("click", (e) => {
  const bg = e.target.closest(".modal-bg");
  if (bg && e.target === bg) bg.classList.remove("open");
});
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    document.querySelectorAll(".modal-bg.open").forEach((m) => m.classList.remove("open"));
  }
});
function confirmDelete(form, message) {
  if (confirm(message || "Delete this record?")) form.submit();
}

/* ---------------- Notification bell ---------------- */
(function () {
  const trigger = document.getElementById("notifTrigger");
  if (!trigger) return;
  const panel = document.getElementById("notifPanel");
  const dot = document.getElementById("notifDot");
  const list = document.getElementById("notifList");
  const readAllBtn = document.getElementById("notifReadAll");
  let loaded = false;

  function render(data) {
    dot.hidden = !data.unread;
    if (!data.items.length) {
      list.innerHTML = '<div class="notif-empty">You\'re all caught up.</div>';
      return;
    }
    list.innerHTML = data.items
      .map((n) => {
        const cls = n.read ? "notif-item" : "notif-item unread";
        const body = n.url ? `<a href="${n.url}" class="notif-open" data-id="${n.id}">` : `<div class="notif-open" data-id="${n.id}">`;
        const closeTag = n.url ? "</a>" : "</div>";
        return `<div class="${cls}">${body}
          <div class="notif-title">${escapeHtml(n.title)}</div>
          <div class="notif-body">${escapeHtml(n.body || "")}</div>
          <div class="notif-when">${escapeHtml(n.when)}</div>
          ${closeTag}</div>`;
      })
      .join("");
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }

  function refresh() {
    fetch("notifications_api.php")
      .then((r) => r.json())
      .then((data) => {
        render(data);
        loaded = true;
      })
      .catch(() => {});
  }

  function post(body) {
    return fetch("notifications_api.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams(Object.assign({ csrf: window.APP_CSRF || "" }, body)),
    }).then((r) => r.json());
  }

  trigger.addEventListener("click", (e) => {
    e.stopPropagation();
    const willOpen = panel.hidden;
    panel.hidden = !willOpen;
    if (willOpen && !loaded) refresh();
  });
  list.addEventListener("click", (e) => {
    const item = e.target.closest(".notif-open");
    if (item) post({ action: "read", id: item.dataset.id });
  });
  readAllBtn.addEventListener("click", () => {
    post({ action: "read_all" }).then(refresh);
  });
  document.addEventListener("click", (e) => {
    if (!panel.hidden && !panel.contains(e.target) && e.target !== trigger) panel.hidden = true;
  });
  refresh();
  setInterval(refresh, 45000);
})();

/* ---------------- Mobile sidebar drawer ---------------- */
(function () {
  const toggle = document.getElementById("sidebarToggle");
  const sidebar = document.getElementById("sidebar");
  const scrim = document.getElementById("sidebarScrim");
  if (!toggle || !sidebar || !scrim) return;
  function close() {
    sidebar.classList.remove("open");
    scrim.classList.remove("show");
  }
  toggle.addEventListener("click", () => {
    sidebar.classList.toggle("open");
    scrim.classList.toggle("show");
  });
  scrim.addEventListener("click", close);
  sidebar.querySelectorAll("a").forEach((a) => a.addEventListener("click", close));
})();

/* ---------------- Global quick search (Ctrl/Cmd+K) ---------------- */
(function () {
  const trigger = document.getElementById("searchTrigger");
  const modal = document.getElementById("searchModal");
  if (!trigger || !modal) return;
  const input = document.getElementById("searchInput");
  const results = document.getElementById("searchResults");
  let items = [];
  let active = -1;
  let timer = null;

  function open() {
    modal.hidden = false;
    input.value = "";
    results.innerHTML = "";
    items = [];
    active = -1;
    setTimeout(() => input.focus(), 10);
  }
  function close() {
    modal.hidden = true;
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }
  function renderResults(groups) {
    items = [];
    if (!groups.length) {
      results.innerHTML = '<div class="search-empty">No matches.</div>';
      return;
    }
    results.innerHTML = groups
      .map((g) => {
        const rows = g.items
          .map((it) => {
            items.push(it);
            const idx = items.length - 1;
            return `<a href="${it.url}" class="search-row" data-idx="${idx}">
              <span class="search-row-title">${escapeHtml(it.title)}</span>
              <span class="search-row-sub">${escapeHtml(it.sub || "")}</span>
            </a>`;
          })
          .join("");
        return `<div class="search-group"><div class="search-group-h">${escapeHtml(g.label)}</div>${rows}</div>`;
      })
      .join("");
    highlight();
  }
  function highlight() {
    results.querySelectorAll(".search-row").forEach((el, i) => el.classList.toggle("active", i === active));
    const el = results.querySelector(".search-row.active");
    if (el) el.scrollIntoView({ block: "nearest" });
  }

  trigger.addEventListener("click", open);
  document.addEventListener("keydown", (e) => {
    const meta = e.ctrlKey || e.metaKey;
    if (meta && e.key.toLowerCase() === "k") {
      e.preventDefault();
      modal.hidden ? open() : close();
    } else if (e.key === "Escape" && !modal.hidden) {
      close();
    }
  });
  modal.addEventListener("click", (e) => {
    if (e.target === modal) close();
  });
  input.addEventListener("input", () => {
    const q = input.value.trim();
    active = -1;
    clearTimeout(timer);
    if (q.length < 2) {
      results.innerHTML = "";
      items = [];
      return;
    }
    timer = setTimeout(() => {
      fetch("search.php?q=" + encodeURIComponent(q))
        .then((r) => r.json())
        .then(renderResults)
        .catch(() => {
          results.innerHTML = '<div class="search-empty">Search failed.</div>';
        });
    }, 220);
  });
  input.addEventListener("keydown", (e) => {
    if (!items.length) return;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      active = Math.min(items.length - 1, active + 1);
      highlight();
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      active = Math.max(0, active - 1);
      highlight();
    } else if (e.key === "Enter" && active >= 0) {
      window.location.href = items[active].url;
    }
  });
})();
