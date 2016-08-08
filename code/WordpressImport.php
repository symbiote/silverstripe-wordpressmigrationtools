<?php 

/** 
 * Utility class
 */
class WordpressImport extends Object {
	/**
	 * Support for json_last_error_msg in PHP 5.3
	 */
	public static function json_last_error_msg() {
		$json_error_msg = 'Unknown error';
		if (function_exists('json_last_error_msg')) {
			$json_error_msg = json_last_error_msg();
		} else {
			$json_error_code = json_last_error();
			if (isset(static::$json_error_msg[$json_error_code])) {
				$json_error_msg = static::$json_error_msg[$json_error_code];
			}
		}
		return $json_error_msg;
	}

	/**
	 * Encodes an array of data to be UTF8 over using html entities
	 * 
	 * @var array
	 */
	public static function utf8_json_encode($arr, $options = 0, $depth = 512) {
		// NOTE(Jake): Might be able to get more speed out of this by making it just json_encode
		//			   and if it fails with JSON_ERROR_UTF8, then do the recursive walk
		$utf8_arr = $arr;
		array_walk_recursive($utf8_arr, array(__CLASS__, '_utf8_json_encode_recursive'));
		$result = json_encode($utf8_arr, $options, $depth);
		return $result;
	}
	public static function _utf8_json_encode_recursive(&$item, $key) {
	    $item = utf8_encode($item);
	}
}