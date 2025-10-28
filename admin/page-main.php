<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$file = plugin_dir_path(__FILE__) . '../' . QMC_Linked_Variations_Simple::LOG_DIR . '/' . QMC_Linked_Variations_Simple::LOG_FILE;
echo '<div class="wrap"><h1>Linked Variations → Logs</h1>';
if ( file_exists($file) ) {
	if ( isset($_GET['qmc_lvs_clear_log']) ) { @unlink($file); echo '<div class="updated"><p>Cleared.</p></div>'; }
	echo '<p><a class="button" href="'.esc_url( add_query_arg(['qmc_lvs_clear_log'=>1]) ).'">Clear log</a></p>';
	echo '<pre style="background:#0b1220;color:#a7f3d0;padding:12px;border-radius:6px;max-height:60vh;overflow:auto;">'. esc_html(file_get_contents($file)) .'</pre>';
} else {
	echo '<p>No logs yet.</p>';
}
echo '</div>';
