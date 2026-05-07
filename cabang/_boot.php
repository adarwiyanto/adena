<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/unit_workflow.php';
$u = require_menu_access('dashboard');
ensure_unit_workflow_schema();
$unitType = 'branch';
$units = unit_rows('branch');
$unitId = selected_unit_id('branch');
$unit = selected_unit_row($unitId);
if (!$unit) { $unitId = unit_first_id('branch'); $unit = selected_unit_row($unitId); }
$customCss = setting('custom_css','');
