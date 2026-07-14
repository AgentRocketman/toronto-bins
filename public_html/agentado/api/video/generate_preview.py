#!/usr/bin/env python3
"""
Generate Ken Burns preview video (480p, watermarked)
Usage: python3 generate_preview.py <work_dir> <output.mp4> <listing_json>
"""
import sys, os, json, subprocess, shutil

work_dir = sys.argv[1]
out_file = sys.argv[2]
listing = json.loads(sys.argv[3]) if len(sys.argv) > 3 else {}

# Find photos
photos = sorted(
    [os.path.join(work_dir, f) for f in os.listdir(work_dir) if f.endswith(('.jpg', '.jpeg', '.png', '.webp'))],
    key=lambda p: int(''.join(filter(str.isdigit, os.path.basename(p))) or '0')
)
if not photos:
    sys.exit(1)

W, H = 640, 360  # 480p preview
CLIP_S = 2.5  # seconds per photo
FADE_S = 0.6
FPS = 24

# ── Scale each photo to 640x360, letterboxed ──
scaled = []
for i, p in enumerate(photos):
    dest = os.path.join(work_dir, f"s{i}.jpg")
    subprocess.run([
        'ffmpeg', '-y', '-i', p, '-vf',
        f'scale={W}:{H}:force_original_aspect_ratio=decrease,pad={W}:{H}:(ow-iw)/2:(oh-ih)/2:black',
        '-q:v', '2', dest
    ], capture_output=True)
    scaled.append(dest)

if len(scaled) == 1:
    # Single photo: just pan
    subprocess.run([
        'ffmpeg', '-y', '-loop', '1', '-i', scaled[0], '-t', str(CLIP_S),
        '-vf', f'zoompan=z=\'min(zoom+0.0008,1.15)\':d=1:x=iw/2-(iw/zoom/2):y=ih/2-(ih/zoom/2):s={W}x{H}:fps={FPS}',
        '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '28', '-pix_fmt', 'yuv420p', '-color_range', 'tv', '-movflags', '+faststart', '-r', str(FPS),
        '-an', out_file
    ], capture_output=True)
else:
    # Multiple photos: zoompan each clip, then concat with crossfades
    clips = []
    for i, s in enumerate(scaled):
        clip = os.path.join(work_dir, f"clip{i}.mp4")
        # Alternate zoom direction
        if i % 2 == 0:
            zfx = f'zoompan=z=\'min(zoom+0.001,1.12)\':d=1:x=iw/2-(iw/zoom/2):y=ih/2-(ih/zoom/2):s={W}x{H}:fps={FPS}'
        else:
            zfx = f'zoompan=z=\'min(zoom+0.001,1.10)\':d=1:x=iw/2-(iw/zoom/2)+10:y=ih/2-(ih/zoom/2):s={W}x{H}:fps={FPS}'
        subprocess.run([
            'ffmpeg', '-y', '-loop', '1', '-i', s, '-t', str(CLIP_S),
            '-vf', zfx + ',format=yuv420p', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '28',
            '-pix_fmt', 'yuv420p', '-color_range', 'tv', '-movflags', '+faststart', '-r', str(FPS), '-an', clip
        ], capture_output=True)
        clips.append(clip)

    # Build concat filter with crossfades
    n = len(clips)
    filter_lines = []
    for i in range(n):
        filter_lines.append(f"[{i}:v]fps={FPS},setpts=PTS-STARTPTS[v{i}];")
    
    prev = f"[v0]"
    for i in range(1, n):
        offset = i * (CLIP_S - FADE_S)
        next_v = f"[v{i}]"
        out_v = f"[vf{i}]"
        filter_lines.append(
            f"{prev}{next_v}xfade=transition=fade:duration={FADE_S}:offset={offset}{out_v};"
        )
        prev = out_v
    
    filter_complex = ''.join(filter_lines)
    
    inputs = []
    for c in clips:
        inputs.extend(['-i', c])
    
    subprocess.run([
        'ffmpeg', '-y', *inputs,
        '-filter_complex', filter_complex,
        '-map', f'[vf{n-1}]',
        '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '28',
        '-pix_fmt', 'yuv420p', '-color_range', 'tv', '-movflags', '+faststart', '-r', str(FPS), '-an',
        out_file
    ], capture_output=True)

# ── Watermark with drawtext (use MP4 box metadata instead if drawtext unavailable) ──
# Try with subtitles-style overlay
watermarked = out_file + '.tmp.mp4'

# Create watermark text image using Python if drawtext fails
# Simple approach: overlay a semi-transparent text using a generated PNG
try:
    from PIL import Image, ImageDraw, ImageFont
    wm_img = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(wm_img)
    try:
        font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 28)
    except:
        font = ImageFont.load_default()
    
    text = "PREVIEW"
    bbox = d.textbbox((0, 0), text, font=font)
    tw, th = bbox[2] - bbox[0], bbox[3] - bbox[1]
    x, y = (W - tw) // 2, (H - th) // 2
    
    # Semi-transparent black bg behind text
    d.rectangle([x-12, y-6, x+tw+12, y+th+6], fill=(0, 0, 0, 140))
    d.text((x, y), text, fill=(255, 255, 255, 180), font=font)
    
    wm_path = os.path.join(work_dir, 'watermark.png')
    wm_img.save(wm_path)
    
    subprocess.run([
        'ffmpeg', '-y', '-i', out_file, '-i', wm_path,
        '-filter_complex', '[1:v]format=rgba,colorchannelmixer=aa=0.8[wm];[0:v][wm]overlay=0:0',
        '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '28',
        '-pix_fmt', 'yuv420p', '-color_range', 'tv', '-movflags', '+faststart', '-an',
        watermarked
    ], capture_output=True)
    
    if os.path.exists(watermarked):
        os.rename(watermarked, out_file)
except Exception as e:
    # If watermark fails, just use unwatermarked version
    print(f"Watermark warning: {e}", file=sys.stderr)

print(f"Preview done: {out_file} ({os.path.getsize(out_file) / 1024:.0f} KB)")