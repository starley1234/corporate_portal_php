<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ORM-модель задачи очереди (nj_120_jobs).
 *
 * Внутри модуля очереди данные чаще читаются напрямую через SQL
 * (см. Service_Nj120_JobQueue) — модель нужна для интерфейс управленияов
 * и стандартных ORM-выборок.
 */
class Model_Nj120_Job extends ORM {

    protected $_table_name = 'nj_120_jobs';
    protected $_primary_key = 'id';

    /**
     * jsonb-поля PostgreSQL — Kohana ORM не умеет их сериализовать,
     * поэтому работаем с ними как со строками JSON.
     */
    protected $_json_columns = ['payload', 'result'];

    public function rules()
    {
        return [
            'channel' => [['not_empty'], ['max_length', [':value', 128]]],
        ];
    }

    /**
     * Payload задачи как массив PHP.
     */
    public function payload_array()
    {
        return json_decode($this->payload, TRUE) ?: [];
    }

    /**
     * Результат как массив PHP.
     */
    public function result_array()
    {
        return $this->result ? (json_decode($this->result, TRUE) ?: []) : NULL;
    }
}
