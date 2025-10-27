<?php

/*
 * Plugin Name: Linked Variations for Simple Products
 * Plugin URI: https://Gstore.ge
 * Description: Apple-style selectors for Color, Storage, and Condition that link between separate simple products.
 * Version: 1.1.0
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
// DEBUG LOGGER
// =====================================================
define('QMC_LVS_DEBUG', true);

function qmc_lvs_log($msg) {
    if (!QMC_LVS_DEBUG) return;

    $dir = plugin_dir_path(__FILE__) . 'logs/';
    if (!file_exists($dir)) wp_mkdir_p($dir);

    $file = $dir . 'debug.log';
    $line = '['.date('Y-m-d H:i:s').'] ' . (is_scalar($msg) ? $msg : print_r($msg, true));
    file_put_contents($file, $line . "\n", FILE_APPEND);
}

// =====================================================
// CLASS
// =====================================================
class QMC_Linked_Variations_Simple {

    const OPT_CONDITIONS = 'qmc_lvs_conditions';
    const META_MODEL   = '_qmc_model';
    const META_STORAGE = '_qmc_storage';
    const META_COLOR   = '_qmc_color';
    const META_COND    = '_qmc_condition';

    public function __construct() {

        // Admin
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_default_conditions']);
        add_action('add_meta_boxes', [$this, 'add_meta']);
        add_action('save_post_product', [$this, 'save_meta']);

        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'load_assets']);
        add_action('woocommerce_single_product_summary', [$this, 'render_selectors'], 15);
    }

    // =====================================================
    // ADMIN UI
    // =====================================================
    public function admin_menu() {
        add_menu_page(
                'Linked Variations',
                'Linked Variations',
                'manage_woocommerce',
                'qmc-lvs',
                [$this, 'settings_page'],
                'dashicons-screenoptions',
                56
        );
    }

    public function settings_page() {
        require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
    }

    public function register_default_conditions() {
        if (!get_option(self::OPT_CONDITIONS)) {
            update_option(self::OPT_CONDITIONS, ['NEW', 'Used (A)']);
        }
    }

    // =====================================================
    // META BOX
    // =====================================================
    public function add_meta() {
        add_meta_box('qmc_lvs_meta', 'Linked Variations', [$this,'meta_html'], 'product', 'side');
    }

    public function meta_html($post) {
        wp_nonce_field('qmc_lvs', 'qmc_lvs_nonce');

        $model   = get_post_meta($post->ID, self::META_MODEL, true);
        $storage = get_post_meta($post->ID, self::META_STORAGE, true);
        $color   = get_post_meta($post->ID, self::META_COLOR, true);
        $cond    = get_post_meta($post->ID, self::META_COND, true);

        // Auto-suggest model from slug
        if (!$model) $model = sanitize_title($post->post_name);

        echo '<p><b>Model Slug</b><br><input name="qmc_model" style="width:100%" value="'.esc_attr($model).'"></p>';

        echo '<p><b>Storage</b><br><input name="qmc_storage" style="width:100%" placeholder="128GB, 256GB" value="'.esc_attr($storage).'"></p>';

        echo '<p><b>Color</b><br><input name="qmc_color" style="width:100%" placeholder="Black, Gold" value="'.esc_attr($color).'"></p>';

        $conds = get_option(self::OPT_CONDITIONS);
        echo '<p><b>Condition</b><br><select name="qmc_condition" style="width:100%"><option></option>';
        foreach($conds as $c) echo '<option '.selected($cond,$c,false).'>'.$c.'</option>';
        echo '</select></p>';
    }

    public function save_meta($post_id) {
        if (!isset($_POST['qmc_lvs_nonce'])) return;
        if (!wp_verify_nonce($_POST['qmc_lvs_nonce'], 'qmc_lvs')) return;

        update_post_meta($post_id, self::META_MODEL, sanitize_title($_POST['qmc_model']));
        update_post_meta($post_id, self::META_STORAGE, sanitize_text_field($_POST['qmc_storage']));
        update_post_meta($post_id, self::META_COLOR, sanitize_text_field($_POST['qmc_color']));
        update_post_meta($post_id, self::META_COND, sanitize_text_field($_POST['qmc_condition']));
    }

    // =====================================================
    // FRONTEND UI
    // =====================================================
    public function load_assets() {
        wp_enqueue_style('qmc-lvs', plugin_dir_url(__FILE__) . 'assets/frontend.css');
    }

    public function render_selectors() {
        global $product;
        if (!$product || $product->get_type() !== 'simple') return;

        $post_id = $product->get_id();
        $model = get_post_meta($post_id,self::META_MODEL,true);
        if (!$model) return;

        $storage = get_post_meta($post_id, self::META_STORAGE, true);
        $color   = get_post_meta($post_id, self::META_COLOR, true);
        $cond    = get_post_meta($post_id, self::META_COND, true);

        $siblings = $this->get_siblings($model);
        if (!$siblings) return;

        qmc_lvs_log('Siblings found: '.count($siblings));

        echo '<div class="qmc-lvs">';
        $this->render_group($siblings,$post_id,'color',$color,'Colors');
        $this->render_group($siblings,$post_id,'storage',$storage,'Storage');
        $this->render_group($siblings,$post_id,'cond',$cond,'Condition');
        echo '</div>';
    }

    private function render_group($siblings,$current,$key,$val,$title) {
        echo "<div class='qmc-lvs-group'><strong>$title</strong><br>";
        foreach ($siblings as $sid=>$data) {
            if (!$data[$key]) continue;

            $url = get_permalink($sid);
            $class = ($sid==$current?'active':'');
            echo "<a href='$url' class='qmc-lvs-pill $class'>".$data[$key]."</a>";
        }
        echo "</div>";
    }

    private function get_siblings($model) {
        $q = new WP_Query([
                'post_type'=>'product',
                'post_status'=>'publish',
                'posts_per_page'=>-1,
                'fields'=>'ids',
                'meta_query'=>[
                        ['key'=> self::META_MODEL, 'value'=>$model]
                ]
        ]);
        $out = [];
        foreach($q->posts as $pid) {
            $out[$pid] = [
                    'storage'=> get_post_meta($pid,self::META_STORAGE,true),
                    'color'  => get_post_meta($pid,self::META_COLOR,true),
                    'cond'   => get_post_meta($pid,self::META_COND,true),
            ];
        }
        return $out;
    }
}
new QMC_Linked_Variations_Simple();
