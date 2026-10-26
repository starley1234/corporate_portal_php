<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Controller_Prl212_Main - Абстрактный контроллер модуля хранилища.
 * 
 * Базовый класс для всех контроллеров модуля prl212_storage.
 * Обеспечивает проверку прав доступа для ролей 212_storage_*.
 * 
 * @package Prl212
 * @version 1.0.1
 */
abstract class Controller_Prl212_Main extends Controller_Base
{
    /**
     * Выполняется перед каждым action
     * Проверяет права доступа и устанавливает общие метаданные
     */
    public function before()
    {
        parent::before();
        
        // Проверка авторизации
        if (!$this->_current_user) {
            $this->redirect('login');
        }
        
        // Установка метаданных модуля
        $this->_meta['title'] = 'Хранилище файлов';
        $this->_meta['description'] = 'Управление файлами, загрузка, скачивание, настройка хранилищ';
        $this->_meta['keywords'] = 'файлы, хранилище, загрузка, скачивание, документы';

        // Установка верхнего меню модуля
        $this->set_storage_menu();
    }

    /**
     * Формирует и устанавливает верхнее меню для модуля хранилища
     */
    protected function set_storage_menu()
    {
        $menu = [
            [
                'title' => 'Файлы',
                'href'  => '/prl212_index',
                'icon'  => 'fas fa-cloud-upload-alt',
                'acl'   => 'prl212_storage.view_files'
            ],
            [
                'title' => 'Общие файлы',
                'href'  => '/prl212_storage/public',
                'icon'  => 'fas fa-share-alt',
                'acl'   => 'prl212_storage.view_public_files'
            ],
            [
                'title' => 'Загрузить',
                'href'  => '/prl212_storage/upload',
                'icon'  => 'fas fa-cloud-upload-alt',
                'acl'   => 'prl212_storage.upload'
            ],
            [
                'title' => 'Настройки',
                'href'  => '/prl212_storage/settings',
                'icon'  => 'fas fa-cogs',
                'acl'   => 'prl212_storage.manage_settings'
            ]
        ];

        $this->set_menu($menu);
    }

    /**
     * Возвращает экземпляр сервиса хранилища
     * 
     * @return Service_Prl212_Storage
     */
    protected function get_storage_service()
    {
        return Service_Prl212_Storage::instance();
    }

    /**
     * Возвращает конфигурацию модуля
     * 
     * @return array
     */
    protected function get_module_config()
    {
        $config = Kohana::$config->load('prl212_storage');
        return $config->as_array();
    }
}