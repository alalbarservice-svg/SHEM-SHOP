import { useEffect, useState } from "react";
import "./order-complete.css";

export function OrderComplete({ brand = "SHEM" }) {
  const [state, setState] = useState(null);
  const [error, setError] = useState("");
  const [copyState, setCopyState] = useState("");
  const params = new URLSearchParams(window.location.hash.slice(1));
  const orderId = params.get("order");
  const token = params.get("token");
  useEffect(() => {
    if (!orderId || !token) {
      setError("קישור ההזמנה אינו תקין.");
      return;
    }
    let active = true;
    const controller = new AbortController();
    const check = async () => {
      try {
        const response = await fetch(
          "/api/checkout-status/" +
            encodeURIComponent(orderId) +
            "?token=" +
            encodeURIComponent(token),
          { signal: controller.signal, cache: "no-store" },
        );
        const data = await response.json();
        if (!response.ok)
          throw Error("לא ניתן לאמת את ההזמנה. בדקו את הקישור האישי שלכם.");
        if (active) {
          setState(data);
          setError("");
        }
      } catch (e) {
        if (active && e.name !== "AbortError") setError(e.message);
      }
    };
    check();
    const timer = setInterval(check, 4000);
    return () => {
      active = false;
      controller.abort();
      clearInterval(timer);
    };
  }, [orderId, token]);
  const paid = state?.paymentStatus === "paid";
  const url =
    "/api/checkout-invoice/" +
    encodeURIComponent(orderId || "") +
    "?token=" +
    encodeURIComponent(token || "");
  return (
    <main className="order-complete" dir="rtl">
      <a
        className="order-complete-brand"
        href="/"
        aria-label={"לעמוד הבית של " + brand}
      >
        {brand}
      </a>
      <section
        className="order-complete-panel"
        aria-labelledby="order-complete-title"
      >
        <span className="order-complete-mark" aria-hidden="true">
          {paid ? "✓" : "·"}
        </span>
        <h1 id="order-complete-title">
          {paid
            ? `לקוח יקר, תודה שרכשת ב${brand}${brand === "SHEM" ? " מותגים" : ""}`
            : "מאמתים את ההזמנה שלך"}
        </h1>
        {paid ? (
          <>
            <p className="order-complete-caption">
              הזמנתך נקלטה, והתשלום בוצע!
            </p>
            <p className="order-complete-caption">
              <b>חשבונית עסקה</b>
            </p>
            {state.invoiceReady ? (
              <div className="order-complete-actions">
                <a
                  className="invoice-open"
                  href={url}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  צפייה בחשבונית העסקה <span aria-hidden="true">↗</span>
                </a>
                <a className="invoice-download" href={url + "&download=1"}>
                  הורדת חשבונית עסקה
                </a>
              </div>
            ) : (
              <p role="status" className="order-complete-message">
                החשבונית בהפקה. הכפתור יופיע כאן בעוד רגע.
              </p>
            )}
            <p className="order-complete-reference">
              מספר הזמנה: <b dir="ltr">{orderId}</b>
            </p>
            <a
              className="order-complete-back"
              href={
                "/#/account?order=" +
                encodeURIComponent(orderId) +
                "&token=" +
                encodeURIComponent(token)
              }
            >
              לפרטי ההזמנה ומעקב
            </a>
            <p className="order-complete-message">
              שמרו את הקישור האישי להזמנה כדי לחזור לפרטים ולחשבונית. הקישור
              פרטי ואין לשתף אותו.
            </p>
            <button
              type="button"
              className="invoice-download"
              onClick={async () => {
                try {
                  await navigator.clipboard.writeText(
                    window.location.origin +
                      "/#/account?order=" +
                      encodeURIComponent(orderId) +
                      "&token=" +
                      encodeURIComponent(token),
                  );
                  setCopyState("הקישור האישי הועתק. שמרו אותו במקום פרטי.");
                } catch {
                  setCopyState(
                    "לא ניתן להעתיק אוטומטית. ניתן לשמור את עמוד ההזמנה במועדפי הדפדפן.",
                  );
                }
              }}
            >
              העתקת הקישור האישי להזמנה
            </button>
            {copyState && (
              <p className="order-complete-message" role="status">
                {copyState}
              </p>
            )}
            <a className="order-complete-back" href="/">
              חזרה לחנות
            </a>
          </>
        ) : (
          <p role="status" className="order-complete-message">
            {error || "המסמך יוצג כאן לאחר אישור התשלום במערכת."}
          </p>
        )}
        {paid && error && (
          <p role="alert" className="order-complete-message">
            {error}
          </p>
        )}
      </section>
    </main>
  );
}
