export const designers = [
  {
    slug: "loro-piana",
    name: "Loro Piana",
    width: 2097,
    height: 750,
    aliases: ["loro piana"],
  },
  {
    slug: "essentials",
    name: "Essentials",
    aliases: ["essential", "essentials", "essentials fear of god"],
  },
  {
    slug: "ralph-lauren",
    name: "Ralph Lauren",
    width: 1983,
    height: 793,
    aliases: ["ralph lauren"],
  },
  { slug: "dior", name: "Dior", width: 2056, height: 765, aliases: ["dior"] },
  { slug: "givenchy", name: "Givenchy", aliases: ["givenchy"] },
  { slug: "amiri", name: "Amiri", aliases: ["amiri"] },
];
export const defaultProductTypes = [
  ["shirts", "חולצות", "Shirts"],
  ["trousers", "מכנסיים", "Trousers"],
  ["knitwear", "סריגים", "Knitwear"],
  ["jackets", "ז׳קטים", "Jackets"],
  ["shoes", "נעליים", "Shoes"],
  ["accessories", "אקססוריז", "Accessories"],
  ["clothing", "בגדים נוספים", "More clothing"],
];
export const productTypes = defaultProductTypes;
// Item categories are managed in the admin (store.itemTypes: [{key, he, en}]).
// Without a configured list the built-in list is used.
export function normalizeItemTypes(list) {
  if (!Array.isArray(list) || !list.length) return defaultProductTypes;
  const rows = list
    .filter((t) => t && typeof t.key === "string" && t.key)
    .map((t) => [t.key, t.he || t.key, t.en || t.he || t.key]);
  return rows.length ? rows : defaultProductTypes;
}
export const designerFor = (name) =>
  designers.find((d) =>
    d.aliases.includes(
      String(name || "")
        .trim()
        .toLowerCase(),
    ),
  );
// A shown item is active, not archived and in stock.
export const isShownItem = (p) =>
  p.active !== false &&
  !p.archived &&
  (p.stock === undefined || p.stock === null || Number(p.stock) > 0);
export const designerProducts = (products, designer) =>
  products.filter(
    (p) => isShownItem(p) && designerFor(p.brand)?.slug === designer.slug,
  );
// Brands with no shown item disappear by themselves and return when one is added.
export const visibleDesigners = (products) =>
  designers.filter((d) => designerProducts(products, d).length > 0);
export function productType(p, types = defaultProductTypes) {
  if (types.some(([key]) => key === p.productType)) return p.productType;
  // Existing catalogue asset keys explicitly represent product types. Unknown items stay generic.
  let key;
  if (p.cat === "shoes" || p.cat === "accessories") key = p.cat;
  else
    key =
      { shirt: "shirts", trousers: "trousers", knit: "knitwear" }[p.image] ||
      "clothing";
  // If the inferred built-in type was removed from the admin list, the item has no category.
  return types.some(([k]) => k === key) ? key : "";
}
