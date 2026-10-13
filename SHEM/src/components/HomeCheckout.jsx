import { useEffect, useState, useRef } from "react";
import { money } from "../commerce";
import { experienceEvent, rememberOrder } from "../experience";
import { HelpCenter } from "./HelpCenter";
const savedSession = () => {
  try {
    return JSON.parse(sessionStorage.getItem("shem-checkout-session")) || null;
  } catch {
    return null;
  }
};
export function deliveryChoices(operations = {}) {
  const max = operations.standardDays ?? 7,
    min = Math.min(operations.standardMinDays ?? 5, max),
    fast = operations.expressDays ?? 1;
  return [
    [
      "standard",
      `שליח עד הבית - ${min === max ? max : min + "-" + max} ימי עסקים`,
      operations.standardPrice ?? 49,
    ],
    [
      "express",
      `שליח עד הבית - ${fast === 1 ? "יום עסקים אחד" : fast + " ימי עסקים"}`,
      operations.expressPrice ?? 199,
    ],
  ];
}
export function fieldsInvalid(customer, terms) {
  const errors = {};
  if (!(customer.name || "").trim() || customer.name.trim().length < 2)
    errors.name = "יש להזין שם מלא.";
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customer.email || ""))
    errors.email = "יש להזין כתובת אימייל תקינה.";
  const phone = (customer.phone || "").replace(/[\s()+-]/g, "");
  if (!/^\d{7,15}$/.test(phone)) errors.phone = "יש להזין מספר טלפון תקין.";
  if (!(customer.city || "").trim()) errors.city = "יש להזין עיר למשלוח.";
  if (!(customer.street || "").trim())
    errors.street = "יש להזין רחוב ומספר בית.";
  if (!terms) errors.terms = "יש לקרוא ולאשר את תנאי השימוש והודעת הפרטיות.";
  return errors;
}
function CheckoutSteps({ stage }) {
  return (
    <ol className="checkout-steps" aria-label="שלבי הרכישה">
      {["פרטים ומשלוח", "סיכום הזמנה", "תשלום"].map((s, i) => (
        <li
          key={s}
          aria-current={stage === i ? "step" : undefined}
          className={stage >= i ? "reached" : ""}
        >
          <span>{i + 1}</span>
          {s}
        </li>
      ))}
    </ol>
  );
}
export function HomeCheckout({
  cart,
  shipping,
  onShipping,
  code,
  customer,
  setCustomer,
  operations,
  onLock = () => {},
  onReview = () => {},
}) {
  const [session, setSession] = useState(savedSession),
    [fieldErrors, setFieldErrors] = useState({}),
    [paymentStatus, setPaymentStatus] = useState("pending"),
    [checkError, setCheckError] = useState(""),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [paid, setPaid] = useState(false),
    [review, setReview] = useState(null),
    [terms, setTerms] = useState(false);
  const requestSignature = JSON.stringify({
    items: cart,
    shipping,
    code,
    customer,
    termsAccepted: terms,
  });
  const reviewRequest = useRef(null);
  useEffect(() => {
    reviewRequest.current?.abort();
    setReview(null);
    return () => reviewRequest.current?.abort();
  }, [requestSignature]);
  useEffect(() => {
    if (session) onLock(true);
  }, [session]);
  useEffect(() => {
    if (!session || paid) return;
    let active = true;
    const poll = async () => {
      try {
        const r = await fetch(
          "/api/checkout-status/" +
            session.orderId +
            "?token=" +
            encodeURIComponent(session.viewToken),
        );
        const data = await r.json();
        if (active && r.ok) {
          setPaymentStatus(data.paymentStatus);
          setCheckError("");
        }
        if (active && !r.ok)
          setCheckError(
            "לא ניתן לאמת את מצב התשלום כרגע. אל תשלמו שוב; ניתן לפנות לתמיכה עם מספר ההזמנה.",
          );
        if (active && r.ok && data.paymentStatus === "paid") {
          setPaid(true);
          sessionStorage.removeItem("shem-checkout-session");
          sessionStorage.removeItem("shem-checkout-customer");
          localStorage.removeItem("shem-cart");
          experienceEvent("purchase_confirmed", { route: "checkout" });
          const params = new URLSearchParams({
            order: session.orderId,
            token: session.viewToken,
          });
          window.location.assign("/order-complete.html#" + params.toString());
        }
      } catch {
        if (active)
          setCheckError(
            "החיבור התנתק. אנחנו ממשיכים לבדוק את מצב התשלום. אין לבצע תשלום נוסף.",
          );
      }
    };
    const id = setInterval(poll, 4000);
    poll();
    return () => {
      active = false;
      clearInterval(id);
    };
  }, [session, paid]);
  const submit = async (e) => {
    e.preventDefault();
    if (!review) {
      const validation = fieldsInvalid(customer, terms);
      setFieldErrors(validation);
      if (Object.keys(validation).length) {
        setError("יש לתקן את הפרטים המסומנים לפני ההמשך.");
        requestAnimationFrame(() =>
          document
            .querySelector('.checkout input[aria-invalid="true"]')
            ?.focus(),
        );
        experienceEvent("checkout_error", {
          route: "checkout",
          reason: "validation",
        });
        return;
      }
      const controller = new AbortController();
      reviewRequest.current?.abort();
      reviewRequest.current = controller;
      setBusy(true);
      setError("");
      try {
        const r = await fetch("/api/checkout-review", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: requestSignature,
          signal: controller.signal,
        });
        const d = await r.json();
        if (!r.ok) throw Error(d.error);
        if (!controller.signal.aborted) {
          setReview(d);
          onReview(d.quote);
          experienceEvent("review_ready", { route: "checkout" });
        }
      } catch (e) {
        if (e.name !== "AbortError") {
          setError(
            e.message || "החיבור אינו זמין כרגע. הפרטים נשמרו, אפשר לנסות שוב.",
          );
          experienceEvent("checkout_error", {
            route: "checkout",
            reason: "network",
          });
        }
      } finally {
        if (reviewRequest.current === controller) setBusy(false);
      }
      return;
    }
    setBusy(true);
    onLock(true);
    setError("");
    try {
      const r = await fetch("/api/checkout-home", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          items: cart,
          shipping,
          code,
          customer,
          termsAccepted: terms,
          reviewToken: review.reviewToken,
        }),
      });
      const d = await r.json();
      if (!r.ok) {
        if (r.status === 409) setReview(null);
        throw Error(d.error);
      }
      const saved = {
        ...d,
        summary: review.source,
        customer: { ...customer },
        shipping,
      };
      setSession(saved);
      rememberOrder(d);
      sessionStorage.setItem("shem-checkout-session", JSON.stringify(saved));
      experienceEvent("payment_open", { route: "checkout" });
      onLock(true);
    } catch (e) {
      onLock(false);
      setError(e.message || "לא ניתן לפתוח את התשלום כרגע. הפרטים נשמרו.");
      experienceEvent("checkout_error", {
        route: "checkout",
        reason: "payment",
      });
    } finally {
      setBusy(false);
    }
  };
  if (paid)
    return (
      <section role="status">
        <h2>התשלום אושר</h2>
        <p>מספר הזמנה: {session.orderId}</p>
        <p>מעבירים אותך לעמוד תודה וחשבונית העסקה...</p>
      </section>
    );
  if (session)
    return (
      <section>
        <CheckoutSteps stage={2} />
        <h2>תשלום מאובטח</h2>
        <p>
          מספר הזמנה: <b dir="ltr">{session.orderId}</b>
        </p>
        <p>
          {customer.name} | {customer.email} | {customer.phone}
        </p>
        <p>
          {customer.street}, {customer.city} |{" "}
          {deliveryChoices(operations).find(([key]) => key === shipping)?.[1]}
        </p>
        {session.summary && (
          <div className="verified-order-summary">
            <h3>ההזמנה שלך</h3>
            {session.summary.items.map((i) => (
              <div
                className="order-line"
                key={i.productId + ":" + i.variantId + ":" + i.size}
              >
                <span>
                  {i.name}
                  <small>
                    מק״ט <b dir="ltr">{i.sku}</b> · {i.size} · {i.color} · כמות{" "}
                    {i.qty}
                  </small>
                </span>
                <b>{money(i.lineCents / 100)}</b>
              </div>
            ))}
            <dl>
              <dt>סך פריטים</dt>
              <dd>{money(session.summary.subtotalCents / 100)}</dd>
              {session.summary.discountCents > 0 && (
                <>
                  <dt>הנחה</dt>
                  <dd>{money(session.summary.discountCents / 100)}</dd>
                </>
              )}
              <dt>משלוח</dt>
              <dd>{money(session.summary.shippingCents / 100)}</dd>
              <dt>סה״כ לתשלום</dt>
              <dd>
                <strong>{money(session.summary.totalCents / 100)}</strong>
              </dd>
            </dl>
            <p className="muted">המחירים כוללים מע״מ.</p>
            <p>
              {session.customer?.name} · {session.customer?.email} ·{" "}
              {session.customer?.phone}
            </p>
            <p>
              {session.customer?.street}, {session.customer?.city}
            </p>
          </div>
        )}
        <iframe
          title="מסך תשלום דרך אתר הבית"
          src={session.embedUrl}
          style={{ width: "100%", height: 650, border: 0 }}
          sandbox="allow-scripts allow-forms allow-same-origin allow-popups"
        />
        <p role="status">
          {checkError ||
            (paymentStatus === "failed"
              ? "התשלום לא הושלם. לבירור או לניסיון נוסף ניתן לפנות לתמיכה."
              : "ממתינים לאישור התשלום. סגירת המסך אינה מבטלת עסקה.")}
        </p>
        <a
          className="text-link"
          href={
            "#/account?order=" +
            encodeURIComponent(session.orderId) +
            "&token=" +
            encodeURIComponent(session.viewToken)
          }
        >
          לפרטי ההזמנה ובדיקת התשלום
        </a>
        <a className="text-link" href="#/contact">
          עזרה בתשלום
        </a>
      </section>
    );
  if (review)
    return (
      <form key="review-confirmation" onSubmit={submit}>
        <CheckoutSteps stage={1} />
        <h2>אישור פרטי ההזמנה</h2>
        {review.verified && (
          <p role="status">
            הפריטים, הזמינות והסכום הסופי נבדקו. אפשר לעבור לתשלום.
          </p>
        )}
        <div className="verified-order-summary">
          {review.source.items.map((i) => (
            <div
              className="order-line"
              key={i.productId + ":" + i.variantId + ":" + i.size}
            >
              <span>
                {i.name}
                <small>
                  מק״ט <b dir="ltr">{i.sku}</b> | {i.size} | {i.color} | כמות{" "}
                  {i.qty}
                </small>
              </span>
              <b>{money(i.lineCents / 100)}</b>
            </div>
          ))}
          <dl>
            <dt>סך הפריטים</dt>
            <dd>{money(review.source.subtotalCents / 100)}</dd>
            <dt>הנחה {review.source.couponCode}</dt>
            <dd>{money(review.source.discountCents / 100)}</dd>
            <dt>מע״מ 18%</dt>
            <dd>כלול במחירי הפריטים</dd>
            <dt>משלוח</dt>
            <dd>{money(review.source.shippingCents / 100)}</dd>
            <dt>סה״כ לתשלום</dt>
            <dd>
              <strong>{money(review.source.totalCents / 100)}</strong>
            </dd>
          </dl>
        </div>
        <p>
          {customer.name} | {customer.phone}
        </p>
        <p>{customer.email}</p>
        <p>
          {[
            customer.street,
            customer.apartment,
            customer.city,
            customer.postcode,
          ]
            .filter(Boolean)
            .join(", ")}
        </p>
        <p>
          {deliveryChoices(operations).find(([key]) => key === shipping)?.[1]} |{" "}
          {money(
            shipping === "express"
              ? (operations.expressPrice ?? 199)
              : (operations.standardPrice ?? 49),
          )}
        </p>
        <p>לאחר אישור התשלום, חשבונית העסקה תהיה זמינה לצפייה ולהורדה באתר.</p>
        {error && <p role="alert">{error}</p>}
        <button
          className="button dark full"
          disabled={busy || !operations.checkoutEnabled}
        >
          לתשלום מאובטח
        </button>
        <button
          type="button"
          className="text-link"
          disabled={busy}
          onClick={(e) => {
            e.preventDefault();
            setReview(null);
          }}
        >
          עריכת פרטים
        </button>
      </form>
    );
  return (
    <form key="customer-details" onSubmit={submit} noValidate>
      <CheckoutSteps stage={0} />
      <h2>פרטי לקוח ומשלוח</h2>
      <p>
        אפשר לרכוש ללא פתיחת חשבון. אנא בדקו את פרטי הקשר והכתובת לפני ההמשך.
      </p>
      {!operations.checkoutEnabled && (
        <p className="demo-note">
          הרכישה עדיין אינה פעילה. אפשר לבדוק סיכום הזמנה באמצעות פרטי בדיקה,
          ללא חיוב.
        </p>
      )}
      <div className="form-grid">
        {[
          ["name", "שם מלא"],
          ["email", "אימייל"],
          ["phone", "טלפון"],
          ["city", "עיר"],
          ["street", "רחוב ומספר בית"],
          ["apartment", "דירה / קומה"],
          ["postcode", "מיקוד"],
        ].map(([k, l]) => (
          <label key={k}>
            {l}
            <input
              required={!["apartment", "postcode"].includes(k)}
              type={k === "email" ? "email" : k === "phone" ? "tel" : "text"}
              maxLength={k === "email" ? 254 : k === "street" ? 300 : 150}
              autoComplete={
                {
                  name: "shipping name",
                  email: "email",
                  phone: "tel",
                  city: "shipping address-level2",
                  street: "shipping street-address",
                  apartment: "shipping address-line2",
                  postcode: "shipping postal-code",
                }[k]
              }
              inputMode={
                k === "phone"
                  ? "tel"
                  : k === "postcode"
                    ? "numeric"
                    : k === "email"
                      ? "email"
                      : "text"
              }
              aria-invalid={!!fieldErrors[k]}
              aria-describedby={
                fieldErrors[k] ? "checkout-error-" + k : undefined
              }
              value={customer[k] || ""}
              onChange={(e) => (
                setCustomer({ ...customer, [k]: e.target.value }),
                setFieldErrors({ ...fieldErrors, [k]: "" })
              )}
            />
            {fieldErrors[k] && (
              <small className="field-error" id={"checkout-error-" + k}>
                {fieldErrors[k]}
              </small>
            )}
          </label>
        ))}
      </div>
      <h2>משלוח</h2>
      {deliveryChoices(operations).map(([v, l, p]) => (
        <label className="shipping-option" key={v}>
          <input
            name="delivery"
            value={v}
            type="radio"
            checked={shipping === v}
            onChange={() => onShipping(v)}
          />
          <span>
            {l}
            <small>ימי עסקים בהתאם למדיניות המשלוחים.</small>
          </span>
          <b>{money(p)}</b>
        </label>
      ))}
      <a className="text-link" href="#/payments">
        למדיניות המשלוחים המלאה
      </a>
      <label>
        <input
          required
          name="terms"
          type="checkbox"
          checked={terms}
          onChange={(e) => setTerms(e.target.checked)}
        />
        קראתי את <a href="#/terms">תנאי השימוש</a> ואת{" "}
        <a href="#/privacy">הודעת הפרטיות</a>
      </label>
      {fieldErrors.terms && (
        <p className="field-error" role="alert">
          {fieldErrors.terms}
        </p>
      )}
      {error && (
        <p role="alert" className="error">
          {error}
        </p>
      )}
      <button className="button dark full" disabled={busy}>
        {busy ? "מאמת את סיכום ההזמנה..." : "המשך לאישור פרטי ההזמנה"}
      </button>
      <HelpCenter placement="checkout" />
    </form>
  );
}
