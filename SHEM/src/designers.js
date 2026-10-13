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
export const productTypes = [
  ["shirts", "חולצות", "Shirts"],
  ["trousers", "מכנסיים", "Trousers"],
  ["knitwear", "סריגים", "Knitwear"],
  ["jackets", "ז׳קטים", "Jackets"],
  ["shoes", "נעליים", "Shoes"],
  ["accessories", "אקססוריז", "Accessories"],
  ["clothing", "בגדים נוספים", "More clothing"],
];
export const designerFor = (name) =>
  designers.find((d) =>
    d.aliases.includes(
      String(name || "")
        .trim()
        .toLowerCase(),
    ),
  );
export const designerProducts = (products, designer) =>
  products.filter(
    (p) =>
      p.active !== false &&
      !p.archived &&
      designerFor(p.brand)?.slug === designer.slug,
  );
export function productType(p) {
  if (productTypes.some(([key]) => key === p.productType)) return p.productType;
  // Existing catalogue asset keys explicitly represent product types. Unknown items stay generic.
  if (p.cat === "shoes" || p.cat === "accessories") return p.cat;
  return (
    { shirt: "shirts", trousers: "trousers", knit: "knitwear" }[p.image] ||
    "clothing"
  );
}
