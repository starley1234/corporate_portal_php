<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Ошибка сервиса распознавания. Наследует контракт машинного API, поэтому
 * Controller_Prx150_Machine превращает её в JSON с тем же HTTP-статусом.
 */
class Prx450_Error extends Prx150_Error {}
