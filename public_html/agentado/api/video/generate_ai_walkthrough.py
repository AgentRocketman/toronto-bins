#!/usr/bin/env python3
"""
Generate 3D AI Cinematic video — Kling 2.1 clips + ffmpeg compositing
Parallel clip generation with progress tracking.

Usage: python3 generate_ai_walkthrough.py <work_dir> <output.mp4> <config.json>
Config JSON keys:
  photos: ["path1.jpg", ...]
  listing: {...}
  audio_path: "/path/to/mixed_audio.mp3" or null
  subs_path: "/path/to/subtitles.ass" or null
  show_price_intro: true/false
  show_price_bar: true/false
  show_contact_slide: true/false
  clip_duration: 5.0 (seconds per Kling clip)
  crossfade: 0.8 (seconds)
"""
import sys, os, json, subprocess, time, base64, requests, tempfile, shutil
from concurrent.futures import ThreadPoolExecutor, as_completed

work_dir = sys.argv[1]
out_file = sys.argv[2]

with open(sys.argv[3]) as f:
    config = json.load(f)

photos       = config['photos']
listing      = config.get('listing', {})
audio_path   = config.get('audio_path')
subs_path    = config.get('subs_path')
show_intro   = config.get('show_price_intro', True)
show_bar     = config.get('show_price_bar', True)
show_contact = config.get('show_contact_slide', True)
clip_dur     = float(config.get('clip_duration', 5.0))
fade_s       = float(config.get('crossfade', 0.8))

W, H = 1280, 720
FPS = 24

# Progress file
progress_file = os.path.join(work_dir, 'progress.json')

def write_progress(status, stage='', progress=0, error=None, result_url=None):
    with open(progress_file, 'w') as f:
        json.dump({
            'status': status,
            'stage': stage,
            'progress': progress,
            'total_clips': len(photos),
            'completed_clips': progress,
            'error': error,
            'result_url': result_url,
        }, f)
    # Also copy progress to a well-known location for PHP polling
    php_progress = os.path.join(work_dir, '..', 'progress.json')
    try:
        shutil.copy(progress_file, php_progress)
    except:
        pass

# Get Together AI key
config_path = '/data/.openclaw/openclaw.json'
with open(config_path) as f:
    cfg = json.load(f)
TOGETHER_KEY = cfg['env'].get('TOGETHER_API_KEY', '')

def generate_kling_clip(image_path, index):
    """Generate a Kling 2.1 cinematic clip. Returns (index, clip_path_or_None, error)."""
    try:
        with open(image_path, 'rb') as f:
            img_b64 = base64.b64encode(f.read()).decode()
    except Exception as e:
        return (index, None, str(e))

    headers = {
        'Authorization': f'Bearer {TOGETHER_KEY}',
        'Content-Type': 'application/json',
    }
    payload = {
        'model': 'kwaivgI/kling-2.1-standard',
        'seconds': str(int(clip_dur)),
        'frame_images': [{'input_image': img_b64, 'frame': 0}],
    }

    try:
        resp = requests.post(
            'https://api.together.xyz/v1/videos',
            headers=headers, json=payload, timeout=120
        )
    except Exception as e:
        return (index, None, f"API request failed: {e}")

    if resp.status_code >= 400:
        try:
            err = resp.json()
        except:
            err = {'error': str(resp.status_code)}
        return (index, None, json.dumps(err))

    data = resp.json()
    clip_url = data.get('url') or data.get('video_url')

    # Poll if needed
    if not clip_url and 'id' in data:
        video_id = data['id']
        for attempt in range(90):  # up to 180 seconds
            time.sleep(2)
            try:
                poll = requests.get(
                    f'https://api.together.xyz/v1/videos/{video_id}',
                    headers=headers, timeout=15
                )
                if poll.status_code == 200:
                    pd = poll.json()
                    status = pd.get('status', '')
                    if status == 'completed':
                        clip_url = pd.get('url') or pd.get('video_url')
                        break
                    elif status == 'failed':
                        err_msg = pd.get('error', {}).get('message', 'Unknown error')
                        return (index, None, f"Kling failed: {err_msg}")
            except:
                pass
        if not clip_url:
            return (index, None, "Timed out polling Kling")

    # Download clip
    if clip_url:
        try:
            clip_resp = requests.get(clip_url, timeout=60)
            clip_resp.raise_for_status()
            clip_path = os.path.join(work_dir, f'kling_{index}.mp4')
            with open(clip_path, 'wb') as f:
                f.write(clip_resp.content)
            return (index, clip_path, None)
        except Exception as e:
            return (index, None, f"Download failed: {e}")

    return (index, None, "No clip URL returned")

# ═══════════════════════ Generate Kling clips (parallel) ═══════════════════
write_progress('generating_clips', f'Firing {len(photos)} Kling 2.1 jobs…', 0)
n = len(photos)

results = {}
errors = []

# Fire all requests in parallel
with ThreadPoolExecutor(max_workers=min(n, 8)) as executor:
    futures = {executor.submit(generate_kling_clip, photo, i): i for i, photo in enumerate(photos)}
    for future in as_completed(futures):
        i, clip_path, err = future.result()
        if clip_path:
            results[i] = clip_path
        else:
            errors.append((i, err))

        completed = len(results)
        write_progress(
            'generating_clips',
            f'{completed}/{n} clips generated',
            completed,
            error=f'{len(errors)} failures' if errors else None
        )

if errors:
    print(f"WARNING: {len(errors)} clips failed: {errors}", file=sys.stderr)

# ═══════════════════════ Scale clips & create fallbacks ═══════════════════
write_progress('scaling_clips', f'Scaling {len(results)} clips…', n)

clips = []
for i in range(n):
    if i in results and results[i]:
        scaled = os.path.join(work_dir, f'scaled_{i}.mp4')
        r = subprocess.run([
            'ffmpeg', '-y', '-i', results[i],
            '-vf', f'scale={W}:{H}:force_original_aspect_ratio=decrease,'
                   f'pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:black',
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
            '-pix_fmt', 'yuv420p', '-r', str(FPS), '-an', scaled
        ], capture_output=True)
        if r.returncode == 0:
            clips.append(scaled)
        else:
            clips.append(None)
        # Clean up raw Kling download
        try:
            os.unlink(results[i])
        except:
            pass
    else:
        # Fallback: static frame from photo
        static = os.path.join(work_dir, f'static_{i}.mp4')
        r = subprocess.run([
            'ffmpeg', '-y', '-loop', '1', '-i', photos[i],
            '-t', str(clip_dur),
            '-vf', f'scale={W}:{H}:force_original_aspect_ratio=decrease,'
                   f'pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:black',
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
            '-pix_fmt', 'yuv420p', '-r', str(FPS), '-an', static
        ], capture_output=True)
        clips.append(static if r.returncode == 0 else None)

# Filter out None clips
valid_clips = [c for c in clips if c]
if not valid_clips:
    write_progress('failed', 'All clips failed', 0, error='No clips generated')
    sys.exit(1)

# ═══════════════════════ Stitch with crossfades ═══════════════════════
write_progress('compositing', f'Stitching {len(valid_clips)} clips with crossfades…', n)
stitched = os.path.join(work_dir, 'stitched.mp4')
m = len(valid_clips)

if m == 1:
    os.rename(valid_clips[0], stitched)
else:
    filter_parts = []
    for i in range(m):
        filter_parts.append(f"[{i}:v]fps={FPS},setpts=PTS-STARTPTS[v{i}];")

    prev = "[v0]"
    for i in range(1, m):
        offset = i * (clip_dur - fade_s)
        out_v = f"[vf{i}]"
        filter_parts.append(f"{prev}[v{i}]xfade=transition=fade:duration={fade_s}:offset={offset}{out_v};")
        prev = out_v

    ff_inputs = []
    for c in valid_clips:
        ff_inputs.extend(['-i', c])

    r = subprocess.run([
        'ffmpeg', '-y', *ff_inputs,
        '-filter_complex', ''.join(filter_parts),
        '-map', f'[vf{m-1}]',
        '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
        '-pix_fmt', 'yuv420p', '-r', str(FPS), '-an', stitched
    ], capture_output=True)

    if r.returncode != 0:
        write_progress('failed', 'Stitching failed', n, error=r.stderr.decode()[-500:])
        sys.exit(1)

# Get total duration
probe = subprocess.run(
    ['ffprobe', '-v', 'quiet', '-show_entries', 'format=duration',
     '-of', 'default=noprint_wrappers=1:nokey=1', stitched],
    capture_output=True, text=True
)
total_dur = float(probe.stdout.strip() or m * clip_dur - fade_s * (m - 1))

# ═══════════════════════ Overlays (intro + bottom bar) ═══════════════════
write_progress('compositing', 'Adding overlays…', n + 1)

intro_png = os.path.join(work_dir, 'intro_overlay.png')
bottom_png = os.path.join(work_dir, 'bottom_bar.png')
overlaid = os.path.join(work_dir, 'overlaid.mp4')

has_intro = os.path.exists(intro_png)
has_bottom = os.path.exists(bottom_png)

if has_intro or has_bottom:
    filter_chunks = []
    filter_idx = 1

    if has_intro:
        filter_chunks.append(
            f"[{filter_idx}:v]fade=t=in:st=0.5:d=0.4:alpha=1,"
            f"fade=t=out:st=3.0:d=0.5:alpha=1[intro_ov];"
        )
        filter_idx += 1

    chain = "[0:v]"
    output_label = ""

    if has_intro:
        chain += f"[intro_ov]overlay=0:0[v{filter_idx}];"
        output_label = f"[v{filter_idx}]"
        filter_idx += 1

    if has_bottom:
        bar_idx = 2 if has_intro else 1
        chain += f"[{output_label[:-1]}][{bar_idx}:v]overlay=0:0:enable='between(t,0.5,{total_dur:.2f})'[out];"
        output_label = "[out]"

    filter_str = ''.join(filter_chunks) + chain
    cmd = ['ffmpeg', '-y', '-i', stitched]
    if has_intro:
        cmd.extend(['-i', intro_png])
    if has_bottom:
        cmd.extend(['-i', bottom_png])
    cmd.extend([
        '-filter_complex', filter_str,
        '-map', output_label,
        '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
        '-pix_fmt', 'yuv420p', '-r', str(FPS), '-movflags', '+faststart', '-an',
        overlaid
    ])
    r = subprocess.run(cmd, capture_output=True)
    if r.returncode == 0:
        current_video = overlaid
    else:
        print(f"Overlay error, skipping: {r.stderr.decode()[-200:]}", file=sys.stderr)
        current_video = stitched
else:
    current_video = stitched

# ═══════════════════════ Audio + Subtitles merge ═══════════════════════
write_progress('finalizing', 'Merging audio and subtitles…', n + 2)

cmd = ['ffmpeg', '-y', '-i', current_video]

if audio_path and os.path.exists(audio_path):
    cmd.extend(['-i', audio_path])

filter_str = ''
video_input = '0:v'

# Subtitle burn-in
if subs_path and os.path.exists(subs_path):
    # Escape special chars in path for ffmpeg
    subs_escaped = subs_path.replace('\\', '\\\\').replace(':', '\\:')
    filter_str = f"[{video_input}]subtitles='{subs_escaped}'[out]"
    output_map = '[out]'
else:
    output_map = '0:v'

if audio_path and os.path.exists(audio_path):
    cmd.extend(['-map', '1:a'])  # audio track
else:
    cmd.append('-an')

if filter_str:
    cmd.extend(['-filter_complex', filter_str, '-map', output_map])
else:
    cmd.extend(['-map', '0:v'])

cmd.extend([
    '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
    '-pix_fmt', 'yuv420p', '-r', str(FPS),
    '-movflags', '+faststart'
])

if audio_path and os.path.exists(audio_path):
    cmd.extend(['-c:a', 'aac', '-b:a', '192k', '-shortest'])

cmd.append(out_file)

r = subprocess.run(cmd, capture_output=True)

if r.returncode == 0:
    size_kb = os.path.getsize(out_file) / 1024
    write_progress('completed', 'Done!', n + 3, result_url='ready')
    print(f"3D Cinematic generated: {size_kb:.0f} KB", file=sys.stderr)
else:
    err = r.stderr.decode()[-500:]
    write_progress('failed', 'Final merge failed', n + 2, error=err)
    print(f"Merge error: {err}", file=sys.stderr)
    sys.exit(1)