import { useEffect, useState } from "react";
import { adminApi } from "../admin-api";
import { helpCategories } from "./HelpCenter";
const metrics = {
  product_view: "צפייה במוצר",
  cart_add: "הוספה לסל",
  checkout_start: "כניסה לרכישה",
  review_ready: "סיכום מאומת",
  payment_open: "פתיחת תשלום",
  purchase_confirmed: "אישור רכישה",
  checkout_error: "תקלות רכישה",
  search_empty: "חיפוש ללא תוצאות",
  help_open: "פתיחת תשובה",
};
export function ExperienceAdmin() {
  const [data, setData] = useState(null),
    [error, setError] = useState(""),
    [notice, setNotice] = useState(""),
    [busy, setBusy] = useState(false);
  const load = async () => {
    setError("");
    try {
      setData(await adminApi("experience"));
    } catch (e) {
      setError(e.message);
    }
  };
  useEffect(() => {
    load();
  }, []);
  const change = (id, key, value) =>
    setData({
      ...data,
      help: data.help.map((i) => (i.id === id ? { ...i, [key]: value } : i)),
    });
  return (
    <section className="admin-panel experience-admin">
      <h2>עזרה, שירות עצמי ומדידה</h2>
      {error && <p role="alert">{error}</p>}
      {notice && <p role="status">{notice}</p>}
      <button disabled={busy} onClick={load}>
        רענון
      </button>
      {data && (
        <form
          onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            setError("");
            setNotice("");
            try {
              await adminApi("experience", {
                method: "PUT",
                body: JSON.stringify({ help: data.help, config: data.config }),
              });
              setNotice("מרכז העזרה וההגדרות נשמרו.");
            } catch (e) {
              setError(e.message);
            } finally {
              setBusy(false);
            }
          }}
        >
          <h3>מרכז העזרה</h3>
          <button
            type="button"
            disabled={busy || data.help.length >= 100}
            onClick={() =>
              setData({
                ...data,
                help: [
                  ...data.help,
                  {
                    id: crypto.randomUUID(),
                    title: "",
                    answer: "",
                    category: "orders",
                    placement: "all",
                    action: "contact",
                    active: false,
                  },
                ],
              })
            }
          >
            הוספת שאלה ותשובה
          </button>
          {data.help.map((i) => (
            <fieldset key={i.id}>
              <legend>{i.title || "תשובה חדשה"}</legend>
              <label>
                שאלה
                <input
                  required
                  maxLength="200"
                  value={i.title}
                  onChange={(e) => change(i.id, "title", e.target.value)}
                />
              </label>
              <label>
                תשובה
                <textarea
                  required
                  maxLength="4000"
                  value={i.answer}
                  onChange={(e) => change(i.id, "answer", e.target.value)}
                />
              </label>
              <label>
                נושא
                <select
                  value={i.category}
                  onChange={(e) => change(i.id, "category", e.target.value)}
                >
                  {Object.entries(helpCategories).map(([k, l]) => (
                    <option key={k} value={k}>
                      {l}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                הצגה בתוך מסלול הרכישה
                <select
                  value={i.placement || "all"}
                  onChange={(e) => change(i.id, "placement", e.target.value)}
                >
                  <option value="all">מוצר ורכישה</option>
                  <option value="product">דף מוצר</option>
                  <option value="checkout">רכישה</option>
                  <option value="none">מרכז העזרה בלבד</option>
                </select>
              </label>
              <label>
                פעולה מהתשובה
                <select
                  value={i.action}
                  onChange={(e) => change(i.id, "action", e.target.value)}
                >
                  {Object.entries({
                    shop: "קולקציה",
                    payments: "מדיניות משלוח ותשלום",
                    account: "אזור הלקוח",
                    contact: "תמיכה",
                    cancel: "בקשת ביטול והחזרה",
                    club: "מועדון",
                  }).map(([k, l]) => (
                    <option key={k} value={k}>
                      {l}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                <input
                  type="checkbox"
                  checked={i.active}
                  onChange={(e) => change(i.id, "active", e.target.checked)}
                />
                פרסום התשובה
              </label>
              <button
                type="button"
                onClick={() => {
                  if (
                    confirm("להסיר את התשובה מהטיוטה? השינוי יחול לאחר שמירה.")
                  )
                    setData({
                      ...data,
                      help: data.help.filter((x) => x.id !== i.id),
                    });
                }}
              >
                הסרה מהטיוטה
              </button>
            </fieldset>
          ))}
          <h3>שימור ומדידה</h3>
          <label>
            <input
              type="checkbox"
              checked={data.config.siteAlertsEnabled}
              onChange={(e) =>
                setData({
                  ...data,
                  config: {
                    ...data.config,
                    siteAlertsEnabled: e.target.checked,
                  },
                })
              }
            />
            הפעלת התראות חזרה למלאי בתוך האתר
          </label>
          <label>
            <input
              type="checkbox"
              checked={data.config.analyticsEnabled}
              onChange={(e) =>
                setData({
                  ...data,
                  config: {
                    ...data.config,
                    analyticsEnabled: e.target.checked,
                  },
                })
              }
            />
            מדידת מסלול רכישה בהסכמת הגולש
          </label>
          <p>
            האירועים נשמרים ל-90 יום. הדוח מציג 30 ימים אחרונים. מספר ביקורים
            שנמדדו אינו מספר כלל המבקרים. אין שליחת מייל או וואטסאפ ואין חיבור
            חברת שילוח פעיל במסגרת מסך זה.
          </p>
          <button className="admin-primary" disabled={busy}>
            {busy ? "שומרים..." : "שמירת עזרה והגדרות"}
          </button>
          <h3>מסלול רכישה ושירות - 30 ימים</h3>
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>אירוע</th>
                  <th>אירועים</th>
                  <th>ביקורים שנמדדו</th>
                </tr>
              </thead>
              <tbody>
                {Object.entries(metrics).map(([event, label]) => {
                  const m = data.events.find((e) => e.event === event) || {};
                  return (
                    <tr key={event}>
                      <td>{label}</td>
                      <td>{m.events || 0}</td>
                      <td>{m.sessions || 0}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <h3>פניות שירות</h3>
          {data.requests.map((r) => (
            <p key={r.type}>
              {{
                exchange: "החלפות",
                return: "החזרות",
                cancel: "ביטולים",
                support: "תמיכה",
              }[r.type] || "אחר"}
              : {r.count}
            </p>
          ))}
          {!data.requests.length && <p>אין פניות בתקופה הזאת.</p>}
        </form>
      )}
    </section>
  );
}
