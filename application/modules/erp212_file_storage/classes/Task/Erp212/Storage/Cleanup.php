<?php

/**
 * CLI задача для удаления просроченных временных ссылок и временных файлов
 * 
 * Запуск: php /path/to/yiic storage cleanup
 */

class Cleanup extends CConsoleCommand 
{
    /**
     * @var string Название модуля
     */
    private $moduleId = 'erp212_storage';
    
    /**
     * @var int Количество дней хранения временных файлов (по умолчанию 7)
     */
    private $retentionDays = 7;
    
    /**
     * @var int Лимит обрабатываемых записей за один запуск
     */
    private $batchLimit = 1000;

    /**
     * Выполнение задачи очистки
     * 
     * @param array $args Аргументы командной строки
     *   --days=<int>   Количество дней хранения (переопределяет значение по умолчанию)
     *   --limit=<int>  Лимит записей за один запуск
     */
    public function run($args)
    {
        $this->parseArguments($args);
        
        $this->log("Начинаю очистку временных ссылок и файлов...");
        $this->log("Период хранения: {$this->retentionDays} дней");
        $this->log("Лимит на запуск: {$this->batchLimit} записей");
        
        $deletedLinks = $this->cleanupExpiredLinks();
        $deletedFiles = $this->cleanupOrphanedFiles();
        
        $this->log("Очистка завершена.");
        $this->log("Удалено временных ссылок: {$deletedLinks}");
        $this->log("Удалено временных файлов: {$deletedFiles}");
    }
    
    /**
     * Очистка просроченных временных ссылок из БД
     * 
     * @return int Количество удаленных записей
     */
    private function cleanupExpiredLinks()
    {
        $criteria = new CDbCriteria();
        $criteria->condition = 'expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)';
        $criteria->limit = $this->batchLimit;
        
        $count = 0;
        $links = TempLink::model()->findAll($criteria);
        
        foreach ($links as $link) {
            try {
                $link->delete();
                $count++;
            } catch (Exception $e) {
                $this->log("Ошибка удаления ссылки ID {$link->id}: " . $e->getMessage(), CLogger::LEVEL_ERROR);
            }
        }
        
        return $count;
    }
    
    /**
     * Очистка временных файлов без привязки к активным ссылкам
     * 
     * @return int Количество удаленных файлов
     */
    private function cleanupOrphanedFiles()
    {
        $storage = Yii::app()->getModule($this->moduleId)->storage;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$this->retentionDays} days"));
        
        // Получаем список временных файлов старше retentionDays
        $criteria = new CDbCriteria();
        $criteria->select = 'file_path, created_at';
        $criteria->condition = 'is_temporary = 1 AND created_at < :cutoff';
        $criteria->params = array(':cutoff' => $cutoffDate);
        $criteria->limit = $this->batchLimit;
        
        $count = 0;
        $files = File::model()->findAll($criteria);
        
        foreach ($files as $file) {
            try {
                // Проверяем, нет ли активных ссылок на этот файл
                $activeLinks = TempLink::model()->countByAttributes(array(
                    'file_path' => $file->file_path,
                    'expires_at >' => date('Y-m-d H:i:s')
                ));
                
                if ($activeLinks == 0) {
                    $storage->delete($file->file_path);
                    $file->delete();
                    $count++;
                }
            } catch (Exception $e) {
                $this->log("Ошибка удаления файла {$file->file_path}: " . $e->getMessage(), CLogger::LEVEL_ERROR);
            }
        }
        
        return $count;
    }
    
    /**
     * Парсинг аргументов командной строки
     * 
     * @param array $args Аргументы
     */
    private function parseArguments($args)
    {
        foreach ($args as $arg) {
            if (strpos($arg, '--days=') === 0) {
                $this->retentionDays = (int)substr($arg, 7);
            } elseif (strpos($arg, '--limit=') === 0) {
                $this->batchLimit = (int)substr($arg, 8);
            }
        }
        
        // Валидация
        if ($this->retentionDays < 1) {
            $this->retentionDays = 7;
        }
        if ($this->batchLimit < 1 || $this->batchLimit > 10000) {
            $this->batchLimit = 1000;
        }
    }
    
    /**
     * Логирование сообщения
     * 
     * @param string $message Сообщение
     * @param int $level Уровень логирования
     */
    private function log($message, $level = CLogger::LEVEL_INFO)
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        Yii::log($message, $level, $this->moduleId . '.cleanup');
    }
}