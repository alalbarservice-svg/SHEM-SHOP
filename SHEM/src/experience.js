export const productImage = (p) =>
  p?.images?.[0]?.url ||
  p?.imageUrl ||
  `/assets/${p?.image || p?.id || "knit"}.png`;
export const normalizeSearch = (value) =>
  String(value || "")
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[\u0591-\u05c7]/g, "")
    .replace(/[׳״'"._-]/g, " ")
    .replace(/\s+/g, " ")
    .trim();
const aliases = [
  ["חולצה", "חולצות", "shirt", "shirts"],
  ["מכנס", "מכנסיים", "מכנסי", "trousers", "pants", "chino"],
  ["נעל", "נעליים", "shoes", "loafer", "מוקסין"],
  ["סריג", "סריגים", "סוודר", "knit", "knitwear"],
  ["דיור", "dior"],
  ["לורו", "לואו", "loro"],
  ["פיאנה", "piana"],
  ["ראלף", "רלף", "ralph", "ralgh"],
  ["לורן", "lauren"],
  ["אמירי", "amiri"],
  ["גיבנשי", "ג׳יבנשי", "givenchy"],
  ["אסנשיאל", "אסנשיאלס", "essentials", "essential"],
];
const editDistance = (a, b) => {
  let prev = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const next = [i];
    for (let j = 1; j <= b.length; j++)
      next[j] = Math.min(
        next[j - 1] + 1,
        prev[j] + 1,
        prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1),
      );
    prev = next;
  }
  return prev[b.length];
};
export function matchesSearch(p, query) {
  const tokens = normalizeSearch(query).split(" ").filter(Boolean);
  const hay = normalizeSearch(
      `${p.he} ${p.en} ${p.brand} ${p.productType} ${p.cat} ${p.sku || ""}`,
    ),
    words = hay.split(" ");
  return tokens.every((token) => {
    const group = aliases.find((g) =>
      g.map(normalizeSearch).includes(token),
    ) || [token];
    return (
      group.some((v) => hay.includes(normalizeSearch(v))) ||
      (token.length >= 4 &&
        words.some((w) => {
          if (w.length < 4) return false;
          if (editDistance(token, w) <= 1) return true;
          const different = [...token]
            .map((c, i) => (c !== w[i] ? i : -1))
            .filter((i) => i >= 0);
          return (
            token.length === w.length &&
            different.length === 2 &&
            different[1] === different[0] + 1 &&
            token[different[0]] === w[different[1]] &&
            token[different[1]] === w[different[0]]
          );
        }))
    );
  });
}
export function matchesAvailability(
  p,
  size = "",
  color = "",
  onlyStock = false,
) {
  if (p.variants?.length)
    return p.variants.some(
      (v) =>
        (!size || v.size === size) &&
        (!color || v.color === color) &&
        (!onlyStock || v.stock > 0),
    );
  return (
    (!size || p.sizes?.includes(size)) &&
    (!color || p.color === color) &&
    (!onlyStock || (size ? (p.sizeStock?.[size] ?? p.stock) : p.stock) > 0)
  );
}
export function complementaryProducts(products, product, limit = 4) {
  return products
    .filter(
      (p) =>
        p.id !== product.id && p.active !== false && !p.archived && p.stock > 0,
    )
    .sort((a, b) => {
      const score = (p) =>
        (p.cat !== product.cat ? 3 : 0) +
        (p.brand === product.brand ? 2 : 0) +
        (p.productType !== product.productType ? 1 : 0) -
        Math.abs(p.price - product.price) / Math.max(product.price, 1);
      return score(b) - score(a);
    })
    .slice(0, limit);
}
export function experienceEvent(event, detail = {}) {
  if (sessionStorage.getItem("shem-analytics-consent") !== "yes") return;
  let sessionId = sessionStorage.getItem("shem-analytics-session");
  if (!sessionId) {
    sessionId = crypto.randomUUID();
    sessionStorage.setItem("shem-analytics-session", sessionId);
  }
  fetch("/api/experience/events", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ event, sessionId, consent: true, ...detail }),
    keepalive: true,
  }).catch(() => {});
}
export function rememberOrder(session) {
  sessionStorage.setItem(
    "shem-recent-order",
    JSON.stringify({ orderId: session.orderId, viewToken: session.viewToken }),
  );
}

export const colourName = (value, he = true) => {
  const label = String(value || "");
  return he
    ? {
        black: "שחור",
        ivory: "שנהב",
        white: "לבן",
        blue: "כחול",
        navy: "כחול כהה",
        grey: "אפור",
        gray: "אפור",
        beige: "בז׳",
        brown: "חום",
      }[label.toLowerCase()] || label
    : label;
};
export const lineColour = (product, line, he = true) =>
  colourName(
    product?.variants?.find((v) => v.id === line.variantId)?.color ||
      product?.color,
    he,
  );
