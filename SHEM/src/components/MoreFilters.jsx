import { useId, useState } from "react";
import { InventoryFilters } from "./InventoryFilters";

// Up to 760px the secondary filters (search, size, color, in stock) sit behind a round
// "More filters" button. Above 760px the button is hidden and the panel is always shown,
// so desktop and tablet are unchanged. InventoryFilters itself is not modified.
export function MoreFilters(props) {
  const [open, setOpen] = useState(false);
  const panelId = useId();
  const { he = true, query, size, color, onlyStock } = props;
  const active = [query && String(query).trim(), size, color, onlyStock].filter(
    Boolean,
  ).length;
  const label = he ? "סינון נוסף" : "More filters";
  return (
    <div className={`more-filters${open ? " is-open" : ""}`}>
      <button
        type="button"
        className="more-filters-toggle"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((value) => !value)}
      >
        {active > 0 ? `${label} (${active})` : label}
      </button>
      <div id={panelId} className="more-filters-panel">
        <InventoryFilters {...props} />
      </div>
    </div>
  );
}
