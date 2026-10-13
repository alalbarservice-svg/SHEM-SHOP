import { useEffect, useState } from "react";
import { experienceEvent, normalizeSearch } from "../experience";
export const helpCategories = {
  product: "מידות ומוצרים",
  orders: "הזמנות וחשבוניות",
  delivery: "משלוחים",
  returns: "החזרות והחלפות",
  payment: "תשלום ותקלות",
  club: "מועדון והטבות",
};
const actions = {
  shop: "לקולקציה",
  payments: "תשלום ומשלוחים",
  account: "לאזור שלי",
  contact: "פנייה לתמיכה",
  cancel: "בקשת החזרה או ביטול",
  club: "למועדון",
};
export function HelpCenter({ placement = "" }) {
  const [items, setItems] = useState([]),
    [query, setQuery] = useState(""),
    [category, setCategory] = useState(""),
    [error, setError] = useState(""),
    [refresh, setRefresh] = useState(0),
    [loading, setLoading] = useState(true);
  useEffect(() => {
    let active = true;
    setLoading(true);
    fetch("/api/help")
      .then(async (r) => {
        if (!r.ok) throw Error();
        return r.json();
      })
      .then((d) => {
        if (active) {
          setItems(d);
          setError("");
        }
      })
      .catch(() => {
        if (active) setError("לא ניתן לטעון את העזרה כרגע.");
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [refresh]);
  const Title = placement ? "h2" : "h1";
  const list = items
    .filter(
      (i) =>
        (!placement ||
          (i.placement || "all") === placement ||
          (i.placement || "all") === "all") &&
        (!category || i.category === category) &&
        normalizeSearch(i.title + " " + i.answer).includes(
          normalizeSearch(query),
        ),
    )
    .slice(0, placement ? 3 : 100);
  return (
    <section className={placement ? "context-help" : "page help-center"}>
      <p className="eyebrow">SHEM / HERE TO HELP</p>
      <Title className={placement ? "context-help-title" : ""}>
        {placement ? "תשובות שיעזרו לך" : "איך אפשר לעזור?"}
      </Title>
      {!placement && (
        <>
          <label>
            חיפוש במרכז העזרה
            <input
              type="search"
              placeholder="למשל: מידות, משלוח, חשבונית"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />
          </label>
          <nav className="help-categories" aria-label="נושאי עזרה">
            <button aria-pressed={!category} onClick={() => setCategory("")}>
              הכול
            </button>
            {Object.entries(helpCategories).map(([k, l]) => (
              <button
                key={k}
                aria-pressed={category === k}
                onClick={() => setCategory(k)}
              >
                {l}
              </button>
            ))}
          </nav>
        </>
      )}
      {error && (
        <p role="alert">
          {error}{" "}
          <button
            className="text-link"
            onClick={() => setRefresh((r) => r + 1)}
          >
            ניסיון חוזר
          </button>
        </p>
      )}
      {loading && <p role="status">טוענים את השאלות והתשובות...</p>}
      <div className="help-questions">
        {list.map((i) => (
          <details
            key={i.id}
            onToggle={(e) => {
              if (e.currentTarget.open)
                experienceEvent("help_open", {
                  route: placement === "checkout" ? "checkout" : "help",
                });
            }}
          >
            <summary>{i.title}</summary>
            <p>{i.answer}</p>
            <a className="text-link" href={"#/" + i.action}>
              {actions[i.action] || "לעזרה נוספת"}
            </a>
          </details>
        ))}
      </div>
      {!loading && !list.length && !error && (
        <p>לא נמצאה תשובה מתאימה. אפשר לשנות את החיפוש או לפנות לתמיכה.</p>
      )}
      <div className="help-contact">
        <a className="button outline" href={placement ? "#/help" : "#/contact"}>
          {placement ? "לכל השאלות והתשובות" : "לא מצאתם תשובה? פנייה לתמיכה"}
        </a>
        {!placement && (
          <p>
            החלפות מטופלות על ידי התמיכה. אין כאן החלפה או החזר כספי אוטומטיים.
          </p>
        )}
      </div>
    </section>
  );
}
