# ИНСТРУКЦИЯ ПО ЗАПУСКУ И ИНТЕГРАЦИИ

## 1. УСТАНОВКА И ЗАПУСК

### Требования:
- PHP 7.4 или выше
- Расширение curl
- Расширение json (обычно встроено)

### Шаг 1: Создание структуры файлов
```bash
# Создайте папку проекта
mkdir aggregator
cd aggregator

# Создайте структуру директорий
mkdir -p data config

# Создайте файлы
touch index.php
touch config/integrations.json
```

### Шаг 2: Настройка конфигурации
Создайте `config/integrations.json`:
```json
{
    "default_crm": {
        "crm_id": "default",
        "crm_type": "generic",
        "api_endpoint": "https://your-crm.com/api/leads",
        "api_key": "your_api_key",
        "field_mapping": {
            "name": "name",
            "phone": "phone",
            "email": "email"
        },
        "webhook_secret": "your_secret_key_here"
    },
    "crm_configs": [
        {
            "crm_id": "client1_crm",
            "crm_type": "amocrm",
            "api_endpoint": "https://client1.amocrm.ru",
            "access_token": "amo_access_token_here",
            "webhook_secret": "secret1"
        },
        {
            "crm_id": "client2_crm",
            "crm_type": "bitrix24",
            "api_endpoint": "https://client2.bitrix24.ru/rest",
            "webhook_secret": "secret2"
        }
    ],
    "landing_crm_mappings": [
        {
            "landing_id": "landing1",
            "crm_id": "client1_crm"
        },
        {
            "landing_id": "landing2",
            "crm_id": "client2_crm"
        }
    ],
    "campaign_crm_mappings": [
        {
            "campaign_id": "yandex_campaign_123",
            "crm_id": "client1_crm"
        },
        {
            "campaign_id": "yandex_campaign_456",
            "crm_id": "client2_crm"
        }
    ],
    "yandex_direct": {
        "campaigns": [
            {
                "campaign_id": "yandex_campaign_123",
                "oauth_token": "ya_oauth_token_for_client1",
                "client_id": "client1"
            },
            {
                "campaign_id": "yandex_campaign_456",
                "oauth_token": "ya_oauth_token_for_client2",
                "client_id": "client2"
            }
        ]
    }
}
```

### Шаг 3: Запуск сервера
```bash
# Запустите веб-сервер (для разработки)
php -S 0.0.0.0:8000 index.php

# В другом терминале запустите воркер для фоновых задач
php index.php --worker
```

### Шаг 4: Проверка работоспособности
Откройте в браузере:
- `http://localhost:8000/` - главная страница API
- `http://localhost:8000/admin` - админ панель

## 2. ИНТЕГРАЦИЯ С ЛЕНДИНГОМ

### Вариант 1: JavaScript (рекомендуется)

Добавьте этот код на лендинг в форму заявки:

```html
<!-- После формы добавьте этот код -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Находим все формы на странице
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Собираем данные формы
            const formData = new FormData(form);
            const data = {};
            
            formData.forEach((value, key) => {
                data[key] = value;
            });
            
            // Добавляем мета-информацию
            const leadData = {
                landing_id: 'landing1', // Укажите ID вашего лендинга
                campaign_id: getUTMParam('utm_campaign') || 'default_campaign',
                form_data: data
            };
            
            // Отправляем в агрегатор
            sendToAggregator(leadData, form);
        });
    });
    
    // Функция отправки в агрегатор
    function sendToAggregator(leadData, originalForm) {
        // URL вашего агрегатора
        const aggregatorUrl = 'http://your-aggregator-domain.com/api/v1/leads';
        
        fetch(aggregatorUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(leadData)
        })
        .then(response => response.json())
        .then(data => {
            console.log('Lead sent successfully:', data);
            
            // Показываем сообщение об успехе
            showSuccessMessage();
            
            // Можно также отправить данные в оригинальную CRM лендинга
            // или оставить только агрегатор
        })
        .catch(error => {
            console.error('Error sending lead:', error);
            showErrorMessage();
        });
    }
    
    // Функция для получения UTM параметров
    function getUTMParam(name) {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get(name);
    }
    
    // Функции отображения сообщений
    function showSuccessMessage() {
        alert('Заявка успешно отправлена! Мы свяжемся с вами в ближайшее время.');
        // Или более красивое уведомление
    }
    
    function showErrorMessage() {
        alert('Произошла ошибка при отправке. Пожалуйста, попробуйте позже.');
    }
});
</script>
```

### Вариант 2: PHP (если лендинг на PHP)

```php
<?php
// В обработчике формы на лендинге
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'name' => $_POST['name'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'email' => $_POST['email'] ?? '',
        // другие поля
    ];
    
    $leadData = [
        'landing_id' => 'landing1', // ID вашего лендинга
        'campaign_id' => $_GET['utm_campaign'] ?? 'default_campaign',
        'form_data' => $formData
    ];
    
    // Отправка в агрегатор
    $ch = curl_init('http://your-aggregator-domain.com/api/v1/leads');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($leadData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $result = json_decode($response, true);
    
    if ($result && isset($result['lead_id'])) {
        // Успешно отправлено
        header('Location: /thank-you.php');
    } else {
        // Ошибка
        header('Location: /error.php');
    }
    
    curl_close($ch);
    exit;
}
?>
```

### Вариант 3: WordPress (для сайтов на WordPress)

Добавьте в functions.php или создайте плагин:

```php
<?php
// Добавляем обработчик формы
add_action('wp_ajax_send_to_aggregator', 'send_to_aggregator');
add_action('wp_ajax_nopriv_send_to_aggregator', 'send_to_aggregator');

function send_to_aggregator() {
    // Проверка nonce для безопасности
    if (!wp_verify_nonce($_POST['nonce'], 'aggregator_nonce')) {
        wp_die('Security check failed');
    }
    
    $formData = [
        'name' => sanitize_text_field($_POST['name']),
        'phone' => sanitize_text_field($_POST['phone']),
        'email' => sanitize_email($_POST['email']),
        // другие поля
    ];
    
    $leadData = [
        'landing_id' => 'wordpress_landing', // ID вашего лендинга
        'campaign_id' => $_POST['campaign_id'] ?? 'default',
        'form_data' => $formData
    ];
    
    // Отправка в агрегатор
    $response = wp_remote_post('http://your-aggregator-domain.com/api/v1/leads', [
        'body' => json_encode($leadData),
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
    ]);
    
    if (is_wp_error($response)) {
        wp_send_json_error('Ошибка отправки');
    } else {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        wp_send_json_success($data);
    }
}

// Добавляем JavaScript для отправки формы
add_action('wp_footer', 'add_aggregator_script');
function add_aggregator_script() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('#your-form-id').on('submit', function(e) {
            e.preventDefault();
            
            var formData = $(this).serializeArray();
            var data = {};
            
            $.each(formData, function(i, field) {
                data[field.name] = field.value;
            });
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'send_to_aggregator',
                    nonce: '<?php echo wp_create_nonce('aggregator_nonce'); ?>',
                    name: data.name,
                    phone: data.phone,
                    email: data.email,
                    campaign_id: getUTMParam('utm_campaign')
                },
                success: function(response) {
                    if (response.success) {
                        alert('Заявка отправлена! ID: ' + response.data.lead_id);
                    } else {
                        alert('Ошибка: ' + response.data);
                    }
                }
            });
        });
        
        function getUTMParam(name) {
            var urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        }
    });
    </script>
    <?php
}
?>
```

## 3. НАСТРОЙКА ДЛЯ ПРОДАКШЕНА

### Конфигурация веб-сервера (Nginx)
```nginx
server {
    listen 80;
    server_name your-aggregator-domain.com;
    root /path/to/aggregator;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

### Конфигурация веб-сервера (Apache)
```apache
<VirtualHost *:80>
    ServerName your-aggregator-domain.com
    DocumentRoot /path/to/aggregator
    
    <Directory /path/to/aggregator>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/aggregator_error.log
    CustomLog ${APACHE_LOG_DIR}/aggregator_access.log combined
</VirtualHost>
```

### Запуск воркера как службы (systemd)
Создайте файл `/etc/systemd/system/aggregator-worker.service`:

```ini
[Unit]
Description=Aggregator Background Worker
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/path/to/aggregator
ExecStart=/usr/bin/php /path/to/aggregator/index.php --worker
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Запустите службу:
```bash
sudo systemctl daemon-reload
sudo systemctl enable aggregator-worker
sudo systemctl start aggregator-worker
sudo systemctl status aggregator-worker
```

## 4. ТЕСТИРОВАНИЕ ИНТЕГРАЦИИ

### Тестовый скрипт для проверки:
```php
<?php
// test_lead.php
$testData = [
    'landing_id' => 'landing1',
    'campaign_id' => 'yandex_campaign_123',
    'form_data' => [
        'name' => 'Иван Иванов',
        'phone' => '+79161234567',
        'email' => 'ivan@test.ru',
        'comment' => 'Тестовая заявка'
    ]
];

$ch = curl_init('http://localhost:8000/api/v1/leads');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$result = json_decode($response, true);

echo "Результат:\n";
print_r($result);

if (isset($result['lead_id'])) {
    echo "\n\nПроверить статус лида:\n";
    echo "http://localhost:8000/api/v1/leads/{$result['lead_id']}\n";
    echo "\nАдмин панель:\n";
    echo "http://localhost:8000/admin\n";
}

curl_close($ch);
```

### Проверка вебхуков:
```bash
# Тестовый вебхук от CRM
curl -X POST http://localhost:8000/api/v1/webhooks/crm/client1_crm \
  -H "Content-Type: application/json" \
  -H "X-Signature: ваш_подписанный_хэш" \
  -d '{
    "event_type": "payment_received",
    "lead_id": "lead_123456",
    "payment_data": {
      "amount": 1000,
      "currency": "RUB",
      "payment_id": "pay_789"
    }
  }'
```

## 5. БЕЗОПАСНОСТЬ

### Для продакшена добавьте:

1. **Аутентификация API** (в начало index.php):
```php
// Проверка API ключа
define('API_KEY', 'your_secret_api_key');

function authenticateApi() {
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals(API_KEY, $providedKey)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }
}

// В контроллерах вызывайте authenticateApi() в начале методов
```

2. **HTTPS**:
```nginx
# Принудительный редирект на HTTPS
server {
    listen 80;
    server_name your-aggregator-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-aggregator-domain.com;
    
    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;
    
    # остальная конфигурация...
}
```

## 6. МОНИТОРИНГ

### Файлы логов:
- `data/events.dat` - все события системы
- `data/tasks.dat` - очередь задач
- Логи веб-сервера
- Логи воркера (systemd journal)

### Проверка работоспособности:
```bash
# Проверка статуса воркера
sudo systemctl status aggregator-worker

# Просмотр логов воркера
sudo journalctl -u aggregator-worker -f

# Проверка дискового пространства
du -sh data/*

# Проверка доступности API
curl -X GET http://localhost:8000/
```

## КРАТКИЙ ЧЕКЛИСТ ДЛЯ ЗАПУСКА:

1. [ ] Установить PHP 7.4+
2. [ ] Скопировать код в index.php
3. [ ] Настроить integrations.json
4. [ ] Создать папки data/ и config/
5. [ ] Запустить веб-сервер: `php -S 0.0.0.0:8000 index.php`
6. [ ] Запустить воркер: `php index.php --worker`
7. [ ] Протестировать API: `curl -X POST http://localhost:8000/api/v1/leads`
8. [ ] Добавить JavaScript код на лендинг
9. [ ] Настроить CORS если нужно (добавить заголовки в PHP)
10. [ ] Настроить продакшен окружение

Система готова к работе! После запуска вы сможете:
- Принимать заявки с лендингов
- Автоматически отправлять их в соответствующие CRM
- Отслеживать оплаты через вебхуки
- Отчитываться в Яндекс.Директ
- Мониторить статистику в админке