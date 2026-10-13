import { useEffect, useState } from "react";
export function CouponQuote({
  cart,
  shipping,
  code,
  setCode,
  onQuote,
  connected,
  he,
}) {
  const [input, setInput] = useState(code),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  const signature = JSON.stringify({ items: cart, shipping, code });
  useEffect(() => {
    if (!connected || !cart.length) return;
    const controller = new AbortController();
    setBusy(true);
    setError("");
    onQuote(null);
    fetch("/api/quote", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: signature,
      signal: controller.signal,
    })
      .then(async (r) => {
        const data = await r.json();
        if (!r.ok) throw new Error(data.error);
        onQuote({ ...data, signature });
      })
      .catch((e) => {
        if (e.name !== "AbortError") setError(e.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setBusy(false);
      });
    return () => controller.abort();
  }, [signature, connected, onQuote]);
  return (
    <div className="coupon-control">
      <label>
        {he ? "קוד הטבה" : "Promotion code"}
        <input
          value={input}
          maxLength="40"
          autoComplete="off"
          dir="ltr"
          onChange={(e) => setInput(e.target.value)}
        />
      </label>
      <button
        className="button dark"
        disabled={busy || !connected}
        onClick={() => setCode(input.trim().toUpperCase())}
      >
        {he ? "החלת קוד" : "Apply code"}
      </button>
      {code && (
        <button
          className="text-button"
          onClick={() => {
            setCode("");
            setInput("");
          }}
        >
          {he ? "הסרת קוד" : "Remove code"}
        </button>
      )}
      {!connected && (
        <p>
          {he
            ? "קודים זמינים לאחר העלאת קטלוג החנות."
            : "Codes become available with the live catalogue."}
        </p>
      )}
      {busy && (
        <p role="status">
          {he ? "מאמת מחירים ומלאי..." : "Validating prices and stock..."}
        </p>
      )}
      {error && <p role="alert">{error}</p>}
    </div>
  );
}
