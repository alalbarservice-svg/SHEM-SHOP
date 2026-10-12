import { useEffect, useState } from "react";
import { adminApi } from "../admin-api";
const home = import.meta.env.VITE_SHOP_ROLE === "home";
const api = (p, d) =>
  adminApi(
    "channel/" + p,
    d === undefined ? {} : { method: "POST", body: JSON.stringify(d) },
  );
export function ChannelProduct({ product, onChange }) {
  const [url, setUrl] = useState(""),
    [source, setSource] = useState(null),
    [link, setLink] = useState(null),
    [map, setMap] = useState({}),
    [notice, setNotice] = useState(""),
    [busy, setBusy] = useState(false);
  const refresh = async () => {
    const d = await api("products/" + product.id + "/source");
    if (!home) setUrl(d.url);
    setLink(d.link);
    if (home && d.link) {
      setUrl(d.link.sourceUrl);
      setMap(d.link.variantMap || {});
      try {
        setSource(await api("preview", { sourceUrl: d.link.sourceUrl }));
      } catch (e) {
        setNotice(e.message);
      }
    }
    onChange?.(!!d.link);
  };
  useEffect(() => {
    if (product.id) refresh().catch(() => {});
  }, [product.id]);
  const run = async (f) => {
    setBusy(true);
    setNotice("");
    try {
      await f();
      await refresh();
    } catch (e) {
      setNotice(e.message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <fieldset className="channel-product">
      <legend>חיבור {home ? "ל-SHEM" : "ל-SHIMI"}</legend>
      {!product.revision ? (
        <p>שמרו את המוצר לפני יצירת חיבור.</p>
      ) : (
        <>
          {product.demo && link?.demo && (
            <p className="channel-demo-guide">
              פריט הדגמה. מוצר המקור והיעד מוסתרים מהחנויות. אפשר לשנות את
              המק״ט, המחיר ומחיר המחירון ב-SHEM וללחוץ כאן על סנכרון כדי לבדוק
              את העברת הנתונים. המלאי מתחיל ב-0.
            </p>
          )}
          <label>
            {home
              ? "קישור מוצר המקור ב-SHEM"
              : "קישור מקור להעתקה לכרטיס SHIMI"}
            <input
              dir="ltr"
              type="url"
              value={url}
              readOnly={!home || !!link}
              onChange={(e) => {
                setUrl(e.target.value);
                setSource(null);
              }}
            />
          </label>
          {!home ? (
            <button
              type="button"
              disabled={!url}
              onClick={() =>
                run(async () => {
                  await navigator.clipboard.writeText(url);
                  setNotice("הקישור הועתק.");
                })
              }
            >
              העתקת קישור
            </button>
          ) : (
            <>
              {!link && (
                <button
                  type="button"
                  disabled={busy || !url}
                  onClick={() =>
                    run(async () => {
                      setSource(await api("preview", { sourceUrl: url }));
                      setMap({});
                    })
                  }
                >
                  בדיקת מקור ותצוגה מקדימה
                </button>
              )}
              {source && !link && (
                <>
                  <p>
                    מק״ט: {source.sku} | מחיר: ₪{source.price}{" "}
                    {source.previousPrice
                      ? `| לפני מבצע: ₪${source.previousPrice}`
                      : ""}
                  </p>
                  {(product.variants || []).map((v) => (
                    <label key={v.id}>
                      {v.size} / {v.color}
                      <select
                        value={map[v.id] || ""}
                        onChange={(e) =>
                          setMap({ ...map, [v.id]: e.target.value })
                        }
                      >
                        <option value="">בחרו גרסת מקור</option>
                        {source.availability.variants
                          .filter((x) => x.size === v.size)
                          .map((x) => (
                            <option key={x.id} value={x.id}>
                              {x.size} / {x.color} / {x.sku}
                            </option>
                          ))}
                      </select>
                    </label>
                  ))}
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                      run(async () => {
                        await api("products/" + product.id + "/link", {
                          sourceUrl: url,
                          variantMap: map,
                        });
                        setNotice(
                          "המוצר חובר. סגרו ופתחו את הכרטיס כדי לטעון את הערכים המעודכנים.",
                        );
                      })
                    }
                  >
                    אישור חיבור וסנכרון
                  </button>
                </>
              )}
              {link && (
                <>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() =>
                      run(() => api("products/" + product.id + "/sync", {}))
                    }
                  >
                    סנכרון עכשיו
                  </button>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                      if (
                        confirm(
                          "ניתוק החיבור יסתיר את המוצר בחנות SHIMI עד להגדרה מחדש. להמשיך?",
                        )
                      )
                        run(() =>
                          api("products/" + product.id + "/unlink", {}),
                        );
                    }}
                  >
                    ניתוק חיבור
                  </button>
                  <p>
                    מק״ט, מחירים ומלאי נקבעים ב-SHEM. השם והתמונות נשארים
                    עצמאיים.
                  </p>
                </>
              )}
            </>
          )}
          {!home && link && (
            <button
              type="button"
              disabled={busy}
              onClick={() =>
                run(() => api("products/" + product.id + "/sync", {}))
              }
            >
              בדיקת חיבור וסנכרון
            </button>
          )}
          {home && link && source && (
            <div>
              <h4>נתוני המקור הנוכחיים ב-SHEM</h4>
              <table>
                <tbody>
                  <tr>
                    <th>מק״ט</th>
                    <td dir="ltr">{source.sku}</td>
                  </tr>
                  <tr>
                    <th>מחיר נוכחי</th>
                    <td>₪{source.price}</td>
                  </tr>
                  <tr>
                    <th>מחיר לפני מבצע</th>
                    <td>
                      {source.previousPrice
                        ? `₪${source.previousPrice}`
                        : "לא הוגדר"}
                    </td>
                  </tr>
                </tbody>
              </table>
              <p>
                לאחר סנכרון סגרו ופתחו את הכרטיס כדי לראות את המחירים והמק״ט
                המעודכנים בשדות המוצר.
              </p>
            </div>
          )}
          <p>
            מצב:{" "}
            {link
              ? {
                  pending: "מוכן לבדיקת סנכרון",
                  connected: "מחובר",
                  error: "נדרש טיפול",
                  "sync-pending": "ממתין לסנכרון",
                  "source-inactive": "מוצר המקור אינו פעיל",
                }[link.status] || link.status
              : "טרם חובר"}
          </p>
          {link?.lastSyncAt && (
            <p>
              סנכרון אחרון: {new Date(link.lastSyncAt).toLocaleString("he-IL")}
            </p>
          )}
          {link?.error && <p role="alert">{link.error}</p>}
        </>
      )}
      {notice && <p role="status">{notice}</p>}
    </fieldset>
  );
}
export function ChannelConsole() {
  const [data, setData] = useState(null),
    [error, setError] = useState(""),
    [config, setConfig] = useState({ provider: "", merchantId: "" }),
    [notice, setNotice] = useState("");
  const load = async () => {
    setData(await api("status"));
    if (home) setConfig(await api("config"));
  };
  useEffect(() => {
    load().catch((e) => setError(e.message));
  }, []);
  return (
    <section className="admin-channel-console">
      <h2>{home ? "סליקה וחיבור ל-SHEM" : "חיבור וסנכרון עם SHIMI"}</h2>
      {error && <p role="alert">{error}</p>}
      {data && (
        <>
          <p>
            חיבור שרתים:{" "}
            {data.connected ? "הוגדר - בדיקה לכל בקשה" : "טרם הוגדר"} | סליקה
            בפועל:{" "}
            {data.paymentReady ? "מתאם הוגדר - נדרשת בדיקת ספק" : "לא פעילה"}
          </p>
          <button onClick={() => load().catch((e) => setError(e.message))}>
            רענון מצב
          </button>
          {home && (
            <form
              onSubmit={async (e) => {
                e.preventDefault();
                try {
                  setConfig(
                    await adminApi("channel/config", {
                      method: "PUT",
                      body: JSON.stringify(config),
                    }),
                  );
                  setNotice(
                    "פרטי הספק נשמרו. חיוב יופעל לאחר חיבור מתאם ובדיקת ספק.",
                  );
                } catch (e) {
                  setError(e.message);
                }
              }}
            >
              <h3>פרטי ספק הסליקה</h3>
              <label>
                שם ספק
                <input
                  value={config.provider || ""}
                  onChange={(e) =>
                    setConfig({ ...config, provider: e.target.value })
                  }
                />
              </label>
              <label>
                מזהה בית עסק
                <input
                  dir="ltr"
                  value={config.merchantId || ""}
                  onChange={(e) =>
                    setConfig({ ...config, merchantId: e.target.value })
                  }
                />
              </label>
              <label>
                שם העסק שגובה את התשלום
                <input
                  value={config.collectorName || ""}
                  onChange={(e) =>
                    setConfig({ ...config, collectorName: e.target.value })
                  }
                />
              </label>
              <p>
                פרטי החיבור הסודיים מוזנים בשרת בלבד. שמירת פרטי הספק אינה
                מפעילה חיוב.
              </p>
              <button>שמירת פרטים</button>
            </form>
          )}
          {data.links.some((l) => l.demo) && (
            <div className="channel-demo-guide">
              <h3>בדיקת קישור לדוגמה</h3>
              <p>
                במוצרים פתחו את{" "}
                {home
                  ? "סריג בייסיק - הדגמת סנכרון"
                  : "הדגמת סנכרון SHIMI - מוצר מקור"}
                . מוצר המקור נמצא ב-SHEM, והקישור שלו הוכן בכרטיס SHIMI עם מיפוי
                מידות M ו-L. לחצו על סנכרון בכרטיס כדי לבדוק את החיבור. שינוי שם
                ותמונה נשאר מקומי; מק״ט ומחירים מגיעים מ-SHEM.
              </p>
            </div>
          )}
          <h3>מוצרים מקושרים</h3>
          {!data.links.length && (
            <p>טרם נוצרו חיבורים. פתחו כרטיס מוצר כדי ליצור חיבור.</p>
          )}
          {data.links.map((l) => (
            <p key={l.id}>
              <a href="#/admin/products">{l.id}</a> | {l.status} |{" "}
              {l.error || "ללא שגיאה"}
            </p>
          ))}
          <h3>עסקאות ובקרת סנכרון</h3>
          {!data.sessions.length && <p>אין עסקאות.</p>}
          {data.sessions.map((s) => (
            <div key={s.id}>
              <p>
                {s.source} | {s.orderId} | ₪{(s.amountCents / 100).toFixed(2)} |{" "}
                {s.status} | {s.error}
              </p>
              {s.status === "paid-awaiting-sync" && (
                <button
                  onClick={async () => {
                    try {
                      await api("reconcile", { id: s.id });
                      await load();
                    } catch (e) {
                      setError(e.message);
                    }
                  }}
                >
                  ניסיון עדכון לתשלום מאומת
                </button>
              )}
            </div>
          ))}
        </>
      )}
      {notice && <p role="status">{notice}</p>}
    </section>
  );
}
