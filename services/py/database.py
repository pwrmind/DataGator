# database.py
from sqlmodel import SQLModel, Field, create_engine, Session, select
from typing import Optional, List, Dict, Any
from datetime import datetime
import enum
from contextlib import asynccontextmanager

# ==================== МОДЕЛИ БАЗЫ ДАННЫХ ====================
class EventType(str, enum.Enum):
    LEAD_CREATED = "lead_created"
    LEAD_SENT_TO_CRM = "lead_sent_to_crm"
    PAYMENT_REGISTERED = "payment_registered"
    YANDEX_DIRECT_CONVERSION = "yandex_direct_conversion"

class TaskStatus(str, enum.Enum):
    PENDING = "pending"
    PROCESSING = "processing"
    COMPLETED = "completed"
    FAILED = "failed"

class TaskType(str, enum.Enum):
    SEND_TO_CRM = "send_to_crm"
    SEND_TO_YANDEX_DIRECT = "send_to_yandex_direct"
    RETRY_FAILED = "retry_failed"

class Event(SQLModel, table=True):
    """Модель для хранения событий Event Sourcing"""
    id: Optional[int] = Field(default=None, primary_key=True)
    event_id: str = Field(index=True, unique=True)
    aggregate_id: str = Field(index=True)  # ID лида
    event_type: EventType
    payload: Dict[str, Any] = Field(default={}, sa_type=sqlmodel.JSON)  # type: ignore
    version: int = Field(default=1)
    created_at: datetime = Field(default_factory=datetime.utcnow)
    metadata: Dict[str, Any] = Field(default={}, sa_type=sqlmodel.JSON)  # type: ignore

class Task(SQLModel, table=True):
    """Модель для фоновых задач"""
    id: Optional[int] = Field(default=None, primary_key=True)
    task_id: str = Field(index=True, unique=True)
    task_type: TaskType
    payload: Dict[str, Any] = Field(default={}, sa_type=sqlmodel.JSON)  # type: ignore
    status: TaskStatus = Field(default=TaskStatus.PENDING)
    attempts: int = Field(default=0)
    max_attempts: int = Field(default=3)
    error_message: Optional[str] = None
    result: Optional[Dict[str, Any]] = Field(default=None, sa_type=sqlmodel.JSON)  # type: ignore
    scheduled_at: datetime = Field(default_factory=datetime.utcnow)
    started_at: Optional[datetime] = None
    completed_at: Optional[datetime] = None

class Lead(SQLModel, table=True):
    """Проекция (Snapshot) для быстрого доступа к данным лида"""
    id: Optional[int] = Field(default=None, primary_key=True)
    lead_id: str = Field(index=True, unique=True)  # aggregate_id
    campaign_id: Optional[str] = Field(index=True)
    landing_id: str = Field(default="default", index=True)
    form_data: Dict[str, Any] = Field(default={}, sa_type=sqlmodel.JSON)  # type: ignore
    status: str = Field(default="new")  # new, sent_to_crm, paid, etc.
    crm_id: Optional[str] = None
    payment_amount: Optional[float] = None
    payment_date: Optional[datetime] = None
    created_at: datetime = Field(default_factory=datetime.utcnow)
    updated_at: datetime = Field(default_factory=datetime.utcnow)

class CampaignStat(SQLModel, table=True):
    """Статистика по кампаниям для быстрой отчетности"""
    id: Optional[int] = Field(default=None, primary_key=True)
    campaign_id: str = Field(index=True, unique=True)
    total_leads: int = Field(default=0)
    total_payments: int = Field(default=0)
    total_revenue: float = Field(default=0.0)
    last_lead_at: Optional[datetime] = None
    last_payment_at: Optional[datetime] = None
    created_at: datetime = Field(default_factory=datetime.utcnow)
    updated_at: datetime = Field(default_factory=datetime.utcnow)

# ==================== НАСТРОЙКА БАЗЫ ДАННЫХ ====================
from sqlalchemy.ext.asyncio import AsyncEngine, AsyncSession, create_async_engine
from sqlalchemy.orm import sessionmaker
import sqlmodel
import os

DATABASE_URL = os.getenv("DATABASE_URL", "sqlite+aiosqlite:///./aggregator.db")

# Асинхронный engine
engine: Optional[AsyncEngine] = None
AsyncSessionLocal = None

async def init_db():
    """Инициализация базы данных"""
    global engine, AsyncSessionLocal
    
    engine = create_async_engine(
        DATABASE_URL,
        echo=True,  # Логировать SQL запросы (отключить в production)
        future=True,
        connect_args={"check_same_thread": False} if "sqlite" in DATABASE_URL else {}
    )
    
    AsyncSessionLocal = sessionmaker(
        engine, class_=AsyncSession, expire_on_commit=False
    )
    
    # Создаем таблицы
    async with engine.begin() as conn:
        # В SQLModel 0.0.14 нужно использовать run_sync для синхронных операций
        await conn.run_sync(SQLModel.metadata.create_all)

async def get_session() -> AsyncSession:
    """Dependency для получения сессии базы данных"""
    async with AsyncSessionLocal() as session:
        yield session

@asynccontextmanager
async def get_db_session():
    """Контекстный менеджер для сессии базы данных"""
    async with AsyncSessionLocal() as session:
        try:
            yield session
            await session.commit()
        except Exception:
            await session.rollback()
            raise
        finally:
            await session.close()