import { designers, designerFor } from "./designers";
import { shemMonogram } from "./brand-logo-assets";
import {
  DesignerLogo,
  DesignerChoices,
  DesignerRails,
  DesignerPage,
} from "./components/DesignerCollections";
import { HomeCheckout } from "./components/HomeCheckout";
import { CustomerArea } from "./components/CustomerArea";
import { HelpCenter } from "./components/HelpCenter";
import {
  ProductGallery,
  SizeGuide,
  StockWatch,
} from "./components/ProductExperience";
import {
  InventoryFilters,
  MeasurementChoice,
} from "./components/InventoryFilters";
import {
  matchesSearch,
  complementaryProducts,
  colourName,
  lineColour,
  matchesAvailability,
  productImage,
  experienceEvent,
} from "./experience";
import "./components/experience.css";
import { useState, useEffect, useRef } from "react";
import {
  InstagramLogo,
  MagnifyingGlass,
  User,
  Handbag,
  Heart,
  X,
  ArrowRight,
  ArrowLeft,
  List,
  Minus,
  Plus,
  SlidersHorizontal,
  Truck,
  ArrowCounterClockwise,
  Check,
} from "@phosphor-icons/react";
import "@fontsource/playfair-display/latin-400.css";
import "@fontsource/montserrat/latin-400.css";
import "@fontsource/arimo/hebrew-400.css";
import "@fontsource/arimo/latin-400.css";
import "@fontsource/arimo/hebrew-700.css";
import "./styles.css";
import { brands as defaultBrands, products as demoProducts } from "./catalogue";
import { Admin } from "./components/Admin";
import { money, addLine, subtotal, safeCart } from "./commerce";
import { Club } from "./components/Club";
import { ProductRail } from "./components/ProductRail";
import { ProductCard } from "./components/ProductCard";
import { HeroCarousel } from "./components/HeroCarousel";
import { PolicyPages, SUPPORT_EMAIL, WHATSAPP } from "./components/PolicyPages";
import { CouponQuote } from "./components/CouponQuote";
const stored = (k, d) => {
  try {
    return JSON.parse(localStorage.getItem(k)) ?? d;
  } catch {
    return d;
  }
};
export function App() {
  const [checkoutLocked, setCheckoutLocked] = useState(false);
  const originalCart = useRef(stored("shem-cart", [])),
    hydratedCart = useRef(false);
  const [route, setRoute] = useState(
      location.hash.slice(1) ||
        (location.pathname === "/admin.html" ? "/admin" : "/"),
    ),
    [panel, setPanel] = useState(null);

  const [products, setProducts] = useState(demoProducts),
    [brands, setBrands] = useState(defaultBrands),
    [storeContent, setStoreContent] = useState({}),
    [collections, setCollections] = useState([]),
    [catalogueConnected, setCatalogueConnected] = useState(false),
    [catalogueStatus, setCatalogueStatus] = useState("loading"),
    [couponCode, setCouponCode] = useState(""),
    [quote, setQuote] = useState(null);
  useEffect(() => {
    fetch("/api/catalogue")
      .then((r) => {
        if (!r.ok) throw Error();
        return r.json();
      })
      .then((data) => {
        setCatalogueStatus("ready");
        if (data?.configured) {
          setProducts(data.products);
          setCatalogueConnected(true);
          if (!hydratedCart.current) {
            setCart(safeCart(originalCart.current, data.products));
            hydratedCart.current = true;
          }
        }
      })
      .catch(() => setCatalogueStatus("error"));
    fetch("/api/collections")
      .then((r) => (r.ok ? r.json() : []))
      .then(setCollections)
      .catch(() => {});
    fetch("/api/store")
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (data) {
          setStoreContent(data);
          if (data.brands?.length) setBrands(data.brands);
        }
      })
      .catch(() => {});
  }, [route]);
  useEffect(() => {
    setCheckoutLocked(false);
  }, [route]);
  const [lang, setLang] = useState(() => stored("shem-lang", "he")),
    he = lang === "he",
    t = (h, e) => (he ? h : e);
  const [cart, setCart] = useState(() =>
      safeCart(stored("shem-cart", []), products),
    ),
    [wish, setWish] = useState(() => stored("shem-wish", []));
  const [query, setQuery] = useState(""),
    [brand, setBrand] = useState(""),
    [cat, setCat] = useState(""),
    [sort, setSort] = useState("featured"),
    [max, setMax] = useState(null),
    [size, setSize] = useState(""),
    [variantId, setVariantId] = useState(""),
    [colorChoice, setColorChoice] = useState(""),
    [filterSize, setFilterSize] = useState(""),
    [filterColor, setFilterColor] = useState(""),
    [onlyStock, setOnlyStock] = useState(false),
    [error, setError] = useState(""),
    [shipping, setShipping] = useState("standard"),
    [review, setReview] = useState(false),
    [subscribed, setSubscribed] = useState(false),
    [customer, setCustomer] = useState(() => {
      try {
        return (
          JSON.parse(sessionStorage.getItem("shem-checkout-customer")) || {}
        );
      } catch {
        return {};
      }
    });
  const closeRef = useRef(null),
    triggerRef = useRef(null);
  useEffect(() => {
    const onRoute = () => {
      setRoute(
        location.hash.slice(1) ||
          (location.pathname === "/admin.html" ? "/admin" : "/"),
      );
      setSize("");
      setVariantId("");
      setColorChoice("");
      setError("");
      setReview(false);
      window.scrollTo(0, 0);
    };
    window.addEventListener("hashchange", onRoute);
    return () => window.removeEventListener("hashchange", onRoute);
  }, []);
  useEffect(() => {
    document.documentElement.dir = he ? "rtl" : "ltr";
    document.documentElement.lang = lang;
    localStorage.setItem("shem-lang", JSON.stringify(lang));
  }, [lang]);
  useEffect(() => {
    if (catalogueStatus === "ready")
      localStorage.setItem("shem-cart", JSON.stringify(cart));
  }, [cart, catalogueStatus]);
  useEffect(
    () => localStorage.setItem("shem-wish", JSON.stringify(wish)),
    [wish],
  );
  useEffect(() => {
    if (!panel) return;
    triggerRef.current = document.activeElement;
    document.body.style.overflow = "hidden";
    closeRef.current?.focus();
    const listener = (e) => {
      if (e.key === "Escape") setPanel(null);
      if (e.key === "Tab") {
        const els = [
          ...document.querySelectorAll(
            ".drawer a,.drawer button,.drawer input,.drawer select",
          ),
        ].filter((e) => !e.disabled);
        const first = els[0],
          last = els.at(-1);
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last?.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first?.focus();
        }
      }
    };
    window.addEventListener("keydown", listener);
    return () => {
      document.body.style.overflow = "";
      window.removeEventListener("keydown", listener);
      triggerRef.current?.focus();
    };
  }, [panel]);
  const go = (r) => {
    location.hash = r;
    setPanel(null);
  };
  const shop = (c = "", b = "") => {
    setCat(c);
    setBrand(b);
    setQuery("");
    setMax(null);
    setFilterSize("");
    setFilterColor("");
    setOnlyStock(false);
    go("/shop");
  };
  const toggle = (id) =>
    setWish((w) => (w.includes(id) ? w.filter((x) => x !== id) : [...w, id]));
  const count = cart.reduce((n, c) => n + c.qty, 0),
    catalogueTotal = subtotal(cart, products),
    catalogueDelivery =
      shipping === "standard"
        ? (storeContent.operations?.standardPrice ?? 49)
        : (storeContent.operations?.expressPrice ?? 199);
  const standardDays = storeContent.operations?.standardDays ?? 7,
    expressDays = storeContent.operations?.expressDays ?? 1,
    standardPrice = storeContent.operations?.standardPrice ?? 49,
    expressPrice = storeContent.operations?.expressPrice ?? 199;
  const shippingCopy = t(
    `שליח עד הבית: עד ${standardDays} ימי עסקים, ${standardPrice}₪. משלוח מהיר: עד ${expressDays} ימי עסקים, ${expressPrice}₪.`,
    `Home delivery: up to ${standardDays} business days, ₪${standardPrice}. Express: up to ${expressDays} business days, ₪${expressPrice}.`,
  );
  const quoteSignature = JSON.stringify({
    items: cart,
    shipping,
    code: couponCode,
  });
  const currentQuote = quote?.signature === quoteSignature ? quote : null;
  const total = currentQuote?.subtotal ?? catalogueTotal;
  const discount = currentQuote?.discount ?? 0;
  const delivery = currentQuote?.shipping ?? catalogueDelivery;
  const collection = collections.find((c) => route === `/collection/${c.id}`);
  useEffect(() => {
    document.title = route.startsWith("/admin")
      ? "SHEM | ניהול החנות"
      : "SHEM | אופנה לגברים";
    if (!route.startsWith("/admin"))
      document.getElementById("main")?.focus({ preventScroll: true });
  }, [route]);
  const qty = (i, d) =>
    setCart((cs) =>
      cs
        .map((c, j) =>
          j === i
            ? {
                ...c,
                qty: Math.min(
                  5,
                  products
                    .find((p) => p.id === c.id)
                    ?.variants?.find((v) => v.id === c.variantId)?.stock ??
                    products.find((p) => p.id === c.id)?.sizeStock?.[c.size] ??
                    products.find((p) => p.id === c.id)?.stock ??
                    5,
                  c.qty + d,
                ),
              }
            : c,
        )
        .filter((c) => c.qty > 0),
    );
  const add = (p) => {
    if (p.stock === 0) {
      setError(t("הפריט אזל מהמלאי.", "This piece is out of stock."));
      return;
    }
    if (!size) {
      setError(
        t(
          "יש לבחור מידה לפני ההוספה לסל",
          "Select a size before adding to bag",
        ),
      );
      return;
    }
    const selectedVariant = p.variants?.find((v) => v.id === variantId);
    if (p.variants?.length && !selectedVariant) {
      setError("יש לבחור מידה וצבע.");
      return;
    }
    const available =
      selectedVariant?.stock ?? p.sizeStock?.[size] ?? p.stock ?? 5;
    const existing = cart
      .filter((c) => c.id === p.id)
      .reduce((n, c) => n + c.qty, 0);
    if (
      (cart.find(
        (c) =>
          c.id === p.id && c.size === size && (c.variantId || "") === variantId,
      )?.qty || 0) >= Math.min(5, available) ||
      existing >= (p.stock ?? 100)
    ) {
      setError(
        t("אין מספיק מלאי במידה שנבחרה.", "Insufficient stock for this size."),
      );
      return;
    }
    setCart((cs) => addLine(cs, p, size, variantId));
    experienceEvent("cart_add", { route: "product", productId: p.id });
    setError("");
    setPanel("cart");
  };
  const currentDesigner = designers.find((d) => route === `/brand/${d.slug}`);
  const current = products.find((p) => route === `/product/${p.id}`);
  const selectedColor =
    colorChoice || current?.variants?.[0]?.color || current?.color || "";
  const availableSelected = current
    ? current.variants?.length
      ? current.variants.find((v) => v.id === variantId)?.stock
      : (current.sizeStock?.[size] ?? current.stock)
    : 0;
  useEffect(() => {
    if (current)
      experienceEvent("product_view", {
        route: "product",
        productId: current.id,
      });
    if (route === "/checkout")
      experienceEvent("checkout_start", { route: "checkout" });
  }, [route]);
  useEffect(() => {
    sessionStorage.setItem("shem-checkout-customer", JSON.stringify(customer));
  }, [customer]);
  const filtered = products
    .filter(
      (p) =>
        (!collection || collection.productIds.includes(p.id)) &&
        (!brand ||
          (designerFor(brand) &&
            designerFor(p.brand)?.slug === designerFor(brand).slug) ||
          p.brand === brand) &&
        (!cat || p.cat === cat) &&
        (max === null || p.price <= max) &&
        matchesSearch(p, query) &&
        matchesAvailability(p, filterSize, filterColor, onlyStock) &&
        (route !== "/wishlist" || wish.includes(p.id)),
    )
    .sort((a, b) =>
      sort === "low"
        ? a.price - b.price
        : sort === "high"
          ? b.price - a.price
          : 0,
    );
  useEffect(() => {
    if (route !== "/shop" || !query || filtered.length) return;
    const timer = setTimeout(
      () =>
        experienceEvent("search_empty", {
          route: "shop",
          reason: "no-results",
        }),
      800,
    );
    return () => clearTimeout(timer);
  }, [query, route, filtered.length]);
  const icon = (label, fn, child) => (
    <button className="icon-button" aria-label={label} onClick={fn}>
      {child}
    </button>
  );
  const card = (p) => (
    <ProductCard
      key={p.id}
      product={p}
      he={he}
      saved={wish.includes(p.id)}
      onSave={toggle}
    />
  );
  const cartContent = () =>
    cart.length ? (
      <>
        <div className="cart-items">
          {cart.map((c, i) => {
            const p = products.find((p) => p.id === c.id);
            if (!p)
              return (
                <article className="cart-item" key={c.id + c.size}>
                  <p>
                    {t(
                      "הפריט אינו זמין בקטלוג. יש להסיר אותו כדי להמשיך.",
                      "This item is unavailable. Remove it to continue.",
                    )}
                  </p>
                  <button
                    disabled={checkoutLocked}
                    onClick={() =>
                      setCart((cs) => cs.filter((_, j) => j !== i))
                    }
                  >
                    {t("הסרה", "Remove")}
                  </button>
                </article>
              );
            return (
              <article
                className="cart-item"
                key={c.id + c.size + (c.variantId || "")}
              >
                <img
                  src={
                    p?.imageUrl || `/assets/${p?.image || p?.id || "knit"}.png`
                  }
                  alt={t(p.he, p.en)}
                />
                <div>
                  <a href={`#/product/${p.id}`} onClick={() => setPanel(null)}>
                    {t(p.he, p.en)}
                  </a>
                  <small>
                    {t("מידה", "Size")}: {c.size} · {lineColour(p, c, he)}
                  </small>
                  <b>{money(p.price)}</b>
                  <div className="quantity">
                    {icon(
                      t("הפחתת כמות", "Decrease quantity"),
                      () => qty(i, -1),
                      <Minus />,
                    )}
                    <span>{c.qty}</span>
                    <button
                      aria-label={t("הגדלת כמות", "Increase quantity")}
                      disabled={
                        c.qty >=
                        Math.min(
                          5,
                          p.variants?.find((v) => v.id === c.variantId)
                            ?.stock ??
                            p.sizeStock?.[c.size] ??
                            p.stock ??
                            5,
                        )
                      }
                      onClick={() => qty(i, 1)}
                    >
                      <Plus />
                    </button>
                    <button
                      className="remove"
                      onClick={() =>
                        setCart((cs) => cs.filter((_, j) => i !== j))
                      }
                    >
                      {t("הסרה", "Remove")}
                    </button>
                  </div>
                </div>
              </article>
            );
          })}
        </div>
        <div className="cart-summary">
          <div>
            <span>{t("סכום ביניים", "Subtotal")}</span>
            <b>{money(total)}</b>
          </div>
          <p>
            {t(
              "עלות המשלוח מחושבת בשלב הבא",
              "Shipping calculated at the next step",
            )}
          </p>
          <CouponQuote
            cart={cart}
            shipping={shipping}
            code={couponCode}
            setCode={setCouponCode}
            onQuote={setQuote}
            connected={catalogueConnected}
            he={he}
          />
          {discount > 0 && (
            <p>
              {t("הנחת קופון", "Promotion discount")}: {money(discount)}
            </p>
          )}
          <button className="button dark full" onClick={() => go("/checkout")}>
            {t("להמשך הקנייה", "Continue to checkout")}
            <ArrowLeft />
          </button>
          <button className="text-button" onClick={() => shop()}>
            {t("המשך לקנות", "Continue shopping")}
          </button>
        </div>
      </>
    ) : (
      <div className="empty">
        <Handbag size={48} />
        <h2>{t("הסל שלך עדיין ריק", "Your bag is empty")}</h2>
        <button className="button dark" onClick={() => shop()}>
          {t("לכל הפריטים", "Explore the collection")}
        </button>
      </div>
    );
  if (route.startsWith("/admin")) return <Admin route={route} />;
  return (
    <>
      <a
        className="skip"
        href="#main"
        onClick={(e) => {
          e.preventDefault();
          document.getElementById("main")?.focus();
        }}
      >
        {t("דלג לתוכן", "Skip to content")}
      </a>
      <header className="header" inert={panel ? true : undefined}>
        <div className="header-tools">
          <button
            className="language"
            aria-label="Switch language"
            onClick={() => setLang(he ? "en" : "he")}
          >
            HE / EN
          </button>
          <span className="header-separator" />
          <button
            className="icon-button desktop-menu"
            aria-label={t("פתיחת תפריט צד", "Open side menu")}
            onClick={() => setPanel("menu")}
          >
            <List />
          </button>
          {icon(
            t("חיפוש", "Search"),
            () => setPanel("search"),
            <MagnifyingGlass />,
          )}
          {icon(
            t("החשבון שלי", "My account"),
            () => setPanel("account"),
            <User />,
          )}
          {icon(
            t(`סל קניות, ${count} פריטים`, `Bag, ${count} items`),
            () => setPanel("cart"),
            <>
              <Handbag />
              {count > 0 && <span className="bag-count">{count}</span>}
            </>,
          )}
        </div>
        <a className="logo" href="#/" aria-label="SHEM home">
          <img className="brand-monogram" src={shemMonogram} alt="" />
          <span className="brand-name">SHEM</span>
          <span className="brand-tagline">THEIR LABEL. YOUR SHEM.</span>
        </a>
        <nav
          className="desktop-nav"
          aria-label={t("ניווט ראשי", "Main navigation")}
        >
          <button onClick={() => shop("clothing")}>
            {t("בגדים", "Clothing")}
          </button>
          <button onClick={() => shop("shoes")}>{t("נעליים", "Shoes")}</button>
          <button onClick={() => shop("accessories")}>
            {t("אקססוריז", "Accessories")}
          </button>
          <button onClick={() => setPanel("brands")}>
            {t("מותגי SHEM", "SHEM designers")}
          </button>
          <button onClick={() => shop()}>{t("חדש באתר", "New in")}</button>
          <button onClick={() => go("/club")}>
            {t("מועדון חברים", "Members club")}
          </button>
        </nav>
        <button
          className="mobile-menu icon-button"
          aria-label={t("פתיחת תפריט", "Open menu")}
          onClick={() => setPanel("menu")}
        >
          <List />
        </button>
      </header>
      <main id="main" tabIndex={-1} inert={panel ? true : undefined}>
        {storeContent.announcement && (
          <p className="site-announcement">{storeContent.announcement}</p>
        )}
        {route === "/" ? (
          <>
            <section
              className="hero"
              onPointerMove={(e) => {
                if (
                  e.pointerType !== "mouse" ||
                  matchMedia("(prefers-reduced-motion: reduce)").matches
                )
                  return;
                const r = e.currentTarget.getBoundingClientRect();
                e.currentTarget.style.setProperty(
                  "--hero-x",
                  `${((e.clientX - r.left - r.width / 2) / r.width) * 8}px`,
                );
                e.currentTarget.style.setProperty(
                  "--hero-y",
                  `${((e.clientY - r.top - r.height / 2) / r.height) * 6}px`,
                );
              }}
              onPointerLeave={(e) => {
                e.currentTarget.style.setProperty("--hero-x", "0px");
                e.currentTarget.style.setProperty("--hero-y", "0px");
              }}
            >
              <HeroCarousel t={t} images={storeContent.heroImages} />
              <span className="hero-eyebrow">THE SHEM EDIT</span>
              <div className="hero-copy">
                <h1 dir="ltr">
                  {(storeContent.heroTitle || "DRESS\nWITH INTENT.")
                    .split("\n")
                    .map((line, i) => (
                      <span key={i} style={{ display: "block" }}>
                        {line}
                      </span>
                    ))}
                </h1>
                <p>
                  {t(
                    storeContent.heroSubtitle || "הסטייל שלך. בלי פשרות.",
                    "Your style. Without compromise.",
                  )}
                </p>
                <button className="button luxe" onClick={() => shop()}>
                  {t("לקולקציה", "Shop the collection")}
                  <ArrowRight size={19} />
                </button>
              </div>
            </section>
            <DesignerRails products={products} card={card} he={he} />
            <section className="selection clearance">
              <div className="section-heading">
                <div>
                  <p className="eyebrow">THE LAST EDIT</p>
                  <h2>
                    {t(
                      storeContent.saleTitle || "פריטים בסוף עונה",
                      "End of season",
                    )}
                  </h2>
                </div>
                <a className="view-all" href="#/shop">
                  {t("לכל הפריטים", "Explore pieces")} <ArrowRight size={18} />
                </a>
              </div>
              <ProductRail
                he={he}
                compact
                label={t(
                  storeContent.saleTitle || "פריטים בסוף עונה",
                  "End of season",
                )}
              >
                {products.filter((p) => p.previousPrice).map(card)}
              </ProductRail>
              <p className="demo-note">
                {t(
                  "פריטים ומחירי מבצע לדוגמה בלבד.",
                  "Illustrative products and sale prices only.",
                )}
              </p>
            </section>
            <section className="designer-strip">
              <p className="eyebrow">THE LABELS</p>
              <h2>{t("מותגי SHEM", "SHEM designers")}</h2>
              <div>
                <DesignerChoices onChoose={(d) => go(`/brand/${d.slug}`)} />
              </div>
            </section>
            <section className="editorial">
              <div>
                <span className="eyebrow">MORE THAN A LABEL</span>
                <h2 dir="ltr">
                  Their label.
                  <br />
                  Your SHEM.
                </h2>
                <p>
                  {t(
                    "סגנון הוא אישי. בחר את הפריטים שמתאימים לך, ובנה מראה שהוא רק שלך.",
                    "Style is personal. Choose the pieces that speak to you, and make the look your own.",
                  )}
                </p>
                <a className="text-link" href="#/about">
                  {t("הסיפור שלנו", "Our story")}
                  <ArrowLeft />
                </a>
              </div>
              <img
                src="/assets/hero.png"
                alt={t("פרטי סריג שחור", "Black knit detail")}
                loading="lazy"
              />
            </section>
            {(storeContent.blocks || [])
              .filter((b) => b.active)
              .sort((a, b) => a.position - b.position)
              .map((b) => (
                <section className="page content-block" key={b.id}>
                  <h2>{b.title}</h2>
                  <p>{b.text}</p>
                  {b.link && b.label && (
                    <a className="button dark" href={b.link}>
                      {b.label}
                    </a>
                  )}
                </section>
              ))}
            <section className="services">
              <div>
                <Truck size={30} />
                <h3>{t("משלוח עד הבית", "Delivered to your door")}</h3>
                <p>
                  {t(
                    `עד ${standardDays} ימי עסקים · ${standardPrice}₪`,
                    `Up to ${standardDays} business days · ₪${standardPrice}`,
                  )}
                </p>
              </div>
              <div>
                <ArrowCounterClockwise size={30} />
                <h3>{t("החלפות והחזרות", "Exchanges & returns")}</h3>
                <p>
                  {t("תוך 14 ימים מקבלת המשלוח", "Within 14 days of delivery")}
                </p>
              </div>
              <div>
                <Handbag size={30} />
                <h3>{t("אופנה לגברים", "Considered menswear")}</h3>
                <p>
                  {t(
                    "בגדים, נעליים ואקססוריז",
                    "Clothing, shoes and accessories",
                  )}
                </p>
              </div>
            </section>
          </>
        ) : route.startsWith("/account") ? (
          <CustomerArea operations={storeContent.operations || {}} />
        ) : route === "/help" ? (
          <HelpCenter />
        ) : [
            "/terms",
            "/privacy",
            "/returns",
            "/accessibility",
            "/payments",
            "/cancel",
            "/contact",
            "/delivery",
          ].includes(route) ? (
          <PolicyPages
            page={route === "/delivery" ? "payments" : route.slice(1)}
            business={storeContent.business || {}}
            operations={storeContent.operations || {}}
          />
        ) : route === "/collections" ? (
          <section className="page">
            <h1>{t("הקולקציות שלנו", "Our collections")}</h1>
            <div className="collection-grid">
              {collections.map((c) => (
                <a href={"#/collection/" + c.id} key={c.id}>
                  <h2>{c.title}</h2>
                  <p>{c.description}</p>
                  <span>
                    {c.productIds.length} {t("פריטים", "pieces")}
                  </span>
                </a>
              ))}
              {!collections.length && (
                <p>
                  {t(
                    "קולקציות חדשות יופיעו כאן בקרוב.",
                    "New collections will appear here soon.",
                  )}
                </p>
              )}
            </div>
          </section>
        ) : route.startsWith("/product/") && !current ? (
          <section className="page narrow">
            <h1>
              {catalogueStatus === "loading"
                ? t("טוענים את הפריט", "Loading your piece")
                : catalogueStatus === "error"
                  ? t(
                      "לא ניתן לטעון את הפריט כרגע",
                      "Unable to load this piece",
                    )
                  : t("הפריט אינו זמין", "This piece is unavailable")}
            </h1>
            {catalogueStatus !== "ready" ? (
              <p role="status">
                {t(
                  "בודקים את פרטי הקטלוג. אפשר לנסות שוב אם הטעינה לא הושלמה.",
                  "Loading catalogue details. Retry if loading does not complete.",
                )}
              </p>
            ) : (
              <a className="button dark" href="#/shop">
                {t("לפריטים נוספים", "Explore other pieces")}
              </a>
            )}
            {catalogueStatus === "error" && (
              <button className="button dark" onClick={() => location.reload()}>
                {t("ניסיון חוזר", "Retry")}
              </button>
            )}
          </section>
        ) : current ? (
          <section className="pdp page">
            <div className="breadcrumb">
              <a href="#/">SHEM</a> /{" "}
              <a href="#/shop">{t("קולקציה", "Collection")}</a> /{" "}
              {t(current.he, current.en)}
            </div>
            <div className="pdp-mobile-buy">
              <b>{money(current.price)}</b>
              <button
                className="button dark"
                disabled={
                  current.stock === 0 || (!!size && availableSelected === 0)
                }
                onClick={() => {
                  if (!size) {
                    document.querySelector(".size-heading")?.scrollIntoView({
                      behavior: matchMedia("(prefers-reduced-motion: reduce)")
                        .matches
                        ? "auto"
                        : "smooth",
                      block: "center",
                    });
                    setError(
                      t(
                        "בחרו מידה כדי להוסיף לסל",
                        "Choose a size to add to bag",
                      ),
                    );
                  } else add(current);
                }}
              >
                {current.stock === 0 || (!!size && availableSelected === 0)
                  ? t("אזל מהמלאי", "Out of stock")
                  : size
                    ? t("הוספה לסל", "Add to bag")
                    : t("בחירת מידה", "Choose size")}
              </button>
            </div>
            <div className="pdp-layout">
              <ProductGallery product={current} he={he} color={selectedColor} />
              <div className="pdp-info">
                <DesignerLogo
                  designer={designerFor(current.brand)}
                  className="pdp-designer-logo"
                />
                <h1>{t(current.he, current.en)}</h1>
                <p className="pdp-price">
                  {current.previousPrice && (
                    <del className="previous-price">
                      {money(current.previousPrice)}
                    </del>
                  )}{" "}
                  {money(current.price)}
                </p>
                {(current.demo || !catalogueConnected) && (
                  <p className="demo-note">
                    {t(
                      "פריט הדגמה - צילום ונתוני מוצר להמחשה בלבד.",
                      "Demo product - illustrative photography and product details.",
                    )}
                  </p>
                )}
                {current.description && <p>{current.description}</p>}
                {current.material && (
                  <p>
                    {t("הרכב בד", "Fabric")}: {current.material}
                  </p>
                )}
                {current.care && (
                  <p>
                    {t("הוראות טיפול", "Care")}: {current.care}
                  </p>
                )}
                <fieldset className="product-colors">
                  <legend>{t("בחירת צבע", "Select colour")}</legend>
                  {[
                    ...new Set(
                      current.variants?.length
                        ? current.variants.map((v) => v.color)
                        : [current.color],
                    ),
                  ]
                    .filter(Boolean)
                    .map((color) => (
                      <button
                        type="button"
                        key={color}
                        aria-pressed={selectedColor === color}
                        onClick={() => {
                          setColorChoice(color);
                          setVariantId("");
                          setSize("");
                          setError("");
                        }}
                      >
                        <span
                          style={{
                            backgroundColor:
                              current.colors?.find((c) => c.name === color)
                                ?.hex ||
                              (color === "black" || color === "שחור"
                                ? "#111111"
                                : color === "ivory" || color === "שנהב"
                                  ? "#ede8df"
                                  : "transparent"),
                          }}
                        />
                        {colourName(color, he)}
                      </button>
                    ))}
                </fieldset>
                <div className="size-heading">
                  <span>{t("בחירת מידה", "Select size")}</span>
                  <button
                    className="text-button"
                    onClick={() => setPanel("sizes")}
                  >
                    {t("מדריך מידות", "Size guide")}
                  </button>
                </div>
                <div
                  className="sizes"
                  aria-label={t("מידות המוצר", "Product sizes")}
                >
                  {current.sizes.map((s) => {
                    const variant = current.variants?.find(
                        (v) => v.size === s && v.color === selectedColor,
                      ),
                      available = current.variants?.length
                        ? (variant?.stock ?? 0)
                        : (current.sizeStock?.[s] ?? current.stock);
                    return (
                      <button
                        key={s}
                        type="button"
                        disabled={current.variants?.length > 0 && !variant}
                        aria-pressed={s === size}
                        className={
                          (s === size ? "active " : "") +
                          (available === 0 ? "unavailable-size" : "")
                        }
                        aria-label={`${s}${available === 0 ? t(" - לא זמין כעת", " - currently unavailable") : ""}`}
                        onClick={() => {
                          setSize(s);
                          setVariantId(variant?.id || "");
                          setError("");
                        }}
                      >
                        {s}
                      </button>
                    );
                  })}
                </div>
                {error && (
                  <p className="error" role="alert">
                    {error}
                  </p>
                )}
                <div className="add-row">
                  <button
                    className="button dark full"
                    disabled={
                      current.stock === 0 || (!!size && availableSelected === 0)
                    }
                    onClick={() => add(current)}
                  >
                    {current.stock === 0 || (!!size && availableSelected === 0)
                      ? t("אזל מהמלאי", "Out of stock")
                      : t("הוספה לסל", "Add to bag")}
                  </button>
                  <button
                    className="wish-pdp"
                    aria-label={t("שמירה במועדפים", "Save to wishlist")}
                    aria-pressed={wish.includes(current.id)}
                    onClick={() => toggle(current.id)}
                  >
                    <Heart
                      size={25}
                      weight={wish.includes(current.id) ? "fill" : "regular"}
                    />
                  </button>
                </div>
                <p className="muted">
                  {t("המחיר כולל מע״מ.", "Price includes VAT.")}
                </p>
                {(current.stock === 0 ||
                  (!!size && availableSelected === 0)) && (
                  <StockWatch
                    product={current}
                    size={size}
                    variantId={variantId}
                    he={he}
                  />
                )}
                <details open>
                  <summary>{t("משלוחים", "Delivery")}</summary>
                  <p>{shippingCopy}</p>
                </details>
                <details>
                  <summary>
                    {t("החלפות והחזרות", "Exchanges & returns")}
                  </summary>
                  <p>
                    {t(
                      "תוך 14 ימים מקבלת המשלוח, באמצעות איסוף שליח עד הבית.",
                      "Within 14 days of delivery, with home courier collection.",
                    )}
                  </p>
                </details>
                <HelpCenter placement="product" />
              </div>
            </div>
            <section className="related">
              <h2>{t("להשלמת המראה", "Complete the look")}</h2>
              <div className="product-grid">
                {complementaryProducts(products, current).map(card)}
              </div>
            </section>
          </section>
        ) : currentDesigner ? (
          <DesignerPage
            key={currentDesigner.slug}
            designer={currentDesigner}
            products={products}
            card={card}
            he={he}
          />
        ) : route === "/shop" || route === "/wishlist" || !!collection ? (
          <section className="page catalogue">
            <p className="eyebrow">
              SHEM / {route === "/wishlist" ? "WISHLIST" : "COLLECTION"}
            </p>
            <h1>
              {route === "/wishlist"
                ? t("המועדפים שלך", "Your wishlist")
                : collection?.title || t("הקולקציה", "The collection")}
            </h1>
            <div className="filter-bar">
              <fieldset className="catalogue-brand-filter">
                <legend>{t("מותגי SHEM", "SHEM designers")}</legend>
                <button
                  className="text-button"
                  aria-pressed={!brand}
                  onClick={() => setBrand("")}
                >
                  {t("הכול", "All")}
                </button>
                <DesignerChoices
                  selected={designerFor(brand)?.slug}
                  onChoose={(d) => setBrand(d.name)}
                />
              </fieldset>
              <label>
                {t("קטגוריה", "Category")}
                <select value={cat} onChange={(e) => setCat(e.target.value)}>
                  <option value="">{t("הכול", "All")}</option>
                  <option value="clothing">{t("בגדים", "Clothing")}</option>
                  <option value="shoes">{t("נעליים", "Shoes")}</option>
                  <option value="accessories">
                    {t("אקססוריז", "Accessories")}
                  </option>
                </select>
              </label>
              <label>
                {t("עד מחיר", "Up to")}{" "}
                {money(max ?? Math.max(299, ...products.map((p) => p.price)))}
                <input
                  type="range"
                  aria-label={t("מחיר מרבי", "Maximum price")}
                  min="0"
                  max={Math.max(299, ...products.map((p) => p.price))}
                  value={max ?? Math.max(299, ...products.map((p) => p.price))}
                  onChange={(e) => setMax(Number(e.target.value))}
                />
              </label>
              <label>
                {t("מיון", "Sort")}
                <select value={sort} onChange={(e) => setSort(e.target.value)}>
                  <option value="featured">{t("מומלצים", "Featured")}</option>
                  <option value="low">
                    {t("מחיר: מהנמוך לגבוה", "Price: low to high")}
                  </option>
                  <option value="high">
                    {t("מחיר: מהגבוה לנמוך", "Price: high to low")}
                  </option>
                </select>
              </label>
            </div>
            <InventoryFilters
              products={products}
              he={he}
              query={query}
              onQuery={setQuery}
              size={filterSize}
              onSize={setFilterSize}
              color={filterColor}
              onColor={setFilterColor}
              onlyStock={onlyStock}
              onStock={setOnlyStock}
            />
            {query && (
              <p>
                {t("תוצאות עבור", "Results for")}: “{query}”
              </p>
            )}
            <div className="results">
              <span>
                {filtered.length} {t("פריטים", "items")}
              </span>
              <button
                className="text-button"
                onClick={() => {
                  setBrand("");
                  setCat("");
                  setQuery("");
                  setMax(null);
                  setFilterSize("");
                  setFilterColor("");
                  setOnlyStock(false);
                }}
              >
                {t("ניקוי סינון", "Clear filters")}
              </button>
            </div>
            {filtered.length ? (
              <div className="product-grid">{filtered.map(card)}</div>
            ) : (
              <div className="empty">
                <h2>{t("לא נמצאו פריטים", "No pieces found")}</h2>
                <p>
                  {t(
                    "נסה לשנות סינון או לשמור פריטים במועדפים.",
                    "Try adjusting filters or saving pieces to your wishlist.",
                  )}
                </p>
                <h3>
                  {t("אולי הפריטים האלה יתאימו", "You may like these pieces")}
                </h3>
                <div className="product-grid">
                  {products
                    .filter((p) => p.stock > 0)
                    .slice(0, 4)
                    .map(card)}
                </div>
              </div>
            )}
            <p className="demo-note">
              {t(
                "קטלוג הדגמה לצורך בדיקת הממשק.",
                "Demo catalogue for interface testing.",
              )}
            </p>
          </section>
        ) : route === "/cart" ? (
          <section className="page narrow">
            <h1>{t("סל הקניות", "Your bag")}</h1>
            {cartContent()}
          </section>
        ) : route === "/checkout" ? (
          <section className="page checkout">
            <p className="eyebrow">SHEM / CHECKOUT</p>
            <h1>{t("פרטי ההזמנה", "Order details")}</h1>
            {!cart.length &&
            !sessionStorage.getItem("shem-checkout-session") ? (
              cartContent()
            ) : (
              <div
                className={
                  "checkout-grid" +
                  (checkoutLocked ? " checkout-is-locked" : "")
                }
              >
                <div>
                  <HomeCheckout
                    onReview={(q) =>
                      setQuote({ ...q, signature: quoteSignature })
                    }
                    onLock={setCheckoutLocked}
                    cart={cart}
                    shipping={shipping}
                    onShipping={setShipping}
                    code={couponCode}
                    customer={customer}
                    setCustomer={setCustomer}
                    operations={storeContent.operations || {}}
                  />
                </div>
                <aside className="order-summary">
                  <h2>{t("סיכום הזמנה", "Order summary")}</h2>
                  {cart.map((c) => {
                    const p = products.find((p) => p.id === c.id);
                    return (
                      <div
                        className="order-line"
                        key={c.id + c.size + (c.variantId || "")}
                      >
                        <img
                          src={
                            p?.imageUrl ||
                            `/assets/${p?.image || p?.id || "knit"}.png`
                          }
                          alt=""
                        />
                        <span>
                          {t(
                            p?.he || "פריט אינו זמין",
                            p?.en || "Unavailable item",
                          )}
                          <small>
                            {c.size} · {lineColour(p, c, he)} · ×{c.qty}
                          </small>
                        </span>
                        <b>
                          {money(
                            (currentQuote?.items?.find(
                              (i) =>
                                i.id === c.id &&
                                i.size === c.size &&
                                (i.variantId || "") === (c.variantId || ""),
                            )?.price ??
                              p?.price ??
                              0) * c.qty,
                          )}
                        </b>
                      </div>
                    );
                  })}
                  {!checkoutLocked && (
                    <CouponQuote
                      cart={cart}
                      shipping={shipping}
                      code={couponCode}
                      setCode={setCouponCode}
                      onQuote={setQuote}
                      connected={catalogueConnected}
                      he={he}
                    />
                  )}
                  <div className="summary-line">
                    <span>
                      {t("סך פריטים כולל מע״מ", "Items including VAT")}
                    </span>
                    {money(total - discount)}
                  </div>
                  <div className="summary-line">
                    <span>{t("משלוח", "Shipping")}</span>
                    {money(delivery)}
                  </div>
                  {discount > 0 && (
                    <p>
                      {t("הנחת קופון", "Promotion discount")}: {money(discount)}
                    </p>
                  )}
                  <div className="summary-line total">
                    <b>{t("סה״כ", "Total")}</b>
                    <b>{money(total + delivery - discount)}</b>
                  </div>
                  <p className="muted">
                    {t(
                      "המחירים כוללים מע״מ. הסכום יאומת לפני התשלום.",
                      "Prices include VAT. Your total is verified before payment.",
                    )}
                  </p>
                  <a href="#/cart" className="text-link">
                    {t("עריכת הסל", "Edit bag")}
                  </a>
                </aside>
              </div>
            )}
          </section>
        ) : route === "/club" || route === "/admin/club" ? (
          <Club he={he} admin={route === "/admin/club"} />
        ) : route === "/designers" ? (
          <section className="page designers">
            <p className="eyebrow">THE LABELS</p>
            <h1>{t("מותגי SHEM", "SHEM designers")}</h1>
            <DesignerChoices onChoose={(d) => go(`/brand/${d.slug}`)} />
          </section>
        ) : (
          <section className="page narrow story">
            <p className="eyebrow">SHEM</p>
            <h1>
              {route === "/about"
                ? t("הסיפור שלנו", "Our story")
                : route === "/delivery"
                  ? t("משלוחים והחזרות", "Delivery & returns")
                  : t("שירות לקוחות", "Customer care")}
            </h1>
            {route === "/about" ? (
              <>
                <h2 dir="ltr">THEIR LABEL. YOUR SHEM.</h2>
                <p>
                  {t(
                    "SHEM היא חנות אופנה לגברים שבונים לעצמם שם. בגדים, נעליים ואקססוריז, עם מקום לבחירה אישית ולסגנון שלך.",
                    "SHEM is a menswear destination for a considered wardrobe. Clothing, footwear and accessories with room for personal style.",
                  )}
                </p>
                <a href="#/shop" className="button dark">
                  {t("גלה את הקולקציה", "Explore the collection")}
                </a>
              </>
            ) : (
              <>
                <h2>{t("משלוח עד הבית", "Home delivery")}</h2>
                <p>{shippingCopy}</p>
                <h2>{t("החלפות והחזרות", "Exchanges & returns")}</h2>
                <p>
                  {t(
                    "תוך 14 ימים מקבלת המשלוח, באמצעות איסוף שליח עד הבית.",
                    "Within 14 days of delivery, with home courier collection.",
                  )}
                </p>
                <p className="demo-note">
                  {t(
                    "פרטי הקשר, אזורי השירות ותנאי האיסוף המלאים יעודכנו לפני פתיחת החנות.",
                    "Contact details, service areas and full collection terms will be updated before launch.",
                  )}
                </p>
              </>
            )}
          </section>
        )}
      </main>
      <section className="newsletter" inert={panel ? true : undefined}>
        <div>
          <span className="eyebrow">THE INNER CIRCLE</span>
          <h2>
            {t("להישאר בעניינים. בסטייל.", "Stay in the know. In style.")}
          </h2>
          <p>
            {t(
              "קולקציות חדשות ועדכונים של SHEM",
              "New collections and updates from SHEM",
            )}
          </p>
        </div>
        <a className="button outline" href="#/club">
          {t("למועדון החברים", "Members club")}
        </a>
      </section>
      <section
        className="instagram-ateliers"
        inert={panel ? true : undefined}
        aria-label={t("SHEM באינסטגרם", "SHEM on Instagram")}
      >
        <a
          className="instagram-signature"
          href={
            "https://www.instagram.com/" +
            (storeContent.operations?.instagram || "new.shem").replace(
              /^@/,
              "",
            ) +
            "/"
          }
          target="_blank"
          rel="noopener noreferrer"
          aria-label={t(
            "עמוד האינסטגרם של SHEM, נפתח בחלון חדש",
            "SHEM Instagram profile, opens a new window",
          )}
        >
          <InstagramLogo
            className="instagram-symbol"
            size={46}
            weight="light"
            aria-hidden="true"
          />
          <span className="instagram-handle" dir="ltr">
            {(storeContent.operations?.instagram || "new.shem").replace(
              /^@/,
              "",
            )}
          </span>
        </a>
      </section>
      <footer inert={panel ? true : undefined}>
        <div className="footer-quick-links">
          <a href="#/club">{t("מועדון חברים", "Members club")}</a>
        </div>
        <a className="footer-wordmark" href="#/">
          SHEM
        </a>
        <nav>
          <a href="#/about">{t("אודות", "About")}</a>
          <a href="#/delivery">{t("משלוחים והחזרות", "Delivery & returns")}</a>
          <a href="#/wishlist">{t("מועדפים", "Wishlist")}</a>
          <a href="#/contact">{t("שירות לקוחות", "Customer care")}</a>
          <a href="#/help">{t("מרכז העזרה", "Help centre")}</a>
          <a href="#/account">
            {t("הזמנות והחשבון שלי", "My account and orders")}
          </a>
          <a href="#/collections">{t("קולקציות", "Collections")}</a>
          <a href="#/terms">{t("תנאי שימוש", "Terms")}</a>
          <a href="#/privacy">{t("מדיניות פרטיות", "Privacy")}</a>
          <a href="#/returns">{t("החלפות והחזרות", "Returns")}</a>
          <a href="#/payments">
            {t("תשלום ומשלוחים", "Payments and shipping")}
          </a>
          <a href="#/accessibility">{t("הצהרת נגישות", "Accessibility")}</a>
          <a href="#/cancel">{t("ביטול עסקה", "Cancel a purchase")}</a>
          <a
            href={
              storeContent.operations?.whatsapp
                ? "https://wa.me/" + storeContent.operations.whatsapp
                : WHATSAPP
            }
            target="_blank"
            rel="noopener noreferrer"
            aria-label={t(
              "תמיכה טכנית בוואטסאפ, נפתח בחלון חדש",
              "WhatsApp technical support, opens a new window",
            )}
          >
            {t("תמיכה בוואטסאפ", "WhatsApp support")}
          </a>
          <a href={"mailto:" + SUPPORT_EMAIL} dir="ltr">
            {SUPPORT_EMAIL}
          </a>
        </nav>
        <p>THEIR LABEL. YOUR SHEM.</p>
        <small>© {new Date().getFullYear()} SHEM</small>
      </footer>
      <MeasurementChoice />
      {panel && (
        <div
          className="overlay"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setPanel(null);
          }}
        >
          <section
            className={`drawer ${panel === "brands" ? "designer-drawer" : ""}`}
            onPointerMove={(e) => {
              if (
                e.pointerType !== "mouse" ||
                matchMedia("(prefers-reduced-motion: reduce)").matches
              )
                return;
              const r = e.currentTarget.getBoundingClientRect();
              e.currentTarget.style.setProperty(
                "--pointer-x",
                `${e.clientX - r.left}px`,
              );
              e.currentTarget.style.setProperty(
                "--pointer-y",
                `${e.clientY - r.top}px`,
              );
            }}
            role="dialog"
            aria-modal="true"
            aria-labelledby="drawer-title"
          >
            <div className="drawer-heading">
              <h2
                id="drawer-title"
                className={
                  panel === "brands" ? "designer-menu-title" : undefined
                }
              >
                {panel === "cart" ? (
                  t("סל הקניות", "Your bag")
                ) : panel === "brands" ? (
                  <>
                    <span className="designer-menu-caption">
                      {t("מותגי", "Designers")}
                    </span>{" "}
                    <span className="designer-menu-wordmark" dir="ltr">
                      SHEM
                    </span>
                  </>
                ) : panel === "search" ? (
                  t("חיפוש", "Search")
                ) : panel === "menu" ? (
                  t("תפריט", "Menu")
                ) : panel === "sizes" ? (
                  t("מדריך מידות", "Size guide")
                ) : (
                  t("החשבון שלי", "My account")
                )}
              </h2>
              <button
                ref={closeRef}
                className="icon-button"
                aria-label={t("סגירה", "Close")}
                onClick={() => setPanel(null)}
              >
                <X />
              </button>
            </div>
            {panel === "cart" ? (
              cartContent()
            ) : panel === "search" ? (
              <>
                <form
                  className="search-form"
                  onSubmit={(e) => {
                    e.preventDefault();
                    setCat("");
                    setBrand("");
                    setMax(null);
                    go("/shop");
                  }}
                >
                  <input
                    aria-label={t(
                      "חיפוש מוצרים או מותגים",
                      "Search products or designers",
                    )}
                    placeholder={t(
                      "חפש פריט או מותג",
                      "Search pieces or designers",
                    )}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                  />
                  <button className="button dark">
                    {t("חיפוש", "Search")}
                  </button>
                </form>
                {products
                  .filter((p) => matchesSearch(p, query))
                  .map((p) => (
                    <button
                      className="search-result"
                      key={p.id}
                      onClick={() => go(`/product/${p.id}`)}
                    >
                      <img
                        src={
                          p?.imageUrl ||
                          `/assets/${p?.image || p?.id || "knit"}.png`
                        }
                        alt=""
                      />
                      <span>
                        {t(p.he, p.en)}
                        <DesignerLogo
                          designer={designerFor(p.brand)}
                          className="search-designer-logo"
                        />
                      </span>
                      <b>{money(p.price)}</b>
                    </button>
                  ))}
              </>
            ) : panel === "brands" ? (
              <DesignerChoices onChoose={(d) => go(`/brand/${d.slug}`)} />
            ) : panel === "menu" ? (
              <nav className="drawer-menu">
                <button onClick={() => go("/club")}>
                  {t("מועדון חברים", "Members club")}
                </button>
                {[
                  ["clothing", "בגדים", "Clothing"],
                  ["shoes", "נעליים", "Shoes"],
                  ["accessories", "אקססוריז", "Accessories"],
                ].map(([c, h, e]) => (
                  <button key={c} onClick={() => shop(c)}>
                    {t(h, e)}
                  </button>
                ))}
                <button onClick={() => setPanel("brands")}>
                  {t("מותגי SHEM", "SHEM designers")}
                </button>
                <button onClick={() => go("/wishlist")}>
                  {t("המועדפים שלי", "My wishlist")}
                </button>
                <button onClick={() => setLang(he ? "en" : "he")}>
                  HE / EN
                </button>
              </nav>
            ) : panel === "sizes" ? (
              <SizeGuide product={current} he={he} />
            ) : (
              <div className="account-drawer-entry">
                <h2>{t("המרחב שלך ב-SHEM", "Your space at SHEM")}</h2>
                <p>
                  {t(
                    "הזמנות, מעקב משלוחים, חשבוניות והטבות.",
                    "Orders, deliveries, invoices and benefits.",
                  )}
                </p>
                <button className="button dark" onClick={() => go("/account")}>
                  {t("לאזור שלי", "My account")}
                </button>
                <button className="text-link" onClick={() => go("/wishlist")}>
                  {t("המועדפים שלי", "My wishlist")}
                </button>
              </div>
            )}
          </section>
        </div>
      )}
    </>
  );
}
