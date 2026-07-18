"""
Session-wide setup: clear brute-force login lockouts from previous runs.
The backend sees all traffic as coming from the reverse-proxy IP, so any
test-induced lockout affects legitimate credential submissions.
"""
import os
import pytest
from pathlib import Path
from pymongo import MongoClient


def _load_frontend_env():
    """Load REACT_APP_BACKEND_URL from /app/frontend/.env if not already set."""
    if os.environ.get("REACT_APP_BACKEND_URL"):
        return
    env_file = Path("/app/frontend/.env")
    if env_file.exists():
        for line in env_file.read_text().splitlines():
            line = line.strip()
            if line.startswith("REACT_APP_BACKEND_URL"):
                _, _, val = line.partition("=")
                os.environ["REACT_APP_BACKEND_URL"] = val.strip().strip('"').strip("'")
                break


_load_frontend_env()


def _clear_lockouts():
    mongo_url = os.environ.get("MONGO_URL", "mongodb://localhost:27017")
    db_name = os.environ.get("DB_NAME", "test_database")
    try:
        cli = MongoClient(mongo_url, serverSelectionTimeoutMS=2000)
        cli[db_name].login_attempts.delete_many({})
        cli.close()
    except Exception as e:
        print(f"[conftest] could not clear login_attempts: {e}")


@pytest.fixture(scope="session", autouse=True)
def clear_lockouts_session():
    _clear_lockouts()
    yield
    _clear_lockouts()
