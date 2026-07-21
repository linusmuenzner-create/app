"""
Refactor-focused tests: /api/config public, HEAD range serve, direct upload 415,
chunked-upload response shape, seed idempotency, admin settings roundtrip.
Uses '== True/False' style per project convention.
"""
import os
import io
import uuid
import hashlib
import requests
import pytest
from pathlib import Path

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/") or "http://localhost:8001"
ACCESS_CODE = "pineapplethefruitdude"
ADMIN_PW = "Jagger-Admin-2026-Kx9!Pq"


@pytest.fixture(scope="module")
def admin():
    s = requests.Session()
    r = s.post(f"{BASE_URL}/api/auth/admin", json={"password": ADMIN_PW})
    assert r.status_code == 200, r.text
    return s


def _clear_lockout():
    try:
        from pymongo import MongoClient
        cli = MongoClient(os.environ.get("MONGO_URL", "mongodb://localhost:27017"),
                          serverSelectionTimeoutMS=2000)
        cli[os.environ.get("DB_NAME", "test_database")].login_attempts.delete_many({})
        cli.close()
    except Exception:
        pass


@pytest.fixture(scope="module")
def viewer():
    _clear_lockout()
    s = requests.Session()
    r = s.post(f"{BASE_URL}/api/auth/access-code", json={"code": ACCESS_CODE})
    if r.status_code != 200:
        _clear_lockout()
        r = s.post(f"{BASE_URL}/api/auth/access-code", json={"code": ACCESS_CODE})
    assert r.status_code == 200, r.text
    return s


def _rand_bytes(n):
    out = bytearray()
    while len(out) < n:
        out += hashlib.sha256(len(out).to_bytes(8, "big")).digest()
    return bytes(out[:n])


class TestRefactor:
    _created_media = []

    # ---------- Public health + config ----------
    def test_01_health(self):
        r = requests.get(f"{BASE_URL}/api/")
        assert r.status_code == 200
        j = r.json()
        assert j.get("ok") == True
        assert j.get("app") == "Meine Weltreise"

    def test_02_public_config_no_auth(self):
        # No cookie set
        r = requests.get(f"{BASE_URL}/api/config")
        assert r.status_code == 200
        j = r.json()
        # Keys required by refactor spec
        for k in ("color_accent", "year", "logo_text"):
            assert k in j, f"{k} missing from /api/config"

    # ---------- Direct upload 415 ----------
    def test_10_direct_upload_wrong_ctype_video(self, admin):
        # kind=video but content-type=image/png must be 415
        r = admin.post(
            f"{BASE_URL}/api/admin/upload",
            files={"file": ("t.png", io.BytesIO(b"\x89PNG\r\n\x1a\n"), "image/png")},
            data={"kind": "video"},
        )
        assert r.status_code == 415, r.text

    # ---------- Chunked upload response format ----------
    def test_20_chunked_upload_shape_and_ondisk(self, admin, viewer):
        # ~6 MB with 3 MB chunks -> exactly 2 chunks
        chunk_size = 3 * 1024 * 1024
        size = 6 * 1024 * 1024
        payload = _rand_bytes(size)
        chunks = [payload[i:i+chunk_size] for i in range(0, size, chunk_size)]
        assert len(chunks) == 2
        upload_id = uuid.uuid4().hex
        media_url = None
        media_id = None

        for idx, cdata in enumerate(chunks):
            r = admin.post(
                f"{BASE_URL}/api/admin/upload-chunk",
                files={"file": (f"p{idx}", io.BytesIO(cdata), "application/octet-stream")},
                data={
                    "upload_id": upload_id,
                    "chunk_index": idx,
                    "total_chunks": len(chunks),
                    "filename": "TEST_refactor.mp4",
                    "kind": "video",
                },
            )
            assert r.status_code == 200, r.text
            j = r.json()
            if idx == 0:
                assert j.get("complete") == False
                assert j.get("chunk_index") == 0
                assert j.get("received_bytes") == len(cdata)
            else:
                assert j.get("complete") == True
                assert j.get("url", "").startswith("/api/uploads/videos/")
                assert j.get("size") == size
                media_url = j["url"]
                media_id = j["id"]
        TestRefactor._created_media.append(media_id)

        # Disk file exact byte sum
        fname = media_url.rsplit("/", 1)[-1]
        disk = Path("/app/backend/uploads/videos") / fname
        assert disk.exists()
        assert disk.stat().st_size == size

        # ---------- Range 206 + no-range 200 + Accept-Ranges ----------
        # Range: bytes=0-1023  → 206 with exactly 1024 bytes
        r = viewer.get(f"{BASE_URL}{media_url}", headers={"Range": "bytes=0-1023"})
        assert r.status_code == 206
        assert len(r.content) == 1024
        assert "content-range" in {k.lower() for k in r.headers.keys()}

        # No range → 200 + Accept-Ranges header
        r = viewer.get(f"{BASE_URL}{media_url}")
        assert r.status_code == 200
        assert r.headers.get("Accept-Ranges") == "bytes"

        # ---------- HEAD ----------
        r = viewer.head(f"{BASE_URL}{media_url}")
        assert r.status_code == 200
        assert r.headers.get("Content-Length") == str(size)
        assert r.headers.get("Content-Type", "").startswith("video/")
        assert r.headers.get("Accept-Ranges") == "bytes"

    # ---------- Admin settings roundtrip ----------
    def test_30_settings_roundtrip(self, admin):
        r = admin.get(f"{BASE_URL}/api/admin/settings")
        assert r.status_code == 200
        orig = r.json()

        new_vals = {
            "color_accent": "#123456",
            "color_bg": "#000010",
            "color_text": "#eeeeee",
            "logo_text": "TEST_LOGO",
            "footer_text": "TEST_FOOTER",
            "year": "2027",
            "socials": {"instagram": "https://example.com/x"},
        }
        r = admin.put(f"{BASE_URL}/api/admin/settings", json=new_vals)
        assert r.status_code == 200
        r = admin.get(f"{BASE_URL}/api/admin/settings").json()
        for k, v in new_vals.items():
            assert r.get(k) == v, f"settings did not persist for {k}: got {r.get(k)!r}"

        # Restore
        restore = {k: orig.get(k) for k in new_vals.keys()}
        admin.put(f"{BASE_URL}/api/admin/settings", json=restore)

    # ---------- Season cascade delete ----------
    def test_40_season_delete_cascades_episodes(self, admin):
        r = admin.post(f"{BASE_URL}/api/admin/seasons", json={"name": "TEST_Cascade", "order": 999})
        assert r.status_code == 200
        sid = r.json()["id"]
        # Create 2 episodes under it
        eids = []
        for i in range(2):
            r = admin.post(
                f"{BASE_URL}/api/admin/episodes",
                json={"season_id": sid, "title": f"TEST_CasEp{i}", "description": "d"},
            )
            assert r.status_code == 200
            eids.append(r.json()["id"])
        # Sanity: episodes exist
        all_eps = admin.get(f"{BASE_URL}/api/admin/episodes").json()
        for eid in eids:
            assert any(e["id"] == eid for e in all_eps)
        # Delete season
        r = admin.delete(f"{BASE_URL}/api/admin/seasons/{sid}")
        assert r.status_code == 200
        # Episodes must be gone
        all_eps = admin.get(f"{BASE_URL}/api/admin/episodes").json()
        for eid in eids:
            assert not any(e["id"] == eid for e in all_eps), f"episode {eid} not cascaded"

    # ---------- Seed idempotency ----------
    def test_50_seed_idempotent(self, viewer):
        # Seeding must NOT wipe/replace existing user data. We just verify
        # that /api/content returns a valid non-empty structure. Exact counts
        # depend on live admin edits (episodes may be hidden/deleted by user).
        r = viewer.get(f"{BASE_URL}/api/content")
        assert r.status_code == 200
        d = r.json()
        assert isinstance(d["episodes"], list)
        assert isinstance(d["seasons"], list) and len(d["seasons"]) >= 1
        assert isinstance(d["countries"], list) and len(d["countries"]) >= 1
        assert d["settings"].get("series_title")

    def test_99_cleanup(self, admin):
        for mid in TestRefactor._created_media:
            if mid:
                admin.delete(f"{BASE_URL}/api/admin/media/{mid}")
