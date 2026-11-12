import { Heart, ArrowUpLeft, ArrowUpRight } from "@phosphor-icons/react";
import { money } from "../commerce";
import { productImage } from "../experience";
export function ProductCard({ product: p, he, saved, onSave }) {
  const name = he ? p.he : p.en;
  const showNew =
    p.isNew &&
    (!p.newUntil || new Date(p.newUntil) >= new Date(new Date().toDateString()));
  return (
    <article className="product-card">
      <div className="product-picture">
        {showNew && <span className="product-new">NEW</span>}
        <a href={`#/product/${p.id}`} aria-label={name}>
          <img
            src={productImage(p)}
            alt={name}
            loading="lazy"
            decoding="async"
          />
          <span className="product-discover" aria-hidden="true">
            {p.sizes?.length ? (
              p.sizes.map((size) => <span key={size}>{size}</span>)
            ) : (
              <>
                <span>{he ? "לפרטי הפריט" : "Explore piece"}</span>
                {he ? <ArrowUpLeft size={18} /> : <ArrowUpRight size={18} />}
              </>
            )}
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
