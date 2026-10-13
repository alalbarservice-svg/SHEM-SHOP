import test from "node:test";
import assert from "node:assert/strict";
import { PDFDocument } from "pdf-lib";
import {
  createDocumentPDF,
  documentAmounts,
  documentHTML,
} from "../worker/documents.js";
test("A4 pagination and embedded VAT retain totals and Hebrew document layout", async () => {
  const doc = {
    number: "QA-0001",
    orderId: "QA-ONLY",
    issuedAt: "2026-10-08T07:00:00Z",
    business: {
      legalName: "SHEM - דוגמה בלבד",
      registrationNumber: "TEST-ONLY",
      vatRate: 18,
    },
    customer: {
      name: "ישראל ישראלי",
      email: "qa@example.test",
      street: "רחוב בדיקה 12",
      city: "תל אביב",
    },
    items: Array.from({ length: 60 }, (_, n) => ({
      name: "סריג בדיקה אלגנטי ארוך עם פרטי מידות וצבע " + n,
      sku: "QA-" + n,
      size: "XL",
      color: "שחור",
      qty: 1,
      priceCents: 12500,
    })),
    subtotalCents: 750000,
    discountCents: 15000,
    shippingCents: 19900,
    totalCents: 754900,
    vatRate: 18,
    shippingMethod: "express",
  };
  const a = documentAmounts(doc);
  assert.equal(a.gross, 735000);
  assert.equal(a.net + a.vat, a.gross);
  assert.equal(a.vat, Math.round((735000 * 18) / 118));
  const pdf = await PDFDocument.load(await createDocumentPDF(doc));
  assert.ok(pdf.getPageCount() > 1);
  for (const p of pdf.getPages()) {
    assert.ok(Math.abs(p.getWidth() - 595.28) < 0.01);
    assert.ok(Math.abs(p.getHeight() - 841.89) < 0.01);
  }
  const html = documentHTML(doc);
  assert.ok(html.includes("יום עסקים אחד"));
  assert.ok(!html.includes("מסמך זה אינו"));
  assert.ok(html.includes("7,549") || html.includes("7549"));
});
