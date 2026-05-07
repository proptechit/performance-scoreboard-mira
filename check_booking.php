<?php
$_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__ . '/..');
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

if (file_exists($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php")) {
    require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
    $connection = \Bitrix\Main\Application::getConnection();
    $sql = "SELECT f.FIELD_NAME, l.EDIT_FORM_LABEL FROM b_user_field f LEFT JOIN b_user_field_lang l ON l.USER_FIELD_ID = f.ID WHERE f.ENTITY_ID = 'CRM_DEAL' AND (l.EDIT_FORM_LABEL LIKE '%Book%' OR f.FIELD_NAME LIKE '%BOOK%')";
    $res = $connection->query($sql);
    while ($row = $res->fetch()) {
        print_r($row);
    }
} else {
    echo "Prolog not found at: " . $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php\n";
}
