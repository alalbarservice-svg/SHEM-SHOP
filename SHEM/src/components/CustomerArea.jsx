import { useEffect, useState } from "react";
import { money } from "../commerce";
import { experienceEvent } from "../experience";
const labels = {
  draft: "הזמנה בהכנה",
  new: "הזמנה התקבלה",
  processing: "בהכנה למשלוח",
  shipped: "יצאה למשלוח",
  completed: "הושלמה",
  cancelled: "בוטלה",
};
const paymentLabels = {
  paid: "התשלום אושר",
  unpaid: "טרם התקבל תשלום",
  pending: "ממתינים לאישור תשלום",
  failed: "התשלום לא הושלם",
};
async function request(path, options = {}) {
  const r = await fetch(path, options);
  let d;
  try {
    d = await r.json();
  } catch {
    throw Error("לא ניתן לקבל מידע כרגע. נסו לרענן.");
  }
  if (!r.ok) throw Error(d.error || "הפעולה לא הושלמה.");
  return d;
}
function InvoiceAccess({ order, token }) {
  const [blob, setBlob] = useState(""),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  useEffect(
    () => () => {
      if (blob) URL.revokeObjectURL(blob);
    },
    [blob],
  );
  if (!order.invoiceReady)
    return (
      <p>
        {order.paymentStatus === "paid"
          ? "חשבונית העסקה בהכנה. היא תופיע כאן כשהמסמך יהיה זמין."
          : "חשבונית העסקה תהיה זמינה לאחר אישור התשלום."}
      </p>
    );
  const url = `/api/customer/orders/${encodeURIComponent(order.id)}/invoice`;
  return (
    <div className="account-invoice">
      {token ? (
        <>
          {!blob && (
            <button
              className="button outline"
              disabled={busy}
              onClick={async () => {
                setBusy(true);
                setError("");
                try {
                  const r = await fetch(url, {
                    headers: { authorization: "Bearer " + token },
                  });
                  if (!r.ok) throw Error("לא ניתן לפתוח את המסמך כרגע.");
                  setBlob(URL.createObjectURL(await r.blob()));
                } catch (e) {
                  setError(e.message);
                } finally {
                  setBusy(false);
                }
              }}
            >
              {busy ? "מכינים את הקובץ..." : "פתיחת חשבונית העסקה"}
            </button>
          )}
          {blob && (
            <>
              <a href={blob} target="_blank" rel="noopener noreferrer">
                צפייה בחשבונית העסקה
              </a>
              <a href={blob} download={`SHEM-invoice-${order.id}.pdf`}>
                הורדת PDF
              </a>
            </>
          )}
        </>
      ) : (
        <>
          <a href={url} target="_blank" rel="noopener noreferrer">
            צפייה בחשבונית העסקה
          </a>
          <a href={url + "?download=1"}>הורדת PDF</a>
        </>
      )}
      {error && <p role="alert">{error}</p>}
    </div>
  );
}
export function OrderDetails({ order, token = "", whatsapp = "972529586766" }) {
  const status = order.status || "new",
    paid = order.paymentStatus === "paid",
    support = `https://wa.me/${whatsapp}?text=${encodeURIComponent("שלום, מבקש עזרה לגבי הזמנת SHEM מספר " + order.id)}`;
  return (
    <article className="account-order" aria-label={"הזמנה " + order.id}>
      <header>
        <div>
          <span className="eyebrow">SHEM / YOUR ORDER</span>
          <h2>
            הזמנה <b dir="ltr">{order.id}</b>
          </h2>
          <time>
            {order.createdAt
              ? new Date(order.createdAt).toLocaleDateString("he-IL")
              : ""}
          </time>
        </div>
        <span className="order-status">{labels[status] || "בטיפול"}</span>
      </header>
      <p className="payment-status">
        {paymentLabels[order.paymentStatus] || "בודקים את מצב התשלום"}
      </p>
      {!paid && (
        <p>אם כבר ניסית לשלם, יש לוודא את מצב העסקה לפני ניסיון נוסף.</p>
      )}
      <ol className="order-timeline">
        {[
          ["new", "הזמנה התקבלה"],
          ["processing", "הכנה"],
          ["shipped", "משלוח"],
          ["completed", "השלמה"],
        ].map(([s, l], i) => (
          <li
            key={s}
            className={
              status !== "cancelled" &&
              ["new", "processing", "shipped", "completed"].indexOf(status) >= i
                ? "reached"
                : ""
            }
          >
            {l}
          </li>
        ))}
      </ol>
      <div className="order-items">
        {order.items.map((i, k) => (
          <div key={k}>
            <span>
              {i.name}
              <small>
                מק״ט <b dir="ltr">{i.sku}</b> · {i.size} · {i.color} · כמות{" "}
                {i.qty}
              </small>
            </span>
            <b>{money((i.lineCents ?? i.priceCents * i.qty) / 100)}</b>
          </div>
        ))}
      </div>
      <dl className="account-totals">
        <dt>סכום ההזמנה</dt>
        <dd>{money(order.totalCents / 100)}</dd>
        <dt>משלוח</dt>
        <dd>
          {order.shippingLabel ||
            (order.shippingMethod === "express"
              ? "שליח עד הבית - יום עסקים אחד"
              : "שליח עד הבית - משלוח רגיל")}
          {order.shippingCents !== undefined
            ? " · " + money(order.shippingCents / 100)
            : ""}
        </dd>
      </dl>
      <p className="muted">המחירים כוללים מע״מ.</p>
      <section className="shipment-info">
        <h3>פרטי משלוח</h3>
        <p>
          {order.customer?.name} · {order.customer?.phone}
        </p>
        <p>
          {order.customer?.street}
          {order.customer?.apartment
            ? " · " + order.customer.apartment
            : ""}, {order.customer?.city} {order.customer?.postcode}
        </p>
        {order.estimatedDelivery && (
          <p>
            הגעה משוערת:{" "}
            {new Date(order.estimatedDelivery + "T12:00:00").toLocaleDateString(
              "he-IL",
            )}
          </p>
        )}
        {order.carrier && <p>חברת שילוח: {order.carrier}</p>}
        {order.tracking && (
          <p>
            מספר מעקב: <b dir="ltr">{order.tracking}</b>
          </p>
        )}
        {order.trackingUrl ? (
          <a
            className="text-link"
            href={order.trackingUrl}
            target="_blank"
            rel="noopener noreferrer"
          >
            מעקב באתר חברת השילוח ↗
          </a>
        ) : (
          <p>
            עדכוני המשלוח מוצגים לפי המידע שהחנות קיבלה. קישור מעקב יופיע כשיהיה
            זמין.
          </p>
        )}
        {order.shipmentHistory?.length > 0 && (
          <details>
            <summary>היסטוריית עדכונים</summary>
            <ul>
              {order.shipmentHistory.map((h, i) => (
                <li key={i}>
                  {labels[h.status] || h.status} ·{" "}
                  {new Date(h.at).toLocaleString("he-IL")}
                </li>
              ))}
            </ul>
          </details>
        )}
      </section>
      <InvoiceAccess order={order} token={token} />
      <div className="account-actions">
        <a
          className="button outline"
          href={support}
          target="_blank"
          rel="noopener noreferrer"
        >
          תמיכה והחלפות
        </a>
        <a className="text-link" href="#/cancel">
          בקשת החזרה או ביטול
        </a>
      </div>
    </article>
  );
}
export function CustomerArea({ operations = {} }) {
  const params = new URLSearchParams(location.hash.split("?")[1] || ""),
    saved = (() => {
      try {
        return JSON.parse(sessionStorage.getItem("shem-recent-order")) || {};
      } catch {
        return {};
      }
    })();
  const [credentials, setCredentials] = useState({
      orderId: params.get("order") || saved.orderId || "",
      viewToken: params.get("token") || saved.viewToken || "",
    }),
    [guest, setGuest] = useState(null),
    [data, setData] = useState(null),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [refresh, setRefresh] = useState(0);
  useEffect(() => {
    let active = true;
    const load = async () => {
      try {
        const me = await request("/api/customer/me");
        if (active) {
          setData(me);
          setError("");
        }
      } catch (e) {
        if (active) setError(e.message);
      }
    };
    load();
    const id = setInterval(load, 30000);
    return () => {
      active = false;
      clearInterval(id);
    };
  }, [refresh]);
  const openGuest = async () => {
    if (!credentials.orderId || !credentials.viewToken) {
      setError("יש להזין מספר הזמנה וקוד גישה מהקישור האישי.");
      return;
    }
    setBusy(true);
    setError("");
    try {
      const order = await request(
        "/api/customer/orders/" + encodeURIComponent(credentials.orderId),
        { headers: { authorization: "Bearer " + credentials.viewToken } },
      );
      setGuest(order);
      sessionStorage.setItem("shem-recent-order", JSON.stringify(credentials));
    } catch (e) {
      setError(e.message);
      setGuest(null);
    } finally {
      setBusy(false);
    }
  };
  useEffect(() => {
    if (!params.get("token") && !saved.viewToken) return;
    let active = true;
    request("/api/customer/orders/" + encodeURIComponent(credentials.orderId), {
      headers: { authorization: "Bearer " + credentials.viewToken },
    })
      .then((o) => {
        if (active) setGuest(o);
      })
      .catch((e) => {
        if (active) setError(e.message);
      });
    return () => {
      active = false;
    };
  }, []);
  useEffect(() => {
    if (!guest) return;
    let active = true;
    const id = setInterval(
      () =>
        request("/api/customer/orders/" + encodeURIComponent(guest.id), {
          headers: { authorization: "Bearer " + credentials.viewToken },
        })
          .then((o) => {
            if (active) setGuest(o);
          })
          .catch(() => {}),
      30000,
    );
    return () => {
      active = false;
      clearInterval(id);
    };
  }, [guest?.id, credentials.viewToken]);
  return (
    <section className="page customer-area">
      <p className="eyebrow">SHEM / YOUR SPACE</p>
      <h1>המרחב שלך</h1>
      <p>הזמנות, משלוחים, חשבוניות והטבות, במקום אחד.</p>
      {error && (
        <p role="alert" className="error">
          {error}{" "}
          <button
            className="text-link"
            onClick={() => setRefresh((r) => r + 1)}
          >
            ניסיון חוזר
          </button>
        </p>
      )}
      {!data && !error && <p role="status">טוענים את האזור שלך...</p>}
      {data?.signedIn ? (
        <>
          <div className="account-toolbar">
            <span dir="ltr">{data.email}</span>
            <button
              className="text-link"
              onClick={() => {
                setRefresh((r) => r + 1);
                if (guest) openGuest();
              }}
            >
              רענון
            </button>
            <a href="/signout-with-chatgpt?return_to=%2F%23%2Faccount">יציאה</a>
          </div>
          {data.member && (
            <section className="member-summary">
              <h2>המועדון שלך</h2>
              <p>
                כרטיס <b dir="ltr">{data.member.cardNumber}</b> ·{" "}
                {data.member.tier === "vip" ? "VIP" : "חבר מועדון"}
              </p>
              <div className="member-balances">
                <p>
                  יתרה זמינה{" "}
                  <strong>{money(data.member.balanceCents / 100)}</strong>
                </p>
                <p>
                  צבירה בהמתנה{" "}
                  <strong>{money(data.member.pendingCents / 100)}</strong>
                </p>
              </div>
              {data.member.benefits && <p>{data.member.benefits}</p>}
              <p>זמינות ומימוש ההטבות כפופים לכללי המועדון.</p>
            </section>
          )}
          <section className="account-alerts">
            <h2>העדכונים שלך</h2>
            {data.notifications.length ? (
              data.notifications.map((n) => (
                <p key={n.id}>
                  <a href={"#/product/" + n.productId}>
                    {n.title} · {n.size} · {n.color} זמין כעת
                  </a>
                </p>
              ))
            ) : (
              <p>אין כרגע עדכוני חזרה למלאי.</p>
            )}
            {data.watches.map((w) => (
              <p key={w.id}>
                התראה שמורה למידה {w.size} · {w.color}{" "}
                <button
                  className="text-link"
                  onClick={async () => {
                    try {
                      await request(
                        "/api/customer/watches/" + encodeURIComponent(w.id),
                        {
                          method: "DELETE",
                          headers: { "content-type": "application/json" },
                        },
                      );
                      setRefresh((r) => r + 1);
                    } catch (e) {
                      setError(e.message);
                    }
                  }}
                >
                  הסרת התראה
                </button>
              </p>
            ))}
            <label>
              <input
                type="checkbox"
                checked={data.preferences.siteNotifications !== false}
                onChange={async (e) => {
                  try {
                    await request("/api/customer/preferences", {
                      method: "PUT",
                      headers: { "content-type": "application/json" },
                      body: JSON.stringify({
                        siteNotifications: e.target.checked,
                      }),
                    });
                    setRefresh((r) => r + 1);
                  } catch (e) {
                    setError(e.message);
                  }
                }}
              />
              הצגת התראות חזרה למלאי באזור שלי
            </label>
            <p className="muted">
              ההתראות מופיעות באתר. לא נשלחות כרגע הודעות מייל או וואטסאפ
              אוטומטיות.
            </p>
          </section>
          <h2>ההזמנות שלך</h2>
          {data.orders.length ? (
            data.orders.map((o) => (
              <OrderDetails
                key={o.id}
                order={o}
                whatsapp={operations.whatsapp}
              />
            ))
          ) : (
            <div className="account-empty">
              <p>
                לא נמצאו הזמנות לכתובת המייל של החשבון המחובר. רכשתם עם כתובת
                אחרת? פתחו את הקישור האישי להזמנה.
              </p>
              <a className="button dark" href="#/shop">
                לגלות את הקולקציה
              </a>
            </div>
          )}
        </>
      ) : (
        data && (
          <section className="account-entry">
            <h2>כניסה לאזור שלי</h2>
            <p>
              התחברו כדי לראות הזמנות שבוצעו עם כתובת המייל המאומתת של החשבון.
              אפשר לרכוש בחנות גם בלי לפתוח חשבון.
            </p>
            <a
              className="button dark"
              href="/signin-with-chatgpt?return_to=%2F%23%2Faccount"
            >
              כניסה עם ChatGPT
            </a>
          </section>
        )
      )}
      <details className="guest-access" open={!!guest}>
        <summary>רכשתם כאורחים? פתיחת הזמנה אישית</summary>
        <p>הקישור האישי מוצג בסיום הרכישה. שמרו אותו אצלכם ואל תשתפו אותו.</p>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            openGuest();
          }}
        >
          <label>
            מספר הזמנה
            <input
              required
              autoComplete="off"
              value={credentials.orderId}
              onChange={(e) => {
                setGuest(null);
                setCredentials({ ...credentials, orderId: e.target.value });
              }}
            />
          </label>
          <label>
            קוד גישה אישי
            <input
              required
              type="password"
              autoComplete="off"
              value={credentials.viewToken}
              onChange={(e) => {
                setGuest(null);
                setCredentials({ ...credentials, viewToken: e.target.value });
              }}
            />
          </label>
          <button className="button outline" disabled={busy}>
            {busy ? "טוענים..." : "פתיחת ההזמנה"}
          </button>
        </form>
        {guest && (
          <>
            <OrderDetails
              order={guest}
              token={credentials.viewToken}
              whatsapp={operations.whatsapp}
            />
            <button
              className="text-link"
              onClick={() => {
                setGuest(null);
                setCredentials({ orderId: "", viewToken: "" });
                sessionStorage.removeItem("shem-recent-order");
                location.hash = "/account";
              }}
            >
              סגירת ההזמנה האישית במכשיר הזה
            </button>
          </>
        )}
      </details>
      <a
        href="#/help"
        className="text-link"
        onClick={() => experienceEvent("help_open", { route: "account" })}
      >
        למרכז העזרה
      </a>
    </section>
  );
}
