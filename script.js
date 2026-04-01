// ── STATE ──────────────────────────────────────────────────────────────────
let currentData = null;
let compareMetric = "sales";
let charts = {};
const tableSortState = {};

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
  return name
    .split(" ")
    .map((n) => n[0])
    .join("")
    .slice(0, 3)
    .toUpperCase();
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
  const daysClass = days <= 14 ? "ok" : days <= 30 ? "warn" : "crit";
  const daysLabel = days <= 30 ? `${days}d ago` : `${days}d ⚠`;
  return { daysClass, daysLabel };
}

function setActiveView(viewId) {
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
  if (arr.includes(cur) || cur === "All") el.value = cur;
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
  return {
    year: document.getElementById("f_year")?.value || "All",
    quarter: document.getElementById("f_quarter")?.value || "All",
    month: document.getElementById("f_month")?.value || "All",
    deal_type: document.getElementById("f_deal_type")?.value || "All",
    agent_id: document.getElementById("f_agent")?.value || "all",
    year1: document.getElementById("yc_year1")?.value || 2025,
    year2: document.getElementById("yc_year2")?.value || 2026,
  };
}

var GLOBAL_DATA;

// ── DATA FETCH ─────────────────────────────────────────────────────────────
async function loadDashboard() {
  const params = getFilterParams();
  const qs = Object.entries(params)
    .map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
    .join("&");
  document.getElementById("loadingOverlay").classList.remove("hidden");

  try {
    const res = await fetch(`data.php?${qs}`);
    const data = await res.json();
    currentData = data;

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
    },
    {
      label: "Active Listings",
      value: fmtNum(s.active_listings_sale),
      sub: "For Sale",
      icon: "🏡",
      badge: null,
    },
  ];

  document.getElementById("ceoKpiGrid").innerHTML = kpis
    .map(
      (k, i) => `
    <div class="kpi-card ${k.highlight ? "highlight" : ""}" style="animation-delay:${0.04 + i * 0.03}s">
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
  renderTargetActual(data.target_vs_actual);
  // renderDeveloperTable(data.top_developers);
  handleTableFilter(data);
  renderSalesByDealTypeTable(data.sales_by_deal_type);
  renderAgentTable(data.agent_performance);
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
  document.getElementById("agentCountBadge").textContent =
    `${agents.length} agents`;

  const sortedAgents = sortCollection(agents, "agentTable", {
    name: { type: "string", get: (a) => a.name },
    deals: { type: "number", get: (a) => a.deals },
    sales: { type: "number", get: (a) => a.sales },
    commission: { type: "number", get: (a) => a.commission },
    top_deal: { type: "number", get: (a) => a.top_deal },
    avg_gap: { type: "number", get: (a) => a.avg_gap },
    last_deal_days: { type: "number", get: (a) => a.last_deal_days },
  });

  tbody.innerHTML = sortedAgents
    .map((a) => {
      const { daysClass, daysLabel } = getDaysBadgeMeta(a.last_deal_days);
      return `
    <tr onclick="drillToAgent(${a.id})">
      <td>
        <div class="agent-name-cell">
          <div class="agent-mini-avatar">${initials(a.name)}</div>
          <div>
            <div style="font-weight:600;">${a.name}</div>
            <div style="font-size:10px;color:var(--grey-400);">${a.designation}</div>
          </div>
        </div>
      </td>
      <td style="font-weight:600;">${a.deals}</td>
      <td>AED ${fmtCurrency(a.sales)}</td>
      <td>AED ${fmtCurrency(a.commission)}</td>
      <td>AED ${fmtCurrency(a.top_deal, true)}</td>
      <td>${a.avg_gap} days</td>
      <td><span class="days-badge ${daysClass}">${daysLabel}</span></td>
    </tr>
    `;
    })
    .join("");
}

function renderTeamTable(teams) {
  const tbody = document.getElementById("teamTableBody");
  if (!tbody || !teams) return;
  document.getElementById("teamCountBadge").textContent =
    `${teams.length} teams`;

  const sortedTeams = sortCollection(teams, "teamTable", {
    name: { type: "string", get: (a) => a.name },
    deals: { type: "number", get: (a) => a.deals },
    leads: { type: "number", get: (a) => a.leads },
    listings: { type: "number", get: (a) => a.listings },
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
      <td style="font-weight:600;">${a.leads}</td>
      <td style="font-weight:600;">${a.listings}</td>
      <td>AED ${fmtCurrency(a.sales)}</td>
      <td>AED ${fmtCurrency(a.commission)}</td>
      <td>AED ${fmtCurrency(a.top_deal, true)}</td>
      <td>${a.avg_gap} days</td>
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

function renderManagerAgentTable(agents) {
  const tbody = document.getElementById("managerAgentTableBody");
  if (!tbody || !agents) return;

  const sortedAgents = sortCollection(agents, "managerAgentTable", {
    name: { type: "string", get: (a) => a.name },
    leads: { type: "number", get: (a) => a.leads },
    reshuffled_leads: { type: "number", get: (a) => a.reshuffled_leads },
    deals: { type: "number", get: (a) => a.deals },
    listings: { type: "number", get: (a) => a.listings },
    sales: { type: "number", get: (a) => a.sales },
    commission: { type: "number", get: (a) => a.commission },
    top_deal: { type: "number", get: (a) => a.top_deal },
    last_deal_days: { type: "number", get: (a) => a.last_deal_days },
    attendance: { type: "number", get: (a) => a.attendance },
  });

  tbody.innerHTML = sortedAgents
    .map((a) => {
      const dc = getDaysBadgeMeta(a.last_deal_days).daysClass;
      const ac =
        a.attendance <= 14 ? "crit" : a.attendance <= 30 ? "warn" : "ok";
      return `<tr onclick="drillToAgent(${a.id})">
        <td><div class="agent-name-cell"><div class="agent-mini-avatar">${initials(a.name)}</div><div><div style="font-weight:600">${a.name}</div><div style="font-size:10px;color:var(--grey-400)">${a.designation}</div></div></div></td>
        <td>${a.leads}</td>
        <td>${a.reshuffled_leads}</td>
        <td>${a.deals}</td>
        <td>${a.listings}</td>
        <td>AED ${fmtCurrency(a.sales)}</td>
        <td>AED ${fmtCurrency(a.commission)}</td>
        <td>AED ${fmtCurrency(a.top_deal, true)}</td>
        <td><span class="days-badge ${dc}">${a.last_deal_days}d ago</span></td>
        <td><span class="days-badge ${ac}">${a.attendance} days</span></td>
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
    <div class="profile-banner">
      <div class="profile-avatar">${initials(p.name)}</div>
      <div class="profile-info">
        <div class="profile-name">${p.name}</div>
        <div class="profile-meta">
          <span class="profile-meta-item">ID: <strong>${p.user_id}</strong></span>
          <span class="profile-meta-item">Joined: <strong>${p.joined}</strong></span>
        </div>
      </div>
      <span class="profile-badge">${p.designation}</span>
    </div>
  `;

  // KPIs
  const kpis = [
    {
      label: "Active Agents",
      value: fmtNum(s.active_agents),
      icon: "👥",
    },
    {
      label: "No Transaction (in Last 60 Days)",
      value: fmtNum(s.no_deal_60_days),
      icon: "⚠️",
      highlight: true,
    },
    {
      label: "Lead Count",
      value: fmtNum(s.lead_count),
      icon: "📋",
    },
    {
      label: "Transaction Count",
      value: fmtNum(s.deal_count),
      icon: "📊",
    },
    {
      label: "Listings Count",
      value: fmtNum(s.listings_count),
      icon: "🏡",
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
      <div class="kpi-card ${k.highlight ? "highlight" : ""}" style="animation-delay:${0.04 + i * 0.03}s">
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
    <div class="profile-banner">
      <div class="profile-avatar">${initials(p.name)}</div>
      <div class="profile-info">
        <div class="profile-name">${p.name}</div>
        <div class="profile-meta">
          <span class="profile-meta-item">Manager: <strong>${p.manager}</strong></span>
          <span class="profile-meta-item">ID: <strong>${p.user_id}</strong></span>
          <span class="profile-meta-item">Joined: <strong>${p.joined}</strong></span>
          <span class="profile-meta-item">Days Since Last Closed Transaction: <strong style="color:${s.days_since_last > 30 ? "var(--red)" : "var(--green)"};">${s.days_since_last}</strong></span>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
        <span class="profile-badge">${p.designation}</span>
        ${p.current ? '<span class="current-badge">● Current</span>' : ""}
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
      value: s.avg_gap_days + " days",
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
      value: s.days_since_last + " days",
      sub: s.days_since_last > 30 ? "⚠ Follow up" : "✓ Active",
      icon: "🗓️",
      highlight: s.days_since_last > 30,
    },
  ];

  document.getElementById("agentKpiGrid").innerHTML = kpis
    .map(
      (k, i) => `
      <div class="kpi-card ${k.highlight ? "highlight" : ""}" style="animation-delay:${0.04 + i * 0.03}s">
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

  // Developer table
  renderAgentDeveloperTable(ag.top_developers);
}

// ── BOOT ───────────────────────────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", () => {
  enhanceSortableHeaders();
  loadDashboard();
});
