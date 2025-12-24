<?php
// ==================== КОНФИГУРАЦИЯ ====================
define('APP_ENV', 'development');
define('EVENT_STORE_FILE', __DIR__ . '/events.dat');
define('TASK_QUEUE_FILE', __DIR__ . '/tasks.dat');
define('INTEGRATIONS_CONFIG', __DIR__ . '/integrations.json');

// ==================== БАЗОВЫЕ ИНТЕРФЕЙСЫ ====================
interface Event {}
interface Command {}
interface IntegrationAdapter {}

// ==================== МОДЕЛИ (M) ====================
abstract class Model
{
    protected static $dataFile;
    
    public static function save($data)
    {
        $items = self::loadAll();
        $items[] = array_merge($data, [
            'id' => uniqid(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        file_put_contents(static::$dataFile, json_encode($items, JSON_PRETTY_PRINT));
    }
    
    public static function loadAll()
    {
        if (!file_exists(static::$dataFile)) {
            return [];
        }
        return json_decode(file_get_contents(static::$dataFile), true) ?? [];
    }
    
    public static function find($id)
    {
        $items = self::loadAll();
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }
}

// ==================== EVENT SOURCING ====================
abstract class DomainEvent implements Event
{
    public $eventId;
    public $aggregateId;
    public $eventType;
    public $payload;
    public $timestamp;
    public $version;
    
    public function __construct($aggregateId, $payload = [])
    {
        $this->eventId = uniqid();
        $this->aggregateId = $aggregateId;
        $this->eventType = static::class;
        $this->payload = $payload;
        $this->timestamp = date('Y-m-d H:i:s');
        $this->version = 1;
    }
}

class LeadCreated extends DomainEvent {}
class LeadSentToCRM extends DomainEvent {}
class PaymentRegistered extends DomainEvent {}

abstract class AbstractCommand implements Command
{
    public $commandId;
    public $aggregateId;
    public $payload;
    
    public function __construct($aggregateId, $payload = [])
    {
        $this->commandId = uniqid();
        $this->aggregateId = $aggregateId;
        $this->payload = $payload;
    }
}

class FileEventStore
{
    private $eventFile;
    
    public function __construct($eventFile)
    {
        $this->eventFile = $eventFile;
        $this->ensureFileExists();
    }
    
    public function append(DomainEvent $event)
    {
        $events = $this->loadEvents();
        $events[] = [
            'event_id' => $event->eventId,
            'aggregate_id' => $event->aggregateId,
            'event_type' => $event->eventType,
            'payload' => $event->payload,
            'timestamp' => $event->timestamp,
            'version' => $event->version
        ];
        
        file_put_contents($this->eventFile, json_encode($events, JSON_PRETTY_PRINT));
        
        $this->dispatch($event);
    }
    
    public function getEventsByAggregate($aggregateId)
    {
        $events = $this->loadEvents();
        return array_filter($events, function($event) use ($aggregateId) {
            return $event['aggregate_id'] === $aggregateId;
        });
    }
    
    public function getAllEvents()
    {
        return $this->loadEvents();
    }
    
    private function loadEvents()
    {
        if (!file_exists($this->eventFile)) {
            return [];
        }
        return json_decode(file_get_contents($this->eventFile), true) ?? [];
    }
    
    private function ensureFileExists()
    {
        $dir = dirname($this->eventFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($this->eventFile)) {
            file_put_contents($this->eventFile, '[]');
        }
    }
    
    private function dispatch(DomainEvent $event)
    {
        $eventHandlers = [
            LeadCreated::class => [],
            PaymentRegistered::class => ['YandexDirectIntegration@onPaymentRegistered']
        ];
        
        if (isset($eventHandlers[$event->eventType])) {
            foreach ($eventHandlers[$event->eventType] as $handler) {
                list($class, $method) = explode('@', $handler);
                if (class_exists($class)) {
                    $instance = new $class();
                    $instance->$method($event);
                }
            }
        }
    }
}

class LeadAggregate
{
    private $id;
    private $data;
    private $status;
    private $crmId;
    private $campaignId;
    private $events = [];
    private $version = 0;
    
    public function __construct($id)
    {
        $this->id = $id;
    }
    
    public static function create($formData, $campaignId = null)
    {
        $aggregateId = uniqid('lead_');
        $aggregate = new self($aggregateId);
        
        $event = new LeadCreated($aggregateId, [
            'form_data' => $formData,
            'initial_status' => 'new',
            'campaign_id' => $campaignId
        ]);
        
        $aggregate->apply($event);
        return $aggregate;
    }
    
    public function sendToCRM($crmConfig)
    {
        $event = new LeadSentToCRM($this->id, [
            'crm_config' => $crmConfig,
            'status' => 'sent_to_crm'
        ]);
        
        $this->apply($event);
    }
    
    public function registerPayment($paymentData)
    {
        $event = new PaymentRegistered($this->id, [
            'payment_data' => $paymentData,
            'status' => 'paid'
        ]);
        
        $this->apply($event);
    }
    
    public function apply(DomainEvent $event)
    {
        $this->version++;
        
        switch ($event->eventType) {
            case LeadCreated::class:
                $this->data = $event->payload['form_data'];
                $this->status = 'new';
                $this->campaignId = $event->payload['campaign_id'] ?? null;
                break;
            case LeadSentToCRM::class:
                $this->status = 'sent_to_crm';
                $this->crmId = $event->payload['crm_config']['crm_id'] ?? null;
                break;
            case PaymentRegistered::class:
                $this->status = 'paid';
                break;
        }
        
        $this->events[] = $event;
    }
    
    public function getId() { return $this->id; }
    public function getData() { return $this->data; }
    public function getStatus() { return $this->status; }
    public function getCampaignId() { return $this->campaignId; }
    public function getEvents() { return $this->events; }
    public function getVersion() { return $this->version; }
}

// ==================== ИНТЕГРАЦИИ ====================
class HttpClient
{
    public function post($url, $data, $headers = [])
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
            'Content-Type: application/json'
        ], $headers));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'status' => $httpCode,
            'body' => json_decode($response, true) ?? $response
        ];
    }
}

abstract class BaseIntegrationAdapter implements IntegrationAdapter
{
    protected $config;
    protected $httpClient;
    
    public function __construct($config)
    {
        $this->config = $config;
        $this->httpClient = new HttpClient();
    }
    
    abstract public function sendLead($leadData);
    abstract public function handleWebhook($webhookData);
}

class YandexDirectIntegration
{
    private $config;
    
    public function __construct($config)
    {
        $this->config = $config;
    }
    
    public function sendConversion($campaignId, $leadId, $conversionType, $value = null)
    {
        // Находим конфигурацию для конкретной кампании
        $campaignConfig = null;
        foreach ($this->config['campaigns'] ?? [] as $campaign) {
            if ($campaign['campaign_id'] === $campaignId) {
                $campaignConfig = $campaign;
                break;
            }
        }
        
        if (!$campaignConfig) {
            error_log("No Yandex.Direct config found for campaign: $campaignId");
            return ['status' => 404, 'body' => 'Campaign not found'];
        }
        
        $url = "https://api.direct.yandex.ru/live/v4/json/";
        
        $data = [
            'method' => 'AddOfflineConversions',
            'param' => [
                'Conversions' => [
                    [
                        'CampaignID' => $campaignId,
                        'Yclid' => $leadId,
                        'ConversionType' => $conversionType,
                        'Value' => $value,
                        'DateTime' => date('Y-m-d\TH:i:s')
                    ]
                ]
            ],
            'token' => $campaignConfig['oauth_token']
        ];
        
        $httpClient = new HttpClient();
        return $httpClient->post($url, $data);
    }
    
    public function onPaymentRegistered(DomainEvent $event)
    {
        // Для отправки в Яндекс.Директ нужно знать campaign_id
        // Он должен быть сохранен в лиде при создании
        $leadId = $event->aggregateId;
        $paymentAmount = $event->payload['payment_data']['amount'] ?? 0;
        
        // Получаем campaign_id из событий лида
        $eventStore = new FileEventStore(EVENT_STORE_FILE);
        $leadEvents = $eventStore->getEventsByAggregate($leadId);
        
        $campaignId = null;
        foreach ($leadEvents as $leadEvent) {
            if ($leadEvent['event_type'] === 'LeadCreated' && isset($leadEvent['payload']['campaign_id'])) {
                $campaignId = $leadEvent['payload']['campaign_id'];
                break;
            }
        }
        
        if ($campaignId) {
            return $this->sendConversion($campaignId, $leadId, 'PAYMENT', $paymentAmount);
        } else {
            error_log("No campaign_id found for lead: $leadId");
            return ['status' => 400, 'body' => 'No campaign_id found'];
        }
    }
}

class CrmIntegration extends BaseIntegrationAdapter
{
    public function sendLead($leadData)
    {
        $crmType = $this->config['crm_type'] ?? 'generic';
        
        switch ($crmType) {
            case 'amocrm':
                return $this->sendToAmoCRM($leadData);
            case 'bitrix24':
                return $this->sendToBitrix24($leadData);
            default:
                return $this->sendToGenericCRM($leadData);
        }
    }
    
    private function sendToAmoCRM($leadData)
    {
        $url = $this->config['api_endpoint'] . '/api/v4/leads';
        $headers = [
            'Authorization: Bearer ' . ($this->config['access_token'] ?? '')
        ];
        
        $data = [
            'name' => $leadData['name'] ?? 'Новый лид',
            'price' => $leadData['price'] ?? 0,
            'custom_fields_values' => $this->mapFields($leadData)
        ];
        
        return $this->httpClient->post($url, $data, $headers);
    }
    
    private function sendToBitrix24($leadData)
    {
        $url = $this->config['api_endpoint'] . '/crm.lead.add.json';
        
        $data = [
            'fields' => [
                'TITLE' => $leadData['name'] ?? 'Новый лид',
                'NAME' => $leadData['first_name'] ?? '',
                'LAST_NAME' => $leadData['last_name'] ?? '',
                'OPPORTUNITY' => $leadData['price'] ?? 0,
                'SOURCE_ID' => 'WEB'
            ]
        ];
        
        return $this->httpClient->post($url, $data);
    }
    
    private function sendToGenericCRM($leadData)
    {
        $url = $this->config['api_endpoint'] ?? '';
        $mapping = $this->config['field_mapping'] ?? [];
        
        $mappedData = [];
        foreach ($mapping as $source => $target) {
            if (isset($leadData[$source])) {
                $mappedData[$target] = $leadData[$source];
            }
        }
        
        // Добавляем API ключ, если есть
        if (!empty($this->config['api_key'])) {
            $mappedData['api_key'] = $this->config['api_key'];
        }
        
        return $this->httpClient->post($url, $mappedData);
    }
    
    public function handleWebhook($webhookData)
    {
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        $expectedSignature = hash_hmac('sha256', json_encode($webhookData), 
                                     $this->config['webhook_secret'] ?? '');
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new Exception('Invalid webhook signature');
        }
        
        $eventType = $webhookData['event_type'] ?? '';
        $leadId = $webhookData['lead_id'] ?? '';
        
        $eventStore = new FileEventStore(EVENT_STORE_FILE);
        
        switch ($eventType) {
            case 'payment_received':
                $event = new PaymentRegistered($leadId, $webhookData);
                $eventStore->append($event);
                break;
        }
        
        return ['status' => 'processed'];
    }
    
    private function mapFields($leadData)
    {
        $mapping = $this->config['field_mapping'] ?? [];
        $mapped = [];
        
        foreach ($mapping as $source => $target) {
            if (isset($leadData[$source])) {
                $mapped[] = [
                    'field_id' => $target,
                    'values' => [['value' => $leadData[$source]]]
                ];
            }
        }
        
        return $mapped;
    }
}

// ==================== СИСТЕМА ФОНОВЫХ ЗАДАЧ ====================
class FileTaskQueue
{
    private $queueFile;
    
    public function __construct($queueFile)
    {
        $this->queueFile = $queueFile;
        $this->ensureFileExists();
    }
    
    public function push($taskType, $payload, $delay = 0)
    {
        $tasks = $this->loadTasks();
        
        $tasks[] = [
            'id' => uniqid(),
            'type' => $taskType,
            'payload' => $payload,
            'status' => 'pending',
            'created_at' => time(),
            'execute_after' => time() + $delay,
            'attempts' => 0,
            'max_attempts' => 3
        ];
        
        $this->saveTasks($tasks);
    }
    
    public function pop()
    {
        $tasks = $this->loadTasks();
        
        foreach ($tasks as $index => $task) {
            if ($task['status'] === 'pending' && $task['execute_after'] <= time()) {
                $tasks[$index]['status'] = 'processing';
                $tasks[$index]['started_at'] = time();
                $tasks[$index]['attempts']++;
                
                $this->saveTasks($tasks);
                return $task;
            }
        }
        
        return null;
    }
    
    public function complete($taskId, $result = null)
    {
        $tasks = $this->loadTasks();
        
        foreach ($tasks as $index => $task) {
            if ($task['id'] === $taskId) {
                $tasks[$index]['status'] = 'completed';
                $tasks[$index]['completed_at'] = time();
                $tasks[$index]['result'] = $result;
                break;
            }
        }
        
        $this->saveTasks($tasks);
    }
    
    public function fail($taskId, $error = null)
    {
        $tasks = $this->loadTasks();
        
        foreach ($tasks as $index => $task) {
            if ($task['id'] === $taskId) {
                if ($task['attempts'] >= $task['max_attempts']) {
                    $tasks[$index]['status'] = 'failed';
                } else {
                    $tasks[$index]['status'] = 'pending';
                    $tasks[$index]['execute_after'] = time() + pow(2, $task['attempts']) * 60;
                }
                $tasks[$index]['error'] = $error;
                break;
            }
        }
        
        $this->saveTasks($tasks);
    }
    
    private function loadTasks()
    {
        if (!file_exists($this->queueFile)) {
            return [];
        }
        return json_decode(file_get_contents($this->queueFile), true) ?? [];
    }
    
    private function saveTasks($tasks)
    {
        file_put_contents($this->queueFile, json_encode($tasks, JSON_PRETTY_PRINT));
    }
    
    private function ensureFileExists()
    {
        $dir = dirname($this->queueFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!file_exists($this->queueFile)) {
            file_put_contents($this->queueFile, '[]');
        }
    }
}

class BackgroundWorker
{
    private $queue;
    private $handlers = [];
    private $running = false;
    
    public function __construct(FileTaskQueue $queue)
    {
        $this->queue = $queue;
    }
    
    public function registerHandler($taskType, callable $handler)
    {
        $this->handlers[$taskType] = $handler;
    }
    
    public function start()
    {
        $this->running = true;
        echo "Worker started at " . date('Y-m-d H:i:s') . "\n";
        
        while ($this->running) {
            $task = $this->queue->pop();
            
            if ($task) {
                $this->processTask($task);
            } else {
                sleep(5);
            }
            
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }
    
    public function stop()
    {
        $this->running = false;
        echo "Worker stopped at " . date('Y-m-d H:i:s') . "\n";
    }
    
    private function processTask($task)
    {
        $taskType = $task['type'];
        $taskId = $task['id'];
        
        echo "Processing task: {$taskType} [{$taskId}]\n";
        
        if (isset($this->handlers[$taskType])) {
            try {
                $result = call_user_func($this->handlers[$taskType], $task['payload']);
                $this->queue->complete($taskId, $result);
                echo "Task completed successfully\n";
            } catch (Exception $e) {
                $this->queue->fail($taskId, $e->getMessage());
                echo "Task failed: " . $e->getMessage() . "\n";
            }
        } else {
            $this->queue->fail($taskId, "No handler for task type: {$taskType}");
            echo "No handler for task type: {$taskType}\n";
        }
    }
}

class TaskHandlers
{
    public static function sendToCrmHandler($payload)
    {
        $leadId = $payload['lead_id'];
        $crmConfig = $payload['crm_config'];
        
        $eventStore = new FileEventStore(EVENT_STORE_FILE);
        $events = $eventStore->getEventsByAggregate($leadId);
        
        if (empty($events)) {
            throw new Exception("Lead not found: {$leadId}");
        }
        
        $leadData = $events[0]['payload']['form_data'] ?? [];
        
        $crmIntegration = new CrmIntegration($crmConfig);
        $result = $crmIntegration->sendLead($leadData);
        
        if ($result['status'] >= 200 && $result['status'] < 300) {
            $event = new LeadSentToCRM($leadId, [
                'crm_response' => $result,
                'crm_config' => $crmConfig
            ]);
            $eventStore->append($event);
        } else {
            throw new Exception("CRM request failed: " . json_encode($result));
        }
        
        return $result;
    }
    
    public static function retryFailedTasksHandler()
    {
        $queueFile = TASK_QUEUE_FILE;
        if (!file_exists($queueFile)) {
            return ['processed' => 0];
        }
        
        $tasks = json_decode(file_get_contents($queueFile), true) ?? [];
        $processed = 0;
        
        foreach ($tasks as $index => $task) {
            if ($task['status'] === 'failed' && $task['attempts'] < $task['max_attempts']) {
                $tasks[$index]['status'] = 'pending';
                $tasks[$index]['execute_after'] = time() + 300;
                $processed++;
            }
        }
        
        file_put_contents($queueFile, json_encode($tasks, JSON_PRETTY_PRINT));
        
        return ['processed' => $processed];
    }
}

// ==================== КОНТРОЛЛЕРЫ (C) ====================
abstract class Controller
{
    protected function jsonResponse($data, $status = 200)
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
    
    protected function getRequestData()
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }
}

class LeadController extends Controller
{
    private $eventStore;
    private $taskQueue;
    
    public function __construct()
    {
        $this->eventStore = new FileEventStore(EVENT_STORE_FILE);
        $this->taskQueue = new FileTaskQueue(TASK_QUEUE_FILE);
    }
    
    public function create()
    {
        $data = $this->getRequestData();
        
        if (empty($data['form_data'])) {
            return $this->jsonResponse(['error' => 'form_data is required'], 400);
        }
        
        // Получаем campaign_id из данных (должен приходить с лендинга)
        $campaignId = $data['campaign_id'] ?? $data['utm_campaign'] ?? null;
        
        // Создаем лид с campaign_id
        $leadAggregate = LeadAggregate::create($data['form_data'], $campaignId);
        
        // Сохраняем события
        foreach ($leadAggregate->getEvents() as $event) {
            $this->eventStore->append($event);
        }
        
        // Получаем конфигурацию CRM для этого лендинга/кампании
        $crmConfig = $this->getCrmConfigForLanding($data['landing_id'] ?? 'default', $campaignId);
        
        // Ставим задачу на отправку в CRM
        $this->taskQueue->push('send_to_crm', [
            'lead_id' => $leadAggregate->getId(),
            'crm_config' => $crmConfig
        ]);
        
        return $this->jsonResponse([
            'lead_id' => $leadAggregate->getId(),
            'campaign_id' => $campaignId,
            'status' => 'created',
            'message' => 'Lead created and queued for CRM'
        ]);
    }
    
    public function get($leadId)
    {
        $events = $this->eventStore->getEventsByAggregate($leadId);
        
        if (empty($events)) {
            return $this->jsonResponse(['error' => 'Lead not found'], 404);
        }
        
        // Восстанавливаем состояние агрегата
        $leadAggregate = new LeadAggregate($leadId);
        foreach ($events as $eventData) {
            $eventClass = $eventData['event_type'];
            if (class_exists($eventClass)) {
                $event = new $eventClass($leadId, $eventData['payload']);
                $leadAggregate->apply($event);
            }
        }
        
        return $this->jsonResponse([
            'id' => $leadAggregate->getId(),
            'data' => $leadAggregate->getData(),
            'status' => $leadAggregate->getStatus(),
            'campaign_id' => $leadAggregate->getCampaignId(),
            'events' => $events
        ]);
    }
    
    private function getCrmConfigForLanding($landingId, $campaignId = null)
    {
        if (!file_exists(INTEGRATIONS_CONFIG)) {
            return ['crm_type' => 'generic', 'api_endpoint' => ''];
        }
        
        $configs = json_decode(file_get_contents(INTEGRATIONS_CONFIG), true);
        
        // Сначала ищем по landing_id
        foreach ($configs['landing_crm_mappings'] ?? [] as $mapping) {
            if ($mapping['landing_id'] === $landingId) {
                // Находим конфигурацию CRM по crm_id из маппинга
                foreach ($configs['crm_configs'] ?? [] as $crmConfig) {
                    if ($crmConfig['crm_id'] === $mapping['crm_id']) {
                        return $crmConfig;
                    }
                }
            }
        }
        
        // Если есть campaign_id, ищем по нему
        if ($campaignId) {
            foreach ($configs['campaign_crm_mappings'] ?? [] as $mapping) {
                if ($mapping['campaign_id'] === $campaignId) {
                    foreach ($configs['crm_configs'] ?? [] as $crmConfig) {
                        if ($crmConfig['crm_id'] === $mapping['crm_id']) {
                            return $crmConfig;
                        }
                    }
                }
            }
        }
        
        // Конфигурация по умолчанию
        return $configs['default_crm'] ?? ['crm_type' => 'generic', 'api_endpoint' => ''];
    }
}

class WebhookController extends Controller
{
    public function crmWebhook($crmId)
    {
        $data = $this->getRequestData();
        
        $config = $this->getCrmConfig($crmId);
        
        if (!$config) {
            return $this->jsonResponse(['error' => 'CRM configuration not found'], 404);
        }
        
        try {
            $crmIntegration = new CrmIntegration($config);
            $result = $crmIntegration->handleWebhook($data);
            
            return $this->jsonResponse([
                'status' => 'success',
                'message' => 'Webhook processed',
                'result' => $result
            ]);
        } catch (Exception $e) {
            return $this->jsonResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
    
    private function getCrmConfig($crmId)
    {
        if (!file_exists(INTEGRATIONS_CONFIG)) {
            return null;
        }
        
        $configs = json_decode(file_get_contents(INTEGRATIONS_CONFIG), true);
        
        foreach ($configs['crm_configs'] ?? [] as $config) {
            if ($config['crm_id'] === $crmId) {
                return $config;
            }
        }
        
        return null;
    }
}

class AdminController extends Controller
{
    public function dashboard()
    {
        $eventStore = new FileEventStore(EVENT_STORE_FILE);
        $taskQueue = new FileTaskQueue(TASK_QUEUE_FILE);
        
        $events = $eventStore->getAllEvents();
        $tasks = [];
        if (file_exists(TASK_QUEUE_FILE)) {
            $tasks = json_decode(file_get_contents(TASK_QUEUE_FILE), true) ?? [];
        }
        
        echo "<h1>Aggregator Admin Dashboard</h1>";
        echo "<h2>Statistics</h2>";
        echo "<p>Total Events: " . count($events) . "</p>";
        echo "<p>Total Tasks: " . count($tasks) . "</p>";
        
        // Статистика по кампаниям
        $campaignStats = [];
        foreach ($events as $event) {
            if ($event['event_type'] === 'LeadCreated' && isset($event['payload']['campaign_id'])) {
                $campaignId = $event['payload']['campaign_id'];
                if (!isset($campaignStats[$campaignId])) {
                    $campaignStats[$campaignId] = ['leads' => 0, 'payments' => 0];
                }
                $campaignStats[$campaignId]['leads']++;
            }
            if ($event['event_type'] === 'PaymentRegistered') {
                // Находим campaign_id для этого лида
                $leadEvents = $eventStore->getEventsByAggregate($event['aggregate_id']);
                foreach ($leadEvents as $leadEvent) {
                    if ($leadEvent['event_type'] === 'LeadCreated' && isset($leadEvent['payload']['campaign_id'])) {
                        $campaignId = $leadEvent['payload']['campaign_id'];
                        if (!isset($campaignStats[$campaignId])) {
                            $campaignStats[$campaignId] = ['leads' => 0, 'payments' => 0];
                        }
                        $campaignStats[$campaignId]['payments']++;
                        break;
                    }
                }
            }
        }
        
        echo "<h2>Campaign Statistics</h2>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Campaign ID</th><th>Leads</th><th>Payments</th><th>Conversion</th></tr>";
        foreach ($campaignStats as $campaignId => $stats) {
            $conversion = $stats['leads'] > 0 ? round(($stats['payments'] / $stats['leads']) * 100, 2) . '%' : '0%';
            echo "<tr>";
            echo "<td>{$campaignId}</td>";
            echo "<td>{$stats['leads']}</td>";
            echo "<td>{$stats['payments']}</td>";
            echo "<td>{$conversion}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<h2>Recent Events</h2>";
        echo "<ul>";
        foreach (array_slice($events, -10) as $event) {
            echo "<li>{$event['event_type']} - {$event['timestamp']} (Lead: {$event['aggregate_id']})</li>";
        }
        echo "</ul>";
        
        echo "<h2>Task Queue Status</h2>";
        $statusCounts = array_count_values(array_column($tasks, 'status'));
        foreach ($statusCounts as $status => $count) {
            echo "<p>{$status}: {$count}</p>";
        }
    }
}

// ==================== МАРШРУТИЗАЦИЯ ====================
class Router
{
    private $routes = [];
    
    public function add($method, $path, $handler)
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler
        ];
    }
    
    public function dispatch()
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && 
                $this->matchPath($route['path'], $requestPath, $params)) {
                return $this->executeHandler($route['handler'], $params);
            }
        }
        
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
    }
    
    private function matchPath($pattern, $path, &$params = [])
    {
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $pattern = "#^{$pattern}$#";
        
        if (preg_match($pattern, $path, $matches)) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return true;
        }
        return false;
    }
    
    private function executeHandler($handler, $params = [])
    {
        if (is_callable($handler)) {
            return call_user_func_array($handler, array_values($params));
        }
        
        if (is_string($handler)) {
            list($controller, $method) = explode('@', $handler);
            $controllerInstance = new $controller();
            return call_user_func_array([$controllerInstance, $method], array_values($params));
        }
    }
}

// ==================== НАСТРОЙКА МАРШРУТОВ ====================
$router = new Router();

$router->add('POST', '/api/v1/leads', function() {
    $controller = new LeadController();
    return $controller->create();
});

$router->add('GET', '/api/v1/leads/{leadId}', function($leadId) {
    $controller = new LeadController();
    return $controller->get($leadId);
});

$router->add('POST', '/api/v1/webhooks/crm/{crmId}', function($crmId) {
    $controller = new WebhookController();
    return $controller->crmWebhook($crmId);
});

$router->add('GET', '/admin', function() {
    $controller = new AdminController();
    return $controller->dashboard();
});

$router->add('GET', '/', function() {
    echo "<h1>Aggregator API</h1>";
    echo "<p>Available endpoints:</p>";
    echo "<ul>";
    echo "<li>POST /api/v1/leads - Create lead</li>";
    echo "<li>GET /api/v1/leads/{id} - Get lead</li>";
    echo "<li>POST /api/v1/webhooks/crm/{crmId} - CRM webhook</li>";
    echo "<li>GET /admin - Admin dashboard</li>";
    echo "</ul>";
});

// ==================== ТОЧКА ВХОДА ====================
if (php_sapi_name() === 'cli') {
    if (in_array('--worker', $argv)) {
        echo "Starting background worker...\n";
        
        $queue = new FileTaskQueue(TASK_QUEUE_FILE);
        $worker = new BackgroundWorker($queue);
        
        $worker->registerHandler('send_to_crm', ['TaskHandlers', 'sendToCrmHandler']);
        $worker->registerHandler('retry_failed', ['TaskHandlers', 'retryFailedTasksHandler']);
        
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function() use ($worker) {
                $worker->stop();
                exit(0);
            });
            pcntl_signal(SIGTERM, function() use ($worker) {
                $worker->stop();
                exit(0);
            });
        }
        
        $worker->start();
    } elseif (in_array('--task', $argv)) {
        $taskType = $argv[2] ?? '';
        $queue = new FileTaskQueue(TASK_QUEUE_FILE);
        
        if ($taskType === 'retry_failed') {
            $queue->push('retry_failed', []);
            echo "Retry task queued\n";
        }
    } else {
        echo "Usage:\n";
        echo "  php index.php --worker       Start background worker\n";
        echo "  php index.php --task retry_failed  Queue retry task\n";
    }
} else {
    $router->dispatch();
}