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

// ═══════════════════════════════════════════════════════════════════════════
// 1. PARSE & VALIDATE PARAMS
// ═══════════════════════════════════════════════════════════════════════════

$rawCompany   = isset($_GET['company'])    ? strtolower(trim($_GET['company'])) : '';
$rawYear      = isset($_GET['year'])       ? trim($_GET['year'])        : date('Y');
$rawQuarter   = isset($_GET['quarter'])    ? trim($_GET['quarter'])     : 'All';
$rawMonth     = isset($_GET['month'])      ? trim($_GET['month'])       : 'All';
$rawDealType  = isset($_GET['deal_type'])  ? trim($_GET['deal_type'])   : 'All';
$rawRole      = isset($_GET['role'])       ? trim($_GET['role'])        : '';
$rawAgentId   = isset($_GET['agent_id'])   ? (int)$_GET['agent_id']     : $currentUserId;
$rawManagerId = isset($_GET['manager_id']) ? (int)$_GET['manager_id']   : $currentUserId;
$rawDeptId    = isset($_GET['dept_id'])    ? (int)$_GET['dept_id']      : 0;
$rawYear1     = isset($_GET['year1'])      ? (int)$_GET['year1']        : (int)date('Y') - 1;
$rawYear2     = isset($_GET['year2'])      ? (int)$_GET['year2']        : (int)date('Y');

// Validate role (whitelist)
$allowedRoles = array('ceo', 'manager', 'agent');
$currentUserRole = getUserRole($currentUserId);
$currentUserCompany = getUserCompany($currentUserId);

$company = COMPANY_MIRA;
if ($currentUserRole === 'ceo') {
    $company = ($rawCompany === COMPANY_EVA) ? COMPANY_EVA : COMPANY_MIRA;
} else {
    // Normal agents and managers cannot switch or see other company data
    $company = $currentUserCompany;
}

$requestedRole = in_array($rawRole, $allowedRoles, true) ? $rawRole : $currentUserRole;
$role = $currentUserRole;

if ($currentUserRole === 'ceo') {
    $role = $requestedRole;
} elseif ($currentUserRole === 'manager' && in_array($requestedRole, array('manager', 'agent'), true)) {
    $role = $requestedRole;
}

// Validate year
$validYears = $GLOBALS['CFG_FILTER_META']['years'];
$year       = ($rawYear === 'All' || in_array((int)$rawYear, $validYears, true)) ? $rawYear : date('Y');

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
$year1 = in_array($rawYear1, $validYears, true) ? $rawYear1 : (int)date('Y') - 1;
$year2 = in_array($rawYear2, $validYears, true) ? $rawYear2 : (int)date('Y');

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
    if (!isUserInAllowedSalesDepartments($scopedUserId, $company)) {
        if ($currentUserRole === 'ceo') {
            $userComp = getUserCompany($scopedUserId);
            if (isUserInAllowedSalesDepartments($scopedUserId, $userComp)) {
                $company = $userComp;
            } else {
                echo json_encode(array('error' => 'Selected user is outside the allowed sales departments', 'user_id' => $scopedUserId));
                exit;
            }
        } else {
            echo json_encode(array('error' => 'Selected user is outside the allowed sales departments', 'user_id' => $scopedUserId));
            exit;
        }
    }
}

if ($role === 'agent') {
    if ($currentUserRole === 'manager') {
        // Manager-view lookup: Private Office agents should still count as normal
        // members of this manager's own department here.
        $managedAgentIds = getAgentIdsByManager($currentUserId, false, null, $company);
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
$cacheKey = $cache->buildKey($company . '_' . $role . '_' . $currentUserId, array(
    'cache_version' => CACHE_VERSION,
    'company'    => $company,
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
    'company'            => $company,
    'can_switch_company' => ($currentUserRole === 'ceo'),
    'role'               => $role,
    'current_user_role'  => $currentUserRole,
    'filters'            => $GLOBALS['CFG_FILTER_META'],
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

    $managerName  = getManagerForAgent($agentId, $company);
    $workPosition = $userRow['WORK_POSITION'] ?? '';

    // Core deal data
    $allDeals       = fetchAllDeals(array($agentId), $dateRange, $dealType, $company);
    $wonDeals       = fetchWonDeals(array($agentId), $dateRange, $dealType, $company);
    $committedDeals = fetchCommittedDeals(array($agentId), $dateRange, $dealType, $company);
    $agg            = aggregateDeals($allDeals);
    // Keep the headline "top" stats scoped to the selected agent.
    $monthlyDeals   = groupDealsByMonth($allDeals, $chartYear);
    $commSplit      = aggregateCommissionDeals($wonDeals, $committedDeals);
    $monthlyTarget  = getAgentTarget($agentId, $workPosition, $company);

    // Supplementary metrics
    $avgGap       = avgGapBetweenDeals($agentId, $dateRange, $company);
    $lastDealDays = daysSinceLastDeal(array($agentId), $company);
    $listingCount = countListingsForUsers(array($agentId));
    $listingSummary = countActiveListingsForUsers(array($agentId));
    $pocketListingSummary = countPocketListingsForUsers(array($agentId));
    $pocketListingCount = (int)$pocketListingSummary['sale'] + (int)$pocketListingSummary['rent'];

    $activeDetails = fetchActiveListingDetailsForUsers(array($agentId));
    $pocketDetails = fetchPocketListingDetailsForUsers(array($agentId));
    $listingDetails = array(
        'sale'        => $activeDetails['sale'],
        'rent'        => $activeDetails['rent'],
        'pocket_sale' => $pocketDetails['sale'],
        'pocket_rent' => $pocketDetails['rent'],
    );

    $attendance   = countAttendanceDays($agentId, $dateRange);
    try {
        $start = new \DateTime($dateRange['from']);
        $end = new \DateTime($dateRange['to']);
        $attendanceTotal = $end->diff($start)->days + 1;
    } catch (\Exception $e) {
        $attendanceTotal = 30;
    }
    $leadCountOffplan   = countActiveLeads(array($agentId), $dateRange, PIPELINE_OFFPLAN, $company);
    $leadCountSecondary = countActiveLeads(array($agentId), $dateRange, PIPELINE_SECONDARY, $company);
    $reshuffled   = countReshuffledLeads(array($agentId), $dateRange, $company);
    $leadRows     = fetchLeadBreakdownRows(array($agentId), $dateRange, $dealType, $company);

    // Chart data
    $dealDist         = buildDealDistribution($allDeals);
    $topDevelopers    = buildTopDevelopers($allDeals, 7);
    $topPropertyTypes = buildTopPropertyTypes($allDeals);
    $targetVsActual   = buildTargetVsActual($monthlyDeals, $monthlyTarget);
    $avgTicketSize    = buildAvgTicketSize($monthlyDeals);
    $leadsByStageOffplan    = buildLeadStageBreakdown($leadRows, PIPELINE_OFFPLAN);
    $leadsByStageSecondary  = buildLeadStageBreakdown($leadRows, PIPELINE_SECONDARY);
    $leadsBySource          = buildLeadSourceBreakdown($leadRows, PIPELINE_OFFPLAN);
    $leadsBySourceSecondary = buildLeadSourceBreakdown($leadRows, PIPELINE_SECONDARY);
    $dealClosureSourceOffplan   = buildDealClosureSourceBreakdown($allDeals, 76);
    $dealClosureSourceSecondary = buildDealClosureSourceBreakdown($allDeals, 75);

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
            'joined'      => formatUserJoiningDate($userRow),
            'manager'     => $managerName,
            'dept_id'     => getUserDeptId($agentId, $company),
            'current'     => true,
        ),
        'summary' => array(
            'commissions'            => $commSplit['total'],
            'sales_volume'           => $agg['sales_volume'],
            'deal_count'             => $agg['deal_count'],
            'lead_count_offplan'     => $leadCountOffplan,
            'lead_count_secondary'   => $leadCountSecondary,
            'reshuffled_leads'       => $reshuffled,
            'listings'               => (int)$listingCount + (int)$pocketListingCount,
            'total_listings'         => (int)$listingCount + (int)$pocketListingCount,
            'active_listings'        => $listingCount,
            'active_listings_rent'   => $listingSummary['rent'],
            'active_listings_sale'   => $listingSummary['sale'],
            'pocket_listings'        => $pocketListingCount,
            'pocket_listings_rent'   => $pocketListingSummary['rent'],
            'pocket_listings_sale'   => $pocketListingSummary['sale'],
            'attendance'             => $attendance,
            'attendance_total'       => $attendanceTotal,
            'avg_revenue'            => $agg['avg_commission_per_deal'],
            'avg_selling_price'      => $agg['avg_sales_per_deal'],
            'avg_gap_days'           => $avgGap,
            'top_deal'               => $agg['top_deal'],
            'top_deal_id'            => $agg['top_deal_id'],
            'top_commission'         => $agg['top_commission'],
            'top_commission_id'      => $agg['top_commission_id'],
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
        'leads_by_stage_offplan'   => $leadsByStageOffplan,
        'leads_by_stage_secondary' => $leadsByStageSecondary,
        'leads_by_source'    => $leadsBySource,
        'leads_by_source_secondary' => $leadsBySourceSecondary,
        'deal_closure_source_offplan'   => $dealClosureSourceOffplan,
        'deal_closure_source_secondary' => $dealClosureSourceSecondary,
    );
    $response['listing_details'] = $listingDetails;

    // ───────────────────────────────────────────────────────────────────────────
    // MANAGER VIEW
    // ───────────────────────────────────────────────────────────────────────────
} elseif ($role === 'manager') {
    $teamRow = array();
    if ($deptId > 0) {
        $teamRow = getSalesTeamById($deptId, $company);
        if (empty($teamRow)) {
            echo json_encode(array('error' => 'Team not found', 'dept_id' => $deptId));
            exit;
        }
        $managerId = resolveSalesTeamHeadId($teamRow, $company);
    }

    $managerRow = $managerId > 0 ? getUserProfile($managerId) : array();
    if ($deptId <= 0 && empty($managerRow)) {
        echo json_encode(array('error' => 'Manager not found', 'manager_id' => $managerId));
        exit;
    }

    if ($deptId <= 0 && $managerId > 0) {
        $resolvedDeptId = getUserDeptId($managerId, $company);
        if ($resolvedDeptId > 0) {
            $teamRow = getSalesTeamById($resolvedDeptId, $company);
        }
    }

    // All agents in this manager's department(s)
    $agentRows = array();
    $activeAgentCount = 0;
    $activeAgentIds = array();
    if ($deptId > 0) {
        // Manager-view: use each agent's real department
        $deptAgents = getAgentsByDept(array($deptId), false, $dateRange, $company);
        $dismissedAgents = getDismissedAgentsByDept(array($deptId), false, $dateRange, $company);
        $activeAgentCount = count($deptAgents);
        $activeAgentIds = array_map(function ($row) {
            return (int)$row['ID'];
        }, $deptAgents);
        $allDeptAgents = array_merge($deptAgents, $dismissedAgents);
        $agentIds = array_map(function ($row) {
            return (int)$row['ID'];
        }, $allDeptAgents);
        foreach ($allDeptAgents as $row) {
            $agentRows[(int)$row['ID']] = $row;
        }
    } else {
        $activeIds = getAgentIdsByManager($managerId, false, $dateRange, $company);
        $dismissedIds = getDismissedAgentIdsByManager($managerId, false, $dateRange, $company);
        $activeAgentCount = count($activeIds);
        $activeAgentIds = $activeIds;
        $agentIds = array_values(array_unique(array_merge($activeIds, $dismissedIds)));
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

    $targetDeptId  = $deptId > 0 ? $deptId : getUserDeptId($managerId, $company);

    // Team won deals
    $allDeals       = empty($dealOwnerIds) ? array() : fetchAllDeals($dealOwnerIds, $dateRange, $dealType, $company);
    $wonDeals       = empty($dealOwnerIds) ? array() : fetchWonDeals($dealOwnerIds, $dateRange, $dealType, $company);
    $committedDeals = empty($dealOwnerIds) ? array() : fetchCommittedDeals($dealOwnerIds, $dateRange, $dealType, $company);

    // Filter deals for the team
    $filteredAllDeals = array();
    foreach ($allDeals as $d) {
        $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
        if (isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $targetDeptId, $dealDate)) {
            $filteredAllDeals[] = $d;
        }
    }

    $filteredWonDeals = array();
    foreach ($wonDeals as $d) {
        $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
        if (isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $targetDeptId, $dealDate)) {
            $filteredWonDeals[] = $d;
        }
    }

    $filteredCommittedDeals = array();
    foreach ($committedDeals as $d) {
        $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
        if (isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $targetDeptId, $dealDate)) {
            $filteredCommittedDeals[] = $d;
        }
    }

    $agg            = aggregateDeals($filteredAllDeals);
    // Keep the headline "top" stats scoped to the selected manager team.
    $monthlyDeals   = groupDealsByMonth($filteredAllDeals, $chartYear);
    $commSplit      = empty($dealOwnerIds) ? array(
        'total' => 0,
        'committed_commission' => 0,
        'committed_commission_pct' => 0,
        'operational_commission' => 0,
        'operational_commission_pct' => 0,
        'top_commission' => 0,
        'top_commission_id' => 0,
    ) : aggregateCommissionDeals($filteredWonDeals, $filteredCommittedDeals);
    $monthlyTarget = getTeamTarget($targetDeptId, $company);

    // Team-wide supplementary (filtered to current members only for listings/leads/activity)
    $currentAgentIds = array();
    if (!empty($agentIds)) {
        foreach ($agentIds as $aid) {
            if (isAgentInDept($aid, $targetDeptId)) {
                $currentAgentIds[] = $aid;
            }
        }
    }
    $resolvedManagerId = $managerId > 0 ? $managerId : (!empty($teamRow) ? resolveSalesTeamHeadId($teamRow, $company) : 0);
    if ($resolvedManagerId > 0 && isAgentInDept($resolvedManagerId, $targetDeptId)) {
        $currentAgentIds[] = $resolvedManagerId;
    }
    $currentAgentIds = array_values(array_unique($currentAgentIds));

    $currentActiveAgentIds = array();
    if (!empty($activeAgentIds)) {
        foreach ($activeAgentIds as $aid) {
            if (isAgentInDept($aid, $targetDeptId)) {
                $currentActiveAgentIds[] = $aid;
            }
        }
    }
    $currentActiveAgentIds = array_values(array_unique($currentActiveAgentIds));

    $leadCountOffplan   = empty($currentAgentIds) ? 0 : countActiveLeads($currentAgentIds, $dateRange, PIPELINE_OFFPLAN, $company);
    $leadCountSecondary = empty($currentAgentIds) ? 0 : countActiveLeads($currentAgentIds, $dateRange, PIPELINE_SECONDARY, $company);
    $reshuffled   = empty($currentAgentIds) ? 0 : countReshuffledLeads($currentAgentIds, $dateRange, $company);
    $listingCount = $deptId > 0
        ? countListingsForDepartments(array($deptId))
        : (empty($currentAgentIds) ? 0 : countListingsForUsers($currentAgentIds));
    $pocketListingCount = $deptId > 0
        ? countPocketListingsForDepartmentsTotal(array($deptId))
        : (empty($currentAgentIds) ? 0 : countPocketListingsForUsersTotal($currentAgentIds));

    $listingSummary = $deptId > 0
        ? countActiveListingsForDepartments(array($deptId))
        : (empty($currentAgentIds) ? array('sale' => 0, 'rent' => 0) : countActiveListingsForUsers($currentAgentIds));
    $pocketListingSummary = $deptId > 0
        ? countPocketListingsForDepartments(array($deptId))
        : (empty($currentAgentIds) ? array('sale' => 0, 'rent' => 0) : countPocketListingsForUsers($currentAgentIds));

    if ($deptId > 0) {
        $activeDetails = fetchActiveListingDetailsForDepartments(array($deptId));
        $pocketDetails = fetchPocketListingDetailsForDepartments(array($deptId));
    } else {
        $activeDetails = empty($currentAgentIds) ? array('sale' => array(), 'rent' => array()) : fetchActiveListingDetailsForUsers($currentAgentIds);
        $pocketDetails = empty($currentAgentIds) ? array('sale' => array(), 'rent' => array()) : fetchPocketListingDetailsForUsers($currentAgentIds);
    }
    $listingDetails = array(
        'sale'        => $activeDetails['sale'],
        'rent'        => $activeDetails['rent'],
        'pocket_sale' => $pocketDetails['sale'],
        'pocket_rent' => $pocketDetails['rent'],
    );
    $noDeal60Details    = getNoDealIn60DaysDetails($currentActiveAgentIds, $company);
    $activeAgentDetails = getActiveAgentsDetails($currentActiveAgentIds, $company);
    $noDeal60           = count($noDeal60Details);
    $deptUserIds  = $targetDeptId > 0 ? getDeptUserIds(array($targetDeptId), false, null, $company) : array();
    $leadRows     = empty($deptUserIds) ? array() : fetchLeadBreakdownRows($deptUserIds, $dateRange, $dealType, $company);

    // Charts
    $dealDist       = buildDealDistribution($filteredAllDeals);
    $targetVsActual = buildTargetVsActual($monthlyDeals, $monthlyTarget);
    $leadsByStageOffplan    = buildLeadStageBreakdown($leadRows, PIPELINE_OFFPLAN);
    $leadsByStageSecondary  = buildLeadStageBreakdown($leadRows, PIPELINE_SECONDARY);
    $leadsBySource          = buildLeadSourceBreakdown($leadRows, PIPELINE_OFFPLAN);
    $leadsBySourceSecondary = buildLeadSourceBreakdown($leadRows, PIPELINE_SECONDARY);
    $dealClosureSourceOffplan   = buildDealClosureSourceBreakdown($filteredAllDeals, 76);
    $dealClosureSourceSecondary = buildDealClosureSourceBreakdown($filteredAllDeals, 75);

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
        $agentDeals          = isset($dealsByAgent[$aid]) ? array_values(array_filter($dealsByAgent[$aid], function($d) use ($targetDeptId) {
            $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
            return isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $targetDeptId, $dealDate);
        })) : array();

        $agentWonDeals       = isset($wonDealsByAgent[$aid]) ? array_values(array_filter($wonDealsByAgent[$aid], function($d) use ($targetDeptId) {
            $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
            return isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $targetDeptId, $dealDate);
        })) : array();

        $agentCommittedDeals = isset($committedDealsByAgent[$aid]) ? array_values(array_filter($committedDealsByAgent[$aid], function($d) use ($targetDeptId) {
            $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
            return isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $targetDeptId, $dealDate);
        })) : array();

        $allAgentRows[] = buildAgentPerformanceRow($agentRows[$aid], $agentDeals, $agentWonDeals, $agentCommittedDeals, $dateRange, $targetDeptId, $company);
    }

    usort($allAgentRows, function ($a, $b) {
        return $b['commission'] - $a['commission'];
    });

    $response['view']    = 'manager';
    $response['manager'] = array(
        'profile' => array(
            'name'        => !empty($managerRow) ? fullName($managerRow) : (($teamRow['DISPLAY_NAME'] ?? '') ?: ($teamRow['NAME'] ?? 'Team')),
            'user_id'     => $managerRow['ID'] ?? 0,
            'designation' => $managerRow['WORK_POSITION'] ?? 'Team Manager',
            'joined'      => formatUserJoiningDate($managerRow),
            'team_name'   => ($teamRow['DISPLAY_NAME'] ?? '') ?: ($teamRow['NAME'] ?? ''),
        ),
        'summary' => array(
            'active_agents'          => $activeAgentCount,
            'no_deal_60_days'        => $noDeal60,
            'deal_count'             => $agg['deal_count'],
            'lead_count_offplan'     => $leadCountOffplan,
            'lead_count_secondary'   => $leadCountSecondary,
            'listings_count'         => (int)$listingCount + (int)$pocketListingCount,
            'total_listings_count'   => (int)$listingCount + (int)$pocketListingCount,
            'active_listings_count'  => $listingCount,
            'active_listings_rent'   => $listingSummary['rent'],
            'active_listings_sale'   => $listingSummary['sale'],
            'pocket_listings_count'  => $pocketListingCount,
            'pocket_listings_rent'   => $pocketListingSummary['rent'],
            'pocket_listings_sale'   => $pocketListingSummary['sale'],
            'sales_volume'           => $agg['sales_volume'],
            'avg_sales_per_deal'     => $agg['avg_sales_per_deal'],
            'avg_sales_per_month'    => (int)round($agg['sales_volume'] / 12),
            'top_deal'               => $agg['top_deal'],
            'top_deal_id'            => $agg['top_deal_id'],
            'commissions'            => $commSplit['total'],
            'committed_commission'   => $commSplit['committed_commission'],
            'operational_commission' => $commSplit['operational_commission'],
            'avg_revenue_per_deal'   => $agg['avg_commission_per_deal'],
            'avg_revenue_per_month'  => (int)round($commSplit['total'] / 12),
            'top_commission'         => $agg['top_commission'],
            'top_commission_id'      => $agg['top_commission_id'],
        ),
        'commission_trend'  => $commissionTrend,
        'target_vs_actual'  => $targetVsActual,
        'deal_distribution' => $dealDist,
        'leads_by_stage_offplan'   => $leadsByStageOffplan,
        'leads_by_stage_secondary' => $leadsByStageSecondary,
        'leads_by_source'   => $leadsBySource,
        'leads_by_source_secondary' => $leadsBySourceSecondary,
        'deal_closure_source_offplan'   => $dealClosureSourceOffplan,
        'deal_closure_source_secondary' => $dealClosureSourceSecondary,
    );
    $response['all_agents']            = $allAgentRows;
    $response['listing_details']       = $listingDetails;
    $response['active_agents_details'] = $activeAgentDetails;
    $response['no_deal_60_details']    = $noDeal60Details;

    // ───────────────────────────────────────────────────────────────────────────
    // CEO VIEW
    // ───────────────────────────────────────────────────────────────────────────
} else {

    // All sales teams and agents
    $salesTeams      = getSalesTeams($company);
    $allDeptIds      = array_map(function ($t) {
        return (int)$t['ID'];
    }, $salesTeams);
    $activeAgents    = empty($allDeptIds) ? array() : getAgentsByDept($allDeptIds, true, $dateRange, $company);
    $dismissedAgents = empty($allDeptIds) ? array() : getDismissedAgentsByDept($allDeptIds, true, $dateRange, $company);

    // Explicitly include user ID 168 and 156 for Mira
    if ($company === COMPANY_MIRA) {
        $specialUserIds = array(168, 156);
        foreach ($specialUserIds as $specialId) {
            $found = false;
            foreach ($activeAgents as $a) {
                if ((int)$a['ID'] === $specialId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                foreach ($dismissedAgents as $a) {
                    if ((int)$a['ID'] === $specialId) {
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                $userProfile = getUserProfile($specialId);
                if (!empty($userProfile)) {
                    if (($userProfile['ACTIVE'] ?? 'Y') === 'N') {
                        $dismissedAgents[] = $userProfile;
                    } else {
                        $activeAgents[] = $userProfile;
                    }
                }
            }
        }
    }

    // Filter out users with excluded work positions (PA Liaison, Listing Admin)
    $activeAgents = array_values(array_filter($activeAgents, function ($a) {
        $pos = strtolower(trim($a['WORK_POSITION'] ?? ''));
        return ($pos === '' || (strpos($pos, 'pa liaison') === false && strpos($pos, 'listing admin') === false));
    }));
    $dismissedAgents = array_values(array_filter($dismissedAgents, function ($a) {
        $pos = strtolower(trim($a['WORK_POSITION'] ?? ''));
        return ($pos === '' || (strpos($pos, 'pa liaison') === false && strpos($pos, 'listing admin') === false));
    }));

    $allAgents = array_merge($activeAgents, $dismissedAgents);

    $allActiveAgentIds = array_map(function ($a) {
        return (int)$a['ID'];
    }, $activeAgents);
    $allAgentIds = $allActiveAgentIds;
    $allManagerIds = getSalesTeamHeadIds($salesTeams);
    $cfgManagerIds = ($company === COMPANY_EVA)
        ? ($GLOBALS['CFG_MANAGER_USER_IDS_EVA'] ?? array())
        : ($GLOBALS['CFG_MANAGER_USER_IDS_MIRA'] ?? array());
    $allManagerUserIds = array_values(array_unique(array_filter(array_merge($allManagerIds, $cfgManagerIds), function ($id) {
        return (int)$id > 0;
    })));
    $allDealOwnerIds = array_values(array_unique(array_merge($allActiveAgentIds, $allManagerUserIds)));

    // Company-wide won deals (no agent filter = all)
    $allDeals       = fetchAllDeals(array(), $dateRange, $dealType, $company);
    $wonDeals       = fetchWonDeals(array(), $dateRange, $dealType, $company);
    $committedDeals = fetchCommittedDeals(array(), $dateRange, $dealType, $company);
    $agg            = aggregateDeals($allDeals);
    // CEO view intentionally remains company-wide.
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
    $monthlyTarget = getCompanyTarget($company);

    // Company-wide supplementary
    if ($company === COMPANY_EVA) {
        $listings = empty($allDealOwnerIds) ? array('sale' => 0, 'rent' => 0) : countActiveListingsForUsers($allDealOwnerIds);
        $pocketListings = empty($allDealOwnerIds) ? array('sale' => 0, 'rent' => 0) : countPocketListingsForUsers($allDealOwnerIds);
        $activeDetails = empty($allDealOwnerIds) ? array('sale' => array(), 'rent' => array()) : fetchActiveListingDetailsForUsers($allDealOwnerIds);
        $pocketDetails = empty($allDealOwnerIds) ? array('sale' => array(), 'rent' => array()) : fetchPocketListingDetailsForUsers($allDealOwnerIds);
    } else {
        $listings = countActiveListingsByBranches();
        $pocketListings = countPocketListingsByBranches();
        $activeDetails = fetchActiveListingDetailsByBranches();
        $pocketDetails = fetchPocketListingDetailsByBranches();
    }
    $listingDetails = array(
        'sale'        => $activeDetails['sale'],
        'rent'        => $activeDetails['rent'],
        'pocket_sale' => $pocketDetails['sale'],
        'pocket_rent' => $pocketDetails['rent'],
    );
    $noDeal60Details    = getNoDealIn60DaysDetails($allAgentIds, $company);
    $activeAgentDetails = getActiveAgentsDetails($allAgentIds, $company);
    $noDeal60           = count($noDeal60Details);
    $leadRows = fetchLeadBreakdownRows(array(), $dateRange, $dealType, $company);

    // Charts
    $dealDist         = buildDealDistribution($allDeals);
    $topDevelopers    = buildTopDevelopers($allDeals, 10);
    $topPropertyTypes = buildTopPropertyTypes($allDeals);
    $targetVsActual   = buildTargetVsActual($monthlyDeals, $monthlyTarget);
    $salesByDealType  = buildSalesByDealType($allDeals, $chartYear);
    $leadsByStageOffplan    = buildLeadStageBreakdown($leadRows, PIPELINE_OFFPLAN);
    $leadsByStageSecondary  = buildLeadStageBreakdown($leadRows, PIPELINE_SECONDARY);
    $leadsBySource          = buildLeadSourceBreakdown($leadRows, PIPELINE_OFFPLAN);
    $leadsBySourceSecondary = buildLeadSourceBreakdown($leadRows, PIPELINE_SECONDARY);
    $dealClosureSourceOffplan   = buildDealClosureSourceBreakdown($allDeals, 76);
    $dealClosureSourceSecondary = buildDealClosureSourceBreakdown($allDeals, 75);

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
        $agentPerformance[] = buildAgentPerformanceRow($agentRow, $agentDeals, $agentWonDeals, $agentCommittedDeals, $dateRange, 0, $company);
    }

    usort($agentPerformance, function ($a, $b) {
        return $b['commission'] - $a['commission'];
    });

    // ── MANAGER PERFORMANCE TABLE ────────────────────────────────────────
    $managerUsers = array();
    if (!empty($allManagerUserIds)) {
        $inMgr = inClauseInt($allManagerUserIds);
        $managerUsers = dbQuery("
            SELECT 
                u.ID,
                u.ACTIVE,
                u.NAME,
                u.LAST_NAME,
                u.WORK_POSITION,
                u.DATE_REGISTER,
                u.PERSONAL_PHOTO,
                uts_u.UF_USR_1778656838068,
                uts_u." . FIELD_COMPANY_USER . " AS company_enum
            FROM b_user u
            LEFT JOIN b_uts_user uts_u
                ON uts_u.VALUE_ID = u.ID
            WHERE u.ID IN {$inMgr}
            ORDER BY u.LAST_NAME ASC, u.NAME ASC
        ");
    }

    $managerTeamMap = array();
    foreach ($salesTeams as $team) {
        $hid = resolveSalesTeamHeadId($team, $company);
        if ($hid > 0) {
            $managerTeamMap[$hid] = ($team['DISPLAY_NAME'] ?? '') ?: $team['NAME'];
        }
    }

    $managerPerformance = array();
    foreach ($managerUsers as $managerRow) {
        $mid                   = (int)$managerRow['ID'];
        $managerDeals          = isset($dealsByAgent[$mid]) ? $dealsByAgent[$mid] : array();
        $managerWonDeals       = isset($wonDealsByAgent[$mid]) ? $wonDealsByAgent[$mid] : array();
        $managerCommittedDeals = isset($committedDealsByAgent[$mid]) ? $committedDealsByAgent[$mid] : array();
        $row = buildAgentPerformanceRow($managerRow, $managerDeals, $managerWonDeals, $managerCommittedDeals, $dateRange, 0, $company);
        if (empty($row['designation']) && isset($managerTeamMap[$mid])) {
            $row['designation'] = $managerTeamMap[$mid];
        }
        $managerPerformance[] = $row;
    }

    usort($managerPerformance, function ($a, $b) {
        return $b['commission'] - $a['commission'];
    });

    // ── TEAM PERFORMANCE TABLE ───────────────────────────────────────────
    $teamPerformance = array();
    foreach ($salesTeams as $team) {
        $tid        = (int)$team['ID'];
        if ($company === COMPANY_MIRA && $tid === 23) {
            continue;
        }
        $teamAgents = getAgentsByDept(array($tid), true, $dateRange, $company);
        $teamIds    = array_map(function ($a) {
            return (int)$a['ID'];
        }, $teamAgents);
        $teamDealOwnerIds = $teamIds;
        $teamManagerId = resolveSalesTeamHeadId($team, $company);
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
                    $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
                    if (isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $tid, $dealDate)) {
                        $teamDeals[] = $d;
                    }
                }
            }
            if (isset($wonDealsByAgent[$tid2])) {
                foreach ($wonDealsByAgent[$tid2] as $d) {
                    $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
                    if (isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $tid, $dealDate)) {
                        $teamWonDeals[] = $d;
                    }
                }
            }
            if (isset($committedDealsByAgent[$tid2])) {
                foreach ($committedDealsByAgent[$tid2] as $d) {
                    $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
                    if (isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $tid, $dealDate)) {
                        $teamCommittedDeals[] = $d;
                    }
                }
            }
        }

        $tagg      = aggregateDeals($teamDeals);
        $teamComm  = aggregateCommissionDeals($teamWonDeals, $teamCommittedDeals);
        $teamList  = countListingsForDepartments(array($tid));
        $teamPocketList = countPocketListingsForDepartmentsTotal(array($tid));
        $currentTeamIds = array();
        if (!empty($teamIds)) {
            foreach ($teamIds as $aid) {
                if (isAgentInDept($aid, $tid)) {
                    $currentTeamIds[] = $aid;
                }
            }
        }
        if ($teamManagerId > 0 && isAgentInDept($teamManagerId, $tid)) {
            $currentTeamIds[] = $teamManagerId;
        }
        $currentTeamIds = array_values(array_unique($currentTeamIds));

        $teamLeadsOffplan   = empty($currentTeamIds) ? 0 : countActiveLeads($currentTeamIds, $dateRange, PIPELINE_OFFPLAN, $company);
        $teamLeadsSecondary = empty($currentTeamIds) ? 0 : countActiveLeads($currentTeamIds, $dateRange, PIPELINE_SECONDARY, $company);
        $lastDeal  = daysSinceLastDeal($currentTeamIds, $company);
        $teamAvgGap = avgGapBetweenDealsForTeam($currentTeamIds, $dateRange, $company);

        $teamPerformance[] = array(
            'id'             => $tid,
            'name'           => ($team['DISPLAY_NAME'] ?? '') ?: $team['NAME'],
            'manager_id'     => $teamManagerId,
            'deals'          => $tagg['deal_count'],
            'leads_offplan'  => $teamLeadsOffplan,
            'leads_secondary'=> $teamLeadsSecondary,
            'listings'       => (int)$teamList + (int)$teamPocketList,
            'active_listings'=> $teamList,
            'pocket_listings'=> $teamPocketList,
            'total_listings' => (int)$teamList + (int)$teamPocketList,
            'sales'          => $tagg['sales_volume'],
            'commission'     => $teamComm['total'],
            'top_deal'       => $tagg['top_deal'],
            'avg_gap'        => $teamAvgGap,
            'last_deal_days' => $lastDeal,
        );
    }

    usort($teamPerformance, function ($a, $b) {
        return $b['commission'] - $a['commission'];
    });

    // ── YEAR COMPARISON ──────────────────────────────────────────────────
    $year1Monthly = empty($allAgentIds) ? groupDealsByMonth(array(), $year1) : fetchYearMonthly($year1, $allAgentIds, 'All', $company);
    $year2Monthly = empty($allAgentIds) ? groupDealsByMonth(array(), $year2) : fetchYearMonthly($year2, $allAgentIds, 'All', $company);
    $year1Summary = empty($allAgentIds) ? array(
        'sales' => 0,
        'commission' => 0,
        'deals' => 0,
        'agents' => 0,
        'avg_deal' => 0,
    ) : fetchYearSummary($year1, $allAgentIds, 'All', $company);
    $year2Summary = empty($allAgentIds) ? array(
        'sales' => 0,
        'commission' => 0,
        'deals' => 0,
        'agents' => 0,
        'avg_deal' => 0,
    ) : fetchYearSummary($year2, $allAgentIds, 'All', $company);

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
        'top_deal_id'                => $agg['top_deal_id'],
        'commissions'                => $commSplit['total'],
        'committed_commission'       => $commSplit['committed_commission'],
        'committed_commission_pct'   => $commSplit['committed_commission_pct'],
        'operational_commission'     => $commSplit['operational_commission'],
        'operational_commission_pct' => $commSplit['operational_commission_pct'],
        'avg_revenue_per_deal'       => $agg['avg_commission_per_deal'],
        'avg_revenue_per_month'      => (int)round($commSplit['total'] / 12),
        'active_listings_rent'       => $listings['rent'],
        'active_listings_sale'       => $listings['sale'],
        'pocket_listings_rent'       => $pocketListings['rent'],
        'pocket_listings_sale'       => $pocketListings['sale'],
        'total_listings_rent'        => (int)$listings['rent'] + (int)$pocketListings['rent'],
        'total_listings_sale'        => (int)$listings['sale'] + (int)$pocketListings['sale'],
        'top_commission'             => $agg['top_commission'],
        'top_commission_id'          => $agg['top_commission_id'],
    );

    $response['commission_trend']   = $commissionTrend;
    $response['deal_distribution']  = $dealDist;
    $response['top_developers']     = $topDevelopers;
    $response['top_property_types'] = $topPropertyTypes;
    $response['target_vs_actual']   = $targetVsActual;
    $response['sales_by_deal_type'] = $salesByDealType;
    $response['leads_by_stage_offplan']   = $leadsByStageOffplan;
    $response['leads_by_stage_secondary'] = $leadsByStageSecondary;
    $response['leads_by_source']    = $leadsBySource;
    $response['leads_by_source_secondary'] = $leadsBySourceSecondary;
    $response['deal_closure_source_offplan']   = $dealClosureSourceOffplan;
    $response['deal_closure_source_secondary'] = $dealClosureSourceSecondary;
    $response['listing_details']       = $listingDetails;
    $response['active_agents_details'] = $activeAgentDetails;
    $response['no_deal_60_details']    = $noDeal60Details;
    $response['agent_performance']     = $agentPerformance;
    $response['manager_performance']   = $managerPerformance;
    $response['team_performance']      = $teamPerformance;

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