<?php

/**
 * list_active_agents.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Secure helper script to inspect the list of active agents, their IDs, names, 
 * and work positions.
 * 
 * Requirement: Must be logged in to Bitrix to run this script.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// Boot Bitrix
bx_boot();

// Security Check: Ensure user is authorized in Bitrix
global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Access Denied: You must be logged in to Bitrix to view this list.";
    exit;
}

$salesTeams  = getSalesTeams();
$allDeptIds  = array_map(function ($t) {
    return (int)$t['ID'];
}, $salesTeams);

$dateRange = array(
    'from' => date('Y-01-01'),
    'to'   => date('Y-12-31')
);

// Fetch agents matching active filters
$allAgents = empty($allDeptIds) ? array() : getAgentsByDept($allDeptIds, true, $dateRange);

// Include special users if not already present
$specialUserIds = array(168, 156);
foreach ($specialUserIds as $specialId) {
    $found = false;
    foreach ($allAgents as $a) {
        if ((int)$a['ID'] === $specialId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $userProfile = getUserProfile($specialId);
        if (!empty($userProfile)) {
            $allAgents[] = $userProfile;
        }
    }
}

// Output list
header('Content-Type: text/plain; charset=utf-8');
echo "Total Active Agents Count: " . count($allAgents) . "\n";
echo "=========================================================================\n\n";

foreach ($allAgents as $a) {
    echo sprintf("ID: %-4d | Name: %-35s | Position: %s\n", 
        $a['ID'], 
        trim(($a['NAME'] ?? '') . ' ' . ($a['LAST_NAME'] ?? '')), 
        $a['WORK_POSITION'] ?? 'N/A'
    );
}
