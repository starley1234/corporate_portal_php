<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный код ошибки без текста документа, ключа API или ответа шлюза.
 *
 * Наследует контракт машинного API: Controller_Prx150_Machine превращает
 * её в JSON {"error":{"code","details"}} с тем же HTTP-статусом.
 */
class Prx395_Error extends Prx150_Error {}
