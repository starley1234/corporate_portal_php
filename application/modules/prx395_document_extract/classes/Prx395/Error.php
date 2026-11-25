<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Ошибка документного сервиса: код + HTTP-статус. Наследует нейтральную
 * `Prx_DomainError`, а не машинную `Prx150_Error`: одна и та же ошибка
 * подходит и странице, и машинному API, а модуль не зависит от prx150.
 */
class Prx395_Error extends Prx_DomainError {}
