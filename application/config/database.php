<?php defined('SYSPATH') OR die('No direct access allowed.');

return array
(

	'default' => array(
        'type'       => 'PostgreSQL',
        'connection' => array(
			'hostname'   => getenv('DB_DEFAULT_HOST'),
			'port'   	 => getenv('DB_DEFAULT_PORT'),
            'database'   => getenv('DB_DEFAULT_NAME'),
            'username'   => getenv('DB_DEFAULT_USER'),
            'password'   => getenv('DB_DEFAULT_PASS'),
			'persistent' => TRUE,
        ),
        // 'primary_key'  => 'id',   // Column to return from INSERT queries, see #2188 and #2273
		'primary_key'  => NULL, 
		'schema'       => getenv('DB_DEFAULT_SCHEMA'), // Укажите здесь нужную схему
		'table_prefix' => '',
        'charset'      => 'utf8',
        'caching'      => FALSE,
    ),
);
