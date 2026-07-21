"""
Meine Weltreise – Streaming Backend
FastAPI + MongoDB + JWT-Cookies
"""
from dotenv import load_dotenv
from pathlib import Path
ROOT_DIR = Path(__file__).parent
load_dotenv(ROOT_DIR / ".env")

import os
import re
import uuid
import shutil
import logging
import bcrypt
import jwt as pyjwt
from datetime import datetime, timezone, timedelta
from typing import List, Optional, Dict, Any

from fastapi import FastAPI, APIRouter, HTTPException, Request, Response, Depends, UploadFile, File, Form
from fastapi.staticfiles import StaticFiles
from fastapi.responses import JSONResponse
from starlette.middleware.cors import CORSMiddleware
from motor.motor_asyncio import AsyncIOMotorClient
from pydantic import BaseModel, Field

# ------------- Setup -------------
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
logger = logging.getLogger("weltreise")

MONGO_URL = os.environ["MONGO_URL"]
DB_NAME = os.environ["DB_NAME"]
JWT_SECRET = os.environ["JWT_SECRET"]
INITIAL_ACCESS_CODE = os.environ.get("INITIAL_ACCESS_CODE", "pineapplethefruitdude")
INITIAL_ADMIN_PASSWORD = os.environ.get("INITIAL_ADMIN_PASSWORD", "changeme")
UPLOAD_DIR = Path(os.environ.get("UPLOAD_DIR", str(ROOT_DIR / "uploads")))
MAX_UPLOAD_MB = int(os.environ.get("MAX_UPLOAD_MB", "500"))
JWT_ALG = "HS256"
COOKIE_NAME = "access_token"
COOKIE_MAX_AGE = 30 * 24 * 3600  # 30 days

# Ensure upload dirs exist
(UPLOAD_DIR / "videos").mkdir(parents=True, exist_ok=True)
(UPLOAD_DIR / "images").mkdir(parents=True, exist_ok=True)

client = AsyncIOMotorClient(MONGO_URL)
db = client[DB_NAME]

app = FastAPI(title="Meine Weltreise API")
api = APIRouter(prefix="/api")

# ------------- Password / JWT helpers -------------
def hash_pw(pw: str) -> str:
    return bcrypt.hashpw(pw.encode("utf-8"), bcrypt.gensalt()).decode("utf-8")

def verify_pw(pw: str, h: str) -> bool:
    try:
        return bcrypt.checkpw(pw.encode("utf-8"), h.encode("utf-8"))
    except Exception:
        return False

def make_token(role: str) -> str:
    payload = {
        "role": role,  # "viewer" | "admin"
        "iat": datetime.now(timezone.utc),
        "exp": datetime.now(timezone.utc) + timedelta(seconds=COOKIE_MAX_AGE),
    }
    return pyjwt.encode(payload, JWT_SECRET, algorithm=JWT_ALG)

def decode_token(token: str) -> Optional[Dict[str, Any]]:
    try:
        return pyjwt.decode(token, JWT_SECRET, algorithms=[JWT_ALG])
    except Exception:
        return None

def get_token(request: Request) -> Optional[str]:
    tok = request.cookies.get(COOKIE_NAME)
    if tok: return tok
    auth = request.headers.get("Authorization", "")
    if auth.startswith("Bearer "): return auth[7:]
    return None

def require_role(min_role: str):
    """min_role: 'viewer' (any auth) or 'admin'"""
    async def dep(request: Request):
        tok = get_token(request)
        if not tok: raise HTTPException(401, "Not authenticated")
        payload = decode_token(tok)
        if not payload: raise HTTPException(401, "Invalid token")
        role = payload.get("role")
        if min_role == "admin" and role != "admin":
            raise HTTPException(403, "Admin required")
        if min_role == "viewer" and role not in ("viewer", "admin"):
            raise HTTPException(401, "Not authenticated")
        return {"role": role}
    return dep

def set_cookie(response: Response, token: str):
    response.set_cookie(
        key=COOKIE_NAME, value=token,
        httponly=True, secure=True, samesite="lax",
        max_age=COOKIE_MAX_AGE, path="/",
    )

# ------------- Brute-force helper -------------
async def check_bruteforce(ip: str, kind: str):
    key = f"{ip}:{kind}"
    doc = await db.login_attempts.find_one({"_id": key})
    if not doc: return
    if doc.get("locked_until") and datetime.now(timezone.utc) < datetime.fromisoformat(doc["locked_until"]):
        raise HTTPException(429, "Zu viele Versuche. Bitte warte 15 Minuten.")

async def record_fail(ip: str, kind: str):
    key = f"{ip}:{kind}"
    doc = await db.login_attempts.find_one({"_id": key}) or {"_id": key, "count": 0}
    doc["count"] = int(doc.get("count", 0)) + 1
    if doc["count"] >= 5:
        doc["locked_until"] = (datetime.now(timezone.utc) + timedelta(minutes=15)).isoformat()
        doc["count"] = 0
    await db.login_attempts.replace_one({"_id": key}, doc, upsert=True)

async def clear_fails(ip: str, kind: str):
    await db.login_attempts.delete_one({"_id": f"{ip}:{kind}"})

# ------------- Models -------------
class AccessCodeIn(BaseModel):
    code: str

class AdminPwIn(BaseModel):
    password: str

class ChangeCodeIn(BaseModel):
    new_code: str

class ChangePwIn(BaseModel):
    new_password: str

class SettingsIn(BaseModel):
    series_title: Optional[str] = None
    season_default_name: Optional[str] = None
    hero_subtitle: Optional[str] = None
    hero_description: Optional[str] = None
    hero_image_url: Optional[str] = None
    hero_bg_url: Optional[str] = None
    logo_text: Optional[str] = None
    logo_url: Optional[str] = None
    footer_text: Optional[str] = None
    color_bg: Optional[str] = None
    color_accent: Optional[str] = None
    color_text: Optional[str] = None
    year: Optional[str] = None
    font_heading: Optional[str] = None
    font_body: Optional[str] = None
    socials: Optional[Dict[str, str]] = None

class SeasonIn(BaseModel):
    name: str
    order: Optional[int] = 0
    hidden: Optional[bool] = False

class EpisodeIn(BaseModel):
    season_id: str
    number: Optional[int] = None
    title: str
    description: Optional[str] = ""
    long_description: Optional[str] = ""
    location: Optional[str] = ""
    country: Optional[str] = ""
    date: Optional[str] = ""
    duration: Optional[int] = 0            # in minutes (display)
    duration_seconds: Optional[int] = 0    # for progress
    thumbnail_url: Optional[str] = ""
    video_url: Optional[str] = ""
    highlights: Optional[List[str]] = []
    travel: Optional[Dict[str, str]] = {}
    hidden: Optional[bool] = False
    order: Optional[int] = 0

class CountryIn(BaseModel):
    name: str
    flag_emoji: Optional[str] = ""

class ReorderIn(BaseModel):
    ids: List[str]

# ------------- Helpers -------------
def new_id() -> str:
    return uuid.uuid4().hex[:16]

def clean_doc(doc: dict) -> dict:
    if not doc: return doc
    doc = dict(doc)
    doc.pop("_id", None)
    return doc

# ------------- Auth endpoints -------------
@api.get("/auth/me")
async def auth_me(request: Request):
    tok = get_token(request)
    if not tok: return {"authenticated": False, "role": None}
    payload = decode_token(tok)
    if not payload: return {"authenticated": False, "role": None}
    return {"authenticated": True, "role": payload.get("role")}

@api.post("/auth/access-code")
async def auth_access_code(body: AccessCodeIn, request: Request, response: Response):
    ip = request.client.host if request.client else "unknown"
    creds = await db.credentials.find_one({"_id": "singleton"})
    if not creds: raise HTTPException(500, "Zugangscode ist noch nicht gesetzt.")
    # Prüfe zuerst Credentials — nur bei Falscheingabe zählt Brute-Force
    if verify_pw(body.code, creds["access_code_hash"]):
        await clear_fails(ip, "access")
        token = make_token("viewer")
        set_cookie(response, token)
        return {"ok": True, "role": "viewer", "token": token}
    # Falsch: prüfe Lockout, sonst zähle hoch
    await check_bruteforce(ip, "access")
    await record_fail(ip, "access")
    raise HTTPException(401, "Falscher Zugangscode.")

@api.post("/auth/admin")
async def auth_admin(body: AdminPwIn, request: Request, response: Response):
    ip = request.client.host if request.client else "unknown"
    creds = await db.credentials.find_one({"_id": "singleton"})
    if not creds: raise HTTPException(500, "Admin-Passwort ist noch nicht gesetzt.")
    if verify_pw(body.password, creds["admin_password_hash"]):
        await clear_fails(ip, "admin")
        token = make_token("admin")
        set_cookie(response, token)
        return {"ok": True, "role": "admin", "token": token}
    await check_bruteforce(ip, "admin")
    await record_fail(ip, "admin")
    raise HTTPException(401, "Falsches Admin-Passwort.")

@api.post("/auth/logout")
async def auth_logout(response: Response):
    response.delete_cookie(COOKIE_NAME, path="/")
    return {"ok": True}

# ------------- Public content -------------
@api.get("/content")
async def get_content(_: dict = Depends(require_role("viewer"))):
    settings = clean_doc(await db.settings.find_one({"_id": "singleton"})) or {}
    seasons = [clean_doc(s) async for s in db.seasons.find({"hidden": {"$ne": True}}).sort("order", 1)]
    countries = [clean_doc(c) async for c in db.countries.find({}).sort("name", 1)]
    eps = [clean_doc(e) async for e in db.episodes.find({"hidden": {"$ne": True}}).sort([("season_id", 1), ("order", 1), ("number", 1)])]
    settings.pop("_id", None)
    return {"settings": settings, "seasons": seasons, "countries": countries, "episodes": eps}

# ------------- Admin: Settings -------------
@api.get("/admin/settings")
async def admin_get_settings(_: dict = Depends(require_role("admin"))):
    doc = clean_doc(await db.settings.find_one({"_id": "singleton"})) or {}
    return doc

@api.put("/admin/settings")
async def admin_update_settings(body: SettingsIn, _: dict = Depends(require_role("admin"))):
    updates = {k: v for k, v in body.model_dump(exclude_unset=True).items() if v is not None}
    if updates:
        await db.settings.update_one({"_id": "singleton"}, {"$set": updates}, upsert=True)
    return await admin_get_settings(_)

# ------------- Admin: Seasons -------------
@api.get("/admin/seasons")
async def admin_list_seasons(_: dict = Depends(require_role("admin"))):
    return [clean_doc(s) async for s in db.seasons.find({}).sort("order", 1)]

@api.post("/admin/seasons")
async def admin_create_season(body: SeasonIn, _: dict = Depends(require_role("admin"))):
    doc = body.model_dump()
    doc["id"] = new_id()
    await db.seasons.insert_one(doc)
    return clean_doc(doc)

@api.put("/admin/seasons/{sid}")
async def admin_update_season(sid: str, body: SeasonIn, _: dict = Depends(require_role("admin"))):
    updates = {k: v for k, v in body.model_dump(exclude_unset=True).items() if v is not None}
    r = await db.seasons.update_one({"id": sid}, {"$set": updates})
    if r.matched_count == 0: raise HTTPException(404, "Season not found")
    return clean_doc(await db.seasons.find_one({"id": sid}))

@api.delete("/admin/seasons/{sid}")
async def admin_delete_season(sid: str, _: dict = Depends(require_role("admin"))):
    # Kaskadierendes Löschen aller Episoden der Staffel
    await db.episodes.delete_many({"season_id": sid})
    await db.seasons.delete_one({"id": sid})
    return {"ok": True}

# ------------- Admin: Countries -------------
@api.get("/admin/countries")
async def admin_list_countries(_: dict = Depends(require_role("admin"))):
    return [clean_doc(c) async for c in db.countries.find({}).sort("name", 1)]

@api.post("/admin/countries")
async def admin_create_country(body: CountryIn, _: dict = Depends(require_role("admin"))):
    if await db.countries.find_one({"name": body.name}):
        raise HTTPException(400, "Land existiert bereits")
    doc = body.model_dump()
    doc["id"] = new_id()
    await db.countries.insert_one(doc)
    return clean_doc(doc)

@api.delete("/admin/countries/{cid}")
async def admin_delete_country(cid: str, _: dict = Depends(require_role("admin"))):
    await db.countries.delete_one({"id": cid})
    return {"ok": True}

# ------------- Admin: Episodes -------------
@api.get("/admin/episodes")
async def admin_list_episodes(_: dict = Depends(require_role("admin"))):
    return [clean_doc(e) async for e in db.episodes.find({}).sort([("season_id", 1), ("order", 1), ("number", 1)])]

@api.post("/admin/episodes")
async def admin_create_episode(body: EpisodeIn, _: dict = Depends(require_role("admin"))):
    doc = body.model_dump()
    doc["id"] = new_id()
    if not doc.get("number"):
        cnt = await db.episodes.count_documents({"season_id": doc["season_id"]})
        doc["number"] = cnt + 1
    if not doc.get("order"): doc["order"] = doc["number"] or 0
    await db.episodes.insert_one(doc)
    return clean_doc(doc)

@api.put("/admin/episodes/{eid}")
async def admin_update_episode(eid: str, body: EpisodeIn, _: dict = Depends(require_role("admin"))):
    updates = {k: v for k, v in body.model_dump(exclude_unset=True).items()}
    r = await db.episodes.update_one({"id": eid}, {"$set": updates})
    if r.matched_count == 0: raise HTTPException(404, "Episode not found")
    return clean_doc(await db.episodes.find_one({"id": eid}))

@api.delete("/admin/episodes/{eid}")
async def admin_delete_episode(eid: str, _: dict = Depends(require_role("admin"))):
    await db.episodes.delete_one({"id": eid})
    return {"ok": True}

@api.post("/admin/episodes/reorder")
async def admin_reorder(body: ReorderIn, _: dict = Depends(require_role("admin"))):
    for idx, eid in enumerate(body.ids):
        await db.episodes.update_one({"id": eid}, {"$set": {"order": idx}})
    return {"ok": True}

# ------------- Admin: Uploads -------------
ALLOWED_VIDEO = {"video/mp4", "video/quicktime", "video/webm", "video/x-matroska"}
ALLOWED_IMAGE = {"image/jpeg", "image/png", "image/webp", "image/gif", "image/svg+xml"}

def safe_filename(name: str) -> str:
    name = re.sub(r"[^a-zA-Z0-9._-]", "_", name)
    return name[:100] or f"file_{new_id()}"

@api.post("/admin/upload")
async def admin_upload(file: UploadFile = File(...), kind: str = Form("auto"), _: dict = Depends(require_role("admin"))):
    ctype = (file.content_type or "").lower()
    if kind == "auto":
        if ctype in ALLOWED_VIDEO: kind = "video"
        elif ctype in ALLOWED_IMAGE: kind = "image"
        else: raise HTTPException(415, f"Nicht unterstützter Dateityp: {ctype}")
    else:
        if kind == "video" and ctype not in ALLOWED_VIDEO:
            raise HTTPException(415, f"Kein Video: {ctype}")
        if kind == "image" and ctype not in ALLOWED_IMAGE:
            raise HTTPException(415, f"Kein Bild: {ctype}")
    subdir = "videos" if kind == "video" else "images"
    fname = f"{new_id()}_{safe_filename(file.filename or 'upload')}"
    dst = UPLOAD_DIR / subdir / fname
    # size check while streaming
    max_bytes = MAX_UPLOAD_MB * 1024 * 1024
    total = 0
    with dst.open("wb") as out:
        while True:
            chunk = await file.read(1024 * 1024)
            if not chunk: break
            total += len(chunk)
            if total > max_bytes:
                out.close()
                dst.unlink(missing_ok=True)
                raise HTTPException(413, f"Datei zu groß (max {MAX_UPLOAD_MB} MB)")
            out.write(chunk)
    url = f"/api/uploads/{subdir}/{fname}"
    media = {"id": new_id(), "kind": kind, "url": url, "name": file.filename, "size": total, "created_at": datetime.now(timezone.utc).isoformat()}
    await db.media.insert_one(media)
    return {"url": url, "kind": kind, "size": total, "id": media["id"]}

# ---- Chunked Upload (für große Dateien > Ingress-Body-Limit) ----
ALLOWED_VIDEO_EXT = {".mp4", ".mov", ".webm", ".mkv", ".m4v"}
ALLOWED_IMAGE_EXT = {".jpg", ".jpeg", ".png", ".webp", ".gif", ".svg"}
_TMP_UPLOADS = UPLOAD_DIR / "tmp"
_TMP_UPLOADS.mkdir(parents=True, exist_ok=True)

def _valid_upload_id(uid: str) -> bool:
    return bool(re.fullmatch(r"[a-zA-Z0-9_-]{6,64}", uid))

@api.post("/admin/upload-chunk")
async def admin_upload_chunk(
    file: UploadFile = File(...),
    upload_id: str = Form(...),
    chunk_index: int = Form(...),
    total_chunks: int = Form(...),
    filename: str = Form(...),
    kind: str = Form(...),
    _: dict = Depends(require_role("admin")),
):
    if not _valid_upload_id(upload_id):
        raise HTTPException(400, "Ungültige upload_id")
    if chunk_index < 0 or chunk_index >= total_chunks or total_chunks <= 0 or total_chunks > 5000:
        raise HTTPException(400, "Ungültige Chunk-Parameter")
    if kind not in ("video", "image"):
        raise HTTPException(400, "kind muss 'video' oder 'image' sein")

    ext = Path(filename).suffix.lower()
    allowed = ALLOWED_VIDEO_EXT if kind == "video" else ALLOWED_IMAGE_EXT
    if ext not in allowed:
        raise HTTPException(415, f"Dateiendung {ext} nicht erlaubt")

    session_dir = _TMP_UPLOADS / upload_id
    session_dir.mkdir(parents=True, exist_ok=True)

    # Prüfe kumulative Größe gegen MAX_UPLOAD_MB
    max_bytes = MAX_UPLOAD_MB * 1024 * 1024
    existing_size = sum(p.stat().st_size for p in session_dir.glob("chunk_*")) if session_dir.exists() else 0

    chunk_path = session_dir / f"chunk_{chunk_index:06d}"
    written = 0
    with chunk_path.open("wb") as out:
        while True:
            data = await file.read(1024 * 1024)
            if not data:
                break
            written += len(data)
            if existing_size + written > max_bytes:
                out.close()
                # Aufräumen
                shutil.rmtree(session_dir, ignore_errors=True)
                raise HTTPException(413, f"Datei überschreitet Limit von {MAX_UPLOAD_MB} MB")
            out.write(data)

    # Letzter Chunk? -> Zusammensetzen
    if chunk_index + 1 == total_chunks:
        # Verifiziere Vollständigkeit
        chunks = sorted(session_dir.glob("chunk_*"))
        if len(chunks) != total_chunks:
            shutil.rmtree(session_dir, ignore_errors=True)
            raise HTTPException(400, f"Fehlende Chunks: erwartet {total_chunks}, gefunden {len(chunks)}")

        subdir = "videos" if kind == "video" else "images"
        final_name = f"{new_id()}_{safe_filename(filename)}"
        dst = UPLOAD_DIR / subdir / final_name
        total_size = 0
        with dst.open("wb") as out:
            for cp in chunks:
                with cp.open("rb") as inp:
                    while True:
                        d = inp.read(1024 * 1024)
                        if not d:
                            break
                        total_size += len(d)
                        out.write(d)
        shutil.rmtree(session_dir, ignore_errors=True)

        url = f"/api/uploads/{subdir}/{final_name}"
        media = {
            "id": new_id(), "kind": kind, "url": url,
            "name": filename, "size": total_size,
            "created_at": datetime.now(timezone.utc).isoformat(),
        }
        await db.media.insert_one(media)
        return {"complete": True, "url": url, "kind": kind, "size": total_size, "id": media["id"]}

    return {"complete": False, "chunk_index": chunk_index, "received_bytes": written}

@api.post("/admin/upload-chunk-abort")
async def admin_upload_chunk_abort(upload_id: str = Form(...), _: dict = Depends(require_role("admin"))):
    if not _valid_upload_id(upload_id):
        raise HTTPException(400, "Ungültige upload_id")
    session_dir = _TMP_UPLOADS / upload_id
    if session_dir.exists():
        shutil.rmtree(session_dir, ignore_errors=True)
    return {"ok": True}

@api.get("/admin/media")
async def admin_list_media(_: dict = Depends(require_role("admin"))):
    return [clean_doc(m) async for m in db.media.find({}).sort("created_at", -1).limit(500)]

@api.delete("/admin/media/{mid}")
async def admin_delete_media(mid: str, _: dict = Depends(require_role("admin"))):
    m = await db.media.find_one({"id": mid})
    if not m: return {"ok": True}
    try:
        # url like /api/uploads/videos/xxx
        rel = m["url"].replace("/api/uploads/", "", 1)
        path = UPLOAD_DIR / rel
        if path.exists(): path.unlink()
    except Exception as e:
        logger.warning(f"Delete file failed: {e}")
    await db.media.delete_one({"id": mid})
    return {"ok": True}

# ------------- Admin: Security -------------
@api.post("/admin/change-access-code")
async def change_access_code(body: ChangeCodeIn, _: dict = Depends(require_role("admin"))):
    if len(body.new_code) < 4:
        raise HTTPException(400, "Code muss mindestens 4 Zeichen haben.")
    await db.credentials.update_one({"_id": "singleton"}, {"$set": {"access_code_hash": hash_pw(body.new_code)}}, upsert=True)
    return {"ok": True}

@api.post("/admin/change-password")
async def change_password(body: ChangePwIn, _: dict = Depends(require_role("admin"))):
    if len(body.new_password) < 6:
        raise HTTPException(400, "Passwort muss mindestens 6 Zeichen haben.")
    await db.credentials.update_one({"_id": "singleton"}, {"$set": {"admin_password_hash": hash_pw(body.new_password)}}, upsert=True)
    return {"ok": True}

# ------------- Uploaded files (serve, auth-guarded, mit Range-Support für Video-Streaming) -------------
import mimetypes
from fastapi.responses import StreamingResponse, FileResponse, Response

def _guess_mime(name: str) -> str:
    m, _ = mimetypes.guess_type(name)
    return m or "application/octet-stream"

@api.head("/uploads/{subdir}/{fname}")
async def head_upload(subdir: str, fname: str, request: Request):
    tok = get_token(request)
    if not tok or not decode_token(tok):
        raise HTTPException(401, "Not authenticated")
    if subdir not in ("videos", "images"):
        raise HTTPException(404, "Not found")
    path = UPLOAD_DIR / subdir / fname
    if not path.exists() or not path.is_file():
        raise HTTPException(404, "Datei nicht gefunden")
    size = path.stat().st_size
    return Response(status_code=200, headers={
        "Content-Length": str(size),
        "Content-Type": _guess_mime(fname),
        "Accept-Ranges": "bytes",
    })

@api.get("/uploads/{subdir}/{fname}")
async def serve_upload(subdir: str, fname: str, request: Request):
    tok = get_token(request)
    if not tok or not decode_token(tok):
        raise HTTPException(401, "Not authenticated")
    if subdir not in ("videos", "images"):
        raise HTTPException(404, "Not found")
    path = UPLOAD_DIR / subdir / fname
    if not path.exists() or not path.is_file():
        raise HTTPException(404, "Datei nicht gefunden")

    file_size = path.stat().st_size
    mime = _guess_mime(fname)
    range_header = request.headers.get("range")

    # Kein Range-Header: ganze Datei mit Accept-Ranges
    if not range_header:
        return FileResponse(str(path), media_type=mime, headers={"Accept-Ranges": "bytes"})

    # Range parsen: "bytes=start-end" oder "bytes=start-"
    m = re.match(r"bytes=(\d+)-(\d*)", range_header)
    if not m:
        return FileResponse(str(path), media_type=mime, headers={"Accept-Ranges": "bytes"})

    start = int(m.group(1))
    end = int(m.group(2)) if m.group(2) else file_size - 1
    if start >= file_size or end >= file_size or start > end:
        return Response(status_code=416, headers={"Content-Range": f"bytes */{file_size}"})

    length = end - start + 1
    CHUNK = 1024 * 1024

    def iter_range():
        with path.open("rb") as f:
            f.seek(start)
            remaining = length
            while remaining > 0:
                chunk = f.read(min(CHUNK, remaining))
                if not chunk:
                    break
                remaining -= len(chunk)
                yield chunk

    headers = {
        "Content-Range": f"bytes {start}-{end}/{file_size}",
        "Accept-Ranges": "bytes",
        "Content-Length": str(length),
    }
    return StreamingResponse(iter_range(), status_code=206, media_type=mime, headers=headers)

# ------------- Health -------------
@api.get("/")
async def root():
    return {"ok": True, "app": "Meine Weltreise"}

# ------------- Startup: seed & indexes -------------
async def seed_defaults():
    # Credentials singleton
    creds = await db.credentials.find_one({"_id": "singleton"})
    if not creds:
        await db.credentials.insert_one({
            "_id": "singleton",
            "access_code_hash": hash_pw(INITIAL_ACCESS_CODE),
            "admin_password_hash": hash_pw(INITIAL_ADMIN_PASSWORD),
            "created_at": datetime.now(timezone.utc).isoformat(),
        })
        logger.info("Seeded credentials.")

    # Settings singleton
    sett = await db.settings.find_one({"_id": "singleton"})
    if not sett:
        await db.settings.insert_one({
            "_id": "singleton",
            "series_title": "Meine Weltreise",
            "season_default_name": "Staffel 1",
            "hero_subtitle": "Staffel 1",
            "hero_description": "Begleite mich auf meiner Reise um die Welt. Jede Episode zeigt eine weitere Woche voller Abenteuer, neuer Kulturen und unvergesslicher Erinnerungen.",
            "hero_image_url": "",
            "hero_bg_url": "",
            "logo_text": "Weltreise",
            "logo_url": "",
            "footer_text": "© 2026 · Meine Weltreise · Eine persönliche Reisedokumentation",
            "color_bg": "#141414",
            "color_accent": "#e50914",
            "color_text": "#ffffff",
            "year": "2026",
            "font_heading": "Bebas Neue",
            "font_body": "Inter",
            "socials": {},
        })
        logger.info("Seeded settings.")

    # Countries
    if await db.countries.count_documents({}) == 0:
        for c in [("Australien", "🇦🇺"), ("Indonesien", "🇮🇩"), ("Philippinen", "🇵🇭")]:
            await db.countries.insert_one({"id": new_id(), "name": c[0], "flag_emoji": c[1]})

    # Default season + 18 episodes if empty
    if await db.seasons.count_documents({}) == 0:
        season_id = new_id()
        await db.seasons.insert_one({"id": season_id, "name": "Staffel 1", "order": 0, "hidden": False})
        # Load bundled default episodes (from public/data/episodes.json)
        import json
        default_json = Path("/app/frontend/public/data/episodes.json")
        if default_json.exists():
            data = json.loads(default_json.read_text(encoding="utf-8"))
            for i, ep in enumerate(data.get("episodes", [])):
                await db.episodes.insert_one({
                    "id": new_id(),
                    "season_id": season_id,
                    "number": ep.get("number", i + 1),
                    "title": ep.get("title", ""),
                    "description": ep.get("description", ""),
                    "long_description": ep.get("longDescription", ""),
                    "location": ep.get("location", ""),
                    "country": ep.get("country", ""),
                    "date": ep.get("date", ""),
                    "duration": ep.get("duration", 0),
                    "duration_seconds": ep.get("durationSeconds") or (ep.get("duration", 0) * 60),
                    "thumbnail_url": "",  # user will upload
                    "video_url": "",
                    "highlights": ep.get("highlights", []),
                    "travel": ep.get("travel", {}),
                    "hidden": False,
                    "order": i,
                })
            logger.info(f"Seeded {len(data.get('episodes', []))} episodes.")

# ------------- CORS & mount -------------
app.include_router(api)
app.add_middleware(
    CORSMiddleware,
    allow_credentials=True,
    allow_origins=os.environ.get("CORS_ORIGINS", "*").split(","),
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.on_event("startup")
async def on_startup():
    await seed_defaults()
    try:
        await db.episodes.create_index([("season_id", 1), ("order", 1)])
        await db.login_attempts.create_index("locked_until", expireAfterSeconds=0)
    except Exception as e:
        logger.warning(f"Index setup: {e}")
    logger.info("Startup complete.")

@app.on_event("shutdown")
async def on_shutdown():
    client.close()
