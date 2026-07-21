"""
Backend tests for Meine Weltreise (FastAPI + MongoDB).
All tests are in one class so they run sequentially on a single xdist worker
(loadscope). This is important because the backend sees all traffic as one IP,
so parallel login attempts across workers can trigger brute-force lockout.
"""
import os
import io
import base64
import requests
import pytest

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/") or "http://localhost:8001"

ACCESS_CODE = "pineapplethefruitdude"
ADMIN_PW = "Jagger-Admin-2026-Kx9!Pq"

TINY_PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII="
)


@pytest.fixture(scope="class")
def viewer():
    s = requests.Session()
    r = s.post(f"{BASE_URL}/api/auth/access-code", json={"code": ACCESS_CODE})
    assert r.status_code == 200, r.text
    return s


@pytest.fixture(scope="class")
def admin():
    s = requests.Session()
    r = s.post(f"{BASE_URL}/api/auth/admin", json={"password": ADMIN_PW})
    assert r.status_code == 200, r.text
    return s


class TestBackend:
    """All backend tests in one class => run sequentially on one worker."""

    def test_01_me_unauth(self):
        r = requests.get(f"{BASE_URL}/api/auth/me")
        assert r.status_code == 200
        assert r.json() == {"authenticated": False, "role": None}

    def test_02_access_code_wrong(self):
        r = requests.post(f"{BASE_URL}/api/auth/access-code", json={"code": "definitely-wrong"})
        assert r.status_code == 401
        assert "Falscher" in r.json().get("detail", "")

    def test_03_access_code_correct(self):
        s = requests.Session()
        r = s.post(f"{BASE_URL}/api/auth/access-code", json={"code": ACCESS_CODE})
        assert r.status_code == 200
        j = r.json()
        assert j["role"] == "viewer" and "token" in j
        assert "access_token" in s.cookies.get_dict()
        me = s.get(f"{BASE_URL}/api/auth/me").json()
        assert me["authenticated"] and me["role"] == "viewer"

    def test_04_admin_wrong(self):
        r = requests.post(f"{BASE_URL}/api/auth/admin", json={"password": "nope"})
        assert r.status_code == 401

    def test_05_admin_correct(self):
        s = requests.Session()
        r = s.post(f"{BASE_URL}/api/auth/admin", json={"password": ADMIN_PW})
        assert r.status_code == 200
        assert r.json()["role"] == "admin"

    def test_10_content_needs_auth(self):
        r = requests.get(f"{BASE_URL}/api/content")
        assert r.status_code == 401

    def test_11_content_seed(self, viewer):
        r = viewer.get(f"{BASE_URL}/api/content")
        assert r.status_code == 200
        d = r.json()
        assert isinstance(d["settings"].get("series_title"), str) and d["settings"]["series_title"]
        assert len(d["episodes"]) >= 1
        assert len(d["seasons"]) >= 1
        assert isinstance(d["countries"], list) and len(d["countries"]) >= 1

    def test_20_settings_forbidden_viewer(self, viewer):
        r = viewer.get(f"{BASE_URL}/api/admin/settings")
        assert r.status_code == 403

    def test_21_settings_admin_ok(self, admin):
        r = admin.get(f"{BASE_URL}/api/admin/settings")
        assert r.status_code == 200
        assert "series_title" in r.json()

    def test_22_settings_update(self, admin):
        orig = admin.get(f"{BASE_URL}/api/admin/settings").json()["series_title"]
        r = admin.put(f"{BASE_URL}/api/admin/settings", json={"series_title": "TEST_TITLE"})
        assert r.status_code == 200 and r.json()["series_title"] == "TEST_TITLE"
        admin.put(f"{BASE_URL}/api/admin/settings", json={"series_title": orig})

    def test_30_upload_and_serve(self, admin, viewer):
        files = {"file": ("t.png", io.BytesIO(TINY_PNG), "image/png")}
        r = admin.post(f"{BASE_URL}/api/admin/upload", files=files, data={"kind": "image"})
        assert r.status_code == 200, r.text
        url = r.json()["url"]
        assert url.startswith("/api/uploads/images/")
        full = f"{BASE_URL}{url}"
        assert requests.get(full).status_code == 401
        r3 = viewer.get(full)
        assert r3.status_code == 200 and r3.content[:4] == b"\x89PNG"

    def test_40_episodes_crud(self, admin):
        seasons = admin.get(f"{BASE_URL}/api/admin/seasons").json()
        assert isinstance(seasons, list) and len(seasons) >= 1
        sid = seasons[0]["id"]
        payload = {"season_id": sid, "title": "TEST_Ep", "description": "d"}
        r = admin.post(f"{BASE_URL}/api/admin/episodes", json=payload)
        assert r.status_code == 200
        eid = r.json()["id"]
        r = admin.put(f"{BASE_URL}/api/admin/episodes/{eid}", json={**payload, "title": "TEST_Up"})
        assert r.status_code == 200 and r.json()["title"] == "TEST_Up"
        ids = [e["id"] for e in admin.get(f"{BASE_URL}/api/admin/episodes").json()]
        r = admin.post(f"{BASE_URL}/api/admin/episodes/reorder", json={"ids": ids})
        assert r.status_code == 200
        r = admin.delete(f"{BASE_URL}/api/admin/episodes/{eid}")
        assert r.status_code == 200
        assert eid not in [e["id"] for e in admin.get(f"{BASE_URL}/api/admin/episodes").json()]

    def test_50_seasons_crud(self, admin):
        r = admin.post(f"{BASE_URL}/api/admin/seasons", json={"name": "TEST_S", "order": 99})
        assert r.status_code == 200
        sid = r.json()["id"]
        r = admin.put(f"{BASE_URL}/api/admin/seasons/{sid}", json={"name": "TEST_S2", "order": 100})
        assert r.status_code == 200 and r.json()["name"] == "TEST_S2"
        r = admin.delete(f"{BASE_URL}/api/admin/seasons/{sid}")
        assert r.status_code == 200

    def test_60_countries_crud(self, admin):
        r = admin.post(f"{BASE_URL}/api/admin/countries", json={"name": "TEST_Land", "flag_emoji": "🏳"})
        assert r.status_code == 200
        cid = r.json()["id"]
        r = admin.delete(f"{BASE_URL}/api/admin/countries/{cid}")
        assert r.status_code == 200

    def test_70_change_access_code(self, admin):
        new_code = "tempcode1234"
        r = admin.post(f"{BASE_URL}/api/admin/change-access-code", json={"new_code": new_code})
        assert r.status_code == 200
        r = requests.post(f"{BASE_URL}/api/auth/access-code", json={"code": new_code})
        assert r.status_code == 200
        r = admin.post(f"{BASE_URL}/api/admin/change-access-code", json={"new_code": ACCESS_CODE})
        assert r.status_code == 200
        assert requests.post(f"{BASE_URL}/api/auth/access-code", json={"code": ACCESS_CODE}).status_code == 200

    def test_71_change_admin_password(self, admin):
        new_pw = "TempAdmin-2026-XYZ"
        r = admin.post(f"{BASE_URL}/api/admin/change-password", json={"new_password": new_pw})
        assert r.status_code == 200
        s = requests.Session()
        r = s.post(f"{BASE_URL}/api/auth/admin", json={"password": new_pw})
        assert r.status_code == 200
        r = s.post(f"{BASE_URL}/api/admin/change-password", json={"new_password": ADMIN_PW})
        assert r.status_code == 200
        assert requests.post(f"{BASE_URL}/api/auth/admin", json={"password": ADMIN_PW}).status_code == 200

    def test_99_brute_force_lockout(self):
        # Runs last; conftest session teardown clears the lockout.
        got = False
        for _ in range(20):
            r = requests.post(f"{BASE_URL}/api/auth/access-code", json={"code": "wrong-xyz"})
            if r.status_code == 429:
                got = True
                assert "Zu viele" in r.json().get("detail", "")
                break
        assert got, "Expected 429 lockout within 20 attempts"
