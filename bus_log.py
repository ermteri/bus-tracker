#!/usr/bin/env python3
"""
Compares two sources for bus 168 at Sköntorp (toward Södersjukhuset):
  api              = SL Transport API departures (what we tested first)
  gtfs_tripupdate  = GTFS-Realtime TripUpdates (predicted arrival at the stop)
  gtfs_vehicle     = GTFS-Realtime VehiclePositions (GPS of the approaching bus)
You press "mark" when the bus really arrives; "report" shows how far off each source was.

Setup (Mac, pip only):
  python3 -m pip install --user gtfs-realtime-bindings
  export TRAFIKLAB_STATIC_KEY="..."   # key for GTFS Regional Static data (needed once, for prepare)
  export TRAFIKLAB_RT_KEY="..."       # key for GTFS Regional Realtime data (run)
Commands:
  python3 bus_log.py prepare   # one time: downloads static SL GTFS, finds stop + trips
  python3 bus_log.py run [sec] # log every 30 s (or [sec]); run only ~20 min before the bus (Ctrl+C to stop)
  python3 bus_log.py mark      # run in a 2nd terminal the moment the bus arrives
  python3 bus_log.py report    # prediction error 10/5/2 min before each "mark"
"""
import csv, gzip, io, json, os, sys, time, unicodedata, urllib.request, zipfile
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

_OLD = os.environ.get("TRAFIKLAB_KEY", "")
STATIC_KEY = os.environ.get("TRAFIKLAB_STATIC_KEY", _OLD)   # key for GTFS Regional Static data
RT_KEY = os.environ.get("TRAFIKLAB_RT_KEY", _OLD)           # key for GTFS Regional Realtime data
SITE_ID, DIRECTION = 1512, 2
LINE, HEADSIGN, STOP_NAME = "168", "södersjukhuset", "sköntorp"
INTERVAL = 30
TZ = ZoneInfo("Europe/Stockholm")
HERE = os.path.dirname(os.path.abspath(__file__))
ZIP, CACHE, LOG = (os.path.join(HERE, n) for n in ("sl_gtfs.zip", "bus_cache.json", "bus_log.csv"))
API = f"https://transport.integration.sl.se/v1/sites/{SITE_ID}/departures?direction={DIRECTION}"
STATIC = f"https://opendata.samtrafiken.se/gtfs/sl/sl.zip?key={STATIC_KEY}"
RT = "https://opendata.samtrafiken.se/gtfs-rt/sl/{}.pb?key=" + RT_KEY


HEADERS = {"User-Agent": "Mozilla/5.0 bus-log/1.0", "Accept": "*/*", "Accept-Encoding": "gzip"}


def open_url(url):
    return urllib.request.urlopen(urllib.request.Request(url, headers=HEADERS), timeout=120)


def fetch(url):
    with open_url(url) as r:
        data = r.read()
        return gzip.decompress(data) if r.headers.get("Content-Encoding") == "gzip" else data


def download(url, path):
    with open_url(url) as r, open(path, "wb") as f:
        src = gzip.GzipFile(fileobj=r) if r.headers.get("Content-Encoding") == "gzip" else r
        while True:
            chunk = src.read(1 << 20)
            if not chunk:
                break
            f.write(chunk)


def norm(s):
    return unicodedata.normalize("NFC", s or "").strip().lower()


def is_skontorp(name):
    n = norm(name)
    return n == STOP_NAME or n.startswith(STOP_NAME + " (")


def prepare():
    if not os.path.exists(ZIP):
        if not STATIC_KEY:
            sys.exit("Set TRAFIKLAB_STATIC_KEY first (needed once, to download the timetable).")
        print("Downloading static SL GTFS (one time, can take a minute)...")
        download(STATIC, ZIP)
    z = zipfile.ZipFile(ZIP)

    def rows(name):
        with z.open(name) as f:
            yield from csv.DictReader(io.TextIOWrapper(f, "utf-8-sig"))

    routes = {r["route_id"] for r in rows("routes.txt") if norm(r.get("route_short_name")) == LINE}
    route_trips = {r["trip_id"] for r in rows("trips.txt") if r["route_id"] in routes}
    names = {r["stop_id"]: norm(r["stop_name"]) for r in rows("stops.txt")}
    seqs = {}
    for r in rows("stop_times.txt"):
        if r["trip_id"] in route_trips:
            seqs.setdefault(r["trip_id"], []).append((int(r["stop_sequence"]), r["stop_id"]))

    # Direction is decided by the trip itself: it must call at Sköntorp and later at Södersjukhuset.
    trips, stops = set(), set()
    for tid, lst in seqs.items():
        lst.sort()
        sk = [i for i, (_, sid) in enumerate(lst) if is_skontorp(names.get(sid, ""))]
        sj = [i for i, (_, sid) in enumerate(lst) if HEADSIGN in names.get(sid, "")]
        if sk and sj and sk[0] < sj[-1]:
            trips.add(tid)
            stops.add(lst[sk[0]][1])

    print(f"routes={len(routes)} all_{LINE}_trips={len(route_trips)} target_trips={len(trips)} stop_ids={sorted(stops)}")
    print("Stop names containing 'sköntorp':", sorted({n for n in names.values() if STOP_NAME in n}))
    print("Stop names containing 'södersjukhuset':", sorted({n for n in names.values() if HEADSIGN in n}))
    if not (routes and trips and stops):
        sys.exit("Could not find the trips. Paste the three lines above to Claude.")
    json.dump({"routes": sorted(routes), "trips": sorted(trips), "stops": sorted(stops)}, open(CACHE, "w"))
    print("Saved", CACHE)


def load():
    if not os.path.exists(CACHE):
        prepare()
    c = json.load(open(CACHE))
    return set(c["routes"]), set(c["trips"]), set(c["stops"])


def poll(routes, trips, stops):
    from google.transit import gtfs_realtime_pb2 as pb
    now = datetime.now(TZ)
    out = []  # (source, trip_id, predicted datetime or None, extra)

    try:
        for d in json.loads(fetch(API)).get("departures", []):
            if str(d.get("line", {}).get("designation")) != LINE:
                continue
            if d.get("direction_code") not in (None, DIRECTION):
                continue
            t = d.get("expected") or d.get("scheduled")
            if t:
                dt = datetime.fromisoformat(t)
                out.append(("api", "", dt if dt.tzinfo else dt.replace(tzinfo=TZ), d.get("display", "")))
    except Exception as e:
        print("api error:", e)

    def ours(trip):
        return trip.route_id in routes if trip.route_id else trip.trip_id in trips

    wanted = set()
    try:
        feed = pb.FeedMessage()
        feed.ParseFromString(fetch(RT.format("TripUpdates")))
        for ent in feed.entity:
            if not ent.HasField("trip_update") or not ours(ent.trip_update.trip):
                continue
            for stu in ent.trip_update.stop_time_update:
                t = stu.arrival.time or stu.departure.time
                if stu.stop_id in stops and t:
                    tid = ent.trip_update.trip.trip_id
                    wanted.add(tid)
                    out.append(("gtfs_tripupdate", tid, datetime.fromtimestamp(t, TZ),
                                f"delay={stu.arrival.delay}s"))
    except Exception as e:
        print("tripupdates error:", e)

    try:
        feed = pb.FeedMessage()
        feed.ParseFromString(fetch(RT.format("VehiclePositions")))
        for ent in feed.entity:
            if ent.HasField("vehicle") and ent.vehicle.trip.trip_id in wanted:
                p = ent.vehicle.position
                out.append(("gtfs_vehicle", ent.vehicle.trip.trip_id, None, f"{p.latitude:.5f},{p.longitude:.5f}"))
    except Exception as e:
        print("vehiclepositions error:", e)

    new = not os.path.exists(LOG)
    with open(LOG, "a", newline="") as f:
        w = csv.writer(f)
        if new:
            w.writerow(["logged_at", "source", "trip_id", "predicted_at", "eta_min", "extra"])
        for src, tid, dt, extra in out:
            eta = round((dt - now).total_seconds() / 60, 1) if dt else ""
            w.writerow([now.isoformat(timespec="seconds"), src, tid,
                        dt.isoformat(timespec="seconds") if dt else "", eta, extra])

    summary = {}
    for src, _, dt, _ in out:
        if dt and (dt - now).total_seconds() > -60:
            m = (dt - now).total_seconds() / 60
            summary[src] = min(summary.get(src, 999), m)
    print(now.strftime("%H:%M:%S"), " | ".join(f"{k}: {v:.1f} min" for k, v in summary.items()) or "no predictions")


def run():
    if not RT_KEY:
        sys.exit("Set TRAFIKLAB_RT_KEY first.")
    r, t, s = load()
    interval = int(sys.argv[2]) if len(sys.argv) > 2 else INTERVAL
    print(f"Logging to {LOG} every {interval}s (2 Trafiklab calls per round). Ctrl+C to stop.")
    while True:
        try:
            poll(r, t, s)
        except Exception as e:
            print("poll error:", e)
        time.sleep(interval)


def mark():
    new = not os.path.exists(LOG)
    now = datetime.now(TZ)
    with open(LOG, "a", newline="") as f:
        w = csv.writer(f)
        if new:
            w.writerow(["logged_at", "source", "trip_id", "predicted_at", "eta_min", "extra"])
        w.writerow([now.isoformat(timespec="seconds"), "actual", "", now.isoformat(timespec="seconds"), 0, "bus arrived"])
    print("Marked arrival at", now.strftime("%H:%M:%S"))


def report():
    rows = list(csv.DictReader(open(LOG)))
    actual = [datetime.fromisoformat(r["logged_at"]) for r in rows if r["source"] == "actual"]
    if not actual:
        sys.exit("No 'mark' entries yet.")
    for T in actual:
        print(f"\nBus arrived {T.strftime('%Y-%m-%d %H:%M:%S')}  (error = predicted - actual; + means bus came earlier than predicted)")
        for src in ("api", "gtfs_tripupdate"):
            line = []
            for lb in (10, 5, 2):
                target = T - timedelta(minutes=lb)
                cand = [r for r in rows if r["source"] == src and r["predicted_at"]
                        and abs((datetime.fromisoformat(r["logged_at"]) - target).total_seconds()) < 45]
                if not cand:
                    line.append(f"{lb} min before: n/a")
                    continue
                best = min(cand, key=lambda r: abs((datetime.fromisoformat(r["predicted_at"]) - T).total_seconds()))
                err = (datetime.fromisoformat(best["predicted_at"]) - T).total_seconds()
                line.append(f"{lb} min before: {err:+.0f}s")
            print(f"  {src:16s}", " | ".join(line))


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    {"prepare": prepare, "run": run, "mark": mark, "report": report}.get(cmd, lambda: print(__doc__))()
