# Browser regression checks

Requires Node 22+ with built-in WebSocket and a local Chrome/Edge browser with the DevTools port open. No npm dependencies are required. Tests use a dedicated browser profile and replace only that profile's synthetic data.

Launch Chrome in a separate process with these arguments (adjust executable/profile paths for your machine):

```text
chrome --headless=new --remote-debugging-port=9222 --user-data-dir=<temporary-test-profile> --no-first-run about:blank
```

Then run from the project folder:

```sh
node tests/browser-check.mjs verify
```

The runner serves local files on 127.0.0.1:8000, runs the browser suite against the standalone bundle and every independent portal, checks responsive cart/product/compare geometry, the unified operational shell and store-local shopping at 360/390/768/1024/1366/1920 pixels, plus a 200% reflow equivalent, decodes all product image assets, and opens the standalone HTML using file://. Results and screenshots are saved in docs/. It does not contact payment, email, merchant or courier services. Stop your own development server on port 8000 before running if it serves a different folder.

`browser-baseline.json` is the pre-fix reproduction evidence. Do not overwrite it by running the baseline option after applying fixes.
