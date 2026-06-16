#!/usr/bin/env python3
"""Auto-generate sitemap.xml from real /site HTML files.
Excludes noindex pages and known stray files. Preserves existing lastmod."""
import os, re, datetime, sys

SITE=os.path.join(os.path.dirname(__file__),"..","site")
BASE="https://igrs.xyz"
TODAY=datetime.date.today().isoformat()
EXCLUDE={"india-osint-toolkit.html","404.html","offline.html","go"}  # stray/non-index

HIGH={"articles.html","tools.html","dorks.html","dashboard.html","toolkit.html","playbooks.html","cyberlaw.html"}

def indexable(path):
    try: s=open(path,encoding="utf-8",errors="ignore").read()
    except: return False
    if re.search(r'<meta[^>]+name=["\']robots["\'][^>]*noindex',s,re.I): return False
    return True

def url_for(rel):
    rel=rel.replace(os.sep,"/")
    if rel=="index.html": return BASE+"/"
    return BASE+"/"+rel

def collect():
    urls=[]
    for f in sorted(os.listdir(SITE)):
        if f.endswith(".html") and f not in EXCLUDE:
            p=os.path.join(SITE,f)
            if indexable(p): urls.append(("index.html" if f=="index.html" else f))
    adir=os.path.join(SITE,"articles")
    for f in sorted(os.listdir(adir)):
        if f.endswith(".html") and f not in EXCLUDE:
            if indexable(os.path.join(adir,f)): urls.append("articles/"+f)
    return urls

def existing_lastmod():
    m={}
    sm=os.path.join(SITE,"sitemap.xml")
    if os.path.exists(sm):
        t=open(sm).read()
        for blk in re.findall(r'<url>(.*?)</url>',t,re.S):
            loc=re.search(r'<loc>(.*?)</loc>',blk); lm=re.search(r'<lastmod>(.*?)</lastmod>',blk)
            if loc and lm: m[loc.group(1).strip()]=lm.group(1).strip()
    return m

def main():
    rels=collect(); prev=existing_lastmod(); out=[]
    out.append('<?xml version="1.0" encoding="UTF-8"?>')
    out.append('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
    for rel in rels:
        u=url_for(rel); lm=prev.get(u,TODAY)
        if rel=="index.html": pr,cf="1.0","daily"
        elif rel.startswith("articles/"): pr,cf="0.8","weekly"
        elif rel in HIGH: pr,cf="0.9","weekly"
        else: pr,cf="0.7","monthly"
        out.append(f"  <url>\n    <loc>{u}</loc>\n    <lastmod>{lm}</lastmod>\n    <changefreq>{cf}</changefreq>\n    <priority>{pr}</priority>\n  </url>")
    out.append('</urlset>\n')
    new="\n".join(out)
    if "--write" in sys.argv:
        open(os.path.join(SITE,"sitemap.xml"),"w").write(new); print("WROTE",len(rels),"urls")
    else:
        # dry-run: print diff vs existing
        newset={url_for(r) for r in rels}; oldset=set(prev)
        print("new total:",len(newset)," old total:",len(oldset))
        print("ADDED:", sorted(newset-oldset))
        print("REMOVED:", sorted(oldset-newset))

if __name__=="__main__": main()
