import fs from 'fs';
import { createDocumentPDF } from './worker/documents.js';
const doc = { number:"7186", orderId:"107", issuedAt:"2026-10-18T10:00:00Z",
 business:{legalName:"SHEM מותגים", registrationNumber:"516067873", vatRate:18},
 customer:{name:"דנה כהן", phone:"050-1234567", street:"הרצל 1", city:"תל אביב"},
 items:[{name:"חולצה בדיקה", sku:"T-1001", qty:1, priceCents:19900}],
 subtotalCents:19900, discountCents:0, shippingCents:0, totalCents:19900, vatRate:18 };
fs.writeFileSync('old.pdf', await createDocumentPDF(doc));
