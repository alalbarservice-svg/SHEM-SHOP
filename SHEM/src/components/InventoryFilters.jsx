import { useEffect, useState } from "react";
export function InventoryFilters({
  products,
  he = true,
  query,
  onQuery,
  size,
  onSize,
  color,
  onColor,
  onlyStock,
  onStock,
}) {
  const sizes = [...new Set(products.flatMap((p) => p.sizes || []))],
    colors = [
      ...new Set(
        products
          .flatMap((p) =>
            p.variants?.length ? p.variants.map((v) => v.color) : [p.color],
          )
          .filter(Boolean),
      ),
    ];
  return (
    <div className="inventory-filters">
      {onQuery && (
        <label>
          {he ? "חיפוש פריטים" : "Search pieces"}
          <input
            type="search"
            value={query}
            onChange={(e) => onQuery(e.target.value)}
            placeholder={
              he ? "שם פריט, מותג או מק״ט" : "Piece, designer or SKU"
            }
          />
        </label>
      )}
      <label>
        {he ? "מידה" : "Size"}
        <select value={size} onChange={(e) => onSize(e.target.value)}>
          <option value="">{he ? "כל המידות" : "All sizes"}</option>
          {sizes.map((s) => (
            <option key={s}>{s}</option>
          ))}
        </select>
      </label>
      <label>
        {he ? "צבע" : "Colour"}
        <select value={color} onChange={(e) => onColor(e.target.value)}>
          <option value="">{he ? "כל הצבעים" : "All colours"}</option>
          {colors.map((c) => (
            <option key={c}>{c}</option>
          ))}
        </select>
      </label>
      <label className="stock-filter">
        <input
          type="checkbox"
          checked={onlyStock}
          onChange={(e) => onStock(e.target.checked)}
        />
        {he ? "זמינים במלאי בלבד" : "Available only"}
      </label>
    </div>
  );
}
export function MeasurementChoice() {
  const [config, setConfig] = useState(null),
    [choice, setChoice] = useState(() =>
      sessionStorage.getItem("shem-analytics-consent"),
    );
  useEffect(() => {
    fetch("/api/experience")
      .then((r) => (r.ok ? r.json() : null))
      .then(setConfig)
      .catch(() => {});
  }, []);
  if (!config?.analyticsEnabled) return null;
  const choose = (value) => {
    sessionStorage.setItem("shem-analytics-consent", value);
    setChoice(value);
  };
  return (
    <aside className="measurement-choice" dir="rtl" aria-label="העדפות מדידה">
      {!choice ? (
        <>
          <p>
            לאפשר מדידה של פעולות באתר כדי לשפר את חוויית הרכישה? אין מדידה של
            פרטי התשלום או שדות הטופס.
          </p>
          <button onClick={() => choose("yes")}>מאשר מדידה</button>
          <button onClick={() => choose("no")}>ללא מדידה</button>
          <a href="#/privacy">פרטיות</a>
        </>
      ) : (
        <button
          className="text-link"
          onClick={() => choose(choice === "yes" ? "no" : "yes")}
        >
          {choice === "yes" ? "כיבוי מדידה" : "הפעלת מדידה"}
        </button>
      )}
    </aside>
  );
}
