-- Refund ledger gives reports a recorded net-sales basis instead of estimating.
CREATE TABLE refund_records (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id uuid NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
  payment_id uuid REFERENCES payments(id) ON DELETE RESTRICT,
  provider_reference text,
  amount_centavos bigint NOT NULL CHECK(amount_centavos > 0),
  status text NOT NULL CHECK(status IN ('pending','succeeded','failed')),
  reason text,
  created_by uuid REFERENCES users(id) ON DELETE RESTRICT,
  created_at timestamptz NOT NULL DEFAULT now(),
  completed_at timestamptz
);
CREATE INDEX refunds_order_status_idx ON refund_records(order_id,status,created_at DESC);
CREATE INDEX refunds_created_idx ON refund_records(created_at DESC) WHERE status='succeeded';

-- Common report and joined-list patterns.
CREATE INDEX orders_date_id_idx ON orders(created_at DESC,id);
CREATE INDEX audit_actor_action_time_idx ON admin_audit_logs(actor_id,action,occurred_at DESC);
