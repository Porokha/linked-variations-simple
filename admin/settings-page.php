<?php if (!current_user_can('manage_woocommerce')) return;

$conds = get_option(QMC_Linked_Variations_Simple::OPT_CONDITIONS);
$logfile = plugin_dir_path(__FILE__).'../logs/debug.log';
?>

<div class="wrap">
	<h1>Linked Variations Settings</h1>

	<form method="post" action="options.php">
		<?php settings_fields('general'); ?>
		<textarea name="qmc_lvs_conditions" rows="3" cols="40"><?php echo esc_textarea(implode(', ', $conds)); ?></textarea>
		<?php submit_button(); ?>
	</form>

	<h2>Debug Log</h2>
	<pre style="background:#111;color:#0f0;padding:12px;max-height:300px;overflow:auto;">
<?php echo file_exists($logfile)? esc_html(file_get_contents($logfile)) : 'No logs yet...'; ?>
    </pre>
</div>
