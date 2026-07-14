#!/usr/bin/env python3
"""
Proxy: Claude Code CLI ↔ OpenRouter
Handles SSE streaming responses + model name translation
"""
import http.server, requests, json, sys, re

with open('/data/.openclaw/openclaw.json') as f:
    API_KEY = json.load(f)['env']['OPENROUTER_API_KEY']

def build_fwd(name):
    """Anthropic → OpenRouter slug"""
    base = re.sub(r'-\d{8,}$', '', name)
    known = {
        'claude-haiku-4-5': 'anthropic/claude-haiku-4-5',
        'claude-sonnet-4-6': 'anthropic/claude-sonnet-4-6',
        'claude-opus-4-7': 'anthropic/claude-opus-4-7',
        'claude-opus-4-8': 'anthropic/claude-opus-4.8',
        'claude-opus-4-5': 'anthropic/claude-opus-4.5',
    }
    if base in known: return known[base]
    m = re.match(r'^claude-([a-z]+)-(\d+)-(\d+(?:\.\d+)?)$', base)
    if m: return f'anthropic/claude-{m.group(1)}-{m.group(2)}.{m.group(3)}'
    return name

REV = {v: k for k, v in {
    'claude-haiku-4-5': 'anthropic/claude-haiku-4-5',
    'claude-sonnet-4-6': 'anthropic/claude-sonnet-4-6',
    'claude-opus-4-7': 'anthropic/claude-opus-4-7',
    'claude-opus-4-8': 'anthropic/claude-opus-4.8',
    'claude-opus-4-5': 'anthropic/claude-opus-4.5',
}.items()}

def rev_model(name):
    if name in REV: return REV[name]
    m = re.match(r'^anthropic/claude-([a-z]+)-(\d+)\.(\d+(?:\.\d+)?)$', name)
    if m: return f'claude-{m.group(1)}-{m.group(2)}-{m.group(3)}'
    return name

class P(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        cl = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(cl) if cl else None
        
        if body:
            data = json.loads(body)
            orig = data['model']
            new = build_fwd(orig)
            data['model'] = new
            body = json.dumps(data).encode()
            print(f'[PROXY] {orig} → {new} stream={self.headers.get("x-stainless-stream","no")}', file=sys.stderr, flush=True)
        
        hdrs = {
            'Authorization': f'Bearer {API_KEY}',
            'Content-Type': 'application/json',
            'anthropic-version': self.headers.get('anthropic-version', '2023-06-01'),
        }
        for k in ['anthropic-beta']:
            if k in self.headers: hdrs[k] = self.headers[k]
        
        try:
            resp = requests.post(
                f'https://openrouter.ai{self.path}',
                data=body, headers=hdrs, timeout=300, stream=True
            )
            
            ct = resp.headers.get('Content-Type', '')
            
            if 'text/event-stream' in ct or 'text/plain' in ct:
                # SSE streaming — pass through with model name rewrites
                self.send_response(resp.status_code)
                self.send_header('Content-Type', 'text/event-stream')
                self.end_headers()
                for chunk in resp.iter_content(chunk_size=4096):
                    if chunk:
                        # Rewrite model names in SSE data lines
                        for or_slug, cc_name in REV.items():
                            chunk = chunk.replace(or_slug.encode(), cc_name.encode())
                        self.wfile.write(chunk)
                print(f'[PROXY] SSE stream done', file=sys.stderr, flush=True)
            else:
                # Plain JSON response
                resp_body = resp.content
                try:
                    data = json.loads(resp_body)
                    data['model'] = rev_model(data['model'])
                    resp_body = json.dumps(data).encode()
                except:
                    pass
                print(f'[PROXY] JSON RESP {resp.status_code} len={len(resp_body)}', file=sys.stderr, flush=True)
                self.send_response(resp.status_code)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(resp_body)
        except Exception as e:
            print(f'[PROXY] ERROR: {e}', file=sys.stderr, flush=True)
            self.send_response(502)
            self.end_headers()
    
    def log_message(self, f, *a): pass

http.server.HTTPServer(('127.0.0.1', 8899), P).serve_forever()