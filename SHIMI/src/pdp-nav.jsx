import { useEffect, useMemo, useState } from "react";

const KEY = "pdp-list-context";
const PID = /^#\/product\/(.+)$/;

const read = () => {
  try {
    return JSON.parse(sessionStorage.getItem(KEY) || "null");
  } catch {
    return null;
  }
};
const write = (v) => {
  try {
    sessionStorage.setItem(KEY, JSON.stringify(v));
  } catch {
    /* storage unavailable: fallback list is used */
  }
};

/* Remembers the ordered list of products the shopper clicked from, so the
   product page can offer "back" and previous / next inside that same list. */
let installed = false;
export function installListMemory() {
  if (installed || typeof document === "undefined") return;
  installed = true;
  document.addEventListener(
    "click",
    (e) => {
      const a = e.target.closest?.('a[href^="#/product/"]');
      if (!a) return;
      const id = a.getAttribute("href").match(PID)?.[1];
      if (!id) return;
      const box =
        a.closest("[data-plist]") ||
        a.closest(".product-grid, .grid, .rail, .product-rail, section, main");
      const ids = [];
      box?.querySelectorAll('a[href^="#/product/"]').forEach((x) => {
        const v = x.getAttribute("href").match(PID)?.[1];
        if (v && !ids.includes(v)) ids.push(v);
      });
      if (!ids.includes(id)) ids.push(id);
      const heading = box?.querySelector("h1, h2");
      write({
        ids,
        href: location.hash || "#/",
        label: heading?.textContent?.trim() || "",
        y: Math.round(window.scrollY),
      });
    },
    true,
  );
}

const Arrow = ({ dir }) => (
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path
      d={dir === "start" ? "M9 5l7 7-7 7" : "M15 5l-7 7 7 7"}
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </svg>
);

/* fallback: [{id}] of the same category, used when the shopper arrived by a
   direct link or the stored list does not contain this product */
export function PdpNav({ id, fallbackIds, fallbackHref, fallbackLabel, products, label }) {
  const [ctx, setCtx] = useState(read);
  useEffect(() => setCtx(read()), [id]);
  const valid = (list) => list.filter((x) => products.some((p) => p.id === x));
  const view = useMemo(() => {
    const stored = ctx?.ids?.includes(id) ? ctx : null;
    const ids = valid(stored ? stored.ids : fallbackIds);
    return {
      ids,
      href: stored ? stored.href : fallbackHref,
      label: stored?.label || fallbackLabel,
      y: stored?.y || 0,
    };
  }, [ctx, id, products, fallbackIds, fallbackHref, fallbackLabel]);
  const i = view.ids.indexOf(id);
  const prev = i > 0 ? view.ids[i - 1] : null;
  const next = i >= 0 && i < view.ids.length - 1 ? view.ids[i + 1] : null;
  const go = (pid) => {
    if (ctx?.ids?.includes(id)) write({ ...ctx });
    else write({ ...view });
    location.hash = `#/product/${pid}`;
    window.scrollTo(0, 0);
  };
  const back = (e) => {
    e.preventDefault();
    const y = view.y;
    location.hash = view.href;
    [60, 250, 600].forEach((ms) => setTimeout(() => window.scrollTo(0, y), ms));
  };
  return (
    <nav className="pdp-nav" aria-label={label.nav}>
      <a className="pdp-back" href={view.href} onClick={back}>
        <Arrow dir="start" />
        <span>{view.label ? `${label.back} · ${view.label}` : label.back}</span>
      </a>
      {view.ids.length > 1 && i >= 0 && (
        <div className="pdp-step">
          <button type="button" disabled={!prev} onClick={() => go(prev)} aria-label={label.prev}>
            <Arrow dir="start" />
          </button>
          <span aria-live="polite" dir="ltr">
            {i + 1} / {view.ids.length}
          </span>
          <button type="button" disabled={!next} onClick={() => go(next)} aria-label={label.next}>
            <Arrow dir="end" />
          </button>
        </div>
      )}
    </nav>
  );
}
