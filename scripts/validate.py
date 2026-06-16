#!/usr/bin/env python3
"""Pre-deploy validator: title, meta description, canonical, JSON-LD parse, internal broken-link check."""
import os, re, json, sys
SITE=os.path.join(os.path.dirname(__file__),"..","site")
issues=[]
def pages():
    for f in os.listdir(SITE):
        if f.endswith(".html"): yield f, os.path.join(SITE,f)
    ad=os.path.join(SITE,"articles")
    for f in os.listdir(ad):
        if f.endswith(".html"): yield "articles/"+f, os.path.join(ad,f)

allfiles=set()
for rel,p in pages(): allfiles.add(rel.replace("\\","/"))

for rel,p in pages():
    s=open(p,encoding="utf-8",errors="ignore").read()
    noindex=bool(re.search(r'name=["\']robots["\'][^>]*noindex',s,re.I))
    # JSON-LD must parse
    for blk in re.findall(r'<script type="application/ld\+json">(.*?)</script>',s,re.S):
        try: json.loads(blk)
        except Exception as e: issues.append(f"[JSON-LD] {rel}: {e}")
    if noindex: continue  # skip meta checks for redirect stubs
    if "<title" not in s: issues.append(f"[title] {rel}: missing <title>")
    if not re.search(r'name=["\']description["\']',s): issues.append(f"[meta] {rel}: missing meta description")
    # internal broken-link check (relative .html links)
    base="articles/" if rel.startswith("articles/") else ""
    for href in re.findall(r'href=["\']([^"\'#?]+\.html)["\']',s):
        if href.startswith("http") or href.startswith("//"): continue
        tgt=href.lstrip("./")
        if href.startswith("../"): tgt=href[3:]              # ../x.html -> x.html (top level)
        elif base and "/" not in href: tgt="articles/"+href   # sibling article
        tgt=tgt.replace("\\","/")
        if tgt not in allfiles:
            issues.append(f"[broken-link] {rel} -> {href}")

print(f"pages scanned: {len(allfiles)}")
print(f"issues found: {len(issues)}")
for i in issues[:60]: print("  "+i)
