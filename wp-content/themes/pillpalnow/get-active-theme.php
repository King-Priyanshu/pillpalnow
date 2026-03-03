<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
echo "Active Theme: " . get_option('stylesheet') . "\n";
echo "Active Plugins: \n";
print_r(get_option('active_plugins'));
