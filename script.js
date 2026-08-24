// ── STATE ──────────────────────────────────────────────────────────────────
let currentData = null;
let currentCompany = "mira";
let compareMetric = "sales";
let charts = {};
const tableSortState = {
  agentTable: { key: "commission", dir: "desc" },
  agentPrivateOfficeTable: { key: "commission", dir: "desc" },
  teamTable: { key: "commission", dir: "desc" },
  managerAgentTable: { key: "commission", dir: "desc" },
};
let listingModalLastFocus = null;
let agentModalLastFocus = null;
let currentAgentModalType = null;
let currentAgentModalItems = [];
let managerAgentStatusFilter = "active";

let currentViewRole = null;
let currentViewDeptId = null;
let currentViewAgentId = null;

// Pagination state
let agentPage = 1;
let agentPageSize = 15;
let agentPrivateOfficePage = 1;
let agentPrivateOfficePageSize = 15;

const CHART_COLORS = [
  "#3b82f6",
  "#c9a84c",
  "#3daa72",
  "#f97316",
  "#8b5cf6",
  "#ef4444",
  "#06b6d4",
];
const DEAL_COLORS = ["#3b82f6", "#3daa72", "#f97316", "#c9a84c"];

// ── FORMATTERS ─────────────────────────────────────────────────────────────
function fmtCurrency(v, short = false) {
  if (v === null || v === undefined) return "–";
  v = Number(v);
  if (short) {
    if (v >= 1e9) return (v / 1e9).toFixed(2) + "B";
    if (v >= 1e6) return (v / 1e6).toFixed(2) + "M";
    if (v >= 1e3) return (v / 1e3).toFixed(0) + "K";
    return v.toLocaleString();
  }
  return v.toLocaleString("en-AE", {
    maximumFractionDigits: 0,
  });
}

function fmtNum(v) {
  return Number(v).toLocaleString();
}

function initials(name) {
  if (!name || typeof name !== "string") return "—";
  return name
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .map((n) => n[0])
    .join("")
    .slice(0, 3)
    .toUpperCase() || "—";
}

const SORT_ICON = `
  <span class="sort-icon" aria-hidden="true">
    <svg class="sort-caret sort-caret-up" width="10" height="5" viewBox="0 0 10 5">
      <path d="M5 0L10 5H0L5 0Z"></path>
    </svg>
    <svg class="sort-caret sort-caret-down" width="10" height="5" viewBox="0 0 10 5">
      <path d="M0 0H10L5 5L0 0Z"></path>
    </svg>
  </span>
`;

const MONTHS = [
  "Jan",
  "Feb",
  "Mar",
  "Apr",
  "May",
  "Jun",
  "Jul",
  "Aug",
  "Sep",
  "Oct",
  "Nov",
  "Dec",
];

function getSortState(tableId) {
  return tableSortState[tableId] || null;
}

function compareSortValues(a, b, type = "string") {
  const aMissing = a === null || a === undefined || a === "";
  const bMissing = b === null || b === undefined || b === "";
  if (aMissing && bMissing) return 0;
  if (aMissing) return 1;
  if (bMissing) return -1;

  if (type === "number") return Number(a) - Number(b);
  return String(a).localeCompare(String(b), undefined, {
    numeric: true,
    sensitivity: "base",
  });
}

function sortCollection(items, tableId, sorters) {
  const sort = getSortState(tableId);
  if (!sort || !sorters[sort.key]) return [...items];

  const sorter = sorters[sort.key];
  const direction = sort.dir === "desc" ? -1 : 1;

  return [...items]
    .map((item, index) => ({ item, index }))
    .sort((left, right) => {
      const result =
        compareSortValues(
          sorter.get(left.item),
          sorter.get(right.item),
          sorter.type,
        ) * direction;
      return result || left.index - right.index;
    })
    .map(({ item }) => item);
}

function refreshSortableHeaders(tableId) {
  document
    .querySelectorAll(`[data-table-id="${tableId}"][data-sort-key]`)
    .forEach((th) => {
      const button = th.querySelector(".sort-button");
      if (!button) return;
      const sort = getSortState(tableId);
      const isActive = sort?.key === th.dataset.sortKey;
      const direction = isActive ? sort.dir : null;

      button.classList.toggle("is-active", isActive);
      button.classList.toggle("is-asc", direction === "asc");
      button.classList.toggle("is-desc", direction === "desc");
      th.setAttribute(
        "aria-sort",
        direction === "asc"
          ? "ascending"
          : direction === "desc"
            ? "descending"
            : "none",
      );
    });
}

function refreshAllSortableHeaders() {
  const tableIds = new Set(
    Array.from(document.querySelectorAll("[data-table-id][data-sort-key]")).map(
      (el) => el.dataset.tableId,
    ),
  );
  tableIds.forEach((tableId) => refreshSortableHeaders(tableId));
}

function enhanceSortableHeaders() {
  document.querySelectorAll("[data-table-id][data-sort-key]").forEach((th) => {
    if (th.dataset.sortReady === "true") return;

    const label = th.textContent.trim();
    const tableId = th.dataset.tableId;
    const sortKey = th.dataset.sortKey;

    th.classList.add("is-sortable");
    th.innerHTML = `
      <button type="button" class="sort-button" data-table-id="${tableId}" data-sort-key="${sortKey}">
        <span class="sort-button-label">${label}</span>
        ${SORT_ICON}
      </button>
    `;
    th.querySelector(".sort-button")?.addEventListener("click", () => {
      toggleTableSort(tableId, sortKey);
    });
    th.dataset.sortReady = "true";
  });

  refreshAllSortableHeaders();
}

function rerenderSortedTable(tableId) {
  switch (tableId) {
    case "developerTable":
      handleTableFilter();
      break;
    case "salesByDealTypeTable":
      renderSalesByDealTypeTable(currentData?.sales_by_deal_type);
      break;
    case "agentTable":
      renderAgentTable(currentData?.agent_performance);
      break;
    case "agentPrivateOfficeTable":
      renderAgentPrivateOfficeTable(currentData?.agent_performance);
      break;
    case "teamTable":
      renderTeamTable(currentData?.team_performance);
      break;
    case "managerAgentTable":
      renderManagerAgentTable(currentData?.all_agents);
      break;
    case "agentDeveloperTable":
      renderAgentDeveloperTable(currentData?.agent?.top_developers);
      break;
  }
}

function toggleTableSort(tableId, sortKey) {
  const current = getSortState(tableId);
  let next = { key: sortKey, dir: "asc" };

  if (current?.key === sortKey) {
    if (current.dir === "asc") next.dir = "desc";
    else {
      delete tableSortState[tableId];
      refreshSortableHeaders(tableId);
      rerenderSortedTable(tableId);
      return;
    }
  }

  tableSortState[tableId] = next;
  refreshSortableHeaders(tableId);
  rerenderSortedTable(tableId);
}

function getDaysBadgeMeta(days) {
  if (days === 999 || days === "999" || days === null || days === undefined) {
    return { daysClass: "none", daysLabel: "–" };
  }
  const daysClass = days <= 14 ? "ok" : days <= 30 ? "warn" : "crit";
  const daysLabel = days <= 30 ? `${days}d ago` : `${days}d ⚠`;
  return { daysClass, daysLabel };
}

function getAttendanceBadgeClass(attendance, total) {
  const t = total || 30;
  if (t <= 0) return "ok";
  const pct = (attendance / t) * 100;
  if (pct < 50) return "crit";
  if (pct < 85) return "warn";
  return "ok";
}

function setActiveView(viewId) {
  closeListingModal();
  closeAgentModal();
  ["view-ceo", "view-manager", "view-agent"].forEach((id) =>
    document.getElementById(id)?.classList.add("hidden"),
  );
  document.getElementById(`view-${viewId}`)?.classList.remove("hidden");
}

function updateRoleBadge(name, fallbackInitial) {
  const labelEl = document.getElementById("roleLabel");
  const avatarEl = document.getElementById("roleAvatar");
  if (labelEl) labelEl.textContent = name;
  if (avatarEl) avatarEl.textContent = initials(name || fallbackInitial);
}

function getDealUrl(dealId) {
  return `https://crm.mira-international.com/crm/deal/details/${dealId}/`;
}

function renderDealReference(dealId) {
  if (!dealId) return "Deal ID unavailable";
  return `<a class="deal-link" href="${getDealUrl(dealId)}" target="_blank" rel="noopener noreferrer">Deal #${dealId}</a>`;
}

function getDrilldownBackLabel(data = currentData) {
  if (data?.current_user_role === "ceo") {
    if (data?.view === "manager") {
      return "Back to CEO View";
    }
    if (data?.view === "agent") {
      return "Back to Team View";
    }
  }
  if (data?.current_user_role === "manager") {
    if (data?.view === "agent") {
      return "Back to Team View";
    }
  }
  return "";
}

function getDrilldownBackButtonHtml(data = currentData) {
  const label = getDrilldownBackLabel(data);
  if (!label) return "";
  return `
    <button type="button" class="view-back-button" onclick="handleBackNavigation()">
      ${label}
    </button>
  `;
}

function handleBackNavigation() {
  const role = currentData?.current_user_role;
  const view = currentData?.view;

  if (role === "ceo") {
    if (view === "agent") {
      const deptId = currentData?.agent?.profile?.dept_id;
      if (deptId) {
        drillToTeam(deptId);
        return;
      }
    }
    returnToPrimaryView();
  } else if (role === "manager") {
    returnToPrimaryView();
  }
}

function returnToPrimaryView() {
  currentViewRole = null;
  currentViewDeptId = null;
  currentViewAgentId = null;
  loadDashboard();
}

function switchCompany(company) {
  if (currentCompany === company) return;
  currentCompany = company;
  updateCompanyToggleUI(company);
  currentViewRole = null;
  currentViewDeptId = null;
  currentViewAgentId = null;
  loadDashboard();
}

function updateCompanyToggleUI(company) {
  const btnMira = document.getElementById("btnCompanyMira");
  const btnEva = document.getElementById("btnCompanyEva");
  if (btnMira && btnEva) {
    if (company === "eva") {
      btnEva.classList.add("active");
      btnMira.classList.remove("active");
    } else {
      btnMira.classList.add("active");
      btnEva.classList.remove("active");
    }
  }
}

function fetchDrilldownView(params) {
  const qs = Object.entries(params)
    .map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
    .join("&");
  document.getElementById("loadingOverlay").classList.remove("hidden");

  return fetch(`data.php?${qs}`)
    .then((r) => r.json())
    .finally(() =>
      document.getElementById("loadingOverlay").classList.add("hidden"),
    );
}

function isZeroValueOther(item, amountKeys = []) {
  if (!item || item.name !== "Other") return false;
  return amountKeys.every((key) => Number(item[key] || 0) === 0);
}

function filterZeroValueOthers(items, amountKeys = []) {
  if (!Array.isArray(items)) return [];
  return items.filter((item) => !isZeroValueOther(item, amountKeys));
}

function filterZeroValueOtherDealTypes(salesData) {
  if (!salesData) return salesData;

  return Object.fromEntries(
    Object.entries(salesData).filter(([type, monthArr]) => {
      if (type !== "Other") return true;
      return !monthArr.every(
        (month) =>
          Number(month?.sales || 0) === 0 &&
          Number(month?.commission || 0) === 0,
      );
    }),
  );
}

// ── CHART HELPERS ──────────────────────────────────────────────────────────
function destroyChart(id) {
  if (charts[id]) {
    charts[id].destroy();
    delete charts[id];
  }
}

Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.color = "#9b9a9c";
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.tooltip.backgroundColor = "#0f1e35";
Chart.defaults.plugins.tooltip.titleColor = "rgba(255,255,255,0.85)";
Chart.defaults.plugins.tooltip.bodyColor = "rgba(255,255,255,0.65)";
Chart.defaults.plugins.tooltip.padding = 12;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.displayColors = true;
Chart.defaults.plugins.tooltip.boxPadding = 4;

// ── FILTER CONTROL ─────────────────────────────────────────────────────────
function fillSelect(id, arr, allLabel) {
  const el = document.getElementById(id);
  if (!el) return;
  const cur = el.value;
  el.innerHTML =
    (allLabel ? `<option value="All">${allLabel}</option>` : "") +
    arr.map((v) => `<option value="${v}">${v}</option>`).join("");
  if (arr.map(String).includes(String(cur)) || cur === "All") el.value = cur;
}

function resetFilters() {
  ["f_year", "f_quarter", "f_month", "f_deal_type"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.selectedIndex = 0;
  });
  loadDashboard();
}

function applyFilters() {
  loadDashboard();
}

function getFilterParams() {
  const currentYear = new Date().getFullYear();
  const params = {
    company: currentCompany,
    year: document.getElementById("f_year")?.value || "All",
    quarter: document.getElementById("f_quarter")?.value || "All",
    month: document.getElementById("f_month")?.value || "All",
    deal_type: document.getElementById("f_deal_type")?.value || "All",
    agent_id: document.getElementById("f_agent")?.value || "all",
    year1: document.getElementById("yc_year1")?.value || (currentYear - 1),
    year2: document.getElementById("yc_year2")?.value || currentYear,
  };

  if (currentViewRole) params.role = currentViewRole;
  if (currentViewDeptId) params.dept_id = currentViewDeptId;
  if (currentViewAgentId) params.agent_id = currentViewAgentId;

  return params;
}

var GLOBAL_DATA;

// ── DATA FETCH ─────────────────────────────────────────────────────────────
async function loadDashboard() {
  agentPage = 1;
  agentPrivateOfficePage = 1;
  const params = getFilterParams();
  const qs = Object.entries(params)
    .map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
    .join("&");
  closeListingModal();
  closeAgentModal();
  document.getElementById("loadingOverlay").classList.remove("hidden");

  try {
    const res = await fetch(`data.php?${qs}`);
    const data = await res.json();
    currentData = data;

    if (data.company) {
      currentCompany = data.company;
      updateCompanyToggleUI(currentCompany);
    }
    const compToggle = document.getElementById("companyToggleContainer");
    if (compToggle) {
      if (data.can_switch_company) {
        compToggle.classList.remove("hidden");
      } else {
        compToggle.classList.add("hidden");
      }
    }

    // Track active view state
    currentViewRole = data.view;
    if (data.view === "manager") {
      currentViewDeptId = data.manager?.profile?.dept_id || null;
      currentViewAgentId = null;
    } else if (data.view === "agent") {
      currentViewAgentId = data.agent?.profile?.user_id || null;
      currentViewDeptId = null;
    } else {
      currentViewDeptId = null;
      currentViewAgentId = null;
    }

    // Populate filters
    if (data.filters) {
      fillSelect("f_year", data.filters.years, "All Years");
      fillSelect("f_quarter", data.filters.quarters, "All Quarters");
      fillSelect("f_month", data.filters.months, "All Months");
      fillSelect("f_deal_type", data.filters.deal_types, null);
    }

    // Show correct view
    ["view-ceo", "view-manager", "view-agent"].forEach((id) =>
      document.getElementById(id).classList.add("hidden"),
    );
    document.getElementById(`view-${data.view}`).classList.remove("hidden");

    GLOBAL_DATA = data;

    if (data.view === "ceo") renderCEO(data);
    if (data.view === "manager") renderManager(data);
    if (data.view === "agent") renderAgent(data);
  } catch (e) {
    console.error("Failed to load data:", e);
    alert(
      "Unable to connect to data.php. Please ensure the PHP server is running.",
    );
  } finally {
    document.getElementById("loadingOverlay").classList.add("hidden");
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// CEO RENDER
// ═══════════════════════════════════════════════════════════════════════════
function renderCEO(data) {
  const s = data.summary;

  // Date label
  const now = new Date();
  document.getElementById("ceoDateLabel").textContent =
    `As of ${now.toLocaleDateString("en-AE", { day: "numeric", month: "long", year: "numeric" })}`;
  updateRoleBadge("CEO", "C");

  // KPI Grid
  const kpis = [
    {
      label: "Active Agents",
      value: fmtNum(s.active_agents),
      sub: "Current staff",
      icon: "👥",
      badge: null,
      action: "active_agents",
    },
    {
      label: "No Transaction (in Last 60 Days)",
      value: fmtNum(s.no_deal_60_days),
      sub: "Need follow-up",
      icon: "⚠️",
      badge: {
        txt: s.no_deal_60_days + " agents",
        cls: "red",
      },
      highlight: true,
      action: "no_deal_60",
    },
    {
      label: "Transaction Count",
      value: fmtNum(s.deal_count),
      sub: "Total transactions",
      icon: "📋",
      badge: null,
    },
    {
      label: "Sales Volume",
      value: "AED " + fmtCurrency(s.sales_volume, true),
      sub: fmtCurrency(s.sales_volume),
      icon: "💰",
      badge: null,
    },
    {
      label: "Avg Sales / Transaction",
      value: "AED " + fmtCurrency(s.avg_sales_per_deal, true),
      sub: "Per transaction",
      icon: "📊",
      badge: null,
    },
    {
      label: "Avg Sales / Month",
      value: "AED " + fmtCurrency(s.avg_sales_per_month, true),
      sub: "Monthly average",
      icon: "📅",
      badge: null,
    },
    {
      label: "Highest Sale",
      value: "AED " + fmtCurrency(s.top_deal, true),
      subHtml: renderDealReference(s.top_deal_id),
      icon: "🏆",
      badge: {
        txt: "#1",
        cls: "gold",
      },
    },
    {
      label: "Highest Commission",
      value: "AED " + fmtCurrency(s.top_commission, true),
      subHtml: renderDealReference(s.top_commission_id),
      icon: "🏆",
      badge: {
        txt: "#1",
        cls: "gold",
      },
    },
    {
      label: "Commissions",
      value: "AED " + fmtCurrency(s.commissions, true),
      sub: fmtCurrency(s.commissions),
      icon: "💼",
      badge: null,
    },
    {
      label: "Avg Revenue / Transaction",
      value: "AED " + fmtCurrency(s.avg_revenue_per_deal, true),
      sub: "Net per deal",
      icon: "📈",
      badge: null,
    },
    {
      label: "Avg Revenue / Month",
      value: "AED " + fmtCurrency(s.avg_revenue_per_month, true),
      sub: "Monthly revenue",
      icon: "🗓️",
      badge: null,
    },
    {
      label: "Active Listings",
      value: fmtNum(s.active_listings_rent),
      sub: "For Rent",
      icon: "🏡",
      badge: null,
      action: "rent",
    },
    {
      label: "Active Listings",
      value: fmtNum(s.active_listings_sale),
      sub: "For Sale",
      icon: "🏡",
      badge: null,
      action: "sale",
    },
    {
      label: "Pocket Listings",
      value: fmtNum(s.pocket_listings_rent),
      sub: "For Rent",
      icon: "🔑",
      badge: null,
      action: "pocket_rent",
    },
    {
      label: "Pocket Listings",
      value: fmtNum(s.pocket_listings_sale),
      sub: "For Sale",
      icon: "🔑",
      badge: null,
      action: "pocket_sale",
    },
  ];

  document.getElementById("ceoKpiGrid").innerHTML = kpis
    .map(
      (k, i) => `
    <div
      class="kpi-card ${k.highlight ? "highlight" : ""} ${k.action ? "clickable" : ""}"
      style="animation-delay:${0.04 + i * 0.03}s"
      ${k.action ? `role="button" tabindex="0" onclick="handleKpiCardClick('${k.action}')" onkeydown="handleKpiCardKeydown(event, '${k.action}')"` : ""}
    >
      <div class="kpi-label">
        <span>${k.label}</span>
        <span style="font-size:16px;">${k.icon}</span>
      </div>
      <div class="kpi-value">${k.value}</div>
      ${k.badge ? `<span class="kpi-badge ${k.badge.cls}">${k.badge.txt}</span>` : ""}
      <div class="kpi-sub">${k.subHtml || k.sub || ""}</div>
    </div>
  `,
    )
    .join("");

  // Commission Split
  document.getElementById("commissionSplitTable").innerHTML = `
    <div class="split-row">
      <span class="split-label">Total Commission</span>
      <span class="split-value">AED ${fmtCurrency(s.commissions)}</span>
    </div>
    <div class="split-row">
      <span class="split-label">Committed</span>
      <div style="display:flex;align-items:center;">
        <span class="split-value">AED ${fmtCurrency(s.committed_commission)}</span>
        <span class="split-pct green">(${s.committed_commission_pct}%)</span>
      </div>
    </div>
    <div class="split-row">
      <span class="split-label">Operational</span>
      <div style="display:flex;align-items:center;">
        <span class="split-value">AED ${fmtCurrency(s.operational_commission)}</span>
        <span class="split-pct red">(${s.operational_commission_pct}%)</span>
      </div>
    </div>
  `;
  document.getElementById("topCommissionVal").textContent =
    "AED " + fmtCurrency(s.top_commission);
  const topCommissionMeta = document.getElementById("topCommissionMeta");
  if (topCommissionMeta) {
    topCommissionMeta.innerHTML = renderDealReference(s.top_commission_id);
  }

  // Charts
  renderCommissionTrend(data.commission_trend);
  renderDealDonut(
    data.deal_distribution,
    "dealDonutChart",
    "dealLegend",
    "donutTotalValue",
    s.sales_volume,
  );
  renderBreakdownDonut(
    data.leads_by_stage_offplan,
    "ceoLeadStageOffplanChart",
    "ceoLeadStageOffplanLegend",
    "ceoLeadStageOffplanVal",
    "Leads",
  );
  renderBreakdownDonut(
    data.leads_by_stage_secondary,
    "ceoLeadStageSecondaryChart",
    "ceoLeadStageSecondaryLegend",
    "ceoLeadStageSecondaryVal",
    "Leads",
  );
  renderBreakdownDonut(
    data.leads_by_source,
    "ceoLeadSourceChart",
    "ceoLeadSourceLegend",
    "ceoLeadSourceVal",
    "Leads",
  );
  renderBreakdownDonut(
    data.leads_by_source_secondary,
    "ceoLeadSourceSecondaryChart",
    "ceoLeadSourceSecondaryLegend",
    "ceoLeadSourceSecondaryVal",
    "Leads",
  );
  renderBreakdownDonut(
    data.deal_closure_source_offplan,
    "ceoDealClosureSourceOffplanChart",
    "ceoDealClosureSourceOffplanLegend",
    "ceoDealClosureSourceOffplanVal",
    "Transactions",
  );
  renderBreakdownDonut(
    data.deal_closure_source_secondary,
    "ceoDealClosureSourceSecondaryChart",
    "ceoDealClosureSourceSecondaryLegend",
    "ceoDealClosureSourceSecondaryVal",
    "Transactions",
  );
  renderTargetActual(data.target_vs_actual);
  // renderDeveloperTable(data.top_developers);
  handleTableFilter(data);
  renderSalesByDealTypeTable(data.sales_by_deal_type);
  renderAgentTable(data.agent_performance);
  renderAgentPrivateOfficeTable(data.agent_performance);
  renderTeamTable(data.team_performance);

  // Year comparison
  const years = data.filters?.years || [2023, 2024, 2025, 2026];
  fillYearCompareSelects(
    years,
    data.year_comparison?.year1,
    data.year_comparison?.year2,
  );
  renderYearComparison(data.year_comparison);
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (char) => {
    switch (char) {
      case "&":
        return "&amp;";
      case "<":
        return "&lt;";
      case ">":
        return "&gt;";
      case '"':
        return "&quot;";
      case "'":
        return "&#39;";
      default:
        return char;
    }
  });
}

function handleKpiCardClick(action) {
  if (action === "active_agents" || action === "no_deal_60") {
    openAgentListModal(action);
  } else if (action) {
    openListingModal(action);
  }
}

function handleKpiCardKeydown(event, action) {
  if (event.key === "Enter" || event.key === " ") {
    event.preventDefault();
    handleKpiCardClick(action);
  }
}

function handleListingCardKeydown(event, type) {
  handleKpiCardKeydown(event, type);
}

function handleListingModalOverlay(event) {
  if (event.target?.id === "listingModal") {
    closeListingModal();
  }
}

function closeListingModal() {
  const modal = document.getElementById("listingModal");
  if (!modal) return;
  modal.classList.add("hidden");
  document.body.style.overflow = "";
  if (listingModalLastFocus && typeof listingModalLastFocus.focus === "function") {
    listingModalLastFocus.focus();
  }
  listingModalLastFocus = null;
}

function openListingModal(type) {
  if (type === "active_agents" || type === "no_deal_60") {
    openAgentListModal(type);
    return;
  }

  const modal = document.getElementById("listingModal");
  const title = document.getElementById("listingModalTitle");
  const subtitle = document.getElementById("listingModalSubtitle");
  const tbody = document.getElementById("listingModalTableBody");
  if (!modal || !title || !subtitle || !tbody) return;

  const labels = {
    sale: "Active Listings for Sale",
    rent: "Active Listings for Rent",
    pocket_sale: "Pocket Listings for Sale",
    pocket_rent: "Pocket Listings for Rent",
  };
  const items = currentData?.listing_details?.[type] || [];
  const isPocket = type.startsWith("pocket");

  listingModalLastFocus = document.activeElement;
  title.textContent = labels[type] || "Listing Details";
  subtitle.textContent = `${fmtNum(items.length)} listing${items.length === 1 ? "" : "s"} currently ${isPocket ? "in pocket stage" : "active"}`;

  if (!items.length) {
    tbody.innerHTML = `<tr><td colspan="4" class="listing-modal-empty">No ${isPocket ? "pocket" : "active"} listings found for this category.</td></tr>`;
  } else {
    tbody.innerHTML = items
      .map((item) => {
        const ref = escapeHtml(item.reference_number || "—");
        const agent = escapeHtml(item.listing_agent || "—");
        const owner = escapeHtml(item.listing_owner || "—");
        const link = item.link
          ? `<a class="listing-modal-link" href="${escapeHtml(item.link)}" target="_blank" rel="noopener noreferrer">Open</a>`
          : "—";

        return `
          <tr>
            <td>${ref}</td>
            <td>${agent}</td>
            <td>${owner}</td>
            <td>${link}</td>
          </tr>
        `;
      })
      .join("");
  }

  modal.classList.remove("hidden");
  document.body.style.overflow = "hidden";
  modal.querySelector(".modal-close")?.focus();
}

function handleAgentModalOverlay(event) {
  if (event.target?.id === "agentModal") {
    closeAgentModal();
  }
}

function closeAgentModal() {
  const modal = document.getElementById("agentModal");
  if (!modal) return;
  modal.classList.add("hidden");
  document.body.style.overflow = "";
  const searchInput = document.getElementById("agentModalSearch");
  if (searchInput) searchInput.value = "";
  if (agentModalLastFocus && typeof agentModalLastFocus.focus === "function") {
    agentModalLastFocus.focus();
  }
  agentModalLastFocus = null;
  currentAgentModalType = null;
  currentAgentModalItems = [];
}

function openAgentListModal(type) {
  const modal = document.getElementById("agentModal");
  const title = document.getElementById("agentModalTitle");
  const subtitle = document.getElementById("agentModalSubtitle");
  const thead = document.getElementById("agentModalTableHead");
  const tbody = document.getElementById("agentModalTableBody");
  const searchInput = document.getElementById("agentModalSearch");
  if (!modal || !title || !subtitle || !thead || !tbody) return;

  agentModalLastFocus = document.activeElement;
  currentAgentModalType = type;
  if (searchInput) searchInput.value = "";

  const isNoDeal = type === "no_deal_60";
  const items = (isNoDeal ? currentData?.no_deal_60_details : currentData?.active_agents_details) || [];
  currentAgentModalItems = items;

  if (isNoDeal) {
    title.textContent = "Agents with No Transaction (Last 60 Days)";
    subtitle.textContent = `${fmtNum(items.length)} agent${items.length === 1 ? "" : "s"} with no transaction deal in the last 60 days (excluding new joiners <= 60 days)`;
    thead.innerHTML = `
      <tr>
        <th style="width: 40px; text-align: center;">#</th>
        <th>Agent</th>
        <th>Position</th>
        <th>Team</th>
        <th>Joined</th>
        <th>Last Transaction</th>
        <th style="text-align: right;">Status</th>
      </tr>
    `;
  } else {
    title.textContent = "Active Sales Agents";
    subtitle.textContent = `${fmtNum(items.length)} active agent${items.length === 1 ? "" : "s"}`;
    thead.innerHTML = `
      <tr>
        <th style="width: 40px; text-align: center;">#</th>
        <th>Agent</th>
        <th>Position</th>
        <th>Team</th>
        <th>Joined</th>
        <th style="text-align: right;">Status</th>
      </tr>
    `;
  }

  modal.classList.remove("hidden");
  document.body.style.overflow = "hidden";

  try {
    renderAgentModalRows(items);
  } catch (err) {
    console.error("Error rendering agent modal rows:", err);
    tbody.innerHTML = `<tr><td colspan="${isNoDeal ? 7 : 6}" class="listing-modal-empty">Failed to load agent details.</td></tr>`;
  }

  setTimeout(() => {
    searchInput?.focus();
  }, 50);
}

function renderAgentModalRows(items) {
  const tbody = document.getElementById("agentModalTableBody");
  if (!tbody) return;

  const isNoDeal = currentAgentModalType === "no_deal_60";
  const colSpan = isNoDeal ? 7 : 6;

  if (!items || !items.length) {
    tbody.innerHTML = `<tr><td colspan="${colSpan}" class="listing-modal-empty">No agents found.</td></tr>`;
    return;
  }

  tbody.innerHTML = items
    .map((agent, index) => {
      const agentInitials = initials(agent.name || `${agent.first_name || ""} ${agent.last_name || ""}`);
      const avatarHtml = agent.photo
        ? `<img class="modal-agent-avatar" src="${escapeHtml(agent.photo)}" alt="${escapeHtml(agent.name)}" onerror="this.outerHTML='<div class=\\'modal-agent-avatar-placeholder\\'>${escapeHtml(agentInitials)}</div>'">`
        : `<div class="modal-agent-avatar-placeholder">${escapeHtml(agentInitials)}</div>`;

      const teamHtml = agent.team_name
        ? `<span class="modal-badge team">${escapeHtml(agent.team_name)}</span>`
        : `<span class="modal-badge neutral">—</span>`;

      const joinedSub = agent.days_joined !== null && agent.days_joined !== undefined
        ? `<div style="font-size: 11px; color: var(--grey-400); margin-top: 2px;">${fmtNum(agent.days_joined)}d ago</div>`
        : "";

      const joinedHtml = `
        <div>
          <div style="font-weight: 500;">${escapeHtml(agent.joined || "—")}</div>
          ${joinedSub}
        </div>
      `;

      if (isNoDeal) {
        const lastDealSub = agent.days_since_last_deal !== null && agent.days_since_last_deal !== undefined
          ? `<div style="font-size: 11px; color: var(--grey-400); margin-top: 2px;">${fmtNum(agent.days_since_last_deal)}d ago</div>`
          : "";
        const lastDealHtml = `
          <div>
            <div style="font-weight: 500;">${escapeHtml(agent.last_deal_date || "—")}</div>
            ${lastDealSub}
          </div>
        `;
        const statusHtml = `<span class="modal-badge warning">⚠️ No Deal (60d+)</span>`;

        return `
          <tr>
            <td style="text-align: center; color: var(--grey-400); font-weight: 600;">${index + 1}</td>
            <td>
              <div class="modal-agent-cell">
                ${avatarHtml}
                <div class="modal-agent-info">
                  <span class="modal-agent-name">${escapeHtml(agent.name)}</span>
                  <span class="modal-agent-id">ID: ${escapeHtml(agent.id)}</span>
                </div>
              </div>
            </td>
            <td style="color: var(--grey-600);">${escapeHtml(agent.position || "Agent")}</td>
            <td>${teamHtml}</td>
            <td>${joinedHtml}</td>
            <td>${lastDealHtml}</td>
            <td style="text-align: right;">${statusHtml}</td>
          </tr>
        `;
      } else {
        const statusHtml = `<span class="modal-badge active">● Active</span>`;

        return `
          <tr>
            <td style="text-align: center; color: var(--grey-400); font-weight: 600;">${index + 1}</td>
            <td>
              <div class="modal-agent-cell">
                ${avatarHtml}
                <div class="modal-agent-info">
                  <span class="modal-agent-name">${escapeHtml(agent.name)}</span>
                  <span class="modal-agent-id">ID: ${escapeHtml(agent.id)}</span>
                </div>
              </div>
            </td>
            <td style="color: var(--grey-600);">${escapeHtml(agent.position || "Agent")}</td>
            <td>${teamHtml}</td>
            <td>${joinedHtml}</td>
            <td style="text-align: right;">${statusHtml}</td>
          </tr>
        `;
      }
    })
    .join("");
}

function filterAgentModalTable(query) {
  const q = (query || "").trim().toLowerCase();
  if (!q) {
    renderAgentModalRows(currentAgentModalItems);
    return;
  }

  const filtered = currentAgentModalItems.filter((agent) => {
    const name = (agent.name || "").toLowerCase();
    const id = String(agent.id || "");
    const team = (agent.team_name || "").toLowerCase();
    const pos = (agent.position || "").toLowerCase();
    return name.includes(q) || id.includes(q) || team.includes(q) || pos.includes(q);
  });

  renderAgentModalRows(filtered);
}

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    closeListingModal();
    closeAgentModal();
  }
});

function renderCommissionTrend(trend) {
  destroyChart("commissionTrendChart");
  const ctx = document.getElementById("commissionTrendChart");
  if (!ctx || !trend) return;
  charts["commissionTrendChart"] = new Chart(ctx, {
    type: "line",
    data: {
      labels: trend.map((d) => d.month),
      datasets: [
        {
          data: trend.map((d) => d.value),
          borderColor: "#c9a84c",
          backgroundColor: "rgba(201,168,76,0.08)",
          borderWidth: 2.5,
          tension: 0.4,
          fill: true,
          pointBackgroundColor: "#c9a84c",
          pointRadius: 5,
          pointHoverRadius: 7,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          grid: {
            display: false,
          },
          ticks: {
            font: {
              size: 11,
            },
          },
        },
        y: {
          grid: {
            color: "rgba(0,0,0,0.05)",
          },
          ticks: {
            callback: (v) => "AED " + fmtCurrency(v, true),
            font: {
              size: 10,
            },
          },
        },
      },
      plugins: {
        tooltip: {
          callbacks: {
            label: (ctx) => "AED " + fmtCurrency(ctx.raw),
          },
        },
      },
    },
  });
}

function renderDealDonut(dist, canvasId, legendId, centerId, totalSales) {
  destroyChart(canvasId);
  const ctx = document.getElementById(canvasId);
  const filteredDist = filterZeroValueOthers(dist, [
    "value",
    "amount",
    "commission",
  ]);
  if (!ctx || !filteredDist?.length) return;

  if (centerId) {
    const el = document.getElementById(centerId);
    if (el) el.textContent = fmtCurrency(totalSales, true);
  }

  charts[canvasId] = new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: filteredDist.map((d) => d.name),
      datasets: [
        {
          data: filteredDist.map((d) => d.value),
          backgroundColor: filteredDist.map(
            (_, i) => DEAL_COLORS[i % DEAL_COLORS.length],
          ),
          borderWidth: 0,
          hoverOffset: 6,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: "68%",
      plugins: {
        tooltip: {
          callbacks: {
            label: (ctx) => `${ctx.label}: ${ctx.raw.toFixed(1)}%`,
          },
        },
      },
    },
  });

  const legendEl = document.getElementById(legendId);
  if (legendEl) {
    legendEl.innerHTML = filteredDist
      .map(
        (d, i) => `
      <div class="legend-item">
        <div class="legend-dot-label">
          <div class="legend-dot" style="background:${DEAL_COLORS[i % DEAL_COLORS.length]}"></div>
          <span class="legend-name">${d.name}</span>
        </div>
        <span class="legend-pct">${d.value.toFixed(1)}%</span>
        <span class="legend-amount">AED ${fmtCurrency(d.amount, true)}</span>
      </div>
    `,
      )
      .join("");
  }
}

function renderBreakdownDonut(items, canvasId, legendId, centerId, centerLabel) {
  destroyChart(canvasId);
  const ctx = document.getElementById(canvasId);
  const filteredItems = Array.isArray(items)
    ? items.filter((item) => Number(item?.count || 0) > 0)
    : [];
  if (!ctx) return;

  const centerEl = centerId ? document.getElementById(centerId) : null;
  const legendEl = document.getElementById(legendId);

  if (!filteredItems.length) {
    if (centerEl) centerEl.textContent = "0";
    if (legendEl) {
      legendEl.innerHTML = `<div class="legend-empty">No ${centerLabel.toLowerCase()} available</div>`;
    }
    return;
  }

  const total = filteredItems.reduce((sum, item) => sum + Number(item.count || 0), 0);
  const chartColors = filteredItems.map(
    (item, i) => item.color || CHART_COLORS[i % CHART_COLORS.length],
  );
  if (centerEl) centerEl.textContent = fmtNum(total);

  charts[canvasId] = new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: filteredItems.map((item) => item.name),
      datasets: [
        {
          data: filteredItems.map((item) => item.count),
          backgroundColor: chartColors,
          borderWidth: 0,
          hoverOffset: 6,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: "68%",
      plugins: {
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : "0.0";
              return `${ctx.label}: ${fmtNum(ctx.raw)} (${pct}%)`;
            },
          },
        },
      },
    },
  });

  if (legendEl) {
    legendEl.innerHTML = filteredItems
      .map(
        (item, i) => `
      <div class="legend-item">
        <div class="legend-dot-label">
          <div class="legend-dot" style="background:${chartColors[i]}"></div>
          <span class="legend-name">${item.name}</span>
        </div>
        <span class="legend-pct">${item.value.toFixed(1)}%</span>
        <span class="legend-amount">${fmtNum(item.count)} ${centerLabel}</span>
      </div>
    `,
      )
      .join("");
  }
}

function renderTargetActual(data) {
  destroyChart("targetActualChart");
  const ctx = document.getElementById("targetActualChart");
  if (!ctx || !data) return;

  const months = data.map((d) => d.month);
  const targets = data.map((d) => d.target);
  const actuals = data.map((d) => d.actual);

  charts["targetActualChart"] = new Chart(ctx, {
    type: "bar",
    data: {
      labels: months,
      datasets: [
        {
          label: "Target",
          data: targets,
          backgroundColor: "rgba(201,168,76,0.25)",
          borderColor: "#c9a84c",
          borderWidth: 1.5,
          borderRadius: 4,
        },
        {
          label: "Actual",
          data: actuals,
          backgroundColor: actuals.map((a, i) =>
            a >= targets[i] ? "rgba(61,170,114,0.7)" : "rgba(249,115,22,0.7)",
          ),
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: "top",
          labels: {
            font: {
              size: 11,
            },
            boxWidth: 12,
            padding: 16,
          },
        },
        tooltip: {
          callbacks: {
            label: (ctx) =>
              ctx.dataset.label + ": AED " + fmtCurrency(ctx.raw, true),
          },
        },
      },
      scales: {
        x: {
          grid: {
            display: false,
          },
          ticks: {
            font: {
              size: 10,
            },
          },
        },
        y: {
          grid: {
            color: "rgba(0,0,0,0.04)",
          },
          ticks: {
            callback: (v) => "AED " + fmtCurrency(v, true),
            font: {
              size: 10,
            },
          },
        },
      },
    },
  });

  // Summary stats
  const above = data.filter((d) => d.actual >= d.target).length;
  const total = data.filter((d) => d.actual > 0).length;
  document.getElementById("targetActualStats").innerHTML = `
    <div style="font-size:12px;color:var(--grey-600);">
      <strong style="color:var(--green);">${above}</strong> of <strong>${total}</strong> months above target
    </div>
    <div style="font-size:12px;color:var(--grey-600);">
      Highest month: <strong style="color:var(--navy);">AED ${fmtCurrency(Math.max(...actuals), true)}</strong>
    </div>
  `;
}

function renderDeveloperTable(devs) {
  const tbody = document.getElementById("developerTableBody");
  if (!tbody || !devs) return;
  const visibleDevs = filterZeroValueOthers(devs, ["amount", "commission"]);
  const sortedDevs = sortCollection(visibleDevs, "developerTable", {
    name: { type: "string", get: (d) => d.name },
    amount: { type: "number", get: (d) => d.amount },
    commission: { type: "number", get: (d) => d.commission },
    deals: { type: "number", get: (d) => d.deals },
  });

  tbody.innerHTML = sortedDevs
    .map(
      (d, i) => `
    <tr>
      <td>
        <span class="rank-badge rank-${i + 1}">${i + 1}</span>${d.name}
      </td>
      <td>${fmtCurrency(d.amount)}</td>
      <td>${fmtCurrency(d.commission)}</td>
      <td>${d.deals}</td>
    </tr>
  `,
    )
    .join("");
}

function renderPropertyTable(types) {
  const tbody = document.getElementById("developerTableBody");
  if (!tbody || !types) return;

  const visibleTypes = filterZeroValueOthers(types, ["amount", "commission"]);
  const sortedTypes = sortCollection(visibleTypes, "developerTable", {
    name: { type: "string", get: (t) => t.name },
    amount: { type: "number", get: (t) => t.amount },
    commission: { type: "number", get: (t) => t.commission },
    deals: { type: "number", get: (t) => t.deals },
  });

  tbody.innerHTML = sortedTypes
    .map(
      (t, i) => `
    <tr>
      <td>
        <span class="rank-badge rank-${i + 1}">${i + 1}</span>${t.name}
      </td>
      <td>${fmtCurrency(t.amount)}</td>
      <td>${fmtCurrency(t.commission)}</td>
      <td>${t.deals}</td>
    </tr>
  `,
    )
    .join("");
}

function renderSalesByDealTypeTable(salesData) {
  const tbody = document.getElementById("salesByDealTypeBody");
  if (!tbody || !salesData) return;
  const filteredSalesData = filterZeroValueOtherDealTypes(salesData);

  let rows = "";
  let grandTotals = {
    sales: new Array(12).fill(0),
    commission: new Array(12).fill(0),
    deals: new Array(12).fill(0),
  };
  let grandTotal = {
    sales: 0,
    commission: 0,
    deals: 0,
  };

  const groups = Object.entries(filteredSalesData).map(([type, monthArr]) => {
    const monthMap = {};
    monthArr.forEach((m) => (monthMap[m.month] = m));

    const totals = {
      sales: 0,
      commission: 0,
      deals: 0,
    };

    MONTHS.forEach((month, i) => {
      const d = monthMap[month];
      if (!d) return;

      totals.sales += d.sales;
      totals.commission += d.commission;
      totals.deals += d.deals;
      grandTotals.sales[i] += d.sales;
      grandTotals.commission[i] += d.commission;
      grandTotals.deals[i] += d.deals;
      grandTotal.sales += d.sales;
      grandTotal.commission += d.commission;
      grandTotal.deals += d.deals;
    });

    return { type, monthMap, totals };
  });

  const salesTypeSorters = {
    type: { type: "string", get: (group) => group.type },
    grand_total: { type: "number", get: (group) => group.totals.sales },
  };

  MONTHS.forEach((month) => {
    salesTypeSorters[month] = {
      type: "number",
      get: (group) => group.monthMap[month]?.sales || 0,
    };
  });

  sortCollection(groups, "salesByDealTypeTable", salesTypeSorters).forEach(
    ({ type, monthMap, totals }) => {
      const salesCells = MONTHS.map((m) => {
        const d = monthMap[m];
        return d ? fmtCurrency(d.sales, true) : "–";
      });
      const commCells = MONTHS.map((m) => {
        const d = monthMap[m];
        return d ? fmtCurrency(d.commission, true) : "–";
      });
      const dealCells = MONTHS.map((m) => {
        const d = monthMap[m];
        return d ? d.deals : "–";
      });

      rows += `<tr class="deal-type-header"><td colspan="14" style="padding:8px 12px;font-size:12px;font-weight:700;color:rgba(255,255,255,0.9);">${type}</td></tr>`;
      rows += `<tr class="deal-type-sub"><td>↳ Sales</td>${salesCells.map((c) => `<td>${c}</td>`).join("")}<td>${fmtCurrency(totals.sales, true)}</td></tr>`;
      rows += `<tr class="deal-type-sub"><td>↳ Commission</td>${commCells.map((c) => `<td>${c}</td>`).join("")}<td>${fmtCurrency(totals.commission, true)}</td></tr>`;
      rows += `<tr class="deal-type-sub"><td>↳ Transaction Count</td>${dealCells.map((c) => `<td>${c}</td>`).join("")}<td>${totals.deals}</td></tr>`;
    },
  );

  // Grand Total
  rows += `
    <tr style="background:var(--navy);color:var(--white);">
      <td style="font-weight:700;color:#fff;padding:10px 12px;">Grand Total – Sales</td>
      ${grandTotals.sales.map((v) => `<td style="color:rgba(255,255,255,0.8);padding:10px 12px;text-align:right;">${v ? fmtCurrency(v, true) : "–"}</td>`).join("")}
      <td style="color:var(--gold-light);font-weight:700;padding:10px 12px;text-align:right;">${fmtCurrency(grandTotal.sales, true)}</td>
    </tr>
    <tr style="background:var(--navy-mid);">
      <td style="font-weight:700;color:rgba(255,255,255,0.7);padding:8px 12px;">Grand Total – Commission</td>
      ${grandTotals.commission.map((v) => `<td style="color:rgba(255,255,255,0.5);padding:8px 12px;text-align:right;">${v ? fmtCurrency(v, true) : "–"}</td>`).join("")}
      <td style="color:var(--gold-light);font-weight:700;padding:8px 12px;text-align:right;">${fmtCurrency(grandTotal.commission, true)}</td>
    </tr>
    <tr style="background:var(--navy-mid);">
      <td style="font-weight:700;color:rgba(255,255,255,0.7);padding:8px 12px;">Grand Total – Transactions</td>
      ${grandTotals.deals.map((v) => `<td style="color:rgba(255,255,255,0.5);padding:8px 12px;text-align:right;">${v || "–"}</td>`).join("")}
      <td style="color:var(--gold-light);font-weight:700;padding:8px 12px;text-align:right;">${grandTotal.deals}</td>
    </tr>
  `;

  tbody.innerHTML = rows;
}

function renderAgentTable(agents) {
  const tbody = document.getElementById("agentTableBody");
  if (!tbody || !agents) return;

  // Filter out Private Office agents unless they also have a regular department
  const regularAgents = agents.filter(
    (a) =>
      !((a.designation || "").trim().toLowerCase().startsWith("private office") || a.department_id === 23) ||
      (a.original_department_id && a.original_department_id > 0),
  );

  const searchQuery = (
    document.getElementById("agentSearchInput")?.value || ""
  ).trim().toLowerCase();

  const filteredAgents = searchQuery
    ? regularAgents.filter((a) =>
        `${a.name || ""} ${a.designation || ""}`
          .toLowerCase()
          .includes(searchQuery),
      )
    : regularAgents;

  document.getElementById("agentCountBadge").textContent =
    filteredAgents.length === regularAgents.length
      ? `${regularAgents.length} agents`
      : `${filteredAgents.length} of ${regularAgents.length} agents`;

  const sortedAgents = sortCollection(filteredAgents, "agentTable", {
    name: { type: "string", get: (a) => a.name },
    reshuffled_leads: { type: "number", get: (a) => a.reshuffled_leads },
    deals: { type: "number", get: (a) => a.deals },
    total_listings: { type: "number", get: (a) => a.total_listings },
    active_listings: { type: "number", get: (a) => a.active_listings },
    pocket_listings: { type: "number", get: (a) => a.pocket_listings },
    sales: { type: "number", get: (a) => a.sales },
    commission: { type: "number", get: (a) => a.commission },
    top_deal: { type: "number", get: (a) => a.top_deal },
    avg_gap: { type: "number", get: (a) => a.avg_gap },
    last_deal_days: { type: "number", get: (a) => a.last_deal_days },
    attendance: { type: "number", get: (a) => a.attendance },
  });

  if (!sortedAgents.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="12" class="table-empty-state">No agents match your search.</td>
      </tr>
    `;
    const pagContainer = document.getElementById("agentTablePagination");
    if (pagContainer) pagContainer.innerHTML = "";
    return;
  }

  // Slicing for pagination
  const totalItems = sortedAgents.length;
  const size = agentPageSize === "All" ? totalItems : agentPageSize;
  const totalPages = Math.ceil(totalItems / size) || 1;
  if (agentPage > totalPages) {
    agentPage = totalPages;
  }
  const startIndex = (agentPage - 1) * size;
  const endIndex = startIndex + size;
  const paginatedAgents = sortedAgents.slice(startIndex, endIndex);

  tbody.innerHTML = paginatedAgents
    .map((a) => {
      const { daysClass, daysLabel } = getDaysBadgeMeta(a.last_deal_days);
      const ac = getAttendanceBadgeClass(a.attendance, a.attendance_total);
      return `
    <tr onclick="drillToAgent(${a.id})">
      <td>
        <div class="agent-name-cell">
          <div class="agent-mini-avatar">${initials(a.name)}</div>
          <div>
            <div style="font-weight:600;">${a.name} ${a.is_transferred ? `<span class="days-badge warn" style="font-size:9px;padding:2px 4px;margin-left:6px;display:inline-flex;">No longer in dept${a.transferred_at ? ' (since ' + a.transferred_at + ')' : ''}</span>` : ''}</div>
            <div style="font-size:10px;color:var(--grey-400);">${a.designation}</div>
          </div>
        </div>
      </td>
      <td>${a.reshuffled_leads}</td>
      <td style="font-weight:600;">${a.deals}</td>
      <td style="font-weight:600;">${a.total_listings}</td>
      <td>${a.active_listings}</td>
      <td>${a.pocket_listings}</td>
      <td>AED ${fmtCurrency(a.sales)}</td>
      <td>AED ${fmtCurrency(a.commission)}</td>
      <td>AED ${fmtCurrency(a.top_deal, true)}</td>
      <td>${a.avg_gap === 999 ? '–' : a.avg_gap + ' days'}</td>
      <td><span class="days-badge ${daysClass}">${daysLabel}</span></td>
      <td><span class="days-badge ${ac}">${a.attendance} / ${a.attendance_total || 30} days</span></td>
    </tr>
    `;
    })
    .join("");

  renderPagination(
    "agentTablePagination",
    agentPage,
    totalItems,
    agentPageSize,
    "changeAgentPage",
    "changeAgentPageSize"
  );
}

function handleAgentSearch() {
  agentPage = 1;
  renderAgentTable(currentData?.agent_performance || []);
}

function changeAgentPage(page) {
  agentPage = page;
  renderAgentTable(currentData?.agent_performance || []);
}

function changeAgentPageSize(size) {
  agentPageSize = size === "All" ? "All" : parseInt(size);
  agentPage = 1;
  renderAgentTable(currentData?.agent_performance || []);
}

function renderAgentPrivateOfficeTable(agents) {
  const tbody = document.getElementById("agentPrivateOfficeTableBody");
  if (!tbody || !agents) return;

  // Filter only Private Office agents (case-insensitive & trimmed)
  const poAgents = agents.filter(
    (a) => (a.designation || "").trim().toLowerCase().startsWith("private office") || a.department_id === 23,
  );

  const searchQuery = (
    document.getElementById("agentPrivateOfficeSearchInput")?.value || ""
  ).trim().toLowerCase();

  const filteredAgents = searchQuery
    ? poAgents.filter((a) =>
        `${a.name || ""} ${a.designation || ""}`
          .toLowerCase()
          .includes(searchQuery),
      )
    : poAgents;

  document.getElementById("agentPrivateOfficeCountBadge").textContent =
    filteredAgents.length === poAgents.length
      ? `${poAgents.length} agents`
      : `${filteredAgents.length} of ${poAgents.length} agents`;

  const sortedAgents = sortCollection(filteredAgents, "agentPrivateOfficeTable", {
    name: { type: "string", get: (a) => a.name },
    reshuffled_leads: { type: "number", get: (a) => a.reshuffled_leads },
    leads_offplan: { type: "number", get: (a) => a.leads_offplan },
    leads_secondary: { type: "number", get: (a) => a.leads_secondary },
    deals: { type: "number", get: (a) => a.deals },
    total_listings: { type: "number", get: (a) => a.total_listings },
    active_listings: { type: "number", get: (a) => a.active_listings },
    pocket_listings: { type: "number", get: (a) => a.pocket_listings },
    sales: { type: "number", get: (a) => a.sales },
    commission: { type: "number", get: (a) => a.commission },
    top_deal: { type: "number", get: (a) => a.top_deal },
    avg_gap: { type: "number", get: (a) => a.avg_gap },
    last_deal_days: { type: "number", get: (a) => a.last_deal_days },
    attendance: { type: "number", get: (a) => a.attendance },
  });

  if (!sortedAgents.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="14" class="table-empty-state">No agents match your search.</td>
      </tr>
    `;
    const pagContainer = document.getElementById("agentPrivateOfficeTablePagination");
    if (pagContainer) pagContainer.innerHTML = "";
    return;
  }

  // Calculate totals
  const totalReshuffled = sortedAgents.reduce((sum, a) => sum + (a.reshuffled_leads || 0), 0);
  const totalLeadsOffplan = sortedAgents.reduce((sum, a) => sum + (a.leads_offplan || 0), 0);
  const totalLeadsSecondary = sortedAgents.reduce((sum, a) => sum + (a.leads_secondary || 0), 0);
  const totalDeals      = sortedAgents.reduce((sum, a) => sum + (a.deals || 0), 0);
  const totalListings   = sortedAgents.reduce((sum, a) => sum + (a.total_listings || 0), 0);
  const totalActiveListings = sortedAgents.reduce((sum, a) => sum + (a.active_listings || 0), 0);
  const totalPocketListings = sortedAgents.reduce((sum, a) => sum + (a.pocket_listings || 0), 0);
  const totalSales      = sortedAgents.reduce((sum, a) => sum + (a.sales || 0), 0);
  const totalCommission = sortedAgents.reduce((sum, a) => sum + (a.commission || 0), 0);
  const topDeal         = sortedAgents.reduce((max, a) => Math.max(max, a.top_deal || 0), 0);
  
  const validGaps       = sortedAgents.map(a => a.avg_gap).filter(g => g > 0);
  const avgGap          = validGaps.length > 0 ? Math.round(validGaps.reduce((sum, g) => sum + g, 0) / validGaps.length) : 0;
  
  const minLastDealDays = sortedAgents.reduce((min, a) => {
    if (a.last_deal_days === null || a.last_deal_days === undefined) return min;
    return min === null ? a.last_deal_days : Math.min(min, a.last_deal_days);
  }, null);

  const { daysClass: totDaysClass, daysLabel: totDaysLabel } = minLastDealDays !== null
    ? getDaysBadgeMeta(minLastDealDays)
    : { daysClass: "crit", daysLabel: "–" };

  // Slicing for pagination
  const totalItems = sortedAgents.length;
  const size = agentPrivateOfficePageSize === "All" ? totalItems : agentPrivateOfficePageSize;
  const totalPages = Math.ceil(totalItems / size) || 1;
  if (agentPrivateOfficePage > totalPages) {
    agentPrivateOfficePage = totalPages;
  }
  const startIndex = (agentPrivateOfficePage - 1) * size;
  const endIndex = startIndex + size;
  const paginatedAgents = sortedAgents.slice(startIndex, endIndex);

  let rowsHtml = paginatedAgents
    .map((a) => {
      const { daysClass, daysLabel } = getDaysBadgeMeta(a.last_deal_days);
      const ac = getAttendanceBadgeClass(a.attendance, a.attendance_total);
      return `
    <tr onclick="drillToAgent(${a.id})">
      <td>
        <div class="agent-name-cell">
          <div class="agent-mini-avatar">${initials(a.name)}</div>
          <div>
            <div style="font-weight:600;">${a.name} ${a.is_transferred ? `<span class="days-badge warn" style="font-size:9px;padding:2px 4px;margin-left:6px;display:inline-flex;">No longer in dept${a.transferred_at ? ' (since ' + a.transferred_at + ')' : ''}</span>` : ''}</div>
            <div style="font-size:10px;color:var(--grey-400);">${a.designation}</div>
          </div>
        </div>
      </td>
      <td>${a.reshuffled_leads}</td>
      <td>${a.leads_offplan}</td>
      <td>${a.leads_secondary}</td>
      <td style="font-weight:600;">${a.deals}</td>
      <td style="font-weight:600;">${a.total_listings}</td>
      <td>${a.active_listings}</td>
      <td>${a.pocket_listings}</td>
      <td>AED ${fmtCurrency(a.sales)}</td>
      <td>AED ${fmtCurrency(a.commission)}</td>
      <td>AED ${fmtCurrency(a.top_deal, true)}</td>
      <td>${a.avg_gap === 999 ? '–' : a.avg_gap + ' days'}</td>
      <td><span class="days-badge ${daysClass}">${daysLabel}</span></td>
      <td><span class="days-badge ${ac}">${a.attendance} / ${a.attendance_total || 30} days</span></td>
    </tr>
    `;
    })
    .join("");

  // Append totals row
  rowsHtml += `
    <tr style="background:var(--navy);color:var(--white);font-weight:700;pointer-events:none;">
      <td style="color:#fff;padding:12px 14px;text-align:left;">Total</td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">${totalReshuffled}</td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">${totalLeadsOffplan}</td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">${totalLeadsSecondary}</td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">${totalDeals}</td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">${totalListings}</td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">${totalActiveListings}</td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">${totalPocketListings}</td>
      <td style="color:var(--gold-light);padding:12px 14px;text-align:right;">AED ${fmtCurrency(totalSales)}</td>
      <td style="color:var(--gold-light);padding:12px 14px;text-align:right;">AED ${fmtCurrency(totalCommission)}</td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">AED ${fmtCurrency(topDeal, true)}</td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">${avgGap} days</td>
      <td style="padding:12px 14px;text-align:right;"><span class="days-badge ${totDaysClass}">${totDaysLabel}</span></td>
      <td style="color:#fff;padding:12px 14px;text-align:right;">–</td>
    </tr>
  `;

  tbody.innerHTML = rowsHtml;

  renderPagination(
    "agentPrivateOfficeTablePagination",
    agentPrivateOfficePage,
    totalItems,
    agentPrivateOfficePageSize,
    "changeAgentPrivateOfficePage",
    "changeAgentPrivateOfficePageSize"
  );
}

function handleAgentPrivateOfficeSearch() {
  agentPrivateOfficePage = 1;
  renderAgentPrivateOfficeTable(currentData?.agent_performance || []);
}

function changeAgentPrivateOfficePage(page) {
  agentPrivateOfficePage = page;
  renderAgentPrivateOfficeTable(currentData?.agent_performance || []);
}

function changeAgentPrivateOfficePageSize(size) {
  agentPrivateOfficePageSize = size === "All" ? "All" : parseInt(size);
  agentPrivateOfficePage = 1;
  renderAgentPrivateOfficeTable(currentData?.agent_performance || []);
}

function renderTeamTable(teams) {
  const tbody = document.getElementById("teamTableBody");
  if (!tbody || !teams) return;
  document.getElementById("teamCountBadge").textContent =
    `${teams.length} teams`;

  const sortedTeams = sortCollection(teams, "teamTable", {
    name: { type: "string", get: (a) => a.name },
    deals: { type: "number", get: (a) => a.deals },
    leads_offplan: { type: "number", get: (a) => a.leads_offplan },
    leads_secondary: { type: "number", get: (a) => a.leads_secondary },
    active_listings: { type: "number", get: (a) => a.active_listings },
    pocket_listings: { type: "number", get: (a) => a.pocket_listings },
    total_listings: { type: "number", get: (a) => a.total_listings },
    sales: { type: "number", get: (a) => a.sales },
    commission: { type: "number", get: (a) => a.commission },
    top_deal: { type: "number", get: (a) => a.top_deal },
    avg_gap: { type: "number", get: (a) => a.avg_gap },
    last_deal_days: { type: "number", get: (a) => a.last_deal_days },
  });

  tbody.innerHTML = sortedTeams
    .map((a) => {
      const { daysClass, daysLabel } = getDaysBadgeMeta(a.last_deal_days);
      return `
    <tr onclick="drillToTeam(${a.id})">
      <td>
        <div class="agent-name-cell">
          <div class="agent-mini-avatar">${initials(a.name)}</div>
          <div>
            <div style="font-weight:600;">${a.name}</div>
          </div>
        </div>
      </td>
      <td style="font-weight:600;">${a.deals}</td>
      <td style="font-weight:600;">${a.leads_offplan}</td>
      <td style="font-weight:600;">${a.leads_secondary}</td>
      <td style="font-weight:600;">${a.active_listings}</td>
      <td style="font-weight:600;">${a.pocket_listings}</td>
      <td style="font-weight:600;">${a.total_listings}</td>
      <td>AED ${fmtCurrency(a.sales)}</td>
      <td>AED ${fmtCurrency(a.commission)}</td>
      <td>AED ${fmtCurrency(a.top_deal, true)}</td>
      <td>${a.avg_gap === 999 ? '–' : a.avg_gap + ' days'}</td>
      <td><span class="days-badge ${daysClass}">${daysLabel}</span></td>
    </tr>
    `;
    })
    .join("");
}

function handleTableFilter() {
  const data = GLOBAL_DATA;
  const filter = document.getElementById("tableFilter").value;

  const title = document.getElementById("tableTitle");
  const subtitle = document.getElementById("tableSubtitle");

  if (filter === "developer") {
    title.innerText = "Sales & Commission by Developer";
    subtitle.innerText = "Top performing developers";
    renderDeveloperTable(data.top_developers);
  } else {
    title.innerText = "Sales & Commission by Property Type";
    subtitle.innerText = "Top performing property types";
    renderPropertyTable(data.top_property_types);
  }
}

function toggleManagerAgentStatus(status) {
  managerAgentStatusFilter = status;
  document.querySelectorAll("#managerAgentStatusToggle .status-tab").forEach((tab) => {
    tab.classList.toggle("active", tab.dataset.status === status);
  });
  renderManagerAgentTable(currentData?.all_agents);
}

function renderManagerAgentTable(agents) {
  const tbody = document.getElementById("managerAgentTableBody");
  if (!tbody) return;

  const isDismissedTab = managerAgentStatusFilter === "dismissed";
  const statusFilteredAgents = (agents || []).filter((a) =>
    isDismissedTab ? a.is_dismissed === true : a.is_dismissed !== true,
  );

  const countBadge = document.getElementById("managerAgentCountBadge");
  if (countBadge) {
    countBadge.textContent = `${statusFilteredAgents.length} ${isDismissedTab ? "dismissed" : "active"} agent${statusFilteredAgents.length === 1 ? "" : "s"}`;
  }

  const sortedAgents = sortCollection(statusFilteredAgents, "managerAgentTable", {
    name: { type: "string", get: (a) => a.name },
    leads_offplan: { type: "number", get: (a) => a.leads_offplan },
    leads_secondary: { type: "number", get: (a) => a.leads_secondary },
    reshuffled_leads: { type: "number", get: (a) => a.reshuffled_leads },
    deals: { type: "number", get: (a) => a.deals },
    active_listings: { type: "number", get: (a) => a.active_listings },
    pocket_listings: { type: "number", get: (a) => a.pocket_listings },
    total_listings: { type: "number", get: (a) => a.total_listings },
    sales: { type: "number", get: (a) => a.sales },
    commission: { type: "number", get: (a) => a.commission },
    top_deal: { type: "number", get: (a) => a.top_deal },
    last_deal_days: { type: "number", get: (a) => a.last_deal_days },
    attendance: { type: "number", get: (a) => a.attendance },
  });

  if (!sortedAgents.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="13" class="table-empty-state">No ${isDismissedTab ? "dismissed" : "active"} agents found for this team.</td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = sortedAgents
    .map((a) => {
      const { daysClass, daysLabel } = getDaysBadgeMeta(a.last_deal_days);
      const ac = getAttendanceBadgeClass(a.attendance, a.attendance_total);
      return `<tr onclick="drillToAgent(${a.id})">
        <td><div class="agent-name-cell"><div class="agent-mini-avatar">${initials(a.name)}</div><div><div style="font-weight:600">${a.name} ${a.is_dismissed ? `<span class="days-badge crit" style="font-size:9px;padding:2px 4px;margin-left:6px;display:inline-flex;">Dismissed</span>` : (a.is_transferred ? `<span class="days-badge warn" style="font-size:9px;padding:2px 4px;margin-left:6px;display:inline-flex;">No longer in dept${a.transferred_at ? ' (since ' + a.transferred_at + ')' : ''}</span>` : '')}</div><div style="font-size:10px;color:var(--grey-400)">${a.designation}</div></div></div></td>
        <td>${a.leads_offplan}</td>
        <td>${a.leads_secondary}</td>
        <td>${a.reshuffled_leads}</td>
        <td>${a.deals}</td>
        <td>${a.active_listings}</td>
        <td>${a.pocket_listings}</td>
        <td>${a.total_listings}</td>
        <td>AED ${fmtCurrency(a.sales)}</td>
        <td>AED ${fmtCurrency(a.commission)}</td>
        <td>AED ${fmtCurrency(a.top_deal, true)}</td>
        <td><span class="days-badge ${daysClass}">${daysLabel}</span></td>
        <td><span class="days-badge ${ac}">${a.attendance} / ${a.attendance_total || 30} days</span></td>
      </tr>`;
    })
    .join("");
}

function renderAgentDeveloperTable(devs) {
  const tbody = document.getElementById("agentDevTableBody");
  if (!tbody || !devs) return;

  const visibleDevs = filterZeroValueOthers(devs, ["amount", "commission"]);
  const sortedDevs = sortCollection(visibleDevs, "agentDeveloperTable", {
    name: { type: "string", get: (d) => d.name },
    amount: { type: "number", get: (d) => d.amount },
    commission: { type: "number", get: (d) => d.commission },
    deals: { type: "number", get: (d) => d.deals },
  });

  tbody.innerHTML = sortedDevs
    .map(
      (d, i) => `
      <tr>
        <td><span class="rank-badge rank-${i + 1}">${i + 1}</span>${d.name}</td>
        <td>${fmtCurrency(d.amount)}</td>
        <td>${fmtCurrency(d.commission)}</td>
        <td>${d.deals}</td>
      </tr>
    `,
    )
    .join("");
}

function drillToAgent(agentId) {
  const params = getFilterParams();
  params.role = "agent";
  params.agent_id = agentId;
  fetchDrilldownView(params)
    .then((data) => {
      if (data.error) {
        alert(data.error);
        return;
      }
      currentData = data;
      currentViewRole = "agent";
      currentViewAgentId = agentId;
      currentViewDeptId = null;
      setActiveView("agent");
      renderAgent(data);
      updateRoleBadge(data.agent?.profile?.name || "Agent", "A");
    })
    .catch(() => alert("Unable to open the selected agent report."));
}

function drillToTeam(deptId) {
  if (!deptId) return;
  const params = getFilterParams();
  params.role = "manager";
  params.dept_id = deptId;
  fetchDrilldownView(params)
    .then((data) => {
      if (data.error) {
        alert(data.error);
        return;
      }
      currentData = data;
      currentViewRole = "manager";
      currentViewDeptId = deptId;
      currentViewAgentId = null;
      setActiveView("manager");
      renderManager(data);
      updateRoleBadge(data.manager?.profile?.name || "Manager", "M");
    })
    .catch(() => alert("Unable to open the selected team report."));
}

// Year Comparison
function fillYearCompareSelects(years, y1, y2) {
  ["yc_year1", "yc_year2"].forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = years
      .map((y) => `<option value="${y}">${y}</option>`)
      .join("");
  });
  const el1 = document.getElementById("yc_year1");
  const el2 = document.getElementById("yc_year2");
  if (el1 && y1) el1.value = y1;
  if (el2 && y2) el2.value = y2;
}

function updateYearComparison() {
  if (!currentData) return;
  const y1 = parseInt(document.getElementById("yc_year1").value);
  const y2 = parseInt(document.getElementById("yc_year2").value);
  const params = {
    ...getFilterParams(),
    role: "ceo",
    year1: y1,
    year2: y2,
  };
  const qs = Object.entries(params)
    .map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
    .join("&");
  fetch(`data.php?${qs}`)
    .then((r) => r.json())
    .then((data) => {
      if (data.year_comparison) renderYearComparison(data.year_comparison);
    });
}

function switchCompareMetric(el, metric) {
  compareMetric = metric;
  document
    .querySelectorAll(".compare-tab")
    .forEach((t) => t.classList.remove("active"));
  el.classList.add("active");
  if (currentData?.year_comparison)
    renderYearComparison(currentData.year_comparison, true);
}

function renderYearComparison(yc, skipPills) {
  if (!yc) return;
  // Update stored reference
  if (currentData) currentData.year_comparison = yc;

  // Summary pills
  if (!skipPills) {
    const pillsEl = document.getElementById("yearSummaryPills");
    if (pillsEl) {
      const s1 = yc.year1_summary || {};
      const s2 = yc.year2_summary || {};
      pillsEl.innerHTML = `
        <div class="year-pill year-pill-1">
          <span class="year-pill-label">${yc.year1}</span>
          <div class="year-pill-stats">
            <span class="year-pill-stat">Sales: <strong>AED ${fmtCurrency(s1.sales, true)}</strong></span>
            <span class="year-pill-stat">Transactions: <strong>${fmtNum(s1.deals)}</strong></span>
            <span class="year-pill-stat">Commission: <strong>AED ${fmtCurrency(s1.commission, true)}</strong></span>
          </div>
        </div>
        <div class="year-pill year-pill-2">
          <span class="year-pill-label">${yc.year2}</span>
          <div class="year-pill-stats">
            <span class="year-pill-stat">Sales: <strong>AED ${fmtCurrency(s2.sales, true)}</strong></span>
            <span class="year-pill-stat">Transactions: <strong>${fmtNum(s2.deals)}</strong></span>
            <span class="year-pill-stat">Commission: <strong>AED ${fmtCurrency(s2.commission, true)}</strong></span>
          </div>
        </div>
      `;
    }
  }

  const metricMap = {
    sales: {
      key: "sales",
      label: "Sales Volume",
      fmt: (v) => "AED " + fmtCurrency(v, true),
    },
    commission: {
      key: "commission",
      label: "Commission",
      fmt: (v) => "AED " + fmtCurrency(v, true),
    },
    deals: {
      key: "deals",
      label: "Transaction Count",
      fmt: (v) => v,
    },
  };
  const m = metricMap[compareMetric];

  destroyChart("yearCompareChart");
  const ctx = document.getElementById("yearCompareChart");
  if (!ctx) return;

  charts["yearCompareChart"] = new Chart(ctx, {
    type: "bar",
    data: {
      labels: (yc.year1_monthly || []).map((d) => d.month),
      datasets: [
        {
          label: String(yc.year1),
          data: (yc.year1_monthly || []).map((d) => d[m.key]),
          backgroundColor: "rgba(59,130,246,0.65)",
          borderColor: "#3b82f6",
          borderWidth: 1,
          borderRadius: 4,
        },
        {
          label: String(yc.year2),
          data: (yc.year2_monthly || []).map((d) => d[m.key]),
          backgroundColor: "rgba(201,168,76,0.65)",
          borderColor: "#c9a84c",
          borderWidth: 1,
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: "top",
          labels: {
            font: {
              size: 12,
            },
            boxWidth: 14,
            padding: 16,
          },
        },
        tooltip: {
          callbacks: {
            label: (ctx) => ctx.dataset.label + ": " + m.fmt(ctx.raw),
          },
        },
      },
      scales: {
        x: {
          grid: {
            display: false,
          },
          ticks: {
            font: {
              size: 11,
            },
          },
        },
        y: {
          grid: {
            color: "rgba(0,0,0,0.04)",
          },
          ticks: {
            callback: (v) => m.fmt(v),
            font: {
              size: 10,
            },
          },
        },
      },
    },
  });
}

// ═══════════════════════════════════════════════════════════════════════════
// MANAGER RENDER
// ═══════════════════════════════════════════════════════════════════════════
function renderManager(data) {
  const mgr = data.manager;
  const s = mgr.summary;
  const p = mgr.profile;

  // Profile banner
  document.getElementById("managerProfileBanner").innerHTML = `
    <div class="profile-banner-wrap">
      ${getDrilldownBackButtonHtml(data)}
      <div class="profile-banner">
      <div class="profile-avatar">${initials(p.name)}</div>
      <div class="profile-info">
        <div class="profile-name">${p.name}</div>
        <div class="profile-meta">
          ${p.team_name ? `<span class="profile-meta-item">Team: <strong>${p.team_name}</strong></span>` : ""}
          <span class="profile-meta-item">ID: <strong>${p.user_id}</strong></span>
          <span class="profile-meta-item">Joined: <strong>${p.joined}</strong></span>
        </div>
      </div>
      <span class="profile-badge">${p.designation}</span>
      </div>
    </div>
  `;

  // KPIs
  const kpis = [
    {
      label: "Active Agents",
      value: fmtNum(s.active_agents),
      icon: "👥",
      action: "active_agents",
    },
    {
      label: "No Transaction (in Last 60 Days)",
      value: fmtNum(s.no_deal_60_days),
      icon: "⚠️",
      highlight: true,
      action: "no_deal_60",
    },
    {
      label: "Offplan Leads Number",
      value: fmtNum(s.lead_count_offplan),
      icon: "📋",
    },
    {
      label: "Secondary Leads No.",
      value: fmtNum(s.lead_count_secondary),
      icon: "📋",
    },
    {
      label: "Transaction Count",
      value: fmtNum(s.deal_count),
      icon: "📊",
    },
    {
      label: "Active Listings",
      value: fmtNum(s.active_listings_rent),
      sub: "For Rent",
      icon: "🏡",
      action: "rent",
    },
    {
      label: "Active Listings",
      value: fmtNum(s.active_listings_sale),
      sub: "For Sale",
      icon: "🏡",
      action: "sale",
    },
    {
      label: "Pocket Listings",
      value: fmtNum(s.pocket_listings_rent),
      sub: "For Rent",
      icon: "🔑",
      action: "pocket_rent",
    },
    {
      label: "Pocket Listings",
      value: fmtNum(s.pocket_listings_sale),
      sub: "For Sale",
      icon: "🔑",
      action: "pocket_sale",
    },
    {
      label: "Sales Volume",
      value: "AED " + fmtCurrency(s.sales_volume, true),
      icon: "💰",
    },
    {
      label: "Avg / Transaction",
      value: "AED " + fmtCurrency(s.avg_sales_per_deal, true),
      icon: "📊",
    },
    {
      label: "Commissions",
      value: "AED " + fmtCurrency(s.commissions, true),
      icon: "💼",
    },
    {
      label: "Highest Sale",
      value: "AED " + fmtCurrency(s.top_deal, true),
      subHtml: renderDealReference(s.top_deal_id),
      icon: "🏆",
    },
    {
      label: "Highest Commission",
      value: "AED " + fmtCurrency(s.top_commission, true),
      subHtml: renderDealReference(s.top_commission_id),
      icon: "🏆",
    },
  ];

  document.getElementById("managerKpiGrid").innerHTML = kpis
    .map(
      (k, i) => `
      <div
        class="kpi-card ${k.highlight ? "highlight" : ""} ${k.action ? "clickable" : ""}"
        style="animation-delay:${0.04 + i * 0.03}s"
        ${k.action ? `role="button" tabindex="0" onclick="handleKpiCardClick('${k.action}')" onkeydown="handleKpiCardKeydown(event, '${k.action}')"` : ""}
      >
      <div class="kpi-label"><span>${k.label}</span><span style="font-size:16px;">${k.icon}</span></div>
      <div class="kpi-value">${k.value}</div>
      ${k.subHtml || k.sub ? `<div class="kpi-sub">${k.subHtml || k.sub}</div>` : ""}
    </div>
  `,
    )
    .join("");

  // Charts
  destroyChart("managerCommChart");
  const ctx1 = document.getElementById("managerCommChart");
  if (ctx1 && mgr.commission_trend) {
    charts["managerCommChart"] = new Chart(ctx1, {
      type: "line",
      data: {
        labels: mgr.commission_trend.map((d) => d.month),
        datasets: [
          {
            data: mgr.commission_trend.map((d) => d.value),
            borderColor: "#c9a84c",
            backgroundColor: "rgba(201,168,76,0.08)",
            borderWidth: 2.5,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: "#c9a84c",
            pointRadius: 5,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: {
            grid: {
              display: false,
            },
          },
          y: {
            grid: {
              color: "rgba(0,0,0,0.05)",
            },
            ticks: {
              callback: (v) => "AED " + fmtCurrency(v, true),
              font: {
                size: 10,
              },
            },
          },
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => "AED " + fmtCurrency(ctx.raw),
            },
          },
        },
      },
    });
  }

  destroyChart("managerTargetChart");
  const ctx2 = document.getElementById("managerTargetChart");
  if (ctx2 && mgr.target_vs_actual) {
    const tva = mgr.target_vs_actual;
    charts["managerTargetChart"] = new Chart(ctx2, {
      type: "bar",
      data: {
        labels: tva.map((d) => d.month),
        datasets: [
          {
            label: "Target",
            data: tva.map((d) => d.target),
            backgroundColor: "rgba(201,168,76,0.25)",
            borderColor: "#c9a84c",
            borderWidth: 1.5,
            borderRadius: 4,
          },
          {
            label: "Actual",
            data: tva.map((d) => d.actual),
            backgroundColor: tva.map((d, i) =>
              d.actual >= d.target
                ? "rgba(61,170,114,0.7)"
                : "rgba(249,115,22,0.7)",
            ),
            borderRadius: 4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: true,
            position: "top",
            labels: {
              font: {
                size: 11,
              },
              boxWidth: 12,
            },
          },
        },
        scales: {
          x: {
            grid: {
              display: false,
            },
          },
          y: {
            ticks: {
              callback: (v) => "AED " + fmtCurrency(v, true),
              font: {
                size: 10,
              },
            },
          },
        },
      },
    });
  }

  // Deal donut
  renderDealDonut(
    mgr.deal_distribution,
    "managerDonutChart",
    "managerDealLegend",
    "managerDonutVal",
    mgr.deal_distribution?.reduce((sum, d) => sum + d.amount, 0),
  );

  renderBreakdownDonut(
    mgr.leads_by_stage_offplan,
    "managerLeadStageOffplanChart",
    "managerLeadStageOffplanLegend",
    "managerLeadStageOffplanVal",
    "Leads",
  );
  renderBreakdownDonut(
    mgr.leads_by_stage_secondary,
    "managerLeadStageSecondaryChart",
    "managerLeadStageSecondaryLegend",
    "managerLeadStageSecondaryVal",
    "Leads",
  );

  renderBreakdownDonut(
    mgr.leads_by_source,
    "managerLeadSourceChart",
    "managerLeadSourceLegend",
    "managerLeadSourceVal",
    "Leads",
  );
  renderBreakdownDonut(
    mgr.leads_by_source_secondary,
    "managerLeadSourceSecondaryChart",
    "managerLeadSourceSecondaryLegend",
    "managerLeadSourceSecondaryVal",
    "Leads",
  );
  renderBreakdownDonut(
    mgr.deal_closure_source_offplan,
    "managerDealClosureSourceOffplanChart",
    "managerDealClosureSourceOffplanLegend",
    "managerDealClosureSourceOffplanVal",
    "Transactions",
  );
  renderBreakdownDonut(
    mgr.deal_closure_source_secondary,
    "managerDealClosureSourceSecondaryChart",
    "managerDealClosureSourceSecondaryLegend",
    "managerDealClosureSourceSecondaryVal",
    "Transactions",
  );

  // Comm split
  document.getElementById("managerCommSplit").innerHTML = `
    <div class="split-row"><span class="split-label">Total</span><span class="split-value">AED ${fmtCurrency(s.commissions)}</span></div>
    <div class="split-row"><span class="split-label">Committed</span>
      <span class="split-value">AED ${fmtCurrency(s.committed_commission)} <span class="split-pct green">(${((s.committed_commission / s.commissions) * 100).toFixed(1)}%)</span></span>
    </div>
    <div class="split-row"><span class="split-label">Operational</span>
      <span class="split-value">AED ${fmtCurrency(s.operational_commission)} <span class="split-pct red">(${((s.operational_commission / s.commissions) * 100).toFixed(1)}%)</span></span>
    </div>
  `;

  // Agent table
  renderManagerAgentTable(data.all_agents);
}

// ═══════════════════════════════════════════════════════════════════════════
// AGENT RENDER
// ═══════════════════════════════════════════════════════════════════════════
function renderAgent(data) {
  const ag = data.agent;
  const p = ag.profile;
  const s = ag.summary;

  // Profile banner
  document.getElementById("agentProfileBanner").innerHTML = `
    <div class="profile-banner-wrap">
      ${getDrilldownBackButtonHtml(data)}
      <div class="profile-banner">
      <div class="profile-avatar">${initials(p.name)}</div>
      <div class="profile-info">
        <div class="profile-name">${p.name}</div>
        <div class="profile-meta">
          <span class="profile-meta-item">Manager: <strong>${p.manager}</strong></span>
          <span class="profile-meta-item">ID: <strong>${p.user_id}</strong></span>
          <span class="profile-meta-item">Joined: <strong>${p.joined}</strong></span>
          <span class="profile-meta-item">Days Since Last Closed Transaction: <strong style="color:${s.days_since_last === 999 ? "var(--grey-400)" : (s.days_since_last > 30 ? "var(--red)" : "var(--green)")};">${s.days_since_last === 999 ? "–" : s.days_since_last}</strong></span>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
        <span class="profile-badge">${p.designation}</span>
        ${p.current ? '<span class="current-badge">● Current</span>' : ""}
      </div>
      </div>
    </div>
  `;

  // KPIs
  const kpis = [
    {
      label: "Commissions",
      value: "AED " + fmtCurrency(s.commissions, true),
      sub: fmtCurrency(s.commissions),
      icon: "💼",
    },
    {
      label: "Committed Commission",
      value: "AED " + fmtCurrency(s.committed_commission, true),
      sub: fmtCurrency(s.committed_commission),
      icon: "🤝",
    },
    {
      label: "Operational Commission",
      value: "AED " + fmtCurrency(s.operational_commission, true),
      sub: fmtCurrency(s.operational_commission),
      icon: "⚙️",
    },
    {
      label: "Sales Volume",
      value: "AED " + fmtCurrency(s.sales_volume, true),
      sub: fmtCurrency(s.sales_volume),
      icon: "💰",
    },
    {
      label: "Transaction Count",
      value: fmtNum(s.deal_count),
      sub: "Total closed transactions",
      icon: "📋",
    },
    {
      label: "Offplan Leads Number",
      value: fmtNum(s.lead_count_offplan),
      sub: "Offplan active pipeline",
      icon: "🎯",
    },
    {
      label: "Secondary Leads No.",
      value: fmtNum(s.lead_count_secondary),
      sub: "Secondary active pipeline",
      icon: "🎯",
    },
    {
      label: "Reshuffled Leads",
      value: fmtNum(s.reshuffled_leads),
      sub: "Leads reshuffled away",
      icon: "🔄",
    },
    {
      label: "Active Listings",
      value: fmtNum(s.active_listings_rent),
      sub: "For Rent",
      icon: "🏡",
      action: "rent",
    },
    {
      label: "Active Listings",
      value: fmtNum(s.active_listings_sale),
      sub: "For Sale",
      icon: "🏡",
      action: "sale",
    },
    {
      label: "Pocket Listings",
      value: fmtNum(s.pocket_listings_rent),
      sub: "For Rent",
      icon: "🔑",
      action: "pocket_rent",
    },
    {
      label: "Pocket Listings",
      value: fmtNum(s.pocket_listings_sale),
      sub: "For Sale",
      icon: "🔑",
      action: "pocket_sale",
    },
    {
      label: "Avg Revenue / Transaction",
      value: "AED " + fmtCurrency(s.avg_revenue, true),
      sub: "Net per transaction",
      icon: "📈",
    },
    {
      label: "Avg Selling Price",
      value: "AED " + fmtCurrency(s.avg_selling_price, true),
      sub: fmtCurrency(s.avg_selling_price),
      icon: "🏠",
    },
    {
      label: "Avg Gap (Days)",
      value: s.avg_gap_days === 999 ? "–" : s.avg_gap_days + " days",
      sub: "Between transactions",
      icon: "⏱️",
    },
    {
      label: "Highest Sale",
      value: "AED " + fmtCurrency(s.top_deal, true),
      subHtml: renderDealReference(s.top_deal_id),
      icon: "🏆",
    },
    {
      label: "Highest Commission",
      value: "AED " + fmtCurrency(s.top_commission, true),
      subHtml: renderDealReference(s.top_commission_id),
      icon: "⭐",
    },
    {
      label: "Days Since Last Transaction Closed",
      value: s.days_since_last === 999 ? "–" : s.days_since_last + " days",
      sub: s.days_since_last === 999 ? "No deals" : (s.days_since_last > 30 ? "⚠ Follow up" : "✓ Active"),
      icon: "🗓️",
      highlight: s.days_since_last !== 999 && s.days_since_last > 30,
    },
    {
      label: "Attendance",
      value: `${s.attendance || 0} / ${s.attendance_total || 30} days`,
      sub: (s.attendance_total > 0 ? (((s.attendance || 0) / s.attendance_total) * 100).toFixed(0) : "100") + "% present in period",
      icon: "📅",
      highlight: (s.attendance_total > 0 ? ((s.attendance || 0) / s.attendance_total) : 1) < 0.5,
    },
  ];

  document.getElementById("agentKpiGrid").innerHTML = kpis
    .map(
      (k, i) => `
      <div
        class="kpi-card ${k.highlight ? "highlight" : ""} ${k.action ? "clickable" : ""}"
        style="animation-delay:${0.04 + i * 0.03}s"
        ${k.action ? `role="button" tabindex="0" onclick="openListingModal('${k.action}')" onkeydown="handleListingCardKeydown(event, '${k.action}')"` : ""}
      >
      <div class="kpi-label"><span>${k.label}</span><span style="font-size:15px;">${k.icon}</span></div>
      <div class="kpi-value">${k.value}</div>
      <div class="kpi-sub">${k.subHtml || k.sub || ""}</div>
    </div>
  `,
    )
    .join("");

  // Target vs actual
  destroyChart("agentTargetChart");
  const ctx1 = document.getElementById("agentTargetChart");
  if (ctx1 && ag.target_vs_actual) {
    const tva = ag.target_vs_actual;
    charts["agentTargetChart"] = new Chart(ctx1, {
      type: "bar",
      data: {
        labels: tva.map((d) => d.month),
        datasets: [
          {
            label: "Target",
            data: tva.map((d) => d.target),
            backgroundColor: "rgba(201,168,76,0.2)",
            borderColor: "#c9a84c",
            borderWidth: 1.5,
            borderRadius: 4,
          },
          {
            label: "Actual",
            data: tva.map((d) => d.actual),
            backgroundColor: tva.map((d) =>
              d.actual >= d.target
                ? "rgba(61,170,114,0.7)"
                : "rgba(249,115,22,0.7)",
            ),
            borderRadius: 4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: true,
            position: "top",
            labels: {
              font: {
                size: 11,
              },
              boxWidth: 12,
            },
          },
        },
        scales: {
          x: {
            grid: {
              display: false,
            },
          },
          y: {
            ticks: {
              callback: (v) => "AED " + fmtCurrency(v, true),
              font: {
                size: 10,
              },
            },
          },
        },
      },
    });
  }

  // Donut
  renderDealDonut(
    ag.deal_distribution,
    "agentDonutChart",
    "agentDealLegend",
    "agentDonutVal",
    ag.deal_distribution?.reduce((sum, d) => sum + (d.amount || 0), 0),
  );

  renderBreakdownDonut(
    ag.leads_by_stage_offplan,
    "agentLeadStageOffplanChart",
    "agentLeadStageOffplanLegend",
    "agentLeadStageOffplanVal",
    "Leads",
  );
  renderBreakdownDonut(
    ag.leads_by_stage_secondary,
    "agentLeadStageSecondaryChart",
    "agentLeadStageSecondaryLegend",
    "agentLeadStageSecondaryVal",
    "Leads",
  );

  renderBreakdownDonut(
    ag.leads_by_source,
    "agentLeadSourceChart",
    "agentLeadSourceLegend",
    "agentLeadSourceVal",
    "Leads",
  );
  renderBreakdownDonut(
    ag.leads_by_source_secondary,
    "agentLeadSourceSecondaryChart",
    "agentLeadSourceSecondaryLegend",
    "agentLeadSourceSecondaryVal",
    "Leads",
  );
  renderBreakdownDonut(
    ag.deal_closure_source_offplan,
    "agentDealClosureSourceOffplanChart",
    "agentDealClosureSourceOffplanLegend",
    "agentDealClosureSourceOffplanVal",
    "Transactions",
  );
  renderBreakdownDonut(
    ag.deal_closure_source_secondary,
    "agentDealClosureSourceSecondaryChart",
    "agentDealClosureSourceSecondaryLegend",
    "agentDealClosureSourceSecondaryVal",
    "Transactions",
  );

  // Ticket size
  destroyChart("agentTicketChart");
  const ctx3 = document.getElementById("agentTicketChart");
  if (ctx3 && ag.avg_ticket_size) {
    charts["agentTicketChart"] = new Chart(ctx3, {
      type: "bar",
      data: {
        labels: ag.avg_ticket_size.map((d) => d.month),
        datasets: [
          {
            data: ag.avg_ticket_size.map((d) => d.value),
            backgroundColor: "rgba(59,130,246,0.55)",
            borderColor: "#3b82f6",
            borderWidth: 1,
            borderRadius: 4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: {
            grid: {
              display: false,
            },
          },
          y: {
            ticks: {
              callback: (v) => "AED " + fmtCurrency(v, true),
              font: {
                size: 10,
              },
            },
          },
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => "AED " + fmtCurrency(ctx.raw),
            },
          },
        },
      },
    });
  }

  // Comm trend
  destroyChart("agentCommChart");
  const ctx4 = document.getElementById("agentCommChart");
  if (ctx4 && ag.commission_trend) {
    charts["agentCommChart"] = new Chart(ctx4, {
      type: "line",
      data: {
        labels: ag.commission_trend.map((d) => d.month),
        datasets: [
          {
            data: ag.commission_trend.map((d) => d.value),
            borderColor: "#c9a84c",
            backgroundColor: "rgba(201,168,76,0.08)",
            borderWidth: 2.5,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: "#c9a84c",
            pointRadius: 5,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: {
            grid: {
              display: false,
            },
          },
          y: {
            ticks: {
              callback: (v) => "AED " + fmtCurrency(v, true),
              font: {
                size: 10,
              },
            },
          },
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: (ctx) => "AED " + fmtCurrency(ctx.raw),
            },
          },
        },
      },
    });
  }

  // Comm split
  const agentCommSplitEl = document.getElementById("agentCommSplit");
  if (agentCommSplitEl) {
    const committedPct = s.commissions > 0 ? ((s.committed_commission / s.commissions) * 100).toFixed(1) : "0.0";
    const operationalPct = s.commissions > 0 ? ((s.operational_commission / s.commissions) * 100).toFixed(1) : "0.0";
    agentCommSplitEl.innerHTML = `
      <div class="split-row"><span class="split-label">Total</span><span class="split-value">AED ${fmtCurrency(s.commissions)}</span></div>
      <div class="split-row"><span class="split-label">Committed</span>
        <span class="split-value">AED ${fmtCurrency(s.committed_commission)} <span class="split-pct green">(${committedPct}%)</span></span>
      </div>
      <div class="split-row"><span class="split-label">Operational</span>
        <span class="split-value">AED ${fmtCurrency(s.operational_commission)} <span class="split-pct red">(${operationalPct}%)</span></span>
      </div>
    `;
  }

  // Developer table
  renderAgentDeveloperTable(ag.top_developers);
}

// ── BOOT ───────────────────────────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", () => {
  enhanceSortableHeaders();
  loadDashboard();
});

// ── PAGINATION UTILITY ──────────────────────────────────────────────────────
function renderPagination(containerId, currentPage, totalItems, pageSize, onPageChange, onPageSizeChange) {
  const container = document.getElementById(containerId);
  if (!container) return;

  if (totalItems <= 0) {
    container.innerHTML = "";
    return;
  }

  const size = pageSize === "All" ? totalItems : pageSize;
  const totalPages = Math.ceil(totalItems / size) || 1;
  const startItem = (currentPage - 1) * size + 1;
  const endItem = Math.min(currentPage * size, totalItems);

  let pages = [];
  const delta = 1; 
  const left = currentPage - delta;
  const right = currentPage + delta + 1;
  
  for (let i = 1; i <= totalPages; i++) {
    if (i === 1 || i === totalPages || (i >= left && i < right)) {
      pages.push(i);
    } else if (pages[pages.length - 1] !== "...") {
      pages.push("...");
    }
  }

  let pagesHtml = pages
    .map((p) => {
      if (p === "...") {
        return `<span class="pagination-ellipsis">...</span>`;
      }
      const isActive = p === currentPage ? "active" : "";
      return `
        <button type="button" class="btn-page-num ${isActive}" onclick="${onPageChange}(${p})">
          ${p}
        </button>
      `;
    })
    .join("");

  const prevDisabled = currentPage === 1 ? "disabled" : "";
  const nextDisabled = currentPage === totalPages ? "disabled" : "";

  container.innerHTML = `
    <div class="table-pagination">
      <div class="pagination-info">
        Showing <strong>${startItem}</strong> to <strong>${endItem}</strong> of <strong>${totalItems}</strong> agents
      </div>
      
      <div class="pagination-actions">
        <div class="pagination-size-selector">
          <span class="pagination-size-label">Rows per page:</span>
          <select class="pagination-select" onchange="${onPageSizeChange}(this.value)">
            <option value="10" ${pageSize === 10 ? "selected" : ""}>10</option>
            <option value="15" ${pageSize === 15 ? "selected" : ""}>15</option>
            <option value="25" ${pageSize === 25 ? "selected" : ""}>25</option>
            <option value="50" ${pageSize === 50 ? "selected" : ""}>50</option>
            <option value="100" ${pageSize === 100 ? "selected" : ""}>100</option>
            <option value="All" ${pageSize === "All" ? "selected" : ""}>All</option>
          </select>
        </div>

        <div class="pagination-buttons">
          <button type="button" class="btn-page-nav" ${prevDisabled} onclick="${onPageChange}(1)" title="First Page">
            &laquo;
          </button>
          <button type="button" class="btn-page-nav" ${prevDisabled} onclick="${onPageChange}(${currentPage - 1})" title="Previous Page">
            &lsaquo;
          </button>
          <div class="pagination-pages-list">
            ${pagesHtml}
          </div>
          <button type="button" class="btn-page-nav" ${nextDisabled} onclick="${onPageChange}(${currentPage + 1})" title="Next Page">
            &rsaquo;
          </button>
          <button type="button" class="btn-page-nav" ${nextDisabled} onclick="${onPageChange}(${totalPages})" title="Last Page">
            &raquo;
          </button>
        </div>
      </div>
    </div>
  `;
}

// ═══════════════════════════════════════════════════════════════════════════
// PDF EXPORT (EXECUTIVE MULTI-PAGE REPORT ENGINE)
// ═══════════════════════════════════════════════════════════════════════════
async function downloadReportPdf() {
  if (!currentData) {
    alert("No report data loaded to export.");
    return;
  }

  const btn = document.getElementById("btnDownloadPdf");
  const overlay = document.getElementById("pdfGeneratingOverlay");

  try {
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = `
        <svg class="animate-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10" stroke-opacity="0.25" />
          <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round" />
        </svg>
        <span>Generating...</span>
      `;
    }
    if (overlay) overlay.classList.remove("hidden");

    // Allow UI to render the overlay
    await new Promise((resolve) => setTimeout(resolve, 80));

    if (typeof html2canvas === "undefined" || !window.jspdf) {
      alert("PDF generation libraries are loading. Please try again in a moment.");
      return;
    }

    const role = currentData.view || "ceo";
    let subtitle = "Company Executive Overview";
    let filePrefix = "CEO_Overview";

    if (role === "ceo") {
      subtitle = "Executive Company-Wide Overview";
      filePrefix = "CEO_Overview";
    } else if (role === "manager") {
      const mgrName = currentData.manager?.profile?.name || "Manager";
      const teamName = currentData.manager?.profile?.team_name || "Team";
      subtitle = `Sales Team: ${teamName} | Manager: ${mgrName}`;
      filePrefix = `Manager_${teamName.replace(/[^a-zA-Z0-9_-]/g, "_")}_${mgrName.replace(/[^a-zA-Z0-9_-]/g, "_")}`;
    } else if (role === "agent") {
      const agentName = currentData.agent?.profile?.name || "Agent";
      const desig = currentData.agent?.profile?.designation || "";
      subtitle = `Agent: ${agentName} ${desig ? `(${desig})` : ""} | Manager: ${currentData.agent?.profile?.manager || "N/A"}`;
      filePrefix = `Agent_${agentName.replace(/[^a-zA-Z0-9_-]/g, "_")}`;
    }

    const filters = getFilterParams();
    const periodParts = [];
    if (filters.year && filters.year !== "All") periodParts.push(filters.year);
    if (filters.quarter && filters.quarter !== "All") periodParts.push(filters.quarter);
    if (filters.month && filters.month !== "All") periodParts.push(filters.month);
    const periodLabel = periodParts.length > 0 ? periodParts.join(" - ") : "All Time";
    const dealTypeLabel = filters.deal_type || "All";

    const now = new Date();
    const generatedDateStr = now.toLocaleDateString("en-GB", {
      day: "2-digit",
      month: "short",
      year: "numeric",
    }) + " " + now.toLocaleTimeString("en-US", {
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
    });
    const dateFileStr = now.toISOString().slice(0, 10);

    // Helpers for rendering charts and components
    const getChartImg = (canvasId, height = 180) => {
      const canvas = document.getElementById(canvasId);
      if (!canvas || canvas.width === 0 || canvas.height === 0) {
        return `<div style="height:${height}px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px;background:#f8fafc;border-radius:6px;">No chart data</div>`;
      }
      try {
        const dataUrl = canvas.toDataURL("image/png", 1.0);
        return `<img src="${dataUrl}" style="width:100%;height:${height}px;object-fit:contain;display:block;margin:0 auto;" />`;
      } catch (e) {
        return `<div style="height:${height}px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px;background:#f8fafc;border-radius:6px;">Chart unavailable</div>`;
      }
    };

    const getDonutCard = (title, subtitleText, canvasId, valId, valLabel, legendId, height = 120) => {
      const chartImg = getChartImg(canvasId, height);
      const totalVal = document.getElementById(valId)?.textContent || "–";
      const legendHtml = document.getElementById(legendId)?.innerHTML || "";
      return `
        <div class="pdf-card" style="display:flex;flex-direction:column;justify-content:space-between;padding:10px 12px;height:100%;">
          <div>
            <div class="pdf-card-title">${title}</div>
            <div class="pdf-card-subtitle">${subtitleText}</div>
          </div>
          <div style="position:relative;margin:4px 0;">
            ${chartImg}
            <div style="text-align:center;margin-top:-14px;font-size:10px;font-weight:700;color:#0f1e35;">${totalVal} <span style="font-size:9px;color:#64748b;font-weight:500;">${valLabel}</span></div>
          </div>
          <div style="max-height:46px;overflow:hidden;font-size:8.5px;">
            ${legendHtml}
          </div>
        </div>
      `;
    };

    const getHeaderMain = (subText) => `
      <div class="pdf-header-main">
        <div style="display:flex;align-items:center;gap:14px;">
          <img src="logo.svg" alt="Mira International" style="height:32px;width:auto;display:block;" />
          <div>
            <div style="font-size:17px;font-weight:700;color:#c9a84c;letter-spacing:0.5px;">Performance Scorecard</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px;">${subText}</div>
          </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <span style="background:rgba(201,168,76,0.15);border:1px solid rgba(201,168,76,0.3);color:#f1f5f9;padding:4px 9px;border-radius:4px;font-size:10px;font-weight:600;">Period: ${periodLabel}</span>
          <span style="background:rgba(201,168,76,0.15);border:1px solid rgba(201,168,76,0.3);color:#f1f5f9;padding:4px 9px;border-radius:4px;font-size:10px;font-weight:600;">Deal Type: ${dealTypeLabel}</span>
          <span style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#94a3b8;padding:4px 9px;border-radius:4px;font-size:10px;">${generatedDateStr}</span>
        </div>
      </div>
    `;

    const getHeaderSub = (sectionTitle, subText) => `
      <div class="pdf-header-sub">
        <div style="display:flex;align-items:center;gap:10px;">
          <img src="logo.svg" alt="Mira" style="height:18px;width:auto;display:block;filter:brightness(0.2);" />
          <div style="font-size:13px;font-weight:700;color:#0f1e35;text-transform:uppercase;letter-spacing:0.5px;">${sectionTitle}</div>
          <div style="font-size:11px;color:#64748b;">| ${subText}</div>
        </div>
        <div style="font-size:10px;color:#64748b;font-weight:600;">Period: ${periodLabel} • ${dealTypeLabel}</div>
      </div>
    `;

    const getFooter = () => `
      <div class="pdf-page-footer">
        <span>Mira Real Estate • Performance Scorecard</span>
        <span class="pdf-footer-page-num" style="font-weight:700;">Page</span>
        <span>Confidential Executive Report</span>
      </div>
    `;

    // Array of HTML strings for each page
    const pagesHtmlList = [];

    // ─────────────────────────────────────────────────────────────────────────
    // 1. CEO VIEW REPORT PAGES
    // ─────────────────────────────────────────────────────────────────────────
    if (role === "ceo") {
      const s = currentData.summary || {};
      const devs = currentData.top_developers || [];
      const devTableHtml = devs.length > 0
        ? devs.slice(0, 10).map((d) => `
            <tr>
              <td style="font-weight:600;">${d.name}</td>
              <td>AED ${fmtCurrency(d.amount)}</td>
              <td>AED ${fmtCurrency(d.commission)}</td>
              <td style="font-weight:600;text-align:center;">${d.deals}</td>
            </tr>
          `).join("")
        : (document.getElementById("developerTableBody")?.innerHTML || `<tr><td colspan="4" style="text-align:center;padding:15px;color:#94a3b8;">No developer data</td></tr>`);

      let dealTypeRows = "";
      if (currentData.sales_by_deal_type && typeof currentData.sales_by_deal_type === "object") {
        if (Array.isArray(currentData.sales_by_deal_type)) {
          dealTypeRows = currentData.sales_by_deal_type.map((r) => `
            <tr>
              <td style="font-weight:600;">${r.name || r.type || '–'}</td>
              <td>AED ${fmtCurrency(r.amount || r.sales)}</td>
              <td>AED ${fmtCurrency(r.commission)}</td>
              <td style="font-weight:600;text-align:center;">${r.deals}</td>
            </tr>
          `).join("");
        } else {
          dealTypeRows = Object.entries(currentData.sales_by_deal_type).map(([type, monthArr]) => {
            let totalSales = 0;
            let totalComm = 0;
            let totalDeals = 0;
            if (Array.isArray(monthArr)) {
              monthArr.forEach((m) => {
                totalSales += Number(m.sales) || 0;
                totalComm += Number(m.commission) || 0;
                totalDeals += Number(m.deals) || 0;
              });
            }
            return `
              <tr>
                <td style="font-weight:600;">${type}</td>
                <td>AED ${fmtCurrency(totalSales)}</td>
                <td>AED ${fmtCurrency(totalComm)}</td>
                <td style="font-weight:600;text-align:center;">${totalDeals}</td>
              </tr>
            `;
          }).join("");
        }
      }

      // CEO Page 1: KPIs & Revenue Overview
      pagesHtmlList.push(`
        <div class="pdf-report-page">
          ${getHeaderMain("Executive Company-Wide Overview")}
          <div class="pdf-page-body">
            <div class="pdf-kpi-grid">
              <div class="pdf-kpi-card highlight">
                <div class="pdf-kpi-label">Sales Volume</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.total_sales_volume)}</div>
                <div class="pdf-kpi-sub">${s.total_transactions || 0} transactions</div>
              </div>
              <div class="pdf-kpi-card highlight">
                <div class="pdf-kpi-label">Commissions</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.commissions)}</div>
                <div class="pdf-kpi-sub">Total gross commission</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Active Agents</div>
                <div class="pdf-kpi-value">${s.active_agents || 0}</div>
                <div class="pdf-kpi-sub">Current active staff</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Inactive (60+ Days)</div>
                <div class="pdf-kpi-value" style="color:#ef4444;">${s.agents_no_deal_60d || 0}</div>
                <div class="pdf-kpi-sub">Need follow-up</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Total Listings</div>
                <div class="pdf-kpi-value">${s.total_listings || 0}</div>
                <div class="pdf-kpi-sub">${s.active_listings || 0} active • ${s.pocket_listings || 0} pocket</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Avg Sales / Trans</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.avg_sales_volume)}</div>
                <div class="pdf-kpi-sub">Per closed transaction</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Avg Sales / Month</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.avg_sales_volume_per_month)}</div>
                <div class="pdf-kpi-sub">Monthly average</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Highest Sale</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.highest_sale)}</div>
                <div class="pdf-kpi-sub">Deal #${s.highest_sale_deal_id || '–'}</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Highest Commission</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.highest_commission)}</div>
                <div class="pdf-kpi-sub">Deal #${s.highest_commission_deal_id || '–'}</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Reshuffled Leads</div>
                <div class="pdf-kpi-value">${s.reshuffled_leads || 0}</div>
                <div class="pdf-kpi-sub">Reallocated leads</div>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1.2fr 1fr 0.9fr;gap:12px;flex:1;min-height:0;">
              <div class="pdf-card">
                <div class="pdf-card-title">Commission Trend</div>
                <div class="pdf-card-subtitle">Monthly gross commissions</div>
                <div style="margin-top:10px;">
                  ${getChartImg("commissionTrendChart", 190)}
                </div>
              </div>

              ${getDonutCard("Deal Type Distribution", "By sales volume", "dealDonutChart", "donutTotalValue", "Total Sales", "dealLegend", 150)}

              <div class="pdf-card" style="display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                  <div class="pdf-card-title">Commission Split</div>
                  <div class="pdf-card-subtitle">Committed vs Operational</div>
                  <div style="margin-top:10px;">
                    ${document.getElementById("commissionSplitTable")?.innerHTML || ""}
                  </div>
                </div>
                <div style="padding:10px 12px;background:#0f1e35;border-radius:6px;margin-top:10px;">
                  <div style="font-size:9px;color:#94a3b8;text-transform:uppercase;font-weight:700;">Top Deal Commission</div>
                  <div style="font-size:18px;font-weight:700;color:#e6ca65;">${document.getElementById("topCommissionVal")?.textContent || "–"}</div>
                  <div style="font-size:9px;color:#64748b;">${document.getElementById("topCommissionMeta")?.textContent || ""}</div>
                </div>
              </div>
            </div>
          </div>
          ${getFooter()}
        </div>
      `);

      // CEO Page 2: Leads by Stage & Source + Deal Closure Source
      pagesHtmlList.push(`
        <div class="pdf-report-page">
          ${getHeaderSub("Leads & Deal Source Intelligence", "Pipeline breakdown & acquisition origins")}
          <div class="pdf-page-body">
            <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;height:240px;">
              ${getDonutCard("Offplan Leads by Stage", "Current lead mix (Offplan)", "ceoLeadStageOffplanChart", "ceoLeadStageOffplanVal", "Leads", "ceoLeadStageOffplanLegend", 110)}
              ${getDonutCard("Secondary Leads by Stage", "Current lead mix (Secondary)", "ceoLeadStageSecondaryChart", "ceoLeadStageSecondaryVal", "Leads", "ceoLeadStageSecondaryLegend", 110)}
              ${getDonutCard("Offplan Leads by Source", "Lead acquisition (Offplan)", "ceoLeadSourceChart", "ceoLeadSourceVal", "Leads", "ceoLeadSourceLegend", 110)}
              ${getDonutCard("Secondary Leads by Source", "Lead acquisition (Secondary)", "ceoLeadSourceSecondaryChart", "ceoLeadSourceSecondaryVal", "Leads", "ceoLeadSourceSecondaryLegend", 110)}
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:10px;flex:1;min-height:0;">
              ${getDonutCard("Offplan Deal Closure Source", "Closed deals origin (Offplan)", "ceoDealClosureSourceOffplanChart", "ceoDealClosureSourceOffplanVal", "Deals", "ceoDealClosureSourceOffplanLegend", 120)}
              ${getDonutCard("Secondary Deal Closure Source", "Closed deals origin (Secondary)", "ceoDealClosureSourceSecondaryChart", "ceoDealClosureSourceSecondaryVal", "Deals", "ceoDealClosureSourceSecondaryLegend", 120)}

              <div class="pdf-card" style="display:flex;flex-direction:column;">
                <div class="pdf-card-title">Sales & Commission by Deal Type</div>
                <div class="pdf-card-subtitle">Performance across deal categories</div>
                <div style="margin-top:8px;overflow:hidden;flex:1;">
                  <table class="pdf-table">
                    <thead>
                      <tr>
                        <th>Deal Type</th>
                        <th>Sales Volume</th>
                        <th>Commission</th>
                        <th>Deals</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${dealTypeRows}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
          ${getFooter()}
        </div>
      `);

      // CEO Page 3: Target vs Actual + Top Developers
      pagesHtmlList.push(`
        <div class="pdf-report-page">
          ${getHeaderSub("Target vs Actual & Developer Performance", "Revenue milestones and key developer partnerships")}
          <div class="pdf-page-body">
            <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:12px;flex:1;min-height:0;">
              <div class="pdf-card" style="display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                  <div class="pdf-card-title">Target vs Actual Performance</div>
                  <div class="pdf-card-subtitle">Monthly sales targets vs achieved volume</div>
                  <div style="margin-top:12px;">
                    ${getChartImg("targetActualChart", 280)}
                  </div>
                </div>
                <div style="padding:10px;background:#f8fafc;border-radius:6px;display:flex;gap:20px;margin-top:10px;">
                  ${document.getElementById("targetActualStats")?.innerHTML || ""}
                </div>
              </div>

              <div class="pdf-card" style="display:flex;flex-direction:column;">
                <div class="pdf-card-title">Top Performing Developers</div>
                <div class="pdf-card-subtitle">Volume and gross commission by partner</div>
                <div style="margin-top:8px;overflow:hidden;flex:1;">
                  <table class="pdf-table">
                    <thead>
                      <tr>
                        <th>Developer</th>
                        <th>Amount (AED)</th>
                        <th>Commission</th>
                        <th>Deals</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${devTableHtml}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
          ${getFooter()}
        </div>
      `);

      // CEO Page 4: Team Performance Scoreboard
      const teamRows = (currentData.team_performance || [])
        .map((t, idx) => `
          <tr>
            <td style="font-weight:700;color:#0f1e35;">#${idx + 1}</td>
            <td style="font-weight:600;">${t.name}</td>
            <td style="color:#64748b;">${t.head_user_name || '–'}</td>
            <td style="font-weight:600;text-align:center;">${t.deals}</td>
            <td style="text-align:center;">${t.leads_offplan}</td>
            <td style="text-align:center;">${t.leads_secondary}</td>
            <td style="text-align:center;">${t.total_listings}</td>
            <td>AED ${fmtCurrency(t.sales)}</td>
            <td style="font-weight:600;color:#0f1e35;">AED ${fmtCurrency(t.commission)}</td>
            <td>AED ${fmtCurrency(t.top_deal, true)}</td>
            <td><span class="days-badge ${getDaysBadgeMeta(t.last_deal_days).daysClass}">${getDaysBadgeMeta(t.last_deal_days).daysLabel}</span></td>
          </tr>
        `).join("");

      pagesHtmlList.push(`
        <div class="pdf-report-page">
          ${getHeaderSub("Sales Teams Performance Leaderboard", "Comparative ranking of all sales departments")}
          <div class="pdf-page-body">
            <div class="pdf-card" style="flex:1;overflow:hidden;">
              <table class="pdf-table">
                <thead>
                  <tr>
                    <th>Rank</th>
                    <th>Team</th>
                    <th>Head of Dept</th>
                    <th style="text-align:center;">Deals</th>
                    <th style="text-align:center;">Leads (Off)</th>
                    <th style="text-align:center;">Leads (Sec)</th>
                    <th style="text-align:center;">Listings</th>
                    <th>Sales Volume</th>
                    <th>Commissions</th>
                    <th>Top Deal</th>
                    <th>Last Deal</th>
                  </tr>
                </thead>
                <tbody>
                  ${teamRows}
                </tbody>
              </table>
            </div>
          </div>
          ${getFooter()}
        </div>
      `);

      // CEO Pages 5+: Agent Performance Tables (Chunked cleanly)
      const regularAgents = (currentData.agent_performance || []).filter(
        (a) =>
          !((a.designation || "").trim().toLowerCase().startsWith("private office") || a.department_id === 23) ||
          (a.original_department_id && a.original_department_id > 0),
      );
      const sortedRegular = sortCollection(regularAgents, "agentTable", {
        name: { type: "string", get: (a) => a.name },
        reshuffled_leads: { type: "number", get: (a) => a.reshuffled_leads },
        deals: { type: "number", get: (a) => a.deals },
        total_listings: { type: "number", get: (a) => a.total_listings },
        active_listings: { type: "number", get: (a) => a.active_listings },
        pocket_listings: { type: "number", get: (a) => a.pocket_listings },
        sales: { type: "number", get: (a) => a.sales },
        commission: { type: "number", get: (a) => a.commission },
        top_deal: { type: "number", get: (a) => a.top_deal },
        avg_gap: { type: "number", get: (a) => a.avg_gap },
        last_deal_days: { type: "number", get: (a) => a.last_deal_days },
        attendance: { type: "number", get: (a) => a.attendance },
      });

      const chunkSize = 16;
      for (let i = 0; i < sortedRegular.length; i += chunkSize) {
        const chunk = sortedRegular.slice(i, i + chunkSize);
        const agentChunkRows = chunk.map((a, cIdx) => {
          const rank = i + cIdx + 1;
          const { daysClass, daysLabel } = getDaysBadgeMeta(a.last_deal_days);
          const ac = getAttendanceBadgeClass(a.attendance, a.attendance_total);
          return `
            <tr>
              <td style="font-weight:700;color:#0f1e35;">#${rank}</td>
              <td>
                <div style="font-weight:600;font-size:10px;">${a.name}</div>
                <div style="font-size:8.5px;color:#64748b;">${a.designation || ''}</div>
              </td>
              <td style="text-align:center;">${a.reshuffled_leads}</td>
              <td style="font-weight:700;text-align:center;">${a.deals}</td>
              <td style="text-align:center;">${a.total_listings}</td>
              <td style="text-align:center;">${a.active_listings}</td>
              <td>AED ${fmtCurrency(a.sales)}</td>
              <td style="font-weight:700;color:#0f1e35;">AED ${fmtCurrency(a.commission)}</td>
              <td>AED ${fmtCurrency(a.top_deal, true)}</td>
              <td><span class="days-badge ${daysClass}">${daysLabel}</span></td>
              <td><span class="days-badge ${ac}">${a.attendance} / ${a.attendance_total || 30}d</span></td>
            </tr>
          `;
        }).join("");

        pagesHtmlList.push(`
          <div class="pdf-report-page">
            ${getHeaderSub("Agent Performance Leaderboard", `Rankings ${i + 1} to ${Math.min(i + chunkSize, sortedRegular.length)} of ${sortedRegular.length} agents`)}
            <div class="pdf-page-body">
              <div class="pdf-card" style="flex:1;overflow:hidden;">
                <table class="pdf-table">
                  <thead>
                    <tr>
                      <th>Rank</th>
                      <th>Agent</th>
                      <th style="text-align:center;">Reshuffled</th>
                      <th style="text-align:center;">Deals</th>
                      <th style="text-align:center;">Listings</th>
                      <th style="text-align:center;">Active</th>
                      <th>Sales Volume</th>
                      <th>Commission</th>
                      <th>Top Deal</th>
                      <th>Last Deal</th>
                      <th>Attendance</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${agentChunkRows}
                  </tbody>
                </table>
              </div>
            </div>
            ${getFooter()}
          </div>
        `);
      }

      // CEO Page Final: Private Office Agents
      const poAgents = (currentData.agent_performance || []).filter(
        (a) => (a.designation || "").trim().toLowerCase().startsWith("private office") || a.department_id === 23,
      );
      if (poAgents.length > 0) {
        const sortedPo = sortCollection(poAgents, "agentPrivateOfficeTable", {
          name: { type: "string", get: (a) => a.name },
          reshuffled_leads: { type: "number", get: (a) => a.reshuffled_leads },
          leads_offplan: { type: "number", get: (a) => a.leads_offplan },
          leads_secondary: { type: "number", get: (a) => a.leads_secondary },
          deals: { type: "number", get: (a) => a.deals },
          total_listings: { type: "number", get: (a) => a.total_listings },
          sales: { type: "number", get: (a) => a.sales },
          commission: { type: "number", get: (a) => a.commission },
          top_deal: { type: "number", get: (a) => a.top_deal },
          last_deal_days: { type: "number", get: (a) => a.last_deal_days },
          attendance: { type: "number", get: (a) => a.attendance },
        });

        const poRows = sortedPo.map((a, idx) => {
          const { daysClass, daysLabel } = getDaysBadgeMeta(a.last_deal_days);
          const ac = getAttendanceBadgeClass(a.attendance, a.attendance_total);
          return `
            <tr>
              <td style="font-weight:700;color:#0f1e35;">#${idx + 1}</td>
              <td style="font-weight:600;">${a.name}</td>
              <td style="text-align:center;">${a.deals}</td>
              <td style="text-align:center;">${a.leads_offplan}</td>
              <td style="text-align:center;">${a.leads_secondary}</td>
              <td style="text-align:center;">${a.total_listings}</td>
              <td>AED ${fmtCurrency(a.sales)}</td>
              <td style="font-weight:700;color:#0f1e35;">AED ${fmtCurrency(a.commission)}</td>
              <td>AED ${fmtCurrency(a.top_deal, true)}</td>
              <td><span class="days-badge ${daysClass}">${daysLabel}</span></td>
              <td><span class="days-badge ${ac}">${a.attendance} / ${a.attendance_total || 30}d</span></td>
            </tr>
          `;
        }).join("");

        pagesHtmlList.push(`
          <div class="pdf-report-page">
            ${getHeaderSub("Private Office Performance", "Dedicated high-net-worth sales advisory team")}
            <div class="pdf-page-body">
              <div class="pdf-card" style="flex:1;overflow:hidden;">
                <table class="pdf-table">
                  <thead>
                    <tr>
                      <th>Rank</th>
                      <th>Private Office Advisor</th>
                      <th style="text-align:center;">Deals</th>
                      <th style="text-align:center;">Leads (Off)</th>
                      <th style="text-align:center;">Leads (Sec)</th>
                      <th style="text-align:center;">Listings</th>
                      <th>Sales Volume</th>
                      <th>Commission</th>
                      <th>Top Deal</th>
                      <th>Last Deal</th>
                      <th>Attendance</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${poRows}
                  </tbody>
                </table>
              </div>
            </div>
            ${getFooter()}
          </div>
        `);
      }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. MANAGER VIEW REPORT PAGES
    // ─────────────────────────────────────────────────────────────────────────
    else if (role === "manager") {
      const mgr = currentData.manager || {};
      const p = mgr.profile || {};
      const s = mgr.summary || {};

      // Manager Page 1: Team KPIs & Revenue
      pagesHtmlList.push(`
        <div class="pdf-report-page">
          ${getHeaderMain(`Sales Team: ${p.team_name || 'Team'} | Manager: ${p.name || 'Manager'}`)}
          <div class="pdf-page-body">
            <div class="pdf-kpi-grid">
              <div class="pdf-kpi-card highlight">
                <div class="pdf-kpi-label">Sales Volume</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.total_sales_volume)}</div>
                <div class="pdf-kpi-sub">${s.total_transactions || 0} transactions</div>
              </div>
              <div class="pdf-kpi-card highlight">
                <div class="pdf-kpi-label">Commissions</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.commissions)}</div>
                <div class="pdf-kpi-sub">Total team commission</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Active Agents</div>
                <div class="pdf-kpi-value">${s.active_agents || 0}</div>
                <div class="pdf-kpi-sub">In team roster</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Inactive (60+ Days)</div>
                <div class="pdf-kpi-value" style="color:#ef4444;">${s.agents_no_deal_60d || 0}</div>
                <div class="pdf-kpi-sub">Need deal closure</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Total Listings</div>
                <div class="pdf-kpi-value">${s.total_listings || 0}</div>
                <div class="pdf-kpi-sub">${s.active_listings || 0} active</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Avg Sales / Trans</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.avg_sales_volume)}</div>
                <div class="pdf-kpi-sub">Per transaction</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Avg Sales / Month</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.avg_sales_volume_per_month)}</div>
                <div class="pdf-kpi-sub">Monthly run rate</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Highest Sale</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.highest_sale)}</div>
                <div class="pdf-kpi-sub">Deal #${s.highest_sale_deal_id || '–'}</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Highest Commission</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.highest_commission)}</div>
                <div class="pdf-kpi-sub">Deal #${s.highest_commission_deal_id || '–'}</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Reshuffled Leads</div>
                <div class="pdf-kpi-value">${s.reshuffled_leads || 0}</div>
                <div class="pdf-kpi-sub">Team reshuffled</div>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1.2fr 1fr 0.9fr;gap:12px;flex:1;min-height:0;">
              <div class="pdf-card">
                <div class="pdf-card-title">Commission Trend</div>
                <div class="pdf-card-subtitle">Monthly team commissions</div>
                <div style="margin-top:10px;">
                  ${getChartImg("managerCommissionTrendChart", 190)}
                </div>
              </div>

              ${getDonutCard("Deal Type Distribution", "By sales volume", "managerDealDonutChart", "managerDonutVal", "Total Sales", "managerDealLegend", 150)}

              <div class="pdf-card" style="display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                  <div class="pdf-card-title">Commission Split</div>
                  <div class="pdf-card-subtitle">Committed vs Operational</div>
                  <div style="margin-top:10px;">
                    ${document.getElementById("managerCommSplit")?.innerHTML || ""}
                  </div>
                </div>
                <div style="padding:10px 12px;background:#0f1e35;border-radius:6px;margin-top:10px;">
                  <div style="font-size:9px;color:#94a3b8;text-transform:uppercase;font-weight:700;">Top Commission</div>
                  <div style="font-size:18px;font-weight:700;color:#e6ca65;">${document.getElementById("managerTopCommissionVal")?.textContent || "–"}</div>
                </div>
              </div>
            </div>
          </div>
          ${getFooter()}
        </div>
      `);

      // Manager Page 2: Leads, Deal Closure Sources & Target vs Actual
      pagesHtmlList.push(`
        <div class="pdf-report-page">
          ${getHeaderSub("Team Lead & Deal Intelligence", `${p.team_name} pipeline and performance targets`)}
          <div class="pdf-page-body">
            <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;height:240px;">
              ${getDonutCard("Offplan Leads by Stage", "Team leads (Offplan)", "managerLeadStageOffplanChart", "managerLeadStageOffplanVal", "Leads", "managerLeadStageOffplanLegend", 110)}
              ${getDonutCard("Secondary Leads by Stage", "Team leads (Secondary)", "managerLeadStageSecondaryChart", "managerLeadStageSecondaryVal", "Leads", "managerLeadStageSecondaryLegend", 110)}
              ${getDonutCard("Offplan Leads by Source", "Acquisition (Offplan)", "managerLeadSourceChart", "managerLeadSourceVal", "Leads", "managerLeadSourceLegend", 110)}
              ${getDonutCard("Secondary Leads by Source", "Acquisition (Secondary)", "managerLeadSourceSecondaryChart", "managerLeadSourceSecondaryVal", "Leads", "managerLeadSourceSecondaryLegend", 110)}
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:10px;flex:1;min-height:0;">
              ${getDonutCard("Offplan Deal Closure Source", "Closed deals (Offplan)", "managerDealClosureSourceOffplanChart", "managerDealClosureSourceOffplanVal", "Deals", "managerDealClosureSourceOffplanLegend", 120)}
              ${getDonutCard("Secondary Deal Closure Source", "Closed deals (Secondary)", "managerDealClosureSourceSecondaryChart", "managerDealClosureSourceSecondaryVal", "Deals", "managerDealClosureSourceSecondaryLegend", 120)}

              <div class="pdf-card" style="display:flex;flex-direction:column;">
                <div class="pdf-card-title">Target vs Actual Performance</div>
                <div class="pdf-card-subtitle">Monthly sales vs target quota</div>
                <div style="margin-top:8px;flex:1;">
                  ${getChartImg("managerTargetActualChart", 150)}
                </div>
              </div>
            </div>
          </div>
          ${getFooter()}
        </div>
      `);

      // Manager Page 3+: Team Agents Table
      const isDismissedTab = managerAgentStatusFilter === "dismissed";
      const statusFilteredAgents = (currentData.all_agents || []).filter((a) =>
        isDismissedTab ? a.is_dismissed === true : a.is_dismissed !== true,
      );
      const sortedMgrAgents = sortCollection(statusFilteredAgents, "managerAgentTable", {
        name: { type: "string", get: (a) => a.name },
        leads_offplan: { type: "number", get: (a) => a.leads_offplan },
        leads_secondary: { type: "number", get: (a) => a.leads_secondary },
        reshuffled_leads: { type: "number", get: (a) => a.reshuffled_leads },
        deals: { type: "number", get: (a) => a.deals },
        active_listings: { type: "number", get: (a) => a.active_listings },
        total_listings: { type: "number", get: (a) => a.total_listings },
        sales: { type: "number", get: (a) => a.sales },
        commission: { type: "number", get: (a) => a.commission },
        top_deal: { type: "number", get: (a) => a.top_deal },
        last_deal_days: { type: "number", get: (a) => a.last_deal_days },
        attendance: { type: "number", get: (a) => a.attendance },
      });

      const chunkSize = 16;
      for (let i = 0; i < Math.max(1, sortedMgrAgents.length); i += chunkSize) {
        const chunk = sortedMgrAgents.slice(i, i + chunkSize);
        const mgrAgentRows = chunk.map((a, cIdx) => {
          const rank = i + cIdx + 1;
          const { daysClass, daysLabel } = getDaysBadgeMeta(a.last_deal_days);
          const ac = getAttendanceBadgeClass(a.attendance, a.attendance_total);
          return `
            <tr>
              <td style="font-weight:700;color:#0f1e35;">#${rank}</td>
              <td>
                <div style="font-weight:600;font-size:10px;">${a.name} ${a.is_dismissed ? `<span class="days-badge crit" style="font-size:8px;padding:1px 3px;">Dismissed</span>` : ''}</div>
                <div style="font-size:8.5px;color:#64748b;">${a.designation || ''}</div>
              </td>
              <td style="text-align:center;">${a.leads_offplan}</td>
              <td style="text-align:center;">${a.leads_secondary}</td>
              <td style="text-align:center;">${a.reshuffled_leads}</td>
              <td style="font-weight:700;text-align:center;">${a.deals}</td>
              <td style="text-align:center;">${a.total_listings}</td>
              <td>AED ${fmtCurrency(a.sales)}</td>
              <td style="font-weight:700;color:#0f1e35;">AED ${fmtCurrency(a.commission)}</td>
              <td>AED ${fmtCurrency(a.top_deal, true)}</td>
              <td><span class="days-badge ${daysClass}">${daysLabel}</span></td>
              <td><span class="days-badge ${ac}">${a.attendance} / ${a.attendance_total || 30}d</span></td>
            </tr>
          `;
        }).join("");

        pagesHtmlList.push(`
          <div class="pdf-report-page">
            ${getHeaderSub("Team Agents Performance", `${p.team_name} • ${isDismissedTab ? 'Dismissed Agents' : 'Active Agents'} (${sortedMgrAgents.length} total)`)}
            <div class="pdf-page-body">
              <div class="pdf-card" style="flex:1;overflow:hidden;">
                <table class="pdf-table">
                  <thead>
                    <tr>
                      <th>Rank</th>
                      <th>Agent</th>
                      <th style="text-align:center;">Leads (Off)</th>
                      <th style="text-align:center;">Leads (Sec)</th>
                      <th style="text-align:center;">Reshuffled</th>
                      <th style="text-align:center;">Deals</th>
                      <th style="text-align:center;">Listings</th>
                      <th>Sales Volume</th>
                      <th>Commission</th>
                      <th>Top Deal</th>
                      <th>Last Deal</th>
                      <th>Attendance</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${mgrAgentRows || `<tr><td colspan="12" style="text-align:center;padding:20px;color:#94a3b8;">No ${isDismissedTab ? 'dismissed' : 'active'} agents found.</td></tr>`}
                  </tbody>
                </table>
              </div>
            </div>
            ${getFooter()}
          </div>
        `);
      }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. AGENT VIEW REPORT PAGES
    // ─────────────────────────────────────────────────────────────────────────
    else if (role === "agent") {
      const ag = currentData.agent || {};
      const p = ag.profile || {};
      const s = ag.summary || {};
      const devRows = (ag.top_developers || [])
        .map((d) => `
          <tr>
            <td style="font-weight:600;">${d.name}</td>
            <td>AED ${fmtCurrency(d.amount)}</td>
            <td>AED ${fmtCurrency(d.commission)}</td>
            <td style="font-weight:700;text-align:center;">${d.deals}</td>
          </tr>
        `).join("");

      // Agent Page 1: Profile, KPIs & Key Charts
      pagesHtmlList.push(`
        <div class="pdf-report-page">
          ${getHeaderMain(`Agent: ${p.name || 'Agent'} (${p.designation || 'Sales Agent'})`)}
          <div class="pdf-page-body">
            <div class="pdf-kpi-grid" style="grid-template-columns:repeat(4, 1fr);">
              <div class="pdf-kpi-card highlight">
                <div class="pdf-kpi-label">Sales Volume</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.total_sales_volume)}</div>
                <div class="pdf-kpi-sub">${s.total_transactions || 0} transactions</div>
              </div>
              <div class="pdf-kpi-card highlight">
                <div class="pdf-kpi-label">Commission Earned</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.commissions)}</div>
                <div class="pdf-kpi-sub">Total gross commission</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Total Listings</div>
                <div class="pdf-kpi-value">${s.total_listings || 0}</div>
                <div class="pdf-kpi-sub">${s.active_listings || 0} active listings</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Attendance Record</div>
                <div class="pdf-kpi-value">${s.attendance || 0} / ${s.attendance_total || 30}</div>
                <div class="pdf-kpi-sub">Days logged in period</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Avg Sales / Trans</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.avg_sales_volume)}</div>
                <div class="pdf-kpi-sub">Per transaction</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Highest Deal</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.highest_sale)}</div>
                <div class="pdf-kpi-sub">Deal #${s.highest_sale_deal_id || '–'}</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Top Commission</div>
                <div class="pdf-kpi-value">AED ${fmtCurrency(s.highest_commission)}</div>
                <div class="pdf-kpi-sub">Single deal record</div>
              </div>
              <div class="pdf-kpi-card">
                <div class="pdf-kpi-label">Reshuffled Leads</div>
                <div class="pdf-kpi-value">${s.reshuffled_leads || 0}</div>
                <div class="pdf-kpi-sub">Assigned leads</div>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:12px;flex:1;min-height:0;">
              <div class="pdf-card">
                <div class="pdf-card-title">Target vs Actual Performance</div>
                <div class="pdf-card-subtitle">Monthly sales vs personal quota</div>
                <div style="margin-top:10px;">
                  ${getChartImg("agentTargetActualChart", 190)}
                </div>
              </div>

              <div class="pdf-card">
                <div class="pdf-card-title">Commission Trend</div>
                <div class="pdf-card-subtitle">Monthly earnings profile</div>
                <div style="margin-top:10px;">
                  ${getChartImg("agentCommissionTrendChart", 190)}
                </div>
              </div>

              ${getDonutCard("Deal Type Distribution", "By sales volume", "agentDonutChart", "agentDonutVal", "Total Sales", "agentDealLegend", 150)}
            </div>
          </div>
          ${getFooter()}
        </div>
      `);

      // Agent Page 2: Leads, Deal Sources, Ticket Size & Developers
      pagesHtmlList.push(`
        <div class="pdf-report-page">
          ${getHeaderSub("Agent Pipeline & Developer Intelligence", `${p.name} • Lead conversion and partner breakdown`)}
          <div class="pdf-page-body">
            <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;height:240px;">
              ${getDonutCard("Offplan Leads by Stage", "Lead pipeline (Offplan)", "agentLeadStageOffplanChart", "agentLeadStageOffplanVal", "Leads", "agentLeadStageOffplanLegend", 110)}
              ${getDonutCard("Secondary Leads by Stage", "Lead pipeline (Secondary)", "agentLeadStageSecondaryChart", "agentLeadStageSecondaryVal", "Leads", "agentLeadStageSecondaryLegend", 110)}
              ${getDonutCard("Offplan Leads by Source", "Origin channel (Offplan)", "agentLeadSourceChart", "agentLeadSourceVal", "Leads", "agentLeadSourceLegend", 110)}
              ${getDonutCard("Secondary Leads by Source", "Origin channel (Secondary)", "agentLeadSourceSecondaryChart", "agentLeadSourceSecondaryVal", "Leads", "agentLeadSourceSecondaryLegend", 110)}
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:10px;flex:1;min-height:0;">
              ${getDonutCard("Offplan Deal Closure Source", "Closed deals (Offplan)", "agentDealClosureSourceOffplanChart", "agentDealClosureSourceOffplanVal", "Deals", "agentDealClosureSourceOffplanLegend", 120)}
              ${getDonutCard("Secondary Deal Closure Source", "Closed deals (Secondary)", "agentDealClosureSourceSecondaryChart", "agentDealClosureSourceSecondaryVal", "Deals", "agentDealClosureSourceSecondaryLegend", 120)}

              <div class="pdf-card" style="display:flex;flex-direction:column;">
                <div class="pdf-card-title">Top Developer Partners</div>
                <div class="pdf-card-subtitle">Volume and deals by developer</div>
                <div style="margin-top:8px;overflow:hidden;flex:1;">
                  <table class="pdf-table">
                    <thead>
                      <tr>
                        <th>Developer</th>
                        <th>Amount (AED)</th>
                        <th>Commission</th>
                        <th>Deals</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${devRows || `<tr><td colspan="4" style="text-align:center;padding:15px;color:#94a3b8;">No developer data</td></tr>`}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
          ${getFooter()}
        </div>
      `);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RENDER PAGES TO jsPDF ONE BY ONE
    // ─────────────────────────────────────────────────────────────────────────
    const exportWrapper = document.createElement("div");
    exportWrapper.id = "pdfReportExportContainer";
    exportWrapper.className = "pdf-export-container";
    exportWrapper.style.position = "absolute";
    exportWrapper.style.top = "0";
    exportWrapper.style.left = "0";
    exportWrapper.style.zIndex = "-9999";
    exportWrapper.style.opacity = "1";
    exportWrapper.style.visibility = "visible";
    exportWrapper.style.pointerEvents = "none";

    exportWrapper.innerHTML = pagesHtmlList.join("");
    document.body.appendChild(exportWrapper);

    // Update total page count in footers
    const pageElements = exportWrapper.querySelectorAll(".pdf-report-page");
    const totalPages = pageElements.length;
    pageElements.forEach((pageEl, idx) => {
      const footerSpan = pageEl.querySelector(".pdf-footer-page-num");
      if (footerSpan) {
        footerSpan.textContent = `Page ${idx + 1} of ${totalPages}`;
      }
    });

    // Wait for DOM layout to settle
    await new Promise((resolve) => setTimeout(resolve, 150));

    const fileName = `Mira_Scorecard_${filePrefix}_${dateFileStr}.pdf`;

    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({
      orientation: "landscape",
      unit: "mm",
      format: "a4",
      compress: true,
    });

    for (let i = 0; i < totalPages; i++) {
      const pageEl = pageElements[i];
      const pageCanvas = await html2canvas(pageEl, {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        logging: false,
        backgroundColor: "#ffffff",
        width: 1120,
        height: 790,
        windowWidth: 1120,
        windowHeight: 790,
        scrollX: 0,
        scrollY: 0,
      });

      const pageImgData = pageCanvas.toDataURL("image/jpeg", 0.95);
      if (i > 0) pdf.addPage();
      pdf.addImage(pageImgData, "JPEG", 0, 0, 297, 210, undefined, "FAST");
    }

    pdf.save(fileName);

    // Clean up temporary DOM container
    if (exportWrapper.parentNode) {
      exportWrapper.parentNode.removeChild(exportWrapper);
    }
  } catch (err) {
    console.error("Failed to generate PDF report:", err);
    alert("An error occurred while generating the PDF report: " + (err?.message || err));
  } finally {
    if (overlay) overlay.classList.add("hidden");
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = `
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <polyline points="7 10 12 15 17 10"/>
          <line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
        <span>Download PDF</span>
      `;
    }
  }
}


