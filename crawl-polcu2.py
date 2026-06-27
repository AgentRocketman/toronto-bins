#!/usr/bin/env python3
"""Crawl www.polcu.com and build a knowledge base."""
import subprocess, json, re, html, time

visited = set()
to_visit = ['https://www.polcu.com/']
results = {}
pdfs = []
max_pages = 60
base_domain = 'polcu.com'

def fetch(url):
    try:
        r = subprocess.run(['curl', '-sL', '--max-time', '15', '-H', 'User-Agent: Mozilla/5.0', url],
                           capture_output=True, text=True, timeout=20)
        return r.stdout, 'html'
    except:
        return None, 'error'

def extract_text(h):
    h2 = re.sub(r'<(script|style|noscript)[^>]*>.*?</\1>', '', h, flags=re.S|re.I)
    title = ''
    m = re.search(r'<title[^>]*>(.*?)</title>', h2, re.I|re.S)
    if m:
        title = html.unescape(m.group(1)).strip()
    text = re.sub(r'<[^>]+>', ' ', h2)
    text = html.unescape(text)
    text = re.sub(r'\s+', ' ', text).strip()
    return title, text[:8000]

def extract_links(h):
    links = set()
    pattern = re.compile(r'href=["\']([^"\']+)["\']', re.I)
    for m in pattern.finditer(h):
        link = m.group(1)
        if link.startswith('#') or link.startswith('mailto:') or link.startswith('tel:') or 'javascript:' in link:
            continue
        if link.startswith('/'):
            link = 'https://www.polcu.com' + link
        elif not link.startswith('http'):
            link = 'https://www.polcu.com/' + link
        if base_domain in link:
            link = link.split('#')[0].split('?')[0]
            if link.endswith('.pdf'):
                pdfs.append(link)
            else:
                links.add(link)
    return links

count = 0
while to_visit and count < max_pages:
    url = to_visit.pop(0)
    if url in visited:
        continue
    visited.add(url)
    count += 1
    print(f'[{count}] {url}')

    data, dtype = fetch(url)
    if not data:
        print(f'  -> FAIL')
        continue

    title, text = extract_text(data)
    if len(text) > 50:
        results[url] = {'title': title, 'text': text}
        print(f'  -> {title[:70]} ({len(text)} chars)')
    else:
        print(f'  -> Empty/minimal content')

    links = extract_links(data)
    for link in links:
        if link not in visited and link not in to_visit:
            to_visit.append(link)

    time.sleep(0.3)

with open('polcu-crawl.json', 'w') as f:
    json.dump({'pages': results, 'pdfs': list(set(pdfs)), 'count': count}, f, indent=2)

print(f'\n=== DONE: {len(results)} pages, {len(set(pdfs))} PDFs ===')
for p in set(pdfs):
    print(f'  PDF: {p}')
