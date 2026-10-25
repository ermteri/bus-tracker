import json, urllib.request

BASE = "https://transport.integration.sl.se/v1"

def get(url):
    with urllib.request.urlopen(url, timeout=20) as r:
        return json.load(r)

for s in get(f"{BASE}/sites?expand=true"):
    if "sköntorp" in s["name"].lower():
        print(f'\n== {s["id"]}  {s["name"]}  {s.get("note", "")}')
        data = get(f'{BASE}/sites/{s["id"]}/departures')
        for d in data.get("departures", []):
            line = d.get("line", {})
            if str(line.get("designation")) == "168":
                print("  168 ->", d.get("destination"),
                      "|", d.get("display"),
                      "| direction:", d.get("direction_code"))
