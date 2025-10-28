<?php

/*
 * Plugin Name: Linked Variations for Simple Products
 * Plugin URI: https://Gstore.ge
 * Description: Apple-style selectors for Color, Storage, and Condition that link between separate simple products.
 * Version: 2.0.0
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


if (!defined('ABSPATH')) exit;

// =====================================================
// CONFIG
// =====================================================
define('QMC_LVS_VERSION', '3.0.1');
define('QMC_LVS_DEBUG', true); // set to false to disable file logging

// Option & Meta keys
if (!defined('QMC_LVS_OPT_CONDITIONS')) define('QMC_LVS_OPT_CONDITIONS', 'qmc_lvs_conditions');
if (!defined('QMC_LVS_META_MODEL'))     define('QMC_LVS_META_MODEL', '_qmc_model');
if (!defined('QMC_LVS_META_STORAGE'))   define('QMC_LVS_META_STORAGE', '_qmc_storage');
if (!defined('QMC_LVS_META_COLOR'))     define('QMC_LVS_META_COLOR', '_qmc_color');
if (!defined('QMC_LVS_META_COND'))      define('QMC_LVS_META_COND', '_qmc_condition');

// =====================================================
// DEBUG LOGGER
// =====================================================
function qmc_lvs_log($msg) {
	if (!QMC_LVS_DEBUG) return;
	$dir = plugin_dir_path(__FILE__) . 'logs/';
	if (!file_exists($dir)) wp_mkdir_p($dir);
	$file = $dir . 'debug.log';
	$line = '['.date('Y-m-d H:i:s').'] ' . (is_scalar($msg) ? $msg : print_r($msg, true));
	@file_put_contents($file, $line . "\n", FILE_APPEND);
}

// =====================================================
// CORE CLASS
// =====================================================
class QMC_Linked_Variations_Simple {

	public function __construct() {
		// Admin
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_init', [$this, 'ensure_defaults']);
		add_action('add_meta_boxes', [$this, 'add_meta']);
		add_action('save_post_product', [$this, 'save_meta']);

		// Admin posts (bulk sync, clear log, test parse, save settings)
		add_action('admin_post_qmc_lvs_run_bulk_sync', [$this, 'handle_bulk_sync']);
		add_action('admin_post_qmc_lvs_clear_log', [$this, 'handle_clear_log']);
		add_action('admin_post_qmc_lvs_test_parse', [$this, 'handle_test_parse']);
		add_action('admin_post_qmc_lvs_save_settings', [$this, 'handle_save_settings']);

		// Frontend
		add_action('wp_enqueue_scripts', [$this, 'load_assets']);
		// You liked it here:
		add_action('woocommerce_after_add_to_cart_form', [$this, 'render_selectors'], 5);
	}

	// ---------------------------
	// Admin Menu & Pages
	// ---------------------------
	public function admin_menu() {
		add_menu_page(
			'Linked Variations',
			'Linked Variations',
			'manage_woocommerce',
			'qmc-lvs',
			[$this, 'render_admin_page'],
			'dashicons-screenoptions',
			56
		);
	}

	public function render_admin_page() {
		require_once plugin_dir_path(__FILE__) . 'admin/page-main.php';
	}

	public function ensure_defaults() {
		if (!get_option(QMC_LVS_OPT_CONDITIONS)) {
			update_option(QMC_LVS_OPT_CONDITIONS, ['NEW', 'Used (A)']);
		}
	}

	public function handle_save_settings() {
		if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
		check_admin_referer('qmc_lvs_save_settings');
		$raw = isset($_POST['conditions']) ? wp_unslash($_POST['conditions']) : '';
		$parts = array_filter(array_map('trim', explode(',', $raw)));
		if (empty($parts)) $parts = ['NEW', 'Used (A)'];
		update_option(QMC_LVS_OPT_CONDITIONS, $parts);
		wp_safe_redirect(add_query_arg(['page'=>'qmc-lvs','tab'=>'settings','saved'=>1], admin_url('admin.php')));
		exit;
	}

	// ---------------------------
	// Meta box
	// ---------------------------
	public function add_meta() {
		add_meta_box('qmc_lvs_meta', 'Linked Variations', [$this,'meta_html'], 'product', 'side');
	}

	public function meta_html($post) {
		wp_nonce_field('qmc_lvs', 'qmc_lvs_nonce');
		$model   = get_post_meta($post->ID, QMC_LVS_META_MODEL, true);
		$storage = get_post_meta($post->ID, QMC_LVS_META_STORAGE, true);
		$color   = get_post_meta($post->ID, QMC_LVS_META_COLOR, true);
		$cond    = get_post_meta($post->ID, QMC_LVS_META_COND, true);
		if (!$model) $model = sanitize_title($post->post_name);

		$conds = get_option(QMC_LVS_OPT_CONDITIONS, ['NEW','Used (A)']);
		?>
		<p><b>Model Slug</b><br>
			<input name="qmc_model" style="width:100%" value="<?php echo esc_attr($model); ?>">
			<small>Shared across siblings. Example: <code>iphone-16-pro</code></small>
		</p>
		<p><b>Storage</b><br>
			<input name="qmc_storage" style="width:100%" placeholder="128GB, 256GB, 512GB, 1TB" value="<?php echo esc_attr($storage); ?>">
		</p>
		<p><b>Color</b><br>
			<input name="qmc_color" style="width:100%" placeholder="Desert Titanium" value="<?php echo esc_attr($color); ?>">
		</p>
		<p><b>Condition</b><br>
			<select name="qmc_condition" style="width:100%">
				<option value=""></option>
				<?php foreach($conds as $c): ?>
					<option <?php selected($cond,$c); ?>><?php echo esc_html($c); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	public function save_meta($post_id) {
		if (!isset($_POST['qmc_lvs_nonce']) || !wp_verify_nonce($_POST['qmc_lvs_nonce'],'qmc_lvs')) return;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (!current_user_can('edit_post', $post_id)) return;

		$model   = isset($_POST['qmc_model']) ? sanitize_title(wp_unslash($_POST['qmc_model'])) : '';
		$storage = isset($_POST['qmc_storage']) ? sanitize_text_field(wp_unslash($_POST['qmc_storage'])) : '';
		$color   = isset($_POST['qmc_color']) ? sanitize_text_field(wp_unslash($_POST['qmc_color'])) : '';
		$cond    = isset($_POST['qmc_condition']) ? sanitize_text_field(wp_unslash($_POST['qmc_condition'])) : '';

		if ($model !== '')   update_post_meta($post_id, QMC_LVS_META_MODEL, $model);
		if ($storage !== '') update_post_meta($post_id, QMC_LVS_META_STORAGE, strtoupper($storage));
		if ($color !== '')   update_post_meta($post_id, QMC_LVS_META_COLOR, $color);
		if ($cond !== '')    update_post_meta($post_id, QMC_LVS_META_COND, $cond);
	}

	// ---------------------------
	// Frontend Assets
	// ---------------------------
	public function load_assets() {
		wp_enqueue_style('qmc-lvs', plugin_dir_url(__FILE__) . 'assets/frontend.css', [], QMC_LVS_VERSION);
	}

	// ---------------------------
	// Render selectors
	// ---------------------------
	public function render_selectors() {
		if (!function_exists('is_product') || !is_product()) return;
		global $product;
		if (!$product || $product->get_type() !== 'simple') return;

		$post_id = $product->get_id();
		$model   = get_post_meta($post_id, QMC_LVS_META_MODEL, true);
		$storage = get_post_meta($post_id, QMC_LVS_META_STORAGE, true);
		$color   = get_post_meta($post_id, QMC_LVS_META_COLOR, true);
		$cond    = get_post_meta($post_id, QMC_LVS_META_COND, true);

		if (!$model) return;

		$siblings = $this->get_siblings($model);
		if (!$siblings) return;

		qmc_lvs_log(['render_for'=>$post_id, 'model'=>$model, 'siblings'=>count($siblings)]);

		// Build maps limited to current dimension relationships
		$by_color = [];
		$by_storage = [];
		$by_cond = [];
		foreach ($siblings as $sid=>$s) {
			if ($s['storage'] === $storage && $s['cond'] === $cond) $by_color[$s['color']] = $sid;
			if ($s['color'] === $color && $s['cond'] === $cond)     $by_storage[$s['storage']] = $sid;
			if ($s['color'] === $color && $s['storage'] === $storage) $by_cond[$s['cond']] = $sid;
		}

		// Sort storages naturally
		$storages = array_keys($by_storage);
		usort($storages, function($a,$b){
			$map = ['KB'=>1,'MB'=>2,'GB'=>3,'TB'=>4];
			$pa = strtoupper(trim($a)); $pb = strtoupper(trim($b));
			$na = floatval($pa); $nb = floatval($pb);
			$ua = preg_replace('/[0-9.\s]/','',$pa) ?: 'GB';
			$ub = preg_replace('/[0-9.\s]/','',$pb) ?: 'GB';
			return ($map[$ua]===$map[$ub]) ? ($na <=> $nb) : ($map[$ua] <=> $map[$ub]);
		});

		$cond_list = get_option(QMC_LVS_OPT_CONDITIONS, ['NEW','Used (A)']);

		echo '<div class="qmc-lvs">';

		// Colors
		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Colors</div><div class="qmc-lvs-flex">';
		$color_keys = array_unique(array_merge(array_keys($by_color), [$color]));
		foreach ($color_keys as $cname) {
			if (!$cname) continue;
			$sid = isset($by_color[$cname]) ? $by_color[$cname] : 0;
			$is_current = strcasecmp($cname, $color) === 0;
			$class = 'qmc-pill'.($is_current?' active':'');
			$url = $is_current ? get_permalink($post_id) : ($sid ? get_permalink($sid) : '#');
			$disabled = (!$sid && !$is_current);
			if ($disabled) $class += ' disabled';
			echo '<a class="'.esc_attr($class).'" href="'.esc_url($url).'"'.($disabled?' aria-disabled="true"':'').'>'.esc_html($cname).'</a>';
		}
		echo '</div></div>';

		// Storage
		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Storage</div><div class="qmc-lvs-flex">';
		if (empty($storages)) $storages = [$storage];
		foreach ($storages as $st) {
			$sid = isset($by_storage[$st]) ? $by_storage[$st] : 0;
			$is_current = strcasecmp($st, $storage) === 0;
			$class = 'qmc-pill'.($is_current?' active':'');
			$url = $is_current ? get_permalink($post_id) : ($sid ? get_permalink($sid) : '#');
			$disabled = (!$sid && !$is_current);
			if ($disabled) $class += ' disabled';
			echo '<a class="'.esc_attr($class).'" href="'.esc_url($url).'"'.($disabled?' aria-disabled="true"':'').'>'.esc_html($st).'</a>';
		}
		echo '</div></div>';

		// Condition
		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Condition</div><div class="qmc-lvs-seg">';
		foreach ($cond_list as $label) {
			$sid = isset($by_cond[$label]) ? $by_cond[$label] : 0;
			$is_current = strcasecmp($label, $cond) === 0;
			$class = $is_current ? 'active' : '';
			$url = $is_current ? get_permalink($post_id) : ($sid ? get_permalink($sid) : '#');
			$attr = ($sid || $is_current) ? '' : ' aria-disabled="true" style="opacity:.5;pointer-events:none"';
			echo '<a class="'.esc_attr($class).'" href="'.esc_url($url).'"'.$attr.'>'.esc_html($label).'</a>';
		}
		echo '</div></div>';

		echo '</div>';
	}

	// ---------------------------
	// Fetch siblings by model
	// ---------------------------
	private function get_siblings($model_slug) {
		$q = new WP_Query([
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				['key'=> QMC_LVS_META_MODEL, 'value'=>$model_slug]
			]
		]);
		$out = [];
		foreach($q->posts as $pid) {
			$out[$pid] = [
				'storage'=> get_post_meta($pid, QMC_LVS_META_STORAGE, true),
				'color'  => get_post_meta($pid, QMC_LVS_META_COLOR, true),
				'cond'   => get_post_meta($pid, QMC_LVS_META_COND, true),
			];
		}
		return $out;
	}

	// =====================================================
	// BULK SYNC (Manual, safe, non-destructive)
	// =====================================================
	public function handle_bulk_sync() {
		if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
		check_admin_referer('qmc_lvs_bulk_sync');

		$paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
		$per_page = 500; // big batches
		$args = [
			'post_type'=>'product',
			'post_status'=>'publish',
			'fields'=>'ids',
			'posts_per_page'=>$per_page,
			'paged'=>$paged,
		];
		$q = new WP_Query($args);

		$updated = 0; $skipped = 0;
		foreach($q->posts as $pid) {
			$model   = get_post_meta($pid, QMC_LVS_META_MODEL, true);
			$storage = get_post_meta($pid, QMC_LVS_META_STORAGE, true);
			$color   = get_post_meta($pid, QMC_LVS_META_COLOR, true);

			if ($model && $storage && $color) { $skipped++; continue; } // respect existing meta

			$post = get_post($pid);
			$parsed = $this->parse_from_slug($post ? $post->post_name : '');

			if (!$model && !empty($parsed['model']))     update_post_meta($pid, QMC_LVS_META_MODEL, $parsed['model']);
			if (!$storage && !empty($parsed['storage'])) update_post_meta($pid, QMC_LVS_META_STORAGE, $parsed['storage']);
			if (!$color && !empty($parsed['color']))     update_post_meta($pid, QMC_LVS_META_COLOR, $parsed['color']);

			if ((!$model && !empty($parsed['model'])) || (!$storage && !empty($parsed['storage'])) || (!$color && !empty($parsed['color']))) {
				$updated++;
				qmc_lvs_log(['bulk_updated'=>$pid, 'parsed'=>$parsed]);
			} else {
				$skipped++;
			}
		}

		$next_page = ($q->max_num_pages > $paged) ? $paged + 1 : 0;
		$redirect = add_query_arg([
			'page' => 'qmc-lvs',
			'tab'  => 'bulk',
			'updated_count' => $updated,
			'skipped_count' => $skipped,
			'next_page'     => $next_page,
		], admin_url('admin.php'));
		wp_safe_redirect($redirect);
		exit;
	}

	public function handle_clear_log() {
		if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
		check_admin_referer('qmc_lvs_clear_log');
		$file = plugin_dir_path(__FILE__) . 'logs/debug.log';
		if (file_exists($file)) @unlink($file);
		wp_safe_redirect(add_query_arg(['page'=>'qmc-lvs','tab'=>'logs','cleared'=>1], admin_url('admin.php')));
		exit;
	}

	public function handle_test_parse() {
		if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
		check_admin_referer('qmc_lvs_test_parse');
		$slug = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';
		$parsed = $this->parse_from_slug($slug);
		$redirect = add_query_arg([
			'page'=>'qmc-lvs','tab'=>'tools','slug'=>$slug,
			'parsed'=>rawurlencode(json_encode($parsed)),
		], admin_url('admin.php'));
		wp_safe_redirect($redirect);
		exit;
	}

	// =====================================================
	// FLEXIBLE SLUG PARSER
	// =====================================================
	private function parse_from_slug($slug) {
		$slug = trim($slug);
		if ($slug === '') return ['model'=>'','storage'=>'','color'=>''];

		$parts = array_values(array_filter(explode('-', strtolower($slug))));
		// find first token that looks like 64gb / 256gb / 1tb etc
		$storage_idx = -1; $storage = '';
		for ($i=0; $i<count($parts); $i++) {
			if (preg_match('/^[0-9]+(kb|mb|gb|tb)$/i', $parts[$i])) {
				$storage_idx = $i; $storage = strtoupper($parts[$i]); break;
			}
		}
		if ($storage_idx === -1) {
			for ($i=0; $i<count($parts); $i++) {
				if (preg_match('/^[0-9]+(\\s)?(kb|mb|gb|tb)$/i', $parts[$i])) { $storage_idx=$i; $storage=strtoupper(preg_replace('/\\s+/', '', $parts[$i])); break; }
			}
		}

		// model = everything from index 1 up to storage-1 (skip brand token at index 0 if present)
		$start = 0;
		$brands = ['apple','samsung','xiaomi','google','huawei','oneplus','nokia','sony','motorola','oppo','vivo','realme'];
		if (isset($parts[0]) && in_array($parts[0], $brands, true)) $start = 1;

		$model_tokens = [];
		if ($storage_idx > $start) {
			$model_tokens = array_slice($parts, $start, $storage_idx - $start);
		} else {
			$model_tokens = array_slice($parts, $start, max(1, count($parts)-2-$start));
		}
		$model_slug = implode('-', $model_tokens);

		// color = everything after storage
		$color_tokens = [];
		if ($storage_idx >= 0 && $storage_idx < count($parts)-1) {
			$color_tokens = array_slice($parts, $storage_idx+1);
		} else {
			$color_tokens = array_slice($parts, -2);
		}
		$color_label = implode(' ', array_map(function($t){ return ucfirst($t); }, $color_tokens));

		return [
			'model' => sanitize_title($model_slug),
			'storage' => $storage,
			'color' => trim($color_label),
		];
	}
}

new QMC_Linked_Variations_Simple();
