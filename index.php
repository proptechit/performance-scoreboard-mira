<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Scorecard – Mira International</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="styles.css">
</head>

<body>

    <!-- ── HEADER ─────────────────────────────────────────────────────────────── -->
    <header class="app-header">
        <div class="brand">
            <div class="brand-logo">
                <img src="logo.svg" alt="Mira International Logo" />
            </div>
            <div class="brand-divider"></div>
            <span class="brand-title">Performance Scorecard</span>
        </div>

        <!-- <div class="header-right">
            <div class="role-badge">
                <div class="role-avatar" id="roleAvatar">C</div>
                <span class="role-badge-text" id="roleLabel">CEO</span>
            </div>
        </div> -->
    </header>

    <!-- ── FILTER BAR ──────────────────────────────────────────────────────────── -->
    <div class="filter-bar" id="filterBar">
        <div class="filter-group">
            <span class="filter-label">Year</span>
            <select class="filter-select" id="f_year">
                <option value="All">All Years</option>
            </select>
        </div>

        <div class="filter-group">
            <span class="filter-label">Quarter</span>
            <select class="filter-select" id="f_quarter">
                <option value="All">All Quarters</option>
            </select>
        </div>

        <div class="filter-group">
            <span class="filter-label">Month</span>
            <select class="filter-select" id="f_month">
                <option value="All">All Months</option>
            </select>
        </div>

        <div class="filter-group">
            <span class="filter-label">Deal Type</span>
            <select class="filter-select" id="f_deal_type">
                <option value="All">All Types</option>
            </select>
        </div>

        <!-- Manager: agent selector -->
        <div class="filter-group hidden" id="agentFilterGroup">
            <div class="filter-divider"></div>
            <span class="filter-label">Agent</span>
            <select class="filter-select" id="f_agent">
                <option value="all">All Agents</option>
            </select>
        </div>

        <div class="filter-divider"></div>

        <button class="btn-apply" onclick="applyFilters()">Apply</button>
        <button class="btn-secondary" onclick="resetFilters()">Reset</button>
    </div>

    <!-- ── MAIN CONTENT ────────────────────────────────────────────────────────── -->
    <div class="main-content">

        <!-- Loading Indicator -->
        <div id="loadingOverlay" class="hidden" style="text-align:center;padding:60px;color:var(--grey-400);font-size:14px;">
            <div style="font-size:32px;margin-bottom:12px;">⏳</div>
            Loading dashboard data…
        </div>

        <!-- ── CEO VIEW ─────────────────────────────────────────────────────── -->
        <div id="view-ceo" class="hidden">

            <!-- KPI Row 1 -->
            <div class="section-header mb-24">
                <span class="section-title">Key Performance Indicators</span>
                <span id="ceoDateLabel" style="font-size:12px;color:var(--grey-400);font-weight:500;"></span>
            </div>

            <div class="kpi-grid" id="ceoKpiGrid"></div>

            <!-- Commission Split Row -->
            <div class="chart-grid-3 mb-24">
                <div class="chart-card" style="grid-column:1/2;">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Commission Trend</div>
                            <div class="chart-card-subtitle">Monthly gross commissions</div>
                        </div>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#c9a84c" stroke-width="2">
                            <polyline points="22 7 13.5 15.5 8.5 10.5 2 17" />
                            <polyline points="16 7 22 7 22 13" />
                        </svg>
                    </div>
                    <div class="chart-container" style="height:200px;">
                        <canvas id="commissionTrendChart"></canvas>
                    </div>
                </div>

                <div class="chart-card" style="grid-column:2/3;">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Deal Type Distribution</div>
                            <div class="chart-card-subtitle">By sales volume</div>
                        </div>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#c9a84c" stroke-width="2">
                            <path d="M21.21 15.89A10 10 0 118 2.83" />
                            <path d="M22 12A10 10 0 0012 2v10z" />
                        </svg>
                    </div>
                    <div class="donut-wrapper" style="height:200px;">
                        <canvas id="dealDonutChart"></canvas>
                        <div class="donut-center" id="donutCenter">
                            <div class="donut-center-value" id="donutTotalValue"></div>
                            <div class="donut-center-label">Total Sales</div>
                        </div>
                    </div>
                    <div class="chart-legend" id="dealLegend"></div>
                </div>

                <div class="chart-card" style="grid-column:3/4;">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">Commission Split</div>
                            <div class="chart-card-subtitle">Committed vs Operational</div>
                        </div>
                    </div>
                    <div id="commissionSplitTable"></div>

                    <!-- Top stat highlight -->
                    <div style="margin-top:20px;padding:14px;background:var(--navy);border-radius:10px;">
                        <div style="font-size:10px;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.8px;font-weight:600;margin-bottom:6px;">Top Commission</div>
                        <div style="font-size:22px;font-weight:700;color:var(--gold-light);font-variant-numeric:tabular-nums;" id="topCommissionVal">–</div>
                        <div style="font-size:11px;color:rgba(255,255,255,0.35);margin-top:4px;">Highest single deal commission</div>
                    </div>
                </div>
            </div>

            <!-- Target vs Actual + Top Developers -->
            <div class="chart-grid-2 mb-24">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title">
                                Sales & Commission by Developer
                            </div>
                            <div class="chart-card-subtitle">
                                Top performing developers
                            </div>
                        </div>
                    </div>
                    <div class="chart-container" style="height:220px;">
                        <canvas id="targetActualChart"></canvas>
                    </div>
                    <div id="targetActualStats" style="display:flex;gap:16px;margin-top:14px;flex-wrap:wrap;"></div>
                </div>

                <div class="chart-card">
                    <div class="chart-card-header">
                        <div>
                            <div class="chart-card-title" id="tableTitle">Sales & Commission by Developer</div>
                            <div class="chart-card-subtitle" id="tableSubtitle">Top performing developers</div>
                        </div>
                        <!-- <span style="font-size:11px;color:var(--grey-400);">Top 7</span> -->
                        <div style="display:flex;align-items:center;gap:10px;">
                            <select id="tableFilter" class="table-filter" onchange="handleTableFilter()">
                                <option value="developer">By Developer</option>
                                <option value="property">By Property Type</option>
                            </select>
                        </div>
                    </div>
                    <div class="agent-table-wrapper" style="max-height:300px;overflow-y:auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Developer</th>
                                    <th>Amount (AED)</th>
                                    <th>Commission</th>
                                    <th>Deals</th>
                                </tr>
                            </thead>
                            <tbody id="developerTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Sales & Commission by Deal Type -->
            <div class="chart-card mb-24">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title">Sales &amp; Commission by Deal Type</div>
                        <div class="chart-card-subtitle">Monthly breakdown per deal category</div>
                    </div>
                </div>
                <div class="agent-table-wrapper">
                    <table class="data-table" id="salesByDealTypeTable">
                        <thead>
                            <tr>
                                <th>Deal Type / Row</th>
                                <th>Jan</th>
                                <th>Feb</th>
                                <th>Mar</th>
                                <th>Apr</th>
                                <th>May</th>
                                <th>Jun</th>
                                <th>Jul</th>
                                <th>Aug</th>
                                <th>Sep</th>
                                <th>Oct</th>
                                <th>Nov</th>
                                <th>Dec</th>
                                <th>Grand Total</th>
                            </tr>
                        </thead>
                        <tbody id="salesByDealTypeBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Agent Performance Table -->
            <div class="chart-card mb-24">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title">Agent Performance Overview</div>
                        <div class="chart-card-subtitle">Click on an agent for detailed view</div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span id="agentCountBadge" style="font-size:11px;color:var(--grey-400);font-weight:500;"></span>
                        <span style="width:8px;height:8px;border-radius:50%;background:var(--red);display:inline-block;"></span>
                        <span style="font-size:11px;color:var(--grey-400);">No deal 60+ days</span>
                    </div>
                </div>
                <div class="agent-table-wrapper">
                    <table class="agent-table">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Deals</th>
                                <th>Sales Volume (AED)</th>
                                <th>Commission</th>
                                <th>Top Deal</th>
                                <th>Avg Gap</th>
                                <th>Last Deal</th>
                            </tr>
                        </thead>
                        <tbody id="agentTableBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Team Performance Table -->
            <div class="chart-card mb-24">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title">Team Performance Overview</div>
                        <div class="chart-card-subtitle">Click on a team for detailed view</div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span id="teamCountBadge" style="font-size:11px;color:var(--grey-400);font-weight:500;"></span>
                        <span style="width:8px;height:8px;border-radius:50%;background:var(--red);display:inline-block;"></span>
                        <span style="font-size:11px;color:var(--grey-400);">No deal 60+ days</span>
                    </div>
                </div>
                <div class="agent-table-wrapper">
                    <table class="agent-table">
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Deals</th>
                                <th>Leads</th>
                                <th>Listings</th>
                                <th>Sales Volume (AED)</th>
                                <th>Commission</th>
                                <th>Top Deal</th>
                                <th>Avg Gap</th>
                                <th>Last Deal</th>
                            </tr>
                        </thead>
                        <tbody id="teamTableBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Year Comparison -->
            <div class="chart-card mb-24" id="yearCompareSection">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-card-title">Year-over-Year Comparison</div>
                        <div class="chart-card-subtitle">Month-by-month performance across two years</div>
                    </div>
                    <div class="year-compare-controls">
                        <span class="filter-label" style="color:var(--grey-600);">Year 1</span>
                        <select class="year-compare-select" id="yc_year1"></select>
                        <span class="year-vs-badge">VS</span>
                        <select class="year-compare-select" id="yc_year2"></select>
                        <button class="btn-apply" onclick="updateYearComparison()" style="font-size:11px;padding:6px 14px;">Compare</button>
                    </div>
                </div>

                <div class="year-summary-pills" id="yearSummaryPills"></div>

                <div class="compare-tabs" id="compareMetricTabs">
                    <button class="compare-tab active" data-metric="sales" onclick="switchCompareMetric(this,'sales')">Sales Volume</button>
                    <button class="compare-tab" data-metric="commission" onclick="switchCompareMetric(this,'commission')">Commissions</button>
                    <button class="compare-tab" data-metric="deals" onclick="switchCompareMetric(this,'deals')">Deal Count</button>
                </div>

                <div class="chart-container" style="height:260px;">
                    <canvas id="yearCompareChart"></canvas>
                </div>
            </div>

        </div><!-- /view-ceo -->

        <!-- ── MANAGER VIEW ──────────────────────────────────────────────────────── -->
        <div id="view-manager" class="hidden">

            <div id="managerProfileBanner"></div>

            <div class="section-header mb-24" style="margin-top:16px;">
                <span class="section-title">Team Performance</span>
            </div>

            <div class="kpi-grid" id="managerKpiGrid"></div>

            <div class="chart-grid-2 mb-24">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title">Commission Trend</div>
                    </div>
                    <div class="chart-container" style="height:200px;">
                        <canvas id="managerCommChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title">Target vs Actual</div>
                    </div>
                    <div class="chart-container" style="height:200px;">
                        <canvas id="managerTargetChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="chart-card mb-24">
                <div class="chart-card-header">
                    <div class="chart-card-title">Team Agents</div>
                </div>
                <div class="agent-table-wrapper">
                    <table class="agent-table">
                        <thead>
                            <tr>
                                <th>Agent</th>
                                <th>Leads</th>
                                <th>Reshuffled Leads</th>
                                <th>Deals</th>
                                <th>Listings</th>
                                <th>Sales Volume</th>
                                <th>Commission</th>
                                <th>Top Deal</th>
                                <th>Last Deal</th>
                                <th>Attendence</th>
                            </tr>
                        </thead>
                        <tbody id="managerAgentTableBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Deal Distribution for Manager -->
            <div class="chart-grid-2 mb-24">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title">Deal Type Distribution</div>
                    </div>
                    <div class="donut-wrapper" style="height:220px;">
                        <canvas id="managerDonutChart"></canvas>
                        <div class="donut-center">
                            <div class="donut-center-value" id="managerDonutVal">–</div>
                            <div class="donut-center-label">Sales</div>
                        </div>
                    </div>
                    <div class="chart-legend" id="managerDealLegend"></div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title">Commission Split</div>
                    </div>
                    <div id="managerCommSplit"></div>
                </div>
            </div>

        </div><!-- /view-manager -->

        <!-- ── AGENT VIEW ────────────────────────────────────────────────────────── -->
        <div id="view-agent" class="hidden">

            <div id="agentProfileBanner"></div>

            <div class="kpi-grid" id="agentKpiGrid" style="margin-top:16px;"></div>

            <div class="chart-grid-3 mb-24">
                <div class="chart-card" style="grid-column:1/2;">
                    <div class="chart-card-header">
                        <div class="chart-card-title">Target vs Actual</div>
                    </div>
                    <div class="chart-container" style="height:220px;">
                        <canvas id="agentTargetChart"></canvas>
                    </div>
                </div>

                <div class="chart-card" style="grid-column:2/3;">
                    <div class="chart-card-header">
                        <div class="chart-card-title">Deal Type Distribution</div>
                    </div>
                    <div class="donut-wrapper" style="height:200px;">
                        <canvas id="agentDonutChart"></canvas>
                        <div class="donut-center">
                            <div class="donut-center-value" id="agentDonutVal">–</div>
                            <div class="donut-center-label">Volume</div>
                        </div>
                    </div>
                    <div class="chart-legend" id="agentDealLegend"></div>
                </div>

                <div class="chart-card" style="grid-column:3/4;">
                    <div class="chart-card-header">
                        <div class="chart-card-title">Average Ticket Size</div>
                    </div>
                    <div class="chart-container" style="height:200px;">
                        <canvas id="agentTicketChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Developers for Agent -->
            <div class="chart-grid-2 mb-24">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title">Sales &amp; Commission by Developer</div>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Developer</th>
                                <th>Amount (AED)</th>
                                <th>Commission</th>
                                <th>Deals</th>
                            </tr>
                        </thead>
                        <tbody id="agentDevTableBody"></tbody>
                    </table>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <div class="chart-card-title">Commission Trend</div>
                    </div>
                    <div class="chart-container" style="height:220px;">
                        <canvas id="agentCommChart"></canvas>
                    </div>
                </div>
            </div>

        </div><!-- /view-agent -->

    </div><!-- /main-content -->

    <!-- ────────────────────────────────────────────────────────────────────────── -->
    <script src="script.js"></script>
</body>

</html>