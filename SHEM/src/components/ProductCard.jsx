import { Heart, ArrowUpLeft, ArrowUpRight } from "@phosphor-icons/react";
import { money } from "../commerce";
import { productImage } from "../experience";
import { hasStock } from "../designers";
// ORDER_SHEM_DEV_013: default photos (knit, shirt, trousers) have a portrait 3:4 version for the regular card only.
const portraitDefault = (src) =>
  src.replace(/^\/assets\/(knit|shirt|trousers)\.png$/, "/assets/$1-3x4.png");
// Same stock sources as the product page: variants.stock, else sizeStock[size], else the general stock.
// Missing stock data means "unknown" (the built-in demo catalogue): nothing is marked.
const stockKnown = (p) =>
  !!p.variants?.length ||
  (!!p.sizeStock && Object.keys(p.sizeStock).length > 0) ||
  typeof p.stock === "number";
const sizeOut = (p, size) => {
  if (p.variants?.length)
    return !p.variants.some((v) => v.size === size && v.stock > 0);
  const n = p.sizeStock?.[size] ?? p.stock;
  return typeof n === "number" && n <= 0;
};
export function ProductCard({ product: p, he, saved, onSave, compact = false }) {
  const name = he ? p.he : p.en;
  const showNew =
    p.isNew &&
    (!p.newUntil || new Date(p.newUntil) >= new Date(new Date().toDateString()));
  const soldOut = stockKnown(p) && !hasStock(p);
  const chips = p.sizes?.length ? (
    p.sizes.map((size) => (
      <span key={size} className={sizeOut(p, size) ? "size-out" : undefined}>
        <bdi>{size}</bdi>
      </span>
    ))
  ) : (
    <>
      <span>{he ? "לפרטי הפריט" : "Explore piece"}</span>
      {he ? <ArrowUpLeft size={18} /> : <ArrowUpRight size={18} />}
    </>
  );
  return (
    <article className="product-card">
      <div className="product-picture">
        {soldOut ? (
          <span className="product-new product-soldout">
            {he ? "אזל" : "SOLD OUT"}
          </span>
        ) : (
          showNew && <span className="product-new">NEW</span>
        )}
        <a href={`#/product/${p.id}`} aria-label={name}>
          <img
            src={compact ? productImage(p) : portraitDefault(productImage(p))}
            alt={name}
            loading="lazy"
            decoding="async"
          />
          <span className="product-discover" aria-hidden="true">
            {chips}
          </span>
        </a>
        <button
          className="favorite"
          aria-label={he ? "שמירה במועדפים" : "Save to wishlist"}
          aria-pressed={saved}
          onClick={() => onSave(p.id)}
        >
          <Heart size={22} weight={saved ? "fill" : "regular"} />
        </button>
      </div>
      <div className="product-details">
        {p.brand && <span className="product-brand">{p.brand}</span>}
        <a className="product-name" href={`#/product/${p.id}`}>
          {name}
        </a>
        <span className={`price ${p.previousPrice ? "sale-price" : ""}`}>
          {p.previousPrice && <del>{money(p.previousPrice)}</del>}{" "}
          {money(p.price)}
        </span>
      </div>
    </article>
  );
}
