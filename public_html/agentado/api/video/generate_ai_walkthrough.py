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
import sys, os, json, subprocess, time, base64, requests, tempfile, shutil, signal
from concurrent.futures import ThreadPoolExecutor, as_completed

# ── Global timeout: 45 minutes max for the entire pipeline ──
MAX_RUNTIME_SEC = 2700
start_time = time.time()

def check_timeout():
    if time.time() - start_time > MAX_RUNTIME_SEC:
        print(f"TIMEOUT after {MAX_RUNTIME_SEC}s", file=sys.stderr)
        sys.exit(2)

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
tunnel_url   = config.get('tunnel_url', 'http://127.0.0.1:9000')
kling_base   = config.get('kling_img_base', '')  # e.g. 'agentado/output/jobs/xxx/kling_imgs'
clip_dur     = float(config.get('clip_duration', 5.0))
fade_s       = float(config.get('crossfade', 0.8))

W, H = 854, 480  # 480p widescreen — faster Kling generation
FPS = 24

# Progress file
progress_file = os.path.join(work_dir, 'progress.json')

def write_progress(status, stage='', progress=0, error=None, result_url=None, **extra):
    data = {
        'status': status,
        'stage': stage,
        'progress': progress,
        'total_clips': len(photos),
        'completed_clips': progress,
        'error': error,
        'result_url': result_url,
    }
    if extra:
        data.update(extra)
    with open(progress_file, 'w') as f:
        json.dump(data, f)
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
    # Together API requires image URLs, not base64. Build tunnel URL from local path.
    filename = os.path.basename(image_path)
    img_url = f'{tunnel_url.rstrip("/")}/{kling_base}/{filename}' if kling_base else f'{tunnel_url.rstrip("/")}/agentado/output/jobs/{os.path.basename(work_dir)}/kling_imgs/{filename}'

    headers = {
        'Authorization': f'Bearer {TOGETHER_KEY}',
        'Content-Type': 'application/json',
    }
    payload = {
        'model': 'kwaivgI/kling-2.1-standard',
        'prompt': (
            'Cinematic real estate walkthrough with pronounced 3D camera movement. '
            'Strong parallax — foreground furniture shifts noticeably faster than distant walls, creating real depth. '
            'Confident slow dolly push-in that draws the viewer into the room. '
            'Wide orbital sweep showing the space from a clearly shifting angle. '
            'Cinematic depth of field with soft background blur. '
            'Warm natural lighting, professional real estate quality. '
            'Do not change any furniture, objects, or room layout — only the camera perspective moves.'
        ),
        'n_frames': 80,  # 5s @ ~16fps effective for 480p (faster gen)
        'frame_images': [{'input_image': img_url, 'frame': 0}],
        'mode': 'standard',
    }

    try:
        resp = requests.post(
            'https://api.together.ai/v2/videos',
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
    clip_url = data.get('outputs', {}).get('video_url') or data.get('url') or data.get('video_url')

    # Poll if needed — with timeout check
    if not clip_url and 'id' in data:
        video_id = data['id']
        for attempt in range(150):  # up to 300 seconds (5 min)
            check_timeout()
            time.sleep(2)
            try:
                poll = requests.get(
                    f'https://api.together.ai/v2/videos/{video_id}',
                    headers=headers, timeout=15
                )
                if poll.status_code == 200:
                    pd = poll.json()
                    status = pd.get('status', '')
                    if status == 'completed':
                        clip_url = pd.get('outputs', {}).get('video_url') or pd.get('url') or pd.get('video_url')
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

kling_ok = len(results)
kling_fail = len(errors)
print(f"Kling: {kling_ok}/{len(photos)} generated ({kling_fail} failed)")
if errors:
    print(f"  Failed clips: {errors}", file=sys.stderr)

# ═══════════════════════ Scale clips & create fallbacks ═══════════════════
write_progress('scaling_clips', f'Scaling {len(results)} clips… (+{kling_fail} fallbacks)', n, kling_ok=kling_ok, kling_fallbacks=kling_fail)

clips = []
clip_types = []  # 'kling' or 'fallback'
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
            clip_types.append('kling')
        else:
            clips.append(None)
            clip_types.append('fallback')
        # Clean up raw Kling download
        try:
            os.unlink(results[i])
        except:
            pass
    else:
        # Ken Burns fallback: subtle zoom+pan instead of static frame
        static = os.path.join(work_dir, f'static_{i}.mp4')
        # Vary the Ken Burns style across clips for variety
        kbs = [
            # 1. Zoom in slowly
            f"zoompan=z='min(zoom+0.0015,1.18)':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s={W}x{H}:fps={FPS}",
            # 2. Pan right gently
            f"zoompan=z=1.08:x='iw/2-(iw/zoom/2)+cos(on*0.01)*20':y='ih/2-(ih/zoom/2)':d=1:s={W}x{H}:fps={FPS}",
            # 3. Zoom in + slight drift
            f"zoompan=z='min(zoom+0.0012,1.15)':d=1:x='iw/2-(iw/zoom/2)+sin(on*0.008)*15':y='ih/2-(ih/zoom/2)+cos(on*0.006)*10':s={W}x{H}:fps={FPS}",
            # 4. Slow zoom out
            f"zoompan=z='max(zoom-0.001,1.0)':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s={W}x{H}:fps={FPS}",
        ]
        kb = kbs[i % len(kbs)]
        r = subprocess.run([
            'ffmpeg', '-y', '-loop', '1', '-i', photos[i],
            '-t', str(clip_dur),
            '-vf', f'scale={W}:{H}:force_original_aspect_ratio=decrease,'
                   f'pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:black,' + kb,
            '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
            '-pix_fmt', 'yuv420p', '-r', str(FPS), '-an', static
        ], capture_output=True)
        clips.append(static if r.returncode == 0 else None)
        clip_types.append('fallback')

# Filter out None clips
valid_clips = [c for c in clips if c]
if not valid_clips:
    write_progress('failed', 'All clips failed', 0, error='No clips generated')
    sys.exit(1)

# ═══════════════════════ Stitch with crossfades ═══════════════════════
write_progress('compositing', f'Stitching {len(valid_clips)} clips with crossfades…', n)
stitched = os.path.join(work_dir, 'stitched.mp4')
m = len(valid_clips)

MAX_BATCH = 6  # ffmpeg can't handle 25 decoders at once — batch to avoid EMFILE/OOM

def stitch_group(clips, prefix):
    """Stitch a small group of clips with crossfades into one mp4."""
    g = len(clips)
    if g == 1:
        return clips[0]
    
    # Probe actual duration of each clip (may be a multi-clip batch from recursion)
    durations = []
    for c in clips:
        probe = subprocess.run(
            ['ffprobe', '-v', 'quiet', '-show_entries', 'format=duration',
             '-of', 'default=noprint_wrappers=1:nokey=1', c],
            capture_output=True, text=True
        )
        durations.append(float(probe.stdout.strip() or clip_dur))
    
    # Build cumulative offsets using actual durations
    offsets = [0.0]
    for i in range(1, g):
        offsets.append(offsets[i-1] + durations[i-1] - fade_s)
    
    filter_parts = []
    for i in range(g):
        filter_parts.append(f"[{i}:v]fps={FPS},setpts=PTS-STARTPTS[v{i}];")
    prev = "[v0]"
    for i in range(1, g):
        out_v = f"[vg{i}]"
        filter_parts.append(f"{prev}[v{i}]xfade=transition=fade:duration={fade_s}:offset={offsets[i]:.3f}{out_v};")
        prev = out_v
    ff_inputs = []
    for c in clips:
        ff_inputs.extend(['-i', c])
    out_path = os.path.join(work_dir, f'{prefix}_stitched.mp4')
    r = subprocess.run([
        'ffmpeg', '-y', *ff_inputs,
        '-filter_complex', ''.join(filter_parts),
        '-map', f'[vg{g-1}]',
        '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
        '-pix_fmt', 'yuv420p', '-r', str(FPS), '-an', out_path
    ], capture_output=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.decode()[-300:])
    return out_path

def stitch_recursive(clips, prefix):
    """Recursively batch-stitch clips to avoid opening too many decoders."""
    if len(clips) <= MAX_BATCH:
        return stitch_group(clips, prefix)
    batches = []
    for i in range(0, len(clips), MAX_BATCH):
        batch = clips[i:i+MAX_BATCH]
        if len(batch) >= 2:
            out = stitch_group(batch, f'{prefix}_b{i//MAX_BATCH}')
            batches.append(out)
        else:
            # Single clip left over from batch (e.g. 7 mod 6 = 1), pass through directly
            batches.append(batch[0])
    return stitch_recursive(batches, f'{prefix}_r')

try:
    stitched = stitch_recursive(valid_clips, 'l0')
except RuntimeError as e:
    write_progress('failed', 'Stitching failed', n, error=str(e))
    sys.exit(1)

write_progress('compositing', f'Stitching complete, {m} clips merged.', n)

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
write_progress('finalizing', 'Merging audio…', n + 2)

# --- Step A: Merge video + audio (no subtitles yet) ---
merge_file = os.path.join(work_dir, 'merged_no_subs.mp4')
cmd = ['ffmpeg', '-y', '-i', current_video]
maps = ['-map', '0:v']

if audio_path and os.path.exists(audio_path):
    cmd.extend(['-i', audio_path, '-map', '1:a'])
    cmd.extend(['-c:a', 'aac', '-b:a', '192k', '-shortest'])
else:
    cmd.append('-an')

cmd.extend(maps)
cmd.extend([
    '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
    '-pix_fmt', 'yuv420p', '-r', str(FPS),
    '-movflags', '+faststart'
])
cmd.append(merge_file)

r = subprocess.run(cmd, capture_output=True)
if r.returncode != 0:
    err = r.stderr.decode()[-500:]
    write_progress('failed', 'Merge failed', n + 2, error=err)
    print(f"Merge error: {err}", file=sys.stderr)
    sys.exit(1)

# --- Step B: Burn subtitles if present (uses Pillow overlay, no libass needed) ---
if subs_path and os.path.exists(subs_path):
    write_progress('finalizing', 'Burning subtitles…', n + 3)
    r = subprocess.run([
        sys.executable,
        os.path.join(os.path.dirname(__file__), 'burn_subs_overlay.py'),
        merge_file, subs_path, out_file
    ], capture_output=True)
    if r.returncode != 0:
        err = r.stderr.decode()[-500:]
        write_progress('failed', 'Subtitle burn failed', n + 3, error=err)
        print(f"Subtitle error: {err}", file=sys.stderr)
        sys.exit(1)
    os.unlink(merge_file)  # clean up temp
else:
    os.rename(merge_file, out_file)

# --- Done ---
size_kb = os.path.getsize(out_file) / 1024
video_url = f"/agentado/api/video/serve-job-video.php?job={os.path.basename(work_dir)}"

# Build per-clip module_type breakdown for frontend
module_types = clip_types
kling_count = module_types.count('kling')
fallback_count = module_types.count('fallback')

write_progress('completed', f'Done! ({kling_count} Kling + {fallback_count} Fallback)', n + 4,
               result_url=video_url,
               kling_ok=kling_count, kling_fallbacks=fallback_count,
               clip_types=module_types)
print(f"3D Cinematic generated: {size_kb:.0f} KB  |  {kling_count} Kling + {fallback_count} fallback", file=sys.stderr)