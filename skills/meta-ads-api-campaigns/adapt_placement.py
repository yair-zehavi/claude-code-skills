#!/usr/bin/env python3
"""Build a missing placement ratio from one supplied artwork, for example 1:1 -> 9:16.

Background: the same artwork scaled to cover the target canvas, heavily blurred and
darkened, so the frame reads as one continuous branded surface with no black bars.
Foreground: the sharp original, scaled to fit and centred, with a feathered edge on
the sides that meet the background so the seam blends.

Text, logo and CTA keep their position inside the original, so check the result
against the placement's UI overlays before uploading. A native export from the
designer is still better: their text can use the full height.

Usage:
  adapt_placement.py <input> <output> [--ratio 9:16] [--width 1080]
                     [--blur 48] [--darken 0.45] [--feather 48]

Requires Pillow.
"""
import argparse

from PIL import Image, ImageDraw, ImageEnhance, ImageFilter


def feather_mask(size, feather, axis):
    """Opaque mask with linear ramps of `feather` px on the two edges of `axis`."""
    w, h = size
    mask = Image.new("L", size, 255)
    draw = ImageDraw.Draw(mask)
    length = h if axis == "y" else w
    f = max(0, min(feather, length // 2))
    for i in range(f):
        alpha = int(255 * (i + 1) / (f + 1))
        if axis == "y":
            draw.line([(0, i), (w, i)], fill=alpha)
            draw.line([(0, h - 1 - i), (w, h - 1 - i)], fill=alpha)
        else:
            draw.line([(i, 0), (i, h)], fill=alpha)
            draw.line([(w - 1 - i, 0), (w - 1 - i, h)], fill=alpha)
    return mask


def adapt(src, ratio_w, ratio_h, width, blur, darken, feather):
    img = src.convert("RGB")
    iw, ih = img.size
    W, H = width, round(width * ratio_h / ratio_w)

    cover = max(W / iw, H / ih)
    bg = img.resize((max(W, round(iw * cover)), max(H, round(ih * cover))), Image.LANCZOS)
    left, top = (bg.width - W) // 2, (bg.height - H) // 2
    bg = bg.crop((left, top, left + W, top + H))
    bg = bg.filter(ImageFilter.GaussianBlur(blur))
    bg = ImageEnhance.Brightness(bg).enhance(darken)

    fit = min(W / iw, H / ih)
    fw, fh = round(iw * fit), round(ih * fit)
    fg = img.resize((fw, fh), Image.LANCZOS)
    axis = "y" if fh < H else "x"
    mask = feather_mask((fw, fh), feather, axis) if (fw, fh) != (W, H) else None
    bg.paste(fg, ((W - fw) // 2, (H - fh) // 2), mask)
    return bg


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("input")
    ap.add_argument("output")
    ap.add_argument("--ratio", default="9:16", help="target W:H, e.g. 9:16 or 4:5")
    ap.add_argument("--width", type=int, default=1080, help="output width in px")
    ap.add_argument("--blur", type=float, default=48, help="background blur radius")
    ap.add_argument("--darken", type=float, default=0.45, help="background brightness factor")
    ap.add_argument("--feather", type=int, default=48, help="seam feather in px")
    a = ap.parse_args()

    rw, rh = (int(x) for x in a.ratio.split(":"))
    out = adapt(Image.open(a.input), rw, rh, a.width, a.blur, a.darken, a.feather)
    if a.output.lower().endswith((".jpg", ".jpeg")):
        out.save(a.output, quality=92)
    else:
        out.save(a.output)
    print(f"{a.output}: {out.width}x{out.height}")


if __name__ == "__main__":
    main()
