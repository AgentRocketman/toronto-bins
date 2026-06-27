#!/usr/bin/env python3
"""Crawl dundalkcu.ca and adjala.ca for POLCU knowledge base."""
import subprocess, json, re, html, time

visited = set()
results = {}
pdfs = []
max_pages_per_site = 40

def fetch(url):
    try:
        r = subprocess.run(['curl', '-sL', '--max-time', '15', '-H', 'User-Agent: Mozilla/5.0'], 
                           input=None, capture_output=True, text=True, timeout=20,
                           args=['curl', '-sL', '--max-time', '15', '-H', 'User-Agent: Mozilla/5.0', url])
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

def extract_links(h, domain):
    links = set()
    pattern = re.compile(r'''href=["']([^"']+)["']''', re.I)
    for m in pattern.finditer(h):
        link = m.group(1)
        if link.startswith('#') or link.startswith('mailto:') or link.startswith('tel:') or 'javascript:' in link:
            continue
        if link.startswith('/'):
            link = f'https://www.{domain}' + link
        elif not link.startswith('http'):
            link = f'https://www.{domain}/' + link
        if domain in link:
            link = link.split('#')[0].split('?')[0]
            if link.endswith('.pdf'):
                pdfs.append((domain, link))
            else:
                links.add(link)
    return links

for domain in ['dundalkcu.ca', 'adjala.ca']:
    print(f'\n{"="*60}')
    print(f'CRAWLING: {domain}')
    print(f'{"="*60}')
    
    to_visit = [f'https://{domain}', f'https://www.{domain}']
    count = 0
    
    while to_visit and count < max_pages_per_site:
        url = to_visit.pop(0)
        if url in visited:
            continue
        visited.add(url)
        count += 1
        print(f'[{count}] {url}')
        
        data, dtype = fetch(url)
        if not data or len(data) < 100:
            print(f'  -> FAIL/empty')
            continue
        
        title, text = extract_text(data)
        if len(text) > 50:
            results[url] = {'title': title, 'text': text, 'domain': domain}
            print(f'  -> {title[:70]} ({len(text)} chars)')
        else:
            print(f'  -> Empty/minimal')
        
        links = extract_links(data, domain)
        for link in links:
            if link not in visited and link not in to_visit:
                to_visit.append(link)
        
        time.sleep(0.3)

with open('branches-crawl.json', 'w') as f:
    json.dump({'pages': results, 'pdfs': pdfs, 'count': len(results)}, f, indent=2)

print(f'\n=== DONE: {len(results)} pages, {len(pdfs)} PDFs ===')
for d, p in pdfs:
    print(f'  [{d}] {p}')
