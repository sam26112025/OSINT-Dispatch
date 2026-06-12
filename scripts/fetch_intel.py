#!/usr/bin/env python3
"""Fetch threat intelligence data from public APIs"""
import urllib.request, urllib.error, json, os, datetime, sys

out = "site/data"
os.makedirs(out, exist_ok=True)

def fetch(url, method="GET", data=None, timeout=30):
    try:
        req = urllib.request.Request(url, 
            data=data.encode() if data else None,
            headers={"User-Agent": "Mozilla/5.0", 
                     "Content-Type": "application/x-www-form-urlencoded"})
        with urllib.request.urlopen(req, timeout=timeout) as r:
            content = r.read().decode("utf-8", errors="replace")
            print(f"  {url[:60]}: HTTP {r.status} ({len(content)} bytes)")
            return content
    except Exception as e:
        print(f"  {url[:60]}: ERROR {e}")
        return None

# URLhaus CSV
print("Fetching URLhaus CSV...")
csv_data = fetch("https://urlhaus.abuse.ch/downloads/csv_recent/", timeout=30)
urls = []
if csv_data:
    for line in csv_data.split("\n"):
        if line.startswith("#") or not line.strip():
            continue
        parts = line.strip().split(",")
        if len(parts) >= 4:
            url = parts[2].strip('"')
            urls.append({
                "id": parts[0].strip('"'),
                "date_added": parts[1].strip('"'),
                "url": url,
                "url_status": parts[3].strip('"'),
                "host": url.split("/")[2] if len(url.split("/")) > 2 else "",
                "tags": []
            })
result = {"query_status": "ok", "urls": urls}
with open(f"{out}/urlhaus.json", "w") as f:
    json.dump(result, f)
print(f"  URLhaus: {len(urls)} URLs saved")

# Feodo
print("Fetching Feodo...")
feodo_data = fetch("https://feodotracker.abuse.ch/downloads/ipblocklist_aggressive.json")
feodo = []
if feodo_data:
    try:
        feodo = json.loads(feodo_data)
        if not isinstance(feodo, list):
            feodo = []
    except:
        pass
with open(f"{out}/feodo.json", "w") as f:
    json.dump(feodo, f)
print(f"  Feodo: {len(feodo)} records saved")

# CISA KEV
print("Fetching CISA KEV...")
kev_data = fetch("https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json", timeout=45)
kev = {"vulnerabilities": []}
if kev_data:
    try:
        kev = json.loads(kev_data)
    except:
        pass
with open(f"{out}/cisa_kev.json", "w") as f:
    json.dump(kev, f)
print(f"  CISA KEV: {len(kev.get('vulnerabilities',[]))} entries saved")

# NVD CVEs
print("Fetching NVD CVEs...")
end = datetime.datetime.utcnow()
start = end - datetime.timedelta(days=30)
nvd_url = f"https://services.nvd.nist.gov/rest/json/cves/2.0?pubStartDate={start.strftime('%Y-%m-%dT%H:%M:%S.000')}&pubEndDate={end.strftime('%Y-%m-%dT%H:%M:%S.000')}&resultsPerPage=100"
nvd_data = fetch(nvd_url, timeout=60)
nvd = {"vulnerabilities": []}
if nvd_data:
    try:
        nvd = json.loads(nvd_data)
    except:
        pass
with open(f"{out}/nvd_cves.json", "w") as f:
    json.dump(nvd, f)
print(f"  NVD: {len(nvd.get('vulnerabilities',[]))} CVEs saved")

# Timestamp
ts = datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
with open(f"{out}/last_updated.json", "w") as f:
    json.dump({"updated": ts}, f)

print(f"Done! Timestamp: {ts}")
import subprocess
subprocess.run(["ls", "-lh", out])
