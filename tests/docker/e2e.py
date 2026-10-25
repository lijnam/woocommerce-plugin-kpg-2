#!/usr/bin/env python3
"""Run real classic WooCommerce checkout and return callbacks against Docker."""

import html
from html.parser import HTMLParser
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("KHALTI_E2E_BASE", "http://127.0.0.1:18081")
CHEAP_ID = int(sys.argv[1])
EXPENSIVE_ID = int(sys.argv[2])
CHECKOUT_PAGE_ID = int(sys.argv[3])


class Inputs(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = {}
        self.payment_methods = set()

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "input" and attrs.get("name"):
            self.values[attrs["name"]] = attrs.get("value", "")
            if attrs["name"] == "payment_method":
                self.payment_methods.add(attrs.get("value", ""))


class Session:
    def __init__(self, country=None):
        self.country = country
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )

    def request(self, path, data=None):
        encoded = urllib.parse.urlencode(data).encode() if data is not None else None
        headers = {"CF-IPCountry": self.country} if self.country else {}
        req = urllib.request.Request(BASE + path, data=encoded, headers=headers)
        with self.opener.open(req, timeout=30) as response:
            return response.status, response.url, response.read().decode("utf-8", "replace")


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def checkout(session, product_id, expected_total):
    session.request("/?add-to-cart=%d" % product_id)
    status, _, page = session.request("/?page_id=%d" % CHECKOUT_PAGE_ID)
    require(status == 200, "Checkout page did not load")
    inputs = Inputs()
    inputs.feed(page)
    require("woocommerce-process-checkout-nonce" in inputs.values, "Checkout nonce absent")
    require("khalti" in inputs.payment_methods, "Khalti unavailable for Nepal/NPR checkout")
    require(expected_total in page, "Expected NPR total absent from checkout")

    nonce_match = re.search(r'"update_order_review_nonce":"([^"]+)"', page)
    require(nonce_match, "Order review nonce absent")
    fields = {
        "billing_first_name": "Docker",
        "billing_last_name": "Test",
        "billing_country": "NP",
        "billing_address_1": "1 Test Street",
        "billing_city": "Kathmandu",
        "billing_state": "BAG",
        "billing_postcode": "44600",
        "billing_phone": "9800000000",
        "billing_email": "docker@example.test",
        "payment_method": "khalti",
        "woocommerce-process-checkout-nonce": inputs.values["woocommerce-process-checkout-nonce"],
        "_wp_http_referer": "/?page_id=%d" % CHECKOUT_PAGE_ID,
    }
    status, _, review = session.request("/?wc-ajax=update_order_review", {
        "security": nonce_match.group(1),
        "payment_method": "khalti",
        "country": "NP",
        "state": "BAG",
        "postcode": "44600",
        "city": "Kathmandu",
        "address": "1 Test Street",
        "post_data": urllib.parse.urlencode(fields),
    })
    require(status == 200, "Order review failed to load")
    review_data = json.loads(review)
    require(review_data.get("result") == "success", "Order review failed: " + review[:500])
    review_html = " ".join(review_data.get("fragments", {}).values())
    require(expected_total in review_html, "Expected NPR total absent after order review")

    status, _, result = session.request("/?wc-ajax=checkout", fields)
    require(status == 200, "Checkout submission failed")
    require(result.lstrip().startswith("{"), "Checkout returned non-JSON: " + result[:1000])
    checkout_data = json.loads(result)
    require(checkout_data.get("result") == "success", "Checkout failed: " + result[:1000])
    redirect = html.unescape(checkout_data["redirect"])
    parsed = urllib.parse.urlsplit(redirect)
    require(parsed.hostname == "pay.khalti.com", "Unexpected payment redirect: " + redirect)
    params = urllib.parse.parse_qs(parsed.query)
    for key in ("pidx", "amount", "purchase_order_id", "purchase_order_name", "order_id"):
        require(key in params, "Mock payment URL missing " + key)
    require(params["purchase_order_id"] == params["purchase_order_name"], "Order keys differ")
    return {key: params[key][0] for key in params}


def callback_path(payment, **overrides):
    params = {
        "wc-api": "WC_Gateway_Khalti",
        "pidx": payment["pidx"],
        "amount": payment["amount"],
        "mobile": "9800000000",
        "purchase_order_id": payment["purchase_order_id"],
        "purchase_order_name": payment["purchase_order_name"],
        "transaction_id": "browser-return-id",
    }
    params.update(overrides)
    return "/?" + urllib.parse.urlencode(params)


def expect_rejected(session, path, description):
    try:
        session.request(path)
    except urllib.error.HTTPError as error:
        require(error.code == 500, "%s returned HTTP %d" % (description, error.code))
        return
    raise AssertionError(description + " was accepted")


us = Session("US")
us.request("/?add-to-cart=%d" % CHEAP_ID)
_, _, us_page = us.request("/?page_id=%d" % CHECKOUT_PAGE_ID)
us_inputs = Inputs()
us_inputs.feed(us_page)
require("khalti" not in us_inputs.payment_methods, "Khalti exposed for USD checkout")

cheap = checkout(Session("NP"), CHEAP_ID, "1,300.00")
expensive = checkout(Session("NP"), EXPENSIVE_ID, "2,600.00")
require(cheap["amount"] == "130000", "Cheap order did not initiate for NPR 1300")
require(expensive["amount"] == "260000", "Expensive order did not initiate for NPR 2600")

# A shopper can retry the pending NPR order even after losing CURCY's cookie.
pay_path = "/?" + urllib.parse.urlencode({
    "page_id": CHECKOUT_PAGE_ID,
    "order-pay": cheap["order_id"],
    "pay_for_order": "true",
    "key": cheap["purchase_order_id"],
})
status, pay_final_url, pay_page = Session().request(pay_path)
pay_inputs = Inputs()
pay_inputs.feed(pay_page)
require(status == 200 and "khalti" in pay_inputs.payment_methods,
        "Khalti unavailable for an NPR order-pay retry without a currency cookie: "
        + str((pay_path, pay_final_url, sorted(pay_inputs.payment_methods))))

# A real lookup for the cheaper completed pidx must not pay the expensive order.
return_without_cookie = Session()
expect_rejected(
    return_without_cookie,
    callback_path(expensive, pidx=cheap["pidx"], amount=cheap["amount"]),
    "Cross-order payment",
)
expect_rejected(
    return_without_cookie,
    callback_path(expensive, amount="1"),
    "Tampered callback amount",
)
status, final_url, _ = return_without_cookie.request(callback_path(expensive))
require(status == 200, "Valid callback did not complete")
require("order-received" in final_url, "Valid callback did not reach order confirmation")
status, _, _ = return_without_cookie.request(callback_path(expensive))
require(status == 200, "Repeated callback was not idempotent")

print(json.dumps({
    "cheap": {"order_key": cheap["purchase_order_id"], "pidx": cheap["pidx"]},
    "expensive": {"order_key": expensive["purchase_order_id"], "pidx": expensive["pidx"]},
    "usd_gateway": "hidden",
    "npr_checkout": "accepted",
    "cookieless_order_retry": "available",
    "cross_order": "rejected",
    "tampered_amount": "rejected",
    "cookieless_return": "accepted",
    "repeat_return": "idempotent",
}))
