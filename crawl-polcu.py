#!/usr/bin/env python3
"""Crawl polcu.ca and build a knowledge base."""
import urllib.request, urllib.error, json, re, html, time, ssl

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

visited = set()
to_visit = ['https://polcu.ca']
results = {}
pdfs = []
max_pages = 60

def fetch(url):
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 (compatible; bot)'})
        with urllib.request.urlopen(req, timeout=15, context=ctx) as resp:
            ct = resp.headers.get('Content-Type', '')
            if 'pdf' in ct:
                return None, 'pdf'
            data = resp.read().decode('utf-8', errors='replace')
            return data, 'html'
    except Exception as e:
        return None, str(e)

def extract_text(h):
    h2 = re.sub(r'<(script|style)[^>]*>.*?</\1>', '', h, flags=re.S|re.I)
    title = ''
    m = re.search(r'<title[^>]*>(.*?)</title>', h2, re.I|re.S)
    if m:
        title = html.unescape(m.group(1)).strip()
    text = re.sub(r'<[^>]+>', ' ', h2)
    text = html.unescape(text)
    text = re.sub(r'\s+', ' ', text).strip()
    return title, text[:5000]

def extract_links(h, base):
    links = set()
    pattern = re.compile(r'''href=["']([^"']+)["']''', re.I)
    for m in pattern.finditer(h):
        link = m.group(1)
        if link.startswith('#') or link.startswith('mailto:') or link.startswith('tel:') or link.startswith('javascript:'):
            continue
        if link.startswith('/'):
            link = 'https://polcu.ca' + link
        elif not link.startswith('http'):
            link = base.rstrip('/') + '/' + link
        if 'polcu.ca' in link:
            link = link.split('#')[0]
            links.add(link)
    return links

count = 0
while to_visit and count < max_pages:
    url = to_visit.pop(0)
    if url in visited:
        continue
    visited.add(url)
    count += 1
    print(f'[{count}] Crawling: {url}')

    data, dtype = fetch(url)
    if dtype == 'pdf':
        pdfs.append(url)
        print(f'  -> PDF found: {url}')
        continue
    if data is None:
        print(f'  -> Error: {dtype}')
        continue

    title, text = extract_text(data)
    results[url] = {'title': title, 'text': text}
    print(f'  -> Title: {title[:80]}')

    links = extract_links(data, url)
    for link in links:
        if link not in visited and link not in to_visit:
            to_visit.append(link)

    time.sleep(0.5)

with open('polcu-crawl.json', 'w') as f:
    json.dump({'pages': results, 'pdfs': pdfs, 'count': count}, f, indent=2)

print(f'\n=== CRAWL COMPLETE ===')
print(f'Pages crawled: {count}')
print(f'PDFs found: {len(pdfs)}')
for p in pdfs:
    print(f'  PDF: {p}')
print('Saved to polcu-crawl.json')
