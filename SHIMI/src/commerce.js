/** Frontend demo calculations only. A production server must revalidate prices and stock. */
export const DELIVERY = Object.freeze({ standard: 49, express: 199 });
export function addLine(cart, product, size, variantId = "") {
  if (!product.sizes.includes(size)) throw new Error("Invalid size");
  const found = cart.findIndex(
    (line) =>
      line.id === product.id &&
      line.size === size &&
      (line.variantId || "") === variantId,
  );
  return found < 0
    ? [
        ...cart,
        { id: product.id, size, qty: 1, ...(variantId ? { variantId } : {}) },
      ]
    : cart.map((line, i) =>
        i === found ? { ...line, qty: Math.min(5, line.qty + 1) } : line,
      );
}
export function subtotal(cart, catalogue) {
  return cart.reduce((total, line) => {
    const product = catalogue.find((p) => p.id === line.id);
    return total + (product?.price ?? 0) * line.qty;
  }, 0);
}
export function orderTotal(cart, catalogue, delivery) {
  if (!(delivery in DELIVERY)) throw new Error("Invalid delivery option");
  return subtotal(cart, catalogue) + (cart.length ? DELIVERY[delivery] : 0);
}
export function safeCart(value, catalogue) {
  return Array.isArray(value)
    ? value.filter(
        (c) =>
          catalogue.some(
            (p) =>
              p.id === c.id &&
              p.sizes.includes(c.size) &&
              (!p.variants?.length ||
                p.variants.some(
                  (v) => v.id === c.variantId && v.size === c.size,
                )),
          ) &&
          Number.isInteger(c.qty) &&
          c.qty > 0 &&
          c.qty <= 5,
      )
    : [];
}
export function money(n) {
  return new Intl.NumberFormat("he-IL", {
    style: "currency",
    currency: "ILS",
    maximumFractionDigits: 2,
  }).format(n);
}
