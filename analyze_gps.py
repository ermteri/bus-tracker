"""
Does the bus's GPS position predict its arrival? Uses what bus_log.py already recorded.

For every "mark" in bus_log.csv it finds the bus that arrived, then prints how far from the
Sköntorp stop (straight line, in metres) that bus was 10, 8, 6, 5, 4, 3, 2, 1.5, 1 and 0.5
minutes before it arrived. If the distances look similar from bus to bus, position can be turned
into a good arrival estimate.

Run in the busstop folder:   python analyze_gps.py
"""
import csv, io, math, zipfile
from datetime import datetime, timedelta

STOP_ID = "9022001013119001"
LOG, ZIP = "bus_log.csv", "sl_gtfs.zip"
TARGETS = [10, 8, 6, 5, 4, 3, 2, 1.5, 1, 0.5]          # minutes before arrival


def haversine(lat1, lon1, lat2, lon2):
    r = 6371000
    p1, p2 = math.radians(lat1), math.radians(lat2)
    a = math.sin((p2 - p1) / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(math.radians(lon2 - lon1) / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))


z = zipfile.ZipFile(ZIP)
stop = None
with z.open("stops.txt") as f:
    for r in csv.DictReader(io.TextIOWrapper(f, "utf-8-sig")):
        if r["stop_id"] == STOP_ID:
            stop = (float(r["stop_lat"]), float(r["stop_lon"]), r["stop_name"])
if not stop:
    raise SystemExit(f"Stop {STOP_ID} not found in {ZIP}")
print(f"Stop {STOP_ID} = {stop[2]} at {stop[0]:.5f}, {stop[1]:.5f}\n")

rows = list(csv.DictReader(open(LOG)))
for r in rows:
    r["t"] = datetime.fromisoformat(r["logged_at"])
marks = [r["t"] for r in rows if r["source"] == "actual"]
if not marks:
    raise SystemExit("No marks in the log yet.")

for T in marks:
    print(f"=== Bus arrived {T:%Y-%m-%d %H:%M:%S}")
    # which trip was it? the one whose predicted arrival (logged shortly before) is closest to the mark
    cand = [r for r in rows if r["source"] == "gtfs_tripupdate" and r["predicted_at"]
            and T - timedelta(minutes=6) <= r["t"] <= T]
    if not cand:
        print("  no live prediction logged in the 6 minutes before this mark - skipping\n")
        continue
    best = min(cand, key=lambda r: abs((datetime.fromisoformat(r["predicted_at"]) - T).total_seconds()))
    trip = best["trip_id"]
    veh = []
    for r in rows:
        if r["source"] == "gtfs_vehicle" and r["trip_id"] == trip and T - timedelta(minutes=16) <= r["t"] <= T + timedelta(seconds=60):
            lat, lon = (float(x) for x in r["extra"].split(","))
            veh.append(((T - r["t"]).total_seconds() / 60, haversine(lat, lon, stop[0], stop[1])))
    if not veh:
        print(f"  trip {trip}: no GPS rows logged\n")
        continue
    print(f"  trip {trip}")
    print("  min before | metres from stop")
    for tgt in TARGETS:
        m, d = min(veh, key=lambda v: abs(v[0] - tgt))
        if abs(m - tgt) <= 0.4:
            print(f"  {tgt:>9} | {d:7.0f}")
        else:
            print(f"  {tgt:>9} |     n/a")
    print()
