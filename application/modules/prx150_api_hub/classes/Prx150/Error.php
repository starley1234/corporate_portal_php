<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичная ошибка машинного API: стабильный код + HTTP-статус.
 *
 * Частный случай `Prx_DomainError`: тот же код/статус/детали и payload
 * `{"error":{"code","details"}}`, но с явным намерением «это ответ машинного
 * канала». Сервисы модулей бросают `Prx_DomainError` (или своего наследника) и
 * не зависят от prx150; `Controller_Prx150_Machine::respond()` ловит общий
 * `Prx_DomainError`, поэтому ловит и то, и другое.
 */
class Prx150_Error extends Prx_DomainError {}
