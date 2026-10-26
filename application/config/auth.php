<?php defined('SYSPATH') OR die('No direct access allowed.');

return array(

	'driver'       => 'ORM',
	'hash_method'  => 'sha256',
	'hash_key'     => getenv('AUTH_HASH_KEY'),
	'lifetime'     => 1209600,
	'session_type' => Session::$default,
	//'session_type' => 'cookie', 
	'session_key'  => 'auth_user',

	// Username/password combinations for the Auth File driver
	'users' => array(
		// 'admin' => '11111111111111',
	),

);
