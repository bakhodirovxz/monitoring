from datetime import datetime
from sqlalchemy import Column, Integer, String, DateTime, ForeignKey, Boolean, Float
from sqlalchemy.orm import relationship
from database import Base


class Branch(Base):
    __tablename__ = "branches"
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(200), unique=True, nullable=False)
    created_at = Column(DateTime, default=datetime.utcnow)

    nvrs = relationship("NVR", back_populates="branch")
    user_branches = relationship("UserBranch", back_populates="branch", cascade="all, delete-orphan")


class User(Base):
    __tablename__ = "users"
    id = Column(Integer, primary_key=True, index=True)
    username = Column(String(100), unique=True, nullable=False)
    password_hash = Column(String(200), nullable=False)
    full_name = Column(String(200), default="")
    role = Column(String(50), default="branch_admin")  # superadmin | branch_admin
    is_active = Column(Boolean, default=True)
    created_at = Column(DateTime, default=datetime.utcnow)

    user_branches = relationship("UserBranch", back_populates="user", cascade="all, delete-orphan")
    user_nvrs = relationship("UserNVR", back_populates="user", cascade="all, delete-orphan")


class UserBranch(Base):
    __tablename__ = "user_branches"
    user_id = Column(Integer, ForeignKey("users.id", ondelete="CASCADE"), primary_key=True)
    branch_id = Column(Integer, ForeignKey("branches.id", ondelete="CASCADE"), primary_key=True)

    user = relationship("User", back_populates="user_branches")
    branch = relationship("Branch", back_populates="user_branches")


class NVR(Base):
    __tablename__ = "nvrs"
    id = Column(Integer, primary_key=True, index=True)
    hik_code = Column(String(200), unique=True, nullable=False)
    name = Column(String(200), default="")
    ip = Column(String(50), default="")
    branch_id = Column(Integer, ForeignKey("branches.id", ondelete="SET NULL"), nullable=True)
    name_overridden = Column(Boolean, default=False)

    branch = relationship("Branch", back_populates="nvrs")
    cameras = relationship("Camera", back_populates="nvr")
    user_nvrs = relationship("UserNVR", back_populates="nvr", cascade="all, delete-orphan")


class Camera(Base):
    __tablename__ = "cameras"
    id = Column(Integer, primary_key=True, index=True)
    hik_code = Column(String(200), unique=True, nullable=False)
    name = Column(String(200), default="")
    channel_ip = Column(String(50), default="")
    nvr_id = Column(Integer, ForeignKey("nvrs.id", ondelete="SET NULL"), nullable=True)
    current_status = Column(Integer, default=0)  # 0=unknown 1=online 2=offline
    last_status_change = Column(DateTime, default=datetime.utcnow)
    name_overridden = Column(Boolean, default=False)

    nvr = relationship("NVR", back_populates="cameras")
    events = relationship("CameraEvent", back_populates="camera")


class CameraEvent(Base):
    __tablename__ = "camera_events"
    id = Column(Integer, primary_key=True, index=True)
    camera_id = Column(Integer, ForeignKey("cameras.id", ondelete="CASCADE"), nullable=False)
    event_type = Column(String(20), nullable=False)  # offline | online
    started_at = Column(DateTime, nullable=False)
    ended_at = Column(DateTime, nullable=True)
    duration_sec = Column(Float, nullable=True)

    camera = relationship("Camera", back_populates="events")


class UserNVR(Base):
    __tablename__ = "user_nvrs"
    user_id = Column(Integer, ForeignKey("users.id", ondelete="CASCADE"), primary_key=True)
    nvr_id = Column(Integer, ForeignKey("nvrs.id", ondelete="CASCADE"), primary_key=True)

    user = relationship("User", back_populates="user_nvrs")
    nvr = relationship("NVR", back_populates="user_nvrs")
