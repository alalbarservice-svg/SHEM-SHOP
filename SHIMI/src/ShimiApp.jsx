import { useEffect, useState } from "react";
import { Admin } from "./components/Admin";
import { PolicyPages } from "./components/PolicyPages";
import { Club } from "./components/Club";
import { HomeCheckout } from "./components/HomeCheckout";
import { money } from "./commerce";
import "./shimi.css";
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
      fetch("/api/catalogue").then((r) => r.json()),
      fetch("/api/store").then((r) => r.json()),
    ])
      .then(([c, s]) => {
        setProducts(c.products || []);
        setContent(s);
        if (c.error) setError(c.error);
      })
      .catch(() => setError("לא ניתן לטעון את הקטלוג כרגע."))
      .finally(() => setLoading(false));
  }, []);
  useEffect(() => {
    if (!cart.length) {
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
  const grid = products.filter((p) =>
    [p.he, p.brand].some((x) =>
      String(x || "")
        .toLowerCase()
        .includes(query.toLowerCase()),
    ),
  );
  return (
    <div className="shimi-app" dir="rtl">
      <a className="skip" href="#main">
        דילוג לתוכן
      </a>
      <header className="shimi-header">
        <a className="shimi-logo" href="#/" dir="ltr">
          SHIMI<span>EVERYDAY BASICS</span>
        </a>
        <nav aria-label="ניווט ראשי">
          <a href="#/">החנות</a>
          <a href="#/club">מועדון חברים</a>
          <a href="#/cart">סל קניות ({totalQty})</a>
        </nav>
      </header>
      <main id="main">
        {error && (
          <p className="notice" role="alert">
            {error}
          </p>
        )}
        {route === "/" && (
          <>
            <section className="shimi-intro">
              <div className="intro-copy">
                <span>SHIMI / BASICS</span>
                <h1>{content.heroTitle || "פשוט. נוח. כל יום."}</h1>
                <p>{content.heroSubtitle || "פריטי בייסיק, במקום אחד."}</p>
                <button
                  className="button dark"
                  onClick={() =>
                    document
                      .getElementById("catalogue")
                      ?.scrollIntoView({ behavior: "smooth" })
                  }
                >
                  לכל הפריטים
                </button>
              </div>
              <div className="intro-media">
                <img
                  src="/assets/shirt.png"
                  alt="חולצה מכופתרת שחורה מקטלוג ההדגמה"
                  fetchPriority="high"
                  width="1448"
                  height="1086"
                />
                <span dir="ltr">THE EVERYDAY EDIT</span>
              </div>
            </section>
            <p className="demo-notice">
              קטלוג הדגמה - הפריטים והתמונות להמחשה. הרכישה אינה פעילה.
            </p>
            <section id="catalogue">
              <div className="catalogue-heading">
                <h2>הפריטים שלנו</h2>
                <label>
                  חיפוש פריט
                  <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="שם פריט או מותג"
                  />
                </label>
              </div>
              {loading ? (
                <p role="status">טוענים את החנות...</p>
              ) : (
                <>
                  {!grid.length && (
                    <p className="empty">
                      הקטלוג מתעדכן. הפריטים יופיעו כאן לאחר הוספתם בממשק
                      הניהול.
                    </p>
                  )}
                  <div className="shimi-grid">
                    {grid.map((p) => (
                      <article key={p.id}>
                        <a href={"#/product/" + p.id}>
                          {p.imageUrl ? (
                            <img src={p.imageUrl} alt={p.he} loading="lazy" />
                          ) : (
                            <div className="product-placeholder">SHIMI</div>
                          )}
                          <small>{p.brand || "SHIMI"}</small>
                          <h3>{p.he}</h3>
                        </a>
                        <p>
                          {p.previousPrice && (
                            <del>{money(p.previousPrice)}</del>
                          )}{" "}
                          {money(p.price)}
                        </p>
                        {p.demo ? (
                          <small className="demo-label">פריט הדגמה</small>
                        ) : (
                          p.stock < 1 && <small>אזל מהמלאי</small>
                        )}
                      </article>
                    ))}
                  </div>
                </>
              )}
            </section>
          </>
        )}
        {route.startsWith("/product/") &&
          (product ? (
            <section className="shimi-detail">
              <div>
                {product.imageUrl ? (
                  <img src={product.imageUrl} alt={product.he} />
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
                  disabled={product.demo || !size || product.stock < 1}
                  onClick={add}
                >
                  {product.demo ? "פריט הדגמה - אינו למכירה" : "הוספה לסל"}
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
