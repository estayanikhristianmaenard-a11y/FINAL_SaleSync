import crypto from "node:crypto";
import { pool } from "../shared/db/pool.js";

// Deterministic demo fixture: exactly 200 parent orders dated over the prior year.
const uuid = (key: string) => {
  const h = crypto
    .createHash("md5")
    .update("salesync-demo:" + key)
    .digest("hex")
    .split("");
  h[12] = "4";
  h[16] = ((parseInt(h[16]!, 16) & 3) | 8).toString(16);
  const s = h.join("");
  return `${s.slice(0, 8)}-${s.slice(8, 12)}-${s.slice(12, 16)}-${s.slice(16, 20)}-${s.slice(20)}`;
};
const categories = [
  "Home & Living",
  "Beauty",
  "Electronics",
  "Fashion",
  "Sports",
];
const names = [
  "Maya Santos",
  "Noah Reyes",
  "Leah Cruz",
  "Ethan Garcia",
  "Ava Dela Cruz",
  "Liam Mendoza",
  "Sofia Ramos",
  "Lucas Flores",
  "Isabella Torres",
  "Mateo Navarro",
];
async function main() {
  const c = await pool.connect();
  try {
    await c.query("BEGIN");
    const admin = uuid("admin");
    await c.query(
      `INSERT INTO users(id,email,full_name,role,status) VALUES($1,'admin.demo@salesync.test','SaleSync Demo Admin','admin','active') ON CONFLICT(id) DO NOTHING`,
      [admin],
    );
    const catIds: string[] = [];
    for (const name of categories) {
      const slug = name.toLowerCase().replaceAll(/[^a-z0-9]+/g, "-");
      const r = await c.query(
        `INSERT INTO categories(name,slug,status) VALUES($1,$2,'active') ON CONFLICT(slug) DO UPDATE SET name=EXCLUDED.name RETURNING id`,
        [name, slug],
      );
      catIds.push(r.rows[0].id);
    }
    const customers: string[] = [];
    for (let i = 0; i < 40; i++) {
      const uid = uuid(`customer-${i}`);
      await c.query(
        `INSERT INTO users(id,email,full_name,role,status,created_at) VALUES($1,$2,$3,'customer','active',now()-interval '420 days'+($4::int*interval '1 day')) ON CONFLICT(id) DO NOTHING`,
        [uid, `customer${i + 1}@salesync.test`, names[i % names.length], i * 2],
      );
      customers.push(uid);
    }
    const sellers: string[] = [];
    for (let i = 0; i < 8; i++) {
      const uid = uuid(`seller-${i}`),
        profile = uuid(`profile-${i}`);
      await c.query(
        `INSERT INTO users(id,email,full_name,role,status) VALUES($1,$2,$3,'seller','active') ON CONFLICT(id) DO NOTHING`,
        [uid, `seller${i + 1}@salesync.test`, `Seller ${names[i]}`],
      );
      await c.query(
        `INSERT INTO seller_profiles(id,user_id,legal_name) VALUES($1,$2,$3) ON CONFLICT(user_id) DO NOTHING`,
        [profile, uid, `Seller ${names[i]} Trading`],
      );
      const actual = await c.query(
        `SELECT id FROM seller_profiles WHERE user_id=$1`,
        [uid],
      );
      const sid = uuid(`store-${i}`);
      await c.query(
        `INSERT INTO stores(id,seller_profile_id,name,status) VALUES($1,$2,$3,'active') ON CONFLICT(seller_profile_id) DO NOTHING`,
        [sid, actual.rows[0].id, `${names[i]} Market`],
      );
      sellers.push(actual.rows[0].id);
    }
    const products: string[] = [];
    for (let i = 0; i < 24; i++) {
      const pid = uuid(`product-${i}`);
      await c.query(
        `INSERT INTO products(id,seller_profile_id,category_id,name,sku,description,price_centavos,stock,status) VALUES($1,$2,$3,$4,$5,$6,$7,50,'published') ON CONFLICT(id) DO NOTHING`,
        [
          pid,
          sellers[i % 8],
          catIds[i % 5],
          [
            `Canvas Organizer`,
            `Vitamin C Serum`,
            `Wireless Earbuds`,
            `Everyday Shirt`,
            `Travel Bottle`,
            `Desk Lamp`,
          ][i % 6],
          `DEMO-${String(i + 1).padStart(3, "0")}`,
          `Demo catalog item ${i + 1}`,
          15000 + ((i * 1739) % 145000),
        ],
      );
      products.push(pid);
    }
    const anchor = Date.now() - 365 * 86400000;
    for (let i = 0; i < 200; i++) {
      const orderId = uuid(`order-${i}`),
        paymentId = uuid(`payment-${i}`),
        orderNo = `SS-DEMO-${String(i + 1).padStart(4, "0")}`,
        created = new Date(
          anchor + Math.floor(i * ((365 * 86400000) / 199)),
        ).toISOString(),
        customer = customers[i % customers.length],
        seller = sellers[i % sellers.length],
        product = products[i % products.length],
        price = 15000 + ((i * 7919) % 185000),
        qty = 1 + (i % 3),
        shipping = i % 4 === 0 ? 0 : 9900,
        total = price * qty + shipping,
        status = [
          "delivered",
          "shipped",
          "processing",
          "cancelled",
          "confirmed",
        ][i % 5]!,
        payment = ["paid", "paid", "paid", "pending", "failed"][i % 5]!;
      await c.query(
        `INSERT INTO orders(id,order_no,customer_id,shipping_address,status,payment_status,subtotal_centavos,shipping_centavos,discount_centavos,total_centavos,created_at,updated_at) VALUES($1,$2,$3,$4,$5,$6,$7,$8,0,$9,$10,$10) ON CONFLICT(id) DO NOTHING`,
        [
          orderId,
          orderNo,
          customer,
          JSON.stringify({
            recipient: names[i % names.length],
            line1: `${10 + i} Mabini Street`,
            city: ["Quezon City", "Cebu City", "Davao City", "Makati"][i % 4],
            region: ["Metro Manila", "Cebu", "Davao del Sur"][i % 3],
            country: "PH",
          }),
          status,
          payment,
          price * qty,
          shipping,
          price * qty + shipping,
          created,
        ],
      );
      await c.query(
        `INSERT INTO order_items(order_id,product_id,seller_profile_id,product_name,sku,unit_price_centavos,quantity,line_total_centavos) VALUES($1,$2,$3,$4,$5,$6,$7,$8) ON CONFLICT DO NOTHING`,
        [
          orderId,
          product,
          seller,
          `Demo Product ${((i * 7) % 24) + 1}`,
          `DEMO-${String(((i * 7) % 24) + 1).padStart(3, "0")}`,
          price,
          qty,
          price * qty,
        ],
      );
      await c.query(
        `INSERT INTO payments(id,order_id,provider,provider_reference,amount_centavos,status,paid_at,safe_metadata,created_at,updated_at) VALUES($1,$2,'paymongo',$3,$4,$5,CASE WHEN $5='paid' THEN $6::timestamptz END,'{}'::jsonb,$6,$6) ON CONFLICT(id) DO NOTHING`,
        [
          paymentId,
          orderId,
          `demo_txn_${String(i + 1).padStart(5, "0")}`,
          price * qty + shipping,
          payment,
          created,
        ],
      );
      await c.query(
        `INSERT INTO order_status_history(order_id,from_status,to_status,changed_by,actor_role,note,changed_at) VALUES($1,NULL,$2,NULL,'system','Historical demo order status',$3) ON CONFLICT DO NOTHING`,
        [orderId, status, created],
      );
    }
    await c.query("COMMIT");
    console.info(
      "Seeded 40 customers, 8 sellers, 24 products, and exactly 200 demo orders/payments spanning 365 days.",
    );
  } catch (e) {
    await c.query("ROLLBACK");
    throw e;
  } finally {
    c.release();
    await pool.end();
  }
}
main().catch((e) => {
  console.error("Seed failed", e instanceof Error ? e.message : "unknown");
  process.exitCode = 1;
});
