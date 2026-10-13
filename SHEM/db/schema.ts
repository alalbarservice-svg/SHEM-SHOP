import { sql } from "drizzle-orm";
import {
  sqliteTable,
  text,
  integer,
  index,
  check,
  primaryKey,
} from "drizzle-orm/sqlite-core";
export const settings = sqliteTable("settings", {
  key: text("key").primaryKey(),
  value: text("value").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const products = sqliteTable("products", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const orders = sqliteTable("orders", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const members = sqliteTable("members", {
  id: text("id").primaryKey(),
  email: text("email").notNull().unique(),
  status: text("status").notNull().default("active"),
  consentAt: text("consent_at").notNull(),
  createdAt: text("created_at").notNull(),
});
export const promotions = sqliteTable("promotions", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const collections = sqliteTable("collections", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const customers = sqliteTable("customers", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const requests = sqliteTable("requests", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const memberProfiles = sqliteTable("member_profiles", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const audit = sqliteTable("audit", {
  id: text("id").primaryKey(),
  actor: text("actor").notNull(),
  action: text("action").notNull(),
  createdAt: text("created_at").notNull(),
});

export const stockMovements = sqliteTable("stock_movements", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const activities = sqliteTable("activities", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const loyaltyLedger = sqliteTable("loyalty_ledger", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const invoiceDocuments = sqliteTable("invoice_documents", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const mailJobs = sqliteTable("mail_jobs", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const documentSequences = sqliteTable("document_sequences", {
  id: text("id").primaryKey(),
  value: integer("value").notNull(),
});
export const operationGuards = sqliteTable(
  "operation_guards",
  { id: text("id").primaryKey(), valid: integer("valid").notNull() },
  (t) => [check("operation_valid", sql`${t.valid}=1`)],
);
export const paymentTransactions = sqliteTable("payment_transactions", {
  id: text("id").primaryKey(),
  orderId: text("order_id").notNull(),
});

export const productLinks = sqliteTable("product_links", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const paymentSessions = sqliteTable("payment_sessions", {
  id: text("id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});

export const channelReservations = sqliteTable(
  "channel_reservations",
  {
    reservationId: text("reservation_id").notNull(),
    productId: text("product_id").notNull(),
    variantId: text("variant_id").notNull(),
    size: text("size").notNull(),
    qty: integer("qty").notNull(),
    state: text("state").notNull(),
    expiresAt: text("expires_at").notNull(),
  },
  (t) => [
    primaryKey({
      columns: [t.reservationId, t.productId, t.variantId, t.size],
    }),
    check("channel_quantity_positive", sql`${t.qty}>0`),
    check(
      "channel_state_valid",
      sql`${t.state} IN ('held','released','committed')`,
    ),
  ],
);

export const cxWatches = sqliteTable(
  "cx_watches",
  {
    id: text("id").primaryKey(),
    userId: text("user_id").notNull(),
    data: text("data").notNull(),
    updatedAt: text("updated_at").notNull(),
  },
  (t) => [index("cx_watches_user").on(t.userId)],
);
export const cxEvents = sqliteTable(
  "cx_events",
  {
    id: text("id").primaryKey(),
    sessionId: text("session_id").notNull(),
    event: text("event").notNull(),
    detail: text("detail").notNull(),
    createdAt: text("created_at").notNull(),
  },
  (t) => [index("cx_events_time").on(t.createdAt)],
);
export const cxPreferences = sqliteTable("cx_preferences", {
  userId: text("user_id").primaryKey(),
  data: text("data").notNull(),
  updatedAt: text("updated_at").notNull(),
});
export const cxShipmentEvents = sqliteTable("cx_shipment_events", {
  id: text("id").primaryKey(),
  orderId: text("order_id").notNull(),
  createdAt: text("created_at").notNull(),
});
