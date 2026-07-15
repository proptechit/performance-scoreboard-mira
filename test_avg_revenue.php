<?php
/**
 * test_avg_revenue.php
 * Test script to run and output the Avg Revenue (Sales) per Transaction calculations.
 */

// If running in a non-Bitrix CLI environment, we can still output explanation,
// but if we are in Bitrix CLI environment, it will run queries.
try {
    require_once __DIR__ . '/./config.php';
    require_once __DIR__ . '/./helpers.php';
    

    // ── Boot Bitrix ──────────────────────────────────────────────────────────────
    bx_boot();

    $salesTeams = getSalesTeams();
    echo "========================================================================\n";
    echo "AVERAGE REVENUE PER TRANSACTION REPORT\n";
    echo "========================================================================\n";

    // Date range: Current month or custom
    $dateRange = array(
        'from' => date('Y-m-01'),
        'to'   => date('Y-m-t')
    );
    echo "Date Range: {$dateRange['from']} to {$dateRange['to']}\n\n";

    // Fetch all active agents
    $allDeptIds = getSalesReportDepartmentIds(false);
    $allAgents = getAgentsByDept($allDeptIds, true, $dateRange);
    $allAgentIds = array_map(function($a) { return (int)$a['ID']; }, $allAgents);

    // Fetch all deals for these agents
    $allDeals = fetchAllDeals($allAgentIds, $dateRange, 'All');

    // Pre-group deals by agent
    $dealsByAgent = array();
    foreach ($allDeals as $d) {
        $rid = (int)$d['ASSIGNED_BY_ID'];
        $dealsByAgent[$rid][] = $d;
    }

    foreach ($salesTeams as $team) {
        $tid = (int)$team['ID'];
        if ($tid === 23) {
            // Private Office is handled separately in frontend, skip or display
            continue;
        }

        $teamAgents = getAgentsByDept(array($tid), true, $dateRange);
        $teamIds = array_map(function($a) { return (int)$a['ID']; }, $teamAgents);
        
        $teamDealOwnerIds = $teamIds;
        $teamManagerId = resolveSalesTeamHeadId($team);
        if ($teamManagerId > 0) {
            $teamDealOwnerIds[] = $teamManagerId;
        }
        $teamDealOwnerIds = array_values(array_unique($teamDealOwnerIds));

        $teamDeals = array();
        foreach ($teamDealOwnerIds as $ownerId) {
            if (isset($dealsByAgent[$ownerId])) {
                foreach ($dealsByAgent[$ownerId] as $d) {
                    $dealDate = $d['effective_create_date'] ?? $d['DATE_CREATE'] ?? '';
                    if (isAgentInDeptAtDate($d['ASSIGNED_BY_ID'], $tid, $dealDate)) {
                        $teamDeals[] = $d;
                    }
                }
            }
        }

        // Aggregate
        $agg = aggregateDeals($teamDeals);

        echo "Team: {$team['DISPLAY_NAME']} (ID: {$tid})\n";
        echo "  - Total Transactions (Active Stages): " . count($teamDeals) . "\n";
        echo "  - Total Sales Volume: AED " . number_format($agg['sales_volume']) . "\n";
        echo "  - Avg Revenue Per Transaction (Calculation: Sales Volume / Transactions Count):\n";
        echo "    AED " . number_format($agg['avg_sales_per_deal']) . "\n";
        echo "  - Deal Breakdown:\n";
        if (empty($teamDeals)) {
            echo "    (No active deals found in this date range)\n";
        } else {
            foreach ($teamDeals as $td) {
                $agent = getUserProfile($td['ASSIGNED_BY_ID']);
                $agentName = $agent ? ($agent['NAME'] . ' ' . $agent['LAST_NAME']) : "Unknown ID: {$td['ASSIGNED_BY_ID']}";
                echo "    * Deal ID: {$td['ID']} | Amount: AED " . number_format($td['sale_amount']) . " | Agent: {$agentName}\n";
            }
        }
        echo "------------------------------------------------------------------------\n";
    }

} catch (Exception $e) {
    echo "Exception occurred: " . $e->getMessage() . "\n";
}
