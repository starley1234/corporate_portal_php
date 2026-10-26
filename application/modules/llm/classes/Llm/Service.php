<?php defined('SYSPATH') or die('No direct script access.'); 

/**
 * Универсальный сервис для взаимодействия с LLM.
 * Инкапсулирует логику выбора провайдера и вызова его метода chat.
 */
class Llm_Service { 
    protected $_default_provider_name;
    protected $_default_model_name; // Модель платформы (config llm.default_model)
    protected $_providers = []; // Массив экземпляров провайдеров
    protected $_model_aliases = []; // Массив для хранения алиасов моделей

    // Текущие выбранные провайдер и модель для цепочечных вызовов
    protected $_current_provider_name;
    protected $_current_model_name;
    // Провайдер выбран в цепочке вручную (Llm::provider('openrouter')) —
    // тогда модель платформы из конфига не перетягивает запрос к себе
    protected $_provider_locked = FALSE;

    // Временные свойства для хранения состояния между вызовами в цепочке
    public $temp_system_prompt;
    public $temp_prompt_content;
    public $temp_messages;
    public $temp_tools;
    protected $_temp_mcp_tools;
    public $temp_options = [];

    public function __construct(string $default_provider_name, array $providers_config, array $model_aliases) {
        $this->_default_provider_name = $default_provider_name;
        $this->_default_model_name = null;
        $this->_current_provider_name = $default_provider_name;
        $this->_model_aliases = $model_aliases;

        foreach ($providers_config as $provider_name => $config) {
            switch ($provider_name) {
                case 'openrouter':
                case 'openai':
                    $this->_providers[$provider_name] = new Llm_Provider_OpenAICompatible($provider_name, $config);
                    break;
                case 'local':
                case 'local_lms':
                // Сервер эмбеддингов (config/llm.php): OpenAI-совместимый
                // /v1/embeddings на отдельном эндпоинте EMBEDDING_API_URL.
                // Раньше провайдер не был известен: в лог лило «Unknown LLM
                // provider configured: embedding_server», а get_embedding()
                // молча падал назад на провайдера 'local'.
                case 'embedding_server':
                    $this->_providers[$provider_name] = new Llm_Provider_Toolforge($provider_name, $config);
                    break;
                default:
                    Kohana::$log->add(Log::WARNING, 'Unknown LLM provider configured: :provider', [':provider' => $provider_name]);
                    break;
            }
        }

        if (!isset($this->_providers[$this->_default_provider_name])) {
            throw new Kohana_Exception('Default LLM provider ":provider" not found or not configured.', [':provider' => $this->_default_provider_name]);
        }
    }

    public function provider(string $provider_name): self {
        if (!isset($this->_providers[$provider_name])) {
            throw new Kohana_Exception('LLM provider ":provider" not found.', [':provider' => $provider_name]);
        }
        $this->_current_provider_name = $provider_name;
        $this->_current_model_name = null;
        // Провайдер выбран явно — модель платформы его не перебивает:
        // модель возьмётся из настроек самого провайдера.
        $this->_provider_locked = TRUE;
        return $this;
    }

    public function model(string $model_name): self {
        $this->_current_model_name = $model_name;
        return $this;
    }

    public function set_model(string $model_name): self {
        return $this->model($model_name);
    }

    /**
     * Провайдер по умолчанию (config: default_model мог задать только провайдера,
     * без модели — тогда модель берётся из настроек провайдера).
     */
    public function set_default_provider(string $provider_name): self {
        $this->_default_provider_name = $provider_name;
        $this->_current_provider_name = $provider_name;

        return $this;
    }

    /**
     * Модель платформы по умолчанию (config llm.default_model).
     *
     * Это именно «модель, когда её не выбрали», поэтому она НЕ перебивает
     * модель, переданную в $options вызова или выставленную через model():
     * иначе навык Алисы (и любой другой вызывающий) всегда получал бы
     * платформенную модель вместо своей.
     */
    public function set_default_model(string $model_name): self {
        $this->_default_model_name = $model_name;

        return $this;
    }

    /** Текущая модель платформы по умолчанию (null — не задана). */
    public function get_default_model(): ?string {
        return $this->_default_model_name;
    }

    public function system(string $content): self {
        $this->temp_system_prompt = $content;
        return $this;
    }

    public function messages(array $messages): self {
        $this->temp_messages = $messages;
        return $this;
    }

    public function tools(array $tools): self {
        $this->temp_tools = $tools;
        return $this;
    }

    public function mcp(array $config): self {
        $this->_temp_mcp_tools = $config;
        return $this;
    }

    public function options(array $options): self {
        $this->temp_options = Arr::merge($this->temp_options, $options);
        return $this;
    }

    public function reset(): self {
        $this->_current_provider_name = $this->_default_provider_name;
        $this->_current_model_name = null;
        $this->_provider_locked = FALSE;
        $this->temp_system_prompt = null;
        $this->temp_prompt_content = null;
        $this->temp_messages = null;
        $this->temp_tools = null;
        $this->_temp_mcp_tools = null;
        $this->temp_options = [];
        return $this;
    }

    /**
     * Упрощенный метод для получения текстового ответа на запрос.
     */
    public function ask(string $prompt = null, array $options = []): string {
        $messages = [];
        
        if ($this->temp_messages) {
            $messages = $this->temp_messages;
        } else {
            if ($this->temp_system_prompt) {
                $messages[] = ['role' => 'system', 'content' => $this->temp_system_prompt];
            }

            $final_prompt = $prompt ?? $this->temp_prompt_content;
            if (!$final_prompt) {
                throw new Kohana_Exception('Prompt content is missing for ask() method.');
            }

            $messages[] = ['role' => 'user', 'content' => $final_prompt];
        }

        // Объединяем опции из шаблона и переданные в метод
        $merged_options = Arr::merge($this->temp_options, $options);

        $response = $this->chat($messages, $merged_options);
        return $response['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Универсальный метод для взаимодействия с LLM.
     *
     * Модель выбирается в таком порядке — побеждает первый найденный вариант:
     *   1. $options['model'] — модель передали в сам вызов:
     *      Llm::chat($messages, ['model' => 'openrouter::google/gemini-2.5-flash']);
     *   2. модель цепочки: Llm::model('openrouter::...')->chat($messages);
     *   3. модель из опций шаблона промпта (Llm::prompt(...));
     *   4. vision_model из конфига — если в сообщениях есть изображения;
     *   5. default_model из конфига — модель платформы;
     *   6. модель провайдера по умолчанию.
     *
     * Формат «провайдер::модель» понимается везде, а алиасы из
     * llm.model_aliases применяются к любому из этих вариантов.
     */
    public function chat(array $messages, array $options = []): array {
        $start_time = microtime(true);
        $status = 'success';
        $error_message = null;

        // Какую модель просили (уже с учётом приоритетов) и кто её обслуживает.
        $resolved = $this->_resolve_model($this->_requested_model($options, $messages));
        $resolved_provider_name = $resolved['provider'];
        $resolved_model_name    = $resolved['model'];

        if (!isset($this->_providers[$resolved_provider_name])) {
            $error_message = 'LLM provider "' . $resolved_provider_name . '" not found.';
            $status = 'error';
            $this->_saveRequestLog($resolved_provider_name, $resolved_model_name ?? 'unknown', $messages, $options, [], $start_time, $status, $error_message);
            throw new Kohana_Exception($error_message);
        }

        $provider = $this->_providers[$resolved_provider_name];
        
        // Объединяем переданные опции с накопленными в temp_options
        $final_options = Arr::merge($this->temp_options, $options);

        if ($resolved_model_name !== null && $resolved_model_name !== '') {
            // Модель задана явно: в $options вызова, через Llm::model(),
            // в шаблоне промпта или в конфиге (default_model / vision_model).
            // Провайдер уже выбран по префиксу «провайдер::модель».
            $final_options['model'] = $resolved_model_name;
        } else {
            // Не задано ничего — берём модель провайдера.
            $final_options['model'] = $provider->getDefaultModel();
        }

        // Сохраняем инструменты во временную переменную, чтобы они были доступны в _handleToolCalls
        $active_tools = $this->temp_tools;

        if ($active_tools !== null && !isset($final_options['tools'])) {
            $formatted_tools = [];
            foreach ($active_tools as $name => $callback) {
                $formatted_tools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $name,
                        'parameters' => $this->_getFunctionParameters($callback)
                    ]
                ];
            }
            $final_options['tools'] = $formatted_tools;
        }

        if ($this->_temp_mcp_tools) {
            $final_options['mcp_tools'] = $this->_temp_mcp_tools;
        }

        $model_info = $provider->getModelInfo($final_options['model']);

        if (isset($model_info['unsupported_params'])) {
            foreach ($model_info['unsupported_params'] as $param) {
                unset($final_options[$param]);
            }
        }

        if (isset($model_info['default_params'])) {
            foreach ($model_info['default_params'] as $param => $value) {
                if (!isset($final_options[$param])) {
                    $final_options[$param] = $value;
                }
            }
        }

        if (!empty($final_options['tools'])) {
            // Если инструменты переданы явно, мы доверяем пользователю.
            // Проверяем только если модель заведомо известна как не поддерживающая tools.
            if (!$provider->supportsTools($final_options['model'])) {
                unset($final_options['tools']);
                unset($final_options['tool_choice']);
            } elseif (!isset($final_options['tool_choice'])) {
                $final_options['tool_choice'] = 'auto';
            }
        }

        try {
            $this->reconnect_db(); 
            $response = $provider->chat($messages, $final_options);
        } catch (Exception $e) {
            $status = 'error';
            $error_message = $e->getMessage();
            $this->_saveRequestLog($resolved_provider_name, $final_options['model'], $messages, $final_options, [], $start_time, $status, $error_message);
            throw $e;
        }

        $this->_saveRequestLog($resolved_provider_name, $final_options['model'], $messages, $final_options, $response, $start_time, $status, $error_message);
        
        // Вызываем локальные инструменты только если это НЕ MCP запрос
        if (!$this->_temp_mcp_tools && isset($response['choices'][0]['message']['tool_calls']) && !empty($response['choices'][0]['message']['tool_calls'])) {
            $response = $this->_handleToolCalls($provider, $messages, $response, $final_options, $active_tools);
        }

        $this->reset();

        return $response;
    }

    /**
     * Какую модель просил вызывающий: null — явного выбора нет, берём
     * модель по умолчанию (приоритеты описаны в chat()).
     *
     * @param   array $options  опции самого вызова (самый высокий приоритет)
     * @param   array $messages сообщения (нужны для автовыбора vision-модели)
     * @return  string|null имя модели, возможно в формате «провайдер::модель»
     */
    protected function _requested_model(array $options, array $messages)
    {
        // 1. Модель передали в сам вызов: Llm::chat($messages, ['model' => ...]).
        $candidate = isset($options['model']) ? trim((string) $options['model']) : '';

        if ($candidate !== '')
        {
            return $candidate;
        }

        // 2. Модель цепочки: Llm::model('openrouter::...')->chat($messages).
        $candidate = $this->_current_model_name === NULL
            ? ''
            : trim((string) $this->_current_model_name);

        if ($candidate !== '')
        {
            return $candidate;
        }

        // 3. Модель из опций шаблона промпта: Llm::prompt('...')->chat(...).
        $candidate = isset($this->temp_options['model'])
            ? trim((string) $this->temp_options['model'])
            : '';

        if ($candidate !== '')
        {
            return $candidate;
        }

        // 4. В сообщениях есть изображения — vision-модель из конфига.
        if ($this->_has_images($messages))
        {
            $vision = Kohana::$config->load('llm.vision_model');

            if ($vision)
            {
                return (string) $vision;
            }
        }

        // 5. Модель платформы из конфига (llm.default_model). Провайдер,
        // выбранный в цепочке вручную, важнее неё.
        if ($this->_provider_locked)
        {
            return NULL;
        }

        return $this->_default_model_name === NULL ? NULL : (string) $this->_default_model_name;
    }

    /**
     * Разбор имени модели: префикс «провайдер::модель» выбирает провайдера,
     * затем применяются алиасы из llm.model_aliases.
     *
     * @param   string|null $requested_model
     * @return  array ['provider' => string, 'model' => string|null]
     */
    protected function _resolve_model($requested_model)
    {
        $provider_name = $this->_current_provider_name;
        $model_name = ($requested_model === NULL OR $requested_model === '')
            ? NULL
            : (string) $requested_model;

        // 'провайдер::модель' — провайдера можно задать прямо в имени модели
        $model_name = $this->_split_provider($model_name, $provider_name);

        // Если есть замена старой модели на аналогичную новую.
        // Цикл — на случай цепочки алиасов, с защитой от зацикливания.
        $seen = [];

        while ($model_name !== NULL AND $model_name !== '')
        {
            $alias_key = $provider_name.'::'.$model_name;

            if (isset($seen[$alias_key]) OR ! isset($this->_model_aliases[$alias_key]))
            {
                break;
            }

            $seen[$alias_key] = TRUE;

            $alias_info = $this->_model_aliases[$alias_key];
            $model_name = isset($alias_info['model']) ? (string) $alias_info['model'] : NULL;

            $model_name = $this->_split_provider($model_name, $provider_name);
        }

        return ['provider' => $provider_name, 'model' => $model_name];
    }

    /**
     * Отрезает префикс «провайдер::» от имени модели (по ссылке меняя провайдера),
     * если такой провайдер настроен.
     */
    protected function _split_provider($model_name, &$provider_name)
    {
        if ($model_name === NULL OR $model_name === '' OR strpos($model_name, '::') === FALSE)
        {
            return $model_name;
        }

        $parts = explode('::', $model_name, 2);

        // Провайдер не настроен — оставляем имя как есть (старое поведение).
        if ( ! isset($this->_providers[$parts[0]]))
        {
            return $model_name;
        }

        $provider_name = $parts[0];

        return $parts[1] !== '' ? $parts[1] : NULL;
    }

    /** Есть ли в сообщениях изображения (нужна vision-модель). */
    protected function _has_images(array $messages)
    {
        foreach ($messages as $msg)
        {
            if (isset($msg['content']) AND is_array($msg['content']))
            {
                foreach ($msg['content'] as $part)
                {
                    if (isset($part['type']) AND $part['type'] === 'image_url')
                    {
                        return TRUE;
                    }
                }
            }
        }

        return FALSE;
    }

    /**
     * Получение векторного представления текста (embedding).
     */
    public function get_embedding(string $input, array $options = []): array { 
        $start_time = microtime(true);
        $resolved_provider_name = $this->_current_provider_name;
        $status = 'success';
        $error_message = null;

        // Пытаемся взять embedding_model из конфига
        $embedding_model = Kohana::$config->load('llm.embedding_model');
        
        if ($embedding_model) {
            // Применяем логику алиасов
            if (isset($this->_model_aliases[$embedding_model])) {
                $embedding_model = $this->_model_aliases[$embedding_model]['model'];
            }

            // Если указан провайдер (provider::model)
            if (strpos($embedding_model, '::') !== false) {
                list($e_provider, $e_model) = explode('::', $embedding_model, 2);
                if (isset($this->_providers[$e_provider])) {
                    $resolved_provider_name = $e_provider;
                    $options['model'] = $e_model;
                } else {
                    $options['model'] = $e_model;
                }
            } else {
                $options['model'] = $embedding_model;
            }
        }

        if (!isset($this->_providers[$resolved_provider_name])) {
            throw new Kohana_Exception('LLM provider ":provider" for embeddings not found.', [':provider' => $resolved_provider_name]);
        }

        $provider = $this->_providers[$resolved_provider_name];
        
        // Если провайдер Toolforge (local), он поддерживает embeddings через LM Studio
        // Если метод не существует, проверяем, не является ли это провайдером, который мы можем вызвать
        if (!method_exists($provider, 'embeddings') && !($provider instanceof Llm_Provider_Toolforge)) {
            throw new Kohana_Exception('Provider ":provider" does not support embeddings.', [':provider' => $resolved_provider_name]);
        }

        try {
            $this->reconnect_db();
            
            // Для Toolforge/OpenAICompatible вызываем embeddings
            $response = $provider->embeddings($input, $options);
            
            // Логируем запрос
            $this->_saveRequestLog($resolved_provider_name, $options['model'] ?? 'default-embedding', [['role' => 'user', 'content' => $input]], $options, $response, $start_time, $status, $error_message);
            
            return $response['data'][0]['embedding'] ?? [];
        } catch (Exception $e) {
            $status = 'error';
            $error_message = $e->getMessage();
            $this->_saveRequestLog($resolved_provider_name, $options['model'] ?? 'default-embedding', [['role' => 'user', 'content' => $input]], $options, [], $start_time, $status, $error_message);
            throw $e;
        } finally {
            $this->reset();
        }
    }

    /**
     * Анализ изображений.
     */
    public function vision($images, string $prompt, array $options = []): string {
        $images = is_array($images) ? $images : [$images];
        $content = [['type' => 'text', 'text' => $prompt]];

        foreach ($images as $image) {
            if (strpos($image, 'http') === 0) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $image]];
            } elseif (file_exists($image)) {
                $type = pathinfo($image, PATHINFO_EXTENSION);
                $data = base64_encode(file_get_contents($image));
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:image/$type;base64,$data"]];
            } else {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $image]];
            }
        }

        $messages = [['role' => 'user', 'content' => $content]];
        $response = $this->chat($messages, $options);
        return $response['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Извлечение структурированных данных в формате JSON.
     */
    public function extract(string $text, array $schema, array $options = []): array {
        $schema_str = json_encode($schema, JSON_UNESCAPED_UNICODE);
        $prompt = "Extract data from the following text according to this JSON schema: $schema_str\n\nText: $text\n\nReturn ONLY valid JSON.";
        
        // Исправлено: OpenAI API требует 'text' или 'json_schema' (в некоторых случаях 'json_object').
        // 'json_object' поддерживается не всеми моделями и требует слова "JSON" в промпте.
        $options['response_format'] = ['type' => 'text'];
        
        $response_text = $this->ask($prompt, $options);
        
        // Очистка от markdown-разметки, если модель вернула ```json ... ```
        $response_text = trim($response_text);
        if (strpos($response_text, '```') !== false) {
            $response_text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $response_text);
        }

        $data = json_decode($response_text, true);
        
        if (is_array($data)) {
            // Если LLM вернула объект, где данные лежат внутри ключа (например, "data" или "result")
            if (count($data) === 1) {
                $first_val = reset($data);
                if (is_array($first_val)) {
                    return $first_val;
                }
            }
            return $data;
        }
        
        return [];
    }

    /**
     * Анализ объекта (ORM или массив).
     */
    public function analyze($object, string $instruction, array $options = []): string {
        $data = ($object instanceof ORM) ? $object->as_array() : $object;
        $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $prompt = "Instruction: $instruction\n\nData:\n$json_data";
        return $this->ask($prompt, $options);
    }

    /**
     * Краткое резюме объекта.
     */
    public function summarize($object, array $options = []): string {
        return $this->analyze($object, "Provide a concise summary of this data.", $options);
    }

    /**
     * Использование именованного шаблона из базы данных.
     */
    public function prompt(string $template_name, array $variables = []): self {
        $template = ORM::factory('Llm_Prompt')->where('name', '=', $template_name)->find();
        
        if (!$template->loaded()) {
            throw new Kohana_Exception('LLM prompt template ":name" not found in database.', [':name' => $template_name]);
        }

        $this->temp_prompt_content = $template->compile_prompt($variables);

        if ($template->system) {
            $this->system($template->system);
        }

        if ($template->provider_name) {
            $this->provider($template->provider_name);
        }

        if ($template->model_name) {
            $this->model($template->model_name);
        }

        $this->temp_options = $template->get_options();

        return $this;
    }

    protected function _handleToolCalls(Interface_LlmProvider $provider, array $messages, array $response, array $options, ?array $active_tools): array {
        $message = $response['choices'][0]['message'];
        $messages[] = $message;

        foreach ($message['tool_calls'] as $tool_call) {
            $function_name = $tool_call['function']['name'];
            $function_args = json_decode($tool_call['function']['arguments'], true);

            $result = null;
            if (isset($active_tools[$function_name]) && is_callable($active_tools[$function_name])) {
                try {
                    $result = call_user_func_array($active_tools[$function_name], $function_args);
                } catch (Exception $e) {
                    $result = "Error executing tool: " . $e->getMessage();
                }
            } else {
                $result = "Tool '$function_name' not found or not callable.";
            }

            $messages[] = [
                'tool_call_id' => $tool_call['id'],
                'role'         => 'tool',
                'name'         => $function_name,
                'content'      => is_array($result) || is_object($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (string)$result,
            ];
        }

        return $provider->chat($messages, $options);
    }

    protected function _getFunctionParameters($callback): array {
        try {
            if (is_array($callback)) {
                $ref = new ReflectionMethod($callback[0], $callback[1]);
            } elseif ($callback instanceof Closure || is_string($callback)) {
                $ref = new ReflectionFunction($callback);
            } else {
                return ['type' => 'object', 'properties' => (object)[]];
            }

            $properties = [];
            $required = [];

            foreach ($ref->getParameters() as $param) {
                $name = $param->getName();
                $properties[$name] = ['type' => 'string']; // По умолчанию string
                if (!$param->isOptional()) {
                    $required[] = $name;
                }
            }

            return [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required
            ];
        } catch (Exception $e) {
            return ['type' => 'object', 'properties' => (object)[]];
        }
    }
    
    protected function reconnect_db($group = 'default') {
        
        try {
            $db = Database::instance($group);
            // Проверка живое ли соединение
            $db->query(Database::SELECT, 'SELECT 1');
        } catch (Exception $e) {
            // Если упало - жестко удаляем инстанс
            if (isset(Database::$instances[$group])) {
                Database::$instances[$group]->disconnect();
                unset(Database::$instances[$group]);
            }
            // Kohana создаст новое при следующем вызове instance()
        }
        
        
    }

    protected function _saveRequestLog(string $provider_name, string $model_name, array $request_messages, array $request_options, array $response_data, float $start_time, string $status, ?string $error_message): void {
        try {
            $this->reconnect_db();
            $end_time = microtime(true);
            $usage = $response_data['usage'] ?? [];

            $log = ORM::factory('Llm_Request');
            $log->provider_name      = $provider_name;
            $log->model_name         = $model_name;
            $log->request_messages   = json_encode($request_messages, JSON_UNESCAPED_UNICODE);
            $log->request_options    = json_encode($request_options, JSON_UNESCAPED_UNICODE);
            $log->response_data      = ($status === 'success') ? json_encode($response_data, JSON_UNESCAPED_UNICODE) : null;
            $log->prompt_tokens      = $usage['prompt_tokens'] ?? null;
            $log->completion_tokens  = $usage['completion_tokens'] ?? null;
            $log->total_tokens       = $usage['total_tokens'] ?? null;
            $log->request_timestamp  = (int)$start_time;
            $log->response_timestamp = ($status === 'success') ? (int)$end_time : null;
            $log->duration_ms        = (int)(($end_time - $start_time) * 1000);
            $log->status             = $status;
            $log->error_message      = $error_message;
            $log->session_id         = Session::instance()->id();
            $log->save();
        } catch (Exception $e) {
            Kohana::$log->add(Log::ERROR, 'Failed to save LLM log: :error', [':error' => $e->getMessage()]);
        }
    }

    public function getProvider(string $provider_name = null): Interface_LlmProvider {
        $name = $provider_name ?? $this->_default_provider_name;
        if (!isset($this->_providers[$name])) {
            throw new Kohana_Exception('LLM provider ":provider" not found.', [':provider' => $name]);
        }
        return $this->_providers[$name];
    }
}