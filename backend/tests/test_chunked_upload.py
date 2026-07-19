"""
Tests for the new chunked upload endpoints:
  POST /api/admin/upload-chunk
  POST /api/admin/upload-chunk-abort
Also verifies /api/uploads/videos/<fname> serves the reassembled file.
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

CHUNK_SIZE = 3 * 1024 * 1024  # 3 MB
FILE_SIZE = 9 * 1024 * 1024 + 123  # ~9MB -> 4 chunks (3+3+3+0.something) actually 3 chunks + tail


@pytest.fixture(scope="module")
def admin_session():
    s = requests.Session()
    r = s.post(f"{BASE_URL}/api/auth/admin", json={"password": ADMIN_PW})
    assert r.status_code == 200, r.text
    return s


@pytest.fixture(scope="module")
def viewer_session():
    s = requests.Session()
    r = s.post(f"{BASE_URL}/api/auth/access-code", json={"code": ACCESS_CODE})
    assert r.status_code == 200, r.text
    return s


def _rand_bytes(n):
    # Deterministic pseudo random content
    out = bytearray()
    seed = b"TEST_CHUNK_UPLOAD"
    while len(out) < n:
        out += hashlib.sha256(seed + len(out).to_bytes(8, "big")).digest()
    return bytes(out[:n])


class TestChunkedUpload:
    created_media_ids = []

    def test_01_unauth_returns_401(self):
        # No admin cookie -> 401
        r = requests.post(
            f"{BASE_URL}/api/admin/upload-chunk",
            files={"file": ("chunk", io.BytesIO(b"x"), "application/octet-stream")},
            data={
                "upload_id": "abcdef1234",
                "chunk_index": 0,
                "total_chunks": 1,
                "filename": "test.mp4",
                "kind": "video",
            },
        )
        assert r.status_code == 401

    def test_02_invalid_upload_id(self, admin_session):
        r = admin_session.post(
            f"{BASE_URL}/api/admin/upload-chunk",
            files={"file": ("c", io.BytesIO(b"x"), "application/octet-stream")},
            data={
                "upload_id": "foo/../bar",
                "chunk_index": 0,
                "total_chunks": 1,
                "filename": "t.mp4",
                "kind": "video",
            },
        )
        assert r.status_code == 400

    def test_03_bad_extension(self, admin_session):
        r = admin_session.post(
            f"{BASE_URL}/api/admin/upload-chunk",
            files={"file": ("c", io.BytesIO(b"x"), "application/octet-stream")},
            data={
                "upload_id": uuid.uuid4().hex,
                "chunk_index": 0,
                "total_chunks": 1,
                "filename": "malicious.exe",
                "kind": "video",
            },
        )
        assert r.status_code == 415

    def test_04_bad_kind(self, admin_session):
        r = admin_session.post(
            f"{BASE_URL}/api/admin/upload-chunk",
            files={"file": ("c", io.BytesIO(b"x"), "application/octet-stream")},
            data={
                "upload_id": uuid.uuid4().hex,
                "chunk_index": 0,
                "total_chunks": 1,
                "filename": "t.mp4",
                "kind": "banana",
            },
        )
        assert r.status_code == 400

    def test_05_chunk_index_out_of_range(self, admin_session):
        r = admin_session.post(
            f"{BASE_URL}/api/admin/upload-chunk",
            files={"file": ("c", io.BytesIO(b"x"), "application/octet-stream")},
            data={
                "upload_id": uuid.uuid4().hex,
                "chunk_index": 5,
                "total_chunks": 3,
                "filename": "t.mp4",
                "kind": "video",
            },
        )
        assert r.status_code == 400

    def test_10_full_chunked_video_upload_and_playback(self, admin_session, viewer_session):
        payload = _rand_bytes(FILE_SIZE)
        upload_id = uuid.uuid4().hex
        chunks = [payload[i:i+CHUNK_SIZE] for i in range(0, len(payload), CHUNK_SIZE)]
        total = len(chunks)
        assert total >= 2

        media_url = None
        media_size = None
        media_id = None
        for idx, cdata in enumerate(chunks):
            r = admin_session.post(
                f"{BASE_URL}/api/admin/upload-chunk",
                files={"file": (f"part{idx}", io.BytesIO(cdata), "application/octet-stream")},
                data={
                    "upload_id": upload_id,
                    "chunk_index": idx,
                    "total_chunks": total,
                    "filename": "TEST_bigvid.mp4",
                    "kind": "video",
                },
            )
            assert r.status_code == 200, f"chunk {idx}: {r.status_code} {r.text}"
            j = r.json()
            if idx < total - 1:
                assert j.get("complete") is False
                assert j.get("chunk_index") == idx
                assert j.get("received_bytes") == len(cdata)
            else:
                assert j.get("complete") is True
                media_url = j["url"]
                media_size = j["size"]
                media_id = j["id"]

        assert media_url and media_url.startswith("/api/uploads/videos/")
        assert media_size == FILE_SIZE
        TestChunkedUpload.created_media_ids.append(media_id)

        # Verify reassembled file exists on disk with correct bytes
        fname = media_url.rsplit("/", 1)[-1]
        disk_path = Path("/app/backend/uploads/videos") / fname
        assert disk_path.exists(), f"file not on disk: {disk_path}"
        assert disk_path.stat().st_size == FILE_SIZE
        disk_bytes = disk_path.read_bytes()
        assert hashlib.sha256(disk_bytes).hexdigest() == hashlib.sha256(payload).hexdigest()

        # Verify /api/uploads/videos/<fname> serves it (auth-guarded via viewer)
        assert requests.get(f"{BASE_URL}{media_url}").status_code == 401
        r = viewer_session.get(f"{BASE_URL}{media_url}")
        assert r.status_code == 200
        assert len(r.content) == FILE_SIZE
        assert hashlib.sha256(r.content).hexdigest() == hashlib.sha256(payload).hexdigest()

        # Range request check
        r = viewer_session.get(f"{BASE_URL}{media_url}", headers={"Range": "bytes=100-199"})
        assert r.status_code == 206
        assert len(r.content) == 100
        assert r.content == payload[100:200]

        # Verify media entry exists in listing
        lst = admin_session.get(f"{BASE_URL}/api/admin/media").json()
        assert any(m["id"] == media_id for m in lst)

        # Tmp dir should be cleaned up
        assert not (Path("/app/backend/uploads/tmp") / upload_id).exists()

    def test_11_abort_cleans_tmp(self, admin_session):
        upload_id = uuid.uuid4().hex
        data = _rand_bytes(1024 * 100)
        r = admin_session.post(
            f"{BASE_URL}/api/admin/upload-chunk",
            files={"file": ("p", io.BytesIO(data), "application/octet-stream")},
            data={
                "upload_id": upload_id,
                "chunk_index": 0,
                "total_chunks": 5,
                "filename": "TEST_abort.mp4",
                "kind": "video",
            },
        )
        assert r.status_code == 200
        tmp_dir = Path("/app/backend/uploads/tmp") / upload_id
        assert tmp_dir.exists()
        r = admin_session.post(
            f"{BASE_URL}/api/admin/upload-chunk-abort",
            data={"upload_id": upload_id},
        )
        assert r.status_code == 200 and r.json() == {"ok": True}
        assert not tmp_dir.exists()

    def test_12_abort_invalid_id(self, admin_session):
        r = admin_session.post(
            f"{BASE_URL}/api/admin/upload-chunk-abort",
            data={"upload_id": "bad/id"},
        )
        assert r.status_code == 400

    def test_20_classic_upload_still_works(self, admin_session, viewer_session):
        # Small file via classic direct upload path
        import base64
        TINY_PNG = base64.b64decode(
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII="
        )
        r = admin_session.post(
            f"{BASE_URL}/api/admin/upload",
            files={"file": ("tiny.png", io.BytesIO(TINY_PNG), "image/png")},
            data={"kind": "image"},
        )
        assert r.status_code == 200
        j = r.json()
        assert j["url"].startswith("/api/uploads/images/")
        TestChunkedUpload.created_media_ids.append(j["id"])

    def test_99_cleanup_created_media(self, admin_session):
        for mid in TestChunkedUpload.created_media_ids:
            r = admin_session.delete(f"{BASE_URL}/api/admin/media/{mid}")
            assert r.status_code == 200
