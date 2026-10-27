import { pool } from "../../shared/db/pool.js";

export type DateRange = { from: string | null; to: string | null };
const sql = `WITH lines AS (
 SELECT o.id,o.order_no,o.created_at,o.status order_status,o.payment_status,o.total_centavos,
        c.full_name customer,st.name seller,oi.line_total_centavos
 FROM orders o JOIN users c ON c.id=o.customer_id
 JOIN order_items oi ON oi.order_id=o.id
 JOIN seller_profiles sp ON sp.id=oi.seller_profile_id JOIN stores st ON st.seller_profile_id=sp.id
 WHERE ($1::timestamptz IS NULL OR o.created_at >= $1) AND ($2::timestamptz IS NULL OR o.created_at < $2::timestamptz + interval '1 day')
), order_totals AS (SELECT DISTINCT id,order_no,created_at,order_status,payment_status,total_centavos,customer FROM lines),
 refunds AS (SELECT order_id,COALESCE(sum(amount_centavos) FILTER(WHERE status='succeeded'),0) refunded FROM refund_records GROUP BY order_id),
 summary AS (SELECT count(*)::int total_orders,count(*) FILTER(WHERE payment_status='paid')::int paid_orders,
 COALESCE(sum(total_centavos) FILTER(WHERE payment_status='paid'),0)::text gross_centavos,
 COALESCE(sum(GREATEST(total_centavos-COALESCE(refunds.refunded,0),0)) FILTER(WHERE payment_status='paid'),0)::text net_centavos FROM order_totals LEFT JOIN refunds ON refunds.order_id=order_totals.id),
 sellers AS (SELECT seller,COALESCE(sum(line_total_centavos) FILTER(WHERE payment_status='paid'),0)::text sales_centavos FROM lines GROUP BY seller)
 SELECT json_build_object('summary',(SELECT row_to_json(summary) FROM summary),'sellerSales',COALESCE((SELECT json_agg(sellers ORDER BY seller) FROM sellers),'[]'::json),'orders',COALESCE((SELECT json_agg(row_to_json(order_totals) ORDER BY created_at DESC) FROM order_totals),'[]'::json)) report`;
export async function reportQuery(
  from: string | null,
  to: string | null,
): Promise<any> {
  const q = await pool.query(sql, [from, to]);
  return (
    q.rows[0]?.report ?? {
      summary: {
        total_orders: 0,
        paid_orders: 0,
        gross_centavos: "0",
        net_centavos: "0",
      },
      sellerSales: [],
      orders: [],
    }
  );
}
function cell(value: unknown): string {
  let s = String(value ?? "");
  if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
  return `"${s.replaceAll('"', '""')}"`;
}
export async function reportCsv(
  from: string | null,
  to: string | null,
): Promise<{ content: string; rows: number }> {
  const q = await pool.query(
    `SELECT o.order_no,to_char(o.created_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"') date,c.full_name customer, string_agg(DISTINCT st.name, ', ' ORDER BY st.name) seller,o.total_centavos::text total,o.payment_status,o.status
 FROM orders o JOIN users c ON c.id=o.customer_id JOIN order_items oi ON oi.order_id=o.id JOIN seller_profiles sp ON sp.id=oi.seller_profile_id JOIN stores st ON st.seller_profile_id=sp.id
 WHERE ($1::timestamptz IS NULL OR o.created_at >= $1) AND ($2::timestamptz IS NULL OR o.created_at < $2::timestamptz + interval '1 day') GROUP BY o.id,c.full_name ORDER BY o.created_at`,
    [from, to],
  );
  const headers = [
    "Order No.",
    "Date",
    "Customer",
    "Seller",
    "Total",
    "Payment Status",
    "Order Status",
  ];
  const lines = [
    headers.map(cell).join(","),
    ...q.rows.map((r) =>
      [
        r.order_no,
        r.date,
        r.customer,
        r.seller,
        (Number(r.total) / 100).toFixed(2),
        r.payment_status,
        r.status,
      ]
        .map(cell)
        .join(","),
    ),
  ];
  const paid = q.rows.filter((r) => r.payment_status === "paid");
  const total = paid.reduce((n, r) => n + Number(r.total), 0);
  lines.push(
    [
      "SUMMARY TOTALS",
      "",
      "",
      "",
      (total / 100).toFixed(2),
      `${paid.length} paid / ${q.rowCount ?? 0} orders`,
      "",
    ]
      .map(cell)
      .join(","),
  );
  return {
    content: "\uFEFF" + lines.join("\r\n") + "\r\n",
    rows: q.rowCount ?? 0,
  };
}
