<?php

/*
 * Plugin Name: Linked Variations for Simple Products
 * Plugin URI: https://Gstore.ge
 * Description: Apple-style selectors for Color, Storage, and Condition that link between separate simple products.
 * Version: 1.0.6
 * Author: Porokha
 * Author URI: https://Gstore.ge
 * License: GPL2
 */

// ===== GitHub Release Updater =====
add_filter('pre_set_site_transient_update_plugins', function($transient){
	if (empty($transient->checked)) return $transient;

	$plugin = plugin_basename(__FILE__);
	$current = $transient->checked[$plugin] ?? null;

	$api = 'https://api.github.com/repos/Porokha/linked-variations-simple/releases/latest';
	$args = ['timeout'=>12,'headers'=>['Accept'=>'application/vnd.github+json','User-Agent'=>'WordPress/Updater']];
	// If private repo and you set QMC_GITHUB_TOKEN, add header:
	if (defined('QMC_GITHUB_TOKEN') && QMC_GITHUB_TOKEN) {
		$args['headers']['Authorization'] = 'Bearer '.QMC_GITHUB_TOKEN;
	}

	$res = wp_remote_get($api, $args);
	if (is_wp_error($res) || wp_remote_retrieve_response_code($res) != 200) return $transient;

	$body = json_decode(wp_remote_retrieve_body($res), true);
	if (!$body || empty($body['tag_name'])) return $transient;

	$new = ltrim($body['tag_name'], 'v');
	// find the uploaded ZIP asset
	$zip = '';
	if (!empty($body['assets'])) {
		foreach ($body['assets'] as $a) {
			if (!empty($a['browser_download_url']) && str_ends_with($a['name'] ?? '', '.zip')) {
				$zip = $a['browser_download_url']; break;
			}
		}
	}
	// fallback to source zipball if no asset
	if (!$zip && !empty($body['zipball_url'])) $zip = $body['zipball_url'];

	if ($zip && $current && version_compare($current, $new, '<')) {
		$transient->response[$plugin] = (object)[
			'slug'        => dirname($plugin),
			'plugin'      => $plugin,
			'new_version' => $new,
			'package'     => $zip,   // WP will download this
			'tested'      => get_bloginfo('version'),
			'url'         => 'https://github.com/Porokha/linked-variations-simple',
		];
	}
	return $transient;
});

// Provide plugin info modal
add_filter('plugins_api', function($res,$action,$args){
	if ($action !== 'plugin_information') return $res;
	if ($args->slug !== dirname(plugin_basename(__FILE__))) return $res;

	$api = 'https://api.github.com/repos/Porokha/linked-variations-simple/releases/latest';
	$args_http = ['timeout'=>12,'headers'=>['Accept'=>'application/vnd.github+json','User-Agent'=>'WordPress/Updater']];
	if (defined('QMC_GITHUB_TOKEN') && QMC_GITHUB_TOKEN) {
		$args_http['headers']['Authorization'] = 'Bearer '.QMC_GITHUB_TOKEN;
	}
	$r = wp_remote_get($api, $args_http);
	if (is_wp_error($r) || wp_remote_retrieve_response_code($r) != 200) return $res;
	$b = json_decode(wp_remote_retrieve_body($r), true);

	$zip = '';
	if (!empty($b['assets'])) {
		foreach ($b['assets'] as $a) {
			if (!empty($a['browser_download_url']) && str_ends_with($a['name'] ?? '', '.zip')) {
				$zip = $a['browser_download_url']; break;
			}
		}
	}
	if (!$zip && !empty($b['zipball_url'])) $zip = $b['zipball_url'];

	return (object)[
		'name' => 'Linked Variations for Simple Products',
		'slug' => dirname(plugin_basename(__FILE__)),
		'version' => ltrim($b['tag_name'] ?? '', 'v'),
		'download_link' => $zip,
		'sections' => [
			'description' => 'Auto-updating plugin from GitHub Releases. Simple-products “linked variations” with Apple-style selectors.'
		]
	];
}, 10, 3);

// If private repo: add Authorization header when WP actually downloads the package
add_filter('http_request_args', function($args, $url){
	if (defined('QMC_GITHUB_TOKEN') && QMC_GITHUB_TOKEN) {
		if (strpos($url, 'github.com') !== false || strpos($url, 'api.github.com') !== false) {
			$args['headers']['Authorization'] = 'Bearer '.QMC_GITHUB_TOKEN;
			$args['headers']['User-Agent'] = 'WordPress/Updater';
			$args['headers']['Accept'] = 'application/octet-stream';
		}
	}
	return $args;
}, 10, 2);


if ( ! defined( 'ABSPATH' ) ) exit;

class QMC_Linked_Variations_Simple {
	const META_MODEL   = '_qmc_model_slug';
	const META_STORAGE = '_qmc_storage';
	const META_COLOR   = '_qmc_color';
	const META_COND    = '_qmc_condition';

	const OPT_CONDITIONS = 'qmc_lvs_conditions'; // array of condition labels e.g. ["NEW","Used (A)"]

	public function __construct() {
		// Defaults
		add_action( 'admin_init', [$this, 'maybe_set_default_conditions'] );

		// Admin UI
		add_action( 'add_meta_boxes', [$this, 'add_meta_box'] );
		add_action( 'save_post_product', [$this, 'save_product_meta'] );

		// Settings page
		add_action( 'admin_menu', [$this, 'add_settings_page'] );
		add_action( 'admin_post_qmc_lvs_save_settings', [$this, 'save_settings'] );

		// Frontend render under short description (priority > 20)
		add_action( 'woocommerce_single_product_summary', [$this, 'render_selectors'], 25 );

		// Assets
		add_action( 'wp_enqueue_scripts', [$this, 'enqueue_assets'] );
	}

	public function maybe_set_default_conditions() {
		$val = get_option(self::OPT_CONDITIONS);
		if ( empty($val) || !is_array($val) ) {
			update_option(self::OPT_CONDITIONS, ['NEW', 'Used (A)']);
		}
	}

	/* ============================
	 * Admin: Meta Box
	 * ============================ */
	public function add_meta_box() {
		add_meta_box(
			'qmc_lvs_meta',
			'Linked Variations (Simple Products)',
			[$this, 'meta_box_html'],
			'product',
			'side',
			'default'
		);
	}

	public function meta_box_html($post) {
		wp_nonce_field( 'qmc_lvs_save', 'qmc_lvs_nonce' );

		$model   = get_post_meta($post->ID, self::META_MODEL, true);
		$storage = get_post_meta($post->ID, self::META_STORAGE, true);
		$color   = get_post_meta($post->ID, self::META_COLOR, true);
		$cond    = get_post_meta($post->ID, self::META_COND, true);

		// Auto-model from slug proposal
		$suggested_model = $model;
		if ( empty($suggested_model) ) {
			$suggested_model = sanitize_title( $post->post_name ); // simple default; editable
		}

		echo '<p><strong>Model Slug</strong><br/>';
		echo '<input type="text" name="qmc_model" value="'.esc_attr($model ?: $suggested_model).'" style="width:100%"/>';
		echo '<small>Shared across siblings. Example: <code>iphone-16-pro</code></small></p>';

		echo '<p><strong>Storage</strong><br/>';
		echo '<input type="text" name="qmc_storage" value="'.esc_attr($storage).'" style="width:100%" placeholder="128GB, 256GB, 512GB, 1TB"/></p>';

		echo '<p><strong>Color</strong><br/>';
		echo '<input type="text" name="qmc_color" value="'.esc_attr($color).'" style="width:100%" placeholder="Black, Gray, Gold"/></p>';

		$conds = get_option(self::OPT_CONDITIONS, ['NEW','Used (A)']);
		echo '<p><strong>Condition</strong><br/>';
		echo '<select name="qmc_condition" style="width:100%">';
		echo '<option value="">— Select —</option>';
		foreach ($conds as $c) {
			echo '<option value="'.esc_attr($c).'" '.selected($cond, $c, false).'>'.esc_html($c).'</option>';
		}
		echo '</select>';
		echo '<small>Manage list under WooCommerce → Settings → Linked Variations.</small>';
		echo '</p>';
	}

	public function save_product_meta($post_id) {
		if ( !isset($_POST['qmc_lvs_nonce']) || !wp_verify_nonce($_POST['qmc_lvs_nonce'], 'qmc_lvs_save') ) return;
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
		if ( ! current_user_can('edit_post', $post_id) ) return;

		$model   = isset($_POST['qmc_model']) ? sanitize_title( wp_unslash($_POST['qmc_model']) ) : '';
		$storage = isset($_POST['qmc_storage']) ? sanitize_text_field( wp_unslash($_POST['qmc_storage']) ) : '';
		$color   = isset($_POST['qmc_color']) ? sanitize_text_field( wp_unslash($_POST['qmc_color']) ) : '';
		$cond    = isset($_POST['qmc_condition']) ? sanitize_text_field( wp_unslash($_POST['qmc_condition']) ) : '';

		update_post_meta($post_id, self::META_MODEL, $model);
		update_post_meta($post_id, self::META_STORAGE, $storage);
		update_post_meta($post_id, self::META_COLOR, $color);
		update_post_meta($post_id, self::META_COND, $cond);
	}

	/* ============================
	 * Settings Page
	 * ============================ */
	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			'Linked Variations',
			'Linked Variations',
			'manage_woocommerce',
			'qmc-lvs-settings',
			[$this, 'settings_page_html']
		);
	}

	public function settings_page_html() {
		if ( ! current_user_can('manage_woocommerce') ) return;
		$conds = get_option(self::OPT_CONDITIONS, ['NEW', 'Used (A)']);
		?>
		<div class="wrap">
			<h1>Linked Variations Settings</h1>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('qmc_lvs_settings_save','qmc_lvs_settings_nonce'); ?>
				<input type="hidden" name="action" value="qmc_lvs_save_settings" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="conds">Conditions</label></th>
						<td>
							<textarea id="conds" name="conditions" rows="4" cols="60" placeholder="Comma-separated, e.g. NEW, Used (A), Used (B)"><?php echo esc_textarea(implode(', ', $conds)); ?></textarea>
							<p class="description">These appear in the Condition selector and product edit dropdown.</p>
						</td>
					</tr>
				</table>
				<?php submit_button('Save Changes'); ?>
			</form>
		</div>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can('manage_woocommerce') ) wp_die('Not allowed.');
		if ( ! isset($_POST['qmc_lvs_settings_nonce']) || ! wp_verify_nonce($_POST['qmc_lvs_settings_nonce'], 'qmc_lvs_settings_save') ) wp_die('Nonce failed.');

		$raw = isset($_POST['conditions']) ? wp_unslash($_POST['conditions']) : '';
		$parts = array_filter(array_map('trim', explode(',', $raw)));
		if (empty($parts)) $parts = ['NEW', 'Used (A)'];
		update_option(self::OPT_CONDITIONS, $parts);

		wp_safe_redirect( admin_url('admin.php?page=qmc-lvs-settings&saved=1') );
		exit;
	}

	/* ============================
	 * Frontend
	 * ============================ */
	public function enqueue_assets() {
		if ( is_product() ) {
			$css = "
.qmc-lvs {margin-top:16px}
.qmc-lvs .group-title{font-weight:600;margin:12px 0 6px}
.qmc-lvs .colors{display:flex;gap:10px;flex-wrap:wrap}
.qmc-lvs .color-card{width:76px;height:60px;border:1px solid #e5e7eb;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;background:#fff;transition:box-shadow .15s,border-color .15s}
.qmc-lvs .color-card img{max-width:60px;max-height:48px;display:block}
.qmc-lvs .color-card.is-active{border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.2)}
.qmc-lvs .color-card.is-disabled{opacity:.45;filter:grayscale(100%);cursor:not-allowed}
.qmc-lvs .pills{display:flex;gap:8px;flex-wrap:wrap}
.qmc-lvs .pill{border:1px solid #e5e7eb;border-radius:999px;padding:8px 14px;cursor:pointer;background:#fff}
.qmc-lvs .pill.is-active{background:#3b82f6;color:#fff;border-color:#3b82f6}
.qmc-lvs .pill.is-disabled{opacity:.45;cursor:not-allowed}
.qmc-lvs .seg{display:flex;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;width:max-content}
.qmc-lvs .seg a{padding:10px 16px;text-decoration:none;display:block;color:#111}
.qmc-lvs .seg .is-active{background:#3b82f6;color:#fff}
";
			wp_register_style('qmc-lvs', false);
			wp_add_inline_style('qmc-lvs', $css);
			wp_enqueue_style('qmc-lvs');
		}
	}

	public function render_selectors() {
		global $product;
		if ( ! $product || ! is_a($product, 'WC_Product_Simple') ) return;

		$post_id = $product->get_id();
		$model   = get_post_meta($post_id, self::META_MODEL, true);
		$storage = get_post_meta($post_id, self::META_STORAGE, true);
		$color   = get_post_meta($post_id, self::META_COLOR, true);
		$cond    = get_post_meta($post_id, self::META_COND, true);

		if ( empty($model) ) return; // cannot group without a model

		// Fetch all siblings for this model
		$siblings = $this->get_siblings($model);

		if ( empty($siblings) ) return;

		// Index by attributes for a quick lookup
		$by_color = [];
		$by_storage = [];
		$by_cond = [];
		$all_colors = [];
		$all_storages = [];
		$all_conds = [];

		foreach ($siblings as $sid => $sdata) {
			$c = $sdata['color'];
			$st = $sdata['storage'];
			$cn = $sdata['cond'];

			$all_colors[$c] = true;
			$all_storages[$st] = true;
			$all_conds[$cn] = true;

			// For current Storage+Condition, gather color options
			if ($st === $storage && $cn === $cond) {
				$by_color[$c] = $sid;
			}
			// For current Color+Condition, gather storage options
			if ($c === $color && $cn === $cond) {
				$by_storage[$st] = $sid;
			}
			// For current Color+Storage, gather condition options
			if ($c === $color && $st === $storage) {
				$by_cond[$cn] = $sid;
			}
		}

		// Sort storages naturally (128GB, 256GB, 512GB, 1TB)
		$storages = array_keys($by_storage);
		usort($storages, function($a,$b){
			$map = ['KB'=>1,'MB'=>2,'GB'=>3,'TB'=>4];
			$pa = strtoupper(trim($a));
			$pb = strtoupper(trim($b));
			$na = floatval($pa);
			$nb = floatval($pb);
			$ua = preg_replace('/[0-9.\s]/','',$pa) ?: 'GB';
			$ub = preg_replace('/[0-9.\s]/','',$pb) ?: 'GB';
			if ($map[$ua] === $map[$ub]) return $na <=> $nb;
			return $map[$ua] <=> $map[$ub];
		});

		// Conditions list in configured order
		$cond_list = get_option(self::OPT_CONDITIONS, ['NEW','Used (A)']);

		echo '<div class="qmc-lvs">';

		// Colors with mini images
		echo '<div class="group">';
		echo '<div class="group-title">Colors</div>';
		echo '<div class="colors">';
		// Build the set of colors that exist for current storage+condition
		// Also include the current color even if indexing missed it
		$present_colors = array_unique(array_merge(array_keys($by_color), [$color]));
		foreach ($present_colors as $cname) {
			if (empty($cname)) continue;
			$is_current = (strcasecmp($cname, $color) === 0);
			$target_id = isset($by_color[$cname]) ? $by_color[$cname] : 0;
			$classes = 'color-card';
			$url = '#';
			$disabled = false;

			if ( $target_id && intval($target_id) !== intval($post_id) ) {
				$url = get_permalink($target_id);
			} elseif ( $is_current ) {
				$url = get_permalink($post_id);
			} else {
				$disabled = true;
			}

			if ( $is_current ) $classes .= ' is-active';
			if ( $disabled ) $classes .= ' is-disabled';

			// Thumbnail of the sibling (or current)
			$thumb_id = $disabled ? get_post_thumbnail_id($post_id) : get_post_thumbnail_id( $target_id ?: $post_id );
			$img = $thumb_id ? wp_get_attachment_image( $thumb_id, 'thumbnail' ) : '<span style="font-size:12px">'.esc_html($cname).'</span>';

			echo '<a class="'.esc_attr($classes).'" href="'.esc_url($url).'" title="'.esc_attr($cname).'" '.($disabled?'aria-disabled="true"':'').'>'.$img.'</a>';
		}
		echo '</div></div>';

		// Storage pills (dynamic)
		echo '<div class="group" style="margin-top:14px">';
		echo '<div class="group-title">Storage Options</div>';
		echo '<div class="pills">';
		if (empty($storages)) $storages = [$storage];
		foreach ($storages as $st) {
			$is_current = (strcasecmp($st, $storage) === 0);
			$target_id = isset($by_storage[$st]) ? $by_storage[$st] : 0;
			$classes = 'pill';
			$url = '#';
			$disabled = false;

			if ( $target_id && intval($target_id) !== intval($post_id) ) {
				$url = get_permalink($target_id);
			} elseif ( $is_current ) {
				$url = get_permalink($post_id);
			} else {
				$disabled = true;
			}

			if ( $is_current ) $classes .= ' is-active';
			if ( $disabled ) $classes .= ' is-disabled';

			echo '<a class="'.esc_attr($classes).'" href="'.esc_url($url).'" '.($disabled?'aria-disabled="true"':'').'>'.esc_html($st).'</a>';
		}
		echo '</div></div>';

		// Condition segmented control
		echo '<div class="group" style="margin-top:14px">';
		echo '<div class="group-title">Condition</div>';
		echo '<div class="seg">';
		foreach ($cond_list as $label) {
			$is_current = (strcasecmp($label, $cond) === 0);
			$target_id = isset($by_cond[$label]) ? $by_cond[$label] : 0;
			$classes = $is_current ? 'is-active' : '';
			$url = $is_current ? get_permalink($post_id) : ( $target_id ? get_permalink($target_id) : '#' );
			$attr = ($target_id || $is_current) ? '' : ' aria-disabled="true" style="opacity:.45;pointer-events:none"';
			echo '<a class="'.esc_attr($classes).'" href="'.esc_url($url).'"'.$attr.'>'.esc_html($label).'</a>';
		}
		echo '</div></div>';

		echo '</div>'; // .qmc-lvs
	}

	private function get_siblings($model_slug) {
		// Get all simple products with the same model slug, published
		$q = new WP_Query([
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => 200,
			'fields' => 'ids',
			'meta_query' => [
				[
					'key'   => self::META_MODEL,
					'value' => $model_slug,
				]
			],
			'tax_query' => [
				[
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => ['simple'],
				]
			]
		]);

		if (!$q->have_posts()) return [];

		$out = [];
		foreach ($q->posts as $pid) {
			$out[$pid] = [
				'storage' => get_post_meta($pid, self::META_STORAGE, true) ?: '',
				'color'   => get_post_meta($pid, self::META_COLOR, true) ?: '',
				'cond'    => get_post_meta($pid, self::META_COND, true) ?: '',
			];
		}
		return $out;
	}
}

new QMC_Linked_Variations_Simple();
