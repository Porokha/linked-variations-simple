<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class QMC_LVS_Parser {
	public static function parse_slug_flexible( $slug ) {
		$out = ['model'=>'','storage'=>'','color'=>'','cond'=>''];
		if ( ! $slug ) return $out;
		$parts = preg_split('/-+/', strtolower($slug));
		$storage = ''; $pos = -1;
		foreach ($parts as $i=>$p){
			if (preg_match('/^(\d+(?:\.\d+)?)(kb|mb|gb|tb)$/i',$p,$m)){
				$unit = strtoupper($m[2]);
				if ($unit==='KB'||$unit==='MB') continue;
				$storage = ($unit==='TB' ? $m[1].'TB' : $m[1].'GB');
				$pos = $i; break;
			}
		}
		if ($storage) $out['storage']=$storage;
		$color_tokens = $pos>=0 ? array_slice($parts,$pos+1) : array_slice($parts,-2);
		$out['color'] = trim( implode(' ', array_map('ucfirst',$color_tokens)) );
		$model_tokens = $pos>=0 ? array_slice($parts,0,$pos) : array_slice($parts,0,max(0,count($parts)-2));
		$brands = ['apple','samsung','xiaomi','redmi','huawei','poco','nokia','google','oneplus'];
		$model_tokens = array_values(array_filter($model_tokens, function($t) use ($brands){ return !in_array($t,$brands,true); }));
		$out['model'] = sanitize_title( implode('-', $model_tokens) );
		$slug_l = strtolower($slug);
		if (strpos($slug_l,'used')!==false || strpos($slug_l,'grade-a')!==false) $out['cond']='Used (A)';
		elseif (strpos($slug_l,'new')!==false) $out['cond']='NEW';
		return $out;
	}
}
