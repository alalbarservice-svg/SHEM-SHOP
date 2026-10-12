/** Demonstration catalogue. Replace through the catalogue adapter before launch. */
export const brands = [
  "Dior",
  "Loro Piana",
  "Givenchy",
  "Amiri",
  "Ralph Lauren",
  "Essential",
];
export const products = [
  {
    id: "knit",
    he: "סריג צמר פרימיום",
    en: "Textured wool knit",
    price: 199,
    brand: "Loro Piana",
    cat: "clothing",
    color: "black",
    sizes: ["S", "M", "L", "XL"],
  },
  {
    id: "trousers",
    he: "מכנסי צ׳ינו מחויטים",
    en: "Tailored chino trousers",
    price: 249,
    brand: "Ralph Lauren",
    cat: "clothing",
    color: "ivory",
    sizes: ["30", "32", "34", "36"],
  },
  {
    id: "shirt",
    he: "חולצה מכופתרת משי",
    en: "Silk evening shirt",
    price: 229,
    brand: "Givenchy",
    cat: "clothing",
    color: "black",
    sizes: ["S", "M", "L", "XL"],
  },
  {
    id: "loafers",
    he: "מוקסין עור איטלקי",
    en: "Leather loafers",
    price: 299,
    brand: "Dior",
    cat: "shoes",
    color: "black",
    sizes: ["40", "41", "42", "43", "44"],
  },
];

products.push(
  ...products
    .slice(0, 3)
    .map((p, i) => ({
      ...p,
      id: `season-${p.id}`,
      image: p.id,
      price: [149, 179, 169][i],
      previousPrice: p.price,
    })),
);
