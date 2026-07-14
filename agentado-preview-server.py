#!/usr/bin/env python3
"""
Agentado Video Preview/Generate Server — runs in Docker container
Called by Hostinger PHP as fallback when exec() is unavailable

Returns video directly as base64 in JSON response (avoids Cloudflare-blocked GET requests)

Modes:
  mode=preview (default): Runs generate_preview.py — watermarked clips, max 3 photos
  mode=generate: Runs generate_overlays.py → generate_kenburns.py or generate_ai_walkthrough.py
"""
import http.server
import json
import os
import subprocess
import tempfile
import shutil
import urllib.request
import urllib.parse
import uuid
import base64
import ssl

# ── Photo download helper ────────────────────────────────────────────
def download_photo(url, dest, timeout=15):
    req = urllib.request.Request(url, headers={
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8'
    })
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            data = resp.read()
            if len(data) > 1024:
                with open(dest, 'wb') as f:
                    f.write(data)
                return True
    except Exception as e:
        print(f"Download failed: {url[:100]} → {e}", flush=True)
    return False

# ── Paths ─────────────────────────────────────────────────────────────
AGENTADO_DIR = '/data/.openclaw/workspace/public_html/agentado'
PREVIEW_SCRIPT = os.path.join(AGENTADO_DIR, 'api/video/generate_preview.py')
OVERLAY_SCRIPT = os.path.join(AGENTADO_DIR, 'api/video/generate_overlays.py')
KENBURNS_SCRIPT = os.path.join(AGENTADO_DIR, 'api/video/generate_kenburns.py')
AI_WALKTHROUGH_SCRIPT = os.path.join(AGENTADO_DIR, 'api/video/generate_ai_walkthrough.py')
PREVIEW_DIR = os.path.join(AGENTADO_DIR, 'output/previews')
VIDEO_DIR = os.path.join(AGENTADO_DIR, 'output/videos')
for d in (PREVIEW_DIR, VIDEO_DIR):
    os.makedirs(d, exist_ok=True)


class Handler(http.server.BaseHTTPRequestHandler):

    # ── GET: health + direct file serving ──────────────────────────
    def do_GET(self):
        if '/dl/' in self.path:
            filename = os.path.basename(self.path.split('?')[0])
            for base in (PREVIEW_DIR, VIDEO_DIR):
                fp = os.path.join(base, filename + '.mp4')
                if os.path.exists(fp):
                    self._sendfile(fp, 'video/mp4')
                    return
            self._text(404, 'Not found')
            return
        if self.path in ('/', '/health'):
            self._json(200, {'ok': True, 'status': 'live'})
            return
        self._text(404, 'Not found')

    def do_OPTIONS(self):
        self._cors_headers()
        self.send_response(200)
        self.end_headers()

    # ── POST: main pipeline ────────────────────────────────────────
    def do_POST(self):
        try:
            parts = self._parse_body()

            mode = parts.get('mode', 'preview')
            if mode == 'generate':
                self._handle_generate(parts)
            else:
                self._handle_preview(parts)

        except Exception as e:
            import traceback
            traceback.print_exc()
            self._json(500, {'error': str(e)})

    # ── PREVIEW MODE ────────────────────────────────────────────────
    def _handle_preview(self, parts):
        listing_data = json.loads(parts.get('listingData', '{}'))
        preview_photos = json.loads(parts.get('previewPhotos', '[]'))

        if not preview_photos:
            self._json(400, {'error': 'No photos provided'})
            return

        job_id = f"prev_{uuid.uuid4().hex[:16]}"
        work_dir = tempfile.mkdtemp(prefix=f'{job_id}_')
        out_file = os.path.join(PREVIEW_DIR, f'{job_id}.mp4')

        # Download photos (max 3 for preview)
        photo_paths = []
        for i, p in enumerate(preview_photos[:3]):
            url = p.get('url', '')
            if not url:
                continue
            dest = os.path.join(work_dir, f'p{i}.jpg')
            if download_photo(url, dest):
                photo_paths.append(dest)
            elif os.path.exists(dest):
                os.unlink(dest)

        if not photo_paths:
            shutil.rmtree(work_dir, ignore_errors=True)
            self._json(400, {'error': 'Could not download any photos from provided URLs'})
            return

        result = subprocess.run(
            ['python3', PREVIEW_SCRIPT, work_dir, out_file, json.dumps(listing_data)],
            capture_output=True, text=True, timeout=120
        )

        shutil.rmtree(work_dir, ignore_errors=True)

        if result.returncode != 0 or not os.path.exists(out_file):
            self._json(500, {
                'error': 'Preview generation failed',
                'rc': result.returncode,
                'output': result.stderr or result.stdout
            })
            return

        # Return video as base64
        video_bytes = open(out_file, 'rb').read()
        self._json(200, {
            'videoData': base64.b64encode(video_bytes).decode('ascii'),
            'videoSize': len(video_bytes),
            'jobId': job_id
        })

    # ── GENERATE MODE ───────────────────────────────────────────────
    def _handle_generate(self, parts):
        listing_data = json.loads(parts.get('listingData', '{}'))
        tier = parts.get('tier', 'kenburns')
        photo_order = json.loads(parts.get('photoOrder', '[]'))
        photo_count = int(parts.get('photoCount', 0))

        if not photo_order:
            self._json(400, {'error': 'No photos provided'})
            return

        is_ai = (tier == 'ai')
        job_id = f"gen_{uuid.uuid4().hex[:16]}"
        work_dir = tempfile.mkdtemp(prefix=f'{job_id}_')
        out_file = os.path.join(VIDEO_DIR, f'{job_id}.mp4')

        # Download all photos
        photo_paths = []
        for p in photo_order:
            url = p.get('url', '')
            if not url:
                continue
            dest = os.path.join(work_dir, f"p{p['index']}.jpg")
            if download_photo(url, dest):
                photo_paths.append(dest)
            elif os.path.exists(dest):
                os.unlink(dest)

        if not photo_paths:
            shutil.rmtree(work_dir, ignore_errors=True)
            self._json(400, {'error': 'Could not download any photos'})
            return

        # Step 1: Generate overlay PNGs
        overlay_rc = subprocess.run(
            ['python3', OVERLAY_SCRIPT, work_dir, json.dumps(listing_data)],
            capture_output=True, text=True, timeout=60
        )

        intro_png = os.path.join(work_dir, 'intro_overlay.png')
        bottom_png = os.path.join(work_dir, 'bottom_bar.png')
        end_png = os.path.join(work_dir, 'end_card.png')

        # Step 2: Generate video
        if is_ai:
            video_rc = subprocess.run(
                ['python3', AI_WALKTHROUGH_SCRIPT, work_dir, out_file,
                 json.dumps(photo_paths), json.dumps(listing_data), tier],
                capture_output=True, text=True, timeout=3600
            )
        else:
            video_rc = subprocess.run(
                ['python3', KENBURNS_SCRIPT, work_dir, out_file,
                 json.dumps(photo_paths), json.dumps(listing_data), tier],
                capture_output=True, text=True, timeout=600
            )

        shutil.rmtree(work_dir, ignore_errors=True)

        if video_rc.returncode != 0 or not os.path.exists(out_file):
            self._json(500, {
                'error': 'Video generation failed',
                'rc': video_rc.returncode,
                'output': video_rc.stderr or video_rc.stdout
            })
            return

        video_bytes = open(out_file, 'rb').read()
        self._json(200, {
            'videoData': base64.b64encode(video_bytes).decode('ascii'),
            'videoSize': len(video_bytes),
            'jobId': job_id
        })

    # ── Helpers ─────────────────────────────────────────────────────
    def _parse_body(self):
        ctype = self.headers.get('Content-Type', '')
        length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(length)

        if 'multipart/form-data' in ctype:
            boundary = ctype.split('boundary=')[1].strip()
            return self._parse_multipart(body, boundary.encode())
        elif 'application/x-www-form-urlencoded' in ctype:
            parts = urllib.parse.parse_qs(body.decode())
            return {k: v[0] for k, v in parts.items()}
        elif 'application/json' in ctype:
            return json.loads(body.decode())
        else:
            parts = urllib.parse.parse_qs(body.decode())
            return {k: v[0] for k, v in parts.items()}

    def _parse_multipart(self, body, boundary):
        parts = {}
        for section in body.split(b'--' + boundary):
            if b'\r\n\r\n' not in section:
                continue
            header_part, content = section.split(b'\r\n\r\n', 1)
            content = content.rstrip(b'\r\n--')
            header_text = header_part.decode(errors='replace')
            for line in header_text.split('\r\n'):
                if 'name=' in line:
                    name = line.split('name="')[1].split('"')[0] if 'name="' in line else ''
                    if name:
                        parts[name] = content.decode(errors='replace')
                    break
        return parts

    def _json(self, code, data):
        self.send_response(code)
        self._cors_headers()
        self.end_headers()
        self.wfile.write(json.dumps(data).encode())

    def _sendfile(self, path, mime):
        self.send_response(200)
        self.send_header('Content-Type', mime)
        self.send_header('Content-Length', str(os.path.getsize(path)))
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Cache-Control', 'public, max-age=3600')
        self.end_headers()
        with open(path, 'rb') as f:
            self.wfile.write(f.read())

    def _text(self, code, text):
        self.send_response(code)
        self.end_headers()
        self.wfile.write(text.encode())

    def _cors_headers(self):
        self.send_header('Content-Type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')

    def log_message(self, format, *args):
        print(f"[agentado-server] {format % args}", flush=True)


if __name__ == '__main__':
    port = 18900
    print(f"Agentado server on :{port}", flush=True)
    http.server.HTTPServer(('127.0.0.1', port), Handler).serve_forever()