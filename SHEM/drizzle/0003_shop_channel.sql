CREATE TABLE product_links (id TEXT PRIMARY KEY NOT NULL, data TEXT NOT NULL, updated_at TEXT NOT NULL);
--> statement-breakpoint
CREATE UNIQUE INDEX product_links_source_unique ON product_links(json_extract(data,'$.sourceId')) WHERE json_extract(data,'$.sourceId') IS NOT NULL;
--> statement-breakpoint
CREATE TABLE payment_sessions (id TEXT PRIMARY KEY NOT NULL, data TEXT NOT NULL, updated_at TEXT NOT NULL);
--> statement-breakpoint
CREATE UNIQUE INDEX payment_sessions_transaction_unique ON payment_sessions(json_extract(data,'$.paymentReference')) WHERE json_extract(data,'$.paymentReference') IS NOT NULL;
--> statement-breakpoint
CREATE TABLE channel_reservations (reservation_id TEXT NOT NULL, product_id TEXT NOT NULL, variant_id TEXT NOT NULL, size TEXT NOT NULL, qty INTEGER NOT NULL CHECK(qty>0), state TEXT NOT NULL CHECK(state IN ('held','released','committed')), expires_at TEXT NOT NULL, PRIMARY KEY(reservation_id,product_id,variant_id,size));
--> statement-breakpoint
CREATE INDEX channel_reservations_stock ON channel_reservations(product_id,state,expires_at);
