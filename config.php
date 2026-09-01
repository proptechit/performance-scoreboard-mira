<?php

$_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__ . '/../../');

/**
 * config.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Single source of truth for ALL Bitrix IDs, field names, pipeline stages,
 * role definitions, targets, and department mappings.
 *
 * data.php and helpers.php NEVER hard-code IDs — they always reference this file.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ═══════════════════════════════════════════════════════════════════════════
// 1. PIPELINE / CATEGORY IDs
// ═══════════════════════════════════════════════════════════════════════════
define('PIPELINE_OFFPLAN',     1);   // Leads – Offplan pipeline
define('PIPELINE_SECONDARY',   2);   // Leads – Secondary pipeline
define('PIPELINE_TRANSACTION', 3);   // Transactions (Transactions) pipeline

// Convenience arrays
define('PIPELINES_LEADS', serialize(array(PIPELINE_OFFPLAN, PIPELINE_SECONDARY)));
// Usage: unserialize(PIPELINES_LEADS)  → [1, 2]

// ═══════════════════════════════════════════════════════════════════════════
// 2. DEAL STAGE IDs
// ═══════════════════════════════════════════════════════════════════════════

// Transaction pipeline (C3) stages
define('STAGE_WON',  'C3:WON');   // Approved by All  → Operational deal
define('STAGE_LOSE', 'C3:LOSE');  // Not Approved     → excluded from both

// Committed = all stages in pipeline 3 EXCEPT WON and LOSE
$GLOBALS['CFG_COMMITTED_STAGES'] = array(
    'C3:UC_831295', // Admin
    'C3:NEW',       // Manager Approval
    'C3:PREPARATION', // Marketing Approval
    'C3:PREPAYMENT_INVOICE', // Compliance Approval
    'C3:EXECUTING', // Finance Level 1
    'C3:UC_DVPTY4', // Finance Final
);

// Active = all stages in pipeline 3 EXCEPT Cancelled
$GLOBALS['CFG_ACTIVE_STAGES'] = array(
    'C3:UC_831295', // Admin
    'C3:NEW',       // Manager Approval
    'C3:PREPARATION', // Marketing Approval
    'C3:PREPAYMENT_INVOICE', // Compliance Approval
    'C3:EXECUTING', // Finance Level 1
    'C3:UC_DVPTY4', // Finance Final
    'C3:WON',       // Approved by All
    'C3:LOSE',      // Not Approved
);

// Lead pipelines – "active" stages (exclude WON/LOSE equivalents)
// Offplan pipeline (category 1)
$GLOBALS['CFG_LEAD_ACTIVE_STAGES_OFFPLAN'] = array(
    'C1:NEW',                // New Lead
    'C1:UC_PQEWDF',          // From Amo CRM
    'C1:UC_Y44ID0',          // Assigned (15 Min)
    'C1:UC_GT3BE1',          // No Answer (2 Days)
    'C1:UC_2OSPRW',          // Contacted (1 Day)
    'C1:UC_C55DKF',          // Qualified
    'C1:PREPAYMENT_INVOICE', // Option Sent (2 Days)
    'C1:UC_VU0VYQ',          // Meetings Scheduled
    'C1:EXECUTING',          // Cold (15 Days)
    'C1:FINAL_INVOICE',      // Warm (10 Days)
    'C1:UC_BHOWXH',          // Hot (5 Days)
    'C1:UC_EO8A5H',          // EOI Submitted
    'C1:UC_L64SIJ',          // Migrated from Eva
);
// Secondary pipeline (category 2)
$GLOBALS['CFG_LEAD_ACTIVE_STAGES_SECONDARY'] = array(
    'C2:NEW',                // New
    'C2:UC_2MP0F2',          // No Answer
    'C2:UC_TDEII1',          // Contacted
    'C2:PREPARATION',        // Qualified
    'C2:EXECUTING',          // Hot
    'C2:PREPAYMENT_INVOICE', // Cold
    'C2:UC_MDWPW1',          // Migrated from Eva
);

// Lead pipelines – "junk/lost" stages (to be excluded from reshuffled leads count)
$GLOBALS['CFG_LEAD_JUNK_STAGES_OFFPLAN'] = array(
    'C1:LOSE',               // Junk
    'C1:APOLOGY',            // Invalid Number
    'C1:UC_6CZF3Y',          // Realestate Brokers
    'C1:UC_FGP2M3',          // Secondary
    'C1:UC_NY1USR',          // Job Seekers
    'C1:UC_AR04OX',          // Never Respond
    'C1:UC_P2JQLK',          // Others
    'C1:2',                  // Not Interested Anymore
    'C1:3',                  // Already purchased
);

// ═══════════════════════════════════════════════════════════════════════════
// 3. CUSTOM FIELD NAMES  (b_crm_deal table columns)
// ═══════════════════════════════════════════════════════════════════════════
define('FIELD_DEAL_AMOUNT',       'OPPORTUNITY');              // Sale price (AED)
define('FIELD_COMMISSION',        'UF_CRM_1770280159');        // Gross commission
define('FIELD_DEVELOPER',         'UF_CRM_1773307643');        // Developer (enum)
define('FIELD_PROPERTY_TYPE',     'UF_CRM_1766811061237');     // Offplan/Secondary/Rental (enum)
define('FIELD_MANAGER_ID',        'UF_CRM_1766937679');        // Manager's Bitrix user ID
define('FIELD_REASSIGNMENT_CNT',  'UF_CRM_1770111873652');     // Lead assignment count (reshuffled if > 0)
define('FIELD_IMPORTED_CREATE_DATE', 'UF_CRM_1769420802242');  // Booking date / Imported original deal create date
define('FIELD_IMPORTED_CLOSE_DATE',  'UF_CRM_1775842990567');  // Imported/original deal close date
define('FIELD_LEAD_SOURCE',       'SOURCE_ID');                // Standard Bitrix lead/deal source field
define('FIELD_EXCLUDE_DEAL',      'UF_CRM_1785767578527');     // Excluded deals / Eva deal flag (1 = Eva, 0/null = Mira)

// Company identifiers
define('COMPANY_MIRA',            'mira');
define('COMPANY_EVA',             'eva');

// User profile company enumeration (UF_USR_1784813725054: 2206 = Mira, 2207 = Eva)
define('FIELD_COMPANY_USER',       'UF_USR_1784813725054');
define('COMPANY_USER_MIRA',        2206);
define('COMPANY_USER_EVA',         2207);

// Deal migration flag (UF_CRM_1785767578527: 1 / Y / true = Eva, 0 / NULL / false = Mira)
define('FIELD_EVA_DEAL',           'UF_CRM_1785767578527');

// ═══════════════════════════════════════════════════════════════════════════
// 4. PROPERTY TYPE ENUM VALUES  (UF_CRM_1766811061237)
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['CFG_PROPERTY_TYPE_MAP'] = array(
    76 => 'Offplan',
    75 => 'Secondary',
    77 => 'Rental',
);

// ═══════════════════════════════════════════════════════════════════════════
// 5. DEVELOPER ENUM VALUES  (UF_CRM_1773307643)
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['CFG_DEVELOPER_MAP'] = array(
    1640 => 'Reportage Properties',
    1641 => 'OKSA',
    1642 => 'Pantheon',
    1643 => 'Bloom Heights Properties',
    1644 => 'Arabian Gulf Properties',
    1645 => 'Marina Arcade',
    1646 => 'GINCO Properties',
    1647 => 'Dubai South Properties',
    1648 => 'Liv Lux',
    1649 => 'Scope Investment',
    1650 => 'A Y S Property Development',
    1651 => 'Iman Developers',
    1652 => 'Jumeirah Hills',
    1653 => 'TownX',
    1654 => 'Liv Marina',
    1655 => 'CITYWALK RESIDENTIAL',
    1656 => 'Kerzner',
    1657 => 'Swiss Property',
    1658 => 'Stell Maris',
    1659 => 'Ahad',
    1660 => 'Aqua',
    1661 => 'IGO',
    1662 => 'Al Hamra',
    1663 => 'Community',
    1664 => 'Condor',
    1665 => 'Elinghton',
    1666 => 'Sobha',
    1667 => 'Maryam Island',
    1668 => 'Samana',
    1669 => 'IMKAN',
    1670 => 'District One',
    1671 => 'Dubai Holding',
    1672 => 'Five',
    1673 => 'Triplanet',
    1674 => 'Aldar',
    1675 => 'MAG',
    1676 => 'Azizi',
    1677 => 'Prescott',
    1678 => 'Select Group',
    1679 => 'Binghatti',
    1680 => 'Damac',
    1681 => 'EMAAR',
    1682 => 'Nakheel',
    1683 => 'MERAAS / Dubai Properties',
    1684 => 'Aqua Properties',
    1685 => 'Omniyat',
    1686 => 'Seven Tides',
    1687 => 'Ellington',
    1688 => 'Nshama',
    1689 => 'Driven Properties',
    1690 => 'RAK',
    1691 => 'Other',
    1692 => 'DP',
    1693 => 'DAR AL ARKAN',
    1694 => 'Refine',
    1695 => 'IMTIAZ / Prescott',
    1696 => 'TIGER PROPERTIES',
    1697 => 'Deyaar',
    1698 => 'Danube',
    1699 => 'G&Co',
    1700 => 'Vincitore',
    1701 => 'Wasl',
    1702 => 'Seven City',
    1703 => 'One Scope Investment',
    1704 => 'Baraka',
    1705 => 'Al Habtoor',
    1706 => 'DURAR',
    1707 => 'Dubai Investments R.E',
    1708 => 'THOE REAL ESTATE',
    1709 => 'Ohana Development',
    1710 => 'Kleindienst Group',
    1711 => 'Ahs Properties',
    1712 => 'TIME PROPERTIES LLC',
    1713 => 'Marquis Signature',
    1714 => 'Abyaar Real Estate',
    1715 => 'OCTA Properties',
    1716 => 'Fakhruddin Properties',
    1717 => 'MIRA',
    1718 => 'Ithra Dubai',
    1719 => 'WOW Investments Limited',
    1720 => 'Major',
    1721 => 'Mr. Eight',
);

// ═══════════════════════════════════════════════════════════════════════════
// 6. LISTINGS SPA
// ═══════════════════════════════════════════════════════════════════════════
define('SPA_LISTINGS_ID',         1052);
define('SPA_LISTINGS_TABLE',      'b_crm_dynamic_items_1052');
define('LISTING_STAGE_ACTIVE',    'DT1052_11:SUCCESS');        // Published = active
define('LISTING_STAGE_POCKET',    'DT1052_11:UC_BDKHAU');      // Pocket listing stage
define('LISTING_TYPE_SALE_VALUE', 493);                        // UF_CRM_5_1752569908 = 493 → For Sale
define('LISTING_TYPE_FIELD',      'UF_CRM_5_1752569908');      // Listing type field
define('LISTING_BRANCH_FIELD',    'UF_CRM_7_1772708634');      // Branch code field
define('LISTING_REF_FIELD',       'UF_CRM_5_1752571265');      // Reference number field
define('LISTING_OWNER_FIELD',     'UF_CRM_5_1766132923');      // Listing owner user field

$GLOBALS['CFG_LISTING_BRANCH_BY_DEPT'] = array(
    22 => 'st1',
    31 => 'st2',
    26 => 'st3',
    21 => 'st4',
    32 => 'st5',
    23 => 'po',
    30 => 'main',
    61 => 'hs',
    3  => 'main',
);

// ═══════════════════════════════════════════════════════════════════════════
// 7. ATTENDANCE SPA
// ═══════════════════════════════════════════════════════════════════════════
define('SPA_ATTENDANCE_ID',    1060);
define('SPA_ATTENDANCE_TABLE', 'b_crm_dynamic_items_1060');
define('ATTENDANCE_TYPE_FIELD', 'UF_CRM_9_PUNCH_TYPE');  // 'IN' = present
define('ATTENDANCE_TYPE_IN',   'IN');

// ═══════════════════════════════════════════════════════════════════════════
// 8. DEPARTMENT STRUCTURE
//    Mira sales department ID = 3 (Parent=1)
//    Eva sales department ID = 36 (Parent=35)
// ═══════════════════════════════════════════════════════════════════════════
define('DEPT_SALES_ROOT_MIRA', 3);   // Mira sales root
define('DEPT_SALES_ROOT_EVA',  36);  // Eva sales root
define('DEPT_SALES_ROOT',      3);   // Default alias

// Mira Sales Departments & Teams
$GLOBALS['CFG_SALES_REPORT_DEPARTMENT_IDS_MIRA'] = array(
    3,   // Sales department (parent)
    22,  // Sales Team 1
    31,  // Sales Team 2
    26,  // Sales Team 3
    21,  // Sales Team 4
    32,  // Sales Team 5
    23,  // Private Office
    30,  // Tamara Getigezheva
    61,  // Homesence
);

$GLOBALS['CFG_SALES_TEAM_CODE_BY_DEPT_MIRA'] = array(
    22 => 'ST1',
    31 => 'ST2',
    26 => 'ST3',
    21 => 'ST4',
    32 => 'ST5',
    23 => 'PO',
    30 => 'TG',
    61 => 'HS',
);

$GLOBALS['CFG_SALES_TEAM_HEAD_BY_DEPT_MIRA'] = array(
    22 => 25,
    31 => 12,
    26 => 134,
    21 => 20,
    32 => 157,
    23 => 123,
    30 => 168,
    61 => 1208,
);

// Eva Sales Departments & Teams
$GLOBALS['CFG_SALES_REPORT_DEPARTMENT_IDS_EVA'] = array(
    36,  // Sales Department (parent)
    37,  // Off Plan Market Department (sub-department)
    38,  // Secondary Market Department (sub-department)
    41,  // Independent H Team
    42,  // Freelance Sales Team
    43,  // Scott's Team
    44,  // Mario's Team
    40,  // Wagdy Team
    39,  // Adilet Team
    45,  // Telesales admins
    46,  // Telesales
);

$GLOBALS['CFG_SALES_TEAM_CODE_BY_DEPT_EVA'] = array(
    37 => 'OP',
    38 => 'SM',
    41 => 'IH',
    42 => 'FT',
    43 => 'ST',
    44 => 'MT',
    40 => 'WT',
    39 => 'AT',
    45 => 'TSA',
    46 => 'TS',
);

$GLOBALS['CFG_SALES_TEAM_HEAD_BY_DEPT_EVA'] = array(
    41 => 581,  // Anastasiya Kouzan (Independent H Team)
    43 => 670,  // Scott McGeachy (Scott's Team)
    44 => 669,  // Mario Volpi (Mario's Team)
    40 => 619,  // Mohamed Wagdy (Wagdy Team)
    39 => 587,  // Adilet Abylgaziev (Adilet Team)
);

// Backward-compatibility references pointing to Mira
$GLOBALS['CFG_SALES_REPORT_DEPARTMENT_IDS'] = &$GLOBALS['CFG_SALES_REPORT_DEPARTMENT_IDS_MIRA'];
$GLOBALS['CFG_SALES_TEAM_CODE_BY_DEPT']      = &$GLOBALS['CFG_SALES_TEAM_CODE_BY_DEPT_MIRA'];
$GLOBALS['CFG_SALES_TEAM_HEAD_BY_DEPT']      = &$GLOBALS['CFG_SALES_TEAM_HEAD_BY_DEPT_MIRA'];

// ═══════════════════════════════════════════════════════════════════════════
// 9. ROLE DEFINITIONS
//    Map Bitrix user IDs to roles.
//    Everyone NOT listed here is treated as 'agent'.
//    Roles: 'ceo' | 'manager' | 'agent'
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['CFG_CEO_USER_IDS'] = array(
    1,     // Mira International (Admin)
    5,     // Kristina Boeva
    7,     // Abinas Subair
    123,   // Aldo De Jager
    168,   // TAMARA GETIGEZHEVA
    259,   // Nazira Raimkulova
    156,   // Jaymee Javin
    104,   // Bitrix Support
    38,    // Vladislav Tikholaz
    897,   // Elvira Sharshenalieva (CEO Eva)
);

$GLOBALS['CFG_MANAGER_USER_IDS_MIRA'] = array(
    25,   // STANISLAV MALTSEV (ST1)
    12,   // JULIA KRAVCHENKO (ST2)
    134,  // Moh'D Barakat (ST3)
    20,   // REZUAN SHOKUEV (ST4)
    157,  // Alex Jordan Devenport (ST5)
    123,  // Aldo De Jager (Private Office)
    1208, // Homesence Manager
);

$GLOBALS['CFG_MANAGER_USER_IDS_EVA'] = array(
    581,  // Anastasiya Kouzan (Independent H Team)
    670,  // Scott McGeachy (Scott's Team)
    669,  // Mario Volpi (Mario's Team)
    619,  // Mohamed Wagdy (Wagdy Team)
    587,  // Adilet Abylgaziev (Adilet Team)
);

$GLOBALS['CFG_MANAGER_USER_IDS'] = array_values(array_unique(array_merge(
    $GLOBALS['CFG_MANAGER_USER_IDS_MIRA'],
    $GLOBALS['CFG_MANAGER_USER_IDS_EVA']
)));

$GLOBALS['CFG_ALLOWED_AGENT_POSITIONS'] = array(
    'PC',
    'SPC',
    'PRIME',
    'POA',
    'Sales Manager'
);

$GLOBALS['CFG_EXCLUDED_USER_IDS'] = array(
    28,  // Daphne Varghese
    29,  // Ma. Betty De Leon
    30,  // Cindy Nacario
    31,  // Jezra Agao
    167, // Marimar Ordaniel
);

// ═══════════════════════════════════════════════════════════════════════════
// 10A. LEAD STAGE / SOURCE MAPPINGS
//      Dummy mappings for manager/agent lead charts.
//      Update labels or add/remove stage IDs later as needed.
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['CFG_LEAD_STAGE_MAP'] = array(
    PIPELINE_OFFPLAN => array(
        // Initial
        'C1:NEW'                => 'New Lead',
        'C1:UC_PQEWDF'          => 'From Amo CRM',

        // Assignment / early stages
        'C1:UC_Y44ID0'          => 'Assigned (15 Min)',
        'C1:UC_GT3BE1'          => 'No Answer (2 Days)',
        'C1:UC_2OSPRW'          => 'Contacted (1 Day)',
        'C1:UC_C55DKF'          => 'Qualified',

        // Mid funnel
        'C1:PREPAYMENT_INVOICE' => 'Option Sent (2 Days)',
        'C1:UC_VU0VYQ'          => 'Meetings Scheduled',

        // Lead temperature
        'C1:EXECUTING'          => 'Cold (15 Days)',
        'C1:FINAL_INVOICE'      => 'Warm (10 Days)',
        'C1:UC_BHOWXH'          => 'Hot (5 Days)',

        // Conversion / Eva Migration
        'C1:UC_EO8A5H'          => 'EOI Submitted',
        'C1:UC_L64SIJ'          => 'Migrated from Eva',

        // Final
        'C1:WON'                => 'Deal won',
        'C1:LOSE'               => 'Junk',

        // Lost reasons
        'C1:APOLOGY'            => 'Invalid Number',
        'C1:UC_6CZF3Y'          => 'Realestate Brokers',
        'C1:UC_FGP2M3'          => 'Secondary',
        'C1:UC_NY1USR'          => 'Job Seekers',
        'C1:UC_AR04OX'          => 'Never Respond',
        'C1:UC_P2JQLK'          => 'Others',
        'C1:2'                  => 'Not Intrested Anymore',
        'C1:3'                  => 'Already purchased',
    ),
    PIPELINE_SECONDARY => array(
        // Initial
        'C2:NEW'                => 'New',

        // Early stages
        'C2:UC_2MP0F2'          => 'No Answer',
        'C2:UC_TDEII1'          => 'Contacted',

        // Qualification
        'C2:PREPARATION'        => 'Qualified',

        // Lead status
        'C2:EXECUTING'          => 'Hot',
        'C2:PREPAYMENT_INVOICE' => 'Cold',
        'C2:UC_MDWPW1'          => 'Migrated from Eva',

        // Final
        'C2:WON'                => 'Deal won',
        'C2:LOSE'               => 'Deal lost',

        // Disqualified
        'C2:APOLOGY'            => 'Disqualified',
    ),
);

$GLOBALS['CFG_LEAD_STAGE_META'] = array(
    PIPELINE_OFFPLAN => array(
        'C1:NEW'                => array('semantics' => null, 'sort' => 10,  'color' => '#39a8ef'),
        'C1:UC_PQEWDF'          => array('semantics' => null, 'sort' => 11,  'color' => '#ace9fb'),
        'C1:UC_Y44ID0'          => array('semantics' => null, 'sort' => 20,  'color' => '#00adf2'),
        'C1:UC_GT3BE1'          => array('semantics' => null, 'sort' => 30,  'color' => '#f6989c'),
        'C1:UC_2OSPRW'          => array('semantics' => null, 'sort' => 35,  'color' => '#fca048'),
        'C1:UC_C55DKF'          => array('semantics' => null, 'sort' => 40,  'color' => '#2fc6f6'),
        'C1:PREPAYMENT_INVOICE' => array('semantics' => null, 'sort' => 50,  'color' => '#00ff00'),
        'C1:UC_VU0VYQ'          => array('semantics' => null, 'sort' => 60,  'color' => '#468ee5'),
        'C1:EXECUTING'          => array('semantics' => null, 'sort' => 70,  'color' => '#00a74c'),
        'C1:FINAL_INVOICE'      => array('semantics' => null, 'sort' => 80,  'color' => '#f99500'),
        'C1:UC_BHOWXH'          => array('semantics' => null, 'sort' => 90,  'color' => '#f11716'),
        'C1:UC_EO8A5H'          => array('semantics' => null, 'sort' => 91,  'color' => '#ace9fb'),
        'C1:UC_L64SIJ'          => array('semantics' => null, 'sort' => 95,  'color' => '#c9a84c'),
        'C1:WON'                => array('semantics' => 'S',  'sort' => 100, 'color' => '#7BD500'),
        'C1:LOSE'               => array('semantics' => 'F',  'sort' => 110, 'color' => '#ff5752'),
        'C1:APOLOGY'            => array('semantics' => 'F',  'sort' => 120, 'color' => '#ff5752'),
        'C1:UC_6CZF3Y'          => array('semantics' => 'F',  'sort' => 130, 'color' => '#ff5b55'),
        'C1:UC_FGP2M3'          => array('semantics' => 'F',  'sort' => 140, 'color' => '#ff5b55'),
        'C1:UC_NY1USR'          => array('semantics' => 'F',  'sort' => 150, 'color' => '#ff5b55'),
        'C1:UC_AR04OX'          => array('semantics' => 'F',  'sort' => 160, 'color' => '#ff5b55'),
        'C1:UC_P2JQLK'          => array('semantics' => 'F',  'sort' => 170, 'color' => '#ff5b55'),
        'C1:2'                  => array('semantics' => 'F',  'sort' => 180, 'color' => '#FF5752'),
        'C1:3'                  => array('semantics' => 'F',  'sort' => 190, 'color' => '#FF5752'),
    ),
    PIPELINE_SECONDARY => array(
        'C2:NEW'                => array('semantics' => null, 'sort' => 10, 'color' => '#39a8ef'),
        'C2:UC_2MP0F2'          => array('semantics' => null, 'sort' => 20, 'color' => '#8ed1a8'),
        'C2:UC_TDEII1'          => array('semantics' => null, 'sort' => 30, 'color' => '#ffa801'),
        'C2:PREPARATION'        => array('semantics' => null, 'sort' => 40, 'color' => '#2fc6f6'),
        'C2:EXECUTING'          => array('semantics' => null, 'sort' => 50, 'color' => '#0000ff'),
        'C2:PREPAYMENT_INVOICE' => array('semantics' => null, 'sort' => 60, 'color' => '#00ff00'),
        'C2:UC_MDWPW1'          => array('semantics' => null, 'sort' => 65, 'color' => '#c9a84c'),
        'C2:WON'                => array('semantics' => 'S',  'sort' => 70, 'color' => '#7BD500'),
        'C2:LOSE'               => array('semantics' => 'F',  'sort' => 80, 'color' => '#FF5752'),
        'C2:APOLOGY'            => array('semantics' => 'F',  'sort' => 90, 'color' => '#ff5752'),
    ),
);

$GLOBALS['CFG_LEAD_SOURCE_MAP'] = array(
    ''              => 'Unknown',

    // Direct / Telephony / System
    'CALL'          => 'Call',
    'EMAIL'         => 'E-Mail',
    'WEB'           => 'Website',
    'ADVERTISING'   => 'Advertising',
    'PARTNER'       => 'Existing Client',
    'RECOMMENDATION'=> 'By Recommendation',
    'TRADE_SHOW'    => 'Show/Exhibition',
    'WEBFORM'       => 'CRM form',
    'CALLBACK'      => 'Callback',
    'RC_GENERATOR'  => 'Sales boost',
    'STORE'         => 'Online Store',
    'BOOKING'       => 'Booking',
    'REPEAT_SALE'   => 'Facebook',
    'OTHER'         => 'Other',

    // WhatsApp Connectors
    '2|WZ_WHATSAPP_CA9E9686623946BD4B227F1B1DBC212B7' => 'WAZZUP: WhatsApp',
    '2|BITRIX_WHATCRM_NET_70680444'                    => 'WhatsApp Line #2',
    '3|BITRIX_WHATCRM_NET_70680444'                    => 'WhatsApp Line #3',

    // Numeric IDs (from Bitrix CRM status list)
    '1'             => 'Tilda',
    '2'             => 'Hubspot',
    '3'             => 'Google',
    '4'             => 'WhatsApp Marketing',
    '5'             => 'Yandex',
    '6'             => 'Snapchat',
    '7'             => 'TikTok',
    '8'             => 'Property Finder',
    '9'             => 'Bayut',
    '10'            => 'Dubizzle',
    '11'            => 'Amo CRM',
    '12'            => 'Manualy Created',
    '13'            => 'Instagram Elvira Direct',
    '14'            => 'Linkedin Direct',
    '15'            => 'Yahoo SEO',
    '16'            => 'Private',
    '17'            => 'Partnership',
    '18'            => 'SEO (Google, Yandex)',
    '19'            => 'Islands',
    '20'            => 'Referral',
    '21'            => 'Emirate Estates',
    '22'            => 'Elvira Lead',
    '23'            => 'Telegram EN',
    '24'            => 'Kiosk',
    '25'            => 'KZ branch',
    '26'            => 'X Red',
    '27'            => 'Bloggers',
    '28'            => 'LinkedIn_ADS',
    '29'            => 'Walk in',
    '30'            => 'Telegram_ADS',
    '31'            => 'Luxebridge',
    '32'            => 'In house G',
    '33'            => 'Instagram',
    '34'            => 'Google Ads',
    '35'            => 'TikTok - 5digital',
    '36'            => 'BigLab',
    '37'            => 'Database',
    '38'            => 'Marina Korchagina',
    '39'            => 'Yandex Ads',
    '40'            => 'PF Email',
    '41'            => 'PF Whatsapp',
    '42'            => 'PF Call',
    '43'            => 'Bayut Call',
    '44'            => 'Bayut Email',
    '45'            => 'Bayut Whatsapp',
    '46'            => 'Duibizle Call',
    '47'            => 'Duibizle Email',
    '48'            => 'Duibizle Whatsapp',
    '49'            => 'SF91',
    '50'            => 'Lyzhin Media',
    '51'            => 'Abdulkarim Personal',
    '52'            => 'Belarus callcenter',
    '53'            => 'Yandex SEO',
    '54'            => 'Bing SEO',
    '55'            => 'Yahoo SEO',
    '56'            => 'repavlovagency',
    '57'            => 'Premium Lead',
    '58'            => 'XMD',
    '59'            => 'TMC',
    '60'            => 'D GARRY',
    '61'            => 'G2',
    '62'            => 'PM (T)',
    '63'            => 'In House Referral',
    '64'            => 'Exclusive EW/VM',
    '65'            => 'Outdoor Leads',
    '66'            => 'Marquiz',
    '67'            => 'Wagdy Green List',
    '68'            => 'Adilet',
    '69'            => 'PC (T)',
    '70'            => 'LevLeads',
    '71'            => 'Binghatti developer leads',
    '72'            => 'AR In House',
    '73'            => 'Dimasik',
    '74'            => 'Eva Real Estate',
    '75'            => 'Marina Bach',
    '76'            => 'PF Projects Leads',
    '77'            => 'Bayut Projects Leads',
    '78'            => 'EVA -NABIL-buyers',
    '79'            => 'GHARR-new',
    '80'            => 'MIRA DB',
    '81'            => 'Anastasia Leads',
    '82'            => 'WhatsApp',
);

// ═══════════════════════════════════════════════════════════════════════════
// 10. MONTHLY TARGETS  (AED)
//     Structure:
//       'company'  => flat monthly target for the whole company
//       'teams'    => [ dept_id => monthly_target ]
//       'agents'   => [ bitrix_user_id => monthly_target ]
//
//     If an agent has no individual target, fall back to their team target.
//     If a team has no target, fall back to company default.
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['CFG_MONTHLY_TARGETS'] = array(

    'mira' => array(
        'company' => array(
            'Jan' => 4724901,
            'Feb' => 5915231,
            'Mar' => 6000000,
            'Apr' => 6500000,
            'May' => 7000000,
            'Jun' => 7500000,
            'Jul' => 7750000,
            'Aug' => 8000000,
            'Sep' => 9000000,
            'Oct' => 9500000,
            'Nov' => 10000000,
            'Dec' => 10500000,
        ),
        'teams' => array(),
        'agents' => array(),
    ),

    'eva' => array(
        'company' => array(
            'Jan' => 0,
            'Feb' => 0,
            'Mar' => 0,
            'Apr' => 0,
            'May' => 0,
            'Jun' => 0,
            'Jul' => 0,
            'Aug' => 0,
            'Sep' => 0,
            'Oct' => 0,
            'Nov' => 0,
            'Dec' => 0,
        ),
        'teams' => array(),
        'agents' => array(),
    ),

    // Legacy fallback structure
    'company' => array(
        'Jan' => 4724901,
        'Feb' => 5915231,
        'Mar' => 6000000,
        'Apr' => 6500000,
        'May' => 7000000,
        'Jun' => 7500000,
        'Jul' => 7750000,
        'Aug' => 8000000,
        'Sep' => 9000000,
        'Oct' => 9500000,
        'Nov' => 10000000,
        'Dec' => 10500000,
    ),

    'teams' => array(),
    'agents' => array(),

);

// ═══════════════════════════════════════════════════════════════════════════
// 11. DESIGNATION → TARGET TIER  (via WORK_POSITION)
//     If no individual target exists in CFG_MONTHLY_TARGETS['agents'],
//     use the agent's WORK_POSITION to pick a default target tier.
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['CFG_POSITION_TARGET'] = array(
    'PC'    => 85000, // Property Consultant
    'SPC'   => 125000, // Senior Property Consultant
    'PRIME' => 125000, // Prime
    'POA'   => 170000, // Private Office Advisor
    'Sales Manager' => 125000,
);

// ═══════════════════════════════════════════════════════════════════════════
// 12. CACHE SETTINGS
// ═══════════════════════════════════════════════════════════════════════════
define('CACHE_DIR',     __DIR__ . '/cache/');   // Cache folder (must be writable)
define('CACHE_TTL',     300);                    // Seconds – 5 minutes default
define('CACHE_ENABLED', true);                   // Set false to disable during dev
define('CACHE_VERSION', '2026-09-01-dismissed-date-v1');

// ═══════════════════════════════════════════════════════════════════════════
// 13. FILTER META  (returned to frontend for populating dropdowns)
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['CFG_FILTER_META'] = array(
    'years'      => array(2023, 2024, 2025, 2026),
    'quarters'   => array('Q1', 'Q2', 'Q3', 'Q4'),
    'months'     => array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'),
    'deal_types' => array('All', 'Offplan', 'Secondary', 'Rental'),
);

// ═══════════════════════════════════════════════════════════════════════════
// 14. BITRIX ROOT PATH  (for bootstrapping)
// ═══════════════════════════════════════════════════════════════════════════
// define('BX_ROOT', realpath($_SERVER['DOCUMENT_ROOT']));
