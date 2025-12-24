# main.py
from fastapi import FastAPI, BackgroundTasks, HTTPException, Depends
from fastapi.responses import HTMLResponse, JSONResponse
from pydantic import BaseModel, Field
from typing import Dict, List, Optional, Any
import json
import uuid
import asyncio
from datetime import datetime, timedelta
import logging
from contextlib import asynccontextmanager
import aiohttp
from sqlmodel import select, update, delete
from sqlalchemy import func, desc
from sqlalchemy.ext.asyncio import AsyncSession

from database import (
    init_db, get_session, Event, Task, Lead, CampaignStat,
    EventType, TaskStatus, TaskType
)

# ==================== КОНФИГУРАЦИЯ ====================
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI(
    title="Aggregator Service",
    description="Сервис агрегации лидов и интеграций с SQLite",
    version="1.0.0"
)

INTEGRATIONS_CONFIG = "integrations.json"

# ==================== МОДЕЛИ PYDANTIC ====================
class LeadFormData(BaseModel):
    name: Optional[str] = None
    email: Optional[str] = None
    phone: Optional[str] = None
    campaign_id: Optional[str] = None
    landing_id: Optional[str] = "default"
    utm_campaign: Optional[str] = None
    utm_source: Optional[str] = None
    utm_medium: Optional[str] = None
    custom_fields: Dict[str, Any] = Field(default_factory=dict)

class CreateLeadRequest(BaseModel):
    form_data: LeadFormData
    landing_id: Optional[str] = "default"
    campaign_id: Optional[str] = None

class WebhookData(BaseModel):
    event_type: str
    lead_id: str
    payment_data: Optional[Dict[str, Any]] = None
    signature: Optional[str] = None

class PaymentData(BaseModel):
    amount: float
    currency: str = "RUB"
    payment_id: Optional[str] = None
    payment_date: Optional[datetime] = None
    description: Optional[str] = None

# ==================== LIFECYCLE ====================
@asynccontextmanager
async def lifespan(app: FastAPI):
    """Управление жизненным циклом приложения"""
    # Инициализация базы данных при старте
    await init_db()
    logger.info("Database initialized")
    
    # Запуск фоновых задач при старте
    asyncio.create_task(background_task_processor())
    
    yield
    
    # Очистка при остановке
    logger.info("Application shutting down")

app = FastAPI(lifespan=lifespan)

# ==================== СЕРВИСЫ ====================
class EventStore:
    """Сервис для работы с событиями (Event Sourcing)"""
    
    @staticmethod
    async def save_event(
        session: AsyncSession,
        aggregate_id: str,
        event_type: EventType,
        payload: Dict[str, Any],
        metadata: Optional[Dict[str, Any]] = None
    ) -> Event:
        """Сохранение события в базу данных"""
        event = Event(
            event_id=str(uuid.uuid4()),
            aggregate_id=aggregate_id,
            event_type=event_type,
            payload=payload,
            metadata=metadata or {}
        )
        
        session.add(event)
        await session.commit()
        await session.refresh(event)
        return event
    
    @staticmethod
    async def get_events_by_aggregate(
        session: AsyncSession,
        aggregate_id: str
    ) -> List[Event]:
        """Получение всех событий для агрегата"""
        query = select(Event).where(
            Event.aggregate_id == aggregate_id
        ).order_by(Event.created_at)
        
        result = await session.execute(query)
        return result.scalars().all()
    
    @staticmethod
    async def get_all_events(
        session: AsyncSession,
        limit: int = 100,
        offset: int = 0
    ) -> List[Event]:
        """Получение всех событий с пагинацией"""
        query = select(Event).order_by(
            desc(Event.created_at)
        ).limit(limit).offset(offset)
        
        result = await session.execute(query)
        return result.scalars().all()
    
    @staticmethod
    async def get_event_count(session: AsyncSession) -> int:
        """Получение общего количества событий"""
        query = select(func.count(Event.id))
        result = await session.execute(query)
        return result.scalar_one()

class TaskQueue:
    """Сервис для работы с фоновыми задачами"""
    
    @staticmethod
    async def create_task(
        session: AsyncSession,
        task_type: TaskType,
        payload: Dict[str, Any],
        delay_seconds: int = 0
    ) -> Task:
        """Создание новой фоновой задачи"""
        task = Task(
            task_id=str(uuid.uuid4()),
            task_type=task_type,
            payload=payload,
            scheduled_at=datetime.utcnow() + timedelta(seconds=delay_seconds)
        )
        
        session.add(task)
        await session.commit()
        await session.refresh(task)
        return task
    
    @staticmethod
    async def get_pending_tasks(
        session: AsyncSession,
        limit: int = 10
    ) -> List[Task]:
        """Получение задач, готовых к выполнению"""
        query = select(Task).where(
            Task.status == TaskStatus.PENDING,
            Task.scheduled_at <= datetime.utcnow()
        ).order_by(
            Task.scheduled_at
        ).limit(limit)
        
        result = await session.execute(query)
        return result.scalars().all()
    
    @staticmethod
    async def update_task_status(
        session: AsyncSession,
        task_id: str,
        status: TaskStatus,
        error_message: Optional[str] = None,
        result: Optional[Dict[str, Any]] = None
    ) -> None:
        """Обновление статуса задачи"""
        task = await TaskQueue.get_task_by_id(session, task_id)
        if not task:
            raise ValueError(f"Task {task_id} not found")
        
        task.status = status
        task.attempts += 1
        
        if status == TaskStatus.PROCESSING:
            task.started_at = datetime.utcnow()
        elif status == TaskStatus.COMPLETED:
            task.completed_at = datetime.utcnow()
            task.result = result
        elif status == TaskStatus.FAILED:
            task.error_message = error_message
        
        session.add(task)
        await session.commit()
    
    @staticmethod
    async def get_task_by_id(
        session: AsyncSession,
        task_id: str
    ) -> Optional[Task]:
        """Поиск задачи по ID"""
        query = select(Task).where(Task.task_id == task_id)
        result = await session.execute(query)
        return result.scalar_one_or_none()

class LeadService:
    """Сервис для работы с лидами"""
    
    @staticmethod
    async def create_lead(
        session: AsyncSession,
        form_data: LeadFormData,
        landing_id: str = "default",
        campaign_id: Optional[str] = None
    ) -> Lead:
        """Создание нового лида (проекция)"""
        lead = Lead(
            lead_id=f"lead_{uuid.uuid4().hex[:8]}",
            campaign_id=campaign_id or form_data.campaign_id,
            landing_id=landing_id,
            form_data=form_data.dict(),
            status="new"
        )
        
        session.add(lead)
        await session.commit()
        await session.refresh(lead)
        return lead
    
    @staticmethod
    async def update_lead_status(
        session: AsyncSession,
        lead_id: str,
        status: str,
        crm_id: Optional[str] = None,
        payment_data: Optional[Dict[str, Any]] = None
    ) -> Optional[Lead]:
        """Обновление статуса лида"""
        query = select(Lead).where(Lead.lead_id == lead_id)
        result = await session.execute(query)
        lead = result.scalar_one_or_none()
        
        if not lead:
            return None
        
        lead.status = status
        lead.updated_at = datetime.utcnow()
        
        if crm_id:
            lead.crm_id = crm_id
        
        if payment_data and status == "paid":
            lead.payment_amount = payment_data.get("amount")
            lead.payment_date = payment_data.get("payment_date") or datetime.utcnow()
        
        session.add(lead)
        await session.commit()
        await session.refresh(lead)
        return lead
    
    @staticmethod
    async def get_lead(
        session: AsyncSession,
        lead_id: str
    ) -> Optional[Lead]:
        """Получение лида по ID"""
        query = select(Lead).where(Lead.lead_id == lead_id)
        result = await session.execute(query)
        return result.scalar_one_or_none()

class CampaignService:
    """Сервис для работы со статистикой кампаний"""
    
    @staticmethod
    async def increment_lead_count(
        session: AsyncSession,
        campaign_id: str
    ) -> CampaignStat:
        """Увеличение счетчика лидов для кампании"""
        query = select(CampaignStat).where(
            CampaignStat.campaign_id == campaign_id
        )
        result = await session.execute(query)
        stat = result.scalar_one_or_none()
        
        if not stat:
            stat = CampaignStat(
                campaign_id=campaign_id,
                total_leads=1,
                last_lead_at=datetime.utcnow()
            )
        else:
            stat.total_leads += 1
            stat.last_lead_at = datetime.utcnow()
            stat.updated_at = datetime.utcnow()
        
        session.add(stat)
        await session.commit()
        await session.refresh(stat)
        return stat
    
    @staticmethod
    async def increment_payment_count(
        session: AsyncSession,
        campaign_id: str,
        amount: float
    ) -> CampaignStat:
        """Увеличение счетчика платежей для кампании"""
        query = select(CampaignStat).where(
            CampaignStat.campaign_id == campaign_id
        )
        result = await session.execute(query)
        stat = result.scalar_one_or_none()
        
        if not stat:
            stat = CampaignStat(
                campaign_id=campaign_id,
                total_payments=1,
                total_revenue=amount,
                last_payment_at=datetime.utcnow()
            )
        else:
            stat.total_payments += 1
            stat.total_revenue += amount
            stat.last_payment_at = datetime.utcnow()
            stat.updated_at = datetime.utcnow()
        
        session.add(stat)
        await session.commit()
        await session.refresh(stat)
        return stat
    
    @staticmethod
    async def get_campaign_stats(
        session: AsyncSession,
        campaign_id: Optional[str] = None
    ) -> List[CampaignStat]:
        """Получение статистики по кампаниям"""
        query = select(CampaignStat)
        
        if campaign_id:
            query = query.where(CampaignStat.campaign_id == campaign_id)
        
        query = query.order_by(desc(CampaignStat.total_leads))
        
        result = await session.execute(query)
        return result.scalars().all()

# ==================== ИНТЕГРАЦИИ ====================
class HTTPClient:
    @staticmethod
    async def post(url: str, data: Dict, headers: Optional[Dict] = None) -> Dict:
        """Асинхронный HTTP клиент"""
        try:
            async with aiohttp.ClientSession() as session:
                async with session.post(
                    url,
                    json=data,
                    headers=headers or {"Content-Type": "application/json"},
                    timeout=aiohttp.ClientTimeout(total=30)
                ) as response:
                    response_data = {
                        "status": response.status,
                        "headers": dict(response.headers),
                        "body": await response.json() 
                        if response.content_type == "application/json" 
                        else await response.text()
                    }
                    
                    # Логирование для отладки
                    logger.debug(f"HTTP Response: {response.status} {url}")
                    return response_data
                    
        except aiohttp.ClientError as e:
            logger.error(f"HTTP Client Error: {e}")
            return {"status": 500, "body": str(e)}
        except Exception as e:
            logger.error(f"Unexpected HTTP Error: {e}")
            return {"status": 500, "body": str(e)}

class YandexDirectIntegration:
    def __init__(self, config: Dict):
        self.config = config
    
    async def send_conversion(
        self, 
        campaign_id: str, 
        lead_id: str, 
        conversion_type: str, 
        value: Optional[float] = None
    ) -> Dict:
        """Отправка конверсии в Яндекс.Директ"""
        campaign_config = next(
            (c for c in self.config.get("campaigns", []) 
             if c.get("campaign_id") == campaign_id),
            None
        )
        
        if not campaign_config:
            logger.error(f"No Yandex.Direct config for campaign: {campaign_id}")
            return {"status": 404, "body": "Campaign not found"}
        
        data = {
            "method": "AddOfflineConversions",
            "param": {
                "Conversions": [{
                    "CampaignID": campaign_id,
                    "Yclid": lead_id,
                    "ConversionType": conversion_type,
                    "Value": value,
                    "DateTime": datetime.now().isoformat()
                }]
            },
            "token": campaign_config.get("oauth_token", "")
        }
        
        return await HTTPClient.post(
            "https://api.direct.yandex.ru/live/v4/json/", 
            data
        )

class CrmIntegration:
    def __init__(self, config: Dict):
        self.config = config
    
    async def send_lead(self, lead_data: Dict) -> Dict:
        """Отправка лида в CRM"""
        crm_type = self.config.get("crm_type", "generic")
        
        if crm_type == "amocrm":
            return await self._send_to_amocrm(lead_data)
        elif crm_type == "bitrix24":
            return await self._send_to_bitrix24(lead_data)
        else:
            return await self._send_to_generic_crm(lead_data)
    
    async def _send_to_amocrm(self, lead_data: Dict) -> Dict:
        url = f"{self.config.get('api_endpoint')}/api/v4/leads"
        headers = {
            "Authorization": f"Bearer {self.config.get('access_token', '')}"
        }
        
        data = {
            "name": lead_data.get("name", "Новый лид"),
            "price": lead_data.get("price", 0),
            "custom_fields_values": self._map_fields(lead_data)
        }
        
        return await HTTPClient.post(url, data, headers)
    
    async def _send_to_bitrix24(self, lead_data: Dict) -> Dict:
        url = f"{self.config.get('api_endpoint')}/crm.lead.add.json"
        
        data = {
            "fields": {
                "TITLE": lead_data.get("name", "Новый лид"),
                "NAME": lead_data.get("first_name", ""),
                "LAST_NAME": lead_data.get("last_name", ""),
                "OPPORTUNITY": lead_data.get("price", 0),
                "SOURCE_ID": "WEB"
            }
        }
        
        return await HTTPClient.post(url, data)
    
    async def _send_to_generic_crm(self, lead_data: Dict) -> Dict:
        url = self.config.get("api_endpoint", "")
        mapping = self.config.get("field_mapping", {})
        
        mapped_data = {}
        for source, target in mapping.items():
            if source in lead_data:
                mapped_data[target] = lead_data[source]
        
        if self.config.get("api_key"):
            mapped_data["api_key"] = self.config["api_key"]
        
        return await HTTPClient.post(url, mapped_data)
    
    def _map_fields(self, lead_data: Dict) -> List[Dict]:
        """Маппинг полей для AmoCRM"""
        mapping = self.config.get("field_mapping", {})
        mapped = []
        
        for source, target in mapping.items():
            if source in lead_data:
                mapped.append({
                    "field_id": target,
                    "values": [{"value": lead_data[source]}]
                })
        
        return mapped

# ==================== ФОНОВЫЕ ЗАДАЧИ ====================
async def process_crm_task(
    session: AsyncSession,
    lead_id: str,
    crm_config: Dict
):
    """Обработка задачи отправки в CRM"""
    logger.info(f"Processing CRM task for lead: {lead_id}")
    
    try:
        # Получаем лид
        lead = await LeadService.get_lead(session, lead_id)
        if not lead:
            raise ValueError(f"Lead not found: {lead_id}")
        
        # Отправляем в CRM
        crm_integration = CrmIntegration(crm_config)
        result = await crm_integration.send_lead(lead.form_data)
        
        if 200 <= result.get("status", 0) < 300:
            # Обновляем статус лида
            await LeadService.update_lead_status(
                session, lead_id, "sent_to_crm", 
                crm_id=crm_config.get("crm_id")
            )
            
            # Сохраняем событие
            await EventStore.save_event(
                session,
                aggregate_id=lead_id,
                event_type=EventType.LEAD_SENT_TO_CRM,
                payload={
                    "crm_response": result,
                    "crm_config": crm_config
                }
            )
            
            logger.info(f"CRM task completed for lead: {lead_id}")
            return {"success": True, "result": result}
        else:
            raise ValueError(f"CRM request failed: {result}")
    
    except Exception as e:
        logger.error(f"CRM task failed for lead {lead_id}: {e}")
        raise

async def process_yandex_direct_task(
    session: AsyncSession,
    lead_id: str,
    payment_amount: float = 0
):
    """Обработка задачи отправки в Яндекс.Директ"""
    logger.info(f"Processing Yandex.Direct task for lead: {lead_id}")
    
    try:
        # Получаем лид для получения campaign_id
        lead = await LeadService.get_lead(session, lead_id)
        if not lead or not lead.campaign_id:
            raise ValueError(f"Lead or campaign_id not found: {lead_id}")
        
        # Загружаем конфигурацию
        try:
            with open(INTEGRATIONS_CONFIG, 'r') as f:
                configs = json.load(f)
        except FileNotFoundError:
            configs = {}
        
        yandex_config = configs.get("yandex_direct", {})
        
        if yandex_config:
            integration = YandexDirectIntegration(yandex_config)
            result = await integration.send_conversion(
                lead.campaign_id, lead_id, "PAYMENT", payment_amount
            )
            
            if 200 <= result.get("status", 0) < 300:
                # Сохраняем событие
                await EventStore.save_event(
                    session,
                    aggregate_id=lead_id,
                    event_type=EventType.YANDEX_DIRECT_CONVERSION,
                    payload={
                        "campaign_id": lead.campaign_id,
                        "amount": payment_amount,
                        "response": result
                    }
                )
                
                logger.info(f"Yandex.Direct conversion sent for campaign: {lead.campaign_id}")
            else:
                logger.error(f"Yandex.Direct conversion failed: {result}")
        
        return {"success": True, "campaign_id": lead.campaign_id}
    
    except Exception as e:
        logger.error(f"Yandex.Direct task failed: {e}")
        raise

async def background_task_processor():
    """Фоновый процессор задач (заменяет BackgroundTasks)"""
    logger.info("Background task processor started")
    
    while True:
        try:
            async with get_session() as session:
                # Получаем задачи для выполнения
                tasks = await TaskQueue.get_pending_tasks(session, limit=5)
                
                for task in tasks:
                    try:
                        # Обновляем статус на "в процессе"
                        await TaskQueue.update_task_status(
                            session, task.task_id, TaskStatus.PROCESSING
                        )
                        
                        # Выполняем задачу в зависимости от типа
                        result = None
                        
                        if task.task_type == TaskType.SEND_TO_CRM:
                            result = await process_crm_task(
                                session,
                                task.payload.get("lead_id"),
                                task.payload.get("crm_config", {})
                            )
                        
                        elif task.task_type == TaskType.SEND_TO_YANDEX_DIRECT:
                            result = await process_yandex_direct_task(
                                session,
                                task.payload.get("lead_id"),
                                task.payload.get("payment_amount", 0)
                            )
                        
                        # Обновляем статус на "завершено"
                        await TaskQueue.update_task_status(
                            session, task.task_id, 
                            TaskStatus.COMPLETED,
                            result=result
                        )
                        
                    except Exception as e:
                        logger.error(f"Task {task.task_id} failed: {e}")
                        
                        # Если превышено количество попыток - помечаем как неудачную
                        if task.attempts >= task.max_attempts - 1:
                            await TaskQueue.update_task_status(
                                session, task.task_id,
                                TaskStatus.FAILED,
                                error_message=str(e)
                            )
                        else:
                            # Иначе возвращаем в очередь с задержкой
                            await TaskQueue.update_task_status(
                                session, task.task_id,
                                TaskStatus.PENDING
                            )
                
                # Если задач нет - ждем
                if not tasks:
                    await asyncio.sleep(5)
                else:
                    await asyncio.sleep(0.1)  # Короткая пауза между задачами
                    
        except Exception as e:
            logger.error(f"Error in task processor: {e}")
            await asyncio.sleep(5)  # Пауза при ошибке

# ==================== DEPENDENCIES ====================
async def load_integrations_config() -> Dict:
    """Загрузка конфигурации интеграций"""
    try:
        with open(INTEGRATIONS_CONFIG, 'r') as f:
            return json.load(f)
    except FileNotFoundError:
        return {}

async def get_crm_config(
    landing_id: str = "default", 
    campaign_id: Optional[str] = None
) -> Dict:
    """Получение конфигурации CRM для лендинга/кампании"""
    configs = await load_integrations_config()
    
    # Поиск по landing_id
    for mapping in configs.get("landing_crm_mappings", []):
        if mapping.get("landing_id") == landing_id:
            crm_id = mapping.get("crm_id")
            for crm_config in configs.get("crm_configs", []):
                if crm_config.get("crm_id") == crm_id:
                    return crm_config
    
    # Поиск по campaign_id
    if campaign_id:
        for mapping in configs.get("campaign_crm_mappings", []):
            if mapping.get("campaign_id") == campaign_id:
                crm_id = mapping.get("crm_id")
                for crm_config in configs.get("crm_configs", []):
                    if crm_config.get("crm_id") == crm_id:
                        return crm_config
    
    # Конфигурация по умолчанию
    return configs.get("default_crm", {"crm_type": "generic", "api_endpoint": ""})

# ==================== ЭНДПОИНТЫ ====================
@app.get("/", response_class=HTMLResponse)
async def root():
    """Главная страница"""
    return """
    <!DOCTYPE html>
    <html>
    <head>
        <title>Aggregator API with SQLite</title>
        <meta charset="UTF-8">
    </head>
    <body>
        <h1>Aggregator API with SQLite</h1>
        <p>Available endpoints:</p>
        <ul>
            <li>POST /api/v1/leads - Create lead</li>
            <li>GET /api/v1/leads/{lead_id} - Get lead</li>
            <li>GET /api/v1/leads - List leads</li>
            <li>POST /api/v1/webhooks/crm/{crm_id} - CRM webhook</li>
            <li>GET /admin - Admin dashboard</li>
            <li>GET /stats - Statistics</li>
            <li>GET /docs - API Documentation (Swagger)</li>
        </ul>
    </body>
    </html>
    """

@app.post("/api/v1/leads")
async def create_lead(
    request: CreateLeadRequest,
    background_tasks: BackgroundTasks,
    session: AsyncSession = Depends(get_session)
):
    """Создание нового лида"""
    try:
        # Используем campaign_id из запроса или из form_data
        campaign_id = (
            request.campaign_id or 
            request.form_data.campaign_id or 
            request.form_data.utm_campaign
        )
        
        # Создаем лид в базе (проекция)
        lead = await LeadService.create_lead(
            session,
            request.form_data,
            request.landing_id,
            campaign_id
        )
        
        # Обновляем статистику кампании
        if campaign_id:
            await CampaignService.increment_lead_count(session, campaign_id)
        
        # Сохраняем событие создания лида
        await EventStore.save_event(
            session,
            aggregate_id=lead.lead_id,
            event_type=EventType.LEAD_CREATED,
            payload={
                "form_data": request.form_data.dict(),
                "campaign_id": campaign_id,
                "landing_id": request.landing_id
            }
        )
        
        # Получаем конфигурацию CRM
        crm_config = await get_crm_config(request.landing_id, campaign_id)
        
        if crm_config.get("api_endpoint"):
            # Создаем фоновую задачу для отправки в CRM
            task = await TaskQueue.create_task(
                session,
                TaskType.SEND_TO_CRM,
                {
                    "lead_id": lead.lead_id,
                    "crm_config": crm_config
                }
            )
            
            return {
                "lead_id": lead.lead_id,
                "campaign_id": campaign_id,
                "status": "created",
                "message": "Lead created successfully",
                "task_id": task.task_id,
                "queued_for_crm": True
            }
        
        return {
            "lead_id": lead.lead_id,
            "campaign_id": campaign_id,
            "status": "created",
            "message": "Lead created (no CRM integration configured)",
            "queued_for_crm": False
        }
    
    except Exception as e:
        logger.error(f"Error creating lead: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/v1/leads/{lead_id}")
async def get_lead(
    lead_id: str,
    session: AsyncSession = Depends(get_session)
):
    """Получение информации о лиде"""
    try:
        lead = await LeadService.get_lead(session, lead_id)
        if not lead:
            raise HTTPException(status_code=404, detail="Lead not found")
        
        # Получаем все события для этого лида
        events = await EventStore.get_events_by_aggregate(session, lead_id)
        
        return {
            "lead": lead.dict(),
            "events": [event.dict() for event in events],
            "event_count": len(events)
        }
    
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error getting lead: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/v1/leads")
async def list_leads(
    campaign_id: Optional[str] = None,
    status: Optional[str] = None,
    limit: int = 100,
    offset: int = 0,
    session: AsyncSession = Depends(get_session)
):
    """Список лидов с фильтрацией"""
    try:
        query = select(Lead)
        
        if campaign_id:
            query = query.where(Lead.campaign_id == campaign_id)
        
        if status:
            query = query.where(Lead.status == status)
        
        query = query.order_by(desc(Lead.created_at)).limit(limit).offset(offset)
        
        result = await session.execute(query)
        leads = result.scalars().all()
        
        # Общее количество для пагинации
        count_query = select(func.count(Lead.id))
        if campaign_id:
            count_query = count_query.where(Lead.campaign_id == campaign_id)
        if status:
            count_query = count_query.where(Lead.status == status)
        
        total_result = await session.execute(count_query)
        total = total_result.scalar_one()
        
        return {
            "leads": [lead.dict() for lead in leads],
            "total": total,
            "limit": limit,
            "offset": offset
        }
    
    except Exception as e:
        logger.error(f"Error listing leads: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/api/v1/webhooks/crm/{crm_id}")
async def crm_webhook(
    crm_id: str,
    webhook_data: WebhookData,
    session: AsyncSession = Depends(get_session)
):
    """Обработка вебхуков от CRM"""
    try:
        # Загружаем конфигурацию
        configs = await load_integrations_config()
        crm_config = None
        
        for config in configs.get("crm_configs", []):
            if config.get("crm_id") == crm_id:
                crm_config = config
                break
        
        if not crm_config:
            raise HTTPException(status_code=404, detail="CRM configuration not found")
        
        # Проверка подписи (упрощенная)
        if webhook_data.signature and crm_config.get("webhook_secret"):
            # Здесь должна быть реальная проверка HMAC
            pass
        
        # Обработка события платежа
        if webhook_data.event_type == "payment_received":
            # Обновляем статус лида
            lead = await LeadService.update_lead_status(
                session,
                webhook_data.lead_id,
                "paid",
                payment_data=webhook_data.payment_data
            )
            
            if not lead:
                raise HTTPException(status_code=404, detail="Lead not found")
            
            # Обновляем статистику кампании
            if lead.campaign_id and webhook_data.payment_data:
                amount = webhook_data.payment_data.get("amount", 0)
                await CampaignService.increment_payment_count(
                    session, lead.campaign_id, amount
                )
            
            # Сохраняем событие о платеже
            await EventStore.save_event(
                session,
                aggregate_id=webhook_data.lead_id,
                event_type=EventType.PAYMENT_REGISTERED,
                payload={
                    "payment_data": webhook_data.payment_data or {},
                    "webhook_source": crm_id
                }
            )
            
            # Создаем задачу для отправки в Яндекс.Директ
            if lead.campaign_id:
                payment_amount = webhook_data.payment_data.get("amount", 0) if webhook_data.payment_data else 0
                
                task = await TaskQueue.create_task(
                    session,
                    TaskType.SEND_TO_YANDEX_DIRECT,
                    {
                        "lead_id": webhook_data.lead_id,
                        "payment_amount": payment_amount
                    }
                )
                
                return {
                    "status": "success",
                    "message": "Payment processed",
                    "yandex_direct_task_id": task.task_id
                }
            
            return {"status": "success", "message": "Payment processed"}
        
        return {"status": "processed", "message": "Webhook received"}
    
    except Exception as e:
        logger.error(f"Error processing webhook: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/admin", response_class=HTMLResponse)
async def admin_dashboard(session: AsyncSession = Depends(get_session)):
    """Админ-панель"""
    try:
        # Получаем статистику
        total_leads = await session.execute(select(func.count(Lead.id)))
        total_leads_count = total_leads.scalar_one()
        
        total_events = await EventStore.get_event_count(session)
        
        # Получаем статистику по кампаниям
        campaign_stats = await CampaignService.get_campaign_stats(session)
        
        # Последние лиды
        recent_leads_query = select(Lead).order_by(
            desc(Lead.created_at)
        ).limit(10)
        recent_leads_result = await session.execute(recent_leads_query)
        recent_leads = recent_leads_result.scalars().all()
        
        # Последние события
        recent_events = await EventStore.get_all_events(session, limit=10)
        
        # Формируем HTML
        html = f"""
        <!DOCTYPE html>
        <html>
        <head>
            <title>Admin Dashboard</title>
            <meta charset="UTF-8">
            <style>
                body {{ font-family: Arial, sans-serif; margin: 20px; }}
                table {{ border-collapse: collapse; margin: 20px 0; width: 100%; }}
                th, td {{ border: 1px solid #ddd; padding: 10px; text-align: left; }}
                th {{ background-color: #f4f4f4; }}
                .stat {{ margin: 15px 0; font-size: 18px; }}
                .card {{ 
                    background: #f8f9fa; 
                    border: 1px solid #dee2e6; 
                    border-radius: 5px; 
                    padding: 15px; 
                    margin: 15px 0; 
                }}
            </style>
        </head>
        <body>
            <h1>Aggregator Admin Dashboard</h1>
            
            <div class="card">
                <div class="stat">
                    <strong>Total Leads:</strong> {total_leads_count}
                </div>
                <div class="stat">
                    <strong>Total Events:</strong> {total_events}
                </div>
            </div>
            
            <h2>Campaign Statistics</h2>
            <table>
                <tr>
                    <th>Campaign ID</th>
                    <th>Leads</th>
                    <th>Payments</th>
                    <th>Revenue</th>
                    <th>Conversion Rate</th>
                    <th>Last Lead</th>
                </tr>
        """
        
        for stat in campaign_stats:
            conversion = "0%"
            if stat.total_leads > 0:
                conversion_rate = (stat.total_payments / stat.total_leads) * 100
                conversion = f"{conversion_rate:.2f}%"
            
            last_lead = stat.last_lead_at.strftime("%Y-%m-%d %H:%M") if stat.last_lead_at else "N/A"
            
            html += f"""
                <tr>
                    <td>{stat.campaign_id}</td>
                    <td>{stat.total_leads}</td>
                    <td>{stat.total_payments}</td>
                    <td>{stat.total_revenue:.2f} RUB</td>
                    <td>{conversion}</td>
                    <td>{last_lead}</td>
                </tr>
            """
        
        html += """
            </table>
            
            <h2>Recent Leads</h2>
            <table>
                <tr>
                    <th>Lead ID</th>
                    <th>Campaign</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Email/Phone</th>
                </tr>
        """
        
        for lead in recent_leads:
            contact = lead.form_data.get('email') or lead.form_data.get('phone') or 'N/A'
            created = lead.created_at.strftime("%Y-%m-%d %H:%M")
            
            html += f"""
                <tr>
                    <td>{lead.lead_id}</td>
                    <td>{lead.campaign_id or 'N/A'}</td>
                    <td>{lead.status}</td>
                    <td>{created}</td>
                    <td>{contact[:30]}</td>
                </tr>
            """
        
        html += """
            </table>
            
            <h2>Recent Events</h2>
            <table>
                <tr>
                    <th>Time</th>
                    <th>Event Type</th>
                    <th>Lead ID</th>
                    <th>Details</th>
                </tr>
        """
        
        for event in recent_events:
            details = json.dumps(event.payload)[:100] + "..." if len(json.dumps(event.payload)) > 100 else json.dumps(event.payload)
            created = event.created_at.strftime("%Y-%m-%d %H:%M:%S")
            
            html += f"""
                <tr>
                    <td>{created}</td>
                    <td>{event.event_type}</td>
                    <td>{event.aggregate_id}</td>
                    <td>{details}</td>
                </tr>
            """
        
        html += """
            </table>
        </body>
        </html>
        """
        
        return HTMLResponse(content=html)
    
    except Exception as e:
        logger.error(f"Error generating admin dashboard: {e}")
        return HTMLResponse(content=f"<h1>Error: {str(e)}</h1>", status_code=500)

@app.get("/stats")
async def get_stats(session: AsyncSession = Depends(get_session)):
    """Подробная статистика сервиса"""
    try:
        # Общая статистика
        total_leads = await session.execute(select(func.count(Lead.id)))
        total_leads_count = total_leads.scalar_one()
        
        total_events = await EventStore.get_event_count(session)
        
        # Статистика по статусам лидов
        status_query = select(
            Lead.status, 
            func.count(Lead.id).label('count')
        ).group_by(Lead.status)
        
        status_result = await session.execute(status_query)
        status_stats = {row.status: row.count for row in status_result}
        
        # Статистика по типам событий
        event_type_query = select(
            Event.event_type, 
            func.count(Event.id).label('count')
        ).group_by(Event.event_type)
        
        event_type_result = await session.execute(event_type_query)
        event_type_stats = {row.event_type.value: row.count for row in event_type_result}
        
        return {
            "total_leads": total_leads_count,
            "total_events": total_events,
            "lead_statuses": status_stats,
            "event_types": event_type_stats,
            "timestamp": datetime.utcnow().isoformat()
        }
    
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/health")
async def health_check(session: AsyncSession = Depends(get_session)):
    """Health check с проверкой базы данных"""
    try:
        # Проверяем подключение к базе данных
        await session.execute(select(1))
        
        return {
            "status": "healthy",
            "database": "connected",
            "timestamp": datetime.utcnow().isoformat()
        }
    except Exception as e:
        raise HTTPException(
            status_code=503, 
            detail=f"Service unhealthy: {str(e)}"
        )

# ==================== ЗАПУСК ====================
if __name__ == "__main__":
    import uvicorn
    
    uvicorn.run(
        "main:app",
        host="0.0.0.0",
        port=8000,
        reload=True,
        log_level="info"
    )