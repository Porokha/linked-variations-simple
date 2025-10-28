<?php

/*
 * Plugin Name: Linked Variations for Simple Products
 * Plugin URI: https://Gstore.ge
 * Description: Apple-style selectors for Color, Storage, and Condition that link between separate simple products.
 * Version: 3.1.0
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

define('QMC_LVS_VERSION', '3.0.0');
define('QMC_LVS_DEBUG', true);
define('QMC_LVS_OPT_CONDITIONS', 'qmc_lvs_conditions');
define('QMC_LVS_META_MODEL', '_qmc_model');
define('QMC_LVS_META_STORAGE', '_qmc_storage');
define('QMC_LVS_META_COLOR', '_qmc_color');
define('QMC_LVS_META_COND', '_qmc_condition');

function qmc_lvs_log($msg) {
	if (!QMC_LVS_DEBUG) return;
	$dir = plugin_dir_path(__FILE__) . 'logs/';
	if (!file_exists($dir)) wp_mkdir_p($dir);
	$file = $dir . 'debug.log';
	$line = '['.date('Y-m-d H:i:s').'] ' . (is_scalar($msg) ? $msg : print_r($msg, true));
	@file_put_contents($file, $line . "\n", FILE_APPEND);
}

class QMC_Linked_Variations_Simple {

	public function __construct() {
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_init', [$this, 'ensure_defaults']);
		add_action('add_meta_boxes', [$this, 'add_meta']);
		add_action('save_post_product', [$this, 'save_meta']);

		add_action('admin_post_qmc_lvs_save_settings', [$this, 'handle_save_settings']);
		add_action('admin_post_qmc_lvs_run_bulk_sync', [$this, 'handle_bulk_sync']);
		add_action('admin_post_qmc_lvs_clear_log', [$this, 'handle_clear_log']);
		add_action('admin_post_qmc_lvs_export_csv', [$this, 'handle_export_csv']);
		add_action('admin_post_qmc_lvs_reset_meta', [$this, 'handle_reset_meta']);

		add_filter('bulk_actions-edit-product', [$this,'register_bulk_actions']);
		add_filter('handle_bulk_actions-edit-product', [$this,'handle_bulk_actions'], 10, 3);

		add_action('wp_enqueue_scripts', [$this, 'load_assets']);
		add_action('woocommerce_after_add_to_cart_form', [$this, 'render_selectors'], 5);

		add_action('rest_api_init', function(){
			register_rest_route('qmc-lvs/v1', '/switch', [
				'methods' => 'GET',
				'callback' => [$this, 'rest_switch_product'],
				'permission_callback' => '__return_true'
			]);
		});
	}

	public function admin_menu() {
		add_menu_page('Linked Variations','Linked Variations','manage_woocommerce','qmc-lvs',[$this,'render_admin'],'dashicons-screenoptions',56);
	}
	public function ensure_defaults() {
		if (!get_option(QMC_LVS_OPT_CONDITIONS)) update_option(QMC_LVS_OPT_CONDITIONS, ['NEW','Used (A)']);
	}
	public function add_meta() {
		add_meta_box('qmc_lvs_meta','Linked Variations',[$this,'meta_html'],'product','side');
	}
	public function meta_html($post) {
		wp_nonce_field('qmc_lvs','qmc_lvs_nonce');
		$model = get_post_meta($post->ID, QMC_LVS_META_MODEL, true) ?: sanitize_title($post->post_name);
		$storage = get_post_meta($post->ID, QMC_LVS_META_STORAGE, true);
		$color = get_post_meta($post->ID, QMC_LVS_META_COLOR, true);
		$cond = get_post_meta($post->ID, QMC_LVS_META_COND, true);
		$conds = get_option(QMC_LVS_OPT_CONDITIONS, ['NEW','Used (A)']);
		echo '<p><b>Model Slug</b><br><input name="qmc_model" style="width:100%" value="'.esc_attr($model).'"></p>';
		echo '<p><b>Storage</b><br><input name="qmc_storage" style="width:100%" placeholder="128GB, 256GB" value="'.esc_attr($storage).'"></p>';
		echo '<p><b>Color</b><br><input name="qmc_color" style="width:100%" placeholder="Desert Titanium" value="'.esc_attr($color).'"></p>';
		echo '<p><b>Condition</b><br><select name="qmc_condition" style="width:100%"><option value=""></option>';
		foreach($conds as $c){ echo '<option '.selected($cond,$c,false).'>'.esc_html($c).'</option>'; }
		echo '</select></p>';
	}
	public function save_meta($post_id) {
		if (!isset($_POST['qmc_lvs_nonce']) || !wp_verify_nonce($_POST['qmc_lvs_nonce'],'qmc_lvs')) return;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (!current_user_can('edit_post', $post_id)) return;
		$model = isset($_POST['qmc_model']) ? sanitize_title(wp_unslash($_POST['qmc_model'])) : '';
		$storage = isset($_POST['qmc_storage']) ? sanitize_text_field(wp_unslash($_POST['qmc_storage'])) : '';
		$color = isset($_POST['qmc_color']) ? sanitize_text_field(wp_unslash($_POST['qmc_color'])) : '';
		$cond = isset($_POST['qmc_condition']) ? sanitize_text_field(wp_unslash($_POST['qmc_condition'])) : '';
		if ($model!=='') update_post_meta($post_id, QMC_LVS_META_MODEL, $model);
		if ($storage!=='') update_post_meta($post_id, QMC_LVS_META_STORAGE, strtoupper($storage));
		if ($color!=='') update_post_meta($post_id, QMC_LVS_META_COLOR, $color);
		if ($cond!=='') update_post_meta($post_id, QMC_LVS_META_COND, $cond);
	}
	public function render_admin() { require plugin_dir_path(__FILE__) . 'admin/page-main.php'; }
	public function handle_save_settings() {
		if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
		check_admin_referer('qmc_lvs_save_settings');
		$raw = isset($_POST['conditions']) ? wp_unslash($_POST['conditions']) : '';
		$parts = array_filter(array_map('trim', explode(',', $raw)));
		if (empty($parts)) $parts = ['NEW','Used (A)'];
		update_option(QMC_LVS_OPT_CONDITIONS, $parts);
		wp_safe_redirect(add_query_arg(['page'=>'qmc-lvs','tab'=>'settings','saved'=>1], admin_url('admin.php'))); exit;
	}

	public function handle_bulk_sync() {
		if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
		check_admin_referer('qmc_lvs_bulk_sync');
		$paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
		$per_page = 400;
		$cat_ids = isset($_POST['qmc_lvs_cat_ids']) ? array_map('intval', (array)$_POST['qmc_lvs_cat_ids']) : [];
		$tax_query = [];
		if (!empty($cat_ids)) { $tax_query[] = ['taxonomy'=>'product_cat','field'=>'term_id','terms'=>$cat_ids]; }
		$q = new WP_Query(['post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>$per_page,'paged'=>$paged,'tax_query'=>$tax_query]);

		$updated=0; $skipped=0; $rows=[];
		foreach($q->posts as $pid){
			$model = get_post_meta($pid, QMC_LVS_META_MODEL, true);
			$storage = get_post_meta($pid, QMC_LVS_META_STORAGE, true);
			$color = get_post_meta($pid, QMC_LVS_META_COLOR, true);

			$slug = get_post_field('post_name',$pid);
			$parsed = $this->parse_from_slug($slug);
			$row = ['ID'=>$pid,'slug'=>$slug,'model'=>$parsed['model'],'storage'=>$parsed['storage'],'color'=>$parsed['color'],'missing'=>[]];

			if (!$model && $parsed['model']) { update_post_meta($pid,QMC_LVS_META_MODEL,$parsed['model']); $updated++; }
			if (!$storage && $parsed['storage']) { update_post_meta($pid,QMC_LVS_META_STORAGE,$parsed['storage']); $updated++; }
			if (!$color && $parsed['color']) { update_post_meta($pid,QMC_LVS_META_COLOR,$parsed['color']); $updated++; }

			if ($model && $storage && $color) $skipped++;
			if (!$parsed['model']) $row['missing'][]='model';
			if (!$parsed['storage']) $row['missing'][]='storage';
			if (!$parsed['color']) $row['missing'][]='color';
			$rows[]=$row;
		}
		update_option('qmc_lvs_last_report', $rows, false);

		$next = ($q->max_num_pages > $paged) ? $paged+1 : 0;
		$redirect = add_query_arg(['page'=>'qmc-lvs','tab'=>'bulk','updated_count'=>$updated,'skipped_count'=>$skipped,'next_page'=>$next,'cats'=>implode(',',$cat_ids)], admin_url('admin.php'));
		wp_safe_redirect($redirect); exit;
	}

	public function handle_export_csv() {
		if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
		check_admin_referer('qmc_lvs_export_csv');
		$rows = get_option('qmc_lvs_last_report', []);
		header('Content-Type: text/csv');
		header('Content-Disposition: attachment; filename="qmc-lvs-report.csv"');
		$out = fopen('php://output','w');
		fputcsv($out, ['ID','Slug','Parsed Model','Parsed Storage','Parsed Color','Missing Fields']);
		foreach($rows as $r){
			fputcsv($out, [$r['ID']??'',$r['slug']??'',$r['model']??'',$r['storage']??'',$r['color']??'', implode('|',$r['missing']??[])]);
		}
		fclose($out); exit;
	}

	public function handle_reset_meta() {
		if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
		check_admin_referer('qmc_lvs_reset_meta');
		$mode = isset($_POST['reset_mode']) ? sanitize_text_field($_POST['reset_mode']) : 'soft';
		$q = new WP_Query(['post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1]);
		$cleared=0;
		foreach($q->posts as $pid){
			if ($mode==='hard'){
				delete_post_meta($pid, QMC_LVS_META_MODEL);
				delete_post_meta($pid, QMC_LVS_META_STORAGE);
				delete_post_meta($pid, QMC_LVS_META_COLOR);
				delete_post_meta($pid, QMC_LVS_META_COND);
				$cleared++;
			} else {
				// Soft: no-op placeholder, keep safe
			}
		}
		wp_safe_redirect(add_query_arg(['page'=>'qmc-lvs','tab'=>'tools','reset_done'=>$cleared], admin_url('admin.php'))); exit;
	}

	public function register_bulk_actions($actions){
		$actions['qmc_lvs_apply_model'] = __('Apply Model Slug (from input below)','qmc-lvs');
		$actions['qmc_lvs_clear_lvs'] = __('Clear Linked Variations meta','qmc-lvs');
		return $actions;
	}
	public function handle_bulk_actions($redirect, $doaction, $ids){
		if ($doaction==='qmc_lvs_apply_model'){
			$model = isset($_REQUEST['qmc_lvs_model_bulk']) ? sanitize_title($_REQUEST['qmc_lvs_model_bulk']) : '';
			if ($model){ foreach($ids as $pid){ update_post_meta($pid, QMC_LVS_META_MODEL, $model); } $redirect = add_query_arg('qmc_lvs_bulk_msg', count($ids).' updated', $redirect); }
		} elseif ($doaction==='qmc_lvs_clear_lvs'){
			foreach($ids as $pid){
				delete_post_meta($pid, QMC_LVS_META_MODEL);
				delete_post_meta($pid, QMC_LVS_META_STORAGE);
				delete_post_meta($pid, QMC_LVS_META_COLOR);
				delete_post_meta($pid, QMC_LVS_META_COND);
			}
			$redirect = add_query_arg('qmc_lvs_bulk_msg', count($ids).' cleared', $redirect);
		}
		return $redirect;
	}

	public function load_assets() {
		wp_enqueue_style('qmc-lvs', plugin_dir_url(__FILE__) . 'assets/frontend.css', [], QMC_LVS_VERSION);
		wp_enqueue_script('qmc-lvs', plugin_dir_url(__FILE__) . 'assets/js/variation-ajax.js', ['jquery'], QMC_LVS_VERSION, true);
		wp_localize_script('qmc-lvs', 'QMC_LVS', ['rest'=>esc_url_raw(rest_url('qmc-lvs/v1/switch')),'stayInPlace'=>true]);
	}

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

		$current_price = floatval( wc_get_price_to_display($product) );
		$cond_list = get_option(QMC_LVS_OPT_CONDITIONS, ['NEW','Used (A)']);

		$by_color=[]; $by_storage=[]; $by_cond=[];
		foreach ($siblings as $sid=>$s) {
			$sib = wc_get_product($sid);
			$in_stock = $sib ? $sib->is_in_stock() : false;
			$price = $sib ? floatval( wc_get_price_to_display($sib) ) : 0;
			if ($s['storage']===$storage && $s['cond']===$cond) $by_color[$s['color']] = ['id'=>$sid,'thumb'=>$this->thumb($sid),'stock'=>$in_stock,'price'=>$price];
			if ($s['color']===$color && $s['cond']===$cond) $by_storage[$s['storage']] = ['id'=>$sid,'stock'=>$in_stock,'price'=>$price];
			if ($s['color']===$color && $s['storage']===$storage) $by_cond[$s['cond']] = ['id'=>$sid,'stock'=>$in_stock,'price'=>$price];
		}
		$storages = array_keys($by_storage);
		usort($storages, function($a,$b){
			$map=['KB'=>1,'MB'=>2,'GB'=>3,'TB'=>4];
			$ua = preg_replace('/[0-9.\s]/','',strtoupper($a)) ?: 'GB';
			$ub = preg_replace('/[0-9.\s]/','',strtoupper($b)) ?: 'GB';
			$na = floatval($a); $nb = floatval($b);
			return ($map[$ua]===$map[$ub]) ? ($na <=> $nb) : ($map[$ua] <=> $map[$ub]);
		});

		echo '<div class="qmc-lvs" data-current-id="'.esc_attr($post_id).'">';

		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Color</div><div class="qmc-lvs-flex">';
		$color_keys = array_unique(array_merge(array_keys($by_color), [$color]));
		foreach ($color_keys as $label) {
			if (!$label) continue;
			$data = isset($by_color[$label]) ? $by_color[$label] : null;
			$is_current = (strcasecmp($label,$color)===0);
			$delta = $data ? ($data['price'] - $current_price) : 0;
			$badge = $data ? ($delta>0?'+'.wc_price($delta):($delta<0?wc_price($delta):'')) : '';
			$classes = 'qmc-card'.($is_current?' active':'').(($data && !$data['stock'])?' disabled':'');
			$url = $data ? get_permalink($data['id']) : '#';
			$thumb = $data ? $data['thumb'] : $this->thumb($post_id);
			echo '<a class="'.esc_attr($classes).'" href="'.esc_url($url).'" data-qmc-link="1" data-product="'.esc_attr($data['id'] ?? 0).'">';
			echo '<span class="qmc-thumb">'.$thumb.'</span><span class="qmc-label">'.esc_html($label).'</span>';
			if ($badge) echo '<span class="qmc-badge">'.$badge.'</span>';
			if ($data && !$data['stock']) echo '<span class="qmc-oos">Sold out</span>';
			echo '</a>';
		}
		echo '</div></div>';

		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Storage</div><div class="qmc-lvs-flex">';
		if (empty($storages)) $storages = [$storage];
		foreach ($storages as $st) {
			$data = isset($by_storage[$st]) ? $by_storage[$st] : null;
			$is_current = (strcasecmp($st,$storage)===0);
			$delta = $data ? ($data['price'] - $current_price) : 0;
			$badge = $data ? ($delta>0?'+'.wc_price($delta):($delta<0?wc_price($delta):'')) : '';
			$classes = 'qmc-pill'.($is_current?' active':'').(($data && !$data['stock'])?' disabled':'');
			$url = $data ? get_permalink($data['id']) : '#';
			echo '<a class="'.esc_attr($classes).'" href="'.esc_url($url).'" data-qmc-link="1" data-product="'.esc_attr($data['id'] ?? 0).'">';
			echo esc_html($st);
			if ($badge) echo ' <span class="qmc-badge">'.$badge.'</span>';
			if ($data && !$data['stock']) echo ' <span class="qmc-oos">Sold out</span>';
			echo '</a>';
		}
		echo '</div></div>';

		$cond_list = get_option(QMC_LVS_OPT_CONDITIONS, ['NEW','Used (A)']);
		$has_multi_cond = count(array_unique(array_keys($by_cond)))>0;
		if ($has_multi_cond){
			echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Condition</div><div class="qmc-lvs-seg">';
			foreach ($cond_list as $label) {
				$data = isset($by_cond[$label]) ? $by_cond[$label] : null;
				$is_current = (strcasecmp($label, $cond)===0);
				$url = $data ? get_permalink($data['id']) : '#';
				$class = $is_current ? 'active' : '';
				$attr = ($data || $is_current) ? '' : ' aria-disabled="true" style="opacity:.5;pointer-events:none"';
				echo '<a class="'.esc_attr($class).'" href="'.esc_url($url).'" data-qmc-link="1" data-product="'.esc_attr($data['id'] ?? 0).'"'.$attr.'>'.esc_html($label).'</a>';
			}
			echo '</div></div>';
		}
		echo '</div>';
	}

	private function thumb($pid){
		$tid = get_post_thumbnail_id($pid);
		if (!$tid) return '<span class="qmc-noimg">No image</span>';
		return wp_get_attachment_image($tid, 'thumbnail', false, ['class'=>'qmc-mini']);
	}

	private function get_siblings($model_slug) {
		$q = new WP_Query(['post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'meta_query'=>[['key'=>QMC_LVS_META_MODEL,'value'=>$model_slug]]]);
		$out=[]; foreach($q->posts as $pid){ $out[$pid]=['storage'=>get_post_meta($pid,QMC_LVS_META_STORAGE,true),'color'=>get_post_meta($pid,QMC_LVS_META_COLOR,true),'cond'=>get_post_meta($pid,QMC_LVS_META_COND,true)]; }
		return $out;
	}

	public function rest_switch_product($req){
		$pid = absint($req->get_param('id'));
		if (!$pid) return new WP_Error('no_id','Missing id', ['status'=>400]);
		$post = get_post($pid);
		if (!$post || $post->post_type!=='product') return new WP_Error('bad_id','Not a product', ['status'=>404]);
		$product = wc_get_product($pid);

		ob_start(); echo '<h1 class="product_title entry-title">'.esc_html(get_the_title($pid)).'</h1>'; $title_html = ob_get_clean();
		ob_start(); echo wc_get_price_html($product); $price_html = ob_get_clean();
		ob_start(); $thumb = get_post_thumbnail_id($pid); if ($thumb) echo wp_get_attachment_image($thumb, 'large', false, ['class'=>'qmc-main-img']); $gallery_html = ob_get_clean();
		ob_start(); $GLOBALS['post']=get_post($pid); setup_postdata($GLOBALS['post']); wc_get_template('single-product/add-to-cart/simple.php', ['product'=>$product]); $cart_html = ob_get_clean(); wp_reset_postdata();

		return ['id'=>$pid,'permalink'=>get_permalink($pid),'title_html'=>$title_html,'price_html'=>$price_html,'gallery_html'=>$gallery_html,'cart_html'=>$cart_html,'sku'=>$product->get_sku(),'in_stock'=>$product->is_in_stock()];
	}

	private function parse_from_slug($slug) {
		$slug = trim($slug);
		if ($slug === '') return ['model'=>'','storage'=>'','color'=>''];
		$parts = array_values(array_filter(explode('-', strtolower($slug))));
		$storage_idx=-1; $storage='';
		for ($i=0; $i<count($parts); $i++) {
			if (preg_match('/^[0-9]+(kb|mb|gb|tb)$/i', $parts[$i])) { $storage_idx=$i; $storage=strtoupper($parts[$i]); break; }
		}
		if ($storage_idx===-1) {
			for ($i=0; $i<count($parts); $i++) {
				if (preg_match('/^[0-9]+(\s)?(kb|mb|gb|tb)$/i', $parts[$i])) { $storage_idx=$i; $storage=strtoupper(preg_replace('/\s+/', '', $parts[$i])); break; }
			}
		}
		$start=0; $brands=['apple','samsung','xiaomi','google','huawei','oneplus','nokia','sony','motorola','oppo','vivo','realme'];
		if (isset($parts[0]) && in_array($parts[0],$brands,true)) $start=1;
		$model_tokens = $storage_idx>$start ? array_slice($parts,$start,$storage_idx-$start) : array_slice($parts,$start,max(1,count($parts)-2-$start));
		$model_slug = implode('-', $model_tokens);
		$color_tokens = ($storage_idx>=0 && $storage_idx<count($parts)-1) ? array_slice($parts,$storage_idx+1) : array_slice($parts,-2);
		$color_label = implode(' ', array_map('ucfirst', $color_tokens));
		return ['model'=>sanitize_title($model_slug), 'storage'=>$storage, 'color'=>trim($color_label)];
	}
}
new QMC_Linked_Variations_Simple();
