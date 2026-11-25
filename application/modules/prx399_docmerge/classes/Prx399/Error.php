<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Ошибки модуля prx399. message = машинный код ('contract_not_found'), который
 * вид-словарь переводит в человеческий текст; code = HTTP-статус. Такой обмен
 * «кодом, а не фразой» позволяет один словарь на UI и API (как Prx396_Error).
 */
class Prx399_Error extends Exception {}
