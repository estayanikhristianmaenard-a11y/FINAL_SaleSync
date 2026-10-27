import { readdir, readFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { pool } from "./pool.js";

const here = dirname(fileURLToPath(import.meta.url));
const migrationsDirectory = join(here, "../../migrations");

async function migrate(): Promise<void> {
  const client = await pool.connect();
  try {
    await client.query(`
      CREATE TABLE IF NOT EXISTS schema_migrations (
        name text PRIMARY KEY,
        applied_at timestamptz NOT NULL DEFAULT now()
      )
    `);

    const files = (await readdir(migrationsDirectory))
      .filter((name) => /^\d+_[a-z0-9_-]+\.sql$/i.test(name))
      .sort();

    for (const name of files) {
      const { rowCount } = await client.query(
        "SELECT 1 FROM schema_migrations WHERE name = $1",
        [name],
      );
      if (rowCount) continue;

      const sql = await readFile(join(migrationsDirectory, name), "utf8");
      await client.query("BEGIN");
      try {
        await client.query(sql);
        await client.query("INSERT INTO schema_migrations (name) VALUES ($1)", [
          name,
        ]);
        await client.query("COMMIT");
        console.info(`Applied migration ${name}`);
      } catch (error) {
        await client.query("ROLLBACK");
        throw error;
      }
    }
    console.info("Database migrations are up to date");
  } finally {
    client.release();
    await pool.end();
  }
}

migrate().catch((error: unknown) => {
  console.error(
    "Database migration failed",
    error instanceof Error ? error.message : "Unknown error",
  );
  process.exitCode = 1;
});
