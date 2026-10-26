<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Бюджет токенов исчерпан: JobQueue::push() отказался ставить задачу.
 * Сообщение — готовый текст для человека; state() — цифры для интерфейса.
 */
class Nj120_Budget_Exception extends Exception
{
	protected $_state = [];

	public function __construct($message, array $state = [])
	{
		parent::__construct($message, 429);
		$this->_state = $state;
	}

	public function state()
	{
		return $this->_state;
	}
}
