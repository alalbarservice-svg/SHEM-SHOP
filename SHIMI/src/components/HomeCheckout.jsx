import { useEffect, useState, useRef } from "react";
import { money } from "../commerce";
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
  const [session, setSession] = useState(null),
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
        if (active && r.ok && data.paymentStatus === "paid") {
          setPaid(true);
          const params = new URLSearchParams({ order: session.orderId, token: session.viewToken });
          window.location.assign("/order-complete.html#" + params.toString());
        }
      } catch {}
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
        }
      } catch (e) {
        if (e.name !== "AbortError") setError(e.message);
      } finally {
        if (reviewRequest.current === controller) setBusy(false);
      }
      return;
    }
    setBusy(true);
    onLock(true);
    setError("");
    try {
      const r = await fetch("/api/shimi/checkout", {
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
      setSession(d);
      onLock(true);
    } catch (e) {
      onLock(false);
      setError(e.message);
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
        <h2>תשלום מאובטח</h2>
        <p>
          {customer.name} | {customer.email} | {customer.phone}
        </p>
        <p>
          {customer.street}, {customer.city} |{" "}
          {shipping === "express"
            ? "שליח עד הבית - יום עסקים אחד"
            : "שליח עד הבית - 5-7 ימי עסקים"}
        </p>
        <iframe
          title="מסך תשלום דרך אתר הבית"
          src={session.embedUrl}
          style={{ width: "100%", height: 650, border: 0 }}
          sandbox="allow-scripts allow-forms allow-same-origin allow-popups"
        />
        <p>מצב התשלום נבדק מול השרת. סגירת המסך אינה מבטלת חיוב.</p>
      </section>
    );
  if (review)
    return (
      <form onSubmit={submit}>
        <h2>אישור פרטי ההזמנה</h2>
        {review.verified && (
          <p role="status">
            סיכום ההזמנה נבדק מול SHIMI. המק״טים, המחירים והסכום הסופי תואמים.
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
            <dt>מחיר הפריטים לפני מע״מ</dt>
            <dd>{money(review.source.netSubtotalCents / 100)}</dd>
            <dt>מע״מ כלול 18%</dt>
            <dd>{money(review.source.vatCents / 100)}</dd>
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
          {customer.street}, {customer.apartment}, {customer.city}{" "}
          {customer.postcode}
        </p>
        <p>
          {shipping === "express"
            ? "שליח עד הבית - יום עסקים אחד"
            : "שליח עד הבית - 5-7 ימי עסקים"}{" "}
          |{" "}
          {money(
            shipping === "express"
              ? (operations.expressPrice ?? 199)
              : (operations.standardPrice ?? 49),
          )}
        </p>
        <p>
          לאחר אישור התשלום, חשבונית העסקה תהיה זמינה לצפייה ולהורדה באתר.
        </p>
        {error && <p role="alert">{error}</p>}
        <button
          className="button dark full"
          disabled={busy || !operations.checkoutEnabled}
        >
          המשך לתשלום מאובטח בתוך SHIMI
        </button>
        <button
          type="button"
          className="text-link"
          disabled={busy}
          onClick={() => setReview(null)}
        >
          עריכת פרטים
        </button>
      </form>
    );
  return (
    <form onSubmit={submit}>
      <h2>פרטי לקוח ומשלוח</h2>
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
              value={customer[k] || ""}
              onChange={(e) =>
                setCustomer({ ...customer, [k]: e.target.value })
              }
            />
          </label>
        ))}
      </div>
      <h2>משלוח</h2>
      {[
        [
          "standard",
          "שליח עד הבית - 5-7 ימי עסקים",
          operations.standardPrice ?? 49,
        ],
        [
          "express",
          "שליח עד הבית - יום עסקים אחד",
          operations.expressPrice ?? 199,
        ],
      ].map(([v, l, p]) => (
        <label className="shipping-option" key={v}>
          <input
            name="delivery"
            value={v}
            type="radio"
            checked={shipping === v}
            onChange={() => onShipping(v)}
          />
          {l} | {money(p)}
        </label>
      ))}
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
      {error && (
        <p role="alert" className="error">
          {error}
        </p>
      )}
      <button className="button dark full" disabled={busy}>
        {busy ? "מאמת את סיכום ההזמנה..." : "המשך לאישור פרטי ההזמנה"}
      </button>
    </form>
  );
}
