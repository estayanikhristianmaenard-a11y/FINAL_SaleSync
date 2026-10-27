import { Router, type Response } from "express";
import { z } from "zod";
import {
  requireAdmin,
  type AdminRequest,
} from "../../shared/auth/admin-auth.js";
import { pool } from "../../shared/db/pool.js";
import { ApiError } from "../../shared/http/api-error.js";
import { audit, mutate } from "../../shared/audit/audit.js";
import { reportCsv, reportQuery } from "../../modules/reports/sales-report.js";

export const adminRouter = Router();
adminRouter.use(requireAdmin);
const id = z.string().uuid();
const page = (req: AdminRequest) => ({
  limit: Math.min(100, Math.max(1, Number(req.query.limit) || 25)),
  offset: Math.max(0, Number(req.query.offset) || 0),
});
const actor = (res: Response) => (res.req as AdminRequest).actor!.id;
const send = (res: Response, data: unknown) => res.json({ data });
const validId = (value: string) => {
  if (!id.safeParse(value).success)
    throw new ApiError(400, "INVALID_ID", "A valid record id is required");
  return value;
};
const text = (v: unknown, max = 500) =>
  typeof v === "string" ? v.trim().slice(0, max) : "";
const dateFilters = (req: AdminRequest) => {
  const from = req.query.from ? String(req.query.from) : null,
    to = req.query.to ? String(req.query.to) : null;
  if (
    (from && Number.isNaN(Date.parse(from))) ||
    (to && Number.isNaN(Date.parse(to)))
  )
    throw new ApiError(400, "INVALID_DATE", "from/to must be ISO dates");
  if (from && to && Date.parse(to) < Date.parse(from))
    throw new ApiError(400, "INVALID_RANGE", "to must be on or after from");
  return { from, to };
};
const read = (sql: string, values: unknown[] = []) => pool.query(sql, values);
const result = (res: Response, rows: unknown[]) =>
  res.json({ data: rows, pagination: { count: rows.length } });

adminRouter.get("/capabilities", (_req, res) =>
  send(res, {
    apiVersion: "v1",
    navigation: [
      "Dashboard",
      "Users",
      "Seller Applications",
      "Products",
      "Orders",
      "Sales Reports",
      "System Settings",
    ],
    modules: [
      "dashboard",
      "users",
      "seller-applications",
      "products",
      "orders",
      "payments",
      "sales-reports",
      "system-settings",
      "activity-log",
    ],
  }),
);

// Dashboard
adminRouter.get("/dashboard/metrics", async (_req, res) => {
  const q =
    await read(`SELECT (SELECT count(*) FROM users WHERE role='customer' AND deleted_at IS NULL) customers,
    (SELECT count(*) FROM users WHERE role='seller' AND deleted_at IS NULL) sellers,
    (SELECT count(*) FROM products WHERE deleted_at IS NULL) products,
    (SELECT count(*) FROM orders) orders,
    COALESCE((SELECT sum(total_centavos) FROM orders WHERE payment_status='paid'),0)::text sales`);
  send(res, q.rows[0]);
});
adminRouter.get("/dashboard/activity", async (req: AdminRequest, res) => {
  const p = page(req);
  const q = await read(
    `SELECT * FROM (SELECT id,actor_id,action,target_type,target_id,metadata,occurred_at FROM admin_audit_logs UNION ALL SELECT id,changed_by actor_id,'order.status_changed' action,'order' target_type,order_id target_id,jsonb_build_object('from',from_status,'to',to_status,'note',note) metadata,changed_at occurred_at FROM order_status_history) activity ORDER BY occurred_at DESC LIMIT $1 OFFSET $2`,
    [p.limit, p.offset],
  );
  result(res, q.rows);
});

// Customers and status workflows; state and audit commit atomically.
adminRouter.get("/users", async (req: AdminRequest, res) => {
  const p = page(req),
    status = String(req.query.status || ""),
    search = `%${String(req.query.search || "").slice(0, 100)}%`;
  const q = await read(
    `SELECT id,email,full_name,phone,status,created_at FROM users WHERE role='customer' AND deleted_at IS NULL AND ($1='' OR status=$1) AND ($2='%%' OR email ILIKE $2 OR full_name ILIKE $2) ORDER BY created_at DESC LIMIT $3 OFFSET $4`,
    [status, search, p.limit, p.offset],
  );
  result(res, q.rows);
});
adminRouter.get("/users/:id", async (req, res) => {
  const q = await read(
    `SELECT id,email,full_name,phone,status,created_at,updated_at FROM users WHERE id=$1 AND role='customer' AND deleted_at IS NULL`,
    [validId(req.params.id)],
  );
  if (!q.rowCount) throw new ApiError(404, "NOT_FOUND", "Customer not found");
  send(res, q.rows[0]);
});
for (const [verb, status] of [
  ["activate", "active"],
  ["suspend", "suspended"],
  ["restore", "active"],
] as const)
  adminRouter.post(`/users/:id/${verb}`, async (req, res) => {
    const uid = validId(req.params.id);
    const reason = text(req.body?.reason);
    if (verb === "suspend" && !reason)
      throw new ApiError(
        400,
        "REASON_REQUIRED",
        "A suspension reason is required",
      );
    const row = await mutate(
      actor(res),
      `user.${verb}`,
      "user",
      uid,
      async (c) => {
        const b = await c.query(
          `SELECT id,status FROM users WHERE id=$1 AND role='customer' AND deleted_at IS NULL FOR UPDATE`,
          [uid],
        );
        if (!b.rowCount)
          throw new ApiError(404, "NOT_FOUND", "Customer not found");
        const u = await c.query(
          `UPDATE users SET status=$2,updated_at=now() WHERE id=$1 RETURNING id,status`,
          [uid, status],
        );
        return { before: b.rows[0], after: u.rows[0], metadata: { reason } };
      },
    );
    send(res, row.after);
  });

// Seller application review plus outbox notification.
adminRouter.get("/seller-applications", async (req: AdminRequest, res) => {
  const p = page(req),
    s = String(req.query.status || "");
  const q = await read(
    `SELECT a.*,u.email,u.full_name,sp.legal_name,st.id store_id,st.name store_name FROM seller_applications a JOIN users u ON u.id=a.seller_user_id LEFT JOIN seller_profiles sp ON sp.user_id=u.id LEFT JOIN stores st ON st.seller_profile_id=sp.id WHERE ($1='' OR a.status=$1) ORDER BY a.submitted_at DESC LIMIT $2 OFFSET $3`,
    [s, p.limit, p.offset],
  );
  result(res, q.rows);
});
adminRouter.get("/seller-applications/:id", async (req, res) => {
  const q = await read(
    `SELECT a.*,u.email,u.full_name,sp.legal_name,st.id store_id,st.name store_name,st.status store_status FROM seller_applications a JOIN users u ON u.id=a.seller_user_id LEFT JOIN seller_profiles sp ON sp.user_id=u.id LEFT JOIN stores st ON st.seller_profile_id=sp.id WHERE a.id=$1`,
    [validId(req.params.id)],
  );
  if (!q.rowCount)
    throw new ApiError(404, "NOT_FOUND", "Application not found");
  send(res, q.rows[0]);
});
adminRouter.get("/stores/:id", async (req, res) => {
  const q = await read(
    `SELECT st.*,u.id seller_id,u.email,u.full_name FROM stores st JOIN seller_profiles sp ON sp.id=st.seller_profile_id JOIN users u ON u.id=sp.user_id WHERE st.id=$1`,
    [validId(req.params.id)],
  );
  if (!q.rowCount) throw new ApiError(404, "NOT_FOUND", "Store not found");
  send(res, q.rows[0]);
});
for (const decision of ["approve", "reject"] as const)
  adminRouter.post(`/seller-applications/:id/${decision}`, async (req, res) => {
    const aid = validId(req.params.id),
      reason = text(req.body?.reason);
    if (decision === "reject" && !reason)
      throw new ApiError(
        400,
        "REASON_REQUIRED",
        "A rejection reason is required",
      );
    const row = await mutate(
      actor(res),
      `seller_application.${decision}`,
      "seller_application",
      aid,
      async (c) => {
        const b = await c.query(
          `SELECT a.*,u.email FROM seller_applications a JOIN users u ON u.id=a.seller_user_id WHERE a.id=$1 FOR UPDATE OF a`,
          [aid],
        );
        if (!b.rowCount)
          throw new ApiError(404, "NOT_FOUND", "Application not found");
        if (b.rows[0].status !== "pending")
          throw new ApiError(
            409,
            "INVALID_TRANSITION",
            "Only pending applications can be reviewed",
          );
        const status = decision === "approve" ? "approved" : "rejected";
        const u = await c.query(
          `UPDATE seller_applications SET status=$2,reviewed_by=$3,reviewed_at=now(),rejection_reason=$4 WHERE id=$1 RETURNING *`,
          [aid, status, actor(res), decision === "reject" ? reason : null],
        );
        if (decision === "approve") {
          await c.query(
            `UPDATE users SET status='active',updated_at=now() WHERE id=$1`,
            [b.rows[0].seller_user_id],
          );
          await c.query(
            `UPDATE stores SET status='active',updated_at=now() WHERE seller_profile_id=(SELECT id FROM seller_profiles WHERE user_id=$1)`,
            [b.rows[0].seller_user_id],
          );
        }
        await c.query(
          `INSERT INTO notification_outbox(deduplication_key,recipient_user_id,notification_type,payload) VALUES($1,$2,$3,$4) ON CONFLICT(deduplication_key) DO NOTHING`,
          [
            `application:${aid}:${status}`,
            b.rows[0].seller_user_id,
            `seller_application_${status}`,
            JSON.stringify({
              applicationId: aid,
              status,
              reason: decision === "reject" ? reason : null,
            }),
          ],
        );
        return {
          before: { id: aid, status: "pending" },
          after: u.rows[0],
          metadata: { reason },
        };
      },
    );
    send(res, row.after);
  });
for (const status of ["suspend", "restore"] as const)
  adminRouter.post(`/stores/:id/${status}`, async (req, res) => {
    const sid = validId(req.params.id),
      reason = text(req.body?.reason);
    if (status === "suspend" && !reason)
      throw new ApiError(
        400,
        "REASON_REQUIRED",
        "A suspension reason is required",
      );
    const row = await mutate(
      actor(res),
      `store.${status}`,
      "store",
      sid,
      async (c) => {
        const b = await c.query(
          `SELECT id,status FROM stores WHERE id=$1 FOR UPDATE`,
          [sid],
        );
        if (!b.rowCount)
          throw new ApiError(404, "NOT_FOUND", "Store not found");
        const a = await c.query(
          `UPDATE stores SET status=$2,updated_at=now() WHERE id=$1 RETURNING id,status`,
          [sid, status === "suspend" ? "suspended" : "active"],
        );
        return { before: b.rows[0], after: a.rows[0], metadata: { reason } };
      },
    );
    send(res, row.after);
  });

// Product registry/moderation. Creation/edit/publish/archive live in System Settings.
adminRouter.get("/products", async (req: AdminRequest, res) => {
  const p = page(req);
  const q = await read(
    `SELECT pr.*,c.name category_name,st.name seller_name FROM products pr JOIN categories c ON c.id=pr.category_id JOIN seller_profiles sp ON sp.id=pr.seller_profile_id JOIN stores st ON st.seller_profile_id=sp.id WHERE pr.deleted_at IS NULL AND ($1='' OR pr.status=$1) AND (NULLIF($2,'') IS NULL OR pr.category_id=NULLIF($2,'')::uuid) AND (NULLIF($3,'') IS NULL OR pr.seller_profile_id=NULLIF($3,'')::uuid) AND ($4='' OR pr.name ILIKE '%'||$4||'%' OR pr.sku ILIKE '%'||$4||'%') ORDER BY pr.created_at DESC LIMIT $5 OFFSET $6`,
    [
      String(req.query.status || ""),
      String(req.query.categoryId || ""),
      String(req.query.sellerId || ""),
      String(req.query.search || "").slice(0, 100),
      p.limit,
      p.offset,
    ],
  );
  result(res, q.rows);
});
adminRouter.get("/products/:id", async (req, res) => {
  const q = await read(
    `SELECT pr.*,c.name category_name,st.name seller_name FROM products pr JOIN categories c ON c.id=pr.category_id JOIN seller_profiles sp ON sp.id=pr.seller_profile_id JOIN stores st ON st.seller_profile_id=sp.id WHERE pr.id=$1 AND pr.deleted_at IS NULL`,
    [validId(req.params.id)],
  );
  if (!q.rowCount) throw new ApiError(404, "NOT_FOUND", "Product not found");
  send(res, q.rows[0]);
});
for (const action of ["deactivate", "restore"] as const)
  adminRouter.post(`/products/:id/${action}`, async (req, res) => {
    const pid = validId(req.params.id),
      reason = text(req.body?.reason);
    if (action === "deactivate" && !reason)
      throw new ApiError(
        400,
        "REASON_REQUIRED",
        "A moderation reason is required",
      );
    const row = await mutate(
      actor(res),
      `product.${action}`,
      "product",
      pid,
      async (c) => {
        const b = await c.query(
          `SELECT id,status FROM products WHERE id=$1 AND deleted_at IS NULL FOR UPDATE`,
          [pid],
        );
        if (!b.rowCount)
          throw new ApiError(404, "NOT_FOUND", "Product not found");
        const next = action === "deactivate" ? "deactivated" : "published";
        const a = await c.query(
          `UPDATE products SET status=$2,moderation_reason=$3,updated_at=now() WHERE id=$1 RETURNING id,status,moderation_reason`,
          [pid, next, action === "deactivate" ? reason : null],
        );
        return { before: b.rows[0], after: a.rows[0], metadata: { reason } };
      },
    );
    send(res, row.after);
  });

// Orders are read-only to Management. Fulfillment transitions remain seller-owned.
adminRouter.get("/orders", async (req: AdminRequest, res) => {
  const p = page(req),
    d = dateFilters(req);
  const q = await read(
    `SELECT o.id,o.order_no,o.created_at,o.status,o.payment_status,o.total_centavos::text,c.full_name customer_name,count(oi.id)::int item_count FROM orders o JOIN users c ON c.id=o.customer_id LEFT JOIN order_items oi ON oi.order_id=o.id WHERE ($1::timestamptz IS NULL OR o.created_at >= $1) AND ($2::timestamptz IS NULL OR o.created_at < $2::timestamptz + interval '1 day') AND ($3='' OR o.status=$3) AND ($4='' OR o.payment_status=$4) GROUP BY o.id,c.full_name ORDER BY o.created_at DESC LIMIT $5 OFFSET $6`,
    [
      d.from,
      d.to,
      String(req.query.status || ""),
      String(req.query.paymentStatus || ""),
      p.limit,
      p.offset,
    ],
  );
  result(res, q.rows);
});
adminRouter.get("/orders/:id", async (req, res) => {
  const oid = validId(req.params.id);
  const o = await read(
    `SELECT o.*,c.full_name customer_name,c.email customer_email FROM orders o JOIN users c ON c.id=o.customer_id WHERE o.id=$1`,
    [oid],
  );
  if (!o.rowCount) throw new ApiError(404, "NOT_FOUND", "Order not found");
  const [items, history] = await Promise.all([
    read(
      `SELECT oi.*,st.name seller_name FROM order_items oi JOIN seller_profiles sp ON sp.id=oi.seller_profile_id JOIN stores st ON st.seller_profile_id=sp.id WHERE oi.order_id=$1`,
      [oid],
    ),
    read(
      `SELECT * FROM order_status_history WHERE order_id=$1 ORDER BY changed_at`,
      [oid],
    ),
  ]);
  send(res, { ...o.rows[0], items: items.rows, statusHistory: history.rows });
});

// Payments expose safe reconciliation fields only.
adminRouter.get("/payments", async (req: AdminRequest, res) => {
  const p = page(req),
    d = dateFilters(req);
  const q = await read(
    `SELECT id,order_id,provider,provider_reference,amount_centavos::text,currency,status,paid_at,created_at,updated_at FROM payments WHERE ($1::timestamptz IS NULL OR created_at >= $1) AND ($2::timestamptz IS NULL OR created_at < $2::timestamptz + interval '1 day') AND ($3='' OR status=$3) ORDER BY created_at DESC LIMIT $4 OFFSET $5`,
    [d.from, d.to, String(req.query.status || ""), p.limit, p.offset],
  );
  result(res, q.rows);
});
adminRouter.get("/payments/:id", async (req, res) => {
  const pid = validId(req.params.id);
  const q = await read(
    `SELECT id,order_id,provider,provider_reference,amount_centavos::text,currency,status,paid_at,safe_metadata,created_at,updated_at FROM payments WHERE id=$1`,
    [pid],
  );
  if (!q.rowCount) throw new ApiError(404, "NOT_FOUND", "Payment not found");
  const e = await read(
    `SELECT event_type,received_at,processed_at,processing_status FROM payment_events WHERE payment_id=$1 ORDER BY received_at`,
    [pid],
  );
  send(res, { ...q.rows[0], events: e.rows });
});

// Reports use identical SQL/calculations for JSON and export.
adminRouter.get("/reports/sales", async (req: AdminRequest, res) => {
  const filters = dateFilters(req);
  const data = await reportQuery(filters.from, filters.to);
  await audit(actor(res), "sales_report.view", "report", null, { filters });
  send(res, data);
});
adminRouter.get("/reports/sales.csv", async (req: AdminRequest, res) => {
  const filters = dateFilters(req);
  const csv = await reportCsv(filters.from, filters.to);
  await audit(actor(res), "sales_report.export", "report", null, {
    filters,
    rows: csv.rows,
  });
  res
    .type("text/csv; charset=utf-8")
    .attachment("sales-report.csv")
    .send(csv.content);
});

// System Settings: product CRUD, categories and typed business configuration.
const productSchema = z.object({
  sellerProfileId: id,
  categoryId: id,
  name: z.string().trim().min(1).max(160),
  sku: z.string().trim().min(1).max(80),
  description: z.string().max(10000).default(""),
  priceCentavos: z.coerce.number().int().min(0).max(1000000000),
  stock: z.coerce.number().int().min(0).max(10000000),
});
adminRouter.post("/settings/products", async (req, res) => {
  const v = productSchema.parse(req.body);
  const row = await mutate(
    actor(res),
    "product.created",
    "product",
    null,
    async (c) => {
      const q = await c.query(
        `INSERT INTO products(seller_profile_id,category_id,created_by,name,sku,description,price_centavos,stock,status) VALUES($1,$2,$3,$4,$5,$6,$7,$8,'draft') RETURNING *`,
        [
          v.sellerProfileId,
          v.categoryId,
          actor(res),
          v.name,
          v.sku,
          v.description,
          v.priceCentavos,
          v.stock,
        ],
      );
      return { before: null, after: q.rows[0], metadata: { sku: v.sku } };
    },
  );
  res.status(201);
  send(res, row.after);
});
adminRouter.patch("/settings/products/:id", async (req, res) => {
  const pid = validId(req.params.id),
    v = productSchema.partial().parse(req.body);
  const row = await mutate(
    actor(res),
    "product.updated",
    "product",
    pid,
    async (c) => {
      const b = await c.query(
        `SELECT * FROM products WHERE id=$1 AND deleted_at IS NULL FOR UPDATE`,
        [pid],
      );
      if (!b.rowCount)
        throw new ApiError(404, "NOT_FOUND", "Product not found");
      const old = b.rows[0];
      const q = await c.query(
        `UPDATE products SET seller_profile_id=$2,category_id=$3,name=$4,sku=$5,description=$6,price_centavos=$7,stock=$8,updated_at=now() WHERE id=$1 RETURNING *`,
        [
          pid,
          v.sellerProfileId ?? old.seller_profile_id,
          v.categoryId ?? old.category_id,
          v.name ?? old.name,
          v.sku ?? old.sku,
          v.description ?? old.description,
          v.priceCentavos ?? old.price_centavos,
          v.stock ?? old.stock,
        ],
      );
      return { before: old, after: q.rows[0], metadata: {} };
    },
  );
  send(res, row.after);
});
for (const [op, status] of [
  ["publish", "published"],
  ["archive", "archived"],
] as const)
  adminRouter.post(`/settings/products/:id/${op}`, async (req, res) => {
    const pid = validId(req.params.id);
    const row = await mutate(
      actor(res),
      `product.${op}`,
      "product",
      pid,
      async (c) => {
        const b = await c.query(
          `SELECT id,status FROM products WHERE id=$1 AND deleted_at IS NULL FOR UPDATE`,
          [pid],
        );
        if (!b.rowCount)
          throw new ApiError(404, "NOT_FOUND", "Product not found");
        const a = await c.query(
          `UPDATE products SET status=$2,updated_at=now() WHERE id=$1 RETURNING id,status`,
          [pid, status],
        );
        return { before: b.rows[0], after: a.rows[0], metadata: {} };
      },
    );
    send(res, row.after);
  });
adminRouter.get("/settings/categories", async (_req, res) => {
  const q = await read(`SELECT * FROM categories ORDER BY name`);
  result(res, q.rows);
});
adminRouter.post("/settings/categories", async (req, res) => {
  const name = text(req.body?.name, 100),
    slug = text(req.body?.slug, 120).toLowerCase();
  if (!name || !slug)
    throw new ApiError(400, "INVALID_CATEGORY", "name and slug are required");
  const row = await mutate(
    actor(res),
    "category.created",
    "category",
    null,
    async (c) => {
      const q = await c.query(
        `INSERT INTO categories(name,slug,created_by,updated_by) VALUES($1,$2,$3,$3) RETURNING *`,
        [name, slug, actor(res)],
      );
      return { before: null, after: q.rows[0], metadata: {} };
    },
  );
  res.status(201);
  send(res, row.after);
});
adminRouter.patch("/settings/categories/:id", async (req, res) => {
  const cid = validId(req.params.id),
    name = text(req.body?.name, 100),
    slug = text(req.body?.slug, 120).toLowerCase();
  const row = await mutate(
    actor(res),
    "category.updated",
    "category",
    cid,
    async (c) => {
      const b = await c.query(
        `SELECT * FROM categories WHERE id=$1 FOR UPDATE`,
        [cid],
      );
      if (!b.rowCount)
        throw new ApiError(404, "NOT_FOUND", "Category not found");
      const q = await c.query(
        `UPDATE categories SET name=COALESCE(NULLIF($2,''),name),slug=COALESCE(NULLIF($3,''),slug),updated_by=$4,updated_at=now() WHERE id=$1 RETURNING *`,
        [cid, name, slug, actor(res)],
      );
      return { before: b.rows[0], after: q.rows[0], metadata: {} };
    },
  );
  send(res, row.after);
});
adminRouter.post("/settings/categories/:id/deactivate", async (req, res) => {
  const cid = validId(req.params.id);
  const row = await mutate(
    actor(res),
    "category.deactivated",
    "category",
    cid,
    async (c) => {
      const b = await c.query(
        `SELECT * FROM categories WHERE id=$1 FOR UPDATE`,
        [cid],
      );
      if (!b.rowCount)
        throw new ApiError(404, "NOT_FOUND", "Category not found");
      const active = await c.query(
        `SELECT 1 FROM products WHERE category_id=$1 AND status='published' AND deleted_at IS NULL LIMIT 1`,
        [cid],
      );
      if (active.rowCount)
        throw new ApiError(
          409,
          "CATEGORY_IN_USE",
          "Move or archive published products before deactivating this category",
        );
      const a = await c.query(
        `UPDATE categories SET status='inactive',updated_by=$2,updated_at=now() WHERE id=$1 RETURNING *`,
        [cid, actor(res)],
      );
      return { before: b.rows[0], after: a.rows[0], metadata: {} };
    },
  );
  send(res, row.after);
});
adminRouter.get("/settings/business", async (_req, res) => {
  const q = await read(
    `SELECT key,value,updated_at FROM business_settings ORDER BY key`,
  );
  result(res, q.rows);
});
adminRouter.put("/settings/business/:key", async (req, res) => {
  const key = String(req.params.key);
  const allowed = new Set([
    "shipping_fee_centavos",
    "platform_commission_percent",
    "return_window_days",
    "platform_rules",
  ]);
  if (!allowed.has(key))
    throw new ApiError(400, "INVALID_SETTING", "Setting key is not supported");
  const value = req.body?.value;
  if (value === undefined)
    throw new ApiError(400, "INVALID_SETTING", "value is required");
  const row = await mutate(
    actor(res),
    "business_setting.updated",
    "business_setting",
    null,
    async (c) => {
      const b = await c.query(
        `SELECT value FROM business_settings WHERE key=$1 FOR UPDATE`,
        [key],
      );
      const a = await c.query(
        `INSERT INTO business_settings(key,value,updated_by) VALUES($1,$2,$3) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value,updated_by=EXCLUDED.updated_by,updated_at=now() RETURNING key,value,updated_at`,
        [key, JSON.stringify(value), actor(res)],
      );
      return { before: b.rows[0] || null, after: a.rows[0], metadata: { key } };
    },
  );
  send(res, row.after);
});

// Administrative Activity Log is read-only.
adminRouter.get("/activity", async (req: AdminRequest, res) => {
  const p = page(req),
    d = dateFilters(req);
  const q = await read(
    `SELECT a.*,u.full_name actor_name,u.email actor_email FROM admin_audit_logs a JOIN users u ON u.id=a.actor_id WHERE ($1::timestamptz IS NULL OR a.occurred_at >= $1) AND ($2::timestamptz IS NULL OR a.occurred_at < $2::timestamptz + interval '1 day') AND ($3='' OR a.action=$3) AND (NULLIF($4,'') IS NULL OR a.actor_id=NULLIF($4,'')::uuid) ORDER BY a.occurred_at DESC LIMIT $5 OFFSET $6`,
    [
      d.from,
      d.to,
      String(req.query.action || ""),
      String(req.query.actorId || ""),
      p.limit,
      p.offset,
    ],
  );
  result(res, q.rows);
});
