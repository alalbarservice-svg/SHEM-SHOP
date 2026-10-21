import { useEffect, useState } from "react";
import { Admin } from "./components/Admin";
import { PolicyPages } from "./components/PolicyPages";
import { Club } from "./components/Club";
import { HomeCheckout } from "./components/HomeCheckout";
import { money } from "./commerce";
import { PdpNav, installListMemory } from "./pdp-nav";
import "./shimi.css";
installListMemory();
const PREVIEW = import.meta.env.VITE_PREVIEW === "1";
const BASE = (import.meta.env.BASE_URL || "/").replace(/\/$/, "");
const media = (u) => (PREVIEW && u && u.startsWith("/") ? BASE + u : u);
const hidden = (p) =>
  /^t_admin_|בדיקת QA/.test(p.he || "") ||
  /^(qa-|demo-shimi)/.test(String(p.id));
const CATS = [
  { key: "tops", he: "חולצות", en: "TOPS", pick: "אוקספורד לבנה" },
  { key: "bottoms", he: "מכנסיים וג'ינס", en: "BOTTOMS", pick: "ג'ינס ישר כחול" },
  { key: "shoes", he: "נעליים", en: "SHOES", pick: "סניקרס עור מינימליסטיות לבנות" },
  { key: "caps", he: "כובעים", en: "CAPS", pick: "מצחייה נייבי" },
];
const catOf = (p) => {
  const n = p.he || "";
  if (p.cat === "shoes" || /נעל|סניקרס/.test(n)) return "shoes";
  if (p.cat === "accessories" || /כובע/.test(n)) return "caps";
  if (/מכנס|ג'ינס|צ'ינו|טרנינג/.test(n)) return "bottoms";
  return "tops";
};
const COLLECTIONS = [
  {
    key: "essentials",
    he: "האסנשיאלס",
    en: "THE ESSENTIALS",
    blurb: "החלקים שמשתלבים עם כל השאר: טי כותנה, ג'ינס ישר וסניקרס נקיות.",
    test: /טי כותנה|ג'ינס ישר|סניקרס|כובע גרב|אוקספורד/,
    pick: "טי כותנה לבנה",
  },
  {
    key: "stone",
    he: "גווני אבן",
    en: "STONE TONES",
    blurb: "אבן, בז' ואופוויט: פלטה רכה ובהירה לימים חמים.",
    test: /אבן|בז'|אופוויט/,
    pick: "פשתן אבן",
  },
  {
    key: "black",
    he: "שחור על שחור",
    en: "ALL BLACK",
    blurb: "פריטים בשחור מלא, מחולצה ועד נעליים.",
    test: /שחור/,
    pick: "ג'ינס ישר שחור",
  },
];
const pickImg = (list, hint) =>
  (list.find((p) => (p.he || "").includes(hint) && p.imageUrl) ||
    list.find((p) => p.imageUrl) ||
    {}).imageUrl;
const sorters = {
  def: null,
  asc: (a, b) => a.price - b.price,
  desc: (a, b) => b.price - a.price,
};
function Card({ p }) {
  return (
    <article className="pcard">
      <a href={"#/product/" + p.id}>
        <div className="pcard-img">
          {p.imageUrl ? (
            <img src={media(p.imageUrl)} alt={p.he} loading="lazy" />
          ) : (
            <div className="product-placeholder">SHIMI</div>
          )}
          {p.demo && <span className="tag">הדגמה</span>}
        </div>
        <small>{p.brand || "SHIMI"}</small>
        <h3>{p.he}</h3>
        <p>
          {p.previousPrice && <del>{money(p.previousPrice)}</del>}{" "}
          {money(p.price)}
        </p>
      </a>
    </article>
  );
}
function Listing({ title, en, blurb, items, active }) {
  const [sort, setSort] = useState("def");
  const list = sorters[sort] ? [...items].sort(sorters[sort]) : items;
  return (
    <section className="listing">
      <nav className="crumbs" aria-label="מיקום">
        <a href="#/">החנות</a> / <span>{title}</span>
      </nav>
      <header className="listing-head">
        <span dir="ltr">{en}</span>
        <h1>{title}</h1>
        {blurb && <p>{blurb}</p>}
      </header>
      <div className="listing-bar">
        <div className="chips">
          <a className={!active ? "on" : ""} href="#/all">
            הכול
          </a>
          {CATS.map((c) => (
            <a
              key={c.key}
              className={active === c.key ? "on" : ""}
              href={"#/category/" + c.key}
            >
              {c.he}
            </a>
          ))}
        </div>
        <label className="sort">
          מיון
          <select value={sort} onChange={(e) => setSort(e.target.value)}>
            <option value="def">מומלצים</option>
            <option value="asc">מחיר: מהנמוך לגבוה</option>
            <option value="desc">מחיר: מהגבוה לנמוך</option>
          </select>
        </label>
      </div>
      <p className="count" role="status">
        {list.length} פריטים
      </p>
      {!list.length && <p className="empty">אין פריטים להצגה כרגע.</p>}
      <div className="pgrid">
        {list.map((p) => (
          <Card key={p.id} p={p} />
        ))}
      </div>
    </section>
  );
}
const readCart = () => {
  try {
    return JSON.parse(localStorage.getItem("shimi-cart")) || [];
  } catch {
    return [];
  }
};
export function App() {
  const [route, setRoute] = useState(
      location.hash.slice(1) ||
        (location.pathname === "/admin.html" ? "/admin" : "/"),
    ),
    [products, setProducts] = useState([]),
    [content, setContent] = useState({}),
    [loading, setLoading] = useState(true),
    [error, setError] = useState(""),
    [cart, setCart] = useState(readCart),
    [query, setQuery] = useState(""),
    [size, setSize] = useState(""),
    [variantId, setVariant] = useState(""),
    [shipping, setShipping] = useState("standard"),
    [customer, setCustomer] = useState({}),
    [code, setCode] = useState(""),
    [quote, setQuote] = useState(null),
    [locked, setLocked] = useState(false);
  useEffect(() => {
    const change = () => {
      setRoute(location.hash.slice(1) || "/");
      setSize("");
      setVariant("");
      setLocked(false);
      window.scrollTo(0, 0);
    };
    addEventListener("hashchange", change);
    return () => removeEventListener("hashchange", change);
  }, []);
  useEffect(() => {
    localStorage.setItem("shimi-cart", JSON.stringify(cart));
  }, [cart]);
  useEffect(() => {
    Promise.all([
      fetch(PREVIEW ? BASE + "/data/catalogue.json" : "/api/catalogue").then((r) =>
        r.json(),
      ),
      fetch(PREVIEW ? BASE + "/data/store.json" : "/api/store").then((r) =>
        r.json(),
      ),
    ])
      .then(([c, s]) => {
        setProducts((c.products || []).filter((p) => !hidden(p)));
        setContent(s);
        if (c.error) setError(c.error);
      })
      .catch(() => setError("לא ניתן לטעון את הקטלוג כרגע."))
      .finally(() => setLoading(false));
  }, []);
  useEffect(() => {
    if (!cart.length || PREVIEW) {
      setQuote(null);
      return;
    }
    let live = true;
    setQuote(null);
    fetch("/api/quote", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ items: cart, shipping, code }),
    })
      .then(async (r) => {
        const d = await r.json();
        if (!r.ok) throw Error(d.error);
        if (live) {
          setQuote(d);
          setError("");
        }
      })
      .catch((e) => {
        if (live) setError(e.message);
      });
    return () => {
      live = false;
    };
  }, [cart, shipping, code]);
  if (route.startsWith("/admin")) return <Admin route={route} />;
  const product = products.find((p) => p.id === route.split("/")[2]),
    op = content.operations || {},
    totalQty = cart.reduce((n, i) => n + i.qty, 0);
  const add = () => {
    if (!product || product.demo || !size) return;
    const v = product.variants?.find((v) => v.id === variantId);
    if (product.variants?.length && !v) return;
    const available = v?.stock ?? product.sizeStock?.[size] ?? product.stock;
    if (available < 1) return;
    setCart((items) => {
      const old = items.find(
        (i) =>
          i.id === product.id &&
          i.size === size &&
          i.variantId === (v?.id || ""),
      );
      if (old && old.qty >= Math.min(available, 5)) return items;
      return old
        ? items.map((i) => (i === old ? { ...i, qty: i.qty + 1 } : i))
        : [
            ...items,
            {
              id: product.id,
              size,
              variantId: v?.id || "",
              qty: 1,
              he: product.he,
              price: product.price,
            },
          ];
    });
    location.hash = "/cart";
  };
  const byCat = (k) => products.filter((p) => catOf(p) === k);
  const search = products.filter((p) =>
    [p.he, p.brand].some((x) =>
      String(x || "")
        .toLowerCase()
        .includes(query.toLowerCase()),
    ),
  );
  const [r0, r1, r2] = route.split("/").slice(1);
  const related = product
    ? products
        .filter((p) => p.id !== product.id && catOf(p) === catOf(product))
        .slice(0, 4)
    : [];
  const fresh = products.slice(0, 8);
  return (
    <div className="shimi-app" dir="rtl">
      <a className="skip" href="#main">
        דילוג לתוכן
      </a>
      <p className="topbar">
        {PREVIEW
          ? "תצוגה מקדימה לאישור - הרכישה אינה פעילה"
          : "קטלוג הדגמה - הפריטים והתמונות להמחשה. הרכישה אינה פעילה."}
      </p>
      <header className="shimi-header">
        <a className="shimi-logo" href="#/" dir="ltr">
          SHIMI<span>EVERYDAY BASICS</span>
        </a>
        <nav aria-label="ניווט ראשי" className="main-nav">
          <a href="#/all">הכול</a>
          {CATS.filter((c) => byCat(c.key).length).map((c) => (
            <a key={c.key} href={"#/category/" + c.key}>
              {c.he}
            </a>
          ))}
          <a href="#/collection/essentials">קולקציות</a>
          <a href="#/club">מועדון חברים</a>
        </nav>
        {!PREVIEW && (
          <a className="cart-link" href="#/cart">
            סל קניות ({totalQty})
          </a>
        )}
      </header>
      <main id="main">
        {error && (
          <p className="notice" role="alert">
            {error}
          </p>
        )}
        {route === "/" && (
          <>
            <section className="hero">
              <div className="hero-copy">
                <span dir="ltr">EVERYDAY BASICS</span>
                <h1>{content.heroTitle || "בסיס טוב. כל יום."}</h1>
                <p>
                  {content.heroSubtitle ||
                    "חולצות, מכנסיים, נעליים וכובעים בגזרות נקיות וצבעים שמשתלבים זה בזה."}
                </p>
                <div className="hero-cta">
                  <a className="button dark" href="#/collection/essentials">
                    לקולקציית האסנשיאלס
                  </a>
                  <a className="button" href="#/all">
                    לכל הפריטים
                  </a>
                </div>
              </div>
              <div className="hero-media" aria-hidden="true">
                {[
                  pickImg(products, "טי כותנה לבנה"),
                  pickImg(products, "ג'ינס ישר כחול"),
                  pickImg(products, "סניקרס עור מינימליסטיות לבנות"),
                  ...products.map((p) => p.imageUrl),
                ]
                  .filter((u, i, a) => u && a.indexOf(u) === i)
                  .slice(0, 3)
                  .map((u, i) => (
                  <img key={i} src={media(u)} alt="" fetchPriority="high" />
                ))}
              </div>
            </section>
            <section className="strip" aria-label="עקרונות">
              <p>בייסיק לכל יום</p>
              <p>צבעים שמשתלבים זה בזה</p>
              <p>גזרות פשוטות ונקיות</p>
              <p>מחיר ברור, בלי הפתעות</p>
            </section>
            <section className="block">
              <header className="block-head">
                <h2>קונים לפי קטגוריה</h2>
                <a href="#/all">לכל הפריטים</a>
              </header>
              <div className="tiles">
                {CATS.filter((c) => byCat(c.key).length).map((c) => (
                  <a key={c.key} className="tile" href={"#/category/" + c.key}>
                    <img
                      src={media(pickImg(byCat(c.key), c.pick))}
                      alt=""
                      loading="lazy"
                    />
                    <span dir="ltr">{c.en}</span>
                    <strong>{c.he}</strong>
                    <small>{byCat(c.key).length} פריטים</small>
                  </a>
                ))}
              </div>
            </section>
            <section className="block">
              <header className="block-head">
                <h2>קולקציות</h2>
              </header>
              <div className="collections">
                {COLLECTIONS.map((c) => {
                  const l = products.filter((p) => c.test.test(p.he || ""));
                  return (
                    <a
                      key={c.key}
                      className="collection"
                      href={"#/collection/" + c.key}
                    >
                      <img
                        src={media(pickImg(l, c.pick))}
                        alt=""
                        loading="lazy"
                      />
                      <div>
                        <span dir="ltr">{c.en}</span>
                        <strong>{c.he}</strong>
                        <small>{l.length} פריטים</small>
                      </div>
                    </a>
                  );
                })}
              </div>
            </section>
            <section className="block" id="catalogue">
              <header className="block-head">
                <h2>חדש בחנות</h2>
                <label className="search">
                  חיפוש פריט
                  <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="שם פריט"
                  />
                </label>
              </header>
              {loading ? (
                <p role="status">טוענים את החנות...</p>
              ) : (
                <>
                  {!search.length && (
                    <p className="empty">לא נמצאו פריטים תואמים.</p>
                  )}
                  <div className="pgrid">
                    {(query ? search : fresh).map((p) => (
                      <Card key={p.id} p={p} />
                    ))}
                  </div>
                </>
              )}
            </section>
            <section className="club-band">
              <div>
                <span dir="ltr">SHIMI CLUB</span>
                <h2>מועדון החברים</h2>
                <p>הצטרפו וקבלו עדכונים על פריטים חדשים.</p>
              </div>
              <a className="button" href="#/club">
                להצטרפות
              </a>
            </section>
          </>
        )}
        {r0 === "all" && (
          <Listing
            title="כל הפריטים"
            en="ALL ITEMS"
            blurb="כל הקטלוג במקום אחד."
            items={products}
          />
        )}
        {r0 === "category" && CATS.find((c) => c.key === r1) && (
          <Listing
            title={CATS.find((c) => c.key === r1).he}
            en={CATS.find((c) => c.key === r1).en}
            items={byCat(r1)}
            active={r1}
          />
        )}
        {r0 === "collection" && COLLECTIONS.find((c) => c.key === r1) && (
          <Listing
            title={COLLECTIONS.find((c) => c.key === r1).he}
            en={COLLECTIONS.find((c) => c.key === r1).en}
            blurb={COLLECTIONS.find((c) => c.key === r1).blurb}
            items={products.filter((p) =>
              COLLECTIONS.find((c) => c.key === r1).test.test(p.he || ""),
            )}
          />
        )}
        {route.startsWith("/product/") &&
          (product ? (
            <section className="shimi-detail">
              <PdpNav
                id={product.id}
                products={products}
                fallbackIds={products.filter((p) => catOf(p) === catOf(product)).map((p) => p.id)}
                fallbackHref={"#/category/" + catOf(product)}
                fallbackLabel=""
                label={{ nav: "ניווט בין פריטים", back: "חזרה", prev: "הפריט הקודם", next: "הפריט הבא" }}
              />
              <nav className="crumbs wide" aria-label="מיקום">
                <a href="#/">החנות</a> /{" "}
                <a href={"#/category/" + catOf(product)}>
                  {CATS.find((c) => c.key === catOf(product)).he}
                </a>{" "}
                / <span>{product.he}</span>
              </nav>
              <div>
                {product.imageUrl ? (
                  <img src={media(product.imageUrl)} alt={product.he} />
                ) : (
                  <div className="product-placeholder">SHIMI</div>
                )}
              </div>
              <div>
                <small>{product.brand || "SHIMI"}</small>
                <h1>{product.he}</h1>
                <p>{product.description}</p>
                <h2>
                  {product.previousPrice && (
                    <del>{money(product.previousPrice)}</del>
                  )}{" "}
                  {money(product.price)}
                </h2>
                {product.variants?.length ? (
                  <label>
                    מידה וצבע
                    <select
                      value={variantId}
                      onChange={(e) => {
                        const v = product.variants.find(
                          (v) => v.id === e.target.value,
                        );
                        setVariant(e.target.value);
                        setSize(v?.size || "");
                      }}
                    >
                      <option value="">בחרו גרסה</option>
                      {product.variants.map((v) => (
                        <option key={v.id} value={v.id} disabled={v.stock < 1}>
                          {v.size} / {v.color}
                          {v.stock < 1 ? " - אזל מהמלאי" : ""}
                        </option>
                      ))}
                    </select>
                  </label>
                ) : (
                  <label>
                    מידה
                    <select
                      value={size}
                      onChange={(e) => setSize(e.target.value)}
                    >
                      <option value="">בחרו מידה</option>
                      {product.sizes.map((s) => (
                        <option
                          key={s}
                          value={s}
                          disabled={
                            (product.sizeStock?.[s] ?? product.stock) < 1
                          }
                        >
                          {s}
                        </option>
                      ))}
                    </select>
                  </label>
                )}
                <button
                  className="button dark"
                  disabled={PREVIEW || product.demo || !size || product.stock < 1}
                  onClick={add}
                >
                  {PREVIEW || product.demo ? "פריט הדגמה - אינו למכירה" : "הוספה לסל"}
                </button>
                <details>
                  <summary>הרכב וטיפול</summary>
                  <p>{product.material}</p>
                  <p>{product.care}</p>
                </details>
              </div>
            </section>
          ) : (
            <p>המוצר אינו זמין.</p>
          ))}
        {route.startsWith("/product/") && product && related.length > 0 && (
          <section className="block">
            <header className="block-head">
              <h2>עוד מאותה קטגוריה</h2>
            </header>
            <div className="pgrid">
              {related.map((p) => (
                <Card key={p.id} p={p} />
              ))}
            </div>
          </section>
        )}
        {route === "/cart" && (
          <section>
            <h1>סל הקניות</h1>
            {!cart.length ? (
              <p>
                הסל שלך ריק. <a href="#/">חזרה לחנות</a>
              </p>
            ) : (
              <div className="shimi-checkout">
                <div>
                  {cart.map((i, k) => (
                    <div
                      className="cart-row"
                      key={i.id + ":" + i.size + ":" + i.variantId}
                    >
                      <div>
                        <h3>{i.he}</h3>
                        <p>
                          מידה {i.size} |{" "}
                          {money(quote?.items?.[k]?.price ?? i.price)}
                        </p>
                      </div>
                      <label>
                        כמות
                        <input
                          type="number"
                          min="1"
                          max="5"
                          disabled={locked}
                          value={i.qty}
                          onChange={(e) =>
                            setCart(
                              cart.map((x, n) =>
                                n === k
                                  ? {
                                      ...x,
                                      qty: Math.max(
                                        1,
                                        Math.min(
                                          5,
                                          Number(e.target.value) || 1,
                                        ),
                                      ),
                                    }
                                  : x,
                              ),
                            )
                          }
                        />
                      </label>
                      <button
                        disabled={locked}
                        onClick={() => setCart(cart.filter((_, n) => n !== k))}
                      >
                        הסרה
                      </button>
                    </div>
                  ))}
                  <label>
                    קוד הטבה
                    <input
                      disabled={locked}
                      value={code}
                      onChange={(e) => setCode(e.target.value)}
                    />
                  </label>
                  {quote && (
                    <dl>
                      <dt>מחיר הפריטים</dt>
                      <dd>{money(quote.subtotal)}</dd>
                      <dt>הנחה</dt>
                      <dd>{money(quote.discount)}</dd>
                      <dt>מחיר הפריטים לפני מע״מ</dt>
                      <dd>{money(quote.netSubtotal)}</dd>
                      <dt>מע״מ כלול 18%</dt>
                      <dd>{money(quote.vatIncluded)}</dd>
                      <dt>משלוח</dt>
                      <dd>{money(quote.shipping)}</dd>
                      <dt>סה״כ לתשלום</dt>
                      <dd>
                        <strong>{money(quote.total)}</strong>
                      </dd>
                    </dl>
                  )}
                </div>
                <div>
                  {quote ? (
                    <HomeCheckout
                      onReview={setQuote}
                      cart={cart}
                      shipping={shipping}
                      onShipping={setShipping}
                      code={code}
                      customer={customer}
                      setCustomer={setCustomer}
                      operations={op}
                      onLock={setLocked}
                    />
                  ) : (
                    <p>יש להשלים בדיקת סל תקינה לפני תשלום.</p>
                  )}
                </div>
              </div>
            )}
          </section>
        )}
        {route === "/club" && <Club />}
        {[
          "terms",
          "privacy",
          "returns",
          "accessibility",
          "payments",
          "cancel",
          "contact",
        ].includes(route.slice(1)) && (
          <PolicyPages
            page={route.slice(1)}
            business={content.business || {}}
            operations={op}
          />
        )}
      </main>
      <footer className="shimi-footer">
        <a className="shimi-logo" href="#/" dir="ltr">
          SHIMI
        </a>
        <nav>
          <a href="#/contact">שירות לקוחות</a>
          <a href="#/payments">משלוחים ותשלום</a>
          <a href="#/returns">החלפות והחזרות</a>
          <a href="#/terms">תנאי שימוש</a>
          <a href="#/privacy">פרטיות</a>
          <a href="#/accessibility">נגישות</a>
          <a href="#/cancel">ביטול עסקה</a>
        </nav>
        <small>© {new Date().getFullYear()} SHIMI</small>
      </footer>
    </div>
  );
}
