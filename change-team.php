<?php
/**
 * change-team.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Standalone Bitrix App: Agent Team Transfer Portal
 * 
 * Responsibilities:
 *   1. Boot Bitrix environment.
 *   2. Check authorization & permissions (Admins/CEOs/Managers only).
 *   3. Handle POST action to update b_agent_dept_history and Bitrix profile.
 *   4. Render a premium administrative dashboard.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cache.php';

// Boot Bitrix
bx_boot();

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    http_response_code(403);
    echo "<div style='font-family:sans-serif;padding:40px;text-align:center;'><h2>Access Denied</h2><p>Please log in to Bitrix first.</p></div>";
    exit;
}

$currentUserId = (int)$USER->GetID();
$isAdmin = $USER->IsAdmin();
$userRole = getUserRole($currentUserId);

// Only Admin, CEO, or Manager can access this transfer portal
if (!$isAdmin && $userRole !== 'ceo' && $userRole !== 'manager') {
    http_response_code(403);
    echo "<div style='font-family:sans-serif;padding:40px;text-align:center;'><h2>Access Denied</h2><p>You do not have permission to access this page.</p></div>";
    exit;
}

// ── 0. Self-healing DB check (heal missing DEPT_NAME from previous entries) ─
try {
    $connection = \Bitrix\Main\Application::getConnection();
    $connection->queryExecute("
        UPDATE b_agent_dept_history h
        JOIN b_iblock_section s ON s.ID = h.DEPT_ID
        SET h.DEPT_NAME = s.NAME
        WHERE h.DEPT_NAME IS NULL OR h.DEPT_NAME = ''
    ");
    $connection->queryExecute("
        UPDATE b_agent_dept_history h
        SET h.DEPT_NAME = 'Private Office'
        WHERE h.DEPT_ID = 23 AND (h.DEPT_NAME IS NULL OR h.DEPT_NAME = '')
    ");
} catch (\Exception $e) {
    // Ignore database write/lock issues
}

// ── 1. Fetch sales departments ──────────────────────────────────────────────
$teams = getSalesTeams();
$allSalesDeptIds = getSalesReportDepartmentIds(false);

$deptMap = array();
// Pre-populate with section names from database to resolve any department
$allDepts = dbQuery("SELECT ID, NAME FROM b_iblock_section WHERE IBLOCK_ID = 3");
foreach ($allDepts as $d) {
    $deptMap[(int)$d['ID']] = $d['NAME'];
}
$deptMap[23] = 'Private Office';
$deptMap[3]  = 'Sales';

// ── 2. Handle POST Department Transfer ───────────────────────────────────────
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_team') {
    $agentId = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : 0;
    $newDeptId = isset($_POST['new_dept_id']) ? (int)$_POST['new_dept_id'] : 0;

    if ($agentId <= 0 || $newDeptId <= 0) {
        $errorMessage = "Invalid agent or department selection.";
    } else {
        // Verify agent exists and is active
        $agentRow = dbQueryOne("SELECT ID, NAME, LAST_NAME FROM b_user WHERE ID = {$agentId} AND ACTIVE = 'Y' LIMIT 1");
        if (empty($agentRow)) {
            $errorMessage = "Agent not found or is inactive.";
        } else {
            // Verify new department is allowed
            if (!in_array($newDeptId, $allSalesDeptIds, true) && $newDeptId !== 23) {
                $errorMessage = "Selected department is not a valid sales team.";
            } else {
                $currentDeptId = getUserDeptId($agentId);
                if ($currentDeptId === $newDeptId) {
                    $errorMessage = "The agent is already in the selected department.";
                } else {
                    $connection = \Bitrix\Main\Application::getConnection();
                    $todayStr = date('Y-m-d');
                    $tomorrowStr = date('Y-m-d', strtotime('+1 day'));

                    $agentFullName = trim($agentRow['NAME'] . ' ' . $agentRow['LAST_NAME']);
                    $newDeptName = $deptMap[$newDeptId] ?? ('Department ' . $newDeptId);

                    $connection->startTransaction();
                    try {
                        // A. Update existing history row with current date as EFFECTIVE_TO
                        $updateSql = "
                            UPDATE b_agent_dept_history 
                            SET EFFECTIVE_TO = '{$todayStr}' 
                            WHERE USER_ID = {$agentId} 
                              AND DEPT_ID = {$currentDeptId} 
                              AND (EFFECTIVE_TO IS NULL OR EFFECTIVE_TO = '0000-00-00')
                        ";
                        $connection->queryExecute($updateSql);

                        $newDeptNameEsc = dbEsc($newDeptName);
                        // B. Insert new history row with tomorrow as EFFECTIVE_FROM
                        $insertSql = "
                            INSERT INTO b_agent_dept_history (USER_ID, DEPT_ID, DEPT_NAME, EFFECTIVE_FROM, EFFECTIVE_TO) 
                            VALUES ({$agentId}, {$newDeptId}, '{$newDeptNameEsc}', '{$tomorrowStr}', NULL)
                        ";
                        $connection->queryExecute($insertSql);

                        // C. Update Bitrix database and User field
                        $connection->queryExecute("DELETE FROM b_utm_user WHERE VALUE_ID = {$agentId} AND FIELD_ID = 40");
                        $connection->queryExecute("INSERT INTO b_utm_user (VALUE_ID, FIELD_ID, VALUE, VALUE_INT) VALUES ({$agentId}, 40, '{$newDeptId}', {$newDeptId})");

                        if (class_exists('CUser')) {
                            $cuser = new CUser;
                            $cuser->Update($agentId, array("UF_DEPARTMENT" => array($newDeptId)));
                        }

                        // D. Clear scoreboard caches
                        $cache = new ScoreboardCache();
                        $cache->flush();

                        $connection->commitTransaction();
                        $successMessage = "Successfully transferred agent <strong>" . htmlspecialchars($agentFullName) . "</strong> to <strong>" . htmlspecialchars($newDeptName) . "</strong>. The transfer is effective from tomorrow (" . date('d/m/Y', strtotime('+1 day')) . ").";
                    } catch (\Exception $e) {
                        $connection->rollbackTransaction();
                        $errorMessage = "Error executing transaction: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// ── 3. Fetch active agents for the selector ──────────────────────────────────
$agentsData = getAgentsByDept($allSalesDeptIds, false);
$agentsList = array();
foreach ($agentsData as $a) {
    $uid = (int)$a['ID'];
    $currentDeptId = getUserDeptId($uid);
    $agentsList[] = array(
        'id' => $uid,
        'name' => trim(($a['NAME'] ?? '') . ' ' . ($a['LAST_NAME'] ?? '')),
        'dept_id' => $currentDeptId,
        'dept_name' => $deptMap[$currentDeptId] ?? 'Unknown Team',
        'designation' => $a['WORK_POSITION'] ?? 'Agent'
    );
}

// Sort alphabetically
usort($agentsList, function ($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

// ── 4. Fetch recent history log ──────────────────────────────────────────────
$historyRows = dbQuery("
    SELECT h.USER_ID, h.DEPT_ID, h.EFFECTIVE_FROM, h.EFFECTIVE_TO, u.NAME, u.LAST_NAME 
    FROM b_agent_dept_history h 
    LEFT JOIN b_user u ON u.ID = h.USER_ID 
    ORDER BY h.EFFECTIVE_FROM DESC, h.EFFECTIVE_TO DESC 
    LIMIT 20
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Agent Team – Performance Scorecard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f1e35;
            --navy-mid: #162844;
            --navy-light: #1e3558;
            --gold: #c9a84c;
            --gold-light: #e2c475;
            --white: #ffffff;
            --off-white: #f7f6f3;
            --grey-100: #f0eff0;
            --grey-200: #dddcdd;
            --grey-400: #9b9a9c;
            --grey-600: #5a5a5d;
            --red: #d94f4f;
            --green: #3daa72;
        }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--off-white);
            color: var(--navy);
            min-height: 100vh;
        }
        .header-bg {
            background-color: var(--navy);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.25);
        }
        .gold-border {
            border-color: var(--gold);
        }
        .gold-bg {
            background-color: var(--gold);
        }
        .gold-bg:hover {
            background-color: var(--gold-light);
        }
        .gold-text {
            color: var(--gold);
        }
        .card-navy {
            background: var(--navy-mid);
            color: var(--white);
        }
        /* Custom Custom Select style */
        .dropdown-shadow {
            box-shadow: 0 10px 30px rgba(15, 30, 53, 0.15);
        }
    </style>
</head>
<body class="bg-gray-50 flex flex-col min-h-screen">

    <!-- ── HEADER ─────────────────────────────────────────────────────────────── -->
    <header class="header-bg py-4 px-6 text-white flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span class="text-xl font-semibold tracking-wide gold-text">Mira International</span>
            <div class="h-6 w-[1px] bg-gray-600"></div>
            <span class="text-sm font-medium text-gray-300">Agent Team Transfer Portal</span>
        </div>
        <a href="index.php" class="text-xs font-semibold px-4 py-2 border border-gray-600 hover:border-white rounded-lg transition">
            Back to Scoreboard
        </a>
    </header>

    <!-- ── MAIN CONTENT ───────────────────────────────────────────────────────── -->
    <main class="flex-grow max-w-7xl w-full mx-auto p-6 md:p-8 grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Left Side: Change Form -->
        <div class="lg:col-span-5 flex flex-col gap-6">
            
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                <h2 class="text-lg font-bold text-gray-800 mb-2">Transfer Agent</h2>
                <p class="text-xs text-gray-500 mb-6">Select an active agent and move them to a new department. A new record will be logged in department history.</p>

                <?php if ($successMessage): ?>
                    <div class="mb-6 p-4 rounded-xl bg-green-50 border border-green-200 text-sm text-green-800">
                        <?= $successMessage ?>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage): ?>
                    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-sm text-red-800">
                        <?= htmlspecialchars($errorMessage) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" onsubmit="return confirmTransfer(event)">
                    <input type="hidden" name="action" value="change_team">
                    
                    <!-- Agent Search Selector -->
                    <div class="mb-5 relative">
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-2">Select Agent</label>
                        
                        <!-- Search Box Display -->
                        <div class="relative">
                            <input type="text" id="agentSearchInput" placeholder="Type name to search agent..." 
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#c9a84c] focus:border-[#c9a84c]"
                                autocomplete="off" onfocus="showAgentDropdown()" onkeyup="filterAgents()">
                            <input type="hidden" id="selectedAgentId" name="agent_id" value="">
                            
                            <!-- Arrow icon -->
                            <div class="absolute right-3 top-3.5 text-gray-400 pointer-events-none">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </div>
                        </div>

                        <!-- Dropdown panel -->
                        <div id="agentDropdown" class="hidden absolute left-0 right-0 mt-2 bg-white border border-gray-200 rounded-xl overflow-hidden dropdown-shadow z-50 max-h-64 overflow-y-auto">
                            <?php if (empty($agentsList)): ?>
                                <div class="p-4 text-xs text-gray-400 text-center">No active agents found</div>
                            <?php else: ?>
                                <?php foreach ($agentsList as $agent): ?>
                                    <div class="agent-option p-3 hover:bg-gray-50 cursor-pointer flex items-center justify-between border-b border-gray-50 last:border-0"
                                         data-id="<?= $agent['id'] ?>"
                                         data-name="<?= htmlspecialchars($agent['name']) ?>"
                                         data-dept-id="<?= $agent['dept_id'] ?>"
                                         data-dept-name="<?= htmlspecialchars($agent['dept_name']) ?>"
                                         data-designation="<?= htmlspecialchars($agent['designation']) ?>"
                                         onclick="selectAgent(this)">
                                        <div>
                                            <div class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($agent['name']) ?></div>
                                            <div class="text-[10px] text-gray-400 uppercase tracking-wider"><?= htmlspecialchars($agent['designation']) ?></div>
                                        </div>
                                        <div class="text-xs px-2.5 py-1 bg-gray-100 rounded-full text-gray-600 font-medium"><?= htmlspecialchars($agent['dept_name']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Current Team Preview Card -->
                    <div id="currentDeptCard" class="hidden mb-5 p-4 rounded-xl bg-gray-50 border border-gray-100 flex flex-col gap-1 transition-all duration-300">
                        <span class="text-[10px] uppercase font-bold text-gray-400 tracking-wider">Current Department</span>
                        <span id="currentDeptName" class="text-base font-bold text-gray-800">-</span>
                        <span id="currentDeptDesignation" class="text-xs text-gray-500">-</span>
                    </div>

                    <!-- Target Department -->
                    <div class="mb-6">
                        <label class="block text-xs font-bold uppercase tracking-wider text-gray-600 mb-2">New Target Team</label>
                        <select name="new_dept_id" id="targetDeptSelect" required
                            class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-[#c9a84c] focus:border-[#c9a84c] bg-white">
                            <option value="">-- Choose New Department --</option>
                            <!-- Also allow Private Office (23) explicitly -->
                            <option value="23">Private Office</option>
                            <?php foreach ($teams as $t): ?>
                                <?php if ((int)$t['ID'] !== 23): ?>
                                    <option value="<?= $t['ID'] ?>"><?= htmlspecialchars($t['DISPLAY_NAME'] ?: $t['NAME']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" id="submitBtn" disabled
                        class="w-full py-3.5 px-6 rounded-xl text-sm font-bold text-white bg-gray-400 cursor-not-allowed transition duration-200 shadow-md">
                        Process Transfer
                    </button>
                </form>

            </div>

        </div>

        <!-- Right Side: Recent Activity -->
        <div class="lg:col-span-7 flex flex-col">
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex-grow">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800">Transfer History</h2>
                        <p class="text-xs text-gray-500">Recent team adjustments recorded in b_agent_dept_history</p>
                    </div>
                    <span class="text-[10px] font-bold px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full">Database Sync Live</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-gray-100 text-gray-400 uppercase tracking-wider font-bold">
                                <th class="pb-3 font-semibold">Agent</th>
                                <th class="pb-3 font-semibold">Target Department</th>
                                <th class="pb-3 font-semibold">Effective From</th>
                                <th class="pb-3 font-semibold">Effective To</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (empty($historyRows)): ?>
                                <tr>
                                    <td colspan="4" class="py-8 text-center text-gray-400">No transfer history records found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historyRows as $row): ?>
                                    <?php 
                                        $fullName = trim(($row['NAME'] ?? '') . ' ' . ($row['LAST_NAME'] ?? ''));
                                        if ($fullName === '') {
                                            $fullName = 'User ID: ' . $row['USER_ID'];
                                        }
                                        $deptName = $deptMap[(int)$row['DEPT_ID']] ?? ('Dept ' . $row['DEPT_ID']);
                                        $effFrom = !empty($row['EFFECTIVE_FROM']) ? convertBitrixDateToString($row['EFFECTIVE_FROM'], 'd/m/Y') : '-';
                                        $effTo = !empty($row['EFFECTIVE_TO']) && $row['EFFECTIVE_TO'] !== '0000-00-00' ? convertBitrixDateToString($row['EFFECTIVE_TO'], 'd/m/Y') : 'Active';
                                    ?>
                                    <tr class="hover:bg-gray-50/50 transition duration-150">
                                        <td class="py-3.5 font-semibold text-gray-800"><?= htmlspecialchars($fullName) ?></td>
                                        <td class="py-3.5 font-medium text-gray-600">
                                            <span class="inline-block px-2 py-0.5 rounded bg-gray-100 text-gray-700 font-normal">
                                                <?= htmlspecialchars($deptName) ?>
                                            </span>
                                        </td>
                                        <td class="py-3.5 text-gray-500 font-mono"><?= htmlspecialchars($effFrom) ?></td>
                                        <td class="py-3.5 font-mono">
                                            <?php if ($effTo === 'Active'): ?>
                                                <span class="text-emerald-600 font-bold">Present</span>
                                            <?php else: ?>
                                                <span class="text-gray-400"><?= htmlspecialchars($effTo) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer class="py-6 text-center text-xs text-gray-400 border-t border-gray-100 bg-white">
        Mira International Performance Scoreboard &copy; 2026. All rights reserved.
    </footer>

    <!-- ── JAVASCRIPT LOGIC ────────────────────────────────────────────────────── -->
    <script>
        // Store all options
        const optionsList = Array.from(document.querySelectorAll('.agent-option'));
        const input = document.getElementById('agentSearchInput');
        const dropdown = document.getElementById('agentDropdown');
        const selectedId = document.getElementById('selectedAgentId');
        const currentDeptCard = document.getElementById('currentDeptCard');
        const currentDeptName = document.getElementById('currentDeptName');
        const currentDeptDesignation = document.getElementById('currentDeptDesignation');
        const targetSelect = document.getElementById('targetDeptSelect');
        const submitBtn = document.getElementById('submitBtn');

        // Document click listener to hide dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                hideAgentDropdown();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Keep input read-only until focused to prevent browser autocomplete issues
            input.addEventListener('focus', showAgentDropdown);
        });

        function showAgentDropdown() {
            dropdown.classList.remove('hidden');
            filterAgents();
        }

        function hideAgentDropdown() {
            dropdown.classList.add('hidden');
        }

        function filterAgents() {
            const query = input.value.toLowerCase().trim();
            let visibleCount = 0;

            optionsList.forEach(opt => {
                const name = opt.getAttribute('data-name').toLowerCase();
                const designation = opt.getAttribute('data-designation').toLowerCase();
                if (name.includes(query) || designation.includes(query)) {
                    opt.classList.remove('hidden');
                    visibleCount++;
                } else {
                    opt.classList.add('hidden');
                }
            });
        }

        function selectAgent(element) {
            const id = element.getAttribute('data-id');
            const name = element.getAttribute('data-name');
            const deptId = element.getAttribute('data-dept-id');
            const deptName = element.getAttribute('data-dept-name');
            const designation = element.getAttribute('data-designation');

            // Set inputs
            input.value = name;
            selectedId.value = id;

            // Show current dept details
            currentDeptName.innerText = deptName;
            currentDeptDesignation.innerText = designation;
            currentDeptCard.classList.remove('hidden');

            // Highlight target option filtering (cannot transfer to their own department)
            Array.from(targetSelect.options).forEach(opt => {
                if (opt.value === deptId) {
                    opt.disabled = true;
                    opt.innerText = opt.innerText.includes(' (Current)') ? opt.innerText : opt.innerText + ' (Current)';
                } else {
                    opt.disabled = false;
                    opt.innerText = opt.innerText.replace(' (Current)', '');
                }
            });

            // Reset target select if it was the same
            if (targetSelect.value === deptId) {
                targetSelect.value = '';
            }

            // Enable submit check
            checkValidation();
            hideAgentDropdown();
        }

        targetSelect.addEventListener('change', checkValidation);

        function checkValidation() {
            if (selectedId.value !== '' && targetSelect.value !== '') {
                submitBtn.disabled = false;
                submitBtn.classList.remove('bg-gray-400', 'cursor-not-allowed');
                submitBtn.classList.add('gold-bg', 'cursor-pointer');
            } else {
                submitBtn.disabled = true;
                submitBtn.classList.remove('gold-bg', 'cursor-pointer');
                submitBtn.classList.add('bg-gray-400', 'cursor-not-allowed');
            }
        }

        function confirmTransfer(e) {
            const name = input.value;
            const targetName = targetSelect.options[targetSelect.selectedIndex].text;
            
            if (!confirm(`Are you sure you want to transfer ${name} to "${targetName}"?\nThis change will take effect from tomorrow.`)) {
                e.preventDefault();
                return false;
            }
            return true;
        }
    </script>
</body>
</html>
