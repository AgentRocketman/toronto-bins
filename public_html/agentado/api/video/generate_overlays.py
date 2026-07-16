#!/usr/bin/env python3
"""
Generate Agentado-style overlay PNGs from listing data
Usage: python3 generate_overlays.py <work_dir> <listing_json>
Creates: intro_overlay.png, bottom_bar.png, end_card.png
"""
import sys, os, json, textwrap
from PIL import Image, ImageDraw, ImageFont

work_dir = sys.argv[1]
listing = json.loads(sys.argv[2])

W, H = 1280, 720
B = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
R = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'

PRICE = listing.get('price', '$0')
ADDR = listing.get('address', '')
BEDS = listing.get('beds', '')
BATHS = listing.get('baths', '')
SQFT = listing.get('sqft', '')

# Shorten address for bottom bar
short_addr = ADDR.split(',')[0].strip() if ADDR else ''
if len(short_addr) > 30:
    short_addr = short_addr[:28] + '…'

stats = []
if BEDS: stats.append(f"{BEDS} BED")
if BATHS: stats.append(f"{BATHS} BATH")
if SQFT: stats.append(f"{SQFT} sqft" if 'sqft' not in SQFT.lower() else SQFT)

# ═══════════════════════ INTRO OVERLAY ═══════════════════════
intro = Image.new('RGBA', (W, H), (0, 0, 0, 0))
ctx = ImageDraw.Draw(intro)

# Gradient overlay at bottom
for y in range(H):
    if y < H * 0.70:
        alpha = 0
    elif y < H * 0.75:
        alpha = int(255 * 0.55 * (y - H*0.70) / (H*0.05))
    else:
        t = (y - H*0.75) / (H*0.25)
        alpha = int(255 * (0.55 + 0.25 * min(t, 1.0)))
    if alpha > 0:
        ctx.line([(0, y), (W, y)], fill=(0, 0, 0, min(alpha, 255)))

# Price — green
fs_price = 72
pf = ImageFont.truetype(B, fs_price)
ctx.text((W//2+3, int(H*0.80)+3), PRICE, fill=(0,0,0,120), font=pf, anchor='mm')
ctx.text((W//2, int(H*0.80)), PRICE, fill=(34,197,94,255), font=pf, anchor='mm')

# Address
addr_fs = 30
af = ImageFont.truetype(B, addr_fs)
# Wrap
words = ADDR.split()
lines = []
line = ''
for w in words:
    test = line + ' ' + w if line else w
    b = ctx.textbbox((0,0), test, font=af)
    if b[2]-b[0] > W*0.82:
        lines.append(line)
        line = w
    else:
        line = test
lines.append(line)

addr_y = int(H*0.80 + fs_price * 1.1)
for i, ln in enumerate(lines):
    ctx.text((W//2, addr_y + i*33), ln, fill=(255,255,255,255), font=af, anchor='mm')

# Stats
label_fs = 24
lf = ImageFont.truetype(R, label_fs)

def tw(text, font):
    return ctx.textbbox((0,0), text, font=font)[2]

sep = '  ·  '
widths = [tw(s, lf) for s in stats]
sep_w = tw(sep, lf)
total_w = sum(widths) + sep_w * (len(stats) - 1)
x = (W - total_w) / 2
label_y = addr_y + (len(lines)-1)*33 + 48

for i, s in enumerate(stats):
    ctx.text((x + widths[i]/2, label_y), s, fill=(255,255,255,170), font=lf, anchor='mm')
    x += widths[i] + sep_w

# Purple accent line
accent_y = label_y + 30
ctx.line([(W*0.32, accent_y), (W*0.68, accent_y)], fill=(124,92,231,140), width=2)

intro.save(os.path.join(work_dir, 'intro_overlay.png'))

# ═══════════════════════ BOTTOM BAR ═══════════════════════
bar = Image.new('RGBA', (W, H), (0, 0, 0, 0))
ctx2 = ImageDraw.Draw(bar)

bar_h = 42
for y in range(H-bar_h, H):
    t = (y - (H-bar_h)) / bar_h
    if t < 0.3:
        alpha = int(255 * 0.45 * t / 0.3)
    else:
        alpha = int(255 * (0.45 + 0.25 * (t-0.3)/0.7))
    ctx2.line([(0, y), (W, y)], fill=(0, 0, 0, min(alpha, 255)))

parts = []
if PRICE:
    parts.append((PRICE, (34,197,94,255)))
if BEDS:
    parts.append((f"{BEDS} BED", (255,255,255,130)))
if BATHS:
    parts.append((f"{BATHS} BATH", (255,255,255,130)))
if SQFT:
    sf_label = SQFT.replace('sqft', 'sqft').strip()
    parts.append((sf_label, (255,255,255,130)))
if short_addr:
    parts.append((short_addr, (255,255,255,160)))

seps = ['  •  ', ' · ', ' · ', '  |  ', '  ']

bf = 16
bar_font = ImageFont.truetype(R, bf)
text_y = H - bar_h * 0.35

pwidths = [ctx2.textbbox((0,0), p[0], font=bar_font)[2] for p in parts]
sep_widths = [ctx2.textbbox((0,0), s, font=bar_font)[2] for s in seps]
total = sum(pwidths) + sum(sep_widths[:len(pwidths)-1])

x = max(10, (W - total) / 2)
for i, (text, color) in enumerate(parts):
    ctx2.text((x + pwidths[i]/2, text_y), text, fill=color, font=bar_font, anchor='mm')
    x += pwidths[i] + (sep_widths[i] if i < len(seps) else 0)

bar.save(os.path.join(work_dir, 'bottom_bar.png'))

# ═══════════════════════ END CARD ═══════════════════════
card = Image.new('RGBA', (W, H), (0,0,0,0))
ctx3 = ImageDraw.Draw(card)

# Dark radial gradient
for r in range(int(W*0.85), 0, -1):
    alpha = int(255 * (r / (W*0.85)) * 0.75 + 20)
    ctx3.ellipse([W*0.35-r, H*0.5-r, W*0.35+r, H*0.5+r], fill=(31,27,46,min(alpha,255)))

# Purple accent line
ctx3.line([(W*0.2, H*0.08), (W*0.8, H*0.08)], fill=(124,92,231,120), width=2)

# Placeholder agent photo circle
px_c, py_c = W//8, H//2
pr = H//4
ctx3.ellipse([px_c-pr, py_c-pr, px_c+pr, py_c+pr], fill=(80,80,100,255))
ctx3.ellipse([px_c-pr-3, py_c-pr-3, px_c+pr+3, py_c+pr+3], outline=(124,92,231,90), width=3)

# Agent info (generic for now — agent can customize)
name_fs = 28
ctx3.text((W//2, H*0.35), 'Your Agent', fill=(255,255,255,255), font=ImageFont.truetype(B, name_fs), anchor='mm')

contact_fs = 22
ctx3.text((W//2, H*0.55), 'Contact for a private showing', fill=(34,197,94,255), font=ImageFont.truetype(B, contact_fs), anchor='mm')

tag_fs = 16
ctx3.text((W//2, H-35), 'Powered by Agentado', fill=(150,150,160,255), font=ImageFont.truetype(R, tag_fs), anchor='mm')

card.save(os.path.join(work_dir, 'end_card.png'))

print(f"Overlays generated: intro={os.path.getsize(os.path.join(work_dir, 'intro_overlay.png'))} bytes")