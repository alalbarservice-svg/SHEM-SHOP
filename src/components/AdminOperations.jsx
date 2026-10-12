import { useEffect, useState } from "react";
import { adminApi } from "../admin-api";
import { money } from "../commerce";
const businessKeys = [
  ["legalName", "שם העוסק הרשמי"],
  ["registrationNumber", "מספר עוסק או חברה"],
  ["address", "כתובת העסק והחזרת מוצרים"],
  ["phone", "טלפון השירות"],
  ["accessibilityContact", "אחראי לפניות נגישות"],
  ["serviceAreas", "אזורי משלוח"],
  ["returnCollectionFee", "עלות איסוף להחזרה, אם קיימת"],
];
const statuses = {
  new: "חדשה",
  reviewing: "בטיפול",
  collection: "תיאום איסוף",
  resolved: "טופלה",
  rejected: "נסגרה ללא אישור",
};
export function exportCSV(name, rows) {
  if (!rows.length) return;
  const keys = Object.keys(rows[0]);
  const cell = (v) =>
    '"' +
    String(v ?? "")
      .replace(/^[=+@-]/, "'$&")
      .replaceAll('"', '""') +
    '"';
  const blob = new Blob(
    [
      "\uFEFF" +
        [keys, ...rows.map((r) => keys.map((k) => r[k]))]
          .map((r) => r.map(cell).join(","))
          .join("\r\n"),
    ],
    { type: "text/csv;charset=utf-8" },
  );
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = name + ".csv";
  a.click();
  URL.revokeObjectURL(url);
}
export function AdminOperations({
  section,
  products,
  members,
  orders,
  onCollectionsChange,
}) {
  const [rows, setRows] = useState([]),
    [editor, setEditor] = useState(null),
    [business, setBusiness] = useState({}),
    [health, setHealth] = useState(null),
    [error, setError] = useState(""),
    [notice, setNotice] = useState(""),
    [busy, setBusy] = useState(false),
    [query, setQuery] = useState(""),
    [loading, setLoading] = useState(true);
  const endpoint = {
    collections: "collections",
    crm: "customers",
    cards: "member-profiles",
    requests: "requests",
    audit: "audit",
  }[section];
  const load = async () => {
    setLoading(true);
    setError("");
    try {
      if (endpoint) setRows(await adminApi(endpoint));
      if (section === "business")
        setBusiness((await adminApi("settings")).business || {});
      if (section === "control") setHealth(await adminApi("health"));
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    setEditor(null);
    setQuery("");
    load();
  }, [section]);
  const run = async (fn) => {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await fn();
      setNotice("השינוי נשמר בשרת.");
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };
  const save = (e) => {
    e.preventDefault();
    run(async () => {
      const record = await adminApi(
        endpoint + (editor.id ? "/" + encodeURIComponent(editor.id) : ""),
        { method: editor.id ? "PUT" : "POST", body: JSON.stringify(editor) },
      );
      setRows((old) => [record, ...old.filter((r) => r.id !== record.id)]);
      setEditor(null);
      if (section === "collections") onCollectionsChange?.();
    });
  };
  const field = (key, label, type = "text") => (
    <label key={key}>
      {label}
      {type === "textarea" ? (
        <textarea
          value={editor[key] || ""}
          onChange={(e) => setEditor({ ...editor, [key]: e.target.value })}
        />
      ) : (
        <input
          type={type}
          required={["name", "email", "title"].includes(key)}
          value={editor[key] || ""}
          onChange={(e) => setEditor({ ...editor, [key]: e.target.value })}
        />
      )}
    </label>
  );
  const filtered = rows.filter((r) =>
    JSON.stringify(r).toLowerCase().includes(query.toLowerCase()),
  );
  return (
    <section className="admin-panel admin-operations">
      {error && (
        <p role="alert" className="admin-alert">
          {error}
          <button onClick={load}>ניסיון חוזר</button>
        </p>
      )}
      {notice && (
        <p role="status" className="admin-success">
          {notice}
        </p>
      )}
      <div className="admin-toolbar">
        <h2>
          {
            {
              collections: "קולקציות",
              crm: "כרטיסי לקוח",
              cards: "כרטיסי מועדון",
              requests: "פניות שירות, ביטול והחזרה",
              business: "פרטי העסק והמדיניות",
              audit: "יומן פעולות",
              control: "בקרה והיערכות להשקה",
            }[section]
          }
        </h2>
        <button disabled={busy} onClick={load}>
          רענון מהשרת
        </button>
        {["collections", "crm"].includes(section) && (
          <button
            className="admin-primary"
            onClick={() =>
              setEditor(
                section === "collections"
                  ? {
                      title: "",
                      description: "",
                      productIds: [],
                      active: false,
                    }
                  : { name: "", email: "", phone: "", notes: "", tags: "" },
              )
            }
          >
            הוספה חדשה
          </button>
        )}
      </div>
      {loading ? (
        <p role="status">טוען נתונים מהשרת...</p>
      ) : (
        <>
          {endpoint && (
            <div className="admin-toolbar">
              <input
                aria-label="חיפוש ברשומות"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="חיפוש"
              />
              <button
                disabled={!filtered.length}
                onClick={() =>
                  exportCSV(
                    "shem-" + section,
                    filtered.map(({ revision, ...r }) =>
                      Object.fromEntries(
                        Object.entries(r).map(([k, v]) => [
                          k,
                          typeof v === "object" ? JSON.stringify(v) : v,
                        ]),
                      ),
                    ),
                  )
                }
              >
                ייצוא CSV
              </button>
            </div>
          )}
          {section === "business" && (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                run(() =>
                  adminApi("settings", {
                    method: "PUT",
                    body: JSON.stringify({ key: "business", value: business }),
                  }),
                );
              }}
            >
              <p className="admin-note">
                שם העוסק, מספרו, כתובתו וטלפון השירות נדרשים לפני פתיחת מכירה.
                פרטים אלו מוצגים בעמודי המדיניות. טלפון התמיכה בוואטסאפ:
                052-9586766.
              </p>
              <div className="admin-form-grid">
                {businessKeys.map(([key, label]) => (
                  <label key={key}>
                    {label}
                    <input
                      value={business[key] || ""}
                      onChange={(e) =>
                        setBusiness({ ...business, [key]: e.target.value })
                      }
                    />
                  </label>
                ))}
              </div>
              <button disabled={busy} className="admin-primary">
                שמירת פרטי העסק
              </button>
              <a href="#/terms">צפייה בתנאי השימוש</a>
            </form>
          )}
          {section === "control" && health && (
            <>
              <p>
                בדיקה אחרונה:{" "}
                {new Date(health.checkedAt).toLocaleString("he-IL")}
              </p>
              <dl className="admin-integrations">
                {[
                  ["מסד נתונים", health.database],
                  ["אחסון תמונות", health.media],
                  ["פרטי עסק מלאים", health.businessComplete],
                  ["סליקה והזמנות", health.payments],
                  ["חיבור למחסן", health.warehouse],
                ].map(([label, ok]) => (
                  <div key={label}>
                    <dt>{label}</dt>
                    <dd>{ok ? "תקין / מחובר" : "נדרשת השלמה"}</dd>
                  </div>
                ))}
              </dl>
              <h3>תמונת מצב מלאי</h3>
              <div className="admin-table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>מצב</th>
                      <th>מספר מוצרים</th>
                    </tr>
                  </thead>
                  <tbody>
                    {[
                      ["מוצגים", products.filter((p) => p.active).length],
                      ["מוסתרים", products.filter((p) => !p.active).length],
                      [
                        "אזלו מהמלאי",
                        products.filter((p) => p.stock === 0).length,
                      ],
                      [
                        "מלאי נמוך, 1-3 יחידות",
                        products.filter((p) => p.stock > 0 && p.stock <= 3)
                          .length,
                      ],
                    ].map(([k, v]) => (
                      <tr key={k}>
                        <th>{k}</th>
                        <td>{v}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="admin-note">
                הרשמות נשמרות במועדון. שליחת דיוור, סליקה, חשבוניות, זיכויים
                אוטומטיים ומימוש נקודות דורשים ספק חיצוני. האתר אינו גובה תשלום
                כרגע.
              </p>
            </>
          )}
          {section === "cards" && (
            <>
              <p className="admin-note">
                הכרטיס מזהה את חבר המועדון. דרגת VIP היא סיווג לניהול שירות
                ואינה מבטיחה הנחה או יתרה כספית. הרשאת דיוור נשמרת בנפרד וניתנת
                רק בהסכמת החבר.
              </p>
              <div className="admin-table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>חבר</th>
                      <th>מספר כרטיס</th>
                      <th>דרגה</th>
                      <th>דיוור</th>
                      <th>פעולה</th>
                    </tr>
                  </thead>
                  <tbody>
                    {members
                      .filter((m) =>
                        m.email.toLowerCase().includes(query.toLowerCase()),
                      )
                      .map((m) => {
                        const p = rows.find((r) => r.id === m.id) || {};
                        return (
                          <tr key={m.id}>
                            <td dir="ltr">{m.email}</td>
                            <td>{p.cardNumber || "טרם הוגדר"}</td>
                            <td>{p.tier === "vip" ? "VIP" : "רגיל"}</td>
                            <td>
                              {m.status === "active" ? "הסכמה פעילה" : "הוסר"}
                            </td>
                            <td>
                              <button
                                onClick={() =>
                                  setEditor({
                                    id: m.id,
                                    tier: "standard",
                                    ...p,
                                    email: m.email,
                                  })
                                }
                              >
                                עריכת כרטיס
                              </button>
                            </td>
                          </tr>
                        );
                      })}
                  </tbody>
                </table>
              </div>
              {!members.length && <p>אין עדיין חברים רשומים.</p>}
            </>
          )}
          {section === "collections" && (
            <div className="admin-table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>שם</th>
                    <th>מוצרים</th>
                    <th>מצב</th>
                    <th>פעולה</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((c) => (
                    <tr key={c.id}>
                      <td>{c.title}</td>
                      <td>{c.productIds.length}</td>
                      <td>{c.active ? "מוצגת באתר" : "טיוטה"}</td>
                      <td>
                        <button onClick={() => setEditor({ ...c })}>
                          עריכה
                        </button>
                        <a href={"#/collection/" + c.id}>תצוגה בחנות</a>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!rows.length && (
                <p>הוסיפו קולקציה ושייכו אליה מוצרים מהקטלוג.</p>
              )}
            </div>
          )}
          {section === "crm" && (
            <>
              <p className="admin-note">
                הכרטיסים משמשים לשירות לקוחות. אין אישור דיוור אוטומטי בעקבות
                יצירת כרטיס. אין לשמור פרטי תשלום או מידע רגיש בהערות.
              </p>
              <div className="admin-table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>שם</th>
                      <th>אימייל</th>
                      <th>טלפון</th>
                      <th>הזמנות ששולמו</th>
                      <th>סכום</th>
                      <th>פעולה</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((c) => {
                      const paid = orders.filter(
                        (o) =>
                          o.customer?.email?.toLowerCase() === c.email &&
                          o.paymentStatus === "paid" &&
                          o.status !== "cancelled",
                      );
                      return (
                        <tr key={c.id}>
                          <td>{c.name}</td>
                          <td dir="ltr">{c.email}</td>
                          <td dir="ltr">{c.phone}</td>
                          <td>{paid.length}</td>
                          <td>
                            {money(
                              paid.reduce(
                                (n, o) => n + Number(o.total || 0),
                                0,
                              ),
                            )}
                          </td>
                          <td>
                            <button onClick={() => setEditor({ ...c })}>
                              עריכה
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
                {!rows.length && (
                  <p>
                    טרם נוספו כרטיסי לקוח. לקוחות מהזמנות מופיעים גם במסך
                    הלקוחות.
                  </p>
                )}
              </div>
            </>
          )}
          {section === "requests" && (
            <>
              <p className="admin-note">
                פנייה שנשמרת אינה מבצעת ביטול חיוב או זיכוי. הטיפול הכספי
                והאיסוף מבוצעים על ידי השירות.
              </p>
              <div className="admin-table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>סוג</th>
                      <th>לקוח / הזמנה</th>
                      <th>התקבלה</th>
                      <th>מצב</th>
                      <th>פעולה</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((r) => (
                      <tr key={r.id}>
                        <td>
                          {
                            {
                              cancel: "ביטול",
                              return: "החזרה",
                              exchange: "החלפה",
                              support: "שירות",
                            }[r.type]
                          }
                        </td>
                        <td>
                          {r.name}
                          <small>
                            {r.orderNumber} / {r.email}
                          </small>
                        </td>
                        <td>{new Date(r.createdAt).toLocaleString("he-IL")}</td>
                        <td>{statuses[r.status]}</td>
                        <td>
                          <button onClick={() => setEditor({ ...r })}>
                            טיפול
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {!rows.length && <p>אין פניות להצגה.</p>}
              </div>
            </>
          )}
          {section === "audit" && (
            <>
              <p className="admin-note">
                מוצגות 200 הפעולות האחרונות שנשמרו. היומן מתעד מי ביצע פעולה
                ומתי, ללא תוכן אישי של הרשומה.
              </p>
              <div className="admin-table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>מועד</th>
                      <th>מזהה מבצע</th>
                      <th>פעולה</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((r) => (
                      <tr key={r.id}>
                        <td>
                          {new Date(r.created_at).toLocaleString("he-IL")}
                        </td>
                        <td dir="ltr">{r.actor}</td>
                        <td dir="ltr">{r.action}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {!rows.length && <p>טרם תועדו פעולות.</p>}
              </div>
            </>
          )}
        </>
      )}
      {editor && (
        <form className="admin-inline-editor" onSubmit={save}>
          <div className="admin-toolbar">
            <h3>עריכת רשומה</h3>
            <button type="button" onClick={() => setEditor(null)}>
              סגירה ללא שמירה
            </button>
          </div>
          {section === "collections" && (
            <>
              {field("title", "שם הקולקציה")}
              {field("description", "תיאור", "textarea")}
              <fieldset>
                <legend>שיוך מוצרים ({editor.productIds.length})</legend>
                <div className="admin-product-picker">
                  {products.map((p) => (
                    <label className="admin-check" key={p.id}>
                      <input
                        type="checkbox"
                        checked={editor.productIds.includes(p.id)}
                        onChange={(e) =>
                          setEditor({
                            ...editor,
                            productIds: e.target.checked
                              ? [...editor.productIds, p.id]
                              : editor.productIds.filter((id) => id !== p.id),
                          })
                        }
                      />
                      {p.he} / {p.brand}
                    </label>
                  ))}
                </div>
              </fieldset>
              <label className="admin-check">
                <input
                  type="checkbox"
                  checked={editor.active}
                  onChange={(e) =>
                    setEditor({ ...editor, active: e.target.checked })
                  }
                />
                הצגת הקולקציה באתר
              </label>
            </>
          )}
          {section === "crm" && (
            <>
              {field("name", "שם")}
              {field("email", "אימייל", "email")}
              {field("phone", "טלפון", "tel")}
              {field("tags", "תגיות, בהפרדה בפסיק")}
              {field("notes", "הערות שירות", "textarea")}
            </>
          )}
          {section === "cards" && (
            <>
              <p dir="ltr">{editor.email}</p>
              {field("name", "שם חבר")}
              {field("phone", "טלפון", "tel")}
              <label>
                דרגה
                <select
                  value={editor.tier}
                  onChange={(e) =>
                    setEditor({ ...editor, tier: e.target.value })
                  }
                >
                  <option value="standard">רגיל</option>
                  <option value="vip">VIP</option>
                </select>
              </label>
              {field("notes", "הערות שירות", "textarea")}
            </>
          )}
          {section === "requests" && (
            <>
              <p>{editor.message || "לא נמסרה הודעה נוספת."}</p>
              <a href={"mailto:" + editor.email}>תגובה במייל</a>
              <label>
                מצב הטיפול
                <select
                  value={editor.status}
                  onChange={(e) =>
                    setEditor({ ...editor, status: e.target.value })
                  }
                >
                  {Object.entries(statuses).map(([key, label]) => (
                    <option key={key} value={key}>
                      {label}
                    </option>
                  ))}
                </select>
              </label>
              {field("notes", "הערות טיפול", "textarea")}
            </>
          )}
          {error && <p role="alert">{error}</p>}
          <button className="admin-primary" disabled={busy}>
            {busy ? "שומר..." : "שמירה בשרת"}
          </button>
        </form>
      )}
    </section>
  );
}
