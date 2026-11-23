<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Единственный тип исключений фасада Storage: prx212 не подключён, недопустимый
 * префикс модуля, отказ в файловом праве или сбой хранилища при записи.
 */
class Storage_Exception extends RuntimeException {}
