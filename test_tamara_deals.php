<?php
/**
 * test_tamara_deals.php
 * Print all deals for Tamara Getigezheva (168) in the database.
 */
try {
    require_once __DIR__ . '/./config.php';
    require_once __DIR__ . '/./helpers.php';
    bx_boot();

    $uid = 168;
    $connection = \Bitrix\Main\Application::getConnection();

    // Query all deals for user 168
    $sql = "
        SELECT 
            d.ID, 
            d.STAGE_ID, 
            d.DATE_CREATE,
            uts.UF_CRM_1769420802242 AS imported_create_date,
            d.OPPORTUNITY AS sale_amount,
            uts.UF_CRM_1770280159 AS commission
        FROM b_crm_deal d
        LEFT JOIN b_uts_crm_deal uts ON uts.VALUE_ID = d.ID
        WHERE d.ASSIGNED_BY_ID = {$uid}
          AND d.CATEGORY_ID = 3
        ORDER BY d.DATE_CREATE DESC
    ";

    $res = $connection->query($sql);
    echo "Deals assigned to Tamara Getigezheva (168):\n";
    $count = 0;
    while ($row = $res->fetch()) {
        $count++;
        echo "- Deal ID: {$row['ID']}\n";
        echo "  STAGE_ID: {$row['STAGE_ID']}\n";
        echo "  DATE_CREATE: {$row['DATE_CREATE']}\n";
        echo "  Imported Create Date (UF_CRM_1769420802242): {$row['imported_create_date']}\n";
        echo "  Amount: AED " . number_format($row['sale_amount']) . "\n";
        echo "  Commission: AED " . number_format($row['commission']) . "\n\n";
    }
    echo "Total Deals Found: {$count}\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
