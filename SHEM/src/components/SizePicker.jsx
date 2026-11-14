import { useState } from "react";
const SIZE_PRESETS = [
  ["מידות אותיות", ["XS", "S", "M", "L", "XL", "XXL", "XXXL"]],
  [
    "מידות מספריות",
    Array.from({ length: 19 }, (_, i) => String(30 + i)),
  ],
];
export const parseSizes = (v) =>
  String(v || "")
    .split(",")
    .map((x) => x.trim())
    .filter(Boolean);
export function SizePicker({ label, value, onChange, disabled }) {
  const [extra, setExtra] = useState("");
  const chosen = parseSizes(value);
  const presetAll = SIZE_PRESETS.flatMap(([, list]) => list);
  const order = (list) => [
    ...presetAll.filter((x) => list.includes(x)),
    ...list.filter((x) => !presetAll.includes(x)),
  ];
  const set = (list) => onChange(order([...new Set(list)]).join(","));
  const toggle = (size) =>
    set(
      chosen.includes(size)
        ? chosen.filter((x) => x !== size)
        : [...chosen, size],
    );
  const addExtra = () => {
    const add = parseSizes(extra);
    if (add.length) set([...chosen, ...add]);
    setExtra("");
  };
  const custom = chosen.filter((x) => !presetAll.includes(x));
  return (
    <div className="size-picker" role="group" aria-label={label}>
      <span className="size-picker-title">{label}</span>
      {SIZE_PRESETS.map(([title, list]) => (
        <div className="size-picker-row" key={title}>
          <small>{title}</small>
          <div>
            {list.map((size) => (
              <button
                type="button"
                key={size}
                className="size-btn"
                aria-pressed={chosen.includes(size)}
                disabled={disabled}
                onClick={() => toggle(size)}
              >
                {size}
              </button>
            ))}
          </div>
        </div>
      ))}
      <div className="size-picker-row">
        <small>הוספת מידה שלא ברשימה</small>
        <div className="size-picker-add">
          <input
            value={extra}
            disabled={disabled}
            placeholder="למשל 3XL, 27 או מידה אחת"
            onChange={(e) => setExtra(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                addExtra();
              }
            }}
          />
          <button
            type="button"
            className="size-add"
            disabled={disabled || !extra.trim()}
            onClick={addExtra}
          >
            הוספה
          </button>
        </div>
        {custom.length > 0 && (
          <div>
            {custom.map((size) => (
              <button
                type="button"
                key={size}
                className="size-btn custom"
                aria-pressed="true"
                aria-label={"הסרת המידה " + size}
                disabled={disabled}
                onClick={() => toggle(size)}
              >
                {size} ×
              </button>
            ))}
          </div>
        )}
      </div>
      <small className="size-picker-summary">
        {chosen.length
          ? "מוצגות בכרטיס: " + chosen.join(" · ")
          : "לא נבחרו מידות"}
      </small>
    </div>
  );
}
