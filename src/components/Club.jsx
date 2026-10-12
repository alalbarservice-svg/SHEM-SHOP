import { useState, useEffect } from "react";
const defaults = {
  title: "הסטייל שלך. המעגל שלך.",
  description: "הצטרפו למעגל של SHEM וקבלו עדכונים על פריטים נבחרים.",
  benefits:
    "גישה מוקדמת לקולקציות\nעדכונים על פריטים נבחרים\nהשראה אישית לסטייל שלכם",
  enabled: false,
  privacyUrl: "",
};
export function Club({ he }) {
  const [config, setConfig] = useState(defaults),
    [loading, setLoading] = useState(true),
    [error, setError] = useState(""),
    [done, setDone] = useState(false),
    [busy, setBusy] = useState(false);
  const t = (h, e) => (he ? h : e);
  useEffect(() => {
    fetch("/api/club")
      .then(async (r) => {
        if (!r.ok) throw new Error("לא ניתן לטעון את פרטי המועדון כרגע.");
        return r.json();
      })
      .then((c) => setConfig({ ...defaults, ...c }))
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);
  const join = async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    setBusy(true);
    setError("");
    try {
      const response = await fetch("/api/club", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          email: new FormData(form).get("email"),
          consent: new FormData(form).get("consent") === "on",
        }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error);
      setDone(true);
      form.reset();
    } catch (e) {
      setError(e.message || "לא ניתן להשלים את ההרשמה כרגע.");
    } finally {
      setBusy(false);
    }
  };
  return (
    <section className="page club-page">
      <p className="eyebrow">SHEM PRIVATE CIRCLE</p>
      <h1>{t(config.title, "Your style. Your circle.")}</h1>
      <p className="club-intro">
        {t(
          config.description,
          "Join the SHEM circle for selected collection updates.",
        )}
      </p>
      <div className="club-benefits">
        {(he
          ? config.benefits.split("\n")
          : [
              "Early collection access",
              "Selected piece updates",
              "Personal style inspiration",
            ]
        )
          .filter(Boolean)
          .map((b, i) => (
            <article key={i}>
              <span>0{i + 1}</span>
              <h2>{b}</h2>
            </article>
          ))}
      </div>
      {loading ? (
        <p role="status">
          {t("טוען את פרטי המועדון...", "Loading club details...")}
        </p>
      ) : config.enabled && config.privacyUrl ? (
        <form onSubmit={join}>
          <label>
            {t("כתובת אימייל", "Email address")}
            <input
              type="email"
              name="email"
              autoComplete="email"
              required
              placeholder="you@example.com"
            />
          </label>
          <label className="club-toggle">
            <input type="checkbox" required name="consent"/>
            {t(
              "אני מסכים לקבל עדכונים ממועדון SHEM.",
              "I agree to receive SHEM club updates.",
            )}
          </label>
          <p>מסירת האימייל היא מרצון ולצורך המועדון והעדכונים. בלי אימייל והסכמה לא תתבצע הרשמה לדיוור. אפשר לבקש עיון, תיקון או הסרה במייל השירות.</p>
          <a href={config.privacyUrl}>
            {t("מדיניות פרטיות", "Privacy policy")}
          </a>
          <button disabled={busy} className="button dark">
            {t(
              busy ? "שולח..." : "להצטרפות למעגל",
              busy ? "Submitting..." : "Join the circle",
            )}
          </button>
          {done && (
            <p role="status">
              {t("הצטרפת למועדון SHEM.", "You have joined the SHEM club.")}
            </p>
          )}
        </form>
      ) : (
        <p>
          {t("ההרשמה למועדון תיפתח בקרוב.", "Club enrollment is coming soon.")}
        </p>
      )}
      {error && <p role="alert">{error}</p>}
    </section>
  );
}
