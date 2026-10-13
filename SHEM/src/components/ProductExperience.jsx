import { useEffect, useRef, useState } from "react";
import { adminApi } from "../admin-api";
import { productImage } from "../experience";

export function ProductGallery({ product, he = true, color = "" }) {
  const images = product.images?.length
      ? product.images
      : [{ url: productImage(product), alt: he ? product.he : product.en }],
    [index, setIndex] = useState(0),
    [zoom, setZoom] = useState(false),
    start = useRef(null),
    dialog = useRef(null),
    opener = useRef(null);
  useEffect(() => {
    const url = product.colors?.find((c) => c.name === color)?.imageUrl;
    setIndex(
      Math.max(
        0,
        images.findIndex((i) => i.url === url),
      ),
    );
  }, [product.id, color]);
  useEffect(() => {
    if (zoom) dialog.current?.showModal();
    else if (dialog.current?.open) dialog.current.close();
  }, [zoom]);
  const active = images[index] || images[0],
    move = (delta) =>
      setIndex((i) => (i + delta + images.length) % images.length);
  return (
    <div className="product-gallery">
      <button
        ref={opener}
        className="gallery-main"
        type="button"
        onClick={() => setZoom(true)}
        onTouchStart={(e) => {
          start.current = e.touches[0].clientX;
        }}
        onTouchEnd={(e) => {
          if (
            start.current !== null &&
            Math.abs(e.changedTouches[0].clientX - start.current) > 45
          )
            move(e.changedTouches[0].clientX > start.current ? -1 : 1);
          start.current = null;
        }}
        aria-label={he ? "הגדלת תמונת המוצר" : "Enlarge product image"}
      >
        <img
          src={active.url}
          alt={active.alt || (he ? product.he : product.en)}
          decoding="async"
        />
        <span>{he ? "להגדלה" : "Enlarge"} ↗</span>
      </button>
      <div
        className="gallery-thumbnails"
        aria-label={he ? "תמונות המוצר" : "Product images"}
      >
        {images.map((image, i) => (
          <button
            type="button"
            key={image.url + ":" + i}
            aria-label={`${he ? "תמונה" : "Image"} ${i + 1}`}
            aria-pressed={index === i}
            onClick={() => setIndex(i)}
          >
            <img src={image.url} alt="" loading="lazy" />
          </button>
        ))}
      </div>
      <dialog
        ref={dialog}
        className="gallery-zoom"
        onCancel={() => setZoom(false)}
        onClose={() => {
          setZoom(false);
          opener.current?.focus();
        }}
      >
        <button
          type="button"
          onClick={() => setZoom(false)}
          aria-label={he ? "סגירת הגדלה" : "Close image"}
        >
          ✕
        </button>
        <img src={active.url} alt={active.alt || product.he} />
        <div>
          <button type="button" onClick={() => move(-1)}>
            {he ? "הקודמת" : "Previous"}
          </button>
          <span>
            {index + 1} / {images.length}
          </span>
          <button type="button" onClick={() => move(1)}>
            {he ? "הבאה" : "Next"}
          </button>
        </div>
      </dialog>
    </div>
  );
}
export function SizeGuide({ product, he = true }) {
  const guide = product?.sizeGuide;
  return (
    <div className="size-guide">
      {guide?.rows?.length ? (
        <>
          <p>
            {guide.instructions ||
              (he
                ? "המידות מתייחסות לפריט זה."
                : "Measurements apply to this product.")}
          </p>
          {guide.fit && <p>{guide.fit}</p>}
          {guide.model && <p>{guide.model}</p>}
          <div className="table-scroll">
            <table>
              <caption>
                {he
                  ? "מידות המוצר בסנטימטרים, אלא אם צוין אחרת"
                  : "Product measurements in cm unless noted otherwise"}
              </caption>
              <thead>
                <tr>
                  <th scope="col">{he ? "מידה" : "Size"}</th>
                  {guide.columns.map((c) => (
                    <th scope="col" key={c}>
                      {c}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {guide.rows.map((r) => (
                  <tr key={r.size}>
                    <th scope="row">{r.size}</th>
                    {r.values.map((v, i) => (
                      <td key={i}>{v || "-"}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ) : (
        <p>
          {he
            ? "טרם הוזנה טבלת מידות לפריט זה. להתאמת מידה ניתן לפנות לשירות לפני הרכישה."
            : "A size chart has not yet been added. Contact us for sizing assistance before purchase."}
        </p>
      )}
      <a className="text-link" href="#/help">
        {he ? "עזרה בבחירת מידה" : "Sizing help"}
      </a>
    </div>
  );
}
export function StockWatch({ product, size, variantId, he = true }) {
  const [state, setState] = useState(""),
    [busy, setBusy] = useState(false);
  return (
    <div className="stock-watch">
      <button
        type="button"
        className="button outline"
        disabled={busy}
        onClick={async () => {
          if (!size) {
            setState(
              he ? "בחרו מידה כדי לשמור התראה." : "Choose a size first.",
            );
            return;
          }
          setBusy(true);
          try {
            const r = await fetch("/api/customer/watches", {
                method: "POST",
                headers: { "content-type": "application/json" },
                body: JSON.stringify({
                  productId: product.id,
                  size,
                  variantId,
                }),
              }),
              d = await r.json();
            if (!r.ok) throw Error(d.error);
            setState(
              he
                ? "ההתראה נשמרה. עדכון יופיע באזור שלך כאשר הפריט יהיה זמין."
                : "Saved. An alert will appear in your account when available.",
            );
          } catch (e) {
            setState(e.message);
          } finally {
            setBusy(false);
          }
        }}
      >
        {he ? "עדכנו אותי כשהמידה חוזרת" : "Notify me when available"}
      </button>
      {state && (
        <p role="status">
          {state} <a href="#/account">{he ? "לאזור שלי" : "My account"}</a>
        </p>
      )}
    </div>
  );
}
export function ProductPresentationEditor({
  value,
  onChange,
  onBusy = () => {},
}) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [sources, setSources] = useState([]);
  const guide = value.sizeGuide || {
    columns: [],
    rows: [],
    instructions: "",
    fit: "",
    model: "",
  };
  const images = value.images?.length
    ? value.images
    : value.imageUrl
      ? [{ url: value.imageUrl, alt: value.he }]
      : [];
  const updateGuide = (next) => onChange({ ...value, sizeGuide: next });
  return (
    <fieldset className="product-presentation-editor" disabled={busy}>
      <legend>גלריה, מידות וצבעים</legend>
      {error && <p role="alert">{error}</p>}
      <p>עד שלוש תמונות. התמונה הראשונה תשמש גם בכרטיס המוצר.</p>
      <div className="editor-image-slots">
        {[0, 1, 2].map((i) => (
          <div key={i}>
            <label>
              תמונה {i + 1}
              <input
                type="file"
                disabled={busy}
                accept="image/png,image/jpeg,image/webp"
                onChange={async (e) => {
                  const f = e.target.files?.[0];
                  if (!f) return;
                  setBusy(true);
                  onBusy(true);
                  setError("");
                  try {
                    const r = await adminApi("media", {
                      method: "POST",
                      body: f,
                      headers: { "content-type": f.type },
                    });
                    const next = [...images];
                    next[i] = { url: r.url, alt: value.he };
                    const compact = next.filter(Boolean);
                    onChange({
                      ...value,
                      images: compact,
                      imageUrl: compact[0]?.url || value.imageUrl,
                    });
                  } catch (e) {
                    setError(e.message);
                  } finally {
                    setBusy(false);
                    onBusy(false);
                    e.target.value = "";
                  }
                }}
              />
            </label>
            {images[i] && (
              <>
                <img
                  className="admin-editor-image"
                  src={images[i].url}
                  alt={`תמונה ${i + 1}`}
                />
                <label>
                  תיאור נגיש לתמונה
                  <input
                    value={images[i].alt || ""}
                    onChange={(e) =>
                      onChange({
                        ...value,
                        images: images.map((x, j) =>
                          j === i ? { ...x, alt: e.target.value } : x,
                        ),
                      })
                    }
                  />
                </label>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    const next = images.filter((_, j) => j !== i);
                    onChange({
                      ...value,
                      images: next,
                      imageUrl: next[0]?.url || "",
                    });
                  }}
                >
                  הסרת תמונה
                </button>
                {i > 0 && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                      const next = [...images];
                      [next[i - 1], next[i]] = [next[i], next[i - 1]];
                      onChange({
                        ...value,
                        images: next,
                        imageUrl: next[0].url,
                      });
                    }}
                  >
                    הקדמת תמונה
                  </button>
                )}
              </>
            )}
          </div>
        ))}
      </div>
      <h3>טבלת מידות לפריט</h3>
      <label>
        העתקת טבלה ממוצר אחר
        <select
          defaultValue=""
          onFocus={async () => {
            if (!sources.length)
              try {
                setSources(await adminApi("products"));
              } catch (e) {
                setError(e.message);
              }
          }}
          onChange={(e) => {
            const p = sources.find((p) => p.id === e.target.value);
            if (p?.sizeGuide) updateGuide(structuredClone(p.sizeGuide));
          }}
        >
          <option value="">בחירת מוצר</option>
          {sources
            .filter((p) => p.id !== value.id && p.sizeGuide?.rows?.length)
            .map((p) => (
              <option key={p.id} value={p.id}>
                {p.he}
              </option>
            ))}
        </select>
      </label>
      {["instructions", "fit", "model"].map((key, i) => (
        <label key={key}>
          {["איך למדוד", "מידע על הגזרה", "מידות הדוגמן והמידה שלבש"][i]}
          <textarea
            value={guide[key] || ""}
            onChange={(e) => updateGuide({ ...guide, [key]: e.target.value })}
          />
        </label>
      ))}
      <button
        type="button"
        disabled={guide.columns.length >= 6}
        onClick={() =>
          updateGuide({
            ...guide,
            columns: [...guide.columns, `מדידה ${guide.columns.length + 1}`],
            rows: guide.rows.map((r) => ({ ...r, values: [...r.values, ""] })),
          })
        }
      >
        הוספת עמודת מדידה
      </button>
      {guide.columns.map((c, i) => (
        <label key={i}>
          כותרת מדידה {i + 1}
          <input
            value={c}
            onChange={(e) =>
              updateGuide({
                ...guide,
                columns: guide.columns.map((x, j) =>
                  j === i ? e.target.value : x,
                ),
              })
            }
          />
          <button
            type="button"
            onClick={() =>
              updateGuide({
                ...guide,
                columns: guide.columns.filter((_, j) => j !== i),
                rows: guide.rows.map((r) => ({
                  ...r,
                  values: r.values.filter((_, j) => j !== i),
                })),
              })
            }
          >
            הסרת עמודה
          </button>
        </label>
      ))}
      {guide.rows.map((r, i) => (
        <fieldset key={i}>
          <legend>שורת מידה {i + 1}</legend>
          <label>
            מידה
            <input
              value={r.size}
              onChange={(e) =>
                updateGuide({
                  ...guide,
                  rows: guide.rows.map((x, j) =>
                    j === i ? { ...x, size: e.target.value } : x,
                  ),
                })
              }
            />
          </label>
          {guide.columns.map((c, k) => (
            <label key={k}>
              {c}
              <input
                value={r.values[k] || ""}
                onChange={(e) =>
                  updateGuide({
                    ...guide,
                    rows: guide.rows.map((x, j) =>
                      j === i
                        ? {
                            ...x,
                            values: x.values.map((v, m) =>
                              m === k ? e.target.value : v,
                            ),
                          }
                        : x,
                    ),
                  })
                }
              />
            </label>
          ))}
          <button
            type="button"
            onClick={() =>
              updateGuide({
                ...guide,
                rows: guide.rows.filter((_, j) => j !== i),
              })
            }
          >
            הסרת מידה
          </button>
        </fieldset>
      ))}
      <button
        type="button"
        disabled={guide.rows.length >= 30}
        onClick={() =>
          updateGuide({
            ...guide,
            rows: [
              ...guide.rows,
              { size: "", values: guide.columns.map(() => "") },
            ],
          })
        }
      >
        הוספת שורת מידה
      </button>
      <h3>טבלת צבעים</h3>
      <p>
        שמות הצבעים צריכים להתאים לשמות בגרסאות המוצר. מלאי ומק״ט מנוהלים
        בגרסאות.
      </p>
      {(value.colors || []).map((c, i) => (
        <fieldset key={i}>
          <legend>צבע {i + 1}</legend>
          <label>
            שם צבע
            <input
              value={c.name}
              onChange={(e) =>
                onChange({
                  ...value,
                  colors: value.colors.map((x, j) =>
                    j === i ? { ...x, name: e.target.value } : x,
                  ),
                })
              }
            />
          </label>
          <label>
            גוון
            <input
              type="color"
              value={c.hex || "#000000"}
              onChange={(e) =>
                onChange({
                  ...value,
                  colors: value.colors.map((x, j) =>
                    j === i ? { ...x, hex: e.target.value } : x,
                  ),
                })
              }
            />
          </label>
          <label>
            תמונה לבחירת הצבע
            <select
              value={c.imageUrl || ""}
              onChange={(e) =>
                onChange({
                  ...value,
                  colors: value.colors.map((x, j) =>
                    j === i ? { ...x, imageUrl: e.target.value } : x,
                  ),
                })
              }
            >
              <option value="">ללא תמונה משויכת</option>
              {images.map((im, k) => (
                <option key={k} value={im.url}>
                  תמונה {k + 1}
                </option>
              ))}
            </select>
          </label>
          <button
            type="button"
            onClick={() =>
              onChange({
                ...value,
                colors: value.colors.filter((_, j) => j !== i),
              })
            }
          >
            הסרת צבע
          </button>
        </fieldset>
      ))}
      <button
        type="button"
        disabled={(value.colors || []).length >= 30}
        onClick={() =>
          onChange({
            ...value,
            colors: [
              ...(value.colors || []),
              { name: "", hex: "#000000", imageUrl: "" },
            ],
          })
        }
      >
        הוספת צבע
      </button>
      {busy && <p role="status">מעלה תמונה...</p>}
    </fieldset>
  );
}
