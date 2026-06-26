#!/usr/bin/env python3
"""
HikCentral Professional camera status poller.
Runs as a background thread; writes events to the SQLite DB.
"""
import hashlib, hmac, base64, time, threading, json
from datetime import datetime, timezone, timedelta
from typing import Optional
import requests, urllib3
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import padding as apad

urllib3.disable_warnings()

# ── CONFIG ─────────────────────────────────────────────────────────────
HIKCENTRAL_HOST  = "https://10.0.120.11"
HIKCENTRAL_PORT  = 443
APP_KEY          = "63165798"
SECRET_KEY_HIK   = "9GgH0XqmgrdBDKHEaics"
HCP_USER         = "admin"
HCP_PASS         = "MASTER0!"
TELEGRAM_TOKEN   = "8873812403:AAFwDJ0n91tDaFAELh5MjQDsGsfYx7jCP2Y"
TELEGRAM_CHAT_ID = "-1003710403799"
POLL_INTERVAL    = 60
PAGE_SIZE        = 200
ISAPI_REFRESH    = 1200
TZ               = timezone(timedelta(hours=5))

# ── STATE ──────────────────────────────────────────────────────────────
cam_ips: dict[str, str] = {}
_isapi_ts: float        = 0.0
prev_status: dict[str, int] = {}
offline_since: dict[str, datetime] = {}
last_poll_time: Optional[datetime] = None
lock = threading.Lock()

_SessionLocal = None


def set_session_factory(factory):
    global _SessionLocal
    _SessionLocal = factory


def _new_db():
    if _SessionLocal:
        return _SessionLocal()
    return None


# ── TELEGRAM ───────────────────────────────────────────────────────────
def tg_send(text: str):
    try:
        requests.post(
            f"https://api.telegram.org/bot{TELEGRAM_TOKEN}/sendMessage",
            json={"chat_id": TELEGRAM_CHAT_ID, "text": text, "parse_mode": "HTML"},
            timeout=10,
        )
    except Exception:
        pass


# ── OPENAPI AUTH ───────────────────────────────────────────────────────
def _hdrs(path: str) -> dict:
    s = f"POST\n*/*\napplication/json\nx-ca-key:{APP_KEY}\n{path}"
    sig = base64.b64encode(
        hmac.new(SECRET_KEY_HIK.encode(), s.encode(), hashlib.sha256).digest()
    ).decode()
    return {
        "Content-Type": "application/json", "Accept": "*/*",
        "x-ca-key": APP_KEY, "x-ca-signature": sig,
        "x-ca-signature-headers": "x-ca-key", "userId": "admin",
    }


def hik(path: str, body: dict = None):
    try:
        r = requests.post(
            f"{HIKCENTRAL_HOST}:{HIKCENTRAL_PORT}{path}",
            headers=_hdrs(path),
            data=json.dumps(body or {}).encode(),
            timeout=30, verify=False,
        )
        return r.json()
    except Exception:
        return None


# ── HCP WEB LOGIN ──────────────────────────────────────────────────────
_WEB_HDR = {
    "Accept": "application/xml, text/xml, */*;",
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:151.0) Gecko/20100101 Firefox/151.0",
    "Referer": HIKCENTRAL_HOST + "/",
    "Origin": HIKCENTRAL_HOST,
}


def hcp_login() -> str:
    try:
        r = requests.post(
            HIKCENTRAL_HOST + "/ISAPI/Bumblebee/Platform/V0/Security/Crypto?MT=GET",
            headers=_WEB_HDR, verify=False, timeout=15,
        )
        crypto_cookie = r.cookies.get("CRYPTO", "")
        crypto_key = (r.json().get("ResponseStatus", {})
                       .get("Data", {}).get("CryptoResponse", {}).get("CryptoKey", ""))
        if not crypto_cookie or not crypto_key:
            return ""

        pub    = serialization.load_der_public_key(base64.b64decode(crypto_key))
        enc_pw = base64.b64encode(pub.encrypt(HCP_PASS.encode(), apad.PKCS1v15())).decode()

        body = json.dumps({
            "LoginRequest": {
                "UserName": HCP_USER, "Password": enc_pw,
                "LoginAddress": HIKCENTRAL_HOST.replace("https://", ""),
                "LoginModel": 1, "IsRSMWebLogin": 0,
            }
        })
        r2 = requests.post(
            HIKCENTRAL_HOST + "/ISAPI/Bumblebee/Platform/V0/Login?CT=0&MT=POST",
            headers={**_WEB_HDR,
                     "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                     "Cookie": f"CRYPTO={crypto_cookie}"},
            data=body.encode(), verify=False, timeout=15,
        )
        sid = r2.cookies.get("SID", "")
        ec  = r2.json().get("ResponseStatus", {}).get("ErrorCode", -1)
        return sid if ec == 0 else ""
    except Exception:
        return ""


# ── ISAPI CHANNEL IPs ──────────────────────────────────────────────────
def fetch_cam_ips(sid: str) -> dict:
    if not sid:
        return {}
    result = {}
    url    = f"{HIKCENTRAL_HOST}/ISAPI/Bumblebee/ResourceMaintain/V0/StatusMonitor/CameraElements?MT=GET"
    hdrs   = {"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
               "Cookie": f"SID={sid}"}
    page   = 1
    while True:
        body = json.dumps({
            "CameraElementStatusRequest": {
                "PageSize": 200, "PageIndex": page,
                "SearchCriteria": {"AreaID": -1, "SiteID": 0, "Alias": "", "DepthTraversal": 0},
                "StatusType": -1, "Sort": {"SortField": -1, "SortType": False},
            }
        })
        try:
            r     = requests.post(url, data=body, headers=hdrs, verify=False, timeout=30)
            if r.status_code != 200:
                break
            cl    = r.json().get("ResponseStatus", {}).get("Data", {}).get("CameraElementStatusList", {})
            cams  = cl.get("CameraElementStatus", [])
            total = cl.get("TotalNum", 0)
            for c in cams:
                cid = str(c.get("ID", ""))
                ip  = c.get("AddressChannel", "")
                if cid and ip:
                    result[cid] = ip
            if not cams or len(cams) < 200 or (total > 0 and len(result) >= total):
                break
            page += 1
        except Exception:
            break
    return result


def refresh_isapi():
    global cam_ips, _isapi_ts
    sid   = hcp_login()
    fresh = fetch_cam_ips(sid) if sid else {}
    if fresh:
        cam_ips = fresh
    _isapi_ts = time.time()


# ── OPENAPI DATA ───────────────────────────────────────────────────────
def _fetch_nvrs_raw() -> dict:
    nvrs, page = {}, 1
    while True:
        d = hik("/artemis/api/resource/v1/encodeDevice/encodeDeviceList",
                {"pageNo": page, "pageSize": 500})
        if not d or str(d.get("code")) != "0":
            break
        for item in d.get("data", {}).get("list", []):
            nvrs[item["encodeDevIndexCode"]] = {
                "name": item.get("encodeDevName", ""),
                "ip":   item.get("encodeDevIp", ""),
            }
        if len(nvrs) >= d.get("data", {}).get("total", 0):
            break
        page += 1
    return nvrs


def _fetch_regions_raw() -> dict:
    regions, page = {}, 1
    while True:
        d = hik("/artemis/api/resource/v1/regions", {"pageNo": page, "pageSize": 500})
        if not d or str(d.get("code")) != "0":
            break
        for r in d.get("data", {}).get("list", []):
            regions[r["indexCode"]] = r.get("name", "")
        if len(regions) >= d.get("data", {}).get("total", 0) or not d.get("data", {}).get("list"):
            break
        page += 1
    return regions


def _fetch_cameras_raw(nvrs: dict, regions: dict) -> dict:
    cameras, page = {}, 1
    while True:
        d = hik("/artemis/api/resource/v1/camera/advance/cameraList",
                {"pageNo": page, "pageSize": PAGE_SIZE, "bRecordSetting": 0})
        if not d or str(d.get("code")) != "0":
            break
        batch = d.get("data", {}).get("list", [])
        total = d.get("data", {}).get("total", 0)
        for c in batch:
            idx     = c.get("cameraIndexCode", "")
            nvrinfo = nvrs.get(c.get("encodeDevIndexCode", ""), {})
            cameras[idx] = {
                "name":            c.get("cameraName", idx),
                "status":          c.get("status", 0),
                "nvr_code":        c.get("encodeDevIndexCode", ""),
                "nvrName":         nvrinfo.get("name", ""),
                "nvrIp":           nvrinfo.get("ip", ""),
                "area":            regions.get(c.get("regionIndexCode", ""), ""),
                "channelIp":       cam_ips.get(idx, ""),
            }
        if len(cameras) >= total or not batch:
            break
        page += 1
    return cameras


# ── DB SYNC ────────────────────────────────────────────────────────────
def sync_nvrs_to_db(db):
    """Upsert NVRs from HikCentral into the database."""
    import models as m
    raw = _fetch_nvrs_raw()
    for code, info in raw.items():
        nvr = db.query(m.NVR).filter(m.NVR.hik_code == code).first()
        if nvr:
            if not nvr.name_overridden:
                nvr.name = info["name"]
            nvr.ip = info["ip"]
        else:
            db.add(m.NVR(hik_code=code, name=info["name"], ip=info["ip"]))
    db.commit()


def sync_cameras_to_db(db, nvrs_raw: dict, cameras_raw: dict):
    """Upsert cameras from HikCentral into the database."""
    import models as m
    for code, info in cameras_raw.items():
        nvr_db = db.query(m.NVR).filter(m.NVR.hik_code == info["nvr_code"]).first()
        cam = db.query(m.Camera).filter(m.Camera.hik_code == code).first()
        if cam:
            if not cam.name_overridden:
                cam.name = info["name"]
            cam.channel_ip = info["channelIp"]
            if nvr_db:
                cam.nvr_id = nvr_db.id
        else:
            db.add(m.Camera(
                hik_code   = code,
                name       = info["name"],
                channel_ip = info["channelIp"],
                nvr_id     = nvr_db.id if nvr_db else None,
            ))
    db.commit()


# ── POLL CYCLE ─────────────────────────────────────────────────────────
def _poll_once():
    global prev_status, last_poll_time

    if time.time() - _isapi_ts > ISAPI_REFRESH:
        refresh_isapi()

    nvrs_raw    = _fetch_nvrs_raw()
    regions_raw = _fetch_regions_raw()
    cameras_raw = _fetch_cameras_raw(nvrs_raw, regions_raw)
    if not cameras_raw:
        return

    db       = _new_db()
    now_utc  = datetime.now(timezone.utc)
    tz_local = timezone(timedelta(hours=5))

    def ts():
        return datetime.now(tz_local).strftime("%d.%m.%Y %H:%M:%S")

    def fmt_dur(sec: float) -> str:
        s = int(sec)
        if s < 60: return f"{s} son."
        m, r = divmod(s, 60)
        if m < 60: return f"{m} daq. {r} son."
        h, m2 = divmod(m, 60)
        return f"{h} soat {m2} daq."

    try:
        if db:
            import models as mdl
            sync_cameras_to_db(db, nvrs_raw, cameras_raw)

        with lock:
            old = dict(prev_status)
            offline_events = []
            online_events  = []

            for idx, info in cameras_raw.items():
                new_st = info["status"]
                old_st = old.get(idx, -1)
                if old_st == -1:
                    if new_st == 2:
                        offline_since[idx] = now_utc
                    continue
                if old_st != 2 and new_st == 2:
                    offline_since[idx] = now_utc
                    offline_events.append((idx, info))
                elif old_st == 2 and new_st != 2:
                    online_events.append((idx, info, offline_since.pop(idx, None)))

            prev_status = {idx: info["status"] for idx, info in cameras_raw.items()}

        # DB updates + Telegram
        for idx, info in offline_events:
            if db:
                cam_db = db.query(mdl.Camera).filter(mdl.Camera.hik_code == idx).first()
                if cam_db:
                    cam_db.current_status    = 2
                    cam_db.last_status_change = now_utc
                    db.add(mdl.CameraEvent(
                        camera_id  = cam_db.id,
                        event_type = "offline",
                        started_at = now_utc,
                    ))
                    db.commit()

            ges = info["area"] or info["nvrName"]
            ip  = info["channelIp"] or info["nvrIp"]
            tg_send(
                f"📵 <b>Camera Offline</b>\n"
                f"━━━━━━━━━━━━━━━━━━━\n"
                f"📹 <b>Kamera:</b> {info['name']}\n"
                f"📍 <b>Ges:</b> {ges}\n"
                f"🌐 <b>IP (Kamera):</b> <code>{ip}</code>\n"
                f"⏰ <b>Uzilish vaqti:</b> {ts()}"
            )

        for idx, info, since in online_events:
            dur_sec = (now_utc - since).total_seconds() if since else None
            if db:
                cam_db = db.query(mdl.Camera).filter(mdl.Camera.hik_code == idx).first()
                if cam_db:
                    cam_db.current_status    = 1
                    cam_db.last_status_change = now_utc
                    # Barcha ochiq offline eventlarni yopamiz (bir nechta bo'lishi mumkin)
                    open_evs = (db.query(mdl.CameraEvent)
                                .filter(mdl.CameraEvent.camera_id == cam_db.id,
                                        mdl.CameraEvent.event_type == "offline",
                                        mdl.CameraEvent.ended_at == None)
                                .order_by(mdl.CameraEvent.started_at.desc())
                                .all())
                    for i, ev in enumerate(open_evs):
                        ev.ended_at     = now_utc
                        # duration faqat eng so'nggi eventda to'g'ri
                        ev.duration_sec = dur_sec if i == 0 else None
                    db.add(mdl.CameraEvent(
                        camera_id  = cam_db.id,
                        event_type = "online",
                        started_at = now_utc,
                    ))
                    db.commit()

            ges    = info["area"] or info["nvrName"]
            ip     = info["channelIp"] or info["nvrIp"]
            tg_dur = f"\n⏱ <b>Offline turdi:</b> {fmt_dur(dur_sec)}" if dur_sec else ""
            tg_send(
                f"✅ <b>Camera Online</b>\n"
                f"━━━━━━━━━━━━━━━━━━━\n"
                f"📹 <b>Kamera:</b> {info['name']}\n"
                f"📍 <b>Ges:</b> {ges}\n"
                f"🌐 <b>IP (Kamera):</b> <code>{ip}</code>\n"
                f"⏰ <b>Online vaqti:</b> {ts()}"
                f"{tg_dur}"
            )

        # Update all camera statuses in DB (bulk)
        if db:
            for idx, info in cameras_raw.items():
                cam_db = db.query(mdl.Camera).filter(mdl.Camera.hik_code == idx).first()
                if cam_db and cam_db.current_status != info["status"]:
                    cam_db.current_status    = info["status"]
                    cam_db.last_status_change = now_utc
            db.commit()

        last_poll_time = now_utc

    finally:
        if db:
            db.close()


# ── MAIN LOOP ──────────────────────────────────────────────────────────
def run_forever():
    """Blocking loop — run in a daemon thread."""
    # Initial sync
    db = _new_db()
    if db:
        try:
            sync_nvrs_to_db(db)
        finally:
            db.close()

    refresh_isapi()

    # Seed initial status
    nvrs_raw    = _fetch_nvrs_raw()
    regions_raw = _fetch_regions_raw()
    cameras_raw = _fetch_cameras_raw(nvrs_raw, regions_raw)
    with lock:
        prev_status.update({idx: info["status"] for idx, info in cameras_raw.items()})
        for idx, info in cameras_raw.items():
            if info["status"] == 2:
                offline_since[idx] = datetime.now(timezone.utc)

    db = _new_db()
    if db:
        try:
            import models as mdl
            sync_cameras_to_db(db, nvrs_raw, cameras_raw)
            now_utc = datetime.now(timezone.utc)
            for idx, info in cameras_raw.items():
                cam_db = db.query(mdl.Camera).filter(mdl.Camera.hik_code == idx).first()
                if not cam_db:
                    continue
                cam_db.current_status    = info["status"]
                cam_db.last_status_change = now_utc
                if info["status"] == 2:
                    # Eski duplikat ochiq eventlarni yopib, yagona yangi event qoldiramiz
                    old_evs = (db.query(mdl.CameraEvent)
                               .filter(mdl.CameraEvent.camera_id == cam_db.id,
                                       mdl.CameraEvent.event_type == "offline",
                                       mdl.CameraEvent.ended_at == None)
                               .order_by(mdl.CameraEvent.started_at.asc())
                               .all())
                    if len(old_evs) > 1:
                        # Eng eski N-1 tasini yopamiz, eng yangi bitta qolsin
                        for ev in old_evs[:-1]:
                            ev.ended_at = now_utc
                    elif len(old_evs) == 0:
                        db.add(mdl.CameraEvent(
                            camera_id  = cam_db.id,
                            event_type = "offline",
                            started_at = now_utc,
                        ))
                elif info["status"] != 2:
                    # Online bo'lgan kameralarda ochiq offline event qolgan bo'lsa yopamiz
                    (db.query(mdl.CameraEvent)
                     .filter(mdl.CameraEvent.camera_id == cam_db.id,
                             mdl.CameraEvent.event_type == "offline",
                             mdl.CameraEvent.ended_at == None)
                     .update({"ended_at": now_utc}))
            db.commit()
        finally:
            db.close()

    total_off = sum(1 for s in prev_status.values() if s == 2)
    total_on  = sum(1 for s in prev_status.values() if s == 1)
    tg_send(
        f"✅ <b>HikCentral Monitor ishga tushdi</b>\n"
        f"📹 Jami kamera: <b>{len(cameras_raw)}</b> ta\n"
        f"🟢 Online: <b>{total_on}</b> ta\n"
        f"🔴 Offline: <b>{total_off}</b> ta"
    )

    while True:
        time.sleep(POLL_INTERVAL)
        try:
            _poll_once()
        except Exception:
            pass
