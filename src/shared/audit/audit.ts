import type { PoolClient } from "pg";
import { pool } from "../db/pool.js";

function scrub(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(scrub);
  if (!value || typeof value !== "object") return value;
  return Object.fromEntries(
    Object.entries(value)
      .filter(
        ([key]) => !/(secret|password|token|authorization|api.?key)/i.test(key),
      )
      .map(([k, v]) => [k, scrub(v)]),
  );
}
export async function writeAudit(
  client: PoolClient,
  actorId: string,
  action: string,
  targetType: string,
  targetId: string | null,
  before: unknown,
  after: unknown,
  metadata: unknown,
): Promise<void> {
  await client.query(
    `INSERT INTO admin_audit_logs(actor_id,action,target_type,target_id,before_data,after_data,metadata) VALUES($1,$2,$3,$4,$5,$6,$7)`,
    [
      actorId,
      action,
      targetType,
      targetId,
      before === null ? null : JSON.stringify(scrub(before)),
      after === null ? null : JSON.stringify(scrub(after)),
      JSON.stringify(scrub(metadata ?? {})),
    ],
  );
}
export async function audit(
  actorId: string,
  action: string,
  targetType: string,
  targetId: string | null,
  metadata: unknown = {},
): Promise<void> {
  const c = await pool.connect();
  try {
    await c.query("BEGIN");
    await writeAudit(
      c,
      actorId,
      action,
      targetType,
      targetId,
      null,
      null,
      metadata,
    );
    await c.query("COMMIT");
  } catch (e) {
    await c.query("ROLLBACK");
    throw e;
  } finally {
    c.release();
  }
}
export async function mutate<T>(
  actorId: string,
  action: string,
  targetType: string,
  targetId: string | null,
  fn: (
    client: PoolClient,
  ) => Promise<{ before: unknown; after: T; metadata?: unknown }>,
): Promise<{ after: T }> {
  const c = await pool.connect();
  try {
    await c.query("BEGIN");
    const change = await fn(c);
    const actualId =
      targetId ?? (change.after as { id?: string } | null)?.id ?? null;
    await writeAudit(
      c,
      actorId,
      action,
      targetType,
      actualId,
      change.before,
      change.after,
      change.metadata ?? {},
    );
    await c.query("COMMIT");
    return { after: change.after };
  } catch (e) {
    await c.query("ROLLBACK");
    throw e;
  } finally {
    c.release();
  }
}
