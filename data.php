<?php

/**
 * data.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Performance Scorecard – Data Endpoint
 * Mira International
 *
 * Responsibilities:
 *   1. Boot Bitrix
 *   2. Parse & validate GET params
 *   3. Check cache (return immediately if hit)
 *   4. Delegate ALL data fetching to helpers.php
 *   5. Assemble the JSON response
 *   6. Write to cache, then output JSON
 *
 * This file contains NO SQL, NO field names, NO IDs.
 * All of those live in config.php and helpers.php.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Output headers ──────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Load dependencies ───────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/helpers.php';

// ── Boot Bitrix ──────────────────────────────────────────────────────────────
bx_boot();

global $USER;

if (!$USER || !$USER->IsAuthorized()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$currentUserId = (int)$USER->GetID();

// ═══════════════════════════════════════════════════════════════════════════
// 1. PARSE & VALIDATE PARAMS
// ═══════════════════════════════════════════════════════════════════════════

$rawYear      = isset($_GET['year'])       ? trim($_GET['year'])        : 'All';
$rawQuarter   = isset($_GET['quarter'])    ? trim($_GET['quarter'])     : 'All';
$rawMonth     = isset($_GET['month'])      ? trim($_GET['month'])       : 'All';
$rawDealType  = isset($_GET['deal_type'])  ? trim($_GET['deal_type'])   : 'All';
$rawYear1     = isset($_GET['year1'])      ? (int)$_GET['year1']        : 2024;
$rawYear2     = isset($_GET['year2'])      ? (int)$_GET['year2']        : 2025;

// Validate role (whitelist)
$allowedRoles = array('ceo', 'manager', 'agent');
$role = getUserRole($currentUserId);

// Validate year
$validYears = $GLOBALS['CFG_FILTER_META']['years'];
$year       = ($rawYear === 'All' || in_array((int)$rawYear, $validYears, true)) ? $rawYear : 'All';

// Validate quarter
$validQtrs = $GLOBALS['CFG_FILTER_META']['quarters'];
$quarter   = ($rawQuarter === 'All' || in_array($rawQuarter, $validQtrs, true)) ? $rawQuarter : 'All';

// Validate month
$validMonths = $GLOBALS['CFG_FILTER_META']['months'];
$month       = ($rawMonth === 'All' || in_array($rawMonth, $validMonths, true)) ? $rawMonth : 'All';

// Validate deal type
$validTypes = $GLOBALS['CFG_FILTER_META']['deal_types'];
$dealType   = in_array($rawDealType, $validTypes, true) ? $rawDealType : 'All';

// Year comparison params
$year1 = in_array($rawYear1, $validYears, true) ? $rawYear1 : 2024;
$year2 = in_array($rawYear2, $validYears, true) ? $rawYear2 : 2025;

// Assign IDs based on role
$agentId   = $currentUserId;
$managerId = $currentUserId;

// ═══════════════════════════════════════════════════════════════════════════
// 2. BUILD DATE RANGE
// ═══════════════════════════════════════════════════════════════════════════

$dateRange = buildDateRange($year, $quarter, $month);

// Effective year for monthly charts
$chartYear = ($year !== 'All' && is_numeric($year)) ? (int)$year : (int)date('Y');

// ═══════════════════════════════════════════════════════════════════════════
// 3. CACHE LOOKUP
// ═══════════════════════════════════════════════════════════════════════════

$cache    = new ScoreboardCache();
$cacheKey = $cache->buildKey($role . '_' . $currentUserId, array(
    'agent_id'   => $agentId,
    'manager_id' => $managerId,
    'year'       => $year,
    'quarter'    => $quarter,
    'month'      => $month,
    'deal_type'  => $dealType,
    'year1'      => $year1,
    'year2'      => $year2,
));

$cached = $cache->get($cacheKey);
if ($cached !== null) {
    echo json_encode($cached);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. BUILD RESPONSE
// ═══════════════════════════════════════════════════════════════════════════

$response = array(
    'role'    => $role,
    'filters' => $GLOBALS['CFG_FILTER_META'],
);

// ───────────────────────────────────────────────────────────────────────────
// AGENT VIEW
// ───────────────────────────────────────────────────────────────────────────
if ($role === 'agent') {

    $userRow = getUserProfile($agentId);
    if (empty($userRow)) {
        echo json_encode(array('error' => 'Agent not found', 'agent_id' => $agentId));
        exit;
    }

    $managerName  = getManagerForAgent($agentId);
    $workPosition = $userRow['WORK_POSITION'] ?? '';

    // Core deal data
    $allDeals     = fetchAllDeals(array($agentId), $dateRange, $dealType);
    $agg          = aggregateDeals($allDeals);
    $monthlyDeals = groupDealsByMonth($allDeals, $chartYear);
    $commSplit    = buildCommissionSplit($allDeals, array($agentId), $dateRange, $dealType);
    $monthlyTarget = getAgentTarget($agentId, $workPosition);

    // Supplementary metrics
    $avgGap       = avgGapBetweenDeals($agentId, $dateRange);
    $lastDealDays = daysSinceLastDeal(array($agentId));
    $listingCount = countTotalListings(array($agentId));
    $attendance   = countAttendanceDays($agentId, $dateRange);
    $leadCount    = countActiveLeads(array($agentId), $dateRange);
    $reshuffled   = countReshuffledLeads(array($agentId), $dateRange);

    // Chart data
    $dealDist         = buildDealDistribution($allDeals);
    $topDevelopers    = buildTopDevelopers($allDeals, 7);
    $topPropertyTypes = buildTopPropertyTypes($allDeals);
    $targetVsActual   = buildTargetVsActual($monthlyDeals, $monthlyTarget);
    $avgTicketSize    = buildAvgTicketSize($monthlyDeals);

    $commissionTrend = array();
    foreach ($monthlyDeals as $m) {
        $commissionTrend[] = array('month' => $m['month'], 'value' => $m['commission']);
    }

    $response['view']  = 'agent';
    $response['agent'] = array(
        'profile' => array(
            'name'        => fullName($userRow),
            'employee_no' => '',
            'designation' => $workPosition,
            'joined'      => !empty($userRow['DATE_REGISTER']) ? date('Y-m-d', strtotime($userRow['DATE_REGISTER'])) : '',
            'manager'     => $managerName,
            'current'     => true,
        ),
        'summary' => array(
            'commissions'            => $commSplit['operational_commission'],
            'sales_volume'           => $agg['sales_volume'],
            'deal_count'             => $agg['deal_count'],
            'lead_count'             => $leadCount,
            'reshuffled_leads'       => $reshuffled,
            'listings'               => $listingCount,
            'attendance'             => $attendance,
            'avg_revenue'            => $agg['avg_sales_per_deal'],
            'avg_selling_price'      => $agg['avg_sales_per_deal'],
            'avg_gap_days'           => $avgGap,
            'top_deal'               => $agg['top_deal'],
            'top_commission'         => $agg['top_commission'],
            'days_since_last'        => $lastDealDays,
            'committed_commission'   => $commSplit['committed_commission'],
            'operational_commission' => $commSplit['operational_commission'],
        ),
        'target_vs_actual'   => $targetVsActual,
        'deal_distribution'  => $dealDist,
        'top_developers'     => $topDevelopers,
        'top_property_types' => $topPropertyTypes,
        'avg_ticket_size'    => $avgTicketSize,
        'commission_trend'   => $commissionTrend,
    );

    // ───────────────────────────────────────────────────────────────────────────
    // MANAGER VIEW
    // ───────────────────────────────────────────────────────────────────────────
} elseif ($role === 'manager') {

    $managerRow = getUserProfile($managerId);
    if (empty($managerRow)) {
        echo json_encode(array('error' => 'Manager not found', 'manager_id' => $managerId));
        exit;
    }

    // All agents in this manager's department(s)
    $agentIds  = getAgentIdsByManager($managerId);
    $agentRows = array();
    foreach ($agentIds as $aid) {
        $row = getUserProfile($aid);
        if (!empty($row)) {
            $agentRows[$aid] = $row;
        }
    }

    // Team won deals
    $allDeals     = fetchAllDeals($agentIds, $dateRange, $dealType);
    $agg          = aggregateDeals($allDeals);
    $monthlyDeals = groupDealsByMonth($allDeals, $chartYear);
    $commSplit    = buildCommissionSplit($allDeals, $agentIds, $dateRange, $dealType);
    $deptId       = getUserDeptId($managerId);
    $monthlyTarget = getTeamTarget($deptId);

    // Team-wide supplementary
    $leadCount    = countActiveLeads($agentIds, $dateRange);
    $reshuffled   = countReshuffledLeads($agentIds, $dateRange);
    $listingCount = countTotalListings($agentIds);
    $noDeal60     = countNoDealIn60Days($agentIds);

    // Charts
    $dealDist       = buildDealDistribution($allDeals);
    $targetVsActual = buildTargetVsActual($monthlyDeals, $monthlyTarget);

    $commissionTrend = array();
    foreach ($monthlyDeals as $m) {
        $commissionTrend[] = array('month' => $m['month'], 'value' => $m['commission']);
    }

    // Per-agent rows — slice from already-fetched deals (no extra deal queries)
    $dealsByAgent = array();
    foreach ($allDeals as $d) {
        $rid = (int)$d['ASSIGNED_BY_ID'];
        if (!isset($dealsByAgent[$rid])) {
            $dealsByAgent[$rid] = array();
        }
        $dealsByAgent[$rid][] = $d;
    }

    $allAgentRows = array();
    foreach ($agentIds as $aid) {
        if (!isset($agentRows[$aid])) {
            continue;
        }
        $agentDeals     = isset($dealsByAgent[$aid]) ? $dealsByAgent[$aid] : array();
        $allAgentRows[] = buildAgentPerformanceRow($agentRows[$aid], $agentDeals, $dateRange);
    }

    $response['view']    = 'manager';
    $response['manager'] = array(
        'profile' => array(
            'name'        => fullName($managerRow),
            'employee_no' => '',
            'designation' => $managerRow['WORK_POSITION'] ?? 'Team Leader',
            'joined'      => !empty($managerRow['DATE_REGISTER']) ? date('Y-m-d', strtotime($managerRow['DATE_REGISTER'])) : '',
        ),
        'summary' => array(
            'active_agents'          => count($agentIds),
            'no_deal_60_days'        => $noDeal60,
            'deal_count'             => $agg['deal_count'],
            'lead_count'             => $leadCount,
            'listings_count'         => $listingCount,
            'sales_volume'           => $agg['sales_volume'],
            'avg_sales_per_deal'     => $agg['avg_sales_per_deal'],
            'avg_sales_per_month'    => (int)round($agg['sales_volume'] / 12),
            'top_deal'               => $agg['top_deal'],
            'commissions'            => $commSplit['operational_commission'],
            'committed_commission'   => $commSplit['committed_commission'],
            'operational_commission' => $commSplit['operational_commission'],
            'avg_revenue_per_deal'   => $agg['avg_sales_per_deal'],
            'avg_revenue_per_month'  => (int)round($commSplit['operational_commission'] / 12),
            'top_commission'         => $agg['top_commission'],
        ),
        'commission_trend'  => $commissionTrend,
        'target_vs_actual'  => $targetVsActual,
        'deal_distribution' => $dealDist,
    );
    $response['all_agents'] = $allAgentRows;

    // ───────────────────────────────────────────────────────────────────────────
    // CEO VIEW
    // ───────────────────────────────────────────────────────────────────────────
} else {

    // All sales teams and agents
    $salesTeams  = getSalesTeams();
    $allDeptIds  = array_map(function ($t) {
        return (int)$t['ID'];
    }, $salesTeams);
    $allAgents   = empty($allDeptIds) ? array() : getAgentsByDept($allDeptIds);
    $allAgentIds = array_map(function ($a) {
        return (int)$a['ID'];
    }, $allAgents);

    // Company-wide won deals (no agent filter = all)
    $allDeals     = fetchAllDeals(array(), $dateRange, $dealType);
    $agg          = aggregateDeals($allDeals);
    $monthlyDeals = groupDealsByMonth($allDeals, $chartYear);
    $commSplit    = buildCommissionSplit($allDeals, array(), $dateRange, $dealType);
    $monthlyTarget = getCompanyTarget();

    // Company-wide supplementary
    $listings = countActiveListings(array());
    $noDeal60 = countNoDealIn60Days($allAgentIds);

    // Charts
    $dealDist         = buildDealDistribution($allDeals);
    $topDevelopers    = buildTopDevelopers($allDeals, 10);
    $topPropertyTypes = buildTopPropertyTypes($allDeals);
    $targetVsActual   = buildTargetVsActual($monthlyDeals, $monthlyTarget);
    $salesByDealType  = buildSalesByDealType($allDeals, $chartYear);

    $commissionTrend = array();
    foreach ($monthlyDeals as $m) {
        $commissionTrend[] = array('month' => $m['month'], 'value' => $m['commission']);
    }

    // ── AGENT PERFORMANCE TABLE ──────────────────────────────────────────
    // Pre-group deals by agent (single pass — avoids N queries)
    $dealsByAgent = array();
    foreach ($allDeals as $d) {
        $rid = (int)$d['ASSIGNED_BY_ID'];
        if (!isset($dealsByAgent[$rid])) {
            $dealsByAgent[$rid] = array();
        }
        $dealsByAgent[$rid][] = $d;
    }

    $agentPerformance = array();
    foreach ($allAgents as $agentRow) {
        $aid        = (int)$agentRow['ID'];
        $agentDeals = isset($dealsByAgent[$aid]) ? $dealsByAgent[$aid] : array();
        $agentPerformance[] = buildAgentPerformanceRow($agentRow, $agentDeals, $dateRange);
    }

    usort($agentPerformance, function ($a, $b) {
        return $b['sales'] - $a['sales'];
    });

    // ── TEAM PERFORMANCE TABLE ───────────────────────────────────────────
    $teamPerformance = array();
    foreach ($salesTeams as $team) {
        $tid        = (int)$team['ID'];
        $teamAgents = getAgentsByDept(array($tid));
        $teamIds    = array_map(function ($a) {
            return (int)$a['ID'];
        }, $teamAgents);

        $teamDeals = array();
        foreach ($teamIds as $tid2) {
            if (isset($dealsByAgent[$tid2])) {
                foreach ($dealsByAgent[$tid2] as $d) {
                    $teamDeals[] = $d;
                }
            }
        }

        $tagg     = aggregateDeals($teamDeals);
        $teamList = countTotalListings($teamIds);
        $teamLeads = countActiveLeads($teamIds, $dateRange);
        $lastDeal = daysSinceLastDeal($teamIds);

        $teamPerformance[] = array(
            'id'             => $tid,
            'name'           => $team['NAME'],
            'deals'          => $tagg['deal_count'],
            'leads'          => $teamLeads,
            'listings'       => $teamList,
            'sales'          => $tagg['sales_volume'],
            'commission'     => $tagg['commissions'],
            'top_deal'       => $tagg['top_deal'],
            'avg_gap'        => 0,
            'last_deal_days' => $lastDeal,
        );
    }

    // ── YEAR COMPARISON ──────────────────────────────────────────────────
    $year1Monthly = fetchYearMonthly($year1);
    $year2Monthly = fetchYearMonthly($year2);
    $year1Summary = fetchYearSummary($year1);
    $year2Summary = fetchYearSummary($year2);

    // ── ASSEMBLE CEO RESPONSE ────────────────────────────────────────────
    $response['view']    = 'ceo';
    $response['summary'] = array(
        'active_agents'              => count($allAgentIds),
        'no_deal_60_days'            => $noDeal60,
        'deal_count'                 => $agg['deal_count'],
        'sales_volume'               => $agg['sales_volume'],
        'avg_sales_per_deal'         => $agg['avg_sales_per_deal'],
        'avg_sales_per_month'        => (int)round($agg['sales_volume'] / 12),
        'top_deal'                   => $agg['top_deal'],
        'commissions'                => $commSplit['operational_commission'],
        'committed_commission'       => $commSplit['committed_commission'],
        'committed_commission_pct'   => $commSplit['committed_commission_pct'],
        'operational_commission'     => $commSplit['operational_commission'],
        'operational_commission_pct' => $commSplit['operational_commission_pct'],
        'avg_revenue_per_deal'       => $agg['avg_sales_per_deal'],
        'avg_revenue_per_month'      => (int)round($commSplit['operational_commission'] / 12),
        'active_listings_rent'       => $listings['rent'],
        'active_listings_sale'       => $listings['sale'],
        'top_commission'             => $agg['top_commission'],
    );

    $response['commission_trend']   = $commissionTrend;
    $response['deal_distribution']  = $dealDist;
    $response['top_developers']     = $topDevelopers;
    $response['top_property_types'] = $topPropertyTypes;
    $response['target_vs_actual']   = $targetVsActual;
    $response['sales_by_deal_type'] = $salesByDealType;
    $response['agent_performance']  = $agentPerformance;
    $response['team_performance']   = $teamPerformance;

    $response['year_comparison'] = array(
        'year1'         => $year1,
        'year2'         => $year2,
        'year1_monthly' => $year1Monthly,
        'year2_monthly' => $year2Monthly,
        'year1_summary' => $year1Summary,
        'year2_summary' => $year2Summary,
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. CACHE & OUTPUT
// ═══════════════════════════════════════════════════════════════════════════

$cache->set($cacheKey, $response);
echo json_encode($response);
