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
  { slug: "stone-island", name: "Stone Island", aliases: ["stone island"] },
  { slug: "loewe", name: "Loewe", aliases: ["loewe"] },
  { slug: "hermes", name: "Hermès", aliases: ["hermès", "hermes", "hermes paris", "hermès paris"] },
  { slug: "rhun", name: "Rhun", aliases: ["rhun"] },
  { slug: "dolce-gabbana", name: "Dolce & Gabbana", aliases: ["dolce & gabbana", "dolce gabbana", "dolce&gabbana", "d&g", "dolce and gabbana"] },
  { slug: "allsaints", name: "AllSaints", aliases: ["allsaints", "all saints", "all saint", "allsaint"] },
  { slug: "prada", name: "Prada", aliases: ["prada"] },
  { slug: "balenciaga", name: "Balenciaga", aliases: ["balenciaga"] },
  { slug: "jacquemus", name: "Jacquemus", aliases: ["jacquemus"] },
  { slug: "casablanca", name: "Casablanca", aliases: ["casablanca", "casa blanca", "casablanca paris"] },
  { slug: "ami-paris", name: "AMI Paris", aliases: ["ami paris", "ami", "ami alexandre mattiussi"] },
  { slug: "celine", name: "Celine", aliases: ["celine", "céline"] },
  { slug: "louis-vuitton", name: "Louis Vuitton", aliases: ["louis vuitton", "lv"] },
  { slug: "valentino", name: "Valentino", aliases: ["valentino", "valentino garavani"] },
  { slug: "alexander-mcqueen", name: "Alexander McQueen", aliases: ["alexander mcqueen", "mcqueen"] },
  { slug: "miu-miu", name: "Miu Miu", aliases: ["miu miu", "miumiu"] },
  { slug: "moncler", name: "Moncler", aliases: ["moncler"] },
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

/** A piece counts as displayed only when it is active, not archived and in stock. */
export const hasStock = (p) => {
  if (p.variants?.length) return p.variants.some((v) => v.stock > 0);
  if (p.sizeStock && Object.keys(p.sizeStock).length)
    return Object.values(p.sizeStock).some((n) => n > 0);
  return p.stock > 0;
};
export const isListed = (p) => p.active !== false && !p.archived && hasStock(p);
/** Designers with at least one displayed piece. Others disappear automatically. */
export const visibleDesigners = (products) =>
  designers.filter((d) =>
    products.some((p) => isListed(p) && designerFor(p.brand)?.slug === d.slug),
  );
/** Product types of one designer with at least one displayed piece (gate=false: no stock rule). */
export const visibleProductTypes = (
  products,
  designer,
  gate = true,
  types = defaultProductTypes,
) => {
  // gate=false applies no stock rule: only active, not archived and of this designer.
  const keys = new Set(
    products
      .filter(
        (p) =>
          p.active !== false &&
          !p.archived &&
          designerFor(p.brand)?.slug === designer.slug,
      )
      .filter((p) => !gate || isListed(p))
      .map((p) => productType(p, types)),
  );
  return types.filter(([key]) => keys.has(key));
};
