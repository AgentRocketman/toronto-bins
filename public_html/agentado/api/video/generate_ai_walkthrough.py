#!/usr/bin/env python3
"""
Generate AI Walkthrough video — Kling 2.1 cinematic clips + compositing
Usage: python3 generate_ai_walkthrough.py <work_dir> <output.mp4> <photo_paths_json> <listing_json>
"""
import sys, os, json, subprocess, time, base64, requests

work_dir = sys.argv[1]
out_file = sys.argv[2]
photos = json.loads(sys.argv[3])
listing = json.loads(sys.argv[4])

W, H = 1280, 720
FADE_S = 0.8
FPS = 24

# Get Together AI key from config
config_path = '/data/.openclaw/openclaw.json'
with open(config_path) as f:
    cfg = json.load(f)
TOGETHER_KEY = cfg['env'].get('TOGETHER_API_KEY', '')

def generate_kling_clip(image_path, index):
    """Generate a Kling 2.1 cinematic clip from a single photo"""
    with open(image_path, 'rb') as f:
        img_b64 = base64.b64encode(f.read()).decode()

    headers = {
        'Authorization': f'Bearer {TOGETHER_KEY}',
        'Content-Type': 'application/json',
    }
    payload = {
        'model': 'kwaivgI/kling-2.1-standard',
        'seconds': '5',
        'frame_images': [{'input_image': img_b64, 'frame': 0}],
    }

    print(f"  Generating clip {index} via Kling 2.1...", file=sys.stderr)

    resp = requests.post(
        'https://api.together.xyz/v1/videos',
        headers=headers,
        json=payload,
        timeout=120
    )

    if resp.status_code >= 400:
        error = resp.json() if resp.text else str(resp.status_code)
        print(f"  Kling clip {index} error: {error}", file=sys.stderr)
        return None

    data = resp.json()
    clip_url = data.get('url') or data.get('video_url')

    if not clip_url and 'id' in data:
        # Poll for completion
        video_id = data['id']
        for attempt in range(30):
            time.sleep(2)
            poll = requests.get(
                f'https://api.together.xyz/v1/videos/{video_id}',
                headers=headers,
                timeout=15
            )
            if poll.status_code == 200:
                pd = poll.json()
                if pd.get('status') == 'completed':
                    clip_url = pd.get('url') or pd.get('video_url')
                    break
        if not clip_url:
            print(f"  Kling clip {index} timed out", file=sys.stderr)
            return None

    # Download clip
    if clip_url:
        clip_resp = requests.get(clip_url, timeout=30)
        clip_path = os.path.join(work_dir, f'kling_{index}.mp4')
        with open(clip_path, 'wb') as f:
            f.write(clip_resp.content)
        print(f"  Clip {index} saved ({len(clip_resp.content)/1024:.0f} KB)", file=sys.stderr)
        return clip_path

    return None

# ═══════════════════════ Generate Kling 2.1 clips ═══════════════════════
print(f"Generating {len(photos)} Kling 2.1 clips...", file=sys.stderr)
clips = []

# Generate each clip (could parallelize but Together may rate-limit)
for i, photo in enumerate(photos):
    clip_path = generate_kling_clip(photo, i)
    if clip_path:
        # Scale to 720p
        scaled = os.path.join(work_dir, f'scaled_{i}.mp4')
        subprocess.run([
            'ffmpeg', '-y', '-i', clip_path,
            '-vf', f'scale={W}:{H}:force_original_aspect_ratio=decrease,pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:black',
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
            '-pix_fmt', 'yuv420p', '-r', str(FPS), '-an', scaled
        ], capture_output=True)
        clips.append(scaled)
        os.unlink(clip_path)
    else:
        # Fallback: use original photo as static frame
        static = os.path.join(work_dir, f'static_{i}.mp4')
        subprocess.run([
            'ffmpeg', '-y', '-loop', '1', '-i', photo, '-t', '5',
            '-vf', f'scale={W}:{H}:force_original_aspect_ratio=decrease,pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:black',
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
            '-pix_fmt', 'yuv420p', '-r', str(FPS), '-an', static
        ], capture_output=True)
        clips.append(static)

if not clips:
    sys.exit(1)

# ═══════════════════════ Concat with crossfades ═══════════════════════
stitched = os.path.join(work_dir, 'stitched.mp4')
n = len(clips)

if n == 1:
    os.rename(clips[0], stitched)
else:
    filter_lines = []
    for i in range(n):
        filter_lines.append(f"[{i}:v]fps={FPS},setpts=PTS-STARTPTS[v{i}];")

    prev = "[v0]"
    for i in range(1, n):
        offset = i * (5 - FADE_S)  # each clip is 5s
        out_v = f"[vf{i}]"
        filter_lines.append(f"{prev}[v{i}]xfade=transition=fade:duration={FADE_S}:offset={offset}{out_v};")
        prev = out_v

    inputs = []
    for c in clips:
        inputs.extend(['-i', c])

    subprocess.run([
        'ffmpeg', '-y', *inputs,
        '-filter_complex', ''.join(filter_lines),
        '-map', f'[vf{n-1}]',
        '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
        '-pix_fmt', 'yuv420p', '-r', str(FPS), '-an', stitched
    ], capture_output=True)

# ═══════════════════════ Composite with overlays ═══════════════════════
intro_png = os.path.join(work_dir, 'intro_overlay.png')
bottom_png = os.path.join(work_dir, 'bottom_bar.png')

# Get duration
probe = subprocess.run(
    ['ffprobe', '-v', 'quiet', '-show_entries', 'format=duration',
     '-of', 'default=noprint_wrappers=1:nokey=1', stitched],
    capture_output=True, text=True
)
total_dur = float(probe.stdout.strip() or n * 5 - FADE_S * (n-1))

has_intro = os.path.exists(intro_png)
has_bottom = os.path.exists(bottom_png)

if has_intro and has_bottom:
    cmd = [
        'ffmpeg', '-y', '-i', stitched, '-i', intro_png, '-i', bottom_png,
        '-filter_complex',
        f"[1:v]fade=t=in:st=0.5:d=0.4:alpha=1,fade=t=out:st=3.0:d=0.5:alpha=1[intro_ov];"
        f"[0:v][intro_ov]overlay=0:0[v_intro];"
        f"[v_intro][2:v]overlay=0:0:enable='between(t,0.5,{total_dur:.2f})'",
        '-map', '[v_out]',  # oops, should reference correct output
        '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
        '-pix_fmt', 'yuv420p', '-r', str(FPS), '-movflags', '+faststart', '-an',
        out_file
    ]
else:
    cmd = ['cp', stitched, out_file]

# Fix: proper output mapping
if has_intro and has_bottom:
    cmd = [
        'ffmpeg', '-y', '-i', stitched, '-i', intro_png, '-i', bottom_png,
        '-filter_complex',
        f"[1:v]fade=t=in:st=0.5:d=0.4:alpha=1,fade=t=out:st=3.0:d=0.5:alpha=1[intro_ov];"
        f"[0:v][intro_ov]overlay=0:0[v1];"
        f"[v1][2:v]overlay=0:0:enable='between(t,0.5,{total_dur:.2f})'[out]",
        '-map', '[out]',
        '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
        '-pix_fmt', 'yuv420p', '-r', str(FPS), '-movflags', '+faststart', '-an',
        out_file
    ]
elif has_intro:
    cmd = [
        'ffmpeg', '-y', '-i', stitched, '-i', intro_png,
        '-filter_complex',
        f"[1:v]fade=t=in:st=0.5:d=0.4:alpha=1,fade=t=out:st=3.0:d=0.5:alpha=1[intro_ov];"
        f"[0:v][intro_ov]overlay=0:0[out]",
        '-map', '[out]',
        '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
        '-pix_fmt', 'yuv420p', '-r', str(FPS), '-movflags', '+faststart', '-an',
        out_file
    ]

result = subprocess.run(cmd, capture_output=True)
if result.returncode != 0:
    print(f"Composite error, using stitched: {result.stderr.decode()[-300:]}", file=sys.stderr)
    subprocess.run(['cp', stitched, out_file])
else:
    print(f"AI Walkthrough generated: {os.path.getsize(out_file)/1024:.0f} KB")