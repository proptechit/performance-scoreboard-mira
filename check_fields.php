<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$res = dbQuery("
    SELECT f.FIELD_NAME, l.EDIT_FORM_LABEL
    FROM b_user_field f
    LEFT JOIN b_user_field_lang l ON l.USER_FIELD_ID = f.ID
    WHERE f.ENTITY_ID = 'CRM_DEAL' AND l.EDIT_FORM_LABEL LIKE '%Book%'
");
print_r($res);
