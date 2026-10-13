import { useEffect, useState } from "react";
import { adminApi } from "../admin-api";
export function Integrations() {
  const [config, setConfig] = useState({
      paymentProvider: "",
      merchantId: "",
      invoiceProvider: "",
      invoiceAccount: "",
    }),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [notice, setNotice] = useState(""),
    [status, setStatus] = useState(null);
  useEffect(() => {
    adminApi("settings")
      .then((v) => setConfig((c) => ({ ...c, ...v.integrations })))
      .catch((e) => setError(e.message));
    adminApi("integration-status")
      .then(setStatus)
      .catch((e) => setError(e.message));
  }, []);
  return (
    <section className="admin-panel">
      <h2>חיבור סליקה וחשבוניות</h2>
      <p className="admin-note">
        התהליך המתוכנן הוא תשלום אצל הספק, אישור תשלום מאומת לשרת, עדכון ההזמנה
        והמלאי והפקת חשבונית. אין להזין מפתחות או סיסמאות בשדות אלו. פרטי הגישה
        יוגדרו בסודות השרת לאחר בחירת הספקים.
      </p>
      <form
        onSubmit={async (e) => {
          e.preventDefault();
          setBusy(true);
          setError("");
          try {
            await adminApi("settings", {
              method: "PUT",
              body: JSON.stringify({ key: "integrations", value: config }),
            });
            setNotice(
              "בחירת הספקים נשמרה. החיבור עדיין דורש מתאם רשמי, פרטי גישה ובדיקת עסקה.",
            );
          } catch (e) {
            setError(e.message);
          } finally {
            setBusy(false);
          }
        }}
      >
        <div className="admin-form-grid">
          {[
            ["paymentProvider", "שם ספק הסליקה"],
            ["merchantId", "מזהה בית עסק אצל ספק הסליקה"],
            ["invoiceProvider", "שם מערכת החשבוניות"],
            ["invoiceAccount", "מזהה העסק במערכת החשבוניות"],
          ].map(([key, label]) => (
            <label key={key}>
              {label}
              <input
                value={config[key]}
                onChange={(e) =>
                  setConfig({ ...config, [key]: e.target.value })
                }
              />
            </label>
          ))}
        </div>
        <button className="admin-primary" disabled={busy}>
          שמירת בחירת הספקים
        </button>
      </form>
      {notice && (
        <p role="status" className="admin-success">
          {notice}
        </p>
      )}
      {error && (
        <p role="alert" className="admin-alert">
          {error}
        </p>
      )}
      <h3>מצב החיבור בפועל</h3>
      {status && (
        <dl className="admin-integrations">
          <dt>סליקה</dt>
          <dd>{status.payment ? "מחוברת" : "לא מחוברת; גבייה חסומה"}</dd>
          <dt>חשבוניות</dt>
          <dd>{status.invoicing ? "מחוברות" : "לא מחוברות; הפקה חסומה"}</dd>
          <dt>השלמה אוטומטית של רכישה</dt>
          <dd>
            {status.automatic ? "פעילה" : "לא פעילה עד להשלמת חיבור ובדיקה"}
          </dd>
        </dl>
      )}
      <h3>בקרות לפני הפעלה</h3>
      <ol>
        <li>בחירת הספק ושימוש בתיעוד הרשמי של אותו ספק.</li>
        <li>הגדרת פרטי גישה בסודות השרת, בלי חשיפתם בדפדפן.</li>
        <li>אימות הודעות תשלום וסכום מול הספק, לא מול הדפדפן.</li>
        <li>מניעת הזמנה או חשבונית כפולה עבור אותה עסקה.</li>
        <li>בדיקת תשלום, כשל, ביטול, מלאי וחשבונית בסביבת בדיקות.</li>
        <li>הפעלת ניטור וניסיונות חוזרים במקרה של כשל בהפקת מסמך.</li>
      </ol>
      <p>נדרש עדיין שם ספק הסליקה שכבר קיים אצלך ובחירת מערכת חשבוניות.</p>
    </section>
  );
}
