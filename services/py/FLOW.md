# Поток данных в приложении агрегатора

```mermaid
sequenceDiagram
    participant Клиент as Клиент/Лендинг
    participant LC as LeadController
    participant LS as LeadService
    participant ES as EventStore
    participant CS as CampaignService
    participant TQ as TaskQueue
    participant BTP as BackgroundTaskProcessor
    participant CI as CrmIntegration
    participant WC as WebhookController
    participant YDI as YandexDirectIntegration
    participant DB as SQLite Database

    Note over Клиент,DB: СЦЕНАРИЙ 1: СОЗДАНИЕ ЛИДА
    
    Клиент->>LC: POST /api/v1/leads
    activate LC
    
    LC->>LC: Валидация данных (Pydantic)
    LC->>LS: create_lead(form_data, campaign_id)
    activate LS
    
    LS->>DB: INSERT INTO leads
    DB-->>LS: lead_id
    LS-->>LC: Объект Lead
    
    LC->>ES: save_event(lead_id, LEAD_CREATED)
    activate ES
    ES->>DB: INSERT INTO events
    DB-->>ES: event_id
    ES-->>LC: Объект Event
    
    LC->>CS: increment_lead_count(campaign_id)
    activate CS
    CS->>DB: UPDATE campaign_stats
    DB-->>CS: Обновленная статистика
    CS-->>LC: Объект CampaignStat
    
    LC->>TQ: create_task(SEND_TO_CRM, payload)
    activate TQ
    TQ->>DB: INSERT INTO tasks
    DB-->>TQ: task_id
    TQ-->>LC: Объект Task
    
    LC-->>Клиент: 200 OK с lead_id и task_id
    deactivate LC
    
    Note over BTP,TQ: ФОНОВАЯ ОБРАБОТКА ЗАДАЧ
    
    BTP->>TQ: get_pending_tasks()
    TQ->>DB: SELECT * FROM tasks WHERE status='pending'
    DB-->>TQ: Список задач
    TQ-->>BTP: Список задач
    
    loop Для каждой задачи
        BTP->>TQ: update_task_status(task_id, PROCESSING)
        TQ->>DB: UPDATE tasks SET status='processing'
        DB-->>TQ: Подтверждение
        
        BTP->>CI: send_lead(lead_data)
        activate CI
        CI->>CI: Определение типа CRM
        CI->>Внешний API: HTTP POST к CRM
        Внешний API-->>CI: Ответ CRM
        CI-->>BTP: Результат отправки
        
        BTP->>LS: update_lead_status(lead_id, 'sent_to_crm')
        LS->>DB: UPDATE leads SET status='sent_to_crm'
        DB-->>LS: Подтверждение
        LS-->>BTP: Объект Lead
        
        BTP->>ES: save_event(lead_id, LEAD_SENT_TO_CRM)
        ES->>DB: INSERT INTO events
        DB-->>ES: event_id
        ES-->>BTP: Объект Event
        
        BTP->>TQ: update_task_status(task_id, COMPLETED, result)
        TQ->>DB: UPDATE tasks SET status='completed'
        DB-->>TQ: Подтверждение
    end
    
    Note over Клиент,DB: СЦЕНАРИЙ 2: ОБРАБОТКА ПЛАТЕЖА
    
    Клиент->>WC: POST /api/v1/webhooks/crm/{crm_id}
    activate WC
    
    WC->>WC: Валидация вебхука (Pydantic)
    WC->>LS: update_lead_status(lead_id, 'paid', payment_data)
    activate LS
    
    LS->>DB: UPDATE leads SET status='paid', payment_amount=...
    DB-->>LS: Подтверждение
    LS-->>WC: Объект Lead
    
    WC->>ES: save_event(lead_id, PAYMENT_REGISTERED)
    activate ES
    ES->>DB: INSERT INTO events
    DB-->>ES: event_id
    ES-->>WC: Объект Event
    
    WC->>CS: increment_payment_count(campaign_id, amount)
    activate CS
    CS->>DB: UPDATE campaign_stats
    DB-->>CS: Обновленная статистика
    CS-->>WC: Объект CampaignStat
    
    WC->>TQ: create_task(SEND_TO_YANDEX_DIRECT, payload)
    activate TQ
    TQ->>DB: INSERT INTO tasks
    DB-->>TQ: task_id
    TQ-->>WC: Объект Task
    
    WC-->>Клиент: 200 OK с yandex_direct_task_id
    deactivate WC
    
    Note over BTP,YDI: ФОНОВАЯ ОБРАБОТКА Яндекс.Директ
    
    BTP->>TQ: get_pending_tasks()
    TQ->>DB: SELECT * FROM tasks WHERE type='send_to_yandex_direct'
    DB-->>TQ: Список задач
    TQ-->>BTP: Список задач
    
    loop Для каждой задачи Яндекс.Директ
        BTP->>LS: get_lead(lead_id)
        LS->>DB: SELECT * FROM leads WHERE lead_id=?
        DB-->>LS: Объект Lead
        LS-->>BTP: Объект Lead
        
        BTP->>YDI: send_conversion(campaign_id, lead_id, 'PAYMENT', amount)
        activate YDI
        YDI->>Яндекс.API: HTTP POST с конверсией
        Яндекс.API-->>YDI: Ответ API
        YDI-->>BTP: Результат
        
        BTP->>ES: save_event(lead_id, YANDEX_DIRECT_CONVERSION)
        ES->>DB: INSERT INTO events
        DB-->>ES: event_id
        ES-->>BTP: Объект Event
        
        BTP->>TQ: update_task_status(task_id, COMPLETED)
        TQ->>DB: UPDATE tasks SET status='completed'
        DB-->>TQ: Подтверждение
    end
    
    Note over Клиент,DB: СЦЕНАРИЙ 3: АДМИН-ПАНЕЛЬ
    
    Клиент->>LC: GET /admin
    activate LC
    
    LC->>LS: (через сессию) SELECT COUNT(*) FROM leads
    LS->>DB: SQL запрос
    DB-->>LS: total_leads
    LS-->>LC: total_leads
    
    LC->>ES: get_event_count()
    ES->>DB: SELECT COUNT(*) FROM events
    DB-->>ES: total_events
    ES-->>LC: total_events
    
    LC->>CS: get_campaign_stats()
    CS->>DB: SELECT * FROM campaign_stats
    DB-->>CS: Статистика кампаний
    CS-->>LC: Список CampaignStat
    
    LC->>LS: (через сессию) SELECT * FROM leads ORDER BY created_at DESC LIMIT 10
    LS->>DB: SQL запрос
    DB-->>LS: Последние лиды
    LS-->>LC: Список Lead
    
    LC->>ES: get_all_events(limit=10)
    ES->>DB: SELECT * FROM events ORDER BY created_at DESC LIMIT 10
    DB-->>ES: Последние события
    ES-->>LC: Список Event
    
    LC-->>Клиент: HTML страница с данными
    deactivate LC
```

## Описание взаимодействий по классам:

### **LeadController** (HTTP контроллер)
- Принимает HTTP запросы на создание лидов
- Взаимодействует с:
  - `LeadService` для создания/получения лидов
  - `EventStore` для сохранения событий
  - `CampaignService` для обновления статистики
  - `TaskQueue` для создания фоновых задач

### **LeadService** (Бизнес-логика лидов)
- Содержит методы для работы с лидами
- Использует SQLModel для ORM операций
- Работает с таблицей `leads` в SQLite

### **EventStore** (Event Sourcing хранилище)
- Сохраняет все события системы
- Поддерживает восстановление состояния агрегатов
- Работает с таблицей `events` в SQLite

### **CampaignService** (Статистика кампаний)
- Считает лиды и платежи по кампаниям
- Обновляет таблицу `campaign_stats`
- Предоставляет данные для отчетов

### **TaskQueue** (Очередь задач)
- Создает и управляет фоновыми задачами
- Работает с таблицей `tasks` в SQLite
- Предоставляет задачи для обработки `BackgroundTaskProcessor`

### **BackgroundTaskProcessor** (Фоновый обработчик)
- Постоянный процесс, опрашивающий очередь задач
- Выполняет задачи асинхронно
- Обновляет статусы задач

### **CrmIntegration** (Интеграция с CRM)
- Отправляет лиды в различные CRM системы
- Поддерживает AmoCRM, Bitrix24, generic CRM
- Выполняет HTTP запросы к внешним API

### **WebhookController** (Обработчик вебхуков)
- Принимает вебхуки от CRM о платежах
- Обновляет статусы лидов
- Запускает задачи для Яндекс.Директ

### **YandexDirectIntegration** (Интеграция с Яндекс.Директ)
- Отправляет конверсии в Яндекс.Директ API
- Использует данные о платежах для отслеживания конверсий

### **SQLite Database** (База данных)
- Хранит все данные приложения:
  - `leads` - проекция лидов
  - `events` - события Event Sourcing
  - `tasks` - фоновые задачи
  - `campaign_stats` - статистика кампаний

## Ключевые особенности потока данных:

1. **Event Sourcing**: Все изменения сохраняются как события в таблице `events`
2. **Проекции**: Таблица `leads` представляет собой оптимизированную проекцию для быстрого доступа
3. **Фоновые задачи**: Длительные операции (отправка в CRM, Яндекс.Директ) выполняются асинхронно
4. **Статистика в реальном времени**: `campaign_stats` автоматически обновляется при каждом лиде/платеже
5. **Восстановление состояния**: Можно восстановить состояние любого лида по цепочке событий

Такая архитектура обеспечивает надежность, масштабируемость и простоту отладки системы.