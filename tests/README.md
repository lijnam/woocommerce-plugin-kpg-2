# Khalti gateway checks

Run the standalone regression suite with `php tests/run.php`. It exercises the
gateway classes with WordPress and WooCommerce stubs, including USD/NPR gateway
availability, payment initiation, exact paisa amounts, Khalti lookup, callback
binding, retries, duplicate charges, and checkout errors. It does not contact
Khalti or change a real order.

Run the Docker end-to-end test from the plugin folder with
`bash tests/docker/run.sh`. It starts WordPress, MySQL, WooCommerce, CURCY,
and the edited Khalti plugin, then checks real HTTP checkout and callback
behavior, including an NPR order payment retry without a currency cookie. A
local WordPress HTTP filter supplies synthetic Khalti API responses; no live
Khalti account or charge is involved. The test uses port
`127.0.0.1:18081` and leaves the stack running for inspection. Stop it with
`docker compose -f tests/docker/compose.yaml down`.
Run `bash tests/docker/run.sh --hpos` to enable WooCommerce HPOS and repeat the
same end-to-end assertions against custom order tables. Run the default test
first on a fresh stack to exercise legacy order storage.

The GitHub Actions workflow in `.github/workflows/khalti-e2e.yml` runs the
standalone checks and both Docker checkout modes on pushes and pull requests.
It downloads CURCY from WordPress.org, verifies version 2.2.19, and passes its directory to Docker
with `CURCY_PLUGIN_PATH`; local runs continue to use the sibling
`woo-multi-currency` folder by default. The workflow needs no Khalti credentials
because the Docker test mocks its API. To use another local CURCY checkout, set
`CURCY_PLUGIN_PATH` to its absolute path.

Before enabling live payments, test these flows on a WooCommerce staging site
with Khalti sandbox credentials:

1. Set the WooCommerce store currency to USD. In CURCY, add NPR, enable **Auto
   select currency**, and enable **Pay in many currencies**. Visit from a Nepal
   location or manually select NPR. Confirm cart, checkout, and the created order
   use NPR and Khalti appears. Select USD and confirm Khalti disappears.
2. Pay an NPR order through the sandbox. Confirm the order records the Khalti
   transaction ID and moves to WooCommerce's normal paid status. Repeat the
   return URL without its currency cookie and confirm the order remains paid.
3. Try a failed or cancelled payment and a failed initiation. Confirm neither
   marks an order paid and the shopper sees a checkout error for initiation
   failures.
4. Initiate payment twice for one order, then pay the first link. Confirm it
   completes. If both links are paid, confirm the second transaction is recorded
   in an order note and the administrator receives an email for reconciliation.
5. Repeat with both WooCommerce HPOS enabled and disabled, and with a physical
   and a virtual product.

Payment links initiated by older plugin versions lack a stored `pidx`; this
version cannot safely verify those returns. Reconcile any such in-flight
payments manually against Khalti before changing their order status.
