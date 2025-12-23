<?php
// ============================================
// АГРЕГАТОР ДАННЫХ ИЗ CRM - BACKEND MVC
// ============================================

// Начало буферизации вывода
ob_start();

// ============================================
// КОНФИГУРАЦИЯ И НАСТРОЙКИ
// ============================================

/**
 * Класс конфигурации приложения
 */
class Config {
    const APP_NAME = 'Агрегатор данных из CRM';
    const APP_VERSION = '1.0.0';
    const APP_ENV = 'development'; // development, production
    
    // Настройки базы данных (в реальном приложении будет отдельный файл)
    const DB_FILE = 'data/crm_aggregator.db';
    const USE_DATABASE = false; // Для демо используем массив, в реальном приложении - БД
    
    // Настройки API
    const API_PREFIX = '/api';
    const DEFAULT_TIMEZONE = 'Europe/Moscow';
    
    // Настройки безопасности
    // Добавляем '*' для упрощения разработки SPA с другого хоста (например, другой порт/домен)
    // В продакшене рекомендуется ограничить список конкретными доменами.
    const ALLOWED_ORIGINS = ['http://localhost', 'http://127.0.0.1', '*'];
    const MAX_REQUEST_SIZE = 10485760; // 10MB
    
    public static function init() {
        // Установка временной зоны
        date_default_timezone_set(self::DEFAULT_TIMEZONE);
        
        // Настройка обработки ошибок
        if (self::APP_ENV === 'development') {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(0);
            ini_set('display_errors', 0);
        }
        
        // Установка заголовков CORS
        self::setCorsHeaders();
    }
    
    private static function setCorsHeaders() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($origin, self::ALLOWED_ORIGINS) || in_array('*', self::ALLOWED_ORIGINS)) {
            header("Access-Control-Allow-Origin: " . $origin);
        }
        
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
        header("Access-Control-Allow-Credentials: true");
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
}

// Инициализация конфигурации
Config::init();

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ И УТИЛИТЫ
// ============================================

/**
 * Класс утилит
 */
class Utils {
    /**
     * Получить текущий URL путь
     */
    public static function getCurrentPath() {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return rtrim($path, '/');
    }
    
    /**
     * Получить метод запроса
     */
    public static function getRequestMethod() {
        return $_SERVER['REQUEST_METHOD'];
    }
    
    /**
     * Получить данные JSON из тела запроса
     */
    public static function getJsonInput() {
        $input = file_get_contents('php://input');
        return json_decode($input, true);
    }
    
    /**
     * Отправить JSON ответ
     */
    public static function sendJson($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    /**
     * Отправить ошибку
     */
    public static function sendError($message, $statusCode = 400, $details = null) {
        $error = [
            'error' => true,
            'message' => $message,
            'status' => $statusCode,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($details !== null) {
            $error['details'] = $details;
        }
        
        self::sendJson($error, $statusCode);
    }
    
    /**
     * Отправить успешный ответ
     */
    public static function sendSuccess($data = null, $message = 'Success') {
        $response = [
            'success' => true,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        self::sendJson($response);
    }
    
    /**
     * Проверить, является ли запрос API запросом
     */
    public static function isApiRequest() {
        return strpos(self::getCurrentPath(), Config::API_PREFIX) === 0;
    }
    
    /**
     * Получить ID из URL
     */
    public static function getIdFromUrl($url) {
        $parts = explode('/', $url);
        return end($parts);
    }
    
    /**
     * Валидация данных
     */
    public static function validate($data, $rules) {
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            
            // Проверка на обязательность
            if (strpos($rule, 'required') !== false && empty($value)) {
                $errors[$field] = "Поле $field обязательно для заполнения";
                continue;
            }
            
            // Проверка email
            if (strpos($rule, 'email') !== false && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field] = "Поле $field должно содержать валидный email";
            }
            
            // Проверка URL
            if (strpos($rule, 'url') !== false && !empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                $errors[$field] = "Поле $field должно содержать валидный URL";
            }
            
            // Проверка числового значения
            if (strpos($rule, 'numeric') !== false && !empty($value) && !is_numeric($value)) {
                $errors[$field] = "Поле $field должно быть числовым";
            }
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Логирование действий
     */
    public static function log($type, $message, $details = null) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
            'details' => $details
        ];
        
        // В реальном приложении пишем в файл или БД
        // file_put_contents('logs/system.log', json_encode($logEntry) . PHP_EOL, FILE_APPEND);
        
        return $logEntry;
    }
}

// ============================================
// БАЗОВЫЙ КЛАСС МОДЕЛИ
// ============================================

/**
 * Абстрактный класс модели
 */
abstract class Model {
    protected static $data = [];
    protected static $lastId = 0;
    
    /**
     * Получить все записи
     */
    public static function all() {
        return array_values(static::$data);
    }
    
    /**
     * Найти запись по ID
     */
    public static function find($id) {
        return static::$data[$id] ?? null;
    }
    
    /**
     * Создать новую запись
     */
    public static function create($data) {
        $id = ++static::$lastId;
        $data['id'] = $id;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        static::$data[$id] = $data;
        return $data;
    }
    
    /**
     * Обновить запись
     */
    public static function update($id, $data) {
        if (!isset(static::$data[$id])) {
            return null;
        }
        
        $data['updated_at'] = date('Y-m-d H:i:s');
        static::$data[$id] = array_merge(static::$data[$id], $data);
        
        return static::$data[$id];
    }
    
    /**
     * Удалить запись
     */
    public static function delete($id) {
        if (!isset(static::$data[$id])) {
            return false;
        }
        
        unset(static::$data[$id]);
        return true;
    }
    
    /**
     * Фильтрация записей
     */
    public static function where($conditions) {
        $results = [];
        
        foreach (static::$data as $item) {
            $match = true;
            
            foreach ($conditions as $key => $value) {
                if (!isset($item[$key]) || $item[$key] != $value) {
                    $match = false;
                    break;
                }
            }
            
            if ($match) {
                $results[] = $item;
            }
        }
        
        return $results;
    }
}

// ============================================
// МОДЕЛИ ДАННЫХ
// ============================================

/**
 * Модель подключения к CRM
 */
class ConnectionModel extends Model {
    protected static $data = [];
    protected static $lastId = 0;
    
    // Инициализация демо-данных
    public static function initDemoData() {
        if (!empty(static::$data)) {
            return;
        }
        
        $demoData = [
            [
                'id' => 1,
                'name' => 'Битрикс24 - ООО "Ромашка"',
                'type' => 'bitrix24',
                'url' => 'https://romashka.bitrix24.ru',
                'api_key' => '••••••••',
                'client_name' => 'ООО "Ромашка"',
                'status' => 'active',
                'last_sync' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'leads_today' => 24,
                'conversions_today' => 8,
                'created_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ],
            [
                'id' => 2,
                'name' => 'AmoCRM - ИП Сидоров',
                'type' => 'amocrm',
                'url' => 'https://sidorov.amocrm.ru',
                'api_key' => '••••••••',
                'client_name' => 'ИП Сидоров А.В.',
                'status' => 'active',
                'last_sync' => date('Y-m-d H:i:s', strtotime('-3 hours')),
                'leads_today' => 15,
                'conversions_today' => 5,
                'created_at' => date('Y-m-d H:i:s', strtotime('-25 days')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
            ],
            [
                'id' => 3,
                'name' => 'RetailCRM - Магазин "ТехноМир"',
                'type' => 'retailcrm',
                'url' => 'https://technomir.retailcrm.ru',
                'api_key' => '••••••••',
                'client_name' => 'Магазин "ТехноМир"',
                'status' => 'inactive',
                'last_sync' => date('Y-m-d H:i:s', strtotime('-5 days')),
                'leads_today' => 0,
                'conversions_today' => 0,
                'created_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-5 days'))
            ]
        ];
        
        foreach ($demoData as $item) {
            static::$data[$item['id']] = $item;
            if ($item['id'] > static::$lastId) {
                static::$lastId = $item['id'];
            }
        }
    }
}

/**
 * Модель шаблонов сопоставления
 */
class MappingModel extends Model {
    protected static $data = [];
    protected static $lastId = 0;
    
    // Инициализация демо-данных
    public static function initDemoData() {
        if (!empty(static::$data)) {
            return;
        }
        
        $demoData = [
            [
                'id' => 1,
                'name' => 'Стандартное для Битрикс24',
                'source_system' => 'landing',
                'target_system' => 'bitrix24',
                'fields' => [
                    ['source' => 'name', 'target' => 'NAME', 'transformation' => 'none'],
                    ['source' => 'phone', 'target' => 'PHONE', 'transformation' => 'phone_ru'],
                    ['source' => 'email', 'target' => 'EMAIL', 'transformation' => 'none'],
                    ['source' => 'yclid', 'target' => 'UTM_SOURCE', 'transformation' => 'add_yclid_prefix']
                ],
                'created_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-3 days'))
            ],
            [
                'id' => 2,
                'name' => 'Для AmoCRM с доп. полями',
                'source_system' => 'landing',
                'target_system' => 'amocrm',
                'fields' => [
                    ['source' => 'name', 'target' => 'Имя', 'transformation' => 'none'],
                    ['source' => 'phone', 'target' => 'Телефон', 'transformation' => 'phone_ru'],
                    ['source' => 'product', 'target' => 'Товар', 'transformation' => 'uppercase']
                ],
                'created_at' => date('Y-m-d H:i:s', strtotime('-15 days')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ]
        ];
        
        foreach ($demoData as $item) {
            static::$data[$item['id']] = $item;
            if ($item['id'] > static::$lastId) {
                static::$lastId = $item['id'];
            }
        }
    }
}

/**
 * Модель логов операций
 */
class LogModel extends Model {
    protected static $data = [];
    protected static $lastId = 0;
    
    // Инициализация демо-данных
    public static function initDemoData() {
        if (!empty(static::$data)) {
            return;
        }
        
        $demoData = [
            [
                'id' => 1,
                'timestamp' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
                'type' => 'success',
                'message' => 'Отправлен лид #2451 в CRM Битрикс24',
                'details' => 'Клиент: ООО "Ромашка", Телефон: +7 999 123-45-67',
                'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))
            ],
            [
                'id' => 2,
                'timestamp' => date('Y-m-d H:i:s', strtotime('-32 minutes')),
                'type' => 'success',
                'message' => 'Конверсия #201 отправлена в Яндекс.Директ',
                'details' => 'ID сделки: 2451, Сумма: 15000 руб.',
                'created_at' => date('Y-m-d H:i:s', strtotime('-32 minutes'))
            ],
            [
                'id' => 3,
                'timestamp' => date('Y-m-d H:i:s', strtotime('-35 minutes')),
                'type' => 'error',
                'message' => 'Ошибка подключения к RetailCRM',
                'details' => 'Неверный API ключ или истек срок действия',
                'created_at' => date('Y-m-d H:i:s', strtotime('-35 minutes'))
            ],
            [
                'id' => 4,
                'timestamp' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'type' => 'warning',
                'message' => 'Медленный ответ от сервера Битрикс24',
                'details' => 'Время ответа: 3.5 сек, порог: 2 сек',
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ]
        ];
        
        foreach ($demoData as $item) {
            static::$data[$item['id']] = $item;
            if ($item['id'] > static::$lastId) {
                static::$lastId = $item['id'];
            }
        }
    }
}

/**
 * Модель настроек системы
 */
class SettingsModel extends Model {
    protected static $data = [];
    protected static $lastId = 0;
    
    // Инициализация демо-данных
    public static function initDemoData() {
        if (!empty(static::$data)) {
            return;
        }
        
        $demoData = [
            [
                'id' => 1,
                'key' => 'system_name',
                'value' => 'Агрегатор данных из CRM',
                'description' => 'Название системы',
                'type' => 'string'
            ],
            [
                'id' => 2,
                'key' => 'sync_interval',
                'value' => '30',
                'description' => 'Интервал синхронизации (минут)',
                'type' => 'number'
            ],
            [
                'id' => 3,
                'key' => 'log_retention_days',
                'value' => '30',
                'description' => 'Время хранения логов (дни)',
                'type' => 'number'
            ],
            [
                'id' => 4,
                'key' => 'notifications_errors',
                'value' => 'true',
                'description' => 'Уведомления об ошибках',
                'type' => 'boolean'
            ],
            [
                'id' => 5,
                'key' => 'notifications_sync_complete',
                'value' => 'true',
                'description' => 'Уведомления о завершении синхронизации',
                'type' => 'boolean'
            ],
            [
                'id' => 6,
                'key' => 'notifications_daily_report',
                'value' => 'false',
                'description' => 'Ежедневный отчет',
                'type' => 'boolean'
            ],
            [
                'id' => 7,
                'key' => 'yandex_api_key',
                'value' => '••••••••',
                'description' => 'API ключ Яндекс.Директ',
                'type' => 'password'
            ],
            [
                'id' => 8,
                'key' => 'webhook_url',
                'value' => 'https://api.aggregator.ru/webhook/landing',
                'description' => 'Webhook URL для лендингов',
                'type' => 'url'
            ]
        ];
        
        foreach ($demoData as $item) {
            static::$data[$item['id']] = $item;
            if ($item['id'] > static::$lastId) {
                static::$lastId = $item['id'];
            }
        }
    }
    
    /**
     * Получить настройку по ключу
     */
    public static function getByKey($key) {
        foreach (static::$data as $setting) {
            if ($setting['key'] === $key) {
                return $setting;
            }
        }
        return null;
    }
    
    /**
     * Обновить настройку по ключу
     */
    public static function updateByKey($key, $value) {
        foreach (static::$data as $id => $setting) {
            if ($setting['key'] === $key) {
                static::$data[$id]['value'] = $value;
                static::$data[$id]['updated_at'] = date('Y-m-d H:i:s');
                return static::$data[$id];
            }
        }
        return null;
    }
}

/**
 * Модель статистики
 */
class StatsModel {
    /**
     * Получить статистику за сегодня
     */
    public static function getTodaysStats() {
        $connections = ConnectionModel::all();
        $activeConnections = array_filter($connections, function($conn) {
            return $conn['status'] === 'active';
        });
        
        $logs = LogModel::all();
        $todayLogs = array_filter($logs, function($log) {
            return strpos($log['timestamp'], date('Y-m-d')) === 0;
        });
        
        $errorLogs = array_filter($todayLogs, function($log) {
            return $log['type'] === 'error';
        });
        
        // Подсчет лидов и конверсий
        $totalLeads = 0;
        $totalConversions = 0;
        
        foreach ($connections as $conn) {
            $totalLeads += $conn['leads_today'] ?? 0;
            $totalConversions += $conn['conversions_today'] ?? 0;
        }
        
        return [
            'leads' => $totalLeads,
            'conversions' => $totalConversions,
            'errors' => count($errorLogs),
            'active_connections' => count($activeConnections),
            'total_connections' => count($connections),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Получить статистику мониторинга
     */
    public static function getMonitoringStats() {
        return [
            'system_health' => [
                'overall' => 'healthy',
                'active_streams' => 3,
                'total_streams' => 5,
                'avg_response_time' => 245,
                'processed_today' => 127
            ],
            'transfer_stats' => [
                'landing_to_crm' => [
                    'success' => 42,
                    'errors' => 3,
                    'pending' => 2,
                    'total' => 47,
                    'success_rate' => 89
                ],
                'crm_to_yandex' => [
                    'success' => 15,
                    'errors' => 1,
                    'pending' => 0,
                    'total' => 16,
                    'success_rate' => 94
                ]
            ],
            'recent_errors' => [
                [
                    'id' => 1,
                    'time' => '2 часа назад',
                    'message' => 'Ошибка подключения к RetailCRM'
                ],
                [
                    'id' => 2,
                    'time' => '5 часов назад',
                    'message' => 'Таймаут при отправке в Яндекс.Директ'
                ]
            ]
        ];
    }
}

// ============================================
// ИНИЦИАЛИЗАЦИЯ ДЕМО-ДАННЫХ
// ============================================

ConnectionModel::initDemoData();
MappingModel::initDemoData();
LogModel::initDemoData();
SettingsModel::initDemoData();

// ============================================
// КОНТРОЛЛЕРЫ
// ============================================

/**
 * Абстрактный класс контроллера
 */
abstract class Controller {
    /**
     * Обработка GET запроса
     */
    abstract public function handleGet($id = null);
    
    /**
     * Обработка POST запроса
     */
    abstract public function handlePost($data);
    
    /**
     * Обработка PUT запроса
     */
    abstract public function handlePut($id, $data);
    
    /**
     * Обработка DELETE запроса
     */
    abstract public function handleDelete($id);
    
    /**
     * Валидация данных
     */
    protected function validate($data, $rules) {
        return Utils::validate($data, $rules);
    }
}

/**
 * Контроллер подключений
 */
class ConnectionController extends Controller {
    private $model;
    
    public function __construct() {
        $this->model = 'ConnectionModel';
    }
    
    public function handleGet($id = null) {
        if ($id) {
            $connection = ConnectionModel::find($id);
            if (!$connection) {
                Utils::sendError('Подключение не найдено', 404);
            }
            Utils::sendSuccess($connection, 'Подключение получено');
        } else {
            $connections = ConnectionModel::all();
            Utils::sendSuccess($connections, 'Список подключений получен');
        }
    }
    
    public function handlePost($data) {
        // Валидация
        $validation = $this->validate($data, [
            'name' => 'required',
            'type' => 'required',
            'url' => 'required|url',
            'client_name' => 'required'
        ]);
        
        if ($validation !== true) {
            Utils::sendError('Ошибка валидации', 422, $validation);
        }
        
        // Создание подключения
        $connection = ConnectionModel::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'url' => $data['url'],
            'api_key' => $data['api_key'] ?? '',
            'client_name' => $data['client_name'],
            'status' => $data['status'] ?? 'inactive',
            'last_sync' => null,
            'leads_today' => 0,
            'conversions_today' => 0
        ]);
        
        // Логирование
        Utils::log('info', 'Создано новое подключение', [
            'connection_id' => $connection['id'],
            'name' => $connection['name']
        ]);
        
        Utils::sendSuccess($connection, 'Подключение успешно создано', 201);
    }
    
    public function handlePut($id, $data) {
        $connection = ConnectionModel::find($id);
        if (!$connection) {
            Utils::sendError('Подключение не найдено', 404);
        }
        
        // Валидация
        $validation = $this->validate($data, [
            'url' => 'url'
        ]);
        
        if ($validation !== true) {
            Utils::sendError('Ошибка валидации', 422, $validation);
        }
        
        // Обновление подключения
        $updated = ConnectionModel::update($id, $data);
        
        // Логирование
        Utils::log('info', 'Обновлено подключение', [
            'connection_id' => $id,
            'name' => $updated['name']
        ]);
        
        Utils::sendSuccess($updated, 'Подключение успешно обновлено');
    }
    
    public function handleDelete($id) {
        $connection = ConnectionModel::find($id);
        if (!$connection) {
            Utils::sendError('Подключение не найдено', 404);
        }
        
        $deleted = ConnectionModel::delete($id);
        if (!$deleted) {
            Utils::sendError('Ошибка при удалении подключения', 500);
        }
        
        // Логирование
        Utils::log('warning', 'Удалено подключение', [
            'connection_id' => $id,
            'name' => $connection['name']
        ]);
        
        Utils::sendSuccess(null, 'Подключение успешно удалено');
    }
    
    /**
     * Дополнительные методы API
     */
    
    // Тест подключения
    public function handleTest($id) {
        $connection = ConnectionModel::find($id);
        if (!$connection) {
            Utils::sendError('Подключение не найдено', 404);
        }
        
        // Имитация тестирования подключения
        sleep(1); // Имитация задержки
        
        $success = rand(0, 1) === 1;
        
        if ($success) {
            // Обновление статуса и времени последней синхронизации
            ConnectionModel::update($id, [
                'status' => 'active',
                'last_sync' => date('Y-m-d H:i:s')
            ]);
            
            $logMessage = 'Тест подключения успешен';
            $logType = 'success';
        } else {
            ConnectionModel::update($id, [
                'status' => 'inactive'
            ]);
            
            $logMessage = 'Тест подключения не удался';
            $logType = 'error';
        }
        
        // Логирование
        Utils::log($logType, $logMessage, [
            'connection_id' => $id,
            'connection_name' => $connection['name']
        ]);
        
        Utils::sendSuccess([
            'success' => $success,
            'message' => $logMessage,
            'connection' => ConnectionModel::find($id)
        ], $logMessage);
    }
    
    // Синхронизация подключения
    public function handleSync($id) {
        $connection = ConnectionModel::find($id);
        if (!$connection) {
            Utils::sendError('Подключение не найдено', 404);
        }
        
        // Имитация синхронизации
        sleep(2); // Имитация задержки
        
        // Генерация случайных данных
        $newLeads = rand(1, 5);
        $newConversions = rand(0, 3);
        
        // Обновление статистики
        $updated = ConnectionModel::update($id, [
            'last_sync' => date('Y-m-d H:i:s'),
            'leads_today' => $connection['leads_today'] + $newLeads,
            'conversions_today' => $connection['conversions_today'] + $newConversions
        ]);
        
        // Логирование
        Utils::log('success', 'Синхронизация подключения завершена', [
            'connection_id' => $id,
            'connection_name' => $connection['name'],
            'new_leads' => $newLeads,
            'new_conversions' => $newConversions
        ]);
        
        Utils::sendSuccess([
            'connection' => $updated,
            'sync_results' => [
                'new_leads' => $newLeads,
                'new_conversions' => $newConversions,
                'total_leads' => $updated['leads_today'],
                'total_conversions' => $updated['conversions_today']
            ]
        ], 'Синхронизация успешно завершена');
    }
    
    // Тест всех подключений
    public function handleTestAll() {
        $connections = ConnectionModel::all();
        $results = [];
        
        foreach ($connections as $connection) {
            sleep(0.5); // Имитация задержки
            
            $success = rand(0, 1) === 1;
            $status = $success ? 'active' : 'inactive';
            
            ConnectionModel::update($connection['id'], [
                'status' => $status,
                'last_sync' => $success ? date('Y-m-d H:i:s') : $connection['last_sync']
            ]);
            
            $results[] = [
                'id' => $connection['id'],
                'name' => $connection['name'],
                'success' => $success,
                'status' => $status
            ];
            
            // Логирование
            Utils::log($success ? 'success' : 'warning', 
                'Тест подключения ' . ($success ? 'успешен' : 'не удался'), [
                'connection_id' => $connection['id'],
                'connection_name' => $connection['name']
            ]);
        }
        
        Utils::sendSuccess([
            'results' => $results,
            'total_tested' => count($connections),
            'successful' => count(array_filter($results, function($r) { return $r['success']; })),
            'failed' => count(array_filter($results, function($r) { return !$r['success']; }))
        ], 'Тестирование всех подключений завершено');
    }
}

/**
 * Контроллер шаблонов сопоставления
 */
class MappingController extends Controller {
    public function handleGet($id = null) {
        if ($id) {
            $mapping = MappingModel::find($id);
            if (!$mapping) {
                Utils::sendError('Шаблон сопоставления не найден', 404);
            }
            Utils::sendSuccess($mapping, 'Шаблон получен');
        } else {
            $mappings = MappingModel::all();
            Utils::sendSuccess($mappings, 'Список шаблонов получен');
        }
    }
    
    public function handlePost($data) {
        // Валидация
        $validation = $this->validate($data, [
            'name' => 'required',
            'source_system' => 'required',
            'target_system' => 'required'
        ]);
        
        if ($validation !== true) {
            Utils::sendError('Ошибка валидации', 422, $validation);
        }
        
        // Создание шаблона
        $mapping = MappingModel::create([
            'name' => $data['name'],
            'source_system' => $data['source_system'],
            'target_system' => $data['target_system'],
            'fields' => $data['fields'] ?? []
        ]);
        
        // Логирование
        Utils::log('info', 'Создан новый шаблон сопоставления', [
            'mapping_id' => $mapping['id'],
            'name' => $mapping['name']
        ]);
        
        Utils::sendSuccess($mapping, 'Шаблон успешно создан', 201);
    }
    
    public function handlePut($id, $data) {
        $mapping = MappingModel::find($id);
        if (!$mapping) {
            Utils::sendError('Шаблон сопоставления не найден', 404);
        }
        
        // Обновление шаблона
        $updated = MappingModel::update($id, $data);
        
        // Логирование
        Utils::log('info', 'Обновлен шаблон сопоставления', [
            'mapping_id' => $id,
            'name' => $updated['name']
        ]);
        
        Utils::sendSuccess($updated, 'Шаблон успешно обновлен');
    }
    
    public function handleDelete($id) {
        $mapping = MappingModel::find($id);
        if (!$mapping) {
            Utils::sendError('Шаблон сопоставления не найден', 404);
        }
        
        $deleted = MappingModel::delete($id);
        if (!$deleted) {
            Utils::sendError('Ошибка при удалении шаблона', 500);
        }
        
        // Логирование
        Utils::log('warning', 'Удален шаблон сопоставления', [
            'mapping_id' => $id,
            'name' => $mapping['name']
        ]);
        
        Utils::sendSuccess(null, 'Шаблон успешно удален');
    }
}

/**
 * Контроллер логов
 */
class LogController extends Controller {
    public function handleGet($id = null) {
        if ($id) {
            $log = LogModel::find($id);
            if (!$log) {
                Utils::sendError('Лог не найден', 404);
            }
            Utils::sendSuccess($log, 'Лог получен');
        } else {
            // Фильтрация по параметрам запроса
            $logs = LogModel::all();
            
            // Фильтрация по типу
            if (isset($_GET['type']) && $_GET['type'] !== 'all') {
                $logs = array_filter($logs, function($log) {
                    return $log['type'] === $_GET['type'];
                });
            }
            
            // Поиск
            if (isset($_GET['search']) && !empty($_GET['search'])) {
                $search = strtolower($_GET['search']);
                $logs = array_filter($logs, function($log) use ($search) {
                    return strpos(strtolower($log['message']), $search) !== false ||
                           strpos(strtolower($log['details'] ?? ''), $search) !== false;
                });
            }
            
            // Сортировка по времени (новые сначала)
            usort($logs, function($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });
            
            Utils::sendSuccess(array_values($logs), 'Логи получены');
        }
    }
    
    public function handlePost($data) {
        // Создание лога (обычно создается системой, но оставим для тестов)
        $log = LogModel::create([
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $data['type'] ?? 'info',
            'message' => $data['message'] ?? '',
            'details' => $data['details'] ?? null
        ]);
        
        Utils::sendSuccess($log, 'Лог создан', 201);
    }
    
    public function handlePut($id, $data) {
        // Логи обычно не редактируются
        Utils::sendError('Редактирование логов запрещено', 403);
    }
    
    public function handleDelete($id) {
        // Удаление отдельных логов запрещено, можно только очистить все
        Utils::sendError('Удаление отдельных логов запрещено. Используйте очистку всех логов.', 403);
    }
    
    /**
     * Очистка всех логов
     */
    public function handleClearAll() {
        // В реальном приложении была бы очистка БД или файла
        // Для демо просто очищаем массив
        $reflection = new ReflectionClass('LogModel');
        $property = $reflection->getProperty('data');
        $property->setAccessible(true);
        $property->setValue(null, []);
        
        // Логирование
        Utils::log('warning', 'Очищены все логи системы', []);
        
        Utils::sendSuccess(null, 'Все логи успешно очищены');
    }
}

/**
 * Контроллер настроек
 */
class SettingsController extends Controller {
    public function handleGet($id = null) {
        if ($id) {
            $setting = SettingsModel::find($id);
            if (!$setting) {
                Utils::sendError('Настройка не найдена', 404);
            }
            Utils::sendSuccess($setting, 'Настройка получена');
        } else {
            $settings = SettingsModel::all();
            
            // Преобразуем в удобный формат для SPA
            $formattedSettings = [];
            foreach ($settings as $setting) {
                $formattedSettings[$setting['key']] = $setting['value'];
            }
            
            Utils::sendSuccess([
                'settings' => $formattedSettings,
                'raw_settings' => $settings
            ], 'Настройки получены');
        }
    }
    
    public function handlePost($data) {
        // Создание новой настройки
        $validation = $this->validate($data, [
            'key' => 'required',
            'value' => 'required',
            'description' => 'required'
        ]);
        
        if ($validation !== true) {
            Utils::sendError('Ошибка валидации', 422, $validation);
        }
        
        $setting = SettingsModel::create([
            'key' => $data['key'],
            'value' => $data['value'],
            'description' => $data['description'],
            'type' => $data['type'] ?? 'string'
        ]);
        
        // Логирование
        Utils::log('info', 'Создана новая настройка', [
            'key' => $setting['key'],
            'description' => $setting['description']
        ]);
        
        Utils::sendSuccess($setting, 'Настройка успешно создана', 201);
    }
    
    public function handlePut($id, $data) {
        $setting = SettingsModel::find($id);
        if (!$setting) {
            Utils::sendError('Настройка не найдена', 404);
        }
        
        // Обновление настройки
        $updated = SettingsModel::update($id, $data);
        
        // Логирование
        Utils::log('info', 'Обновлена настройка', [
            'key' => $updated['key'],
            'old_value' => $setting['value'],
            'new_value' => $updated['value']
        ]);
        
        Utils::sendSuccess($updated, 'Настройка успешно обновлена');
    }
    
    public function handleDelete($id) {
        $setting = SettingsModel::find($id);
        if (!$setting) {
            Utils::sendError('Настройка не найдена', 404);
        }
        
        // Нельзя удалять системные настройки
        if (in_array($setting['key'], ['system_name', 'sync_interval', 'log_retention_days'])) {
            Utils::sendError('Нельзя удалять системные настройки', 403);
        }
        
        $deleted = SettingsModel::delete($id);
        if (!$deleted) {
            Utils::sendError('Ошибка при удалении настройки', 500);
        }
        
        // Логирование
        Utils::log('warning', 'Удалена настройка', [
            'key' => $setting['key'],
            'description' => $setting['description']
        ]);
        
        Utils::sendSuccess(null, 'Настройка успешно удалена');
    }
    
    /**
     * Обновление настройки по ключу
     */
    public function handleUpdateByKey($key, $value) {
        $setting = SettingsModel::getByKey($key);
        if (!$setting) {
            Utils::sendError('Настройка не найдена', 404);
        }
        
        $updated = SettingsModel::updateByKey($key, $value);
        
        // Логирование
        Utils::log('info', 'Обновлена настройка по ключу', [
            'key' => $key,
            'old_value' => $setting['value'],
            'new_value' => $value
        ]);
        
        Utils::sendSuccess($updated, 'Настройка успешно обновлена');
    }
}

/**
 * Контроллер статистики
 */
class StatsController extends Controller {
    public function handleGet($id = null) {
        $statsType = $id;
        
        switch ($statsType) {
            case 'todays':
                $stats = StatsModel::getTodaysStats();
                break;
            case 'monitoring':
                $stats = StatsModel::getMonitoringStats();
                break;
            case 'dashboard':
                $stats = [
                    'todays_stats' => StatsModel::getTodaysStats(),
                    'monitoring_stats' => StatsModel::getMonitoringStats(),
                    'connections_summary' => [
                        'total' => count(ConnectionModel::all()),
                        'active' => count(ConnectionModel::where(['status' => 'active'])),
                        'inactive' => count(ConnectionModel::where(['status' => 'inactive']))
                    ],
                    'mappings_summary' => [
                        'total' => count(MappingModel::all()),
                        'by_system' => [
                            'bitrix24' => count(MappingModel::where(['target_system' => 'bitrix24'])),
                            'amocrm' => count(MappingModel::where(['target_system' => 'amocrm']))
                        ]
                    ]
                ];
                break;
            default:
                Utils::sendError('Тип статистики не указан', 400);
                return;
        }
        
        Utils::sendSuccess($stats, 'Статистика получена');
    }
    
    // Эти методы не используются для статистики, но обязательны для абстрактного класса
    public function handlePost($data) {
        Utils::sendError('Метод не поддерживается', 405);
    }
    
    public function handlePut($id, $data) {
        Utils::sendError('Метод не поддерживается', 405);
    }
    
    public function handleDelete($id) {
        Utils::sendError('Метод не поддерживается', 405);
    }
}

// ============================================
// МАРШРУТИЗАТОР
// ============================================

/**
 * Класс маршрутизатора
 */
class Router {
    private $routes = [];
    private $path;
    private $method;
    
    public function __construct() {
        $this->path = Utils::getCurrentPath();
        $this->method = Utils::getRequestMethod();
    }
    
    /**
     * Добавление маршрута
     */
    public function addRoute($pattern, $controller, $action = null) {
        $this->routes[$pattern] = [
            'controller' => $controller,
            'action' => $action
        ];
    }
    
    /**
     * Обработка маршрута
     */
    public function dispatch() {
        // Проверяем, является ли запрос API запросом
        if (Utils::isApiRequest()) {
            $this->handleApiRequest();
        } else {
            $this->handleWebRequest();
        }
    }
    
    /**
     * Обработка API запроса
     */
    private function handleApiRequest() {
        $apiPath = substr($this->path, strlen(Config::API_PREFIX));
        $pathParts = explode('/', trim($apiPath, '/'));
        
        // Определяем ресурс и действие
        $resource = $pathParts[0] ?? '';
        $id = $pathParts[1] ?? null;
        $action = $pathParts[2] ?? null;
        
        // Обработка специальных действий (например, test, sync)
        if ($action && in_array($action, ['test', 'sync', 'test-all', 'clear-all'])) {
            $id = $id ?: null;
        }
        
        // Получение данных запроса
        $requestData = Utils::getJsonInput() ?? $_POST;
        
        // Определение контроллера
        $controller = $this->getControllerForResource($resource);
        if (!$controller) {
            Utils::sendError('Ресурс не найден', 404);
        }
        
        // Обработка запроса в зависимости от метода
        try {
            switch ($this->method) {
                case 'GET':
                    if ($action === 'test-all' && $resource === 'connections') {
                        $controller->handleTestAll();
                    } elseif ($action === 'clear-all' && $resource === 'logs') {
                        $controller->handleClearAll();
                    } else {
                        $controller->handleGet($id);
                    }
                    break;
                    
                case 'POST':
                    if ($action === 'test' && $resource === 'connections' && $id) {
                        $controller->handleTest($id);
                    } elseif ($action === 'sync' && $resource === 'connections' && $id) {
                        $controller->handleSync($id);
                    } elseif ($action === 'update-by-key' && $resource === 'settings') {
                        $key = $_GET['key'] ?? null;
                        $value = $requestData['value'] ?? null;
                        if (!$key || !$value) {
                            Utils::sendError('Не указан ключ или значение', 400);
                        }
                        $controller->handleUpdateByKey($key, $value);
                    } else {
                        $controller->handlePost($requestData);
                    }
                    break;
                    
                case 'PUT':
                    if (!$id) {
                        Utils::sendError('ID не указан', 400);
                    }
                    $controller->handlePut($id, $requestData);
                    break;
                    
                case 'DELETE':
                    if (!$id) {
                        Utils::sendError('ID не указан', 400);
                    }
                    $controller->handleDelete($id);
                    break;
                    
                default:
                    Utils::sendError('Метод не поддерживается', 405);
            }
        } catch (Exception $e) {
            Utils::sendError('Внутренняя ошибка сервера', 500, [
                'message' => $e->getMessage(),
                'trace' => Config::APP_ENV === 'development' ? $e->getTrace() : null
            ]);
        }
    }
    
    /**
     * Получение контроллера для ресурса
     */
    private function getControllerForResource($resource) {
        $controllers = [
            'connections' => new ConnectionController(),
            'mappings' => new MappingController(),
            'logs' => new LogController(),
            'settings' => new SettingsController(),
            'stats' => new StatsController()
        ];
        
        return $controllers[$resource] ?? null;
    }
    
    /**
     * Обработка веб запроса (отдача SPA)
     */
    private function handleWebRequest() {
        // Если это не API запрос, отдаем SPA
        $this->renderSPA();
    }
    
    /**
     * Отдача SPA HTML
     */
    private function renderSPA() {
        // Получаем содержимое SPA из буфера
        ob_start();
        
        // В реальном приложении здесь был бы отдельный файл с SPA
        // Для демо выводим простую HTML страницу с информацией
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo Config::APP_NAME; ?> - Backend API</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    padding: 20px;
                }
                .api-card {
                    background: white;
                    border-radius: 15px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                    padding: 30px;
                    margin-bottom: 30px;
                }
                .endpoint {
                    background: #f8f9fa;
                    border-left: 4px solid #667eea;
                    padding: 15px;
                    margin: 10px 0;
                    border-radius: 5px;
                }
                .method-get { border-left-color: #28a745; }
                .method-post { border-left-color: #007bff; }
                .method-put { border-left-color: #ffc107; }
                .method-delete { border-left-color: #dc3545; }
                code {
                    background: #2d3748;
                    color: #e2e8f0;
                    padding: 2px 8px;
                    border-radius: 4px;
                    font-family: 'Courier New', monospace;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-md-10 col-lg-8">
                        <div class="api-card">
                            <h1 class="text-center mb-4">
                                <i class="fas fa-database"></i> <?php echo Config::APP_NAME; ?>
                            </h1>
                            <p class="text-center text-muted mb-4">
                                Backend API v<?php echo Config::APP_VERSION; ?>
                            </p>
                            
                            <div class="alert alert-info">
                                <h5><i class="fas fa-info-circle"></i> Информация</h5>
                                <p>Это backend API для SPA "Агрегатор данных из CRM". API доступно по адресу <code>/api</code>.</p>
                                <p>Для работы с SPA перейдите к файлу <code>spa.html</code> или используйте API напрямую.</p>
                            </div>
                            
                            <h3 class="mt-5 mb-3">Доступные endpoints:</h3>
                            
                            <div class="endpoint method-get">
                                <strong>GET</strong> <code>/api/connections</code><br>
                                <small>Получить список всех подключений</small>
                            </div>
                            
                            <div class="endpoint method-post">
                                <strong>POST</strong> <code>/api/connections</code><br>
                                <small>Создать новое подключение (требует JSON body)</small>
                            </div>
                            
                            <div class="endpoint method-get">
                                <strong>GET</strong> <code>/api/connections/{id}</code><br>
                                <small>Получить информацию о подключении</small>
                            </div>
                            
                            <div class="endpoint method-put">
                                <strong>PUT</strong> <code>/api/connections/{id}</code><br>
                                <small>Обновить подключение</small>
                            </div>
                            
                            <div class="endpoint method-delete">
                                <strong>DELETE</strong> <code>/api/connections/{id}</code><br>
                                <small>Удалить подключение</small>
                            </div>
                            
                            <div class="endpoint method-post">
                                <strong>POST</strong> <code>/api/connections/{id}/test</code><br>
                                <small>Протестировать подключение</small>
                            </div>
                            
                            <div class="endpoint method-post">
                                <strong>POST</strong> <code>/api/connections/{id}/sync</code><br>
                                <small>Синхронизировать данные подключения</small>
                            </div>
                            
                            <div class="endpoint method-get">
                                <strong>GET</strong> <code>/api/mappings</code><br>
                                <small>Получить список шаблонов сопоставления</small>
                            </div>
                            
                            <div class="endpoint method-get">
                                <strong>GET</strong> <code>/api/logs</code><br>
                                <small>Получить логи операций</small>
                            </div>
                            
                            <div class="endpoint method-get">
                                <strong>GET</strong> <code>/api/settings</code><br>
                                <small>Получить настройки системы</small>
                            </div>
                            
                            <div class="endpoint method-get">
                                <strong>GET</strong> <code>/api/stats/dashboard</code><br>
                                <small>Получить статистику для дашборда</small>
                            </div>
                            
                            <hr class="my-4">
                            
                            <h5>Пример запроса:</h5>
                            <pre class="bg-dark text-light p-3 rounded">
// JavaScript (fetch API)
fetch('/api/connections')
  .then(response => response.json())
  .then(data => console.log(data));
                            </pre>
                            
                            <div class="text-center mt-4">
                                <div class="btn-group">
                                    <a href="/api/connections" class="btn btn-primary" target="_blank">
                                        <i class="fas fa-plug"></i> Test Connections API
                                    </a>
                                    <a href="/api/stats/dashboard" class="btn btn-success" target="_blank">
                                        <i class="fas fa-chart-bar"></i> Test Stats API
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <div class="api-card">
                            <h4><i class="fas fa-server"></i> Статус системы</h4>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5><?php echo count(ConnectionModel::all()); ?></h5>
                                            <small class="text-muted">Подключений</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5><?php echo count(MappingModel::all()); ?></h5>
                                            <small class="text-muted">Шаблонов</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5><?php echo count(LogModel::all()); ?></h5>
                                            <small class="text-muted">Логов</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card bg-light">
                                        <div class="card-body text-center">
                                            <h5><?php echo Config::APP_VERSION; ?></h5>
                                            <small class="text-muted">Версия</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
            <script src="https://kit.fontawesome.com/your-fontawesome-kit.js" crossorigin="anonymous"></script>
        </body>
        </html>
        <?php
        
        $content = ob_get_clean();
        echo $content;
        exit();
    }
}

// ============================================
// ТОЧКА ВХОДА
// ============================================

// Создаем и запускаем маршрутизатор
$router = new Router();
$router->dispatch();

// Завершаем буферизацию
ob_end_flush();
?>