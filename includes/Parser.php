<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * v3.0.1-proven flexible parser (restored)
 */
class QMC_LVS_Parser {
	/**
	 * Parse product slug flexibly into model/storage/color/cond.
	 * Example: apple-iphone-16-pro-256gb-desert-titanium
	 */
	public static function parse_slug_flexible( $slug ) {
		$out = ['model'=>'','storage'=>'','color'=>'','cond'=>''];
		if ( ! $slug ) return $out;

		$parts = preg_split('/-+/', strtolower($slug));
		$storage = '';
		$pos = -1;
		foreach ($parts as $i => $p) {
			if ( preg_match('/^(\d+(?:\.\d+)?)(kb|mb|gb|tb)$/i', $p, $m) ) {
				$unit = strtoupper($m[2]);
				if ($unit==='KB'||$unit==='MB') continue;
				$storage = ($unit==='TB' ? $m[1].'TB' : $m[1].'GB');
				$pos = $i;
				break;
			}
		}
		if ($storage) $out['storage'] = $storage;

		// color tokens after storage, else last up to 2 tokens
		if ($pos >= 0) {
			$color_tokens = array_slice($parts, $pos+1);
		} else {
			$color_tokens = array_slice($parts, -2);
		}
		$color_label = trim( implode(' ', array_map(function($t){ return ucfirst($t); }, $color_tokens)) );
		$out['color'] = $color_label;

		// model before storage (skip common brands)
		$model_tokens = $pos >= 0 ? array_slice($parts, 0, $pos) : array_slice($parts, 0, max(0, count($parts)-2));
		$brands = ['apple','samsung','xiaomi','redmi','huawei','poco','nokia','google','oneplus'];
		$model_tokens = array_values(array_filter($model_tokens, function($t) use ($brands){ return ! in_array($t, $brands, true); }));
		$out['model'] = sanitize_title( implode('-', $model_tokens) );

		// light condition guess
		$slug_l = strtolower($slug);
		if (strpos($slug_l,'used')!==false || strpos($slug_l,'grade-a')!==false) $out['cond'] = 'Used (A)';
		elseif (strpos($slug_l,'new')!==false) $out['cond'] = 'NEW';

		return $out;
	}
}
