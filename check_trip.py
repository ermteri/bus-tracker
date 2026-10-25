"""
Looks up trips in the static SL timetable (sl_gtfs.zip) and prints the LAST_STOP_IDS line for bus.php.
Run in the busstop folder:   python check_trip.py [trip_id ...]
Default trip: 14010000735755616
"""
import csv, io, json, sys, zipfile
from collections import Counter

TRIPS = sys.argv[1:] or ["14010000735755616"]
z = zipfile.ZipFile("sl_gtfs.zip")


def rows(name):
    with z.open(name) as f:
        yield from csv.DictReader(io.TextIOWrapper(f, "utf-8-sig"))


target = set(json.load(open("bus_cache.json"))["trips"])
names = {r["stop_id"]: r["stop_name"] for r in rows("stops.txt")}
routes = {r["route_id"]: r.get("route_short_name") for r in rows("routes.txt")}
trip_rows = {r["trip_id"]: r for r in rows("trips.txt") if r["trip_id"] in TRIPS}

calls = {t: [] for t in TRIPS}          # all stops of the trips we were asked about
last = {}                               # trip -> (max sequence, stop_id) for the right trips
for r in rows("stop_times.txt"):
    t, seq = r["trip_id"], int(r["stop_sequence"])
    if t in calls:
        calls[t].append((seq, r["arrival_time"], r["stop_id"]))
    if t in target and (t not in last or seq > last[t][0]):
        last[t] = (seq, r["stop_id"])

for t in TRIPS:
    print(f"\n=== trip {t}")
    print("in your 168 -> Södersjukhuset list:", t in target)
    tr = trip_rows.get(t)
    if not tr:
        print("not found in the static timetable")
        continue
    print("line:", routes.get(tr["route_id"]), "| direction_id:", tr.get("direction_id"), "| headsign:", tr.get("trip_headsign"))
    seq = sorted(calls[t])
    print("first:", seq[0][1], names.get(seq[0][2]), f"({seq[0][2]})")
    for _, arr, sid in seq:
        if names.get(sid, "").strip().lower().startswith("sköntorp") and "plan" not in names[sid].lower():
            print("calls at", names[sid], f"({sid})", "scheduled", arr)
    print("last :", seq[-1][1], names.get(seq[-1][2]), f"({seq[-1][2]})")

counts = Counter(sid for _, sid in last.values())
print("\n=== End stops of your", len(last), "trips toward Södersjukhuset")
for sid, n in counts.most_common():
    print(f"  {sid}  {names.get(sid)}  ({n} trips)")
print("\nPaste this into bus.php:")
print("const LAST_STOP_IDS = [" + ", ".join(f"'{sid}'" for sid in counts) + "];")
