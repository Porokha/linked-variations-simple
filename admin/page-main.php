<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$file = plugin_dir_path(__FILE__) . '../' . QMC_Linked_Variations_Simple::LOG_DIR . '/' . QMC_Linked_Variations_Simple::LOG_FILE;
echo '<div class="wrap"><h1>Linked Variations → Logs</h1>';
echo '<p>This page shows recent events (AJAX switches, bulk sync results). The log file is inside the plugin folder.</p>';
if ( file_exists($file) ) {
	echo '<p><a class="button" href="'.esc_url( add_query_arg(['qmc_lvs_clear_log'=>1]) ).'">Clear log</a></p>';
	if ( isset($_GET['qmc_lvs_clear_log']) ) { @unlink($file); echo '<div class="updated"><p>Cleared.</p></div>'; }
	echo '<pre style="background:#111;color:#0f0;padding:12px;border-radius:6px;max-height:60vh;overflow:auto;">'. esc_html(file_get_contents($file)) .'</pre>';
} else {
	echo '<p>No logs yet.</p>';
}
echo '</div>';
