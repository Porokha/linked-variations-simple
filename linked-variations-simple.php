<?php

/*
 * Plugin Name: Linked Variations for Simple Products
 * Plugin URI: https://Gstore.ge
 * Description: Apple-style selectors for Color, Storage, and Condition that link between separate simple products.
 * Version: 4.0.1
 * Requires at least: 6.0
 * Requires PHP: 8.2.28
 * Tested up to: 6.8.3
 * WC requires at least: 6.0.0
 * WC tested up to: 10.3.3
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

require_once __DIR__ . '/includes/parser.php';

final class QMC_Linked_Variations_Simple {
	const META_MODEL   = '_qmc_model_slug';
	const META_STORAGE = '_qmc_storage';
	const META_COLOR   = '_qmc_color';
	const META_COND    = '_qmc_condition';

	const OPT_CONDITIONS = 'qmc_lvs_conditions';
	const LOG_DIR  = 'logs';
	const LOG_FILE = 'debug.log';

	public function __construct(){
		add_action('admin_init', [$this,'maybe_defaults']);
		add_action('admin_menu', [$this,'admin_menu']);
		add_action('admin_post_qmc_lvs_bulk_sync',  [$this,'bootstrap_then_bulk_sync']);
		add_action('admin_post_qmc_lvs_reset_meta', [$this,'handle_reset_meta']);

		add_action('add_meta_boxes',       [$this,'add_meta_box']);
		add_action('save_post_product',    [$this,'save_product_meta']);

		add_action('wp_enqueue_scripts',   [$this,'enqueue']);
		add_action('woocommerce_single_product_summary', [$this,'render_selectors'], 12);

		add_action('wp_enqueue_scripts', function(){
			if (is_product()) {
				wp_add_inline_script('qmc-lvs-js', "document.addEventListener('DOMContentLoaded',function(){var f=document.querySelector('form.cart');var b=document.querySelector('.qmc-lvs');if(f&&b&&f.parentNode){f.parentNode.insertBefore(b,f);}});");
			}
		}, 100);

		add_action('rest_api_init', function(){
			register_rest_route('qmc-lvs/v1','/switch',[
				'methods'  => 'GET',
				'callback' => [$this,'rest_switch_product'],
				'permission_callback' => '__return_true'
			]);
		});
	}

	public static function activate(){
		$dir = plugin_dir_path(__FILE__) . self::LOG_DIR;
		if ( ! file_exists($dir) ) { wp_mkdir_p($dir); }
		self::log('activate', ['version'=>'4.0.1']);
	}
	public static function deactivate(){}

	public function maybe_defaults(){
		$val = get_option(self::OPT_CONDITIONS);
		if ( empty($val) || !is_array($val) ) update_option(self::OPT_CONDITIONS, ['NEW','Used (A)']);
	}

	public static function log($tag,$data=[]){
		$file = trailingslashit(plugin_dir_path(__FILE__) . self::LOG_DIR) . self::LOG_FILE;
		@file_put_contents($file, '['.gmdate('Y-m-d H:i:s').' UTC] '.$tag.' '.wp_json_encode($data, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
	}

	public function admin_menu(){
		add_menu_page('Linked Variations','Linked Variations','manage_woocommerce','qmc-lvs',[$this,'admin_logs_page'],'dashicons-screenoptions',59);
		add_submenu_page('qmc-lvs','Bulk Smart Sync','Bulk Smart Sync','manage_woocommerce','qmc-lvs-bulk',[$this,'bulk_page']);
		add_submenu_page('qmc-lvs','Reset/Clear','Reset/Clear','manage_woocommerce','qmc-lvs-reset',[$this,'reset_page']);
	}
	public function admin_logs_page(){ include __DIR__.'/admin/page-main.php'; }

	public function bulk_page(){
		if (!current_user_can('manage_woocommerce')) return;
		$terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
		echo '<div class="wrap"><h1>Linked Variations → Bulk Smart Sync</h1>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
		echo '<input type="hidden" name="action" value="qmc_lvs_bulk_sync" />';
		wp_nonce_field('qmc_lvs_bulk_sync','qmc_lvs_bulk_sync');
		echo '<p><label><input type="checkbox" name="dryrun" value="1" /> Dry run (no writes)</label></p>';
		echo '<p><strong>Categories to include (optional):</strong></p><div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;max-width:520px">';
		foreach ($terms as $t) echo '<label><input type="checkbox" name="cats[]" value="'.intval($t->term_id).'"> '.esc_html($t->name).'</label>';
		echo '</div>';
		submit_button('Run Smart Sync');
		echo '</form>';
		if (isset($_GET['report'])) echo '<p><a class="button" href="'.esc_url(plugins_url('logs/'.basename($_GET['report']),__FILE__)).'">Download last CSV</a></p>';
		echo '</div>';
	}

	public function reset_page(){
		if (!current_user_can('manage_woocommerce')) return;
		$terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
		echo '<div class="wrap"><h1>Linked Variations → Reset/Clear</h1>';
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
		echo '<input type="hidden" name="action" value="qmc_lvs_reset_meta" />';
		wp_nonce_field('qmc_lvs_reset_meta','qmc_lvs_reset_meta');
		echo '<p><label><input type="checkbox" name="all" value="1" checked/> Clear ALL products</label></p>';
		echo '<p>Or select categories:</p><div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;max-width:520px">';
		foreach ($terms as $t) echo '<label><input type="checkbox" name="cats[]" value="'.intval($t->term_id).'"> '.esc_html($t->name).'</label>';
		echo '</div>';
		submit_button('Clear Meta');
		echo '</form>';
		if (isset($_GET['done'])) echo '<div class="updated"><p>Cleared '.intval($_GET['done']).' products.</p></div>';
		echo '</div>';
	}

	public function bootstrap_then_bulk_sync(){
		if ( ! class_exists('WooCommerce') ) {
			$wc = WP_PLUGIN_DIR.'/woocommerce/woocommerce.php';
			if (file_exists($wc)) include_once $wc;
		}
		if (defined('WC_ABSPATH')){
			@include_once WC_ABSPATH.'includes/wc-template-functions.php';
			@include_once WC_ABSPATH.'includes/wc-product-functions.php';
		}
		$this->handle_bulk_sync();
	}

	public function handle_bulk_sync(){
		if (!current_user_can('manage_woocommerce')) wp_die('Nope');
		if (!isset($_POST['qmc_lvs_bulk_sync']) || !wp_verify_nonce($_POST['qmc_lvs_bulk_sync'],'qmc_lvs_bulk_sync')) wp_die('Nonce');

		$dry  = !empty($_POST['dryrun']);
		$cats = isset($_POST['cats']) ? array_map('intval', (array)$_POST['cats']) : [];

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];
		if (!empty($cats)) {
			$args['tax_query'] = [['taxonomy'=>'product_cat','field'=>'term_id','terms'=>$cats]];
		}

		$q = new WP_Query($args);
		$rows = [];
		$changed_total = 0;

		if ($q->have_posts()){
			foreach ($q->posts as $pid){
				$slug   = get_post_field('post_name',$pid);
				$parsed = QMC_LVS_Parser::parse_slug_flexible($slug);

				$existing_model   = get_post_meta($pid,self::META_MODEL,true);
				$existing_storage = get_post_meta($pid,self::META_STORAGE,true);
				$existing_color   = get_post_meta($pid,self::META_COLOR,true);
				$existing_cond    = get_post_meta($pid,self::META_COND,true);

				$changed = 0;
				if (empty($existing_model)   && !empty($parsed['model']))   { if(!$dry) update_post_meta($pid,self::META_MODEL,$parsed['model']);   $changed++; }
				if (empty($existing_storage) && !empty($parsed['storage'])) { if(!$dry) update_post_meta($pid,self::META_STORAGE,$parsed['storage']); $changed++; }
				if (empty($existing_color)   && !empty($parsed['color']))   { if(!$dry) update_post_meta($pid,self::META_COLOR,$parsed['color']);   $changed++; }
				if (empty($existing_cond)    && !empty($parsed['cond']))    { if(!$dry) update_post_meta($pid,self::META_COND,$parsed['cond']);    $changed++; }

				$changed_total += $changed;
				$rows[] = [
					'id'=>$pid,'slug'=>$slug,
					'existing_model'=>$existing_model,'existing_storage'=>$existing_storage,'existing_color'=>$existing_color,'existing_cond'=>$existing_cond,
					'parsed_model'=>$parsed['model'],'parsed_storage'=>$parsed['storage'],'parsed_color'=>$parsed['color'],'parsed_cond'=>$parsed['cond'],
					'changed_fields'=>$changed,'mode'=>$dry?'DRY':'WRITE'
				];
			}
		}

		self::log('bulk_sync',['count'=>count($rows),'dry'=>$dry,'changed_total'=>$changed_total]);

		$dir = plugin_dir_path(__FILE__) . self::LOG_DIR;
		if (!file_exists($dir)) wp_mkdir_p($dir);
		$file = trailingslashit($dir) . 'sync-report-' . gmdate('Ymd-His') . '.csv';
		$fp = fopen($file,'w');
		if (!empty($rows)) {
			fputcsv($fp, array_keys($rows[0]));
			foreach ($rows as $r) fputcsv($fp, $r);
		} else {
			fputcsv($fp, ['note']); fputcsv($fp, ['No products matched criteria']);
		}
		fclose($fp);

		wp_safe_redirect( admin_url('admin.php?page=qmc-lvs-bulk&report='.urlencode(basename($file)).'&changed='.$changed_total) );
		exit;
	}

	public function handle_reset_meta(){
		if (!current_user_can('manage_woocommerce')) wp_die('Nope');
		if (!isset($_POST['qmc_lvs_reset_meta']) || !wp_verify_nonce($_POST['qmc_lvs_reset_meta'],'qmc_lvs_reset_meta')) wp_die('Nonce');

		$all  = !empty($_POST['all']);
		$cats = isset($_POST['cats']) ? array_map('intval',(array)$_POST['cats']) : [];

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];
		if (!$all && !empty($cats)) {
			$args['tax_query'] = [['taxonomy'=>'product_cat','field'=>'term_id','terms'=>$cats]];
		}
		$q = new WP_Query($args);
		$cleared = 0;
		if ($q->have_posts()){
			foreach ($q->posts as $pid){
				delete_post_meta($pid,self::META_MODEL);
				delete_post_meta($pid,self::META_STORAGE);
				delete_post_meta($pid,self::META_COLOR);
				delete_post_meta($pid,self::META_COND);
				$cleared++;
			}
		}
		self::log('reset_meta',['cleared'=>$cleared,'all'=>$all,'cats'=>$cats]);
		wp_safe_redirect( admin_url('admin.php?page=qmc-lvs-reset&done='.$cleared) );
		exit;
	}

	public function enqueue(){
		if (!is_product()) return;
		wp_register_style('qmc-lvs-css', plugins_url('assets/frontend.css', __FILE__), [], '4.0.1');
		wp_enqueue_style('qmc-lvs-css');
		wp_register_script('qmc-lvs-js', plugins_url('assets/js/variation-ajax.js', __FILE__), ['jquery'], '4.0.1', true);
		wp_localize_script('qmc-lvs-js','QMC_LVS',[
			'rest'=> esc_url_raw( rest_url('qmc-lvs/v1/switch') ),
			'nonce'=> wp_create_nonce('wp_rest')
		]);
		wp_enqueue_script('qmc-lvs-js');
	}

	public function add_meta_box(){
		add_meta_box('qmc_lvs_meta','Linked Variations (Simple)',[$this,'meta_box_html'],'product','side','default');
	}
	public function meta_box_html($post){
		wp_nonce_field('qmc_lvs_save','qmc_lvs_nonce');
		$model   = get_post_meta($post->ID,self::META_MODEL,true);
		$storage = get_post_meta($post->ID,self::META_STORAGE,true);
		$color   = get_post_meta($post->ID,self::META_COLOR,true);
		$cond    = get_post_meta($post->ID,self::META_COND,true);
		$suggested = $model ?: sanitize_title($post->post_name);
		$conds = get_option(self::OPT_CONDITIONS,['NEW','Used (A)']);

		echo '<p><strong>Model Slug</strong><br/><input type="text" name="qmc_model" value="'.esc_attr($suggested).'" style="width:100%"></p>';
		echo '<p><strong>Storage</strong><br/><input type="text" name="qmc_storage" value="'.esc_attr($storage).'" style="width:100%"></p>';
		echo '<p><strong>Color</strong><br/><input type="text" name="qmc_color" value="'.esc_attr($color).'" style="width:100%"></p>';
		echo '<p><strong>Condition</strong><br/><select name="qmc_condition" style="width:100%"><option value="">— Select —</option>';
		foreach ($conds as $c) echo '<option value="'.esc_attr($c).'" '.selected($cond,$c,false).'>'.esc_html($c).'</option>';
		echo '</select></p>';
	}
	public function save_product_meta($post_id){
		if (!isset($_POST['qmc_lvs_nonce']) || !wp_verify_nonce($_POST['qmc_lvs_nonce'],'qmc_lvs_save')) return;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (!current_user_can('edit_post',$post_id)) return;

		update_post_meta($post_id,self::META_MODEL,  isset($_POST['qmc_model'])?sanitize_title(wp_unslash($_POST['qmc_model'])):'');
		update_post_meta($post_id,self::META_STORAGE,isset($_POST['qmc_storage'])?sanitize_text_field(wp_unslash($_POST['qmc_storage'])):'');
		update_post_meta($post_id,self::META_COLOR,  isset($_POST['qmc_color'])?sanitize_text_field(wp_unslash($_POST['qmc_color'])):'');
		update_post_meta($post_id,self::META_COND,   isset($_POST['qmc_condition'])?sanitize_text_field(wp_unslash($_POST['qmc_condition'])):'');
		self::log('save_meta',['id'=>$post_id]);
	}

	public function render_selectors(){
		if (!function_exists('wc_get_product')) return;
		global $product;
		if (!$product || !$product->is_type('simple')) return;

		$post_id = $product->get_id();
		$model   = get_post_meta($post_id,self::META_MODEL,true);
		$storage = get_post_meta($post_id,self::META_STORAGE,true);
		$color   = get_post_meta($post_id,self::META_COLOR,true);
		$cond    = get_post_meta($post_id,self::META_COND,true);
		if (!$model) return;

		$siblings = $this->get_siblings($model);
		if (empty($siblings)) return;

		$by_color=[]; $by_storage=[]; $by_cond=[];
		foreach ($siblings as $sid=>$s){
			if ($s['storage']===$storage && $s['cond']===$cond) $by_color[$s['color']]=$sid;
			if ($s['color']===$color && $s['cond']===$cond) $by_storage[$s['storage']]=$sid;
			if ($s['color']===$color && $s['storage']===$storage) $by_cond[$s['cond']]=$sid;
		}
		$cond_list = get_option(self::OPT_CONDITIONS,['NEW','Used (A)']);

		echo '<div class="qmc-lvs" data-current-id="'.esc_attr($post_id).'">';

		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Condition</div><div class="qmc-lvs-seg">';
		foreach ($cond_list as $label){
			$is_current = (strcasecmp($label,$cond)===0);
			$tid = isset($by_cond[$label]) ? intval($by_cond[$label]) : 0;
			$url = $is_current ? get_permalink($post_id) : ( $tid ? get_permalink($tid) : '#' );
			$attr = ($tid || $is_current) ? '' : ' aria-disabled="true" style="opacity:.5;pointer-events:none"';
			echo '<a class="'.($is_current?'is-active':'').'" href="'.esc_url($url).'" data-qmc-link="1" data-product="'.esc_attr($tid?:$post_id).'"'.$attr.'>'.esc_html($label).'</a>';
		}
		echo '</div></div>';

		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Storage</div><div class="qmc-lvs-flex qmc-storage">';
		if (empty($by_storage)) $by_storage[$storage]=$post_id;
		$keys = array_keys($by_storage);
		usort($keys,function($a,$b){$m=['KB'=>1,'MB'=>2,'GB'=>3,'TB'=>4];$pa=strtoupper($a);$pb=strtoupper($b);$na=floatval($pa);$nb=floatval($pb);$ua=preg_replace('/[0-9.\s]/','',$pa)?:'GB';$ub=preg_replace('/[0-9.\s]/','',$pb)?:'GB';if($m[$ua]==$m[$ub])return $na<=>$nb;return $m[$ua]<=>$m[$ub];});
		foreach ($keys as $st){
			$tid = intval($by_storage[$st]);
			$is_current = (strcasecmp($st,$storage)===0);
			$url = $is_current ? get_permalink($post_id) : get_permalink($tid);
			echo '<a class="qmc-pill '.($is_current?'active':'').'" href="'.esc_url($url).'" data-qmc-link="1" data-product="'.esc_attr($tid).'">'.esc_html($st).'</a>';
		}
		echo '</div></div>';

		echo '<div class="qmc-lvs-group"><div class="qmc-lvs-title">Color</div><div class="qmc-lvs-flex qmc-colors">';
		$present = array_unique(array_merge(array_keys($by_color), [$color]));
		foreach ($present as $cname){
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

		echo '</div>';
	}

	private function color_hex_from_label($name){
		$n = strtolower($name);
		$map = [
			'black'=>'#1f2937','graphite'=>'#1f2937','space'=>'#1f2937','midnight'=>'#111827',
			'white'=>'#e5e7eb','silver'=>'#d4d4d8','starlight'=>'#e5e5da',
			'gold'=>'#e6c65b','desert'=>'#c0ac7a','titanium'=>'#9aa0a6','blue'=>'#3b82f6',
			'purple'=>'#6d5bd0','green'=>'#10b981','red'=>'#ef4444'
		];
		foreach ($map as $k=>$hex) if (strpos($n,$k)!==false) return $hex;
		return '#3b82f6';
	}

	private function get_siblings($model_slug){
		$q = new WP_Query([
			'post_type'=>'product','post_status'=>'publish','posts_per_page'=>250,'fields'=>'ids',
			'meta_query'=>[['key'=>self::META_MODEL,'value'=>$model_slug]],
			'tax_query'=>[['taxonomy'=>'product_type','field'=>'slug','terms'=>['simple']]]
		]);
		if (!$q->have_posts()) return [];
		$out=[];
		foreach ($q->posts as $pid){
			$out[$pid] = [
				'storage'=> get_post_meta($pid,self::META_STORAGE,true) ?: '',
				'color'  => get_post_meta($pid,self::META_COLOR,true)   ?: '',
				'cond'   => get_post_meta($pid,self::META_COND,true)    ?: '',
			];
		}
		return $out;
	}

	public function rest_switch_product( WP_REST_Request $request ){
		if ( ! class_exists('WooCommerce') ) {
			$wc = WP_PLUGIN_DIR.'/woocommerce/woocommerce.php';
			if (file_exists($wc)) include_once $wc;
		}
		if (defined('WC_ABSPATH')){
			@include_once WC_ABSPATH.'includes/wc-template-functions.php';
			@include_once WC_ABSPATH.'includes/wc-product-functions.php';
		}
		if (!function_exists('wc_get_product')){
			self::log('rest_missing_wc', []);
			return new WP_REST_Response(['error'=>'woocommerce_unavailable'], 500);
		}
		$id = intval($request->get_param('id'));
		if ($id<=0) return new WP_REST_Response(['error'=>'missing_id'],400);
		$product = wc_get_product($id);
		if (!$product || !$product->is_type('simple')) return new WP_REST_Response(['error'=>'not_simple_or_missing'],400);

		$payload = [
			'id'        => $product->get_id(),
			'title'     => $product->get_name(),
			'price'     => method_exists($product,'get_price_html') ? $product->get_price_html() : '',
			'permalink' => get_permalink($product->get_id()),
			'in_stock'  => $product->is_in_stock(),
			'cart_id'   => $product->get_id(),
			'thumb'     => get_the_post_thumbnail_url($product->get_id(),'large'),
		];
		self::log('ajax_switch',$payload);
		return new WP_REST_Response($payload,200);
	}
}

register_activation_hook(__FILE__, ['QMC_Linked_Variations_Simple','activate']);
register_deactivation_hook(__FILE__, ['QMC_Linked_Variations_Simple','deactivate']);

new QMC_Linked_Variations_Simple();
