CREATE TABLE checkout_quotes (
    token_hash TEXT PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES users(user_id),
    fingerprint TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE INDEX checkout_quote_expiry ON checkout_quotes(expires_at);
