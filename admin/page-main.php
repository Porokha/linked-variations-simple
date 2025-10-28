<?php
if (!current_user_can('manage_woocommerce')) { wp_die('Not allowed'); }
$active = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
$conds = get_option(QMC_LVS_OPT_CONDITIONS, ['NEW','Used (A)']);
$logfile = plugin_dir_path(__FILE__).'../logs/debug.log';

function qmc_lvs_tabs($active){
    $tabs = ['settings'=>'Settings','bulk'=>'Bulk Smart Sync','tools'=>'Tools','logs'=>'Logs'];
    echo '<h1>Linked Variations</h1><h2 class="nav-tab-wrapper">';
    foreach($tabs as $slug=>$label){
        $cls = ($slug===$active)?' nav-tab nav-tab-active':' nav-tab';
        echo '<a class="'.$cls.'" href="'.esc_url(add_query_arg(['tab'=>$slug])).'">'.$label.'</a>';
    }
    echo '</h2>';
}
qmc_lvs_tabs($active);
?>

<?php if ($active==='settings'): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('qmc_lvs_save_settings'); ?>
        <input type="hidden" name="action" value="qmc_lvs_save_settings" />
        <table class="form-table" role="presentation">
            <tr>
                <th><label>Conditions</label></th>
                <td>
                    <textarea name="conditions" rows="3" cols="60"><?php echo esc_textarea(implode(', ', $conds)); ?></textarea>
                    <p class="description">Comma-separated, e.g. NEW, Used (A), Used (B).</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Save'); ?>
    </form>

<?php elseif ($active==='bulk'): ?>
    <?php $cats = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]); $next = isset($_GET['next_page']) ? (int)$_GET['next_page'] : 1; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
        <?php wp_nonce_field('qmc_lvs_bulk_sync'); ?>
        <input type="hidden" name="action" value="qmc_lvs_run_bulk_sync" />
        <input type="hidden" name="paged" value="<?php echo $next; ?>" />
        <p><b>Run for categories (optional):</b></p>
        <select name="qmc_lvs_cat_ids[]" multiple size="6" style="min-width:280px">
            <?php foreach($cats as $t): ?>
                <option value="<?php echo (int)$t->term_id; ?>" <?php if(isset($_GET['cats']) && in_array($t->term_id, array_map('intval', explode(',', $_GET['cats'])))) echo 'selected'; ?>>
                    <?php echo esc_html($t->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">Leave empty to scan all products. Existing meta will never be overwritten.</p>
        <?php submit_button($next>1?'Continue Next Batch':'Run Smart Sync'); ?>
    </form>
    <?php if (isset($_GET['updated_count'])): ?>
        <div class="notice notice-success"><p>
                Updated: <b><?php echo (int)$_GET['updated_count']; ?></b>,
                Skipped: <b><?php echo (int)$_GET['skipped_count']; ?></b>.
                <?php if (!empty($_GET['next_page'])): ?>
                    Next batch ready. Click the button again to continue.
                <?php endif; ?>
            </p></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('qmc_lvs_export_csv'); ?>
            <input type="hidden" name="action" value="qmc_lvs_export_csv" />
            <?php submit_button('Download Last Report (CSV)','secondary'); ?>
        </form>
    <?php endif; ?>

<?php elseif ($active==='tools'): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
        <?php wp_nonce_field('qmc_lvs_reset_meta'); ?>
        <input type="hidden" name="action" value="qmc_lvs_reset_meta" />
        <p><b>Reset plugin data</b></p>
        <label><input type="radio" name="reset_mode" value="soft" checked> Soft (safe)</label><br>
        <label><input type="radio" name="reset_mode" value="hard"> Hard (remove all Model/Storage/Color/Condition)</label><br><br>
        <?php submit_button('Run Reset','delete'); ?>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('qmc_lvs_clear_log'); ?>
        <input type="hidden" name="action" value="qmc_lvs_clear_log" />
        <?php submit_button('Clear Log','secondary'); ?>
    </form>

<?php elseif ($active==='logs'): ?>
    <pre style="background:#0b0b0b;color:#9cff9c;padding:12px;max-height:420px;overflow:auto;">
<?php $logfile = plugin_dir_path(__FILE__).'../logs/debug.log'; echo file_exists($logfile)? esc_html(file_get_contents($logfile)) : 'No logs yet...'; ?>
</pre>
<?php endif; ?>
