#!/usr/bin/env python3
"""
Burn ASS subtitles onto video using Pillow + ffmpeg overlay filter.
Creates ONE subtitle video (transparent bg, white text) then overlays it.
Only 2 inputs total — avoids pthread exhaustion.
"""
import re, os, sys, subprocess, tempfile, json, shutil
from PIL import Image, ImageDraw, ImageFont

FONT_PATH = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
FONT_SIZE = 36
SHADOW_OFFSET = 2
SHADOW_ALPHA = 140
TEXT_ALPHA = 240
BOTTOM_MARGIN = 60

def parse_ass(ass_path):
    with open(ass_path) as f:
        content = f.read()
    pattern = r'Dialogue: \d+,(\d+):(\d+):(\d+)\.(\d+),(\d+):(\d+):(\d+)\.(\d+),.*?}}(.+?)$'
    dialogues = []
    for m in re.finditer(pattern, content, re.MULTILINE):
        h1,m1,s1,cs1 = int(m[1]),int(m[2]),int(m[3]),int(m[4])
        h2,m2,s2,cs2 = int(m[5]),int(m[6]),int(m[7]),int(m[8])
        start = h1*3600 + m1*60 + s1 + cs1/100.0
        end = h2*3600 + m2*60 + s2 + cs2/100.0
        text = m[9].strip()
        if text:
            dialogues.append((start, end, text))
    return dialogues

def burn(video_path, ass_path, output_path, ffmpeg_bin='ffmpeg'):
    if not os.path.exists(ass_path):
        print(f"ASS file not found: {ass_path}", file=sys.stderr)
        return 1

    dialogues = parse_ass(ass_path)
    if not dialogues:
        print("No dialogue lines found", file=sys.stderr)
        return 1

    # Get video dimensions and duration using ffprobe
    probe = subprocess.run(
        ['ffprobe', '-v', 'quiet', '-of', 'json', '-show_format',
         '-show_streams', '-select_streams', 'v:0', video_path],
        capture_output=True, text=True
    )
    info = json.loads(probe.stdout)
    vstream = info['streams'][0]
    width, height = vstream['width'], vstream['height']
    duration = float(info['format']['duration'])
    
    print(f"Video: {width}x{height}, {duration:.1f}s, {len(dialogues)} subs", file=sys.stderr)

    font = ImageFont.truetype(FONT_PATH, FONT_SIZE)
    tmpdir = tempfile.mkdtemp(prefix='agentado_subs_')

    # Find framerate to generate subtitle frames
    fps = eval(vstream.get('avg_frame_rate', '30'))

    # Build a lookup: for each frame, what text to show
    total_frames = int(duration * fps) + 1
    
    # For efficiency: create one frame per unique subtitle window
    # Build list of (frame_start, frame_end, text) 
    sub_frames = []
    for start, end, text in dialogues:
        fs = int(start * fps)
        fe = int(end * fps)
        sub_frames.append((fs, fe, text))
    
    # Create subtitle PNGs: white text on black background
    sub_pngs = []
    for i, (fs, fe, text) in enumerate(sub_frames):
        img = Image.new('RGB', (width, height), (0, 0, 0))  # black bg for colorkey
        draw = ImageDraw.Draw(img)
        
        # Word wrap if needed
        max_width = int(width * 0.9)
        words = text.split()
        lines = []
        current_line = []
        for w in words:
            test = ' '.join(current_line + [w])
            if font.getbbox(test)[2] - font.getbbox(test)[0] <= max_width:
                current_line.append(w)
            else:
                if current_line:
                    lines.append(' '.join(current_line))
                current_line = [w]
        if current_line:
            lines.append(' '.join(current_line))
        
        total_height = sum(font.getbbox(l)[3] - font.getbbox(l)[1] for l in lines) + (len(lines)-1) * 6
        y_start = height - BOTTOM_MARGIN - total_height
        
        for li, line in enumerate(lines):
            bbox = font.getbbox(line)
            tw = bbox[2] - bbox[0]
            th = bbox[3] - bbox[1]
            x = (width - tw) // 2
            y = y_start + li * (th + 6)
            # Shadow (dark gray)
            draw.text((x+SHADOW_OFFSET, y+SHADOW_OFFSET), line, font=font, fill=(40, 40, 40))
            # Text (white)
            draw.text((x, y), line, font=font, fill=(255, 255, 255))
        
        path = os.path.join(tmpdir, f'sub_{i:04d}.png')
        img.save(path)
        sub_pngs.append((path, fs, fe))
    
    # Create a subtitle video: one frame = one PNG, repeated for its duration
    # Use concat demuxer or just direct overlay
    sub_video_path = os.path.join(tmpdir, 'sub_video.mp4')
    
    # Strategy: concat each sub PNG to its proper duration, with blank between
    concat_file = os.path.join(tmpdir, 'concat.txt')
    with open(concat_file, 'w') as f:
        for i, (path, fs, fe) in enumerate(sub_pngs):
            nframes = fe - fs
            if nframes < 1:
                nframes = 1
            f.write(f"file '{path}'\n")
            f.write(f"duration {nframes/fps:.6f}\n")
        # Add a blank frame at end
        last_end = sub_pngs[-1][2]
        blank_path = os.path.join(tmpdir, 'blank.png')
        Image.new('RGB', (width, height), (0, 0, 0)).save(blank_path)
        remaining = total_frames - last_end
        if remaining > 0:
            f.write(f"file '{blank_path}'\n")
            f.write(f"duration {remaining/fps:.6f}\n")
    
    r = subprocess.run([
        ffmpeg_bin, '-threads', '1', '-y',
        '-f', 'concat', '-safe', '0', '-i', concat_file,
        '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '0',
        '-pix_fmt', 'yuv420p', '-r', str(fps),
        '-t', str(duration),
        sub_video_path
    ], capture_output=True, timeout=60)
    
    if r.returncode != 0:
        print(f"Subtitle video creation failed: {r.stderr.decode()[-500:]}", file=sys.stderr)
        shutil.rmtree(tmpdir, ignore_errors=True)
        return 1
    
    # Now overlay using colorkey to make black background transparent
    # (libx264 doesn't support alpha channel, so we key out the black)
    r = subprocess.run([
        ffmpeg_bin, '-threads', '1', '-y',
        '-i', video_path, '-i', sub_video_path,
        '-filter_complex', '[1:v]colorkey=0x000000:0.01:0.01[akey];[0:v][akey]overlay=0:0[out]',
        '-map', '[out]', '-map', '0:a?',
        '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '28',
        '-c:a', 'aac', '-b:a', '128k', '-shortest',
        output_path
    ], capture_output=True, timeout=120)
    
    shutil.rmtree(tmpdir, ignore_errors=True)
    
    if r.returncode != 0:
        print(f"Overlay failed: {r.stderr.decode()[-500:]}", file=sys.stderr)
        return 1
    
    return 0

if __name__ == '__main__':
    if len(sys.argv) < 4:
        print(f"Usage: {sys.argv[0]} <video> <ass> <output>", file=sys.stderr)
        sys.exit(1)
    sys.exit(burn(sys.argv[1], sys.argv[2], sys.argv[3]))