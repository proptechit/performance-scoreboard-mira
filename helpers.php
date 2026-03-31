<?php

/**
 * helpers.php
 * ─────────────────────────────────────────────────────────────────────────────
 * All shared helper functions for data.php.
 * Covers: Bitrix bootstrap, SQL building, aggregation, formatting,
 *         user/department lookups, listing/attendance counts.
 *
 * Every function that touches the database is isolated here so data.php
 * stays clean and readable.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ═══════════════════════════════════════════════════════════════════════════
// 0. BITRIX BOOTSTRAP
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Boot Bitrix and load required modules.
 * Call once at the top of data.php.
 */
function bx_boot()
{
    if (empty($_SERVER["DOCUMENT_ROOT"])) {
        $_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__ . '/../../');
    }

    if (!defined('BX_PERSONAL_ROOT')) {
        define('BX_PERSONAL_ROOT', '/bitrix');
    }

    $prolog = $_SERVER["DOCUMENT_ROOT"] . '/bitrix/modules/main/include/prolog_before.php';

    if (!file_exists($prolog)) {
        http_response_code(500);
        echo json_encode(['error' => 'Bitrix prolog not found']);
        exit;
    }

    define('NO_KEEP_STATISTIC', true);
    define('NO_AGENT_STATISTIC', true);
    define('NO_AGENT_CHECK', true);
    define('BX_BUFFER_USED', true);
    define('BX_WITH_ON_AFTER_EPILOG', false);

    require_once($prolog);

    \Bitrix\Main\Loader::includeModule('crm');
    \Bitrix\Main\Loader::includeModule('iblock');
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. DATE RANGE BUILDER
//    Converts the GET filter params (year / quarter / month) into a
//    SQL-safe date range for CLOSEDATE / DATE_CREATE comparisons.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Returns array('from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD')
 * based on year / quarter / month params.
 * If year is 'All', returns full range from 2020 to today.
 *
 * @param  string|int $year
 * @param  string     $quarter  'All'|'Q1'|'Q2'|'Q3'|'Q4'
 * @param  string     $month    'All'|'Jan'|'Feb'|...
 * @return array
 */
function buildDateRange($year, $quarter, $month)
{
    $monthNames = array(
        'Jan' => 1,
        'Feb' => 2,
        'Mar' => 3,
        'Apr' => 4,
        'May' => 5,
        'Jun' => 6,
        'Jul' => 7,
        'Aug' => 8,
        'Sep' => 9,
        'Oct' => 10,
        'Nov' => 11,
        'Dec' => 12,
    );
    $quarterMap = array(
        'Q1' => array(1,  3),
        'Q2' => array(4,  6),
        'Q3' => array(7,  9),
        'Q4' => array(10, 12),
    );

    if ($year === 'All' || !is_numeric($year)) {
        return array('from' => '2020-01-01', 'to' => date('Y-12-31'));
    }

    $y = (int)$year;

    // Specific month
    if ($month !== 'All' && isset($monthNames[$month])) {
        $m      = $monthNames[$month];
        $lastDay = date('t', mktime(0, 0, 0, $m, 1, $y));
        return array(
            'from' => sprintf('%04d-%02d-01', $y, $m),
            'to'   => sprintf('%04d-%02d-%02d', $y, $m, $lastDay),
        );
    }

    // Quarter
    if ($quarter !== 'All' && isset($quarterMap[$quarter])) {
        $qm = $quarterMap[$quarter];
        $lastDay = date('t', mktime(0, 0, 0, $qm[1], 1, $y));
        return array(
            'from' => sprintf('%04d-%02d-01', $y, $qm[0]),
            'to'   => sprintf('%04d-%02d-%02d', $y, $qm[1], $lastDay),
        );
    }

    // Full year
    return array(
        'from' => $y . '-01-01',
        'to'   => $y . '-12-31',
    );
}

/**
 * Build 12-month range array for a given year.
 * Returns array of ['from'=>..., 'to'=>..., 'month'=>'Jan', 'month_num'=>1]
 */
function buildMonthRanges($year)
{
    $months = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
    $ranges = array();
    foreach ($months as $i => $m) {
        $mn      = $i + 1;
        $lastDay = date('t', mktime(0, 0, 0, $mn, 1, $year));
        $ranges[] = array(
            'month'     => $m,
            'month_num' => $mn,
            'from'      => sprintf('%04d-%02d-01', $year, $mn),
            'to'        => sprintf('%04d-%02d-%02d', $year, $mn, $lastDay),
        );
    }
    return $ranges;
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. SQL HELPERS  (raw DB — \Bitrix\Main\Application::getConnection())
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Execute a raw SQL query and return all rows as associative array.
 */
function dbQuery($sql)
{
    $conn   = \Bitrix\Main\Application::getConnection();
    $result = $conn->query($sql);
    $rows   = array();
    while ($row = $result->fetch()) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Execute a raw SQL and return a single row.
 */
function dbQueryOne($sql)
{
    $conn   = \Bitrix\Main\Application::getConnection();
    $result = $conn->query($sql);
    return $result->fetch() ?: array();
}

/**
 * Safely escape a string for SQL.
 */
function dbEsc($val)
{
    return \Bitrix\Main\Application::getConnection()->getSqlHelper()->forSql($val);
}

/**
 * Safely cast to integer for SQL.
 */
function dbInt($val)
{
    return (int)$val;
}

/**
 * Build a SQL IN clause from an array of integers.
 * e.g. inClauseInt([1,2,3]) → "(1,2,3)"
 */
function inClauseInt($arr)
{
    if (empty($arr)) {
        return '(0)';
    }
    $ints = array_map('intval', $arr);
    return '(' . implode(',', $ints) . ')';
}

/**
 * Build a SQL IN clause from an array of strings (each quoted).
 */
function inClauseStr($arr)
{
    if (empty($arr)) {
        return "('')";
    }
    $escaped = array_map(function ($v) {
        return "'" . dbEsc($v) . "'";
    }, $arr);
    return '(' . implode(',', $escaped) . ')';
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. USER / DEPARTMENT HELPERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Determine the role of a Bitrix user ID.
 * Returns 'ceo' | 'manager' | 'agent'
 */
function getUserRole($userId)
{
    $uid = (int)$userId;
    if (in_array($uid, $GLOBALS['CFG_CEO_USER_IDS'], true)) {
        return 'ceo';
    }
    if (in_array($uid, $GLOBALS['CFG_MANAGER_USER_IDS'], true)) {
        return 'manager';
    }
    return 'agent';
}

/**
 * Fetch all sub-departments under DEPT_SALES_ROOT.
 * Returns array of ['ID', 'NAME', 'UF_HEAD'] rows.
 */
function getSalesTeams()
{
    $parentId = dbInt(DEPT_SALES_ROOT);

    return dbQuery("
        SELECT 
            s.ID,
            s.NAME,
            uts.UF_HEAD
        FROM b_iblock_section s
        LEFT JOIN b_uts_iblock_3_section uts 
            ON uts.VALUE_ID = s.ID
        WHERE s.IBLOCK_ID = 3
          AND s.ACTIVE = 'Y'
          AND s.IBLOCK_SECTION_ID = {$parentId}
        ORDER BY s.SORT ASC, s.NAME ASC
    ");
}

/**
 * Fetch all active agents in a given department (and its sub-departments).
 * Returns array of user rows: ID, NAME, LAST_NAME, WORK_POSITION, UF_DEPARTMENT.
 *
 * @param  int|array $deptIds  Single dept ID or array of dept IDs
 * @return array
 */
function getAgentsByDept($deptIds)
{
    if (!is_array($deptIds)) {
        $deptIds = array($deptIds);
    }

    $in = inClauseInt($deptIds);

    return dbQuery("
        SELECT DISTINCT
            u.ID,
            u.NAME,
            u.LAST_NAME,
            u.WORK_POSITION,
            u.DATE_REGISTER,
            u.PERSONAL_PHOTO
        FROM b_user u

        JOIN b_utm_user ud
            ON ud.VALUE_ID = u.ID
           AND ud.FIELD_ID = 40   -- UF_DEPARTMENT

        WHERE u.ACTIVE = 'Y'
          AND ud.VALUE_INT IN {$in}

        ORDER BY u.LAST_NAME ASC, u.NAME ASC
    ");
}

/**
 * Fetch a single user's profile info.
 */
function getUserProfile($userId)
{
    $uid = dbInt($userId);

    return dbQueryOne("
        SELECT 
            u.ID,
            u.NAME,
            u.LAST_NAME,
            u.WORK_POSITION,
            u.DATE_REGISTER,
            u.EMAIL
        FROM b_user u
        WHERE u.ID = {$uid}
        LIMIT 1
    ");
}

/**
 * Get manager's name for an agent by UF_HEAD of their department.
 */
function getManagerForAgent($userId)
{
    $uid = dbInt($userId);

    $row = dbQueryOne("
        SELECT CONCAT(m.NAME, ' ', m.LAST_NAME) AS FULL_NAME
        FROM b_utm_user ud

        JOIN b_iblock_section s 
            ON s.ID = ud.VALUE_INT

        LEFT JOIN b_uts_iblock_3_section uts 
            ON uts.VALUE_ID = s.ID

        JOIN b_user m 
            ON m.ID = uts.UF_HEAD

        WHERE ud.VALUE_ID = {$uid}
          AND ud.FIELD_ID = 40
        LIMIT 1
    ");

    return $row ? $row['FULL_NAME'] : '';
}

/**
 * Resolve department ID(s) for a user.
 * UF_DEPARTMENT in b_user is stored as JSON array in modern Bitrix.
 * Returns first department ID as int.
 */
function getUserDeptId($userId)
{
    $uid = dbInt($userId);

    $row = dbQueryOne("
        SELECT VALUE_INT
        FROM b_utm_user
        WHERE VALUE_ID = {$uid}
          AND FIELD_ID = 40
        LIMIT 1
    ");

    return (int)($row['VALUE_INT'] ?? 0);
}

/**
 * Get all agent user IDs managed by a given manager (by department UF_HEAD).
 * Returns array of integer user IDs.
 */
function getAgentIdsByManager($managerId)
{
    $mid = dbInt($managerId);

    $rows = dbQuery("
        SELECT DISTINCT u.ID
        FROM b_user u

        JOIN b_utm_user ud
            ON ud.VALUE_ID = u.ID
           AND ud.FIELD_ID = 40

        JOIN b_iblock_section s 
            ON s.ID = ud.VALUE_INT

        LEFT JOIN b_uts_iblock_3_section uts 
            ON uts.VALUE_ID = s.ID

        WHERE uts.UF_HEAD = {$mid}
          AND u.ACTIVE = 'Y'
    ");

    return array_map(function ($r) {
        return (int)$r['ID'];
    }, $rows);
}

/**
 * Get human-readable full name from a user row.
 */
function fullName($row)
{
    return trim($row['NAME'] . ' ' . $row['LAST_NAME']);
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. DEAL QUERIES  (Transactions pipeline = PIPELINE_TRANSACTION = 3)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Core function: fetch won deals (C3:WON) for a set of agents within a date range.
 * Applies optional deal_type (property type) filter.
 *
 * Returns raw DB rows. Each row has:
 *   ID, ASSIGNED_BY_ID, CLOSEDATE, OPPORTUNITY (sale amount), commission field,
 *   developer field, property type field, manager ID field.
 *
 * @param  array  $agentIds    Bitrix user IDs to filter by (empty = all agents)
 * @param  array  $dateRange   ['from'=>'YYYY-MM-DD', 'to'=>'YYYY-MM-DD']
 * @param  string $dealType    'All' | 'Offplan' | 'Secondary' | 'Rental'
 * @return array
 */
function fetchWonDeals($agentIds, $dateRange, $dealType = 'All')
{
    $catId    = dbInt(PIPELINE_TRANSACTION);
    $stageWon = dbEsc(STAGE_WON);
    $from     = dbEsc($dateRange['from']);
    $to       = dbEsc($dateRange['to']);

    $fAmount  = FIELD_DEAL_AMOUNT;
    $fComm    = FIELD_COMMISSION;
    $fDev     = FIELD_DEVELOPER;
    $fType    = FIELD_PROPERTY_TYPE;
    $fMgr     = FIELD_MANAGER_ID;

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $typeFilter = buildPropertyTypeFilter($dealType, 'uts');

    return dbQuery("
        SELECT
            d.ID,
            d.ASSIGNED_BY_ID,
            d.CLOSEDATE,
            d.{$fAmount}            AS sale_amount,

            uts.{$fComm}            AS commission,
            uts.{$fDev}             AS developer_id,
            uts.{$fType}            AS property_type_id,
            uts.{$fMgr}             AS manager_id

        FROM b_crm_deal d

        LEFT JOIN b_uts_crm_deal uts 
            ON uts.VALUE_ID = d.ID

        WHERE d.CATEGORY_ID = {$catId}
          AND d.STAGE_ID    = '{$stageWon}'
          AND DATE(d.CLOSEDATE) >= '{$from}'
          AND DATE(d.CLOSEDATE) <= '{$to}'
          {$agentFilter}
          {$typeFilter}

        ORDER BY d.CLOSEDATE ASC
    ");
}

/**
 * Fetch committed deals (all stages in pipeline 3 except WON and LOSE).
 */
function fetchCommittedDeals($agentIds, $dateRange, $dealType = 'All')
{
    $catId     = dbInt(PIPELINE_TRANSACTION);
    $stageWon  = dbEsc(STAGE_WON);
    $stageLose = dbEsc(STAGE_LOSE);
    $from      = dbEsc($dateRange['from']);
    $to        = dbEsc($dateRange['to']);

    $fAmount = FIELD_DEAL_AMOUNT;
    $fComm   = FIELD_COMMISSION;

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $typeFilter = buildPropertyTypeFilter($dealType, 'uts');

    return dbQuery("
        SELECT
            d.ID,
            d.ASSIGNED_BY_ID,
            d.DATE_CREATE,
            d.{$fAmount}        AS sale_amount,
            uts.{$fComm}        AS commission

        FROM b_crm_deal d

        LEFT JOIN b_uts_crm_deal uts 
            ON uts.VALUE_ID = d.ID

        WHERE d.CATEGORY_ID = {$catId}
          AND d.STAGE_ID   != '{$stageWon}'
          AND d.STAGE_ID   != '{$stageLose}'
          AND DATE(d.DATE_CREATE) >= '{$from}'
          AND DATE(d.DATE_CREATE) <= '{$to}'
          {$agentFilter}
          {$typeFilter}

        ORDER BY d.DATE_CREATE ASC
    ");
}

/**
 * Build SQL WHERE fragment for property type filter.
 */
function buildPropertyTypeFilter($dealType, $alias = 'd')
{
    if ($dealType === 'All') {
        return '';
    }
    // Find the enum ID(s) matching the requested deal type label
    $matchIds = array();
    foreach ($GLOBALS['CFG_PROPERTY_TYPE_MAP'] as $enumId => $label) {
        if (strtolower($label) === strtolower($dealType)) {
            $matchIds[] = $enumId;
        }
    }
    if (empty($matchIds)) {
        return '';
    }
    return 'AND ' . $alias . '.' . FIELD_PROPERTY_TYPE . ' IN ' . inClauseInt($matchIds);
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. LEAD QUERIES  (Pipelines 1 = Offplan, 2 = Secondary)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Count active leads for a set of agents.
 * Active = not WON/LOSE equivalent in their pipeline.
 *
 * @param  array  $agentIds
 * @param  array  $dateRange
 * @return int
 */
function countActiveLeads($agentIds, $dateRange)
{
    $pipelines = array(PIPELINE_OFFPLAN, PIPELINE_SECONDARY);
    $in        = inClauseInt($pipelines);
    $from      = dbEsc($dateRange['from']);
    $to        = dbEsc($dateRange['to']);
    $stageWon  = dbEsc('C' . PIPELINE_OFFPLAN . ':WON');  // rough; refined below

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    // Exclude terminal stages across both pipelines
    $excludeStages = array('C1:WON', 'C1:LOSE', 'C2:WON', 'C2:LOSE');
    $excludeIn     = inClauseStr($excludeStages);

    $row = dbQueryOne("
        SELECT COUNT(*) AS cnt
        FROM b_crm_deal d
        WHERE d.CATEGORY_ID IN {$in}
          AND d.STAGE_ID NOT IN {$excludeIn}
          AND d.DELETED   = 'N'
          AND DATE(d.DATE_CREATE) >= '{$from}'
          AND DATE(d.DATE_CREATE) <= '{$to}'
          {$agentFilter}
    ");
    return (int)($row['cnt'] ?? 0);
}

/**
 * Count reshuffled leads (assignment count field > 0) for a set of agents.
 */
function countReshuffledLeads($agentIds, $dateRange)
{
    $pipelines = array(PIPELINE_OFFPLAN, PIPELINE_SECONDARY);
    $in        = inClauseInt($pipelines);
    $from      = dbEsc($dateRange['from']);
    $to        = dbEsc($dateRange['to']);
    $fAssign   = FIELD_REASSIGNMENT_CNT;

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $excludeStages = array('C1:WON', 'C1:LOSE', 'C2:WON', 'C2:LOSE');
    $excludeIn     = inClauseStr($excludeStages);

    $row = dbQueryOne("
        SELECT COUNT(*) AS cnt
        FROM b_crm_deal d
        WHERE d.CATEGORY_ID IN {$in}
          AND d.STAGE_ID NOT IN {$excludeIn}
          AND d.DELETED   = 'N'
          AND (d.{$fAssign} IS NOT NULL AND d.{$fAssign} > 0)
          AND DATE(d.DATE_CREATE) >= '{$from}'
          AND DATE(d.DATE_CREATE) <= '{$to}'
          {$agentFilter}
    ");
    return (int)($row['cnt'] ?? 0);
}

// ═══════════════════════════════════════════════════════════════════════════
// 6. LISTING QUERIES  (SPA 1052)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Count active listings (for rent vs for sale) for a set of agents.
 * Returns array('sale' => int, 'rent' => int)
 *
 * @param  array $agentIds  Empty = all agents
 * @return array
 */
function countActiveListings($agentIds)
{
    $table       = SPA_LISTINGS_TABLE;
    $stage       = dbEsc(LISTING_STAGE_ACTIVE);
    $typeField   = LISTING_TYPE_FIELD;
    $saleValue   = dbInt(LISTING_TYPE_SALE_VALUE);

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND l.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $rows = dbQuery("
        SELECT
            SUM(CASE WHEN l.{$typeField} = {$saleValue} THEN 1 ELSE 0 END) AS sale_count,
            SUM(CASE WHEN l.{$typeField} != {$saleValue} OR l.{$typeField} IS NULL THEN 1 ELSE 0 END) AS rent_count
        FROM {$table} l
        WHERE l.STAGE_ID = '{$stage}'
          {$agentFilter}
    ");

    $row = !empty($rows) ? $rows[0] : array();
    return array(
        'sale' => (int)($row['sale_count'] ?? 0),
        'rent' => (int)($row['rent_count'] ?? 0),
    );
}

/**
 * Count total listings (all stages) for a set of agents — for agent/manager view.
 */
function countTotalListings($agentIds)
{
    $table = SPA_LISTINGS_TABLE;

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'WHERE l.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $row = dbQueryOne("
        SELECT COUNT(*) AS cnt
        FROM {$table} l
          {$agentFilter}
    ");
    return (int)($row['cnt'] ?? 0);
}

// ═══════════════════════════════════════════════════════════════════════════
// 7. ATTENDANCE QUERIES  (SPA 1060)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Count working days (punch-in entries) for an agent in the date range.
 *
 * @param  int   $userId
 * @param  array $dateRange
 * @return int
 */
function countAttendanceDays($userId, $dateRange)
{
    $table     = SPA_ATTENDANCE_TABLE;
    $typeField = ATTENDANCE_TYPE_FIELD;
    $typeIn    = dbEsc(ATTENDANCE_TYPE_IN);
    $uid       = dbInt($userId);
    $from      = dbEsc($dateRange['from']);
    $to        = dbEsc($dateRange['to']);

    // Count distinct calendar days the agent punched in
    $row = dbQueryOne("
        SELECT COUNT(DISTINCT DATE(a.DATE_CREATE)) AS cnt
        FROM {$table} a
        WHERE a.ASSIGNED_BY_ID = {$uid}
          AND a.{$typeField}   = '{$typeIn}'
          AND DATE(a.DATE_CREATE) >= '{$from}'
          AND DATE(a.DATE_CREATE) <= '{$to}'
          AND a.DELETED = 'N'
    ");
    return (int)($row['cnt'] ?? 0);
}

// ═══════════════════════════════════════════════════════════════════════════
// 8. AGGREGATION HELPERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Aggregate a flat array of won deal rows into summary metrics.
 * Returns array with: deal_count, sales_volume, commissions, top_deal,
 *                     top_commission, avg_sales_per_deal, avg_ticket_size
 */
function aggregateDeals($deals)
{
    $count     = 0;
    $sales     = 0.0;
    $comm      = 0.0;
    $topDeal   = 0.0;
    $topComm   = 0.0;

    foreach ($deals as $d) {
        $amount    = (float)($d['sale_amount'] ?? 0);
        $c         = (float)($d['commission']  ?? 0);
        $count++;
        $sales    += $amount;
        $comm     += $c;
        if ($amount > $topDeal) $topDeal = $amount;
        if ($c      > $topComm) $topComm = $c;
    }

    return array(
        'deal_count'       => $count,
        'sales_volume'     => (int)$sales,
        'commissions'      => (int)$comm,
        'top_deal'         => (int)$topDeal,
        'top_commission'   => (int)$topComm,
        'avg_sales_per_deal' => $count > 0 ? (int)round($sales / $count) : 0,
    );
}

/**
 * Calculate days since last won deal for a set of agents.
 * Returns int (days) or 0 if no deals exist.
 */
function daysSinceLastDeal($agentIds)
{
    $catId    = dbInt(PIPELINE_TRANSACTION);
    $stageWon = dbEsc(STAGE_WON);

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $row = dbQueryOne("
        SELECT MAX(d.CLOSEDATE) AS last_date
        FROM b_crm_deal d
        WHERE d.CATEGORY_ID = {$catId}
          AND d.STAGE_ID    = '{$stageWon}'
          AND d.DELETED     = 'N'
          {$agentFilter}
    ");

    if (empty($row['last_date'])) {
        return 999;  // Never closed a deal
    }

    $lastDate = new \DateTime($row['last_date']);
    $now      = new \DateTime();
    return (int)$lastDate->diff($now)->days;
}

/**
 * Calculate average gap (days) between consecutive won deals for an agent.
 */
function avgGapBetweenDeals($agentId, $dateRange)
{
    $catId    = dbInt(PIPELINE_TRANSACTION);
    $stageWon = dbEsc(STAGE_WON);
    $uid      = dbInt($agentId);
    $from     = dbEsc($dateRange['from']);
    $to       = dbEsc($dateRange['to']);

    $rows = dbQuery("
        SELECT DATE(d.CLOSEDATE) AS close_date
        FROM b_crm_deal d
        WHERE d.CATEGORY_ID   = {$catId}
          AND d.STAGE_ID      = '{$stageWon}'
          AND d.DELETED       = 'N'
          AND d.ASSIGNED_BY_ID = {$uid}
          AND DATE(d.CLOSEDATE) >= '{$from}'
          AND DATE(d.CLOSEDATE) <= '{$to}'
        ORDER BY d.CLOSEDATE ASC
    ");

    if (count($rows) < 2) {
        return 0;
    }

    $gaps  = array();
    $dates = array_map(function ($r) {
        return new \DateTime($r['close_date']);
    }, $rows);
    for ($i = 1; $i < count($dates); $i++) {
        $gaps[] = (int)$dates[$i - 1]->diff($dates[$i])->days;
    }
    return (int)round(array_sum($gaps) / count($gaps));
}

/**
 * Count agents with no won deal in last 60 days.
 *
 * @param  array $agentIds  All agent user IDs to check
 * @return int
 */
function countNoDealIn60Days($agentIds)
{
    if (empty($agentIds)) {
        return 0;
    }
    $catId    = dbInt(PIPELINE_TRANSACTION);
    $stageWon = dbEsc(STAGE_WON);
    $cutoff   = dbEsc(date('Y-m-d', strtotime('-60 days')));
    $inAgents = inClauseInt($agentIds);

    // Agents who DO have a recent deal
    $rows = dbQuery("
        SELECT DISTINCT ASSIGNED_BY_ID
        FROM b_crm_deal
        WHERE CATEGORY_ID    = {$catId}
          AND STAGE_ID       = '{$stageWon}'
          AND DELETED        = 'N'
          AND ASSIGNED_BY_ID IN {$inAgents}
          AND DATE(CLOSEDATE) >= '{$cutoff}'
    ");

    $activeAgents = count($rows);
    return max(0, count($agentIds) - $activeAgents);
}

// ═══════════════════════════════════════════════════════════════════════════
// 9. MONTHLY BREAKDOWN  (for charts)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Group won deals by month and return month-wise aggregation.
 * Returns array of 12 items (one per month) with:
 *   month, month_num, sales, commission, deals
 *
 * @param  array $deals     Raw deal rows from fetchWonDeals()
 * @param  int   $year      The year to build 12 months for
 * @return array
 */
function groupDealsByMonth($deals, $year)
{
    $monthMap = array();
    foreach ($deals as $d) {
        $dt = new \DateTime($d['CLOSEDATE']);
        if ((int)$dt->format('Y') !== (int)$year) {
            continue;
        }
        $mn = (int)$dt->format('n');  // 1-12
        if (!isset($monthMap[$mn])) {
            $monthMap[$mn] = array('sales' => 0, 'commission' => 0, 'deals' => 0);
        }
        $monthMap[$mn]['sales']      += (float)($d['sale_amount'] ?? 0);
        $monthMap[$mn]['commission'] += (float)($d['commission']  ?? 0);
        $monthMap[$mn]['deals']++;
    }

    $months = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
    $result = array();
    foreach ($months as $i => $m) {
        $mn   = $i + 1;
        $data = isset($monthMap[$mn]) ? $monthMap[$mn] : array('sales' => 0, 'commission' => 0, 'deals' => 0);
        $result[] = array(
            'month'      => $m,
            'month_num'  => $mn,
            'sales'      => (int)$data['sales'],
            'commission' => (int)$data['commission'],
            'deals'      => (int)$data['deals'],
            'value'      => (int)$data['commission'],  // alias for commission trend chart
        );
    }
    return $result;
}

/**
 * Build target_vs_actual array for 12 months.
 *
 * @param  array $monthlyDeals  Output of groupDealsByMonth()
 * @param  int   $monthlyTarget AED target per month
 * @return array
 */
function buildTargetVsActual($monthlyDeals, $monthlyTarget)
{
    $result = array();
    foreach ($monthlyDeals as $m) {
        $result[] = array(
            'month'  => $m['month'],
            'target' => (int)$monthlyTarget,
            'actual' => (int)$m['sales'],
        );
    }
    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════
// 10. DEAL TYPE DISTRIBUTION
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Build deal distribution array (for doughnut chart) from won deals.
 * Groups by property type enum ID, maps to label.
 *
 * Returns array of ['name', 'value' (%), 'amount', 'commission', 'deals']
 */
function buildDealDistribution($deals)
{
    $typeMap    = $GLOBALS['CFG_PROPERTY_TYPE_MAP'];
    $grouped    = array();
    $totalSales = 0.0;

    foreach ($deals as $d) {
        $typeId = (int)($d['property_type_id'] ?? 0);
        $label  = isset($typeMap[$typeId]) ? $typeMap[$typeId] : 'Other';
        if (!isset($grouped[$label])) {
            $grouped[$label] = array('amount' => 0, 'commission' => 0, 'deals' => 0);
        }
        $amt = (float)($d['sale_amount'] ?? 0);
        $grouped[$label]['amount']     += $amt;
        $grouped[$label]['commission'] += (float)($d['commission'] ?? 0);
        $grouped[$label]['deals']++;
        $totalSales += $amt;
    }

    $result = array();
    foreach ($grouped as $name => $g) {
        $pct      = $totalSales > 0 ? round(($g['amount'] / $totalSales) * 100, 2) : 0;
        $result[] = array(
            'name'       => $name,
            'value'      => $pct,
            'amount'     => (int)$g['amount'],
            'commission' => (int)$g['commission'],
            'deals'      => (int)$g['deals'],
        );
    }

    // Sort descending by amount
    usort($result, function ($a, $b) {
        return $b['amount'] - $a['amount'];
    });
    return $result;
}

/**
 * Build sales_by_deal_type array (for the monthly breakdown table).
 * Returns ['Offplan' => [...12 months...], 'Secondary' => [...], 'Rental' => [...]]
 *
 * @param  array $deals   Won deals with CLOSEDATE
 * @param  int   $year
 * @return array
 */
function buildSalesByDealType($deals, $year)
{
    $typeMap  = $GLOBALS['CFG_PROPERTY_TYPE_MAP'];
    $months   = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');

    // Initialize structure
    $grouped = array();
    foreach ($typeMap as $label) {
        $grouped[$label] = array();
        foreach ($months as $i => $m) {
            $grouped[$label][$m] = array('month' => $m, 'sales' => 0, 'commission' => 0, 'deals' => 0);
        }
    }

    foreach ($deals as $d) {
        $dt = new \DateTime($d['CLOSEDATE']);
        if ((int)$dt->format('Y') !== (int)$year) {
            continue;
        }
        $mn     = (int)$dt->format('n');
        $mName  = $months[$mn - 1];
        $typeId = (int)($d['property_type_id'] ?? 0);
        $label  = isset($typeMap[$typeId]) ? $typeMap[$typeId] : 'Other';

        if (!isset($grouped[$label])) {
            $grouped[$label] = array();
            foreach ($months as $m2) {
                $grouped[$label][$m2] = array('month' => $m2, 'sales' => 0, 'commission' => 0, 'deals' => 0);
            }
        }
        $grouped[$label][$mName]['sales']      += (float)($d['sale_amount'] ?? 0);
        $grouped[$label][$mName]['commission'] += (float)($d['commission']  ?? 0);
        $grouped[$label][$mName]['deals']++;
    }

    // Convert inner arrays to indexed arrays and cast to int
    $result = array();
    foreach ($grouped as $label => $monthData) {
        $result[$label] = array();
        foreach ($monthData as $m => $vals) {
            if ($vals['deals'] > 0 || true) {  // include all months for table completeness
                $result[$label][] = array(
                    'month'      => $vals['month'],
                    'sales'      => (int)$vals['sales'],
                    'commission' => (int)$vals['commission'],
                    'deals'      => (int)$vals['deals'],
                );
            }
        }
    }
    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════
// 11. TOP DEVELOPERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Build top developers array from won deals.
 * Groups by developer enum ID, maps to name, sorts by amount descending.
 *
 * @param  array $deals
 * @param  int   $limit
 * @return array
 */
function buildTopDevelopers($deals, $limit = 10)
{
    $devMap  = $GLOBALS['CFG_DEVELOPER_MAP'];
    $grouped = array();

    foreach ($deals as $d) {
        $devId = (int)($d['developer_id'] ?? 0);
        $name  = isset($devMap[$devId]) ? $devMap[$devId] : 'Other';
        if (!isset($grouped[$name])) {
            $grouped[$name] = array('amount' => 0, 'commission' => 0, 'deals' => 0);
        }
        $grouped[$name]['amount']     += (float)($d['sale_amount'] ?? 0);
        $grouped[$name]['commission'] += (float)($d['commission']  ?? 0);
        $grouped[$name]['deals']++;
    }

    $result = array();
    foreach ($grouped as $name => $g) {
        $result[] = array(
            'name'       => $name,
            'amount'     => (int)$g['amount'],
            'commission' => (int)$g['commission'],
            'deals'      => (int)$g['deals'],
        );
    }

    usort($result, function ($a, $b) {
        return $b['amount'] - $a['amount'];
    });
    return array_slice($result, 0, $limit);
}

/**
 * Build top property types array from won deals.
 */
function buildTopPropertyTypes($deals)
{
    $dist = buildDealDistribution($deals);
    $result = array();
    foreach ($dist as $d) {
        $result[] = array(
            'name'       => $d['name'],
            'amount'     => $d['amount'],
            'commission' => $d['commission'],
            'deals'      => $d['deals'],
        );
    }
    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════
// 12. TARGET HELPERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Get the monthly target for an agent.
 * Priority: agent-specific → WORK_POSITION-based → company default
 */
function getAgentTarget($userId, $workPosition)
{
    $uid     = (int)$userId;
    $targets = $GLOBALS['CFG_MONTHLY_TARGETS'];

    if (isset($targets['agents'][$uid])) {
        return (int)$targets['agents'][$uid];
    }
    if (isset($GLOBALS['CFG_POSITION_TARGET'][$workPosition])) {
        return (int)$GLOBALS['CFG_POSITION_TARGET'][$workPosition];
    }
    return (int)$targets['company'];
}

/**
 * Get the monthly target for a team (department).
 * Priority: team-specific → company default
 */
function getTeamTarget($deptId)
{
    $targets = $GLOBALS['CFG_MONTHLY_TARGETS'];
    if (isset($targets['teams'][(int)$deptId])) {
        return (int)$targets['teams'][(int)$deptId];
    }
    return (int)$targets['company'];
}

/**
 * Get company-wide monthly target.
 */
function getCompanyTarget()
{
    return (int)$GLOBALS['CFG_MONTHLY_TARGETS']['company'];
}

// ═══════════════════════════════════════════════════════════════════════════
// 13. YEAR SUMMARY  (for year comparison pills)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Fetch a year's summary metrics for the year comparison section.
 * Runs its own query scoped to the full year.
 *
 * @param  int   $year
 * @param  array $agentIds  Empty = all agents
 * @return array
 */
function fetchYearSummary($year, $agentIds = array())
{
    $range = buildDateRange($year, 'All', 'All');
    $deals = fetchWonDeals($agentIds, $range, 'All');
    $agg   = aggregateDeals($deals);
    return array(
        'sales'      => $agg['sales_volume'],
        'commission' => $agg['commissions'],
        'deals'      => $agg['deal_count'],
        'agents'     => empty($agentIds) ? countAllActiveAgents() : count($agentIds),
        'avg_deal'   => $agg['avg_sales_per_deal'],
    );
}

/**
 * Count total active agents across all sales sub-departments.
 */
function countAllActiveAgents()
{
    $parentId = dbInt(DEPT_SALES_ROOT);

    $row = dbQueryOne("
        SELECT COUNT(DISTINCT u.ID) AS cnt
        FROM b_user u

        JOIN b_utm_user ud
            ON ud.VALUE_ID = u.ID
           AND ud.FIELD_ID = 40

        JOIN b_iblock_section s 
            ON s.ID = ud.VALUE_INT

        WHERE u.ACTIVE = 'Y'
          AND s.IBLOCK_ID = 3
          AND s.IBLOCK_SECTION_ID = {$parentId}
    ");

    return (int)($row['cnt'] ?? 0);
}

// ═══════════════════════════════════════════════════════════════════════════
// 14. COMMITTED VS OPERATIONAL COMMISSION SPLIT
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Build the committed/operational commission split.
 *
 * Operational = won deals (C3:WON) → already in $wonDeals aggregation
 * Committed   = all other open stages in pipeline 3
 *
 * Returns array with both totals and percentages.
 */
function buildCommissionSplit($wonDeals, $agentIds, $dateRange, $dealType)
{
    $operationalComm = 0;
    foreach ($wonDeals as $d) {
        $operationalComm += (float)($d['commission'] ?? 0);
    }

    $committedDeals = fetchCommittedDeals($agentIds, $dateRange, $dealType);
    $committedComm  = 0;
    foreach ($committedDeals as $d) {
        $committedComm += (float)($d['commission'] ?? 0);
    }

    $total = $operationalComm + $committedComm;
    return array(
        'total'                      => (int)$total,
        'committed_commission'       => (int)$committedComm,
        'committed_commission_pct'   => $total > 0 ? round(($committedComm / $total) * 100, 1) : 0,
        'operational_commission'     => (int)$operationalComm,
        'operational_commission_pct' => $total > 0 ? round(($operationalComm / $total) * 100, 1) : 0,
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// 15. MONTHLY DEAL BREAKDOWN FETCHER  (for year comparison)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Fetch 12-month breakdown for a full year (for year comparison chart).
 * Groups by month, returns sales/commission/deals per month.
 */
function fetchYearMonthly($year, $agentIds = array())
{
    $range = buildDateRange($year, 'All', 'All');
    $deals = fetchWonDeals($agentIds, $range, 'All');
    return groupDealsByMonth($deals, $year);
}

// ═══════════════════════════════════════════════════════════════════════════
// 16. AGENT PERFORMANCE ROW BUILDER
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Build a single agent's performance row for the agent table.
 * Used in both CEO and Manager views.
 *
 * @param  array $userRow    Row from b_user
 * @param  array $wonDeals   Pre-fetched won deals (already filtered for this agent)
 * @param  array $dateRange
 * @return array
 */
function buildAgentPerformanceRow($userRow, $wonDeals, $dateRange)
{
    $uid = (int)$userRow['ID'];
    $agg = aggregateDeals($wonDeals);

    $leadCount       = countActiveLeads(array($uid), $dateRange);
    $reshuffledCount = countReshuffledLeads(array($uid), $dateRange);
    $listingCount    = countTotalListings(array($uid));
    $lastDealDays    = daysSinceLastDeal(array($uid));
    $avgGap          = avgGapBetweenDeals($uid, $dateRange);
    $attendance      = countAttendanceDays($uid, $dateRange);

    return array(
        'id'               => $uid,
        'name'             => fullName($userRow),
        'designation'      => $userRow['WORK_POSITION'] ?? '',
        'leads'            => $leadCount,
        'reshuffled_leads' => $reshuffledCount,
        'listings'         => $listingCount,
        'deals'            => $agg['deal_count'],
        'sales'            => $agg['sales_volume'],
        'commission'       => $agg['commissions'],
        'top_deal'         => $agg['top_deal'],
        'top_commission'   => $agg['top_commission'],
        'avg_gap'          => $avgGap,
        'last_deal_days'   => $lastDealDays,
        'attendance'       => $attendance,
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// 17. AVERAGE TICKET SIZE  (for agent view chart)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Calculate average ticket size (avg sale per deal) per month.
 * Uses the same monthly breakdown, just divides sales by deal count.
 */
function buildAvgTicketSize($monthlyDeals)
{
    $result = array();
    foreach ($monthlyDeals as $m) {
        $avg = $m['deals'] > 0 ? (int)round($m['sales'] / $m['deals']) : 0;
        $result[] = array(
            'month' => $m['month'],
            'value' => $avg,
        );
    }
    return $result;
}
