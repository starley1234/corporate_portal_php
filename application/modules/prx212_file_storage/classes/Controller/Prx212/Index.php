<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Controller_Prx212_Index
 * 
 * @package Prx212
 * @version 1.0.0
 * 
 * Основной контроллер модуля хранилища:
 * - Отображение списка файлов с пагинацией
 * - Поиск файлов по имени и метаданным
 * - Интерфейс управления (просмотр, загрузка через HMVC)
 * 
 * Требуемые права доступа: prx212_storage.view_files, prx212_storage.upload, prx212_storage.manage_settings
 */
class Controller_Prx212_Index extends Controller_Prx212_Main
{
    /**
     * @var Service_Prx212_Storage Экземпляр сервиса хранилища
     */
    protected $storage_service;
    
    /**
     * @var int Размер страницы по умолчанию
     */
    const DEFAULT_PER_PAGE = 20;
    
    /**
     * Выполняется перед каждым action
     * Инициализирует сервис и проверяет права доступа
     */
    public function before()
    {
        parent::before();
        
        // Инициализация сервиса хранилища
        $this->storage_service = Service_Prx212_Storage::instance();
        
        // Установка мета-данных по умолчанию
        $this->_meta['title'] = 'Файловый менеджер';
        $this->_meta['description'] = 'Управление файлами, загрузка, поиск и контроль доступа';
    }
    
    /**
     * action_index
     * Отображение списка файлов с пагинацией и поиском
     * 
     * GET параметры:
     * - page (int) - номер страницы (по умолчанию 1)
     * - per_page (int) - количество элементов на странице (по умолчанию 20)
     * - q (string) - поисковый запрос
     * 
     * @return void
     * @throws HTTP_Exception_403 Если нет прав на просмотр файлов
     */
    public function action_index()
    {
        if (!$this->can('prx212_storage.view_files')) {
            throw new HTTP_Exception_403('Доступ запрещен. У вас нет прав на просмотр файлов.');
        }

        $query_params = $this->request->query();
        
        $page = (int) Arr::get($query_params, 'page', 1);
        $per_page = (int) Arr::get($query_params, 'per_page', self::DEFAULT_PER_PAGE);
        $search = trim((string) Arr::get($query_params, 'q'));
        
        $result = $this->storage_service->get_files_list([
            'user_id' => $this->_current_user ? $this->_current_user->id : null,
            'search' => $search,
            'page' => $page,
            'per_page' => $per_page,
            'with_permissions' => true
        ]);
        
        $stats = $this->storage_service->get_storage_stats([
            'user_id' => $this->_current_user ? $this->_current_user->id : null
        ]);
        
        $view = View::factory('prx212/storage/index')
            ->set('files', $result['items'])
            ->set('total_files', $result['total'])
            ->set('current_page', $page)
            ->set('per_page', $per_page)
            ->set('total_pages', ceil($result['total'] / $per_page))
            ->set('stats', $stats)
            ->set('search', $search)
            ->set('current_user', $this->_current_user)
            ->set('can_upload', $this->can('prx212_storage.upload'))
            ->set('can_view_settings', $this->can('prx212_storage.manage_settings'));
        
        if ($search) {
            $this->_meta['title'] = 'Поиск: ' . HTML::chars($search) . ' - Файловый менеджер';
        }
        
        $this->template->content = $view;
    }
}
