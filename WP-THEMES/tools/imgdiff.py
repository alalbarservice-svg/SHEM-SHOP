"""usage: imgdiff.py <dirX> <dirY> <label> -> JSON lines per matching PNG: size match, % px differing (>0), % significant (>16), bbox rows."""
import sys, os, json
import numpy as np
from PIL import Image
dx, dy, label = sys.argv[1:4]
rows = []
for f in sorted(os.listdir(dx)):
    if not f.endswith(".png"): continue
    fy = os.path.join(dy, f)
    if not os.path.exists(fy): rows.append({"file": f, "missing": True}); continue
    a = np.asarray(Image.open(os.path.join(dx, f)).convert("RGB")).astype(int)
    b = np.asarray(Image.open(fy).convert("RGB")).astype(int)
    r = {"file": f, "sizeX": a.shape[1::-1], "sizeY": b.shape[1::-1], "sameSize": a.shape == b.shape}
    h = min(a.shape[0], b.shape[0]); w = min(a.shape[1], b.shape[1])
    d = np.abs(a[:h, :w] - b[:h, :w]).max(axis=2)
    nz = d > 0; sig = d > 16
    r["pct_any"] = round(100 * nz.sum() / d.size, 4)
    r["pct_sig"] = round(100 * sig.sum() / d.size, 4)
    r["maxdiff"] = int(d.max())
    if sig.any():
        ys, xs = np.where(sig); r["bbox_sig"] = [int(xs.min()), int(ys.min()), int(xs.max()), int(ys.max())]
    rows.append(r)
print(json.dumps({"label": label, "rows": rows}, ensure_ascii=False))
