import { products, brands } from "../catalogue";
/**
 * Integration boundary for replacing the demo data with the inventory system.
 * Production responses must identify a SKU for each colour/size combination,
 * stock availability, composition, localized copy and licensed product images.
 * Never expose inventory or payment API secrets in this frontend.
 */
export const demoCatalogueAdapter = {
  async getBrands() {
    return [...brands];
  },
  async getProduct(id) {
    return products.find((p) => p.id === id) ?? null;
  },
  async list({
    page = 1,
    pageSize = 24,
    brand = "",
    category = "",
    search = "",
  } = {}) {
    const filtered = products.filter(
      (p) =>
        (!brand || p.brand === brand) &&
        (!category || p.cat === category) &&
        `${p.he} ${p.en} ${p.brand}`
          .toLowerCase()
          .includes(search.toLowerCase()),
    );
    return {
      items: filtered.slice((page - 1) * pageSize, page * pageSize),
      total: filtered.length,
      page,
      pageSize,
    };
  },
};
