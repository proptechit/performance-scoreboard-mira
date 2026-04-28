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
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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
$rawRole      = isset($_GET['role'])       ? trim($_GET['role'])        : '';
$rawAgentId   = isset($_GET['agent_id'])   ? (int)$_GET['agent_id']     : $currentUserId;
$rawManagerId = isset($_GET['manager_id']) ? (int)$_GET['manager_id']   : $currentUserId;
$rawDeptId    = isset($_GET['dept_id'])    ? (int)$_GET['dept_id']      : 0;
$rawYear1     = isset($_GET['year1'])      ? (int)$_GET['year1']        : 2024;
$rawYear2     = isset($_GET['year2'])      ? (int)$_GET['year2']        : 2025;

// Validate role (whitelist)
$allowedRoles = array('ceo', 'manager', 'agent');
$currentUserRole = getUserRole($currentUserId);
$requestedRole = in_array($rawRole, $allowedRoles, true) ? $rawRole : $currentUserRole;
$role = $currentUserRole;

if ($currentUserRole === 'ceo') {
    $role = $requestedRole;
} elseif ($currentUserRole === 'manager' && in_array($requestedRole, array('manager', 'agent'), true)) {
    $role = $requestedRole;
}

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
$agentId   = ($role === 'agent' && $rawAgentId > 0) ? $rawAgentId : $currentUserId;
$managerId = ($role === 'manager' && $rawManagerId > 0) ? $rawManagerId : $currentUserId;
$deptId    = ($role === 'manager' && $currentUserRole === 'ceo' && $rawDeptId > 0) ? $rawDeptId : 0;

if ($role === 'manager' && $deptId <= 0 && getUserRole($managerId) !== 'manager') {
    echo json_encode(array('error' => 'Invalid manager selection', 'manager_id' => $managerId));
    exit;
}

if (in_array($role, array('manager', 'agent'), true) && $deptId <= 0) {
    $scopedUserId = $role === 'agent' ? $agentId : $managerId;
    if (!isUserInAllowedSalesDepartments($scopedUserId)) {
        echo json_encode(array('error' => 'Selected user is outside the allowed sales departments', 'user_id' => $scopedUserId));
        exit;
    }
}

if ($role === 'agent') {
    if ($currentUserRole === 'manager') {
        $managedAgentIds = getAgentIdsByManager($currentUserId);
        if (!in_array($agentId, $managedAgentIds, true)) {
            echo json_encode(array('error' => 'Unauthorized agent selection', 'agent_id' => $agentId));
            exit;
        }
    }
}

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
    'cache_version' => CACHE_VERSION,
    'agent_id'   => $agentId,
    'manager_id' => $managerId,
    'dept_id'    => $deptId,
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
    'role'              => $role,
    'current_user_role' => $currentUserRole,
    'filters'           => $GLOBALS['CFG_FILTER_META'],
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
    $allDeals       = fetchAllDeals(array($agentId), $dateRange, $dealType);
    $wonDeals       = fetchWonDeals(array($agentId), $dateRange, $dealType);
    $committedDeals = fetchCommittedDeals(array($agentId), $dateRange, $dealType);
    $agg            = aggregateDeals($allDeals);
    $pipelineAgg    = aggregateDeals(fetchTransactionPipelineDeals());
    $monthlyDeals   = groupDealsByMonth($allDeals, $chartYear);
    $commSplit      = aggregateCommissionDeals($wonDeals, $committedDeals);
    $monthlyTarget = getAgentTarget($agentId, $workPosition);

    // Supplementary metrics
    $avgGap       = avgGapBetweenDeals($agentId, $dateRange);
    $lastDealDays = daysSinceLastDeal(array($agentId));
    $listingCount = countListingsForUsers(array($agentId));
    $attendance   = countAttendanceDays($agentId, $dateRange);
    $leadCount    = countActiveLeads(array($agentId), $dateRange);
    $reshuffled   = countReshuffledLeads(array($agentId), $dateRange);
    $leadRows     = fetchLeadBreakdownRows(array($agentId), $dateRange, $dealType);

    // Chart data
    $dealDist         = buildDealDistribution($allDeals);
    $topDevelopers    = buildTopDevelopers($allDeals, 7);
    $topPropertyTypes = buildTopPropertyTypes($allDeals);
    $targetVsActual   = buildTargetVsActual($monthlyDeals, $monthlyTarget);
    $avgTicketSize    = buildAvgTicketSize($monthlyDeals);
    $leadsByStage     = buildLeadStageBreakdown($leadRows);
    $leadsBySource    = buildLeadSourceBreakdown($leadRows);

    $commissionTrend = array();
    foreach ($monthlyDeals as $m) {
        $commissionTrend[] = array('month' => $m['month'], 'value' => $m['commission']);
    }

    $response['view']  = 'agent';
    $response['agent'] = array(
        'profile' => array(
            'name'        => fullName($userRow),
            'user_id'     => $userRow['ID'],
            'designation' => $workPosition,
            'joined'      => !empty($userRow['DATE_REGISTER']) ? date('Y-m-d', strtotime($userRow['DATE_REGISTER'])) : '',
            'manager'     => $managerName,
            'current'     => true,
        ),
        'summary' => array(
            'commissions'            => $commSplit['total'],
            'sales_volume'           => $agg['sales_volume'],
            'deal_count'             => $agg['deal_count'],
            'lead_count'             => $leadCount,
            'reshuffled_leads'       => $reshuffled,
            'listings'               => $listingCount,
            'attendance'             => $attendance,
            'avg_revenue'            => $agg['avg_sales_per_deal'],
            'avg_selling_price'      => $agg['avg_sales_per_deal'],
            'avg_gap_days'           => $avgGap,
            'top_deal'               => $pipelineAgg['top_deal'],
            'top_deal_id'            => $pipelineAgg['top_deal_id'],
            'top_commission'         => $pipelineAgg['top_commission'],
            'top_commission_id'      => $pipelineAgg['top_commission_id'],
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
        'leads_by_stage'     => $leadsByStage,
        'leads_by_source'    => $leadsBySource,
    );

    // ───────────────────────────────────────────────────────────────────────────
    // MANAGER VIEW
    // ───────────────────────────────────────────────────────────────────────────
} elseif ($role === 'manager') {
    $teamRow = array();
    if ($deptId > 0) {
        $teamRow = getSalesTeamById($deptId);
        if (empty($teamRow)) {
            echo json_encode(array('error' => 'Team not found', 'dept_id' => $deptId));
            exit;
        }
        $managerId = (int)($teamRow['UF_HEAD'] ?? 0);
    }

    $managerRow = $managerId > 0 ? getUserProfile($managerId) : array();
    if ($deptId <= 0 && empty($managerRow)) {
        echo json_encode(array('error' => 'Manager not found', 'manager_id' => $managerId));
        exit;
    }

    // All agents in this manager's department(s)
    $agentRows = array();
    if ($deptId > 0) {
        $deptAgents = getAgentsByDept(array($deptId));
        $agentIds = array_map(function ($row) {
            return (int)$row['ID'];
        }, $deptAgents);
        foreach ($deptAgents as $row) {
            $agentRows[(int)$row['ID']] = $row;
        }
    } else {
        $agentIds = getAgentIdsByManager($managerId);
        foreach ($agentIds as $aid) {
            $row = getUserProfile($aid);
            if (!empty($row)) {
                $agentRows[$aid] = $row;
            }
        }
    }

    $dealOwnerIds = $agentIds;
    if ($managerId > 0) {
        $dealOwnerIds[] = $managerId;
    }
    $dealOwnerIds = array_values(array_unique(array_map('intval', $dealOwnerIds)));

    // Team won deals
    $allDeals       = empty($dealOwnerIds) ? array() : fetchAllDeals($dealOwnerIds, $dateRange, $dealType);
    $wonDeals       = empty($dealOwnerIds) ? array() : fetchWonDeals($dealOwnerIds, $dateRange, $dealType);
    $committedDeals = empty($dealOwnerIds) ? array() : fetchCommittedDeals($dealOwnerIds, $dateRange, $dealType);
    $agg            = aggregateDeals($allDeals);
    $pipelineAgg    = aggregateDeals(fetchTransactionPipelineDeals());
    $monthlyDeals   = groupDealsByMonth($allDeals, $chartYear);
    $commSplit      = empty($dealOwnerIds) ? array(
        'total' => 0,
        'committed_commission' => 0,
        'committed_commission_pct' => 0,
        'operational_commission' => 0,
        'operational_commission_pct' => 0,
        'top_commission' => 0,
        'top_commission_id' => 0,
    ) : aggregateCommissionDeals($wonDeals, $committedDeals);
    $targetDeptId  = $deptId > 0 ? $deptId : getUserDeptId($managerId);
    $monthlyTarget = getTeamTarget($targetDeptId);

    // Team-wide supplementary
    $leadCount    = empty($agentIds) ? 0 : countActiveLeads($agentIds, $dateRange);
    $reshuffled   = empty($agentIds) ? 0 : countReshuffledLeads($agentIds, $dateRange);
    $listingCount = $deptId > 0
        ? countListingsForDepartments(array($deptId))
        : (empty($agentIds) ? 0 : countListingsForUsers($agentIds));
    $noDeal60     = countNoDealIn60Days($agentIds);
    $leadRows     = empty($agentIds) ? array() : fetchLeadBreakdownRows($agentIds, $dateRange, $dealType);

    // Charts
    $dealDist       = buildDealDistribution($allDeals);
    $targetVsActual = buildTargetVsActual($monthlyDeals, $monthlyTarget);
    $leadsByStage   = buildLeadStageBreakdown($leadRows);
    $leadsBySource  = buildLeadSourceBreakdown($leadRows);

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

    $wonDealsByAgent = array();
    foreach ($wonDeals as $d) {
        $rid = (int)$d['ASSIGNED_BY_ID'];
        if (!isset($wonDealsByAgent[$rid])) {
            $wonDealsByAgent[$rid] = array();
        }
        $wonDealsByAgent[$rid][] = $d;
    }

    $committedDealsByAgent = array();
    foreach ($committedDeals as $d) {
        $rid = (int)$d['ASSIGNED_BY_ID'];
        if (!isset($committedDealsByAgent[$rid])) {
            $committedDealsByAgent[$rid] = array();
        }
        $committedDealsByAgent[$rid][] = $d;
    }

    $allAgentRows = array();
    foreach ($agentIds as $aid) {
        if (!isset($agentRows[$aid])) {
            continue;
        }
        $agentDeals          = isset($dealsByAgent[$aid]) ? $dealsByAgent[$aid] : array();
        $agentWonDeals       = isset($wonDealsByAgent[$aid]) ? $wonDealsByAgent[$aid] : array();
        $agentCommittedDeals = isset($committedDealsByAgent[$aid]) ? $committedDealsByAgent[$aid] : array();
        $allAgentRows[] = buildAgentPerformanceRow($agentRows[$aid], $agentDeals, $agentWonDeals, $agentCommittedDeals, $dateRange);
    }

    $response['view']    = 'manager';
    $response['manager'] = array(
        'profile' => array(
            'name'        => !empty($managerRow) ? fullName($managerRow) : ($teamRow['NAME'] ?? 'Team'),
            'user_id'     => $managerRow['ID'] ?? 0,
            'designation' => $managerRow['WORK_POSITION'] ?? 'Team Manager',
            'joined'      => !empty($managerRow['DATE_REGISTER']) ? date('Y-m-d', strtotime($managerRow['DATE_REGISTER'])) : '',
            'team_name'   => $teamRow['NAME'] ?? '',
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
            'top_deal'               => $pipelineAgg['top_deal'],
            'top_deal_id'            => $pipelineAgg['top_deal_id'],
            'commissions'            => $commSplit['total'],
            'committed_commission'   => $commSplit['committed_commission'],
            'operational_commission' => $commSplit['operational_commission'],
            'avg_revenue_per_deal'   => $agg['avg_sales_per_deal'],
            'avg_revenue_per_month'  => (int)round($commSplit['total'] / 12),
            'top_commission'         => $pipelineAgg['top_commission'],
            'top_commission_id'      => $pipelineAgg['top_commission_id'],
        ),
        'commission_trend'  => $commissionTrend,
        'target_vs_actual'  => $targetVsActual,
        'deal_distribution' => $dealDist,
        'leads_by_stage'    => $leadsByStage,
        'leads_by_source'   => $leadsBySource,
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
    $allManagerIds = getSalesTeamHeadIds($salesTeams);
    $allDealOwnerIds = array_values(array_unique(array_merge($allAgentIds, $allManagerIds)));

    // Company-wide won deals (no agent filter = all)
    $allDeals       = empty($allDealOwnerIds) ? array() : fetchAllDeals($allDealOwnerIds, $dateRange, $dealType);
    $wonDeals       = empty($allDealOwnerIds) ? array() : fetchWonDeals($allDealOwnerIds, $dateRange, $dealType);
    $committedDeals = empty($allDealOwnerIds) ? array() : fetchCommittedDeals($allDealOwnerIds, $dateRange, $dealType);
    $agg            = aggregateDeals($allDeals);
    $pipelineAgg    = aggregateDeals(fetchTransactionPipelineDeals());
    $monthlyDeals   = groupDealsByMonth($allDeals, $chartYear);
    $commSplit      = empty($allDealOwnerIds) ? array(
        'total' => 0,
        'committed_commission' => 0,
        'committed_commission_pct' => 0,
        'operational_commission' => 0,
        'operational_commission_pct' => 0,
        'top_commission' => 0,
        'top_commission_id' => 0,
    ) : aggregateCommissionDeals($wonDeals, $committedDeals);
    $monthlyTarget = getCompanyTarget();

    // Company-wide supplementary
    $listings = countActiveListingsByBranches();
    $listingDetails = fetchActiveListingDetailsByBranches();
    $noDeal60 = countNoDealIn60Days($allAgentIds);
    $leadRows = empty($allAgentIds) ? array() : fetchLeadBreakdownRows($allAgentIds, $dateRange, $dealType);

    // Charts
    $dealDist         = buildDealDistribution($allDeals);
    $topDevelopers    = buildTopDevelopers($allDeals, 10);
    $topPropertyTypes = buildTopPropertyTypes($allDeals);
    $targetVsActual   = buildTargetVsActual($monthlyDeals, $monthlyTarget);
    $salesByDealType  = buildSalesByDealType($allDeals, $chartYear);
    $leadsByStage     = buildLeadStageBreakdown($leadRows);
    $leadsBySource    = buildLeadSourceBreakdown($leadRows);

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

    $wonDealsByAgent = array();
    foreach ($wonDeals as $d) {
        $rid = (int)$d['ASSIGNED_BY_ID'];
        if (!isset($wonDealsByAgent[$rid])) {
            $wonDealsByAgent[$rid] = array();
        }
        $wonDealsByAgent[$rid][] = $d;
    }

    $committedDealsByAgent = array();
    foreach ($committedDeals as $d) {
        $rid = (int)$d['ASSIGNED_BY_ID'];
        if (!isset($committedDealsByAgent[$rid])) {
            $committedDealsByAgent[$rid] = array();
        }
        $committedDealsByAgent[$rid][] = $d;
    }

    $agentPerformance = array();
    foreach ($allAgents as $agentRow) {
        $aid                 = (int)$agentRow['ID'];
        $agentDeals          = isset($dealsByAgent[$aid]) ? $dealsByAgent[$aid] : array();
        $agentWonDeals       = isset($wonDealsByAgent[$aid]) ? $wonDealsByAgent[$aid] : array();
        $agentCommittedDeals = isset($committedDealsByAgent[$aid]) ? $committedDealsByAgent[$aid] : array();
        $agentPerformance[] = buildAgentPerformanceRow($agentRow, $agentDeals, $agentWonDeals, $agentCommittedDeals, $dateRange);
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
        $teamDealOwnerIds = $teamIds;
        $teamManagerId = (int)($team['UF_HEAD'] ?? 0);
        if ($teamManagerId > 0) {
            $teamDealOwnerIds[] = $teamManagerId;
        }
        $teamDealOwnerIds = array_values(array_unique($teamDealOwnerIds));
        if (empty($teamDealOwnerIds)) {
            continue;
        }

        $teamDeals = array();
        $teamWonDeals = array();
        $teamCommittedDeals = array();
        foreach ($teamDealOwnerIds as $tid2) {
            if (isset($dealsByAgent[$tid2])) {
                foreach ($dealsByAgent[$tid2] as $d) {
                    $teamDeals[] = $d;
                }
            }
            if (isset($wonDealsByAgent[$tid2])) {
                foreach ($wonDealsByAgent[$tid2] as $d) {
                    $teamWonDeals[] = $d;
                }
            }
            if (isset($committedDealsByAgent[$tid2])) {
                foreach ($committedDealsByAgent[$tid2] as $d) {
                    $teamCommittedDeals[] = $d;
                }
            }
        }

        $tagg      = aggregateDeals($teamDeals);
        $teamComm  = aggregateCommissionDeals($teamWonDeals, $teamCommittedDeals);
        $teamList  = countListingsForDepartments(array($tid));
        $teamLeads = countActiveLeads($teamIds, $dateRange);
        $lastDeal  = daysSinceLastDeal($teamIds);

        $teamPerformance[] = array(
            'id'             => $tid,
            'name'           => $team['NAME'],
            'manager_id'     => $teamManagerId,
            'deals'          => $tagg['deal_count'],
            'leads'          => $teamLeads,
            'listings'       => $teamList,
            'sales'          => $tagg['sales_volume'],
            'commission'     => $teamComm['total'],
            'top_deal'       => $tagg['top_deal'],
            'avg_gap'        => 0,
            'last_deal_days' => $lastDeal,
        );
    }

    // ── YEAR COMPARISON ──────────────────────────────────────────────────
    $year1Monthly = empty($allAgentIds) ? groupDealsByMonth(array(), $year1) : fetchYearMonthly($year1, $allAgentIds);
    $year2Monthly = empty($allAgentIds) ? groupDealsByMonth(array(), $year2) : fetchYearMonthly($year2, $allAgentIds);
    $year1Summary = empty($allAgentIds) ? array(
        'sales' => 0,
        'commission' => 0,
        'deals' => 0,
        'agents' => 0,
        'avg_deal' => 0,
    ) : fetchYearSummary($year1, $allAgentIds);
    $year2Summary = empty($allAgentIds) ? array(
        'sales' => 0,
        'commission' => 0,
        'deals' => 0,
        'agents' => 0,
        'avg_deal' => 0,
    ) : fetchYearSummary($year2, $allAgentIds);

    // ── ASSEMBLE CEO RESPONSE ────────────────────────────────────────────
    $response['view']    = 'ceo';
    $response['summary'] = array(
        'active_agents'              => count($allAgentIds),
        'no_deal_60_days'            => $noDeal60,
        'deal_count'                 => $agg['deal_count'],
        'sales_volume'               => $agg['sales_volume'],
        'avg_sales_per_deal'         => $agg['avg_sales_per_deal'],
        'avg_sales_per_month'        => (int)round($agg['sales_volume'] / 12),
        'top_deal'                   => $pipelineAgg['top_deal'],
        'top_deal_id'                => $pipelineAgg['top_deal_id'],
        'commissions'                => $commSplit['total'],
        'committed_commission'       => $commSplit['committed_commission'],
        'committed_commission_pct'   => $commSplit['committed_commission_pct'],
        'operational_commission'     => $commSplit['operational_commission'],
        'operational_commission_pct' => $commSplit['operational_commission_pct'],
        'avg_revenue_per_deal'       => $agg['avg_sales_per_deal'],
        'avg_revenue_per_month'      => (int)round($commSplit['total'] / 12),
        'active_listings_rent'       => $listings['rent'],
        'active_listings_sale'       => $listings['sale'],
        'top_commission'             => $pipelineAgg['top_commission'],
        'top_commission_id'          => $pipelineAgg['top_commission_id'],
    );

    $response['commission_trend']   = $commissionTrend;
    $response['deal_distribution']  = $dealDist;
    $response['top_developers']     = $topDevelopers;
    $response['top_property_types'] = $topPropertyTypes;
    $response['target_vs_actual']   = $targetVsActual;
    $response['sales_by_deal_type'] = $salesByDealType;
    $response['leads_by_stage']     = $leadsByStage;
    $response['leads_by_source']    = $leadsBySource;
    $response['listing_details']    = $listingDetails;
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
