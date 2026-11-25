<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Ошибка сервиса распознавания: код + HTTP-статус. Наследует нейтральную
 * `Prx_DomainError`, поэтому `Controller_Prx150_Machine` превращает её в JSON
 * с тем же статусом, а страница может показать текст. Модуль не зависит от prx150
 * ради класса ошибки.
 */
class Prx450_Error extends Prx_DomainError {}
