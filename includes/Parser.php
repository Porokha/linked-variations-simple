<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class QMC_LVS_Parser {
	/**
	 * Parse product slug flexibly into model/storage/color/cond.
	 * Example: apple-iphone-16-pro-256gb-desert-titanium
	 */
	public static function parse_slug_flexible( $slug ) {
		$out = ['model'=>'','storage'=>'','color'=>'','cond'=>''];
		if ( ! $slug ) return $out;

		$parts = explode('-', $slug);
		// find storage token like 64gb/128gb/256gb/1tb etc.
		$storage = '';
		foreach ($parts as $p) {
			if ( preg_match('/^(\\d+(?:\\.\\d+)?)(kb|mb|gb|tb)$/i', $p, $m) ) {
				$num = $m[1]; $unit = strtoupper($m[2]);
				if ($unit==='KB'||$unit==='MB') continue;
				$storage = ( $unit==='TB' ? $num.'TB' : $num.'GB' );
				break;
			}
		}
		if ($storage) $out['storage'] = $storage;

		// crude color reconstruction: everything after storage joined; otherwise last 1-3 tokens
		$storage_pos = array_search(strtolower($storage), array_map('strtolower',$parts));
		if ($storage && $storage_pos !== false) {
			$color_tokens = array_slice($parts, $storage_pos+1);
		} else {
			$color_tokens = array_slice($parts, -2);
		}
		$color_label = trim(implode(' ', array_map('ucfirst', $color_tokens)));
		$out['color'] = $color_label;

		// model: tokens before storage (skip brand)
		if ($storage && $storage_pos !== false) {
			$model_tokens = array_slice($parts, 0, $storage_pos);
		} else {
			$model_tokens = array_slice($parts, 0, max(0, count($parts)-2) );
		}
		// remove common brands
		$brands = ['apple','samsung','xiaomi','redmi','huawei','poco','nokia','google','oneplus'];
		$model_tokens = array_values(array_filter($model_tokens, function($t) use ($brands){ return ! in_array(strtolower($t), $brands, true); }));
		$out['model'] = sanitize_title( implode('-', $model_tokens) );

		// condition: default blank; leave admin to pick; or detect keywords
		$slug_l = strtolower($slug);
		if (strpos($slug_l, 'used') !== false || strpos($slug_l,'grade-a') !== false) {
			$out['cond'] = 'Used (A)';
		} elseif (strpos($slug_l, 'new') !== false) {
			$out['cond'] = 'NEW';
		}

		return $out;
	}
}
