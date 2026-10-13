import { Management } from "./Management";
import { useEffect, useState, useRef } from "react";
import { adminApi } from "../admin-api";
import { money } from "../commerce";
import { products as examples } from "../catalogue";
import {
  List,
  X,
  Package,
  Users,
  Receipt,
  Tag,
  SlidersHorizontal,
  House,
  Storefront,
} from "@phosphor-icons/react";
import "./admin.css";
import { AdminOperations, exportCSV } from "./AdminOperations";
import { Integrations } from "./Integrations";
const tabs = [
  ["overview", "סקירה", House],
  ["products", "מוצרים", Package],
  ["brands", "מותגים", Storefront],
  ["collections", "קולקציות", Package],
  ["inventory", "מלאי", Package],
  ["orders", "הזמנות", Receipt],
  ["customers", "לקוחות מהזמנות", Users],
  ["crm", "כרטיסי לקוח", Users],
  ["cards", "כרטיסי מועדון", Users],
  ["requests", "שירות והחזרות", Receipt],
  ["members", "מועדון חברים", Users],
  ["promotions", "מבצעים", Tag],
  ["content", "תוכן האתר", SlidersHorizontal],
  ["experience", "עזרה ושירות עצמי", Users],
  ["settings", "הגדרות", SlidersHorizontal],
  ["business", "פרטי העסק", Storefront],
  ["control", "בקרה והשקה", House],
  ["audit", "יומן פעולות", Receipt],
  ["integrations", "סליקה וחשבוניות", Receipt],
];
const blank = {
  he: "",
  en: "",
  brand: "",
  cat: "clothing",
  price: 199,
  previousPrice: "",
  stock: 0,
  sizes: ["S", "M", "L", "XL"],
  color: "black",
  active: false,
  imageUrl: "",
  sku: "",
  description: "",
  material: "",
  care: "",
  demo: false,
};
const initialClub = {
  title: "הסטייל שלך. המעגל שלך.",
  description: "הצטרפו למועדון SHEM",
  benefits: "גישה מוקדמת לקולקציות\nעדכונים על פריטים נבחרים",
  enabled: false,
  privacyUrl: "",
};
const initialStore = {
  heroTitle: "DRESS\nWITH INTENT.",
  heroSubtitle: "הסטייל שלך. בלי פשרות.",
  contactEmail: "your.shem88@gmail.com",
  warehouseSystem: "",
  brands: [
    "Dior",
    "Loro Piana",
    "Givenchy",
    "Amiri",
    "Ralph Lauren",
    "Essential",
  ],
};
export function Admin({ route }) {
  const section =
    route.split("/")[2] === "club"
      ? "members"
      : route.split("/")[2] || "overview";
  const [identity, setIdentity] = useState(null),
    [session, setSession] = useState(null),
    [loading, setLoading] = useState(true),
    [error, setError] = useState(""),
    [notice, setNotice] = useState(""),
    [busy, setBusy] = useState(false),
    [mobile, setMobile] = useState(false);
  const [products, setProducts] = useState([]),
    [orders, setOrders] = useState([]),
    [members, setMembers] = useState([]),
    [promotions, setPromotions] = useState([]),
    [club, setClub] = useState(initialClub),
    [store, setStore] = useState(initialStore),
    [query, setQuery] = useState(""),
    [edit, setEdit] = useState(null),
    [promo, setPromo] = useState(null),
    [order, setOrder] = useState(null);
  const managed = [
    "products",
    "collections",
    "inventory",
    "orders",
    "crm",
    "cards",
    "requests",
    "members",
    "promotions",
    "content",
    "settings",
    "business",
    "control",
    "audit",
    "integrations",
    "experience",
  ].includes(section);
  const load = async ({ silent = false } = {}) => {
    if (!silent) setLoading(true);
    setError("");
    try {
      const who = await fetch("/api/session").then((r) => r.json());
      setIdentity(who);
      const auth = await adminApi("session");
      setSession(auth);
      const values = await Promise.all([
        adminApi("products"),
        adminApi("orders"),
        adminApi("members"),
        adminApi("promotions"),
        adminApi("settings"),
      ]);
      setProducts(values[0]);
      setOrders(values[1]);
      setMembers(values[2]);
      setPromotions(values[3]);
      setClub({ ...initialClub, ...values[4].club });
      setStore({ ...initialStore, ...values[4].store });
    } catch (e) {
      setError(e.message);
      if (e.status === 401 || e.status === 403) setSession(null);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    load();
  }, []);
  useEffect(() => {
    setEdit(null);
    setOrder(null);
    setPromo(null);
    setQuery("");
    setNotice("");
    setMobile(false);
  }, [section]);
  const action = async (fn, message) => {
    if (busy) return;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await fn();
      setNotice(message);
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };
  const saveProduct = (e) => {
    e.preventDefault();
    action(async () => {
      const saved = await adminApi(
        `products${edit.id ? "/" + encodeURIComponent(edit.id) : ""}`,
        { method: edit.id ? "PUT" : "POST", body: JSON.stringify(edit) },
      );
      setProducts((prev) => [saved, ...prev.filter((p) => p.id !== saved.id)]);
      setEdit(null);
    }, "המוצר נשמר בשרת.");
  };
  const saveSetting = (key, value) =>
    action(
      () =>
        adminApi("settings", {
          method: "PUT",
          body: JSON.stringify({ key, value }),
        }),
      "השינויים נשמרו בשרת.",
    );
  const matched = products.filter((p) =>
    `${p.he} ${p.brand} ${p.id}`.toLowerCase().includes(query.toLowerCase()),
  );
  const completed = orders.filter(
    (o) => o.paymentStatus === "paid" && o.status !== "cancelled",
  );
  useEffect(() => {
    if (!edit && !promo && !order) return;
    const previous = document.activeElement,
      overflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const modal = document.querySelector(".admin-modal");
    const nodes = () =>
      [
        ...modal.querySelectorAll("button,input,textarea,select,a[href]"),
      ].filter((n) => !n.disabled);
    nodes()[0]?.focus();
    const key = (e) => {
      if (e.key === "Escape") {
        setEdit(null);
        setPromo(null);
        setOrder(null);
      }
      if (e.key === "Tab") {
        const all = nodes(),
          first = all[0],
          last = all.at(-1);
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last?.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first?.focus();
        }
      }
    };
    document.addEventListener("keydown", key);
    return () => {
      document.removeEventListener("keydown", key);
      document.body.style.overflow = overflow;
      previous?.focus();
    };
  }, [!!edit, !!promo, !!order]);
  useEffect(() => {
    if (!mobile) return;
    document.querySelector(".admin-mobile-close")?.focus();
    const onKey = (e) => {
      if (e.key === "Escape") setMobile(false);
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [mobile]);
  return (
    <div className="admin-root" dir="rtl">
      <aside
        inert={edit || promo || order ? true : undefined}
        className={`admin-sidebar ${mobile ? "open" : ""}`}
      >
        <a className="admin-brand" href="#/admin">
          SHEM<span>STORE MANAGEMENT</span>
        </a>
        <button
          className="admin-mobile-close"
          aria-label="סגירת ניווט"
          onClick={() => setMobile(false)}
        >
          <X />
        </button>
        <nav aria-label="ניהול החנות">
          {tabs.map(([key, label, Icon]) => (
            <a
              key={key}
              href={`#/admin/${key}`}
              className={section === key ? "selected" : ""}
              aria-current={section === key ? "page" : undefined}
            >
              <Icon size={20} />
              {label}
            </a>
          ))}
        </nav>
        <a className="admin-store-link" href="#/">
          תצוגת החנות
        </a>
      </aside>
      <div
        className="admin-work"
        inert={edit || promo || order || mobile ? true : undefined}
      >
        <header className="admin-header">
          <button
            className="admin-mobile-open"
            aria-label="פתיחת ניווט ניהול"
            onClick={() => setMobile(true)}
          >
            <List />
          </button>
          <div>
            <p>SHEM / MANAGEMENT</p>
            <h1>{tabs.find((t) => t[0] === section)?.[1] || "ניהול החנות"}</h1>
          </div>
          <div className="admin-identity">
            <span>{session ? "חשבון מנהל מחובר" : "כתובת מנהל מבוקשת"}</span>
            <b dir="ltr">
              {session?.signedInEmail ||
                session?.contactEmail ||
                "your.shem88@gmail.com"}
            </b>
          </div>
        </header>
        <main className="admin-main">
          {session?.localPreview && (
            <p className="admin-note">
              סביבת בדיקה מקומית. הנתונים כאן נפרדים מהחנות המתפרסמת.
            </p>
          )}
          {loading ? (
            <p role="status" className="admin-empty">
              טוען את נתוני החנות...
            </p>
          ) : (
            <>
              {error && (
                <div role="alert" className="admin-alert">
                  {error}
                  <button onClick={load}>ניסיון חוזר</button>
                </div>
              )}
              {notice && (
                <p className="admin-success" role="status">
                  {notice}
                </p>
              )}
              {!session ? (
                <div className="admin-empty">
                  <h2>כניסה לניהול החנות</h2>
                  {identity?.signedIn && (
                    <div>
                      <p>החשבון מחובר. הרשאת הניהול טרם שויכה אליו.</p>
                      <p>מזהה החשבון לשיוך הרשאה:</p>
                      <code dir="ltr" style={{ overflowWrap: "anywhere" }}>
                        {identity.userId}
                      </code>
                      <p>
                        חשבון מחובר: <span dir="ltr">{identity.email}</span>
                      </p>
                    </div>
                  )}
                  <p>ממשק הניהול מוגן על ידי חשבון האתר והרשאה בשרת.</p>
                  <a
                    className="admin-primary"
                    href="/signin-with-chatgpt?return_to=%2F%23%2Fadmin"
                  >
                    כניסה עם ChatGPT
                  </a>
                  <p>
                    כתובת המנהל המבוקשת:{" "}
                    <span dir="ltr">your.shem88@gmail.com</span>
                  </p>
                </div>
              ) : (
                <>
                  {managed ? (
                    <Management
                      key={section}
                      section={section}
                      onChange={() => load({ silent: true })}
                    />
                  ) : (
                    <>
                      {[
                        "collections",
                        "crm",
                        "cards",
                        "requests",
                        "business",
                        "control",
                        "audit",
                      ].includes(section) && (
                        <AdminOperations
                          key={section}
                          section={section}
                          products={products}
                          members={members}
                          orders={orders}
                        />
                      )}
                      {section === "integrations" && <Integrations />}
                      {section === "overview" && (
                        <>
                          <div className="admin-toolbar">
                            <button disabled={busy} onClick={load}>
                              רענון כל הנתונים מהשרת
                            </button>
                          </div>
                          <div className="admin-metrics">
                            {[
                              [products.length, "מוצרים בקטלוג"],
                              [orders.length, "הזמנות"],
                              [
                                members.filter((m) => m.status === "active")
                                  .length,
                                "חברי מועדון",
                              ],
                              [
                                money(
                                  completed.reduce(
                                    (n, o) => n + Number(o.total || 0),
                                    0,
                                  ),
                                ),
                                "הכנסות מהזמנות ששולמו",
                              ],
                            ].map(([value, label]) => (
                              <article key={label}>
                                <span>{label}</span>
                                <strong>{value}</strong>
                              </article>
                            ))}
                          </div>
                          <div className="admin-panels">
                            <section className="admin-panel">
                              <h2>פעולות מהירות</h2>
                              <div className="admin-quick">
                                <a href="#/admin/products">ניהול הקטלוג</a>
                                <a href="#/admin/members">
                                  עריכת מועדון החברים
                                </a>
                                <a href="#/admin/content">עריכת תוכן האתר</a>
                              </div>
                            </section>
                            <section className="admin-panel">
                              <h2>חיבורים להשקה</h2>
                              <dl className="admin-integrations">
                                <dt>מסד נתונים</dt>
                                <dd>מחובר</dd>
                                <dt>אחסון תמונות</dt>
                                <dd>מחובר</dd>
                                <dt>מערכת מחסן</dt>
                                <dd>ממתינה לפרטי מערכת וחיבור</dd>
                                <dt>סליקה והזמנות</dt>
                                <dd>טרם חוברו</dd>
                                <dt>מימוש קופונים</dt>
                                <dd>חישוב קופון בסל מחובר; סליקה טרם חוברה</dd>
                                <dt>העברת בעלות לחשבון החדש</dt>
                                <dd>טרם בוצעה</dd>
                              </dl>
                            </section>
                          </div>
                        </>
                      )}
                      {(section === "products" || section === "inventory") && (
                        <section className="admin-panel">
                          <div className="admin-toolbar">
                            <input
                              aria-label="חיפוש מוצרים"
                              placeholder="חיפוש לפי פריט, מותג או מזהה"
                              value={query}
                              onChange={(e) => setQuery(e.target.value)}
                            />
                            <button
                              disabled={!matched.length}
                              onClick={() =>
                                exportCSV(
                                  "shem-products",
                                  matched.map((p) => ({
                                    sku: p.sku,
                                    name: p.he,
                                    brand: p.brand,
                                    price: p.price,
                                    previousPrice: p.previousPrice,
                                    stock: p.stock,
                                    active: p.active,
                                  })),
                                )
                              }
                            >
                              ייצוא מוצרים CSV
                            </button>
                            {section === "products" && (
                              <button
                                className="admin-primary"
                                onClick={() => setEdit({ ...blank })}
                              >
                                מוצר חדש
                              </button>
                            )}
                          </div>
                          {products.length === 0 ? (
                            <div className="admin-empty">
                              <h2>הקטלוג שלך מתחיל כאן</h2>
                              <p>
                                אפשר להוסיף מוצר אמיתי או לטעון את מוצרי ההדגמה
                                לעריכה. מלאי מוצרי ההדגמה מתחיל ב-0.
                              </p>
                              <button
                                disabled={busy}
                                onClick={() =>
                                  action(async () => {
                                    const saved = [];
                                    for (const p of examples)
                                      saved.push(
                                        await adminApi("products", {
                                          method: "POST",
                                          body: JSON.stringify({
                                            ...p,
                                            image: p.image || p.id,
                                            stock: 0,
                                            active: true,
                                            demo: true,
                                          }),
                                        }),
                                      );
                                    setProducts(saved);
                                  }, "מוצרי ההדגמה נטענו. יש לעדכן נתונים ותמונות לפני מכירה.")
                                }
                              >
                                טעינת מוצרי הדגמה
                              </button>
                            </div>
                          ) : (
                            <div className="admin-table-wrap">
                              <table>
                                <thead>
                                  <tr>
                                    <th>פריט</th>
                                    <th>מותג</th>
                                    <th>מחיר</th>
                                    <th>מלאי</th>
                                    <th>מצב</th>
                                    <th>פעולה</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {matched.map((p) => (
                                    <tr key={p.id}>
                                      <td>
                                        <div className="admin-product-cell">
                                          {(p.imageUrl || p.image) && (
                                            <img
                                              src={
                                                p.imageUrl ||
                                                `/assets/${p.image}.png`
                                              }
                                              alt=""
                                            />
                                          )}
                                          <span>
                                            {p.he}
                                            <small>{p.id}</small>
                                          </span>
                                        </div>
                                      </td>
                                      <td>{p.brand}</td>
                                      <td>{money(p.price)}</td>
                                      <td>
                                        <span
                                          className={
                                            p.stock === 0
                                              ? "admin-low-stock"
                                              : ""
                                          }
                                        >
                                          {p.stock}
                                        </span>
                                      </td>
                                      <td>{p.active ? "מוצג" : "מוסתר"}</td>
                                      <td>
                                        <button
                                          onClick={() => setEdit({ ...p })}
                                        >
                                          עריכה
                                        </button>
                                      </td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                              {matched.length === 0 && (
                                <p className="admin-empty">לא נמצאו מוצרים.</p>
                              )}
                            </div>
                          )}
                        </section>
                      )}
                      {section === "brands" && (
                        <section className="admin-panel">
                          <h2>המותגים בחנות</h2>
                          <p>
                            רשימה זו מוצגת בניווט המותגים ובמסנני החנות. שיוך כל
                            מוצר למותג מתבצע בכרטיס המוצר.
                          </p>
                          <form
                            onSubmit={(e) => {
                              e.preventDefault();
                              saveSetting("store", store);
                            }}
                          >
                            <label>
                              מותגים - שורה לכל מותג
                              <textarea
                                value={(store.brands || []).join("\n")}
                                onChange={(e) =>
                                  setStore({
                                    ...store,
                                    brands: e.target.value
                                      .split("\n")
                                      .filter(Boolean),
                                  })
                                }
                              />
                            </label>
                            <button className="admin-primary" disabled={busy}>
                              שמירת מותגים
                            </button>
                          </form>
                        </section>
                      )}
                      {section === "orders" && (
                        <section className="admin-panel">
                          <h2>הזמנות החנות</h2>
                          <p className="admin-note">
                            יוצגו כאן הזמנות אמיתיות לאחר חיבור מערכת ההזמנות
                            והסליקה. אין כאן הזמנות או הכנסות מומצאות.
                          </p>
                          {orders.length === 0 ? (
                            <p className="admin-empty">אין הזמנות להצגה.</p>
                          ) : (
                            <div className="admin-table-wrap">
                              <table>
                                <thead>
                                  <tr>
                                    <th>מספר הזמנה</th>
                                    <th>לקוח</th>
                                    <th>סכום</th>
                                    <th>מצב</th>
                                    <th>פעולה</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {orders.map((o) => (
                                    <tr key={o.id}>
                                      <td>{o.id}</td>
                                      <td>{o.customer?.name}</td>
                                      <td>{money(o.total || 0)}</td>
                                      <td>{o.status}</td>
                                      <td>
                                        <button onClick={() => setOrder(o)}>
                                          פתיחה
                                        </button>
                                      </td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          )}
                        </section>
                      )}
                      {section === "customers" && (
                        <section className="admin-panel">
                          <h2>לקוחות</h2>
                          {orders.length === 0 ? (
                            <p className="admin-empty">
                              פרטי לקוחות יופיעו כאן כשיתקבלו הזמנות אמיתיות.
                            </p>
                          ) : (
                            <div className="admin-table-wrap">
                              <table>
                                <thead>
                                  <tr>
                                    <th>שם</th>
                                    <th>אימייל</th>
                                    <th>טלפון</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {[
                                    ...new Map(
                                      orders
                                        .filter((o) => o.customer?.email)
                                        .map((o) => [
                                          o.customer.email,
                                          o.customer,
                                        ]),
                                    ).values(),
                                  ].map((c) => (
                                    <tr key={c.email}>
                                      <td>{c.name}</td>
                                      <td>{c.email}</td>
                                      <td>{c.phone}</td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          )}
                        </section>
                      )}
                      {section === "members" && (
                        <>
                          <section className="admin-panel">
                            <h2>תוכן מועדון החברים</h2>
                            <form
                              onSubmit={(e) => {
                                e.preventDefault();
                                saveSetting("club", club);
                              }}
                            >
                              {[
                                ["title", "כותרת"],
                                ["description", "תיאור"],
                                ["benefits", "הטבות - שורה לכל הטבה"],
                                ["privacyUrl", "קישור למדיניות פרטיות"],
                              ].map(([key, label]) => (
                                <label key={key}>
                                  {label}
                                  {key === "description" ||
                                  key === "benefits" ? (
                                    <textarea
                                      value={club[key]}
                                      onChange={(e) =>
                                        setClub({
                                          ...club,
                                          [key]: e.target.value,
                                        })
                                      }
                                    />
                                  ) : (
                                    <input
                                      required={
                                        key === "title" ||
                                        (key === "privacyUrl" && club.enabled)
                                      }
                                      type="text"
                                      value={club[key]}
                                      onChange={(e) =>
                                        setClub({
                                          ...club,
                                          [key]: e.target.value,
                                        })
                                      }
                                    />
                                  )}
                                </label>
                              ))}
                              <label className="admin-check">
                                <input
                                  type="checkbox"
                                  checked={club.enabled}
                                  onChange={(e) =>
                                    setClub({
                                      ...club,
                                      enabled: e.target.checked,
                                    })
                                  }
                                />
                                הפעלת הרשמה אמיתית למועדון
                              </label>
                              <p className="admin-note">
                                להפעלת הרשמה נדרש קישור למדיניות פרטיות. הרשמות
                                חדשות נשמרות בשרת עם מועד ההסכמה.
                              </p>
                              <button className="admin-primary" disabled={busy}>
                                שמירת מועדון
                              </button>
                            </form>
                          </section>
                          <section className="admin-panel">
                            <h2>חברי המועדון ({members.length})</h2>
                            {members.length === 0 ? (
                              <p className="admin-empty">
                                עדיין אין חברים רשומים.
                              </p>
                            ) : (
                              <div className="admin-table-wrap">
                                <table>
                                  <thead>
                                    <tr>
                                      <th>אימייל</th>
                                      <th>תאריך הצטרפות</th>
                                      <th>מצב</th>
                                      <th>פעולה</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    {members.map((m) => (
                                      <tr key={m.id}>
                                        <td dir="ltr">{m.email}</td>
                                        <td>
                                          {new Date(
                                            m.created_at,
                                          ).toLocaleDateString("he-IL")}
                                        </td>
                                        <td>
                                          {m.status === "active"
                                            ? "פעיל"
                                            : "לא מקבל עדכונים"}
                                        </td>
                                        <td>
                                          <button
                                            disabled={busy}
                                            onClick={() =>
                                              action(async () => {
                                                await adminApi(
                                                  "members/" + m.id,
                                                  {
                                                    method: "PATCH",
                                                    body: JSON.stringify({
                                                      status: "unsubscribed",
                                                    }),
                                                  },
                                                );
                                                setMembers((ms) =>
                                                  ms.map((x) =>
                                                    x.id === m.id
                                                      ? {
                                                          ...x,
                                                          status:
                                                            "unsubscribed",
                                                        }
                                                      : x,
                                                  ),
                                                );
                                              }, "החבר הוסר מרשימת העדכונים.")
                                            }
                                          >
                                            הפסקת עדכונים
                                          </button>
                                        </td>
                                      </tr>
                                    ))}
                                  </tbody>
                                </table>
                              </div>
                            )}
                          </section>
                        </>
                      )}
                      {section === "promotions" && (
                        <section className="admin-panel">
                          <div className="admin-toolbar">
                            <h2>קודי מבצע</h2>
                            <button
                              className="admin-primary"
                              onClick={() =>
                                setPromo({
                                  code: "",
                                  discount: 10,
                                  endsAt: "",
                                  active: false,
                                })
                              }
                            >
                              קוד חדש
                            </button>
                          </div>
                          <p className="admin-note">
                            הקודים מחושבים בשרת בסל של מוצרים אמיתיים. סליקה טרם
                            חוברה. מחיר מבצע על מוצר מתעדכן בכרטיס המוצר.
                          </p>
                          {promotions.length === 0 ? (
                            <p className="admin-empty">לא הוגדרו קודים.</p>
                          ) : (
                            <div className="admin-table-wrap">
                              <table>
                                <thead>
                                  <tr>
                                    <th>קוד</th>
                                    <th>הנחה</th>
                                    <th>תוקף</th>
                                    <th>מצב</th>
                                    <th>פעולה</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {promotions.map((p) => (
                                    <tr key={p.id}>
                                      <td>{p.code}</td>
                                      <td>{p.discount}%</td>
                                      <td>{p.endsAt || "לא הוגדר"}</td>
                                      <td>
                                        {p.active ? "פעיל לפי התוקף" : "טיוטה"}
                                      </td>
                                      <td>
                                        <button
                                          onClick={() => setPromo({ ...p })}
                                        >
                                          עריכה
                                        </button>
                                      </td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          )}
                        </section>
                      )}
                      {section === "content" && (
                        <section className="admin-panel">
                          <h2>תוכן המסך הראשי</h2>
                          <form
                            onSubmit={(e) => {
                              e.preventDefault();
                              saveSetting("store", store);
                            }}
                          >
                            <label>
                              כותרת ראשית באנגלית
                              <textarea
                                dir="ltr"
                                required
                                value={store.heroTitle}
                                onChange={(e) =>
                                  setStore({
                                    ...store,
                                    heroTitle: e.target.value,
                                  })
                                }
                              />
                            </label>
                            <label>
                              שורת תיאור בעברית
                              <input
                                required
                                value={store.heroSubtitle}
                                onChange={(e) =>
                                  setStore({
                                    ...store,
                                    heroSubtitle: e.target.value,
                                  })
                                }
                              />
                            </label>
                            <button className="admin-primary" disabled={busy}>
                              שמירת תוכן האתר
                            </button>
                          </form>
                        </section>
                      )}
                      {section === "settings" && (
                        <section className="admin-panel">
                          <h2>הגדרות ותשתית</h2>
                          <form
                            onSubmit={(e) => {
                              e.preventDefault();
                              saveSetting("store", store);
                            }}
                          >
                            <label>
                              כתובת המנהל המבוקשת
                              <input
                                readOnly
                                value={session.contactEmail}
                                dir="ltr"
                              />
                            </label>
                            <label>
                              חשבון מחובר
                              <input
                                readOnly
                                value={
                                  session.signedInEmail ||
                                  "מזוהה על ידי הפלטפורמה"
                                }
                                dir="ltr"
                              />
                            </label>
                            <p className="admin-note">
                              האתר והשרת עדיין בבעלות חשבון ChatGPT הקיים. העברת
                              בעלות או הרשאת ניהול למייל החדש טרם בוצעה.
                            </p>
                            <label>
                              שם מערכת המחסן
                              <input
                                value={store.warehouseSystem}
                                onChange={(e) =>
                                  setStore({
                                    ...store,
                                    warehouseSystem: e.target.value,
                                  })
                                }
                              />
                            </label>
                            <p>
                              משלוח רגיל: 49₪, 5-7 ימי עסקים. משלוח מהיר: 199₪,
                              יום עסקים אחד. החזרות: תוך 14 ימים באיסוף שליח.
                            </p>
                            <p className="admin-note">
                              מלאי מנוהל כעת לכל מוצר. סנכרון מידות ומחסן דורש
                              חיבור למערכת המלאי הקיימת.
                            </p>
                            <button disabled={busy} className="admin-primary">
                              שמירת הגדרות
                            </button>
                          </form>
                        </section>
                      )}
                    </>
                  )}
                </>
              )}
            </>
          )}
        </main>
      </div>
      {edit && (
        <div className="admin-modal-backdrop">
          <section
            className="admin-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="product-edit-title"
          >
            <div className="admin-modal-title">
              <h2 id="product-edit-title">
                {edit.id ? "עריכת מוצר" : "מוצר חדש"}
              </h2>
              <button
                aria-label="סגירת עריכת מוצר"
                onClick={() => setEdit(null)}
              >
                <X />
              </button>
            </div>
            <form onSubmit={saveProduct}>
              <label>
                סוג פריט בעמוד המותג
                <select
                  value={edit.productType || ""}
                  onChange={(e) =>
                    setEdit({ ...edit, productType: e.target.value })
                  }
                >
                  {[
                    ["", "לפי סוג הפריט הקיים"],
                    ["shirts", "חולצות"],
                    ["trousers", "מכנסיים"],
                    ["knitwear", "סריגים"],
                    ["jackets", "ז׳קטים"],
                    ["shoes", "נעליים"],
                    ["accessories", "אקססוריז"],
                    ["clothing", "בגדים נוספים"],
                  ].map(([v, label]) => (
                    <option key={v} value={v}>
                      {label}
                    </option>
                  ))}
                </select>
              </label>
              <div className="admin-form-grid">
                {[
                  ["sku", 'מק"ט', "text"],
                  ["he", "שם בעברית", "text"],
                  ["en", "שם באנגלית", "text"],
                  ["brand", "מותג", "text"],
                  ["price", "מחיר", "number"],
                  ["previousPrice", "מחיר לפני מבצע", "number"],
                  ["stock", "מלאי כולל", "number"],
                  ["color", "צבע", "text"],
                ].map(([key, label, type]) => (
                  <label key={key}>
                    {label}
                    <input
                      required={["he", "price", "stock"].includes(key)}
                      min={key === "price" ? 0.01 : 0}
                      disabled={key === "stock" && !!edit.sizeStock}
                      step={key === "stock" ? 1 : "any"}
                      type={type}
                      value={edit[key] ?? ""}
                      onChange={(e) =>
                        setEdit({ ...edit, [key]: e.target.value })
                      }
                    />
                  </label>
                ))}
                <label>
                  קטגוריה
                  <select
                    value={edit.cat}
                    onChange={(e) => setEdit({ ...edit, cat: e.target.value })}
                  >
                    <option value="clothing">בגדים</option>
                    <option value="shoes">נעליים</option>
                    <option value="accessories">אקססוריז</option>
                  </select>
                </label>
                <label>
                  מידות - הפרדה בפסיק
                  <input
                    required
                    value={edit.sizes.join(",")}
                    onChange={(e) =>
                      setEdit({ ...edit, sizes: e.target.value.split(",") })
                    }
                  />
                </label>
                <label>
                  תמונת מוצר
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    disabled={busy}
                    onChange={(e) => {
                      const file = e.target.files?.[0];
                      if (file)
                        action(async () => {
                          const saved = await adminApi("media", {
                            method: "POST",
                            headers: { "content-type": file.type },
                            body: file,
                          });
                          setEdit((p) => ({ ...p, imageUrl: saved.imageUrl }));
                        }, "התמונה הועלתה. שמרו את המוצר כדי לשייך אותה.");
                    }}
                  />
                </label>
              </div>
              <label>
                תיאור מוצר
                <textarea
                  value={edit.description || ""}
                  onChange={(e) =>
                    setEdit({ ...edit, description: e.target.value })
                  }
                />
              </label>
              <label>
                הרכב בד
                <input
                  value={edit.material || ""}
                  onChange={(e) =>
                    setEdit({ ...edit, material: e.target.value })
                  }
                />
              </label>
              <label>
                הוראות טיפול
                <input
                  value={edit.care || ""}
                  onChange={(e) => setEdit({ ...edit, care: e.target.value })}
                />
              </label>
              <label className="admin-check">
                <input
                  type="checkbox"
                  checked={!!edit.sizeStock}
                  onChange={(e) =>
                    setEdit({
                      ...edit,
                      sizeStock: e.target.checked
                        ? Object.fromEntries(
                            edit.sizes.map((size) => [size.trim(), 0]),
                          )
                        : undefined,
                      stock: e.target.checked ? 0 : edit.stock,
                    })
                  }
                />
                ניהול מלאי לפי מידה
              </label>
              {edit.sizeStock && (
                <fieldset>
                  <legend>מלאי לכל מידה, הסכום מעדכן מלאי כולל</legend>
                  <div className="admin-form-grid">
                    {edit.sizes.map((raw) => {
                      const size = raw.trim();
                      return (
                        <label key={size}>
                          {size}
                          <input
                            type="number"
                            min="0"
                            step="1"
                            value={edit.sizeStock[size] ?? 0}
                            onChange={(e) => {
                              const sizes = Object.fromEntries(
                                edit.sizes.map((s) => [
                                  s.trim(),
                                  Number(edit.sizeStock[s.trim()] || 0),
                                ]),
                              );
                              sizes[size] = Number(e.target.value);
                              setEdit({
                                ...edit,
                                sizeStock: sizes,
                                stock: Object.values(sizes).reduce(
                                  (n, v) => n + v,
                                  0,
                                ),
                              });
                            }}
                          />
                        </label>
                      );
                    })}
                  </div>
                </fieldset>
              )}
              {edit.imageUrl && (
                <img
                  className="admin-upload-preview"
                  src={edit.imageUrl}
                  alt="תצוגה מקדימה"
                />
              )}
              <label className="admin-check">
                <input
                  type="checkbox"
                  checked={edit.active}
                  onChange={(e) =>
                    setEdit({ ...edit, active: e.target.checked })
                  }
                />
                הצגת המוצר בחנות
              </label>
              {error && (
                <p role="alert" className="admin-alert">
                  {error}
                </p>
              )}
              <button disabled={busy} className="admin-primary">
                {busy ? "שומר..." : "שמירת מוצר"}
              </button>
            </form>
          </section>
        </div>
      )}
      {promo && (
        <div className="admin-modal-backdrop">
          <section
            className="admin-modal"
            role="dialog"
            aria-modal="true"
            aria-label="עריכת קוד מבצע"
          >
            <button onClick={() => setPromo(null)}>סגירה</button>
            <h2>קוד מבצע</h2>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                action(async () => {
                  const saved = await adminApi(
                    "promotions" + (promo.id ? "/" + promo.id : ""),
                    {
                      method: promo.id ? "PUT" : "POST",
                      body: JSON.stringify(promo),
                    },
                  );
                  setPromotions((ps) => [
                    saved,
                    ...ps.filter((x) => x.id !== saved.id),
                  ]);
                  setPromo(null);
                }, "הקוד נשמר בשרת.");
              }}
            >
              <label>
                קוד
                <input
                  required
                  value={promo.code}
                  onChange={(e) => setPromo({ ...promo, code: e.target.value })}
                />
              </label>
              <label>
                אחוז הנחה
                <input
                  type="number"
                  min="1"
                  max="100"
                  required
                  value={promo.discount}
                  onChange={(e) =>
                    setPromo({ ...promo, discount: e.target.value })
                  }
                />
              </label>
              <label>
                תאריך התחלה
                <input
                  type="date"
                  value={promo.startsAt || ""}
                  onChange={(e) =>
                    setPromo({ ...promo, startsAt: e.target.value })
                  }
                />
              </label>
              <label>
                סכום סל מינימלי
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={promo.minSubtotal || 0}
                  onChange={(e) =>
                    setPromo({ ...promo, minSubtotal: e.target.value })
                  }
                />
              </label>
              <label className="admin-check">
                <input
                  type="checkbox"
                  checked={!!promo.excludeSale}
                  onChange={(e) =>
                    setPromo({ ...promo, excludeSale: e.target.checked })
                  }
                />
                ללא כפל מבצעים על פריטים מוזלים
              </label>
              <label>
                תאריך סיום
                <input
                  type="date"
                  value={promo.endsAt}
                  onChange={(e) =>
                    setPromo({ ...promo, endsAt: e.target.value })
                  }
                />
              </label>
              <label className="admin-check">
                <input
                  type="checkbox"
                  checked={promo.active}
                  onChange={(e) =>
                    setPromo({ ...promo, active: e.target.checked })
                  }
                />
                קוד פעיל
              </label>
              <button disabled={busy} className="admin-primary">
                שמירת קוד
              </button>
              {error && <p role="alert">{error}</p>}
            </form>
          </section>
        </div>
      )}
      {order && (
        <div className="admin-modal-backdrop">
          <section
            className="admin-modal"
            role="dialog"
            aria-modal="true"
            aria-label="עריכת הזמנה"
          >
            <button onClick={() => setOrder(null)}>סגירה</button>
            <h2>הזמנה {order.id}</h2>
            <form
              onSubmit={(e) => {
                e.preventDefault();
                action(async () => {
                  const saved = await adminApi("orders/" + order.id, {
                    method: "PUT",
                    body: JSON.stringify(order),
                  });
                  setOrders((os) =>
                    os.map((o) => (o.id === saved.id ? saved : o)),
                  );
                  setOrder(null);
                }, "ההזמנה עודכנה.");
              }}
            >
              <label>
                מצב
                <select
                  value={order.status}
                  onChange={(e) =>
                    setOrder({ ...order, status: e.target.value })
                  }
                >
                  {[
                    ["new", "חדשה"],
                    ["processing", "בטיפול"],
                    ["shipped", "נשלחה"],
                    ["completed", "הושלמה"],
                    ["cancelled", "בוטלה"],
                  ].map(([v, l]) => (
                    <option key={v} value={v}>
                      {l}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                מספר מעקב
                <input
                  value={order.tracking || ""}
                  onChange={(e) =>
                    setOrder({ ...order, tracking: e.target.value })
                  }
                />
              </label>
              <label>
                הערות
                <textarea
                  value={order.notes || ""}
                  onChange={(e) =>
                    setOrder({ ...order, notes: e.target.value })
                  }
                />
              </label>
              <button disabled={busy} className="admin-primary">
                שמירת הזמנה
              </button>
            </form>
          </section>
        </div>
      )}
    </div>
  );
}
