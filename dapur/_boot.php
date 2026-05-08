<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/unit_workflow.php';
$u = require_menu_access('inventori');
ensure_unit_workflow_schema();
$unitType = 'kitchen';
$units = unit_rows('kitchen');
$unitId = selected_unit_id('kitchen');
$unit = selected_unit_row($unitId);
if (!$unit) { $unitId = unit_first_id('kitchen'); $unit = selected_unit_row($unitId); }
$customCss = setting('custom_css','');
