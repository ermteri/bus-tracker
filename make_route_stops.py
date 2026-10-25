"""
Prints the ROUTE_STOPS list for bus.php: the stops of line 168 toward Södersjukhuset that come
before (and including) Sköntorp, with names and coordinates from the static timetable.

Run in the busstop folder:   python make_route_stops.py
"""
import csv, io, json, zipfile
from collections import Counter

OUR_STOP = "9022001013119001"
BEFORE = 20                                   # how many stops before Sköntorp to include

z = zipfile.ZipFile("sl_gtfs.zip")


def rows(name):
    with z.open(name) as f:
        yield from csv.DictReader(io.TextIOWrapper(f, "utf-8-sig"))


target = set(json.load(open("bus_cache.json"))["trips"])
stops = {r["stop_id"]: (r["stop_name"], float(r["stop_lat"]), float(r["stop_lon"])) for r in rows("stops.txt")}

seqs = {}
for r in rows("stop_times.txt"):
    if r["trip_id"] in target:
        seqs.setdefault(r["trip_id"], []).append((int(r["stop_sequence"]), r["stop_id"]))

patterns = Counter()
for lst in seqs.values():
    ids = [sid for _, sid in sorted(lst)]
    if OUR_STOP in ids:
        patterns[tuple(ids[max(0, ids.index(OUR_STOP) - BEFORE): ids.index(OUR_STOP) + 1])] += 1

if not patterns:
    raise SystemExit("No trip in bus_cache.json calls at the Sköntorp stop id.")

best, count = patterns.most_common(1)[0]
print(f"{len(seqs)} trips, {len(patterns)} different stop patterns before Sköntorp; using the most common ({count} trips):\n")
for i, sid in enumerate(best):
    name, lat, lon = stops[sid]
    print(f"  {i}. {name:<28} {lat:.5f}, {lon:.5f}   ({sid})")

print("\nPaste this into bus.php (replace the line  const ROUTE_STOPS = [];):\n")
print("const ROUTE_STOPS = [")
for sid in best:
    name, lat, lon = stops[sid]
    print(f"    ['{sid}', '{name.replace(chr(39), chr(92) + chr(39))}', {lat:.5f}, {lon:.5f}],")
print("];")
