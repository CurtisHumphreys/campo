#!/usr/bin/env python3
"""Generate CAMPO PWA icons (full-bleed, maskable-safe) from the favicon design:
dark background (#1c1a17) with a bold orange 'C' (#f97316), centered well within
the maskable safe zone."""
from PIL import Image, ImageDraw, ImageFont

BG = (28, 26, 23, 255)      # #1c1a17
FG = (249, 115, 22, 255)    # #f97316
FONT = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
OUT = "/opt/forgebox/apps/campoffice/public/pwa-icons"


def make(size):
    img = Image.new("RGBA", (size, size), BG)
    d = ImageDraw.Draw(img)
    # Glyph height ~55% of canvas keeps it inside the maskable safe zone (center 80%).
    font = ImageFont.truetype(FONT, int(size * 0.62))
    box = d.textbbox((0, 0), "C", font=font)
    w, h = box[2] - box[0], box[3] - box[1]
    x = (size - w) / 2 - box[0]
    y = (size - h) / 2 - box[1]
    d.text((x, y), "C", font=font, fill=FG)
    img.save(f"{OUT}/icon-{size}.png")
    print(f"wrote {OUT}/icon-{size}.png")


import os
os.makedirs(OUT, exist_ok=True)
for s in (192, 512):
    make(s)
