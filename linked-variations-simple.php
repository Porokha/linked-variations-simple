<?php

/*
 * Plugin Name: Linked Variations for Simple Products
 * Plugin URI: https://Gstore.ge
 * Description: Apple-style selectors for Color, Storage, and Condition that link between separate simple products.
 * Version: 3.1.3
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

final class QMC_Linked_Variations_Simple {

	const META_MODEL   = '_qmc_model_slug';
	const META_STORAGE = '_qmc_storage';
	const META_COLOR   = '_qmc_color';
	const META_COND    = '_qmc_condition';

	const OPT_CONDITIONS = 'qmc_lvs_conditions'; // array of labels
	const LOG_DIR = 'logs';
	const LOG_FILE = 'debug.log';

	public function __construct() {
		// defaults
		add_action('admin_init', [$this,'maybe_defaults']);

		// admin
		add_action('admin_menu', [$this,'admin_menu'] );
		add_action('admin_post_qmc_lvs_bulk_sync', [$this,'handle_bulk_sync'] );

		// frontend assets
		add_action('wp_enqueue_scripts', [$this,'enqueue']);

		// place selectors ABOVE buttons in summary (price ~10, excerpt 20, add-to-cart 30)
		add_action('woocommerce_single_product_summary', [$this,'render_selectors'], 25);

		// safety relocation (if theme nests inside form)
		add_action('wp_enqueue_scripts', function(){
			if (is_product()) {
				wp_add_inline_script('qmc-lvs-js', "document.addEventListener('DOMContentLoaded',function(){var f=document.querySelector('form.cart');var b=document.querySelector('.qmc-lvs');if(f&&b&&f.contains(b)){f.parentNode.insertBefore(b,f);}});");
			}
		});

		// REST route for AJAX switch
		add_action('rest_api_init', function(){
			register_rest_route('qmc-lvs/v1','/switch', [
				'methods'  => 'GET',
				'callback' => [$this,'rest_switch_product'],
				'permission_callback' => '__return_true'
			]);
		});
	}

	public static function activate() {
		$dir = plugin_dir_path(__FILE__) . self::LOG_DIR;
		if ( ! file_exists($dir) ) {
			wp_mkdir_p($dir);
		}
		self::log('activate', ['version'=> '3.0.4']);
	}
	public static function deactivate() {}

	public function maybe_defaults() {
		$val = get_option(self::OPT_CONDITIONS);
		if ( empty($val) || !is_array($val) ) {
			update_option(self::OPT_CONDITIONS, ['NEW','Used (A)']);
		}
	}

	/* ========== LOGGING ========== */
	public static function log($tag, $data = []) {
		$base = plugin_dir_path(__FILE__) . self::LOG_DIR;
		if ( ! file_exists($base) ) { @wp_mkdir_p($base); }
		$file = trailingslashit($base) . self::LOG_FILE;
		$line = '['. gmdate('Y-m-d H:i:s') .' UTC] '. $tag .' '. wp_json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
		@file_put_contents($file, $line, FILE_APPEND);
	}

	/* ========== ADMIN ========== */
	public function admin_menu() {
		add_menu_page('Linked Variations','Linked Variations','manage_woocommerce','qmc-lvs',[$this,'admin_logs_page'],'dashicons-screenoptions',59);
		add_submenu_page('qmc-lvs','Bulk Smart Sync','Bulk Smart Sync','manage_woocommerce','qmc-lvs-bulk',[$this,'bulk_page']);
		add_submenu_page('qmc-lvs','Settings','Settings','manage_woocommerce','qmc-lvs-settings',[$this,'settings_page']);
	}

	public function admin_logs_page() {
		echo '<div class="wrap"><h1>Linked Variations → Logs</h1>';
		$file = plugin_dir_path(__FILE__) . self::LOG_DIR . '/' . self::LOG_FILE;
		if ( file_exists($file) ) {
			echo '<p><a class="button" href="'.esc_url( add_query_arg(['qmc_lvs_clear_log'=>1]) ).'">Clear log</a></p>';
			if ( isset($_GET['qmc_lvs_clear_log']) ) { @unlink($file); echo '<div class="updated"><p>Cleared.</p></div>'; }
			echo '<pre style="background:#111;color:#0f0;padding:12px;border-radius:6px;max-height:60vh;overflow:auto;">'. esc_html(file_get_contents($file)) .'</pre>';
		} else {
			echo '<p>No logs yet.</p>';
		}
		echo '</div>';
	}

	public function settings_page() {
		if ( ! current_user_can('manage_woocommerce') ) return;
		$conds = get_option(self::OPT_CONDITIONS, ['NEW','Used (A)']);
		echo '<div class="wrap"><h1>Linked Variations → Settings</h1>';
		echo '<form method="post">';
		wp_nonce_field('qmc_lvs_save_settings','qmc_lvs_save_settings');
		echo '<table class="form-table"><tr><th>Conditions</th><td><textarea name="qmc_lvs_conditions" rows="3" cols="60">'.esc_textarea(implode(', ',$conds)).'</textarea><p class="description">Comma separated.</p></td></tr></table>';
		submit_button('Save');
		echo '</form>';
		if ( isset($_POST['qmc_lvs_save_settings']) && wp_verify_nonce($_POST['qmc_lvs_save_settings'],'qmc_lvs_save_settings') ) {
			$raw = isset($_POST['qmc_lvs_conditions']) ? wp_unslash($_POST['qmc_lvs_conditions']) : '';
			$parts = array_filter(array_map('trim', explode(',',$raw)));
			if ( empty($parts) ) $parts = ['NEW','Used (A)'];
			update_option(self::OPT_CONDITIONS, $parts);
			echo '<div class="updated"><p>Saved.</p></div>';
		}
		echo '</div>';
	}

	public function bulk_page() {
		if ( ! current_user_can('manage_woocommerce') ) return;
		$terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
		echo '<div class="wrap"><h1>Linked Variations → Bulk Smart Sync</h1>';
		echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'">';
		echo '<input type="hidden" name="action" value="qmc_lvs_bulk_sync"/>';
		wp_nonce_field('qmc_lvs_bulk_sync','qmc_lvs_bulk_sync');
		echo '<p><label><input type="checkbox" name="dryrun" value="1" checked/> Dry run (no writes)</label></p>';
		echo '<p><strong>Categories to include:</strong></p>';
		echo '<div style="max-width:520px;display:grid;grid-template-columns:1fr 1fr;gap:6px;">';
		foreach ($terms as $t) {
			echo '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" name="cats[]" value="'.intval($t->term_id).'" /> '.esc_html($t->name).'</label>';
		}
		echo '</div>';
		submit_button('Run Smart Sync');
		echo '</form>';
		echo '</div>';
	}

	public function handle_bulk_sync() {
		if ( ! current_user_can('manage_woocommerce') ) wp_die('Nope');
		if ( ! isset($_POST['qmc_lvs_bulk_sync']) || ! wp_verify_nonce($_POST['qmc_lvs_bulk_sync'],'qmc_lvs_bulk_sync') ) wp_die('Nonce');
		$dry = ! empty($_POST['dryrun']);
		$cats = isset($_POST['cats']) ? array_map('intval', (array)$_POST['cats']) : [];
		require_once __DIR__ . '/includes/parser.php';

		$args = [
			'post_type' => 'product',
			'post_status'=> 'publish',
			'posts_per_page'=> -1,
			'fields'=>'ids',
			'tax_query'=>[]
		];
		if ( ! empty($cats) ) {
			$args['tax_query'][] = [
				'taxonomy'=>'product_cat','field'=>'term_id','terms'=>$cats
			];
		}
		$q = new WP_Query($args);
		$rows = [];
		if ( $q->have_posts() ) {
			foreach ($q->posts as $pid) {
				$slug = get_post_field('post_name',$pid);
				$parsed = QMC_LVS_Parser::parse_slug_flexible($slug);
				$existing = [
					'model'=> get_post_meta($pid,self::META_MODEL,true),
					'storage'=> get_post_meta($pid,self::META_STORAGE,true),
					'color'=> get_post_meta($pid,self::META_COLOR,true),
					'cond'=> get_post_meta($pid,self::META_COND,true),
				];
				$applied = ['model'=>'','storage':'','color':'','cond':''];
				$changed = 0;
				if ( empty($existing['model']) && ! empty($parsed['model']) ) { if(!$dry) update_post_meta($pid,self::META_MODEL,$parsed['model']); $applied['model']=$parsed['model']; $changed++; }
				if ( empty($existing['storage']) && ! empty($parsed['storage']) ) { if(!$dry) update_post_meta($pid,self::META_STORAGE,$parsed['storage']); $applied['storage']=$parsed['storage']; $changed++; }
				if ( empty($existing['color']) && ! empty($parsed['color']) ) { if(!$dry) update_post_meta($pid,self::META_COLOR,$parsed['color']); $applied['color']=$parsed['color']; $changed++; }
				if ( empty($existing['cond']) && ! empty($parsed['cond']) ) { if(!$dry) update_post_meta($pid,self::META_COND,$parsed['cond']); $applied['cond']=$parsed['cond']; $changed++; }
				$rows[] = [$pid, $slug, $existing['model'],$existing['storage'],$existing['color'],$existing['cond'], $parsed['model'],$parsed['storage'],$parsed['color'],$parsed['cond'], $changed, $dry? 'DRY' : 'WRITE' ];
			}
		}
		self::log('bulk_sync', ['count'=>count($rows),'dry'=>$dry]);

		// write CSV
		$dir = plugin_dir_path(__FILE__) . self::LOG_DIR;
		if ( ! file_exists($dir) ) wp_mkdir_p($dir);
		$file = trailingslashit($dir) . 'sync-report-' . gmdate('Ymd-His') . '.csv';
		$fp = fopen($file,'w');
		fputcsv($fp, ['ID','slug','existing_model','existing_storage','existing_color','existing_cond','parsed_model','parsed_storage','parsed_color','parsed_cond','changed_fields','mode']);
		foreach ($rows as $r) fputcsv($fp,$r);
		fclose($fp);

		wp_safe_redirect( admin_url('admin.php?page=qmc-lvs-bulk&report=' . urlencode( basename($file) )) );
		exit;
	}

	/* ========== ASSETS ========== */
	public function enqueue() {
		if ( ! is_product() ) return;
		wp_register_style('qmc-lvs-css', plugins_url('assets/frontend.css', __FILE__), [], '3.0.4');
		wp_enqueue_style('qmc-lvs-css');
		wp_register_script('qmc-lvs-js', plugins_url('assets/js/variation-ajax.js', __FILE__), ['jquery'], '3.0.4', true);
		wp_localize_script('qmc-lvs-js', 'QMC_LVS', [
			'rest' => esc_url_raw( rest_url('qmc-lvs/v1/switch') ),
			'nonce'=> wp_create_nonce('wp_rest')
		]);
		wp_enqueue_script('qmc-lvs-js');
	}

	/* ========== RENDER ========== */
	public function render_selectors() {
		if ( ! function_exists('wc_get_product') ) return;
		global $product;
		if ( ! $product || ! is_a($product,'WC_Product') ) return;
		if ( ! $product->is_type('simple') ) return;

		$post_id = $product->get_id();
		$model   = get_post_meta($post_id, self::META_MODEL, true);
		$storage = get_post_meta($post_id, self::META_STORAGE, true);
		$color   = get_post_meta($post_id, self::META_COLOR, true);
		$cond    = get_post_meta($post_id, self::META_COND, true);

		if ( empty($model) ) return;

		$siblings = $this->get_siblings($model);
		if ( empty($siblings) ) return;

		// Compute option maps
		$by_color = []; $by_storage=[]; $by_cond=[];
		foreach ($siblings as $sid => $s) {
			if ($s['storage']===$storage && $s['cond']===$cond) $by_color[$s['color']]=$sid;
			if ($s['color']===$color && $s['cond']===$cond) $by_storage[$s['storage']]=$sid;
			if ($s['color']===$color && $s['storage']===$storage) $by_cond[$s['cond']]=$sid;
		}
		$cond_list = get_option(self::OPT_CONDITIONS, ['NEW','Used (A)']);

		echo '<div class="qmc-lvs" data-current-id="'.esc_attr($post_id).'">';

		// Condition
		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Condition</div><div class="qmc-lvs-seg">';
		foreach ($cond_list as $label) {
			$is_current = (strcasecmp($label,$cond)===0);
			$tid = isset($by_cond[$label]) ? intval($by_cond[$label]) : 0;
			$url = $is_current ? get_permalink($post_id) : ( $tid ? get_permalink($tid) : '#' );
			$attr = ($tid || $is_current) ? '' : ' aria-disabled="true" style="opacity:.5;pointer-events:none"';
			echo '<a class="'.($is_current?'is-active':'').'" href="'.esc_url($url).'" data-qmc-link="1" data-product="'.esc_attr($tid?:$post_id).'"'.$attr.'>'.esc_html($label).'</a>';
		}
		echo '</div></div>';

		// Storage (Apple-style segmented)
		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Storage</div><div class="qmc-lvs-flex qmc-storage">';
		if (empty($by_storage)) $by_storage[$storage]=$post_id;
		$keys = array_keys($by_storage);
		usort($keys,function($a,$b){$m=['KB'=>1,'MB'=>2,'GB'=>3,'TB'=>4];$pa=strtoupper($a);$pb=strtoupper($b);$na=floatval($pa);$nb=floatval($pb);$ua=preg_replace('/[0-9.\s]/','',$pa)?:'GB';$ub=preg_replace('/[0-9.\s]/','',$pb)?:'GB';if($m[$ua]==$m[$ub])return $na<=>$nb;return $m[$ua]<=>$m[$ub];});
		foreach ($keys as $st) {
			$tid = intval($by_storage[$st]);
			$is_current = (strcasecmp($st,$storage)===0);
			$url = $is_current ? get_permalink($post_id) : get_permalink($tid);
			echo '<a class="qmc-pill '.($is_current?'active':'').'" href="'.esc_url($url).'" data-qmc-link="1" data-product="'.esc_attr($tid).'">'.esc_html($st).'</a>';
		}
		echo '</div></div>';

		// Colors (mini images, no labels)
		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Color</div><div class="qmc-lvs-flex qmc-colors">';
		$present = array_unique( array_merge( array_keys($by_color), [$color] ) );
		foreach ($present as $cname) {
			if (!$cname) continue;
			$tid = isset($by_color[$cname]) ? intval($by_color[$cname]) : 0;
			$is_current = (strcasecmp($cname,$color)===0);
			$url = $is_current ? get_permalink($post_id) : ( $tid ? get_permalink($tid) : '#' );
			$classes = 'color-card'.($is_current?' is-active':'');
			$outline = esc_attr( $this->color_hex_from_label($cname) );
			$thumb_id = $tid ? get_post_thumbnail_id($tid) : get_post_thumbnail_id($post_id);
			$img = $thumb_id ? wp_get_attachment_image($thumb_id,'thumbnail',['class'=>'qmc-mini','crossorigin'=>'anonymous']) : '<span class="qmc-mini-fallback"></span>';
			echo '<a class="'.$classes.'" href="'.esc_url($url).'" data-qmc-link="1" data-product="'.esc_attr($tid?:$post_id).'" style="--qmc-outline: '.$outline.'"><span class="qmc-thumb">'.$img.'</span></a>';
		}
		echo '</div></div>';

		echo '</div>'; // .qmc-lvs
	}

	private function color_hex_from_label($name) {
		$n = strtolower($name);
		$map = [
			'black'=>'#1f2937','graphite'=>'#1f2937','space'=>'#1f2937','midnight'=>'#111827',
			'white'=>'#e5e7eb','silver'=>'#d4d4d8','starlight'=>'#e5e5da',
			'gold'=>'#e6c65b','desert'=>'#c0ac7a','titanium'=>'#9aa0a6','blue'=>'#3b82f6',
			'purple'=>'#6d5bd0','green'=>'#10b981','red'=>'#ef4444'
		];
		foreach ($map as $k=>$hex) { if ( strpos($n,$k)!==false ) return $hex; }
		return '#3b82f6';
	}

	private function get_siblings($model_slug) {
		$q = new WP_Query([
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => 250,
			'fields'=>'ids',
			'meta_query'=>[ ['key'=>self::META_MODEL,'value'=>$model_slug] ],
			'tax_query'=>[ ['taxonomy'=>'product_type','field'=>'slug','terms'=>['simple']] ]
		]);
		if ( ! $q->have_posts() ) return [];
		$out = [];
		foreach ($q->posts as $pid) {
			$out[$pid] = [
				'storage'=> get_post_meta($pid,self::META_STORAGE,true) ?: '',
				'color'  => get_post_meta($pid,self::META_COLOR,true) ?: '',
				'cond'   => get_post_meta($pid,self::META_COND,true) ?: '',
			];
		}
		return $out;
	}

	/* ========== REST: AJAX SWITCH ========== */
	public function rest_switch_product( WP_REST_Request $request ) {

		// Ensure WooCommerce env is available
		if ( ! class_exists('WooCommerce') ) {
			$wc_plugin = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
			if ( file_exists($wc_plugin) ) { include_once $wc_plugin; }
		}
		if ( ! function_exists('wc_get_product') && defined('WC_ABSPATH') ) {
			include_once WC_ABSPATH . 'includes/wc-product-functions.php';
		}
		if ( ! function_exists('wc_get_price_html') && defined('WC_ABSPATH') ) {
			include_once WC_ABSPATH . 'includes/wc-template-functions.php';
		}
		if ( ! function_exists('wc_get_price_html') ) {
			self::log('rest_switch_product_missing_wc', []);
			return new WP_REST_Response(['error'=>'woocommerce_template_functions_unavailable'], 500);
		}

		$id = intval($request->get_param('id'));
		if ( $id <= 0 ) return new WP_REST_Response(['error'=>'missing_id'], 400);

		$product = wc_get_product($id);
		if ( ! $product || ! $product->is_type('simple') ) {
			return new WP_REST_Response(['error'=>'not_simple_or_missing'], 400);
		}

		$payload = [
			'id'        => $product->get_id(),
			'title'     => $product->get_name(),
			'price'     => wc_get_price_html($product),
			'permalink' => get_permalink($product->get_id()),
			'in_stock'  => $product->is_in_stock(),
			'cart_id'   => $product->get_id(),
		];

		self::log('ajax_switch', $payload);
		return new WP_REST_Response($payload, 200);
	}
}

/* ===== Admin include (parser) ===== */
require_once __DIR__ . '/includes/parser.php';

register_activation_hook(__FILE__, ['QMC_Linked_Variations_Simple','activate']);
register_deactivation_hook(__FILE__, ['QMC_Linked_Variations_Simple','deactivate']);

new QMC_Linked_Variations_Simple();
