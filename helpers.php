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

    $today = date('Y-m-d');
    $currentYear = (int)date('Y');

    if ($year === 'All' || !is_numeric($year)) {
        return array('from' => '2020-01-01', 'to' => '2099-12-31');
    }

    $y = (int)$year;

    // Specific month
    if ($month !== 'All' && isset($monthNames[$month])) {
        $m       = $monthNames[$month];
        $lastDay = date('t', mktime(0, 0, 0, $m, 1, $y));
        return array(
            'from' => sprintf('%04d-%02d-01', $y, $m),
            'to'   => sprintf('%04d-%02d-%02d', $y, $m, $lastDay)
        );
    }

    // Quarter
    if ($quarter !== 'All' && isset($quarterMap[$quarter])) {
        $qm      = $quarterMap[$quarter];
        $lastDay = date('t', mktime(0, 0, 0, $qm[1], 1, $y));
        return array(
            'from' => sprintf('%04d-%02d-01', $y, $qm[0]),
            'to'   => sprintf('%04d-%02d-%02d', $y, $qm[1], $lastDay)
        );
    }

    // Full year
    return array(
        'from' => $y . '-01-01',
        'to'   => $y . '-12-31'
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
 * Determine company for a Bitrix user ID.
 * Returns 'mira' | 'eva'
 */
function getUserCompany($userId)
{
    $uid = dbInt($userId);
    if ($uid <= 0) {
        return COMPANY_MIRA;
    }

    $row = dbQueryOne("
        SELECT uts_u." . FIELD_COMPANY_USER . " AS company_enum
        FROM b_user u
        LEFT JOIN b_uts_user uts_u ON uts_u.VALUE_ID = u.ID
        WHERE u.ID = {$uid}
        LIMIT 1
    ");

    if (!empty($row['company_enum'])) {
        $enumVal = (int)$row['company_enum'];
        if ($enumVal === COMPANY_USER_EVA) {
            return COMPANY_EVA;
        }
        if ($enumVal === COMPANY_USER_MIRA) {
            return COMPANY_MIRA;
        }
    }

    // Fallback: check if user is in any Eva department (root 35 or sub-depts)
    $evaDeptIds = $GLOBALS['CFG_SALES_REPORT_DEPARTMENT_IDS_EVA'] ?? array(36);
    $deptRow = dbQueryOne("
        SELECT 1 AS match_found
        FROM b_utm_user
        WHERE VALUE_ID = {$uid}
          AND FIELD_ID = 40
          AND VALUE_INT IN " . inClauseInt($evaDeptIds) . "
        LIMIT 1
    ");

    if (!empty($deptRow['match_found'])) {
        return COMPANY_EVA;
    }

    return COMPANY_MIRA;
}

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

function normalizeWorkPosition($workPosition)
{
    return strtoupper(trim((string)$workPosition));
}

function isAllowedAgentPosition($workPosition)
{
    return in_array(
        normalizeWorkPosition($workPosition),
        $GLOBALS['CFG_ALLOWED_AGENT_POSITIONS'],
        true
    );
}

function getNonAgentUserIds()
{
    return array_values(array_unique(array_merge(
        $GLOBALS['CFG_CEO_USER_IDS'] ?? [],
        $GLOBALS['CFG_MANAGER_USER_IDS'] ?? [],
        $GLOBALS['CFG_EXCLUDED_USER_IDS'] ?? []
    )));
}

function getSalesReportDepartmentIds($includeRoot = true, $company = 'mira')
{
    $root = ($company === COMPANY_EVA) ? DEPT_SALES_ROOT_EVA : DEPT_SALES_ROOT_MIRA;
    $cfgKey = ($company === COMPANY_EVA) ? 'CFG_SALES_REPORT_DEPARTMENT_IDS_EVA' : 'CFG_SALES_REPORT_DEPARTMENT_IDS_MIRA';
    $deptIds = array_map('intval', $GLOBALS[$cfgKey] ?? array($root));
    $deptIds = array_values(array_unique(array_filter($deptIds, function ($id) {
        return $id > 0;
    })));

    if (!$includeRoot) {
        $excluded = array((int)$root);
        if ($company === COMPANY_EVA) {
            $excluded[] = 37; // Off Plan Market Department
            $excluded[] = 38; // Secondary Market Department
        }
        $deptIds = array_values(array_filter($deptIds, function ($id) use ($excluded) {
            return !in_array($id, $excluded, true);
        }));
    }

    return $deptIds;
}

function filterAllowedSalesDepartmentIds($deptIds, $includeRoot = true, $company = 'mira')
{
    if (!is_array($deptIds)) {
        $deptIds = array($deptIds);
    }

    $allowedIds = getSalesReportDepartmentIds($includeRoot, $company);
    return array_values(array_unique(array_intersect(
        array_map('intval', $deptIds),
        $allowedIds
    )));
}

function isUserInAllowedSalesDepartments($userId, $company = 'mira')
{
    $uid = (int)$userId;
    if ($company === COMPANY_MIRA && ($uid === 168 || $uid === 156)) {
        return true;
    }
    $uid = dbInt($userId);

    // If user's WORK_POSITION is "Private Office", they belong to PO team (dept 23) in Mira
    if ($company === COMPANY_MIRA) {
        $userRow = dbQueryOne("SELECT WORK_POSITION FROM b_user WHERE ID = {$uid} LIMIT 1");
        if ($userRow && trim(strtolower($userRow['WORK_POSITION'] ?? '')) === 'private office') {
            return true;
        }
    }

    $allowedDeptIds = getSalesReportDepartmentIds(true, $company);
    if (empty($allowedDeptIds)) {
        return false;
    }

    $row = dbQueryOne("
        SELECT 1 AS match_found
        FROM b_utm_user
        WHERE VALUE_ID = {$uid}
          AND FIELD_ID = 40
          AND VALUE_INT IN " . inClauseInt($allowedDeptIds) . "
        LIMIT 1
    ");

    if (!empty($row['match_found'])) {
        return true;
    }

    $histRow = dbQueryOne("
        SELECT 1 AS match_found
        FROM b_agent_dept_history
        WHERE USER_ID = {$uid}
          AND DEPT_ID IN " . inClauseInt($allowedDeptIds) . "
        LIMIT 1
    ");

    return !empty($histRow['match_found']);
}

/**
 * Fetch all sales sub-departments for the specified company.
 * Returns array of ['ID', 'NAME', 'UF_HEAD', 'DISPLAY_NAME'] rows.
 */
function getSalesTeams($company = 'mira')
{
    $teamIds = getSalesReportDepartmentIds(false, $company);
    if (empty($teamIds)) {
        return array();
    }

    $rows = dbQuery("
        SELECT 
            s.ID,
            s.NAME,
            uts.UF_HEAD
        FROM b_iblock_section s
        LEFT JOIN b_uts_iblock_3_section uts 
            ON uts.VALUE_ID = s.ID
        WHERE s.IBLOCK_ID = 3
          AND s.ACTIVE = 'Y'
          AND s.ID IN " . inClauseInt($teamIds) . "
        ORDER BY s.SORT ASC, s.NAME ASC
    ");

    foreach ($rows as &$row) {
        $row['UF_HEAD'] = resolveSalesTeamHeadId($row, $company);
        $row['DISPLAY_NAME'] = getSalesTeamDisplayName($row, $company);
    }
    unset($row);

    return $rows;
}

function getSalesTeamHeadIds($teams)
{
    $headIds = array();
    foreach ($teams as $team) {
        $headId = (int)($team['UF_HEAD'] ?? 0);
        if ($headId > 0) {
            $headIds[] = $headId;
        }
    }

    return array_values(array_unique($headIds));
}

function getSalesTeamById($deptId, $company = 'mira')
{
    $deptId = dbInt($deptId);
    if (!in_array($deptId, getSalesReportDepartmentIds(false, $company), true)) {
        // Try resolving company if not found
        $detectedCompany = ($company === COMPANY_MIRA) ? COMPANY_EVA : COMPANY_MIRA;
        if (!in_array($deptId, getSalesReportDepartmentIds(false, $detectedCompany), true)) {
            return array();
        }
        $company = $detectedCompany;
    }

    $row = dbQueryOne("
        SELECT 
            s.ID,
            s.NAME,
            uts.UF_HEAD
        FROM b_iblock_section s
        LEFT JOIN b_uts_iblock_3_section uts 
            ON uts.VALUE_ID = s.ID
        WHERE s.IBLOCK_ID = 3
          AND s.ACTIVE = 'Y'
          AND s.ID = {$deptId}
        LIMIT 1
    ");

    if (!empty($row)) {
        $row['UF_HEAD'] = resolveSalesTeamHeadId($row, $company);
        $row['DISPLAY_NAME'] = getSalesTeamDisplayName($row, $company);
    }

    return $row;
}

function resolveSalesTeamHeadId($teamRow, $company = 'mira')
{
    $deptId = (int)($teamRow['ID'] ?? 0);
    $cfgKey = ($company === COMPANY_EVA) ? 'CFG_SALES_TEAM_HEAD_BY_DEPT_EVA' : 'CFG_SALES_TEAM_HEAD_BY_DEPT_MIRA';
    $headsByDept = $GLOBALS[$cfgKey] ?? array();
    if ($deptId > 0 && isset($headsByDept[$deptId])) {
        return (int)$headsByDept[$deptId];
    }

    return (int)($teamRow['UF_HEAD'] ?? 0);
}

function getSalesTeamCode($teamRow, $company = 'mira')
{
    $deptId = (int)($teamRow['ID'] ?? 0);
    $teamName = trim((string)($teamRow['NAME'] ?? ''));
    $cfgKey = ($company === COMPANY_EVA) ? 'CFG_SALES_TEAM_CODE_BY_DEPT_EVA' : 'CFG_SALES_TEAM_CODE_BY_DEPT_MIRA';
    $codesByDept = $GLOBALS[$cfgKey] ?? array();

    if ($deptId > 0 && !empty($codesByDept[$deptId])) {
        return strtoupper(trim((string)$codesByDept[$deptId]));
    }

    if ($teamName !== '' && preg_match('/^Sales Team\s+(\d+)$/i', $teamName, $m)) {
        return 'ST' . $m[1];
    }

    if ($teamName !== '' && preg_match('/^Private Office$/i', $teamName)) {
        return 'PO';
    }

    $parts = preg_split('/[^A-Za-z0-9]+/', $teamName, -1, PREG_SPLIT_NO_EMPTY);
    $code = '';
    foreach ($parts as $part) {
        $code .= strtoupper(substr($part, 0, 1));
    }

    return $code !== '' ? $code : strtoupper($teamName);
}

function getSalesTeamDisplayName($teamRow, $company = 'mira')
{
    static $headProfileCache = array();

    $teamName = trim((string)($teamRow['NAME'] ?? ''));
    $code = getSalesTeamCode($teamRow, $company);
    $headId = resolveSalesTeamHeadId($teamRow, $company);
    $headFirstName = '';

    if ($headId > 0) {
        if (!isset($headProfileCache[$headId])) {
            $headProfileCache[$headId] = getUserProfile($headId);
        }

        $headFirstName = strtoupper(trim((string)($headProfileCache[$headId]['NAME'] ?? '')));
    }

    if ($code !== '' && $headFirstName !== '') {
        return $code . ' - ' . $headFirstName;
    }

    if ($code !== '') {
        return $code;
    }

    return $teamName;
}

function getAgentsByDept($deptIds, $applyPrivateOfficeOverride = true, $dateRange = null, $company = 'mira')
{
    $deptIds = filterAllowedSalesDepartmentIds($deptIds, true, $company);
    if (empty($deptIds)) {
        return array();
    }

    $in = inClauseInt($deptIds);

    $nonAgentIds = getNonAgentUserIds();
    $excludeNonAgents = !empty($nonAgentIds)
        ? 'AND u.ID NOT IN ' . inClauseInt($nonAgentIds)
        : '';

    $deptExpr = ($company === COMPANY_EVA)
        ? "ud.VALUE_INT IN {$in}"
        : ($applyPrivateOfficeOverride
            ? "(ud.VALUE_INT IN {$in} OR (23 IN {$in} AND TRIM(LOWER(u.WORK_POSITION)) = 'private office') OR (u.ID = 168 AND 30 IN {$in}) OR (u.ID = 156 AND 26 IN {$in}))"
            : "(ud.VALUE_INT IN {$in} OR (u.ID = 168 AND 30 IN {$in}) OR (u.ID = 156 AND 26 IN {$in}))");

    if ($dateRange && isset($dateRange['from']) && isset($dateRange['to'])) {
        $from = dbEsc($dateRange['from']);
        $to   = dbEsc($dateRange['to']);

        $sql = "
            SELECT DISTINCT
                u.ID,
                u.ACTIVE,
                u.NAME,
                u.LAST_NAME,
                u.WORK_POSITION,
                u.DATE_REGISTER,
                u.PERSONAL_PHOTO,
                uts_u.UF_USR_1778656838068
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40   -- UF_DEPARTMENT
            LEFT JOIN b_uts_user uts_u
                ON uts_u.VALUE_ID = u.ID
            WHERE u.ACTIVE = 'Y'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND (
                  {$deptExpr}
                  OR u.ID IN (
                      SELECT h.USER_ID 
                      FROM b_agent_dept_history h 
                      WHERE h.DEPT_ID IN {$in}
                        AND h.EFFECTIVE_FROM <= '{$to}'
                        AND (h.EFFECTIVE_TO IS NULL OR h.EFFECTIVE_TO >= '{$from}')
                  )
              )
              {$excludeNonAgents}
            ORDER BY u.LAST_NAME ASC, u.NAME ASC
        ";
    } else {
        $sql = "
            SELECT DISTINCT
                u.ID,
                u.ACTIVE,
                u.NAME,
                u.LAST_NAME,
                u.WORK_POSITION,
                u.DATE_REGISTER,
                u.PERSONAL_PHOTO,
                uts_u.UF_USR_1778656838068
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40   -- UF_DEPARTMENT
            LEFT JOIN b_uts_user uts_u
                ON uts_u.VALUE_ID = u.ID
            WHERE u.ACTIVE = 'Y'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND {$deptExpr}
              {$excludeNonAgents}
            ORDER BY u.LAST_NAME ASC, u.NAME ASC
        ";
    }

    return dbQuery($sql);
}

/**
 * Fetch dismissed agents (ACTIVE = 'N') who were in a given department.
 */
function getDismissedAgentsByDept($deptIds, $applyPrivateOfficeOverride = true, $dateRange = null, $company = 'mira')
{
    $deptIds = filterAllowedSalesDepartmentIds($deptIds, true, $company);
    if (empty($deptIds)) {
        return array();
    }

    $in = inClauseInt($deptIds);

    $nonAgentIds = getNonAgentUserIds();
    $excludeNonAgents = !empty($nonAgentIds)
        ? 'AND u.ID NOT IN ' . inClauseInt($nonAgentIds)
        : '';

    $deptExpr = ($company === COMPANY_EVA)
        ? "ud.VALUE_INT IN {$in}"
        : ($applyPrivateOfficeOverride
            ? "(ud.VALUE_INT IN {$in} OR (23 IN {$in} AND TRIM(LOWER(u.WORK_POSITION)) = 'private office') OR (u.ID = 168 AND 30 IN {$in}) OR (u.ID = 156 AND 26 IN {$in}))"
            : "(ud.VALUE_INT IN {$in} OR (u.ID = 168 AND 30 IN {$in}) OR (u.ID = 156 AND 26 IN {$in}))");

    if ($dateRange && isset($dateRange['from']) && isset($dateRange['to'])) {
        $from = dbEsc($dateRange['from']);
        $to   = dbEsc($dateRange['to']);

        $sql = "
            SELECT DISTINCT
                u.ID,
                u.ACTIVE,
                u.NAME,
                u.LAST_NAME,
                u.WORK_POSITION,
                u.DATE_REGISTER,
                u.PERSONAL_PHOTO,
                uts_u.UF_USR_1778656838068
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40   -- UF_DEPARTMENT
            LEFT JOIN b_uts_user uts_u
                ON uts_u.VALUE_ID = u.ID
            WHERE u.ACTIVE = 'N'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND (
                  {$deptExpr}
                  OR u.ID IN (
                      SELECT h.USER_ID 
                      FROM b_agent_dept_history h 
                      WHERE h.DEPT_ID IN {$in}
                        AND h.EFFECTIVE_FROM <= '{$to}'
                        AND (h.EFFECTIVE_TO IS NULL OR h.EFFECTIVE_TO >= '{$from}')
                  )
              )
              {$excludeNonAgents}
            ORDER BY u.LAST_NAME ASC, u.NAME ASC
        ";
    } else {
        $sql = "
            SELECT DISTINCT
                u.ID,
                u.ACTIVE,
                u.NAME,
                u.LAST_NAME,
                u.WORK_POSITION,
                u.DATE_REGISTER,
                u.PERSONAL_PHOTO,
                uts_u.UF_USR_1778656838068
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40   -- UF_DEPARTMENT
            LEFT JOIN b_uts_user uts_u
                ON uts_u.VALUE_ID = u.ID
            WHERE u.ACTIVE = 'N'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND (
                  {$deptExpr}
                  OR u.ID IN (
                      SELECT h.USER_ID 
                      FROM b_agent_dept_history h 
                      WHERE h.DEPT_ID IN {$in}
                  )
              )
              {$excludeNonAgents}
            ORDER BY u.LAST_NAME ASC, u.NAME ASC
        ";
    }

    return dbQuery($sql);
}

/**
 * Fetch all active user IDs in a given department (and its sub-departments), without role restrictions.
 *
 * @param  int|array $deptIds  Single dept ID or array of dept IDs
 * @param  bool      $applyPrivateOfficeOverride  See getAgentsByDept(). Default true (CEO-view
 *                    grouping); pass false for manager-view (real department) behavior.
 * @param  array|null $dateRange Optional date range filter to include historical members.
 * @param  string    $company 'mira' | 'eva'
 * @return array
 */
function getDeptUserIds($deptIds, $applyPrivateOfficeOverride = true, $dateRange = null, $company = 'mira')
{
    $deptIds = filterAllowedSalesDepartmentIds($deptIds, true, $company);
    if (empty($deptIds)) {
        return array();
    }

    $in = inClauseInt($deptIds);

    $deptExpr = ($company === COMPANY_EVA)
        ? "ud.VALUE_INT IN {$in}"
        : ($applyPrivateOfficeOverride
            ? "(ud.VALUE_INT IN {$in} OR (23 IN {$in} AND TRIM(LOWER(u.WORK_POSITION)) = 'private office') OR (u.ID = 168 AND 30 IN {$in}) OR (u.ID = 156 AND 26 IN {$in}))"
            : "(ud.VALUE_INT IN {$in} OR (u.ID = 168 AND 30 IN {$in}) OR (u.ID = 156 AND 26 IN {$in}))");

    if ($dateRange && isset($dateRange['from']) && isset($dateRange['to'])) {
        $from = dbEsc($dateRange['from']);
        $to   = dbEsc($dateRange['to']);

        $sql = "
            SELECT DISTINCT u.ID
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40   -- UF_DEPARTMENT
            WHERE u.ACTIVE = 'Y'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND (
                  {$deptExpr}
                  OR u.ID IN (
                      SELECT h.USER_ID 
                      FROM b_agent_dept_history h 
                      WHERE h.DEPT_ID IN {$in}
                        AND h.EFFECTIVE_FROM <= '{$to}'
                        AND (h.EFFECTIVE_TO IS NULL OR h.EFFECTIVE_TO >= '{$from}')
                  )
              )
        ";
    } else {
        $sql = "
            SELECT DISTINCT u.ID
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40   -- UF_DEPARTMENT
            WHERE u.ACTIVE = 'Y'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND {$deptExpr}
        ";
    }

    $rows = dbQuery($sql);

    return array_map(function ($row) {
        return (int)$row['ID'];
    }, $rows);
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
            u.ACTIVE,
            u.NAME,
            u.LAST_NAME,
            u.WORK_POSITION,
            u.DATE_REGISTER,
            u.EMAIL,
            uts_u.UF_USR_1778656838068,
            uts_u." . FIELD_COMPANY_USER . " AS company_enum
        FROM b_user u
        LEFT JOIN b_uts_user uts_u
            ON uts_u.VALUE_ID = u.ID
        WHERE u.ID = {$uid}
        LIMIT 1
    ");
}

/**
 * Get manager's name for an agent by UF_HEAD of their department.
 */
function getManagerForAgent($userId, $company = null)
{
    $uid = dbInt($userId);
    if ($company === null) {
        $company = getUserCompany($userId);
    }

    // If user's WORK_POSITION is "Private Office" in Mira, their manager is the head of department 23 (Aldo De Jager)
    if ($company === COMPANY_MIRA) {
        $userRow = dbQueryOne("SELECT WORK_POSITION FROM b_user WHERE ID = {$uid} LIMIT 1");
        if ($userRow && trim(strtolower($userRow['WORK_POSITION'] ?? '')) === 'private office') {
            $poHeadRow = dbQueryOne("
                SELECT m.NAME, m.LAST_NAME
                FROM b_uts_iblock_3_section uts
                JOIN b_user m ON m.ID = uts.UF_HEAD
                WHERE uts.VALUE_ID = 23
                LIMIT 1
            ");
            if ($poHeadRow) {
                return trim($poHeadRow['NAME'] . ' ' . $poHeadRow['LAST_NAME']);
            }
            return '';
        }
    }

    $row = dbQueryOne("
        SELECT s.ID AS DEPT_ID, uts.UF_HEAD, CONCAT(m.NAME, ' ', m.LAST_NAME) AS FULL_NAME
        FROM b_utm_user ud

        JOIN b_iblock_section s 
            ON s.ID = ud.VALUE_INT

        LEFT JOIN b_uts_iblock_3_section uts 
            ON uts.VALUE_ID = s.ID

        LEFT JOIN b_user m 
            ON m.ID = uts.UF_HEAD

        WHERE ud.VALUE_ID = {$uid}
          AND ud.FIELD_ID = 40
        LIMIT 1
    ");

    if ($row) {
        $deptId = (int)($row['DEPT_ID'] ?? 0);
        $headId = resolveSalesTeamHeadId(array('ID' => $deptId, 'UF_HEAD' => $row['UF_HEAD']), $company);
        if ($headId > 0) {
            $headProf = getUserProfile($headId);
            if (!empty($headProf)) {
                return fullName($headProf);
            }
        }
        if (!empty($row['FULL_NAME'])) {
            return trim($row['FULL_NAME']);
        }
    }

    // Fallback for dismissed users from history table
    $allowedDeptIds = getSalesReportDepartmentIds(false, $company);
    $histRow = dbQueryOne("
        SELECT h.DEPT_ID, uts.UF_HEAD, CONCAT(m.NAME, ' ', m.LAST_NAME) AS FULL_NAME
        FROM b_agent_dept_history h
        JOIN b_uts_iblock_3_section uts ON uts.VALUE_ID = h.DEPT_ID
        LEFT JOIN b_user m ON m.ID = uts.UF_HEAD
        WHERE h.USER_ID = {$uid}
          AND h.DEPT_ID IN " . inClauseInt($allowedDeptIds) . "
        ORDER BY h.EFFECTIVE_FROM DESC
        LIMIT 1
    ");

    if ($histRow) {
        $deptId = (int)($histRow['DEPT_ID'] ?? 0);
        $headId = resolveSalesTeamHeadId(array('ID' => $deptId, 'UF_HEAD' => $histRow['UF_HEAD']), $company);
        if ($headId > 0) {
            $headProf = getUserProfile($headId);
            if (!empty($headProf)) {
                return fullName($headProf);
            }
        }
        return trim((string)($histRow['FULL_NAME'] ?? ''));
    }

    return '';
}

/**
 * Resolve department ID(s) for a user.
 * UF_DEPARTMENT in b_user is stored as JSON array in modern Bitrix.
 * Returns first department ID as int.
 */
function getUserDeptId($userId, $company = null)
{
    $uid = dbInt($userId);
    if ($company === null) {
        $company = getUserCompany($userId);
    }

    if ($company === COMPANY_MIRA) {
        if ($uid === 156) {
            return 26; // ST3 branch
        }
        if ($uid === 168) {
            return 30; // TG department
        }

        // Check if the user is in Private Office via WORK_POSITION
        $userRow = dbQueryOne("SELECT WORK_POSITION FROM b_user WHERE ID = {$uid} LIMIT 1");
        if ($userRow && trim(strtolower($userRow['WORK_POSITION'] ?? '')) === 'private office') {
            return 23; // Private Office department ID
        }
    }

    $allowedDeptIds = getSalesReportDepartmentIds(true, $company);

    $row = dbQueryOne("
        SELECT VALUE_INT
        FROM b_utm_user
        WHERE VALUE_ID = {$uid}
          AND FIELD_ID = 40
          AND VALUE_INT IN " . inClauseInt($allowedDeptIds) . "
        LIMIT 1
    ");

    if (!empty($row['VALUE_INT'])) {
        return (int)$row['VALUE_INT'];
    }

    // Fallback for dismissed users from history table
    $histRow = dbQueryOne("
        SELECT DEPT_ID
        FROM b_agent_dept_history
        WHERE USER_ID = {$uid}
          AND DEPT_ID IN " . inClauseInt($allowedDeptIds) . "
        ORDER BY EFFECTIVE_FROM DESC
        LIMIT 1
    ");

    return (int)($histRow['DEPT_ID'] ?? 0);
}

function getUserOriginalDeptId($userId, $company = null)
{
    $uid = dbInt($userId);
    if ($company === null) {
        $company = getUserCompany($userId);
    }

    if ($company === COMPANY_MIRA) {
        if ($uid === 156) {
            return 26; // ST3 branch
        }
        if ($uid === 168) {
            return 30; // TG department
        }
    }

    $root = ($company === COMPANY_EVA) ? DEPT_SALES_ROOT_EVA : DEPT_SALES_ROOT_MIRA;
    $allowedDeptIds = getSalesReportDepartmentIds(true, $company);

    // Exclude Private Office (23) and Sales Root to get the agent's actual sales team department
    $allowedDeptIds = array_values(array_filter($allowedDeptIds, function($id) use ($root) {
        return (int)$id !== 23 && (int)$id !== (int)$root;
    }));

    $row = dbQueryOne("
        SELECT VALUE_INT
        FROM b_utm_user
        WHERE VALUE_ID = {$uid}
          AND FIELD_ID = 40
          AND VALUE_INT IN " . inClauseInt($allowedDeptIds) . "
        LIMIT 1
    ");

    if (!empty($row['VALUE_INT'])) {
        return (int)$row['VALUE_INT'];
    }

    // Fallback from history table
    $histRow = dbQueryOne("
        SELECT DEPT_ID
        FROM b_agent_dept_history
        WHERE USER_ID = {$uid}
          AND DEPT_ID IN " . inClauseInt($allowedDeptIds) . "
        ORDER BY EFFECTIVE_FROM DESC
        LIMIT 1
    ");

    return (int)($histRow['DEPT_ID'] ?? 0);
}

function getListingBranchCodeForDeptId($deptId)
{
    $deptId = (int)$deptId;
    return $GLOBALS['CFG_LISTING_BRANCH_BY_DEPT'][$deptId] ?? '';
}

function getListingBranchCodesForDeptIds($deptIds)
{
    if (!is_array($deptIds)) {
        $deptIds = array($deptIds);
    }

    $codes = array();
    foreach ($deptIds as $deptId) {
        $code = getListingBranchCodeForDeptId($deptId);
        if ($code !== '') {
            $codes[] = $code;
        }
    }

    return array_values(array_unique($codes));
}

function getListingBranchCodesForUserIds($userIds, $company = 'mira')
{
    if (!is_array($userIds)) {
        $userIds = array($userIds);
    }

    $codes = array();
    foreach ($userIds as $userId) {
        $deptId = getUserDeptId($userId, $company);
        $code = getListingBranchCodeForDeptId($deptId);
        if ($code !== '') {
            $codes[] = $code;
        }
    }

    return array_values(array_unique($codes));
}

/**
 * Get all agent user IDs managed by a given manager (by department UF_HEAD).
 * Returns array of integer user IDs.
 *
 * @param  int  $managerId
 * @param  bool $applyPrivateOfficeOverride  See getAgentsByDept(). Default true (CEO-view
 *              grouping); pass false for manager-view (real department) behavior, so a
 *              Private Office agent shows up normally under their actual manager/department.
 * @param  array|null $dateRange Optional date range filter to include historical members.
 * @param  string|null $company
 */
function getAgentIdsByManager($managerId, $applyPrivateOfficeOverride = true, $dateRange = null, $company = null)
{
    $mid = dbInt($managerId);
    if ($company === null) {
        $company = getUserCompany($managerId);
    }

    $nonAgentIds = getNonAgentUserIds();
    $excludeNonAgents = !empty($nonAgentIds)
        ? 'AND u.ID NOT IN ' . inClauseInt($nonAgentIds)
        : '';

    // Find all departments this manager heads using fallback-aware logic
    $managerDepts = array();
    if ($company === COMPANY_MIRA && $mid === 156) {
        $managerDepts[] = 26; // ST3 branch
    }
    $salesTeams = getSalesTeams($company);
    foreach ($salesTeams as $team) {
        if ((int)$team['UF_HEAD'] === $mid) {
            $managerDepts[] = (int)$team['ID'];
        }
    }

    if (empty($managerDepts)) {
        return array();
    }

    $deptIn = inClauseInt($managerDepts);

    $deptExpr = ($company === COMPANY_EVA)
        ? "ud.VALUE_INT IN {$deptIn}"
        : ($applyPrivateOfficeOverride
            ? "(ud.VALUE_INT IN {$deptIn} OR (23 IN {$deptIn} AND TRIM(LOWER(u.WORK_POSITION)) = 'private office') OR (u.ID = 168 AND 30 IN {$deptIn}) OR (u.ID = 156 AND 26 IN {$deptIn}))"
            : "(ud.VALUE_INT IN {$deptIn} OR (u.ID = 168 AND 30 IN {$deptIn}) OR (u.ID = 156 AND 26 IN {$deptIn}))");

    if ($dateRange && isset($dateRange['from']) && isset($dateRange['to'])) {
        $from = dbEsc($dateRange['from']);
        $to   = dbEsc($dateRange['to']);

        $sql = "
            SELECT DISTINCT u.ID
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40
            WHERE u.ACTIVE = 'Y'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND (
                  {$deptExpr}
                  OR u.ID IN (
                      SELECT h.USER_ID 
                      FROM b_agent_dept_history h 
                      WHERE h.DEPT_ID IN {$deptIn}
                        AND h.EFFECTIVE_FROM <= '{$to}'
                        AND (h.EFFECTIVE_TO IS NULL OR h.EFFECTIVE_TO >= '{$from}')
                  )
              )
              {$excludeNonAgents}
        ";
    } else {
        $sql = "
            SELECT DISTINCT u.ID
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40
            WHERE u.ACTIVE = 'Y'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND {$deptExpr}
              {$excludeNonAgents}
        ";
    }

    $rows = dbQuery($sql);

    return array_map(function ($r) {
        return (int)$r['ID'];
    }, $rows);
}

/**
 * Get dismissed agent user IDs (ACTIVE = 'N') managed by a given manager.
 */
function getDismissedAgentIdsByManager($managerId, $applyPrivateOfficeOverride = true, $dateRange = null, $company = null)
{
    $mid = dbInt($managerId);
    if ($company === null) {
        $company = getUserCompany($managerId);
    }

    $nonAgentIds = getNonAgentUserIds();
    $excludeNonAgents = !empty($nonAgentIds)
        ? 'AND u.ID NOT IN ' . inClauseInt($nonAgentIds)
        : '';

    $managerDepts = array();
    if ($company === COMPANY_MIRA && $mid === 156) {
        $managerDepts[] = 26; // ST3 branch
    }
    $salesTeams = getSalesTeams($company);
    foreach ($salesTeams as $team) {
        if ((int)$team['UF_HEAD'] === $mid) {
            $managerDepts[] = (int)$team['ID'];
        }
    }

    if (empty($managerDepts)) {
        return array();
    }

    $deptIn = inClauseInt($managerDepts);

    $deptExpr = ($company === COMPANY_EVA)
        ? "ud.VALUE_INT IN {$deptIn}"
        : ($applyPrivateOfficeOverride
            ? "(ud.VALUE_INT IN {$deptIn} OR (23 IN {$deptIn} AND TRIM(LOWER(u.WORK_POSITION)) = 'private office') OR (u.ID = 168 AND 30 IN {$deptIn}) OR (u.ID = 156 AND 26 IN {$deptIn}))"
            : "(ud.VALUE_INT IN {$deptIn} OR (u.ID = 168 AND 30 IN {$deptIn}) OR (u.ID = 156 AND 26 IN {$deptIn}))");

    if ($dateRange && isset($dateRange['from']) && isset($dateRange['to'])) {
        $from = dbEsc($dateRange['from']);
        $to   = dbEsc($dateRange['to']);

        $sql = "
            SELECT DISTINCT u.ID
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40
            WHERE u.ACTIVE = 'N'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND (
                  {$deptExpr}
                  OR u.ID IN (
                      SELECT h.USER_ID 
                      FROM b_agent_dept_history h 
                      WHERE h.DEPT_ID IN {$deptIn}
                        AND h.EFFECTIVE_FROM <= '{$to}'
                        AND (h.EFFECTIVE_TO IS NULL OR h.EFFECTIVE_TO >= '{$from}')
                  )
              )
              {$excludeNonAgents}
        ";
    } else {
        $sql = "
            SELECT DISTINCT u.ID
            FROM b_user u
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = u.ID
               AND ud.FIELD_ID = 40
            WHERE u.ACTIVE = 'N'
              AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
              AND (
                  {$deptExpr}
                  OR u.ID IN (
                      SELECT h.USER_ID 
                      FROM b_agent_dept_history h 
                      WHERE h.DEPT_ID IN {$deptIn}
                  )
              )
              {$excludeNonAgents}
        ";
    }

    $rows = dbQuery($sql);

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

function formatUserJoiningDate($row)
{
    $custom = $row['UF_USR_1778656838068'] ?? '';
    $reg = $row['DATE_REGISTER'] ?? '';
    $raw = !empty($custom) ? $custom : $reg;
    if (empty($raw)) {
        return '';
    }

    $dt = parseReportDate($raw);
    if ($dt) {
        return $dt->format('Y-m-d');
    }

    $ts = strtotime($raw);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return '';
}

function parseReportDate($dateStr)
{
    if (empty($dateStr)) {
        return null;
    }

    if (is_object($dateStr)) {
        if (method_exists($dateStr, 'format')) {
            try {
                return new \DateTime($dateStr->format('Y-m-d H:i:s'));
            } catch (\Exception $e) {
                return null;
            }
        }
        if (method_exists($dateStr, 'getTimestamp')) {
            $ts = $dateStr->getTimestamp();
            if ($ts > 0) {
                $dt = new \DateTime();
                $dt->setTimestamp($ts);
                return $dt;
            }
        }
        $dateStr = (string)$dateStr;
    }

    $dateStr = trim((string)$dateStr);
    if ($dateStr === '' || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
        return null;
    }

    $formats = array(
        'Y-m-d H:i:s',
        'Y-m-d',
        'd.m.Y H:i:s',
        'd.m.Y h:i:s a',
        'd.m.Y h:i:s A',
        'd.m.Y',
        'd/m/Y H:i:s',
        'd/m/Y h:i:s a',
        'd/m/Y h:i:s A',
        'd/m/Y',
        'd-m-Y H:i:s',
        'd-m-Y',
    );

    foreach ($formats as $format) {
        $dt = \DateTime::createFromFormat($format, (strpos($format, 'a') !== false || strpos($format, 'A') !== false)
            ? strtolower($dateStr)
            : $dateStr);
        if ($dt instanceof \DateTime) {
            return $dt;
        }
    }

    // Try substring to 10 chars (date part only)
    $datePart = substr($dateStr, 0, 10);
    $datePartFormats = array('Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y');
    foreach ($datePartFormats as $format) {
        $dt = \DateTime::createFromFormat($format, $datePart);
        if ($dt instanceof \DateTime) {
            return $dt;
        }
    }

    try {
        return new \DateTime($dateStr);
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Convert a raw DB date field (which can be a string, null, or Bitrix\Main\Type\Date/DateTime object)
 * into a clean standard date string.
 *
 * @param mixed $val
 * @param string $format Target format, defaults to 'Y-m-d'
 * @return string
 */
function convertBitrixDateToString($val, $format = 'Y-m-d')
{
    if ($val === null) {
        return '';
    }
    if (is_object($val)) {
        if (method_exists($val, 'format')) {
            return (string)$val->format($format);
        }
        if (method_exists($val, 'getTimestamp')) {
            $ts = $val->getTimestamp();
            return $ts > 0 ? date($format, $ts) : '';
        }
        $val = (string)$val;
    }

    $val = trim((string)$val);
    if ($val === '' || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') {
        return '';
    }

    // Parse using our existing robust parseReportDate
    $dt = parseReportDate($val);
    if ($dt) {
        return $dt->format($format);
    }

    // Fallback to strtotime
    $ts = strtotime($val);
    if ($ts !== false && $ts > 0) {
        return date($format, $ts);
    }

    return $val;
}

function filterDealsByReportDateRange($deals, $dateRange, $primaryField = 'DATE_CREATE')
{
    $from = new \DateTime($dateRange['from']);
    $to   = new \DateTime($dateRange['to'] . ' 23:59:59');

    return array_values(array_filter($deals, function ($deal) use ($primaryField, $from, $to) {
        $dateStr = $deal['effective_create_date'] ?? ($deal[$primaryField] ?? ($deal['CLOSEDATE'] ?? ''));
        $dt = parseReportDate($dateStr);
        if (!$dt) {
            return false;
        }
        return $dt >= $from && $dt <= $to;
    }));
}

function getEffectiveDealCreateDateExpr($dealAlias = 'd', $utsAlias = 'uts')
{
    $f   = FIELD_IMPORTED_CREATE_DATE;
    $raw = "CAST({$utsAlias}.{$f} AS CHAR)";
    $sub = "SUBSTRING_INDEX(TRIM({$raw}), ' ', 1)";

    // Safely parse custom string formats in MySQL so DATE() doesn't return NULL
    $parsedImported = "CASE 
        WHEN {$sub} LIKE '%/%/%' THEN STR_TO_DATE({$sub}, '%d/%m/%Y')
        WHEN {$sub} LIKE '%.%.%' THEN STR_TO_DATE({$sub}, '%d.%m.%Y')
        WHEN {$sub} LIKE '%-%-%' AND {$sub} NOT LIKE '20%' THEN STR_TO_DATE({$sub}, '%d-%m-%Y')
        ELSE {$utsAlias}.{$f}
    END";

    return "COALESCE(
        CASE
            WHEN {$utsAlias}.{$f} IS NULL THEN NULL
            WHEN {$raw} IN ('', '0000-00-00', '0000-00-00 00:00:00') THEN NULL
            ELSE {$parsedImported}
        END,
        {$dealAlias}.DATE_CREATE
    )";
}

function getEffectiveDealCloseDateExpr($dealAlias = 'd', $utsAlias = 'uts')
{
    $importedCloseField = FIELD_IMPORTED_CLOSE_DATE;
    $importedCloseExpr = "CAST({$utsAlias}.{$importedCloseField} AS CHAR)";
    return "CASE
        WHEN {$utsAlias}.{$importedCloseField} IS NULL THEN {$dealAlias}.CLOSEDATE
        WHEN {$importedCloseExpr} IN ('', '0000-00-00') THEN {$dealAlias}.CLOSEDATE
        ELSE {$importedCloseExpr}
    END";
}

/**
 * Build SQL WHERE fragment to filter deals by company (using UF_CRM_1785767578527).
 * For 'eva': deal flag is 1 / 'Y' / 'true'.
 * For 'mira': deal flag is NULL / 0 / NOT '1' / NOT 'Y'.
 */
function getExcludeDealFilter($utsAlias = 'uts', $company = 'mira')
{
    $f = FIELD_EXCLUDE_DEAL;
    if ($company === COMPANY_EVA) {
        return "AND ({$utsAlias}.{$f} IS NOT NULL AND CAST({$utsAlias}.{$f} AS CHAR) IN ('1', 'Y', 'true', 'TRUE'))";
    }
    return "AND ({$utsAlias}.{$f} IS NULL OR CAST({$utsAlias}.{$f} AS CHAR) NOT IN ('1', 'Y', 'true', 'TRUE'))";
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
 * @param  string $company     'mira' | 'eva'
 * @return array
 */
function fetchWonDeals($agentIds, $dateRange, $dealType = 'All', $company = 'mira')
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
    $fImportedCreate = FIELD_IMPORTED_CREATE_DATE;
    $fImportedClose  = FIELD_IMPORTED_CLOSE_DATE;
    $effectiveCreateExpr = getEffectiveDealCreateDateExpr('d', 'uts');
    $effectiveCloseExpr  = getEffectiveDealCloseDateExpr('d', 'uts');

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $typeFilter = buildPropertyTypeFilter($dealType, 'uts');
    $excludeDealFilter = getExcludeDealFilter('uts', $company);

    return dbQuery("
        SELECT
            d.ID,
            d.ASSIGNED_BY_ID,
            d.SOURCE_ID             AS deal_source_id,
            d.DATE_CREATE,
            d.CLOSEDATE,
            uts.{$fImportedCreate}   AS imported_create_date,
            {$effectiveCreateExpr}   AS effective_create_date,
            uts.{$fImportedClose}    AS imported_close_date,
            {$effectiveCloseExpr}    AS effective_close_date,
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
          AND DATE({$effectiveCreateExpr}) >= '{$from}'
          AND DATE({$effectiveCreateExpr}) <= '{$to}'
          {$agentFilter}
          {$typeFilter}
          {$excludeDealFilter}

        ORDER BY {$effectiveCreateExpr} ASC
    ");
}

function fetchAllDeals($agentIds, $dateRange, $dealType = 'All', $company = 'mira')
{
    $catId    = dbInt(PIPELINE_TRANSACTION);
    $from     = dbEsc($dateRange['from']);
    $to       = dbEsc($dateRange['to']);

    $fAmount  = FIELD_DEAL_AMOUNT;
    $fComm    = FIELD_COMMISSION;
    $fDev     = FIELD_DEVELOPER;
    $fType    = FIELD_PROPERTY_TYPE;
    $fMgr     = FIELD_MANAGER_ID;
    $fImportedCreate = FIELD_IMPORTED_CREATE_DATE;
    $effectiveCreateExpr = getEffectiveDealCreateDateExpr('d', 'uts');
    $stages = inClauseStr($GLOBALS['CFG_ACTIVE_STAGES']);

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $typeFilter = buildPropertyTypeFilter($dealType, 'uts');
    $excludeDealFilter = getExcludeDealFilter('uts', $company);

    return dbQuery("
        SELECT
            d.ID,
            d.ASSIGNED_BY_ID,
            d.SOURCE_ID             AS deal_source_id,
            d.DATE_CREATE,
            d.CLOSEDATE,
            uts.{$fImportedCreate}   AS imported_create_date,
            {$effectiveCreateExpr}   AS effective_create_date,
            d.{$fAmount}            AS sale_amount,

            uts.{$fComm}            AS commission,
            uts.{$fDev}             AS developer_id,
            uts.{$fType}            AS property_type_id,
            uts.{$fMgr}             AS manager_id

        FROM b_crm_deal d

        LEFT JOIN b_uts_crm_deal uts 
            ON uts.VALUE_ID = d.ID

        WHERE d.CATEGORY_ID = {$catId}
          AND d.STAGE_ID IN {$stages}
          AND DATE({$effectiveCreateExpr}) >= '{$from}'
          AND DATE({$effectiveCreateExpr}) <= '{$to}'
          {$agentFilter}
          {$typeFilter}
          {$excludeDealFilter}

        AND d.STAGE_ID IN {$stages}

        ORDER BY {$effectiveCreateExpr} ASC
    ");
}

/**
 * Fetch all deals in the Transactions pipeline without report filters.
 * Scope is limited only by owner IDs when provided.
 */
function fetchTransactionPipelineDeals($agentIds = array(), $company = 'mira')
{
    $catId   = dbInt(PIPELINE_TRANSACTION);
    $fAmount = FIELD_DEAL_AMOUNT;
    $fComm   = FIELD_COMMISSION;
    $fDev    = FIELD_DEVELOPER;
    $fType   = FIELD_PROPERTY_TYPE;
    $fMgr    = FIELD_MANAGER_ID;
    $fImportedCreate = FIELD_IMPORTED_CREATE_DATE;
    $effectiveCreateExpr = getEffectiveDealCreateDateExpr('d', 'uts');
    $stages = inClauseStr($GLOBALS['CFG_ACTIVE_STAGES']);

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }
    $excludeDealFilter = getExcludeDealFilter('uts', $company);

    return dbQuery("
        SELECT
            d.ID,
            d.ASSIGNED_BY_ID,
            d.SOURCE_ID             AS deal_source_id,
            d.DATE_CREATE,
            d.CLOSEDATE,
            uts.{$fImportedCreate}   AS imported_create_date,
            {$effectiveCreateExpr}   AS effective_create_date,
            d.{$fAmount}            AS sale_amount,
            uts.{$fComm}            AS commission,
            uts.{$fDev}             AS developer_id,
            uts.{$fType}            AS property_type_id,
            uts.{$fMgr}             AS manager_id
        FROM b_crm_deal d
        LEFT JOIN b_uts_crm_deal uts
            ON uts.VALUE_ID = d.ID
        WHERE d.CATEGORY_ID = {$catId}
          AND d.STAGE_ID IN {$stages}
          {$agentFilter}
          {$excludeDealFilter}
        ORDER BY {$effectiveCreateExpr} ASC
    ");
}

/**
 * Fetch committed deals (all stages in pipeline 3 except WON and LOSE).
 */
function fetchCommittedDeals($agentIds, $dateRange, $dealType = 'All', $company = 'mira')
{
    $catId     = dbInt(PIPELINE_TRANSACTION);
    $from      = dbEsc($dateRange['from']);
    $to        = dbEsc($dateRange['to']);

    $stages = inClauseStr($GLOBALS['CFG_COMMITTED_STAGES']);

    $fAmount = FIELD_DEAL_AMOUNT;
    $fComm   = FIELD_COMMISSION;
    $fImportedCreate = FIELD_IMPORTED_CREATE_DATE;
    $effectiveCreateExpr = getEffectiveDealCreateDateExpr('d', 'uts');

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $typeFilter = buildPropertyTypeFilter($dealType, 'uts');
    $excludeDealFilter = getExcludeDealFilter('uts', $company);

    return dbQuery("
        SELECT
            d.ID,
            d.ASSIGNED_BY_ID,
            d.SOURCE_ID             AS deal_source_id,
            d.DATE_CREATE,
            uts.{$fImportedCreate}   AS imported_create_date,
            {$effectiveCreateExpr}   AS effective_create_date,
            d.{$fAmount}        AS sale_amount,
            uts.{$fComm}        AS commission

        FROM b_crm_deal d

        LEFT JOIN b_uts_crm_deal uts 
            ON uts.VALUE_ID = d.ID

        WHERE d.CATEGORY_ID = {$catId}
          AND d.STAGE_ID IN {$stages}
          AND DATE({$effectiveCreateExpr}) >= '{$from}'
          AND DATE({$effectiveCreateExpr}) <= '{$to}'
          {$agentFilter}
          {$typeFilter}
          {$excludeDealFilter}

        ORDER BY {$effectiveCreateExpr} ASC
    ");
}

/**
 * Build SQL WHERE fragment for property type filter.
 */
function buildPropertyTypeFilter($dealType, $alias = 'uts')
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

function getLeadPipelinesForDealType($dealType)
{
    if ($dealType === 'Offplan') {
        return array(PIPELINE_OFFPLAN);
    }
    if ($dealType === 'Secondary') {
        return array(PIPELINE_SECONDARY);
    }
    if ($dealType === 'Rental') {
        return array();
    }
    return array(PIPELINE_OFFPLAN, PIPELINE_SECONDARY);
}

function fetchLeadBreakdownRows($agentIds, $dateRange, $dealType = 'All', $company = 'mira')
{
    $pipelines = getLeadPipelinesForDealType($dealType);
    if (empty($pipelines)) {
        return array();
    }

    $catIn  = inClauseInt($pipelines);
    $from   = dbEsc($dateRange['from']);
    $to     = dbEsc($dateRange['to']);
    $source = FIELD_LEAD_SOURCE;
    $effectiveCreateExpr = getEffectiveDealCreateDateExpr('d', 'uts');

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }
    $excludeDealFilter = getExcludeDealFilter('uts', $company);

    return dbQuery("
        SELECT
            d.CATEGORY_ID,
            d.STAGE_ID,
            d.{$source} AS source_id,
            COUNT(*) AS cnt
        FROM b_crm_deal d
        LEFT JOIN b_uts_crm_deal uts ON uts.VALUE_ID = d.ID
        WHERE d.CATEGORY_ID IN {$catIn}
          AND DATE({$effectiveCreateExpr}) >= '{$from}'
          AND DATE({$effectiveCreateExpr}) <= '{$to}'
          {$agentFilter}
          {$excludeDealFilter}
        GROUP BY d.CATEGORY_ID, d.STAGE_ID, d.{$source}
    ");
}

function buildLeadStageBreakdown($rows, $pipelineIdFilter = null)
{
    $stageMap  = $GLOBALS['CFG_LEAD_STAGE_MAP'];
    $stageMeta = $GLOBALS['CFG_LEAD_STAGE_META'] ?? array();
    $grouped   = array();
    $total     = 0;

    foreach ($rows as $row) {
        $pipelineId = (int)($row['CATEGORY_ID'] ?? 0);
        if ($pipelineIdFilter !== null && $pipelineId !== (int)$pipelineIdFilter) {
            continue;
        }
        $stageId    = (string)($row['STAGE_ID'] ?? '');
        $count      = (int)($row['cnt'] ?? 0);
        $label      = $stageMap[$pipelineId][$stageId] ?? $stageId ?: 'Unknown';
        $meta       = $stageMeta[$pipelineId][$stageId] ?? array();
        $semantics  = $meta['semantics'] ?? null;
        $sort       = (int)($meta['sort'] ?? 9999);
        $color      = trim((string)($meta['color'] ?? ''));

        if (!isset($grouped[$label])) {
            $grouped[$label] = array(
                'count' => 0,
                'semantics' => $semantics,
                'sort' => $sort,
                'color' => $color,
            );
        }
        $grouped[$label]['count'] += $count;
        if ($sort < (int)$grouped[$label]['sort']) {
            $grouped[$label]['sort'] = $sort;
        }
        if ($grouped[$label]['color'] === '' && $color !== '') {
            $grouped[$label]['color'] = $color;
        }
        if ($grouped[$label]['semantics'] === null && $semantics !== null) {
            $grouped[$label]['semantics'] = $semantics;
        }
        $total += $count;
    }

    return formatLeadBreakdownItems($grouped, $total, true);
}

function buildLeadSourceBreakdown($rows, $pipelineIdFilter = null)
{
    $sourceMap = $GLOBALS['CFG_LEAD_SOURCE_MAP'];
    $grouped   = array();
    $total     = 0;

    foreach ($rows as $row) {
        $pipelineId = (int)($row['CATEGORY_ID'] ?? 0);
        if ($pipelineIdFilter !== null && $pipelineId !== (int)$pipelineIdFilter) {
            continue;
        }
        $sourceId = trim((string)($row['source_id'] ?? ''));
        $count    = (int)($row['cnt'] ?? 0);
        $label    = $sourceMap[$sourceId] ?? ($sourceId !== '' ? $sourceId : 'Unknown');

        if (!isset($grouped[$label])) {
            $grouped[$label] = 0;
        }
        $grouped[$label] += $count;
        $total += $count;
    }

    return formatLeadBreakdownItems($grouped, $total);
}

function buildDealClosureSourceBreakdown($deals, $propertyTypeIdFilter = null)
{
    $sourceMap = $GLOBALS['CFG_LEAD_SOURCE_MAP'];
    $grouped   = array();
    $total     = 0;

    foreach ($deals as $d) {
        $typeId = (int)($d['property_type_id'] ?? 0);
        if ($propertyTypeIdFilter !== null && $typeId !== (int)$propertyTypeIdFilter) {
            continue;
        }
        $sourceId = trim((string)($d['deal_source_id'] ?? ($d['SOURCE_ID'] ?? '')));
        $label    = $sourceMap[$sourceId] ?? ($sourceId !== '' ? $sourceId : 'Unknown');

        if (!isset($grouped[$label])) {
            $grouped[$label] = 0;
        }
        $grouped[$label]++;
        $total++;
    }

    return formatLeadBreakdownItems($grouped, $total);
}

function formatLeadBreakdownItems($grouped, $total, $preserveStageOrder = false)
{
    $items = array();
    foreach ($grouped as $label => $rawValue) {
        $count = is_array($rawValue) ? (int)($rawValue['count'] ?? 0) : (int)$rawValue;
        $items[] = array(
            'name'      => $label,
            'count'     => $count,
            'value'     => $total > 0 ? round(($count / $total) * 100, 2) : 0,
            'color'     => is_array($rawValue) ? trim((string)($rawValue['color'] ?? '')) : '',
            '_sort'     => is_array($rawValue) ? (int)($rawValue['sort'] ?? 9999) : 9999,
            '_semantic' => is_array($rawValue) ? ($rawValue['semantics'] ?? null) : null,
        );
    }

    if ($preserveStageOrder) {
        $semanticRank = array(
            null => 0,
            ''   => 0,
            'S'  => 1,
            'F'  => 2,
        );

        usort($items, function ($a, $b) use ($semanticRank) {
            $aRank = $semanticRank[$a['_semantic'] ?? null] ?? 3;
            $bRank = $semanticRank[$b['_semantic'] ?? null] ?? 3;

            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }
            if ($a['_sort'] !== $b['_sort']) {
                return $a['_sort'] <=> $b['_sort'];
            }
            return $b['count'] <=> $a['count'];
        });
    } else {
        usort($items, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
    }

    foreach ($items as &$item) {
        unset($item['_sort'], $item['_semantic']);
    }
    unset($item);

    return $items;
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
 * @param  int|null $pipeline
 * @param  string $company 'mira' | 'eva'
 * @return int
 */
function countActiveLeads($agentIds, $dateRange, $pipeline = null, $company = 'mira')
{
    if ($pipeline === PIPELINE_OFFPLAN) {
        $pipelines = array(PIPELINE_OFFPLAN);
        $excludeStages = array('C1:WON', 'C1:LOSE');
    } elseif ($pipeline === PIPELINE_SECONDARY) {
        $pipelines = array(PIPELINE_SECONDARY);
        $excludeStages = array('C2:WON', 'C2:LOSE');
    } else {
        $pipelines = array(PIPELINE_OFFPLAN, PIPELINE_SECONDARY);
        $excludeStages = array('C1:WON', 'C1:LOSE', 'C2:WON', 'C2:LOSE');
    }
    $in        = inClauseInt($pipelines);
    $from      = dbEsc($dateRange['from']);
    $to        = dbEsc($dateRange['to']);

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $excludeIn         = inClauseStr($excludeStages);
    $excludeDealFilter = getExcludeDealFilter('uts', $company);
    $effectiveCreateExpr = getEffectiveDealCreateDateExpr('d', 'uts');

    $row = dbQueryOne("
        SELECT COUNT(*) AS cnt
        FROM b_crm_deal d
        LEFT JOIN b_uts_crm_deal uts ON uts.VALUE_ID = d.ID
        WHERE d.CATEGORY_ID IN {$in}
          AND d.STAGE_ID NOT IN {$excludeIn}
          AND DATE({$effectiveCreateExpr}) >= '{$from}'
          AND DATE({$effectiveCreateExpr}) <= '{$to}'
          {$agentFilter}
          {$excludeDealFilter}
    ");
    return (int)($row['cnt'] ?? 0);
}

/**
 * Count automated reshuffles: leads taken AWAY from an agent by the
 * automated lead-distribution system (CREATED_BY_ID = 1), within the
 * date range.
 *
 * @param  array  $agentIds
 * @param  array  $dateRange
 * @param  string $company 'mira' | 'eva'
 * @return int
 */
function countReshuffledLeads($agentIds, $dateRange, $company = 'mira')
{
    $from      = dbEsc($dateRange['from']);
    $to        = dbEsc($dateRange['to']);

    if (empty($agentIds)) {
        return 0;
    }

    $inAgents = inClauseInt($agentIds);
    $excludeDealFilter = getExcludeDealFilter('uts', $company);

    $row = dbQueryOne("
        SELECT COUNT(*) AS cnt
        FROM bit_distribution_lead_assignment_log log
        INNER JOIN b_crm_deal d ON d.ID = log.DEAL_ID
        INNER JOIN b_uts_crm_deal uts ON uts.VALUE_ID = d.ID
        WHERE log.EVENT_TYPE = 'ASSIGNED'
          AND log.ASSIGNMENT_TYPE = 'REASSIGNED'
          AND log.CREATED_AT >= '{$from} 00:00:00'
          AND log.CREATED_AT <= '{$to} 23:59:59'
          AND log.TO_USER_ID IN {$inAgents}
          AND d.CATEGORY_ID = 1
          AND d.SOURCE_ID != '11'
          AND uts.UF_CRM_1766809458282 IS NOT NULL
          AND TRIM(uts.UF_CRM_1766809458282) != ''
          AND (uts.UF_CRM_1774601088414 IS NULL OR uts.UF_CRM_1774601088414 != 1)
          {$excludeDealFilter}
    ");

    return (int)($row['cnt'] ?? 0);
}

// ═══════════════════════════════════════════════════════════════════════════
// 6. LISTING QUERIES  (SPA 1052)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Count active listings (for rent vs for sale) for a set of branches.
 * Returns array('sale' => int, 'rent' => int)
 *
 * @param  array $branchCodes Empty = all configured branches
 * @return array
 */
function countActiveListingsByBranches($branchCodes = array())
{
    $table       = SPA_LISTINGS_TABLE;
    $stage       = dbEsc(LISTING_STAGE_ACTIVE);
    $typeField   = LISTING_TYPE_FIELD;
    $saleValue   = dbInt(LISTING_TYPE_SALE_VALUE);
    $branchField = LISTING_BRANCH_FIELD;

    if (empty($branchCodes)) {
        $branchCodes = getListingBranchCodesForDeptIds(array_keys($GLOBALS['CFG_LISTING_BRANCH_BY_DEPT'] ?? array()));
    }

    $branchFilter = '';
    if (!empty($branchCodes)) {
        $branchFilter = 'AND l.' . $branchField . ' IN ' . inClauseStr($branchCodes);
    }

    $rows = dbQuery("
        SELECT
            SUM(CASE WHEN l.{$typeField} = {$saleValue} THEN 1 ELSE 0 END) AS sale_count,
            SUM(CASE WHEN l.{$typeField} != {$saleValue} OR l.{$typeField} IS NULL THEN 1 ELSE 0 END) AS rent_count
        FROM {$table} l
        WHERE l.STAGE_ID = '{$stage}'
          {$branchFilter}
    ");

    $row = !empty($rows) ? $rows[0] : array();
    return array(
        'sale' => (int)($row['sale_count'] ?? 0),
        'rent' => (int)($row['rent_count'] ?? 0),
    );
}

/**
 * Count active listings for a set of branches.
 */
function countListingsByBranches($branchCodes = array())
{
    $counts = countActiveListingsByBranches($branchCodes);
    return (int)$counts['sale'] + (int)$counts['rent'];
}

/**
 * Count active listings for a set of users.
 */
function countListingsForUsers($userIds)
{
    if (empty($userIds)) {
        return 0;
    }
    if (!is_array($userIds)) {
        $userIds = array($userIds);
    }
    $table      = SPA_LISTINGS_TABLE;
    $stage      = dbEsc(LISTING_STAGE_ACTIVE);
    $inUsers    = inClauseInt($userIds);
    $ownerField = LISTING_OWNER_FIELD;

    $row = dbQueryOne("
        SELECT COUNT(*) AS cnt
        FROM {$table} l
        WHERE l.STAGE_ID = '{$stage}'
          AND l.{$ownerField} IN {$inUsers}
    ");
    return (int)($row['cnt'] ?? 0);
}

/**
 * Count active listings for the branches represented by a set of departments.
 */
function countListingsForDepartments($deptIds)
{
    $branchCodes = getListingBranchCodesForDeptIds($deptIds);
    if (!empty($branchCodes)) {
        return countListingsByBranches($branchCodes);
    }
    $userIds = getDeptUserIds($deptIds);
    return empty($userIds) ? 0 : countListingsForUsers($userIds);
}

/**
 * Count active listings split by sale/rent for a set of users.
 */
function countActiveListingsForUsers($userIds)
{
    if (empty($userIds)) {
        return array('sale' => 0, 'rent' => 0);
    }
    if (!is_array($userIds)) {
        $userIds = array($userIds);
    }
    $table      = SPA_LISTINGS_TABLE;
    $stage      = dbEsc(LISTING_STAGE_ACTIVE);
    $typeField  = LISTING_TYPE_FIELD;
    $saleValue  = dbInt(LISTING_TYPE_SALE_VALUE);
    $ownerField = LISTING_OWNER_FIELD;
    $inUsers    = inClauseInt($userIds);

    $rows = dbQuery("
        SELECT
            SUM(CASE WHEN l.{$typeField} = {$saleValue} THEN 1 ELSE 0 END) AS sale_count,
            SUM(CASE WHEN l.{$typeField} != {$saleValue} OR l.{$typeField} IS NULL THEN 1 ELSE 0 END) AS rent_count
        FROM {$table} l
        WHERE l.STAGE_ID = '{$stage}'
          AND l.{$ownerField} IN {$inUsers}
    ");

    $row = !empty($rows) ? $rows[0] : array();
    return array(
        'sale' => (int)($row['sale_count'] ?? 0),
        'rent' => (int)($row['rent_count'] ?? 0),
    );
}

/**
 * Count active listings split by sale/rent for the branches represented by a
 * set of departments.
 */
function countActiveListingsForDepartments($deptIds)
{
    $branchCodes = getListingBranchCodesForDeptIds($deptIds);
    if (!empty($branchCodes)) {
        return countActiveListingsByBranches($branchCodes);
    }
    $userIds = getDeptUserIds($deptIds);
    return empty($userIds) ? array('sale' => 0, 'rent' => 0) : countActiveListingsForUsers($userIds);
}

/**
 * Count pocket listings (for rent vs for sale) for a set of branches.
 * Returns array('sale' => int, 'rent' => int)
 *
 * @param  array $branchCodes Empty = all configured branches
 * @return array
 */
function countPocketListingsByBranches($branchCodes = array())
{
    $table       = SPA_LISTINGS_TABLE;
    $stage       = dbEsc(LISTING_STAGE_POCKET);
    $typeField   = LISTING_TYPE_FIELD;
    $saleValue   = dbInt(LISTING_TYPE_SALE_VALUE);
    $branchField = LISTING_BRANCH_FIELD;

    if (empty($branchCodes)) {
        $branchCodes = getListingBranchCodesForDeptIds(array_keys($GLOBALS['CFG_LISTING_BRANCH_BY_DEPT'] ?? array()));
    }

    $branchFilter = '';
    if (!empty($branchCodes)) {
        $branchFilter = 'AND l.' . $branchField . ' IN ' . inClauseStr($branchCodes);
    }

    $rows = dbQuery("
        SELECT
            SUM(CASE WHEN l.{$typeField} = {$saleValue} THEN 1 ELSE 0 END) AS sale_count,
            SUM(CASE WHEN l.{$typeField} != {$saleValue} OR l.{$typeField} IS NULL THEN 1 ELSE 0 END) AS rent_count
        FROM {$table} l
        WHERE l.STAGE_ID = '{$stage}'
          {$branchFilter}
    ");

    $row = !empty($rows) ? $rows[0] : array();
    return array(
        'sale' => (int)($row['sale_count'] ?? 0),
        'rent' => (int)($row['rent_count'] ?? 0),
    );
}

/**
 * Count pocket listings for a set of branches.
 */
function countPocketListingsByBranchesTotal($branchCodes = array())
{
    $counts = countPocketListingsByBranches($branchCodes);
    return (int)$counts['sale'] + (int)$counts['rent'];
}

/**
 * Count pocket listings split by sale/rent for the branches represented by a set of departments.
 */
function countPocketListingsForDepartments($deptIds)
{
    $branchCodes = getListingBranchCodesForDeptIds($deptIds);
    if (!empty($branchCodes)) {
        return countPocketListingsByBranches($branchCodes);
    }
    $userIds = getDeptUserIds($deptIds);
    return empty($userIds) ? array('sale' => 0, 'rent' => 0) : countPocketListingsForUsers($userIds);
}

/**
 * Count pocket listings total for the branches represented by a set of departments.
 */
function countPocketListingsForDepartmentsTotal($deptIds)
{
    $branchCodes = getListingBranchCodesForDeptIds($deptIds);
    if (!empty($branchCodes)) {
        return countPocketListingsByBranchesTotal($branchCodes);
    }
    $userIds = getDeptUserIds($deptIds);
    return empty($userIds) ? 0 : countPocketListingsForUsersTotal($userIds);
}

/**
 * Count pocket listings split by sale/rent for a set of users.
 */
function countPocketListingsForUsers($userIds)
{
    if (empty($userIds)) {
        return array('sale' => 0, 'rent' => 0);
    }
    if (!is_array($userIds)) {
        $userIds = array($userIds);
    }
    $table      = SPA_LISTINGS_TABLE;
    $stage      = dbEsc(LISTING_STAGE_POCKET);
    $typeField  = LISTING_TYPE_FIELD;
    $saleValue  = dbInt(LISTING_TYPE_SALE_VALUE);
    $ownerField = LISTING_OWNER_FIELD;
    $inUsers    = inClauseInt($userIds);

    $rows = dbQuery("
        SELECT
            SUM(CASE WHEN l.{$typeField} = {$saleValue} THEN 1 ELSE 0 END) AS sale_count,
            SUM(CASE WHEN l.{$typeField} != {$saleValue} OR l.{$typeField} IS NULL THEN 1 ELSE 0 END) AS rent_count
        FROM {$table} l
        WHERE l.STAGE_ID = '{$stage}'
          AND l.{$ownerField} IN {$inUsers}
    ");

    $row = !empty($rows) ? $rows[0] : array();
    return array(
        'sale' => (int)($row['sale_count'] ?? 0),
        'rent' => (int)($row['rent_count'] ?? 0),
    );
}

/**
 * Count pocket listings total for a set of users.
 */
function countPocketListingsForUsersTotal($userIds)
{
    if (empty($userIds)) {
        return 0;
    }
    if (!is_array($userIds)) {
        $userIds = array($userIds);
    }
    $table      = SPA_LISTINGS_TABLE;
    $stage      = dbEsc(LISTING_STAGE_POCKET);
    $inUsers    = inClauseInt($userIds);
    $ownerField = LISTING_OWNER_FIELD;

    $row = dbQueryOne("
        SELECT COUNT(*) AS cnt
        FROM {$table} l
        WHERE l.STAGE_ID = '{$stage}'
          AND l.{$ownerField} IN {$inUsers}
    ");
    return (int)($row['cnt'] ?? 0);
}

/**
 * Fetch pocket listing details for a set of branches, grouped by sale/rent.
 * Returns array('sale' => [...], 'rent' => [...])
 *
 * @param  array $branchCodes Empty = all configured branches
 * @return array
 */
function fetchPocketListingDetailsByBranches($branchCodes = array())
{
    $table      = SPA_LISTINGS_TABLE;
    $stage      = dbEsc(LISTING_STAGE_POCKET);
    $typeField  = LISTING_TYPE_FIELD;
    $saleValue  = dbInt(LISTING_TYPE_SALE_VALUE);
    $branchField = LISTING_BRANCH_FIELD;
    $refField   = LISTING_REF_FIELD;
    $ownerField = LISTING_OWNER_FIELD;

    if (empty($branchCodes)) {
        $branchCodes = getListingBranchCodesForDeptIds(array_keys($GLOBALS['CFG_LISTING_BRANCH_BY_DEPT'] ?? array()));
    }

    $branchFilter = '';
    if (!empty($branchCodes)) {
        $branchFilter = 'AND l.' . $branchField . ' IN ' . inClauseStr($branchCodes);
    }

    $rows = dbQuery("
        SELECT
            l.ID,
            l.{$typeField} AS listing_type,
            l.{$refField} AS reference_number,
            l.ASSIGNED_BY_ID,
            CONCAT(COALESCE(agent.NAME, ''), ' ', COALESCE(agent.LAST_NAME, '')) AS assigned_name,
            l.{$ownerField} AS owner_user_id,
            CONCAT(COALESCE(owner.NAME, ''), ' ', COALESCE(owner.LAST_NAME, '')) AS owner_name
        FROM {$table} l
        LEFT JOIN b_user agent
          ON agent.ID = l.ASSIGNED_BY_ID
        LEFT JOIN b_user owner
          ON owner.ID = l.{$ownerField}
        WHERE l.STAGE_ID = '{$stage}'
          {$branchFilter}
        ORDER BY l.ID DESC
    ");

    $grouped = array(
        'sale' => array(),
        'rent' => array(),
    );

    foreach ($rows as $row) {
        $typeKey = (int)($row['listing_type'] ?? 0) === LISTING_TYPE_SALE_VALUE ? 'sale' : 'rent';
        $id = (int)($row['ID'] ?? 0);
        $grouped[$typeKey][] = array(
            'id' => $id,
            'reference_number' => trim((string)($row['reference_number'] ?? '')),
            'listing_agent' => trim((string)($row['assigned_name'] ?? '')),
            'listing_owner' => trim((string)($row['owner_name'] ?? '')),
            'link' => $id > 0 ? 'https://crm.mira-international.com/crm/type/1052/details/' . $id . '/' : '',
        );
    }

    return $grouped;
}

/**
 * Fetch pocket listing details for a set of departments.
 */
function fetchPocketListingDetailsForDepartments($deptIds)
{
    $branchCodes = getListingBranchCodesForDeptIds($deptIds);
    if (!empty($branchCodes)) {
        return fetchPocketListingDetailsByBranches($branchCodes);
    }
    $userIds = getDeptUserIds($deptIds);
    return empty($userIds) ? array('sale' => array(), 'rent' => array()) : fetchPocketListingDetailsForUsers($userIds);
}

/**
 * Fetch pocket listing details for a set of users.
 */
function fetchPocketListingDetailsForUsers($userIds)
{
    if (empty($userIds)) {
        return array('sale' => array(), 'rent' => array());
    }
    if (!is_array($userIds)) {
        $userIds = array($userIds);
    }
    $table      = SPA_LISTINGS_TABLE;
    $stage      = dbEsc(LISTING_STAGE_POCKET);
    $typeField  = LISTING_TYPE_FIELD;
    $saleValue  = dbInt(LISTING_TYPE_SALE_VALUE);
    $refField   = LISTING_REF_FIELD;
    $ownerField = LISTING_OWNER_FIELD;
    $inUsers    = inClauseInt($userIds);

    $rows = dbQuery("
        SELECT
            l.ID,
            l.{$typeField} AS listing_type,
            l.{$refField} AS reference_number,
            l.ASSIGNED_BY_ID,
            CONCAT(COALESCE(agent.NAME, ''), ' ', COALESCE(agent.LAST_NAME, '')) AS assigned_name,
            l.{$ownerField} AS owner_user_id,
            CONCAT(COALESCE(owner.NAME, ''), ' ', COALESCE(owner.LAST_NAME, '')) AS owner_name
        FROM {$table} l
        LEFT JOIN b_user agent
          ON agent.ID = l.ASSIGNED_BY_ID
        LEFT JOIN b_user owner
          ON owner.ID = l.{$ownerField}
        WHERE l.STAGE_ID = '{$stage}'
          AND l.{$ownerField} IN {$inUsers}
        ORDER BY l.ID DESC
    ");

    $grouped = array(
        'sale' => array(),
        'rent' => array(),
    );

    foreach ($rows as $row) {
        $typeKey = (int)($row['listing_type'] ?? 0) === LISTING_TYPE_SALE_VALUE ? 'sale' : 'rent';
        $id = (int)($row['ID'] ?? 0);
        $grouped[$typeKey][] = array(
            'id' => $id,
            'reference_number' => trim((string)($row['reference_number'] ?? '')),
            'listing_agent' => trim((string)($row['assigned_name'] ?? '')),
            'listing_owner' => trim((string)($row['owner_name'] ?? '')),
            'link' => $id > 0 ? 'https://crm.mira-international.com/crm/type/1052/details/' . $id . '/' : '',
        );
    }

    return $grouped;
}


/**
 * Fetch active listing details for a set of branches, grouped by sale/rent.
 * Returns array('sale' => [...], 'rent' => [...])
 *
 * @param  array $branchCodes Empty = all configured branches
 * @return array
 */
function fetchActiveListingDetailsByBranches($branchCodes = array())
{
    $table      = SPA_LISTINGS_TABLE;
    $stage      = dbEsc(LISTING_STAGE_ACTIVE);
    $typeField  = LISTING_TYPE_FIELD;
    $saleValue  = dbInt(LISTING_TYPE_SALE_VALUE);
    $branchField = LISTING_BRANCH_FIELD;
    $refField   = LISTING_REF_FIELD;
    $ownerField = LISTING_OWNER_FIELD;

    if (empty($branchCodes)) {
        $branchCodes = getListingBranchCodesForDeptIds(array_keys($GLOBALS['CFG_LISTING_BRANCH_BY_DEPT'] ?? array()));
    }

    $branchFilter = '';
    if (!empty($branchCodes)) {
        $branchFilter = 'AND l.' . $branchField . ' IN ' . inClauseStr($branchCodes);
    }

    $rows = dbQuery("
        SELECT
            l.ID,
            l.{$typeField} AS listing_type,
            l.{$refField} AS reference_number,
            l.ASSIGNED_BY_ID,
            CONCAT(COALESCE(agent.NAME, ''), ' ', COALESCE(agent.LAST_NAME, '')) AS assigned_name,
            l.{$ownerField} AS owner_user_id,
            CONCAT(COALESCE(owner.NAME, ''), ' ', COALESCE(owner.LAST_NAME, '')) AS owner_name
        FROM {$table} l
        LEFT JOIN b_user agent
          ON agent.ID = l.ASSIGNED_BY_ID
        LEFT JOIN b_user owner
          ON owner.ID = l.{$ownerField}
        WHERE l.STAGE_ID = '{$stage}'
          {$branchFilter}
        ORDER BY l.ID DESC
    ");

    $grouped = array(
        'sale' => array(),
        'rent' => array(),
    );

    foreach ($rows as $row) {
        $typeKey = (int)($row['listing_type'] ?? 0) === LISTING_TYPE_SALE_VALUE ? 'sale' : 'rent';
        $id = (int)($row['ID'] ?? 0);
        $grouped[$typeKey][] = array(
            'id' => $id,
            'reference_number' => trim((string)($row['reference_number'] ?? '')),
            'listing_agent' => trim((string)($row['assigned_name'] ?? '')),
            'listing_owner' => trim((string)($row['owner_name'] ?? '')),
            'link' => $id > 0 ? 'https://crm.mira-international.com/crm/type/1052/details/' . $id . '/' : '',
        );
    }

    return $grouped;
}

/**
 * Fetch active listing details for a set of users.
 */
function fetchActiveListingDetailsForUsers($userIds)
{
    if (empty($userIds)) {
        return array('sale' => array(), 'rent' => array());
    }
    if (!is_array($userIds)) {
        $userIds = array($userIds);
    }
    $table      = SPA_LISTINGS_TABLE;
    $stage      = dbEsc(LISTING_STAGE_ACTIVE);
    $typeField  = LISTING_TYPE_FIELD;
    $saleValue  = dbInt(LISTING_TYPE_SALE_VALUE);
    $refField   = LISTING_REF_FIELD;
    $ownerField = LISTING_OWNER_FIELD;
    $inUsers    = inClauseInt($userIds);

    $rows = dbQuery("
        SELECT
            l.ID,
            l.{$typeField} AS listing_type,
            l.{$refField} AS reference_number,
            l.ASSIGNED_BY_ID,
            CONCAT(COALESCE(agent.NAME, ''), ' ', COALESCE(agent.LAST_NAME, '')) AS assigned_name,
            l.{$ownerField} AS owner_user_id,
            CONCAT(COALESCE(owner.NAME, ''), ' ', COALESCE(owner.LAST_NAME, '')) AS owner_name
        FROM {$table} l
        LEFT JOIN b_user agent
          ON agent.ID = l.ASSIGNED_BY_ID
        LEFT JOIN b_user owner
          ON owner.ID = l.{$ownerField}
        WHERE l.STAGE_ID = '{$stage}'
          AND l.{$ownerField} IN {$inUsers}
        ORDER BY l.ID DESC
    ");

    $grouped = array(
        'sale' => array(),
        'rent' => array(),
    );

    foreach ($rows as $row) {
        $typeKey = (int)($row['listing_type'] ?? 0) === LISTING_TYPE_SALE_VALUE ? 'sale' : 'rent';
        $id = (int)($row['ID'] ?? 0);
        $grouped[$typeKey][] = array(
            'id' => $id,
            'reference_number' => trim((string)($row['reference_number'] ?? '')),
            'listing_agent' => trim((string)($row['assigned_name'] ?? '')),
            'listing_owner' => trim((string)($row['owner_name'] ?? '')),
            'link' => $id > 0 ? 'https://crm.mira-international.com/crm/type/1052/details/' . $id . '/' : '',
        );
    }

    return $grouped;
}

/**
 * Fetch active listing details for the branches represented by a set of
 * departments.
 */
function fetchActiveListingDetailsForDepartments($deptIds)
{
    $branchCodes = getListingBranchCodesForDeptIds($deptIds);
    if (!empty($branchCodes)) {
        return fetchActiveListingDetailsByBranches($branchCodes);
    }
    $userIds = getDeptUserIds($deptIds);
    return empty($userIds) ? array('sale' => array(), 'rent' => array()) : fetchActiveListingDetailsForUsers($userIds);
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
function countAttendanceDays($userId, $dateRange, $scopeDeptId = 0)
{
    $table     = SPA_ATTENDANCE_TABLE;
    $typeField = ATTENDANCE_TYPE_FIELD;
    $typeIn    = dbEsc(ATTENDANCE_TYPE_IN);
    $uid       = dbInt($userId);
    $from      = dbEsc($dateRange['from']);
    $to        = dbEsc($dateRange['to']);

    $scopeJoin = '';
    $scopeFilter = '';
    if ($scopeDeptId > 0) {
        $scopeJoin = "
            LEFT JOIN b_utm_user ud
                ON ud.VALUE_ID = a.ASSIGNED_BY_ID
               AND ud.FIELD_ID = 40
            LEFT JOIN b_agent_dept_history h
                ON h.USER_ID = a.ASSIGNED_BY_ID
               AND DATE(a.CREATED_TIME) >= h.EFFECTIVE_FROM
               AND (h.EFFECTIVE_TO IS NULL OR DATE(a.CREATED_TIME) <= h.EFFECTIVE_TO)
        ";
        $scopeFilter = "
            AND (
                (h.DEPT_ID IS NOT NULL AND (
                    h.DEPT_ID = {$scopeDeptId}
                    OR (h.DEPT_ID = 23 AND EXISTS (
                        SELECT 1 FROM b_utm_user ud2 
                        WHERE ud2.VALUE_ID = a.ASSIGNED_BY_ID 
                          AND ud2.FIELD_ID = 40 
                          AND ud2.VALUE_INT = {$scopeDeptId}
                    ))
                ))
                OR (h.DEPT_ID IS NULL AND (
                    ud.VALUE_INT = {$scopeDeptId}
                    OR (
                        {$scopeDeptId} = 23
                        AND (SELECT TRIM(LOWER(WORK_POSITION)) FROM b_user WHERE ID = a.ASSIGNED_BY_ID) = 'private office'
                    )
                    OR (
                        ud.VALUE_INT = 23
                        AND EXISTS (
                            SELECT 1 FROM b_utm_user ud2 
                            WHERE ud2.VALUE_ID = a.ASSIGNED_BY_ID 
                              AND ud2.FIELD_ID = 40 
                              AND ud2.VALUE_INT = {$scopeDeptId}
                        )
                    )
                ))
            )
        ";
    }

    // Count distinct calendar days the agent punched in
    $row = dbQueryOne("
        SELECT COUNT(DISTINCT DATE(a.CREATED_TIME)) AS cnt
        FROM {$table} a
        {$scopeJoin}
        WHERE a.ASSIGNED_BY_ID = {$uid}
          AND a.{$typeField}   = '{$typeIn}'
          AND DATE(a.CREATED_TIME) >= '{$from}'
          AND DATE(a.CREATED_TIME) <= '{$to}'
          {$scopeFilter}
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
    $topDealId = 0;
    $topCommId = 0;

    foreach ($deals as $d) {
        $dealId     = (int)($d['ID'] ?? 0);
        $amount    = (float)($d['sale_amount'] ?? 0);
        $c         = (float)($d['commission']  ?? 0);
        $count++;
        $sales    += $amount;
        $comm     += $c;
        if ($amount > $topDeal) {
            $topDeal = $amount;
            $topDealId = $dealId;
        }
        if ($c > $topComm) {
            $topComm = $c;
            $topCommId = $dealId;
        }
    }

    return array(
        'deal_count'       => $count,
        'sales_volume'     => (int)$sales,
        'commissions'      => (int)$comm,
        'top_deal'         => (int)$topDeal,
        'top_deal_id'      => $topDealId,
        'top_commission'   => (int)$topComm,
        'top_commission_id' => $topCommId,
        'avg_sales_per_deal' => $count > 0 ? (int)round($sales / $count) : 0,
        'avg_commission_per_deal' => $count > 0 ? (int)round($comm / $count) : 0,
    );
}

/**
 * Aggregate commission-focused metrics from won + committed deal sets.
 * Keeps commission totals and top commission aligned to the same dataset.
 */
function aggregateCommissionDeals($wonDeals, $committedDeals = array())
{
    $operationalComm = 0.0;
    $committedComm   = 0.0;
    $topComm         = 0.0;
    $topCommId       = 0;
    $seenDealIds     = array();

    foreach ($wonDeals as $d) {
        $dealId = (int)($d['ID'] ?? 0);
        $c      = (float)($d['commission'] ?? 0);

        $operationalComm += $c;
        if ($c > $topComm) {
            $topComm   = $c;
            $topCommId = $dealId;
        }
        $seenDealIds[$dealId] = true;
    }

    foreach ($committedDeals as $d) {
        $dealId = (int)($d['ID'] ?? 0);
        $c      = (float)($d['commission'] ?? 0);

        $committedComm += $c;
        if (!isset($seenDealIds[$dealId]) && $c > $topComm) {
            $topComm   = $c;
            $topCommId = $dealId;
        }
    }

    $total = $operationalComm + $committedComm;

    return array(
        'total'                      => (int)$total,
        'committed_commission'       => (int)$committedComm,
        'committed_commission_pct'   => $total > 0 ? round(($committedComm / $total) * 100, 1) : 0,
        'operational_commission'     => (int)$operationalComm,
        'operational_commission_pct' => $total > 0 ? round(($operationalComm / $total) * 100, 1) : 0,
        'top_commission'             => (int)$topComm,
        'top_commission_id'          => $topCommId,
    );
}

/**
 * Calculate days since last transaction-pipeline deal for a set of agents.
 * Returns int (days) or 999 if no deals exist.
 */
function daysSinceLastDeal($agentIds, $company = 'mira')
{
    $catId = dbInt(PIPELINE_TRANSACTION);
    $importedCloseField  = FIELD_IMPORTED_CLOSE_DATE;
    $importedCreateField = FIELD_IMPORTED_CREATE_DATE;

    $agentFilter = '';
    if (!empty($agentIds)) {
        $agentFilter = 'AND d.ASSIGNED_BY_ID IN ' . inClauseInt($agentIds);
    }

    $excludeDealFilter = getExcludeDealFilter('uts', $company);

    $row = dbQueryOne("
        SELECT MAX(
            COALESCE(
                -- Prefer a real imported close date
                CASE
                    WHEN uts.{$importedCloseField} IS NULL THEN NULL
                    WHEN CAST(uts.{$importedCloseField} AS CHAR) IN ('', '0000-00-00') THEN NULL
                    ELSE CAST(uts.{$importedCloseField} AS CHAR)
                END,
                -- Then a real imported create date
                CASE
                    WHEN uts.{$importedCreateField} IS NULL THEN NULL
                    WHEN CAST(uts.{$importedCreateField} AS CHAR) IN ('', '0000-00-00') THEN NULL
                    ELSE CAST(uts.{$importedCreateField} AS CHAR)
                END,
                -- Fall back to native Bitrix dates
                d.CLOSEDATE,
                d.DATE_CREATE
            )
        ) AS last_date
        FROM b_crm_deal d
        LEFT JOIN b_uts_crm_deal uts
            ON uts.VALUE_ID = d.ID
        WHERE d.CATEGORY_ID = {$catId}
          {$agentFilter}
          {$excludeDealFilter}
    ");

    if (empty($row['last_date'])) {
        return 999;
    }

    try {
        $lastDate = new \DateTime($row['last_date']);
    } catch (\Exception $e) {
        $lastDate = \DateTime::createFromFormat('d/m/Y h:i:s a', strtolower($row['last_date']));
        if (!$lastDate) {
            return 999;
        }
    }

    return (int)(new \DateTime())->diff($lastDate)->days;
}

/**
 * Calculate average gap (days) between consecutive won deals for an agent.
 */
function avgGapBetweenDeals($agentId, $dateRange, $company = 'mira')
{
    $catId    = dbInt(PIPELINE_TRANSACTION);
    $stages   = inClauseStr($GLOBALS['CFG_ACTIVE_STAGES']);
    $uid      = dbInt($agentId);
    $from     = dbEsc($dateRange['from']);
    $to       = dbEsc($dateRange['to']);
    $effectiveCreateExpr = getEffectiveDealCreateDateExpr('d', 'uts');
    $excludeDealFilter   = getExcludeDealFilter('uts', $company);

    $rows = dbQuery("
        SELECT DATE({$effectiveCreateExpr}) AS booking_date
        FROM b_crm_deal d
        LEFT JOIN b_uts_crm_deal uts
            ON uts.VALUE_ID = d.ID
        WHERE d.CATEGORY_ID   = {$catId}
          AND d.STAGE_ID     IN {$stages}
          AND d.ASSIGNED_BY_ID = {$uid}
          AND DATE({$effectiveCreateExpr}) >= '{$from}'
          AND DATE({$effectiveCreateExpr}) <= '{$to}'
          {$excludeDealFilter}
        ORDER BY {$effectiveCreateExpr} ASC
    ");

    if (count($rows) < 2) {
        return 0;
    }

    $gaps  = array();
    $dates = array_values(array_filter(array_map(function ($r) {
        return parseReportDate($r['booking_date'] ?? '');
    }, $rows)));

    if (count($dates) < 2) {
        return 0;
    }

    for ($i = 1; $i < count($dates); $i++) {
        $gaps[] = (int)$dates[$i - 1]->diff($dates[$i])->days;
    }
    return (int)round(array_sum($gaps) / count($gaps));
}

/**
 * Calculate average gap (days) between consecutive won deals for each agent in a team,
 * and return the average of those averages.
 */
function avgGapBetweenDealsForTeam($agentIds, $dateRange, $company = 'mira')
{
    if (empty($agentIds)) {
        return 0;
    }
    $catId    = dbInt(PIPELINE_TRANSACTION);
    $stages   = inClauseStr($GLOBALS['CFG_ACTIVE_STAGES']);
    $inAgents = inClauseInt($agentIds);
    $from     = dbEsc($dateRange['from']);
    $to       = dbEsc($dateRange['to']);
    $effectiveCreateExpr = getEffectiveDealCreateDateExpr('d', 'uts');
    $excludeDealFilter   = getExcludeDealFilter('uts', $company);

    $rows = dbQuery("
        SELECT d.ASSIGNED_BY_ID, DATE({$effectiveCreateExpr}) AS booking_date
        FROM b_crm_deal d
        LEFT JOIN b_uts_crm_deal uts
            ON uts.VALUE_ID = d.ID
        WHERE d.CATEGORY_ID   = {$catId}
          AND d.STAGE_ID     IN {$stages}
          AND d.ASSIGNED_BY_ID IN {$inAgents}
          AND DATE({$effectiveCreateExpr}) >= '{$from}'
          AND DATE({$effectiveCreateExpr}) <= '{$to}'
          {$excludeDealFilter}
        ORDER BY d.ASSIGNED_BY_ID ASC, {$effectiveCreateExpr} ASC
    ");

    if (empty($rows)) {
        return 0;
    }

    // Group booking dates by agent
    $datesByAgent = array();
    foreach ($rows as $row) {
        $aid = (int)$row['ASSIGNED_BY_ID'];
        if (!isset($datesByAgent[$aid])) {
            $datesByAgent[$aid] = array();
        }
        $datesByAgent[$aid][] = $row['booking_date'];
    }

    $allAgentGaps = array();
    foreach ($datesByAgent as $aid => $bookingDates) {
        if (count($bookingDates) < 2) {
            continue;
        }
        $dates = array_values(array_filter(array_map(function ($dateStr) {
            return parseReportDate($dateStr ?? '');
        }, $bookingDates)));

        if (count($dates) < 2) {
            continue;
        }

        $gaps = array();
        for ($i = 1; $i < count($dates); $i++) {
            $gaps[] = (int)$dates[$i - 1]->diff($dates[$i])->days;
        }
        $allAgentGaps[] = array_sum($gaps) / count($gaps);
    }

    if (empty($allAgentGaps)) {
        return 0;
    }

    return (int)round(array_sum($allAgentGaps) / count($allAgentGaps));
}

/**
 * Count agents with no transaction-pipeline deal in last 60 days.
 *
 * @param  array $agentIds  All agent user IDs to check
 * @param  string $company  'mira' | 'eva'
 * @return int
 */
function countNoDealIn60Days($agentIds, $company = 'mira')
{
    if (empty($agentIds)) {
        return 0;
    }

    $nonAgentIds = getNonAgentUserIds();
    if (!empty($nonAgentIds)) {
        $nonAgentMap = array_flip(array_map('intval', $nonAgentIds));
        $agentIds = array_values(array_filter(array_map('intval', $agentIds), function ($id) use ($nonAgentMap) {
            return $id > 0 && !isset($nonAgentMap[$id]);
        }));
    }

    if (empty($agentIds)) {
        return 0;
    }

    // Fetch joining dates for these active agents to filter out new joiners (<= 60 days)
    $inAgents = inClauseInt($agentIds);
    $userRows = dbQuery("
        SELECT u.ID, u.DATE_REGISTER, uts_u.UF_USR_1778656838068
        FROM b_user u
        LEFT JOIN b_uts_user uts_u ON uts_u.VALUE_ID = u.ID
        WHERE u.ID IN {$inAgents}
          AND u.ACTIVE = 'Y'
          AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
    ");

    $cutoff60 = new \DateTime('-60 days');
    $cutoff60->setTime(0, 0, 0);

    $eligibleAgentIds = array();
    foreach ($userRows as $row) {
        $joiningDateStr = formatUserJoiningDate($row);
        if (empty($joiningDateStr)) {
            // Default to eligible if no join date is set
            $eligibleAgentIds[] = (int)$row['ID'];
            continue;
        }

        $joiningDate = null;
        $dt = parseReportDate($joiningDateStr);
        if ($dt) {
            $joiningDate = $dt;
        } else {
            $ts = strtotime($joiningDateStr);
            if ($ts !== false && $ts > 0) {
                $joiningDate = new \DateTime(date('Y-m-d', $ts));
            }
        }

        if ($joiningDate) {
            $joiningDate->setTime(0, 0, 0);
            if ($joiningDate < $cutoff60) {
                $eligibleAgentIds[] = (int)$row['ID'];
            }
        } else {
            $eligibleAgentIds[] = (int)$row['ID'];
        }
    }

    if (empty($eligibleAgentIds)) {
        return 0;
    }

    $catId    = dbInt(PIPELINE_TRANSACTION);
    $cutoff   = dbEsc(date('Y-m-d', strtotime('-60 days')));
    $inEligibleAgents = inClauseInt($eligibleAgentIds);
    $effectiveCreateExpr = getEffectiveDealCreateDateExpr('d', 'uts');
    $excludeDealFilter   = getExcludeDealFilter('uts', $company);

    // Agents who DO have a recent transaction-pipeline deal based on Booking Date
    $rows = dbQuery("
        SELECT DISTINCT ASSIGNED_BY_ID
        FROM b_crm_deal d
        LEFT JOIN b_uts_crm_deal uts
            ON uts.VALUE_ID = d.ID
        WHERE d.CATEGORY_ID    = {$catId}
          AND d.ASSIGNED_BY_ID IN {$inEligibleAgents}
          AND DATE({$effectiveCreateExpr}) >= '{$cutoff}'
          {$excludeDealFilter}
    ");

    $activeAgents = count($rows);
    return max(0, count($eligibleAgentIds) - $activeAgents);
}

// ═══════════════════════════════════════════════════════════════════════════
// 9. MONTHLY BREAKDOWN  (for charts)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Group won deals by month and return month-wise aggregation.
 * Returns array of 12 items (one per month) with:
 *   month, month_num, sales, commission, deals
 *
 * @param  array $deals     Raw deal rows from fetchAllDeals()
 * @param  int   $year      The year to build 12 months for
 * @return array
 */
function groupDealsByMonth($deals, $year)
{
    $monthMap = array();
    foreach ($deals as $d) {
        $dateStr = $d['effective_create_date'] ?? ($d['DATE_CREATE'] ?? ($d['CLOSEDATE'] ?? ''));
        $dt = parseReportDate($dateStr);
        if (!$dt) {
            continue;
        }

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
 * @param  int|array $monthlyTarget AED target per month
 * @return array
 */
function buildTargetVsActual($monthlyDeals, $monthlyTarget)
{
    $targetMap = is_array($monthlyTarget) ? $monthlyTarget : array();
    $result = array();
    foreach ($monthlyDeals as $m) {
        $target = is_array($monthlyTarget)
            ? (int)($targetMap[$m['month']] ?? 0)
            : (int)$monthlyTarget;
        $result[] = array(
            'month'  => $m['month'],
            'target' => $target,
            'actual' => (int)$m['commission'],
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

    // Remove Other if it has 0 deals for cleaner chart
    $result = array_filter($result, function ($r) {
        if ($r['name'] === 'Other' && $r['deals'] === 0) {
            return false;
        }
        return true;
    });

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
        $dateStr = $d['effective_create_date'] ?? ($d['DATE_CREATE'] ?? ($d['CLOSEDATE'] ?? ''));
        $dt = parseReportDate($dateStr);
        if (!$dt) {
            continue;
        }

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

    // Remove Other if it has 0 deals for cleaner chart
    $result = array_filter($result, function ($label) use ($result) {
        if ($label === 'Other') {
            $totalDeals = array_sum(array_map(function ($m) {
                return $m['deals'];
            }, $result[$label]));
            if ($totalDeals === 0) {
                return false;
            }
        }
        return true;
    }, ARRAY_FILTER_USE_KEY);

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
function getAgentTarget($userId, $workPosition, $company = 'mira')
{
    $uid     = (int)$userId;
    $targets = $GLOBALS['CFG_MONTHLY_TARGETS'];
    $companyTargets = $targets[$company] ?? $targets;

    if (isset($companyTargets['agents'][$uid])) {
        return (int)$companyTargets['agents'][$uid];
    }
    if (isset($targets['agents'][$uid])) {
        return (int)$targets['agents'][$uid];
    }
    $position = normalizeWorkPosition($workPosition);
    if (isset($GLOBALS['CFG_POSITION_TARGET'][$position])) {
        return (int)$GLOBALS['CFG_POSITION_TARGET'][$position];
    }
    return 0;
}

/**
 * Get the monthly target for a team (department).
 * Priority: team-specific → company default
 */
function getTeamTarget($deptId, $company = 'mira')
{
    $targets = $GLOBALS['CFG_MONTHLY_TARGETS'];
    $companyTargets = $targets[$company] ?? $targets;
    if (isset($companyTargets['teams'][(int)$deptId])) {
        return $companyTargets['teams'][(int)$deptId];
    }
    if (isset($targets['teams'][(int)$deptId])) {
        return $targets['teams'][(int)$deptId];
    }
    return $companyTargets['company'] ?? ($targets['company'] ?? 0);
}

/**
 * Get company-wide monthly target.
 */
function getCompanyTarget($company = 'mira')
{
    $targets = $GLOBALS['CFG_MONTHLY_TARGETS'];
    $companyTargets = $targets[$company] ?? $targets;
    return $companyTargets['company'] ?? ($targets['company'] ?? 0);
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
 * @param  string $dealType
 * @param  string $company
 * @return array
 */
function fetchYearSummary($year, $agentIds = array(), $dealType = 'All', $company = 'mira')
{
    $range = buildDateRange($year, 'All', 'All');
    $deals = fetchAllDeals($agentIds, $range, $dealType, $company);
    $agg   = aggregateDeals($deals);
    return array(
        'sales'      => $agg['sales_volume'],
        'commission' => $agg['commissions'],
        'deals'      => $agg['deal_count'],
        'agents'     => empty($agentIds) ? countAllActiveAgents($company) : count($agentIds),
        'avg_deal'   => $agg['avg_sales_per_deal'],
    );
}

/**
 * Count total active agents across all sales sub-departments.
 */
function countAllActiveAgents($company = 'mira')
{
    $allowedDeptIds = getSalesReportDepartmentIds(true, $company);
    if (empty($allowedDeptIds)) {
        return 0;
    }

    $nonAgentIds = getNonAgentUserIds();
    $excludeNonAgents = !empty($nonAgentIds)
        ? 'AND u.ID NOT IN ' . inClauseInt($nonAgentIds)
        : '';

    $poCond = ($company === COMPANY_MIRA) ? "OR (s.ID = 23 AND TRIM(LOWER(u.WORK_POSITION)) = 'private office')" : "";

    $row = dbQueryOne("
        SELECT COUNT(DISTINCT u.ID) AS cnt
        FROM b_user u

        LEFT JOIN b_utm_user ud
            ON ud.VALUE_ID = u.ID
           AND ud.FIELD_ID = 40

        JOIN b_iblock_section s 
            ON s.ID = ud.VALUE_INT
            {$poCond}

        WHERE u.ACTIVE = 'Y'
          AND (u.WORK_POSITION IS NULL OR (LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%pa liaison%' AND LOWER(TRIM(u.WORK_POSITION)) NOT LIKE '%listing admin%'))
          AND s.IBLOCK_ID = 3
          AND s.ID IN " . inClauseInt($allowedDeptIds) . "
          {$excludeNonAgents}
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
function buildCommissionSplit($wonDeals, $agentIds, $dateRange, $dealType, $company = 'mira')
{
    $committedDeals = fetchCommittedDeals($agentIds, $dateRange, $dealType, $company);
    return aggregateCommissionDeals($wonDeals, $committedDeals);
}

// ═══════════════════════════════════════════════════════════════════════════
// 15. MONTHLY DEAL BREAKDOWN FETCHER  (for year comparison)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Fetch 12-month breakdown for a full year (for year comparison chart).
 * Groups by month, returns sales/commission/deals per month.
 */
function fetchYearMonthly($year, $agentIds = array(), $dealType = 'All', $company = 'mira')
{
    $range = buildDateRange($year, 'All', 'All');
    $deals = fetchAllDeals($agentIds, $range, $dealType, $company);
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
function buildAgentPerformanceRow($userRow, $allDeals, $wonDeals, $committedDeals, $dateRange, $scopeDeptId = 0, $company = 'mira')
{
    $uid = (int)$userRow['ID'];
    $agg = aggregateDeals($allDeals);
    $commissionAgg = aggregateCommissionDeals($wonDeals, $committedDeals);

    $leadCountOffplan   = countActiveLeads(array($uid), $dateRange, PIPELINE_OFFPLAN, $company);
    $leadCountSecondary = countActiveLeads(array($uid), $dateRange, PIPELINE_SECONDARY, $company);
    $reshuffledCount = countReshuffledLeads(array($uid), $dateRange, $company);
    $listingCount    = countListingsForUsers(array($uid));
    $pocketListings  = countPocketListingsForUsers(array($uid));
    $pocketListingCount = (int)$pocketListings['sale'] + (int)$pocketListings['rent'];
    $lastDealDays    = daysSinceLastDeal(array($uid), $company);
    $avgGap          = avgGapBetweenDeals($uid, $dateRange, $company);
    $attendance      = countAttendanceDays($uid, $dateRange, $scopeDeptId);

    try {
        $start = new \DateTime($dateRange['from']);
        $end = new \DateTime($dateRange['to']);
        $attendanceTotal = $end->diff($start)->days + 1;
    } catch (\Exception $e) {
        $attendanceTotal = 30;
    }

    $designation = $userRow['WORK_POSITION'] ?? '';
    if ($company === COMPANY_MIRA && trim(strtolower($designation)) === 'private office') {
        static $teamCache = array();
        $origDeptId = getUserOriginalDeptId($uid, $company);
        if ($origDeptId > 0) {
            if (!isset($teamCache[$origDeptId])) {
                $teamRow = getSalesTeamById($origDeptId, $company);
                $teamCache[$origDeptId] = !empty($teamRow) ? getSalesTeamCode($teamRow, $company) : '';
            }
            $code = $teamCache[$origDeptId];
            if ($code !== '') {
                $designation .= ' (' . $code . ')';
            }
        }
    }

    $isTransferred = false;
    $transferredAt = '';
    if ($scopeDeptId > 0) {
        $teamDepts = filterAllowedSalesDepartmentIds(array($scopeDeptId), true, $company);
        $historyRow = dbQueryOne("
            SELECT EFFECTIVE_TO 
            FROM b_agent_dept_history 
            WHERE USER_ID = {$uid} 
              AND DEPT_ID IN " . inClauseInt($teamDepts) . "
              AND EFFECTIVE_TO IS NOT NULL
              AND EFFECTIVE_TO != '0000-00-00'
            ORDER BY EFFECTIVE_TO DESC 
            LIMIT 1
        ");
        if (!empty($historyRow) && !empty($historyRow['EFFECTIVE_TO'])) {
            $effectiveTo = convertBitrixDateToString($historyRow['EFFECTIVE_TO'], 'Y-m-d');
            if ($effectiveTo !== '') {
                $isTransferred = true;
                $transferredAt = date('d/m/Y', strtotime($effectiveTo . ' +1 day'));
            }
        }
    }

    $deptId = 0;
    if ($company === COMPANY_MIRA && $uid === 156) {
        $deptId = 26;
    } elseif ($company === COMPANY_MIRA && $uid === 168) {
        $deptId = 30;
    } elseif ($company === COMPANY_MIRA && trim(strtolower($userRow['WORK_POSITION'] ?? '')) === 'private office') {
        $deptId = 23;
    } else {
        $allowedDeptIds = getSalesReportDepartmentIds(true, $company);
        $row = dbQueryOne("
            SELECT VALUE_INT
            FROM b_utm_user
            WHERE VALUE_ID = {$uid}
              AND FIELD_ID = 40
              AND VALUE_INT IN " . inClauseInt($allowedDeptIds) . "
            LIMIT 1
        ");
        $deptId = (int)($row['VALUE_INT'] ?? 0);
    }

    $origDeptId = getUserOriginalDeptId($uid, $company);

    return array(
        'id'                     => $uid,
        'name'                   => fullName($userRow),
        'designation'            => $designation,
        'department_id'          => $deptId,
        'original_department_id' => $origDeptId,
        'leads_offplan'          => $leadCountOffplan,
        'leads_secondary'  => $leadCountSecondary,
        'reshuffled_leads' => $reshuffledCount,
        'listings'         => (int)$listingCount + (int)$pocketListingCount,
        'active_listings'  => $listingCount,
        'pocket_listings'  => $pocketListingCount,
        'total_listings'   => (int)$listingCount + (int)$pocketListingCount,
        'deals'            => $agg['deal_count'],
        'sales'            => $agg['sales_volume'],
        'commission'       => $commissionAgg['total'],
        'top_deal'         => $agg['top_deal'],
        'top_deal_id'      => $agg['top_deal_id'],
        'top_commission'   => $commissionAgg['top_commission'],
        'top_commission_id' => $commissionAgg['top_commission_id'],
        'avg_gap'          => $avgGap,
        'last_deal_days'   => $lastDealDays,
        'attendance'       => $attendance,
        'attendance_total' => $attendanceTotal,
        'is_transferred'   => $isTransferred,
        'transferred_at'   => $transferredAt,
        'is_dismissed'     => (($userRow['ACTIVE'] ?? 'Y') === 'N'),
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

/**
 * Resolve an agent's department ID at a specific date using b_agent_dept_history.
 * Falls back to the current department if no historical record is found or matches.
 */
function getAgentDeptAtDate($userId, $dateStr)
{
    static $historyCache = null;

    if ($historyCache === null) {
        $historyCache = array();
        // Fetch all history records
        $rows = dbQuery("SELECT USER_ID, DEPT_ID, EFFECTIVE_FROM, EFFECTIVE_TO FROM b_agent_dept_history ORDER BY EFFECTIVE_FROM ASC");
        foreach ($rows as $row) {
            $uid = (int)$row['USER_ID'];
            if (!isset($historyCache[$uid])) {
                $historyCache[$uid] = array();
            }
            $fromStr = convertBitrixDateToString($row['EFFECTIVE_FROM'], 'Y-m-d');
            $toStr   = convertBitrixDateToString($row['EFFECTIVE_TO'], 'Y-m-d') ?: '9999-12-31';
            $historyCache[$uid][] = array(
                'dept_id' => (int)$row['DEPT_ID'],
                'from'    => $fromStr,
                'to'      => $toStr
            );
        }
    }

    $uid = (int)$userId;
    if (empty($dateStr)) {
        return getUserDeptId($uid);
    }

    // Convert date string to YYYY-MM-DD
    $date = convertBitrixDateToString($dateStr, 'Y-m-d');
    if ($date === '') {
        return getUserDeptId($uid);
    }

    if (isset($historyCache[$uid])) {
        foreach ($historyCache[$uid] as $h) {
            if ($date >= $h['from'] && $date <= $h['to']) {
                return $h['dept_id'];
            }
        }
    }

    // Fallback to current department
    return getUserDeptId($uid);
}

/**
 * Resolve an agent's original department ID (excluding 23 and 3) at a specific date using b_agent_dept_history.
 * Falls back to their current original department if no historical record matches.
 */
function getAgentOriginalDeptAtDate($userId, $dateStr)
{
    static $origHistoryCache = null;

    if ($origHistoryCache === null) {
        $origHistoryCache = array();
        // Fetch all history records
        $rows = dbQuery("SELECT USER_ID, DEPT_ID, EFFECTIVE_FROM, EFFECTIVE_TO FROM b_agent_dept_history ORDER BY EFFECTIVE_FROM ASC");
        foreach ($rows as $row) {
            $deptId = (int)$row['DEPT_ID'];
            if ($deptId === 23 || $deptId === 3) {
                continue;
            }
            $uid = (int)$row['USER_ID'];
            if (!isset($origHistoryCache[$uid])) {
                $origHistoryCache[$uid] = array();
            }
            $fromStr = convertBitrixDateToString($row['EFFECTIVE_FROM'], 'Y-m-d');
            $toStr   = convertBitrixDateToString($row['EFFECTIVE_TO'], 'Y-m-d') ?: '9999-12-31';
            $origHistoryCache[$uid][] = array(
                'dept_id' => $deptId,
                'from'    => $fromStr,
                'to'      => $toStr
            );
        }
    }

    $uid = (int)$userId;
    if (!empty($dateStr)) {
        // Convert date string to YYYY-MM-DD
        $date = convertBitrixDateToString($dateStr, 'Y-m-d');
        if ($date !== '' && isset($origHistoryCache[$uid])) {
            foreach ($origHistoryCache[$uid] as $h) {
                if ($date >= $h['from'] && $date <= $h['to']) {
                    return $h['dept_id'];
                }
            }
        }
    }

    // Fallback to current original department
    return getUserOriginalDeptId($uid);
}

/**
 * Check if an agent belongs to a department at a specific date.
 * Handles Private Office double-grouping.
 */
function isAgentInDeptAtDate($userId, $deptId, $dateStr)
{
    if ((int)$deptId <= 0) {
        return true;
    }
    $resolvedDept = getAgentDeptAtDate($userId, $dateStr);
    if ($resolvedDept === (int)$deptId) {
        return true;
    }

    $origDept = getAgentOriginalDeptAtDate($userId, $dateStr);
    if ($origDept === (int)$deptId) {
        return true;
    }

    return false;
}

/**
 * Check if an agent currently belongs to a department.
 * Handles Private Office double-grouping.
 */
function isAgentInDept($userId, $deptId)
{
    if ((int)$deptId <= 0) {
        return true;
    }
    $currDept = getUserDeptId($userId);
    if ($currDept === (int)$deptId) {
        return true;
    }

    $origDept = getUserOriginalDeptId($userId);
    if ($origDept === (int)$deptId) {
        return true;
    }

    return false;
}