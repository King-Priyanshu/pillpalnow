<?php
$file = '/var/www/html/apps/wp-content/plugins/pillpalnow-core/includes/class-pillpalnow-form-handlers.php';
$content = file_get_contents($file);

// Replace returns in nonce checks
$content = preg_replace('/if \(!isset\(\$_POST\[\'([^\']+)\'\]\) \|\| !wp_verify_nonce\(\$_POST\[\'[^\']+\'\], \'[^\']+\'\)\) \{\s*return;\s*\}/', "if (!isset(\$_POST['$1']) || !wp_verify_nonce(\$_POST['$1'], '$1')) {\n            wp_die('Security check failed');\n        }", $content);
$content = str_replace("return;\n        }\n\n        if (!is_user_logged_in())", "wp_die('Security check failed');\n        }\n\n        if (!is_user_logged_in())", $content);

// Add else clauses for if ($post_id) ... exit; } -> if ($post_id) { ... exit; } else { wp_die('Failed to save data.', 'pillpalnow'); }
$content = preg_replace('/(\s+exit;\s*\n\s*\})(\n\s*\})/', "$1 else {\n            wp_die(__('Failed to save data. Please try again.', 'pillpalnow'));\n        }$2", $content);

file_put_contents($file, $content);
echo "Fix applied.";
