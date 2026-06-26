from datetime import datetime, timedelta
from typing import Optional
from jose import JWTError, jwt
import bcrypt
import models

SECRET_KEY = "hcp-monitor-2024-secret"
ALGORITHM  = "HS256"
TOKEN_TTL  = 8  # hours


def verify_password(plain: str, hashed: str) -> bool:
    return bcrypt.checkpw(plain.encode(), hashed.encode())


def hash_password(password: str) -> str:
    return bcrypt.hashpw(password.encode(), bcrypt.gensalt()).decode()


def create_token(data: dict) -> str:
    payload = data.copy()
    payload["exp"] = datetime.utcnow() + timedelta(hours=TOKEN_TTL)
    return jwt.encode(payload, SECRET_KEY, algorithm=ALGORITHM)


def get_current_user(db, token: Optional[str]) -> Optional[models.User]:
    if not token:
        return None
    try:
        payload = jwt.decode(token, SECRET_KEY, algorithms=[ALGORITHM])
        username = payload.get("sub")
        if not username:
            return None
        return (db.query(models.User)
                  .filter(models.User.username == username,
                          models.User.is_active == True)
                  .first())
    except JWTError:
        return None
