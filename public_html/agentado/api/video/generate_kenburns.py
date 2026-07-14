#!/usr/bin/env python3
"""
Generate full Ken Burns video with overlays (720p)
Usage: python3 generate_kenburns.py <work_dir> <output.mp4> <photo_paths_json> <listing_json>
"""
import sys, os, json, subprocess

work_dir = sys.argv[1]
out_file = sys.argv[2]
photos = json.loads(sys.argv[3])
listing = json.loads(sys.argv[4])

W, H = 1280, 720
CLIP_S = 3.5   # seconds per photo
FADE_S = 0.8
FPS = 24

# Scale photos
scaled = []
for i, p in enumerate(photos):
    dest = os.path.join(work_dir, f"s{i}.jpg")
    subprocess.run([
        'ffmpeg', '-y', '-i', p, '-vf',
        f'scale={W}:{H}:force_original_aspect_ratio=decrease,pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:black',
        '-q:v', '2', dest
    ], capture_output=True)
    scaled.append(dest)

# Generate clips
clips = []
for i, s in enumerate(scaled):
    clip = os.path.join(work_dir, f"c{i}.mp4")
    dur = CLIP_S if i < len(scaled) - 1 else CLIP_S  # last clip same duration
    zfx = (f"zoompan=z='min(zoom+0.0006,1.10)':d=1:"
           f"x=iw/2-(iw/zoom/2)+{i%3*3}:y=ih/2-(ih/zoom/2):s={W}x{H}:fps={FPS}")
    subprocess.run([
        'ffmpeg', '-y', '-loop', '1', '-i', s, '-t', str(dur),
        '-vf', zfx,
        '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
        '-pix_fmt', 'yuv420p', '-r', str(FPS), '-an', clip
    ], capture_output=True)
    clips.append(clip)

# Concat with crossfades
n = len(clips)
if n == 1:
    stitched = os.path.join(work_dir, 'stitched.mp4')
    os.rename(clips[0], stitched)
else:
    stitched = os.path.join(work_dir, 'stitched.mp4')
    filter_lines = []
    for i in range(n):
        filter_lines.append(f"[{i}:v]fps={FPS},setpts=PTS-STARTPTS[v{i}];")
    
    prev = "[v0]"
    for i in range(1, n):
        offset = i * (CLIP_S - FADE_S)
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

# ── Probe total duration ──
probe = subprocess.run(
    ['ffprobe', '-v', 'quiet', '-show_entries', 'format=duration',
     '-of', 'default=noprint_wrappers=1:nokey=1', stitched],
    capture_output=True, text=True
)
total_dur = float(probe.stdout.strip() or n * CLIP_S - FADE_S * (n-1))
print(f"Video duration: {total_dur:.2f}s")

# ── Composite with overlays ──
intro_png = os.path.join(work_dir, 'intro_overlay.png')
bottom_png = os.path.join(work_dir, 'bottom_bar.png')
end_png = os.path.join(work_dir, 'end_card.png')

has_intro = os.path.exists(intro_png)
has_end = os.path.exists(end_png)
has_bottom = os.path.exists(bottom_png)

# Build filter
filters = []
inputs = ['-i', stitched]
maps = []

if has_intro:
    inputs.extend(['-i', intro_png])
    filters.append(
        f"[1:v]fade=t=in:st=0.5:d=0.4:alpha=1,fade=t=out:st=3.0:d=0.5:alpha=1[intro_ov];"
    )

if has_bottom:
    inputs.extend(['-i', bottom_png])

if has_end:
    inputs.extend(['-i', end_png])
    end_idx = len(inputs) // 2  # index of end card
    # End card: make 3-second clip to append
    end_st = total_dur - 0.5
    filters.append(
        f"[{end_idx-1}:v]trim=duration=3,setpts=PTS-STARTPTS[end_clip];"
    )

# Overlay chain
curr = '[0:v]'
if has_intro:
    curr_intro = curr + '[intro_ov]overlay=0:0'
    filters.append(f"{curr_intro}[v_over_intro];")
    curr = '[v_over_intro]'

if has_bottom:
    bottom_idx = (2 if has_intro else 1) + (1 if has_intro else 0)
    curr_bottom = f"{curr}[{bottom_idx}:v]overlay=0:0:enable='between(t,0.5,{total_dur:.2f})'"
    filters.append(f"{curr_bottom}[v_final];")

if has_end:
    pass  # Add end card path — simplified for now

if not filters:
    os.rename(stitched, out_file)
    print("No overlays, copied stitched video")
    sys.exit(0)

filter_complex = ''.join(filters)
final_out = '[v_final]' if has_bottom else (curr if not has_intro else '[v_over_intro]')

# Build command
cmd = ['ffmpeg', '-y'] + inputs + [
    '-filter_complex', filter_complex,
    '-map', final_out,
    '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
    '-pix_fmt', 'yuv420p', '-r', str(FPS), '-movflags', '+faststart',
    '-an', out_file
]

result = subprocess.run(cmd, capture_output=True)
if result.returncode != 0:
    print(f"FFmpeg error: {result.stderr.decode()[-500:]}", file=sys.stderr)
    # Fallback: just use stitched video
    subprocess.run(['cp', stitched, out_file])
else:
    print(f"Ken Burns video generated: {os.path.getsize(out_file)/1024:.0f} KB")