<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$connection = \Bitrix\Main\Application::getConnection();

$catId = PIPELINE_TRANSACTION;
$stages = inClauseStr($GLOBALS['CFG_ACTIVE_STAGES']);
$expr = getEffectiveDealCreateDateExpr('d', 'uts');

$sql = "
    SELECT 
        d.ID, 
        d.DATE_CREATE,
        uts.UF_CRM_1769420802242,
        {$expr} as EFFECTIVE_DATE,
        DATE({$expr}) as PARSED_DATE
    FROM b_crm_deal d
    LEFT JOIN b_uts_crm_deal uts ON uts.VALUE_ID = d.ID
    WHERE d.ID IN (16501, 16571)
";

$res = $connection->query($sql);
while ($row = $res->fetch()) {
    echo "Deal {$row['ID']}:\n";
    echo "  DATE_CREATE: {$row['DATE_CREATE']}\n";
    echo "  BOOKING_DATE (UF_CRM_...): {$row['UF_CRM_1769420802242']}\n";
    echo "  EFFECTIVE_DATE: {$row['EFFECTIVE_DATE']}\n";
    echo "  PARSED_DATE: {$row['PARSED_DATE']}\n\n";
}
