import { useState } from "react";
import { InventoryFilters } from "./InventoryFilters";
import { matchesAvailability, matchesSearch } from "../experience";
import { brandLogoAssets } from "../brand-logo-assets";
import { ArrowLeft, ArrowRight } from "@phosphor-icons/react";
import {
  designers,
  designerProducts,
  productTypes,
  productType,
} from "../designers";
import { ProductRail } from "./ProductRail";
export function DesignerLogo({
  designer,
  tone = "black",
  className = "",
  priority = false,
}) {
  if (!designer) return null;
  return (
    <img
      className={`designer-logo designer-logo--${tone} ${className} logo-${designer.slug}`}
      src={brandLogoAssets[`${designer.slug}-${tone}`]}
      alt={designer.name}
      width={designer.width || 2172}
      height={designer.height || 724}
      loading="eager"
      fetchPriority={priority ? "high" : undefined}
      decoding="async"
    />
  );
}
export function DesignerChoices({ onChoose, selected, className = "" }) {
  return (
    <div className={`designer-choices ${className}`}>
      {designers.map((d) => (
        <button
          key={d.slug}
          type="button"
          aria-label={d.name}
          aria-pressed={selected === d.slug}
          onClick={() => onChoose(d)}
        >
          <DesignerLogo designer={d} />
        </button>
      ))}
    </div>
  );
}
export function DesignerRails({ products, card, he }) {
  const pad = (n) => String(n).padStart(2, "0");
  return (
    <div className="designer-collections">
      {designers.map((d, i) => {
        const items = designerProducts(products, d);
        return (
          <section
            className="selection designer-collection"
            key={d.slug}
            aria-label={d.name}
          >
            <div className="designer-band">
              <span className="designer-index" dir="ltr" aria-hidden="true">
                N° {pad(i + 1)}
              </span>
              <h2>
                <a
                  href={`#/brand/${d.slug}`}
                  aria-label={he ? `לקולקציית ${d.name}` : `Explore ${d.name}`}
                >
                  <DesignerLogo designer={d} tone="white" />
                </a>
              </h2>
              <span className="designer-meta" dir="ltr">
                THE COLLECTION
                {items.length ? ` · ${items.length} ${items.length === 1 ? "PIECE" : "PIECES"}` : ""}
              </span>
            </div>
            {items.length ? (
              <>
                <ProductRail he={he} label={d.name}>
                  {items.map(card)}
                </ProductRail>
                <div className="designer-cta-row">
                  <a className="button luxe" href={`#/brand/${d.slug}`}>
                    {he ? `לקולקציית ${d.name}` : `Explore ${d.name}`}
                    <ArrowRight size={19} />
                  </a>
                </div>
              </>
            ) : (
              <div className="designer-empty">
                <p>
                  {he
                    ? "הקולקציה תתעדכן בקרוב"
                    : "The collection will be updated soon"}
                </p>
              </div>
            )}
          </section>
        );
      })}
    </div>
  );
}
export function DesignerPage({ designer, products, card, he }) {
  const [type, setType] = useState(""),
    [sort, setSort] = useState("featured");
  const all = designerProducts(products, designer);
  const groups = productTypes.filter(([key]) =>
    all.some((p) => productType(p) === key),
  );
  const [query, setQuery] = useState(""),
    [size, setSize] = useState(""),
    [color, setColor] = useState(""),
    [onlyStock, setOnlyStock] = useState(false);
  const visible = all
    .filter(
      (p) =>
        (!type || productType(p) === type) &&
        matchesSearch(p, query) &&
        matchesAvailability(p, size, color, onlyStock),
    )
    .slice()
    .sort((a, b) =>
      sort === "low"
        ? a.price - b.price
        : sort === "high"
          ? b.price - a.price
          : 0,
    );
  return (
    <section className="designer-page" aria-label={designer.name}>
      <header className="designer-page-hero">
        <h1>
          <DesignerLogo designer={designer} tone="white" priority />
        </h1>
      </header>
      <div className="designer-page-content">
        <div className="designer-page-topline">
          <a className="designer-back" href="#/designers">
            {he ? <ArrowRight size={16} /> : <ArrowLeft size={16} />}
            {he ? "מותגי SHEM" : "SHEM designers"}
          </a>
          <span className="designer-page-signature" dir="ltr">
            SHEM / THE EDIT
          </span>
        </div>
        <div className="designer-page-tools">
          <div className="designer-page-intro">
            <h2>{he ? "הקולקציה" : "The collection"}</h2>
            <span>
              {all.length} {he ? "פריטים" : "pieces"}
            </span>
          </div>
          <nav
            className="designer-types"
            aria-label={he ? "סוגי פריטים" : "Product types"}
          >
            <button aria-pressed={!type} onClick={() => setType("")}>
              {he ? "כל הפריטים" : "All pieces"}
              <span>{all.length}</span>
            </button>
            {groups.map(([key, h, e]) => (
              <button
                key={key}
                aria-pressed={type === key}
                onClick={() => setType(key)}
              >
                {he ? h : e}
                <span>{all.filter((p) => productType(p) === key).length}</span>
              </button>
            ))}
          </nav>
          <div className="designer-results">
            <span>
              {visible.length} {he ? "פריטים" : "pieces"}
            </span>
            <label>
              {he ? "מיון" : "Sort"}
              <select value={sort} onChange={(e) => setSort(e.target.value)}>
                <option value="featured">{he ? "מומלצים" : "Featured"}</option>
                <option value="low">
                  {he ? "מחיר מהנמוך לגבוה" : "Price low to high"}
                </option>
                <option value="high">
                  {he ? "מחיר מהגבוה לנמוך" : "Price high to low"}
                </option>
              </select>
            </label>
          </div>
        </div>
        <InventoryFilters
          products={all}
          he={he}
          query={query}
          onQuery={setQuery}
          size={size}
          onSize={setSize}
          color={color}
          onColor={setColor}
          onlyStock={onlyStock}
          onStock={setOnlyStock}
        />
        {visible.length ? (
          <div className="designer-type-sections">
            {groups
              .filter(([key]) => !type || key === type)
              .map(([key, h, e], index) => {
                const items = visible.filter((p) => productType(p) === key);
                return items.length ? (
                  <section key={key} className="designer-type-section">
                    <div className="designer-type-heading">
                      <span
                        className="designer-section-index"
                        aria-hidden="true"
                      >
                        {String(index + 1).padStart(2, "0")}
                      </span>
                      <h2>{he ? h : e}</h2>
                      <span className="designer-section-count">
                        {items.length} {he ? "פריטים" : "pieces"}
                      </span>
                    </div>
                    <div className="product-grid">{items.map(card)}</div>
                  </section>
                ) : null;
              })}
          </div>
        ) : (
          <div className="empty designer-page-empty">
            <h2>
              {he
                ? all.length
                  ? "לא נמצאו פריטים בסינון שבחרתם"
                  : "הקולקציה תתעדכן בקרוב"
                : all.length
                  ? "No pieces match your filters"
                  : "The collection will be updated soon"}
            </h2>
            <a className="button dark" href="#/designers">
              {he ? "למותגים נוספים" : "Explore other designers"}
            </a>
          </div>
        )}
        <nav
          className="designer-page-more"
          aria-label={he ? "מותגים נוספים" : "More designers"}
        >
          <div className="designer-more-heading">
            <h2>{he ? "עוד ממותגי SHEM" : "More SHEM designers"}</h2>
            <a href="#/designers">{he ? "כל המותגים" : "All designers"}</a>
          </div>
          <div className="designer-page-other-brands">
            {designers
              .filter((d) => d.slug !== designer.slug)
              .map((d) => (
                <a key={d.slug} href={`#/brand/${d.slug}`} aria-label={d.name}>
                  <DesignerLogo designer={d} />
                </a>
              ))}
          </div>
        </nav>
      </div>
    </section>
  );
}
