#!/usr/bin/env python3
"""Generate /site/feed.xml (RSS 2.0) from the article HTML files.
Reads canonical URL, title, description and datePublished from each article,
sorts newest-first, emits the latest 30. Run before deploy when articles change."""
import glob, re, os, html
from datetime import datetime

SITE = "https://igrs.xyz"
ROOT = os.path.join(os.path.dirname(__file__), "..", "site")
MONTHS = ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"]

def field(pat, s, default=""):
    m = re.search(pat, s, re.S)
    return m.group(1).strip() if m else default

items = []
for f in glob.glob(os.path.join(ROOT, "articles", "*.html")):
    s = open(f, encoding="utf-8").read()
    if re.search(r'<meta[^>]+name="robots"[^>]+noindex', s, re.I):
        continue
    canon = field(r'rel="canonical" href="([^"]+)"', s)
    url = canon or f"{SITE}/articles/{os.path.basename(f)}"
    title = field(r'property="og:title" content="([^"]+)"', s) or field(r"<title>(.*?)</title>", s)
    title = re.sub(r"\s*[—\-|]\s*IGRS.*$", "", title).strip()
    desc = field(r'name="description" content="([^"]+)"', s)
    title = html.unescape(title); desc = html.unescape(desc)
    date = field(r'"datePublished":"([0-9]{4}-[0-9]{2}-[0-9]{2})"', s) or "2026-01-01"
    items.append((date, url, title, desc, os.path.basename(f)))

items.sort(reverse=True)            # newest first by datePublished
items = items[:30]

def rfc822(d):
    dt = datetime.strptime(d, "%Y-%m-%d")
    return f"{MONTHS[dt.weekday()]}, {dt.day:02d} {dt.strftime('%b %Y')} 09:00:00 +0530"

now = datetime.utcnow().strftime("%a, %d %b %Y %H:%M:%S +0000")
parts = ['<?xml version="1.0" encoding="UTF-8"?>',
'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">', '<channel>',
'<title>IGRS — Intel Grid Research Station</title>',
f'<link>{SITE}/</link>',
'<description>Cybersecurity, OSINT and digital-forensics intelligence for India\'s frontline defenders — threat intel, investigation playbooks and free tools.</description>',
'<language>en-in</language>',
f'<lastBuildDate>{now}</lastBuildDate>',
f'<atom:link href="{SITE}/feed.xml" rel="self" type="application/rss+xml"/>']
for date, url, title, desc, fn in items:
    parts += ['<item>',
        f'<title>{html.escape(title)}</title>',
        f'<link>{url}</link>',
        f'<guid isPermaLink="true">{url}</guid>',
        f'<pubDate>{rfc822(date)}</pubDate>',
        f'<description>{html.escape(desc)}</description>',
        '</item>']
parts += ['</channel>', '</rss>']
open(os.path.join(ROOT, "feed.xml"), "w", encoding="utf-8").write("\n".join(parts))
print(f"WROTE feed.xml with {len(items)} items (newest: {items[0][2][:50]})")
