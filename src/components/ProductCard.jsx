import { Heart, ArrowUpLeft, ArrowUpRight } from "@phosphor-icons/react";
import { money } from "../commerce";
export function ProductCard({ product: p, he, saved, onSave }) {
  const name = he ? p.he : p.en;
  return (
    <article className="product-card">
      <div className="product-picture">
        <a href={`#/product/${p.id}`} aria-label={name}>
          <img
            src={p.imageUrl || `/assets/${p.image || p.id}.png`}
            alt={name}
            loading="lazy"
            decoding="async"
          />
          <span className="product-discover" aria-hidden="true">
            <span>{he ? "לפרטי הפריט" : "Explore piece"}</span>
            {he ? <ArrowUpLeft size={18} /> : <ArrowUpRight size={18} />}
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
