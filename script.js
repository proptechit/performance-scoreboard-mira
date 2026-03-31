// ── STATE ──────────────────────────────────────────────────────────────────
let currentRole = "ceo";
let currentData = null;
let compareMetric = "sales";
let charts = {};

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

// ── ROLE SWITCH ────────────────────────────────────────────────────────────
function switchRole(role) {
  currentRole = role;
  document.querySelectorAll(".role-btn").forEach((b) => {
    b.classList.toggle("active", b.textContent.toLowerCase() === role);
  });
  const labels = {
    ceo: "CEO",
    manager: "Team Manager",
    agent: "Sales Agent",
  };
  const avatarLetters = {
    ceo: "C",
    manager: "M",
    agent: "A",
  };
  document.getElementById("roleLabel").textContent = labels[role];
  document.getElementById("roleAvatar").textContent = avatarLetters[role];

  // Show/hide agent filter
  document
    .getElementById("agentFilterGroup")
    .classList.toggle("hidden", role !== "manager");

  loadDashboard();
}

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
    role: currentRole,
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
      label: "No Deal (60 Days)",
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
      label: "Deal Count",
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
      label: "Avg Sales / Deal",
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
      label: "Top Deal",
      value: "AED " + fmtCurrency(s.top_deal, true),
      sub: "Highest single sale",
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
      label: "Avg Revenue / Deal",
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
      <div class="kpi-sub">${k.sub}</div>
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
  if (!ctx || !dist) return;

  if (centerId) {
    const el = document.getElementById(centerId);
    if (el) el.textContent = fmtCurrency(totalSales, true);
  }

  charts[canvasId] = new Chart(ctx, {
    type: "doughnut",
    data: {
      labels: dist.map((d) => d.name),
      datasets: [
        {
          data: dist.map((d) => d.value),
          backgroundColor: DEAL_COLORS,
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
    legendEl.innerHTML = dist
      .map(
        (d, i) => `
      <div class="legend-item">
        <div class="legend-dot-label">
          <div class="legend-dot" style="background:${DEAL_COLORS[i]}"></div>
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
  tbody.innerHTML = devs
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

  tbody.innerHTML = types
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
  const months = [
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

  Object.entries(salesData).forEach(([type, monthArr]) => {
    const monthMap = {};
    monthArr.forEach((m) => (monthMap[m.month] = m));

    let rowSales = 0,
      rowComm = 0,
      rowDeals = 0;
    const salesCells = months.map((m, i) => {
      const d = monthMap[m];
      if (d) {
        rowSales += d.sales;
        grandTotals.sales[i] += d.sales;
        grandTotal.sales += d.sales;
        return fmtCurrency(d.sales, true);
      }
      return "–";
    });
    const commCells = months.map((m, i) => {
      const d = monthMap[m];
      if (d) {
        rowComm += d.commission;
        grandTotals.commission[i] += d.commission;
        grandTotal.commission += d.commission;
        return fmtCurrency(d.commission, true);
      }
      return "–";
    });
    const dealCells = months.map((m, i) => {
      const d = monthMap[m];
      if (d) {
        rowDeals += d.deals;
        grandTotals.deals[i] += d.deals;
        grandTotal.deals += d.deals;
        return d.deals;
      }
      return "–";
    });

    rows += `<tr class="deal-type-header"><td colspan="14" style="padding:8px 12px;font-size:12px;font-weight:700;color:rgba(255,255,255,0.9);">${type}</td></tr>`;
    rows += `<tr class="deal-type-sub"><td>↳ Sales</td>${salesCells.map((c) => `<td>${c}</td>`).join("")}<td>${fmtCurrency(rowSales, true)}</td></tr>`;
    rows += `<tr class="deal-type-sub"><td>↳ Commission</td>${commCells.map((c) => `<td>${c}</td>`).join("")}<td>${fmtCurrency(rowComm, true)}</td></tr>`;
    rows += `<tr class="deal-type-sub"><td>↳ Deal Count</td>${dealCells.map((c) => `<td>${c}</td>`).join("")}<td>${rowDeals}</td></tr>`;
  });

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
      <td style="font-weight:700;color:rgba(255,255,255,0.7);padding:8px 12px;">Grand Total – Deals</td>
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

  tbody.innerHTML = agents
    .map((a) => {
      const daysClass =
        a.last_deal_days <= 14
          ? "ok"
          : a.last_deal_days <= 30
            ? "warn"
            : "crit";
      const daysLabel =
        a.last_deal_days <= 14
          ? `${a.last_deal_days}d ago`
          : a.last_deal_days <= 30
            ? `${a.last_deal_days}d ago`
            : `${a.last_deal_days}d ⚠`;
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

  tbody.innerHTML = teams
    .map((a) => {
      const daysClass =
        a.last_deal_days <= 14
          ? "ok"
          : a.last_deal_days <= 30
            ? "warn"
            : "crit";
      const daysLabel =
        a.last_deal_days <= 14
          ? `${a.last_deal_days}d ago`
          : a.last_deal_days <= 30
            ? `${a.last_deal_days}d ago`
            : `${a.last_deal_days}d ⚠`;
      return `
    <tr onclick="drillToAgent(${a.id})">
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

function drillToAgent(agentId) {
  // Switch to agent view for this agent
  currentRole = "agent";
  document
    .querySelectorAll(".role-btn")
    .forEach((b) => b.classList.remove("active"));
  document.getElementById("f_agent") &&
    (document.getElementById("f_agent").value = agentId);
  // re-simulate by changing role params
  const params = getFilterParams();
  params.role = "agent";
  params.agent_id = agentId;
  const qs = Object.entries(params)
    .map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
    .join("&");
  document.getElementById("loadingOverlay").classList.remove("hidden");
  fetch(`data.php?${qs}`)
    .then((r) => r.json())
    .then((data) => {
      currentData = data;
      ["view-ceo", "view-manager", "view-agent"].forEach((id) =>
        document.getElementById(id).classList.add("hidden"),
      );
      document.getElementById("view-agent").classList.remove("hidden");
      renderAgent(data);
      document.getElementById("roleLabel").textContent =
        data.agent?.profile?.name || "Agent";
      document.getElementById("roleAvatar").textContent = initials(
        data.agent?.profile?.name || "A",
      );
    })
    .finally(() =>
      document.getElementById("loadingOverlay").classList.add("hidden"),
    );
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
            <span class="year-pill-stat">Deals: <strong>${fmtNum(s1.deals)}</strong></span>
            <span class="year-pill-stat">Commission: <strong>AED ${fmtCurrency(s1.commission, true)}</strong></span>
          </div>
        </div>
        <div class="year-pill year-pill-2">
          <span class="year-pill-label">${yc.year2}</span>
          <div class="year-pill-stats">
            <span class="year-pill-stat">Sales: <strong>AED ${fmtCurrency(s2.sales, true)}</strong></span>
            <span class="year-pill-stat">Deals: <strong>${fmtNum(s2.deals)}</strong></span>
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
      label: "Deal Count",
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
          <span class="profile-meta-item">Employee No: <strong>${p.employee_no}</strong></span>
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
      label: "No Deal 60 Days",
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
      label: "Deal Count",
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
      label: "Avg / Deal",
      value: "AED " + fmtCurrency(s.avg_sales_per_deal, true),
      icon: "📊",
    },
    {
      label: "Top Deal",
      value: "AED " + fmtCurrency(s.top_deal, true),
      icon: "🏆",
    },
    {
      label: "Commissions",
      value: "AED " + fmtCurrency(s.commissions, true),
      icon: "💼",
    },
    {
      label: "Top Commission",
      value: "AED " + fmtCurrency(s.top_commission, true),
      icon: "⭐",
    },
  ];

  document.getElementById("managerKpiGrid").innerHTML = kpis
    .map(
      (k, i) => `
    <div class="kpi-card ${k.highlight ? "highlight" : ""}" style="animation-delay:${0.04 + i * 0.03}s">
      <div class="kpi-label"><span>${k.label}</span><span style="font-size:16px;">${k.icon}</span></div>
      <div class="kpi-value">${k.value}</div>
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
  const tbody = document.getElementById("managerAgentTableBody");
  if (tbody && data.all_agents) {
    tbody.innerHTML = data.all_agents
      .map((a) => {
        const dc =
          a.last_deal_days <= 14
            ? "ok"
            : a.last_deal_days <= 30
              ? "warn"
              : "crit";
        const ac =
          a.attendance <= 14 ? "crit" : a.attendance <= 30 ? "warn" : "ok";
        return `<tr>
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
          <span class="profile-meta-item">Emp No: <strong>${p.employee_no}</strong></span>
          <span class="profile-meta-item">Joined: <strong>${p.joined}</strong></span>
          <span class="profile-meta-item">Days Since Last Deal: <strong style="color:${s.days_since_last > 30 ? "var(--red)" : "var(--green)"};">${s.days_since_last}</strong></span>
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
      label: "Deal Count",
      value: fmtNum(s.deal_count),
      sub: "Total closed deals",
      icon: "📋",
    },
    {
      label: "Avg Revenue / Deal",
      value: "AED " + fmtCurrency(s.avg_revenue, true),
      sub: "Net per deal",
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
      label: "Top Deal",
      value: "AED " + fmtCurrency(s.top_deal, true),
      sub: fmtCurrency(s.top_deal),
      icon: "🏆",
    },
    {
      label: "Top Commission",
      value: "AED " + fmtCurrency(s.top_commission, true),
      sub: "Single deal",
      icon: "⭐",
    },
    {
      label: "Days Since Last Deal",
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
      <div class="kpi-sub">${k.sub}</div>
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
  const tbody = document.getElementById("agentDevTableBody");
  if (tbody && ag.top_developers) {
    tbody.innerHTML = ag.top_developers
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
}

// ── BOOT ───────────────────────────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", () => {
  loadDashboard();
});
