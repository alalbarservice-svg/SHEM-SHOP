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
export const visibleProductTypes = (products, designer, gate = true) => {
  const keys = new Set(
    designerProducts(products, designer)
      .filter((p) => !gate || isListed(p))
      .map(productType),
  );
  return productTypes.filter(([key]) => keys.has(key));
};
