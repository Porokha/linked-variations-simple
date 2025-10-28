<?php
if (!current_user_can('manage_woocommerce')) { wp_die('Not allowed'); }

$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
$conds = get_option(QMC_LVS_OPT_CONDITIONS, ['NEW','Used (A)']);
$logfile = plugin_dir_path(__FILE__).'../logs/debug.log';

function qmc_lvs_admin_tabs($active) {
    $tabs = [
            'settings' => 'Settings',
            'bulk'     => 'Bulk Smart Sync',
            'tools'    => 'Testing Tools',
            'logs'     => 'Logs',
    ];
    echo '<h1>Linked Variations</h1><h2 class="nav-tab-wrapper">';
    foreach($tabs as $slug=>$label){
        $class = ($slug===$active) ? ' nav-tab nav-tab-active' : '';
        echo '<a class="nav-tab'.$class.'" href="'.esc_url( add_query_arg(['tab'=>$slug]) ).'">'.$label.'</a>';
    }
    echo '</h2>';
}

qmc_lvs_admin_tabs($active_tab);

if ($active_tab==='settings'): ?>

    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <?php wp_nonce_field('qmc_lvs_save_settings'); ?>
        <input type="hidden" name="action" value="qmc_lvs_save_settings" />
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label>Conditions</label></th>
                <td>
                    <textarea name="conditions" rows="3" cols="60"><?php echo esc_textarea( implode(', ', $conds) ); ?></textarea>
                    <p class="description">Comma-separated. Example: NEW, Used (A), Used (B)</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Save'); ?>
    </form>

<?php elseif ($active_tab==='bulk'): ?>

    <p>Run a manual, non-destructive scan. It fills missing <b>Model</b>, <b>Storage</b> and <b>Color</b> by parsing the product slug. Existing meta values are never overwritten.</p>

    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-bottom:14px">
        <?php wp_nonce_field('qmc_lvs_bulk_sync'); ?>
        <input type="hidden" name="action" value="qmc_lvs_run_bulk_sync" />
        <input type="hidden" name="paged" value="<?php echo isset($_GET['next_page'])?(int)$_GET['next_page']:1; ?>" />

        <p><b>Limit to Categories (optional)</b></p>
        <?php
        // multi-select dropdown for product categories
        wp_dropdown_categories([
                'taxonomy'         => 'product_cat',
                'hide_empty'       => false,
                'name'             => 'product_cats[]',
                'orderby'          => 'name',
                'hierarchical'     => true,
                'show_option_all'  => '',
                'show_count'       => true,
                'walker'           => new Walker_CategoryDropdown(),
                'multiple'         => true,
                'class'            => 'regular-text',
        ]);
        ?>
        <p class="description">Leave empty to scan all products.</p>

        <?php submit_button( isset($_GET['next_page']) && (int)$_GET['next_page']>1 ? 'Continue Next Batch' : 'Run Smart Sync' ); ?>
    </form>

    <?php if (isset($_GET['updated_count'])): ?>
        <div class="notice notice-success"><p>
                Updated: <b><?php echo (int)$_GET['updated_count']; ?></b>,
                Skipped: <b><?php echo (int)$_GET['skipped_count']; ?></b>.
                <?php if (!empty($_GET['next_page'])): ?>
                    Next batch ready. Click the button again to continue.
                <?php endif; ?>
            </p></div>
    <?php endif; ?>

    <hr>

    <h3>Reset Tools</h3>

    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" onsubmit="return confirm('Delete Model/Storage/Color/Condition meta from ALL products? This cannot be undone. Continue?')">
        <?php wp_nonce_field('qmc_lvs_reset_meta'); ?>
        <input type="hidden" name="action" value="qmc_lvs_reset_meta" />
        <?php submit_button('Reset Variation Meta Only', 'delete'); ?>
    </form>

    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" onsubmit="return confirm('Reset and immediately re-sync from slugs for ALL products. Continue?')">
        <?php wp_nonce_field('qmc_lvs_reset_and_resync'); ?>
        <input type="hidden" name="action" value="qmc_lvs_reset_and_resync" />
        <?php submit_button('Reset & Re-Sync from Slugs', 'primary'); ?>
    </form>

    <?php if (!empty($_GET['reset_done'])): ?>
        <div class="notice notice-success"><p>
                Reset completed. Count: <b><?php echo (int)($_GET['reset_count'] ?? 0); ?></b>.
            </p></div>
    <?php endif; ?>

<?php elseif ($active_tab==='tools'): ?>

    <p>Test the slug parser without changing any data.</p>
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-bottom:16px">
        <?php wp_nonce_field('qmc_lvs_test_parse'); ?>
        <input type="hidden" name="action" value="qmc_lvs_test_parse" />
        <input type="text" name="slug" style="width:360px" placeholder="apple-iphone-16-pro-256gb-desert-titanium" required />
        <?php submit_button('Parse Slug', 'secondary', '', false); ?>
    </form>
    <?php if (isset($_GET['parsed'])):
        $parsed = json_decode( wp_unslash($_GET['parsed']), true ); ?>
        <table class="widefat striped" style="max-width:560px">
            <thead><tr><th>Field</th><th>Value</th></tr></thead>
            <tbody>
            <tr><td>Model</td><td><code><?php echo esc_html($parsed['model'] ?? ''); ?></code></td></tr>
            <tr><td>Storage</td><td><code><?php echo esc_html($parsed['storage'] ?? ''); ?></code></td></tr>
            <tr><td>Color</td><td><code><?php echo esc_html($parsed['color'] ?? ''); ?></code></td></tr>
            </tbody>
        </table>
    <?php endif; ?>

<?php elseif ($active_tab==='logs'): ?>

    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-bottom:10px">
        <?php wp_nonce_field('qmc_lvs_clear_log'); ?>
        <input type="hidden" name="action" value="qmc_lvs_clear_log" />
        <?php submit_button('Clear Log', 'delete'); ?>
    </form>
    <pre style="background:#0b0b0b;color:#9cff9c;padding:12px;max-height:380px;overflow:auto;">
<?php echo file_exists($logfile)? esc_html(file_get_contents($logfile)) : 'No logs yet...'; ?>
  </pre>

<?php endif; ?>
