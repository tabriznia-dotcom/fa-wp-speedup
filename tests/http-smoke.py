"""HTTP smoke checks for the disposable Docker site; never use on a real shop."""
import http.cookiejar
import json
import os
import re
import urllib.parse
import urllib.request
from pathlib import Path

state = Path(os.environ.get('FAWS_DEV_STATE', '/workspace/.faws-dev'))
fixture = json.loads(state.joinpath('woo-fixtures.json').read_text().splitlines()[-1])
base = 'http://localhost:8080'
assert fixture['cart'].startswith(base), 'Disposable localhost site required'
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), urllib.request.ProxyHandler({}))
results = []

def request(url, data=None, expected=200):
    if data is not None:
        data = urllib.parse.urlencode(data).encode()
    try:
        response = client.open(url, data, timeout=30)
        status, body = response.status, response.read().decode('utf-8')
    except urllib.error.HTTPError as error:
        status, body = error.code, error.read().decode('utf-8')
    assert status == expected, (url.split('?')[0], status, expected)
    assert 'Fatal error' not in body and 'critical error on this website' not in body
    return body

def passed(label):
    results.append(label)
    print('PASS', label)

for label, url in [('home', base+'/'), ('shop', fixture['shop']), ('simple', fixture['simple_url']),
                   ('variable', fixture['variable_url']), ('search', base+'/?s=FAWS&post_type=product'),
                   ('account', fixture['account'])]:
    assert '<html' in request(url).lower()
    passed(label)

cart = json.loads(request(base+'/?wc-ajax=add_to_cart', {'product_id':fixture['simple'], 'quantity':1}))
assert cart.get('cart_hash') and not cart.get('error'), cart
passed('AJAX simple add to cart')
variation = request(base+'/?add-to-cart='+str(fixture['variable'])+'&variation_id='+str(fixture['variation'])+'&attribute_size=Small&quantity=1')
cart_html = request(fixture['cart'])
assert 'FAWS' in cart_html
passed('variable add and cart render')
checkout = request(fixture['checkout'])
nonce = re.search(r'name="woocommerce-process-checkout-nonce" value="([^"]+)"', checkout)
assert nonce, 'Classic checkout nonce missing'
order = json.loads(request(base+'/?wc-ajax=checkout', {
    'billing_first_name':'Test', 'billing_last_name':'FAWS', 'billing_country':'IR',
    'billing_address_1':'Test street 1', 'billing_city':'Tabriz', 'billing_state':'EAZ',
    'billing_postcode':'5136811111', 'billing_phone':'09140000000', 'billing_email':'test@example.invalid',
    'payment_method':'cod', 'terms':'on', 'terms-field':'1',
    'woocommerce-process-checkout-nonce':nonce.group(1), '_wp_http_referer':'/?wc-ajax=update_order_review',
}))
assert order['result'] == 'success', order
assert 'order-received' in request(order['redirect'])
passed('classic checkout COD order confirmation (no real gateway)')
# Guest mutation must fail capability checks.
request(base+'/wp-admin/admin-post.php', {'action':'faws_action', 'op':'settings'}, expected=400)
passed('guest settings mutation denied')
creds = dict(line.split('=',1) for line in state.joinpath('credentials.env').read_text().splitlines())
request(base+'/wp-login.php')
request(base+'/wp-login.php', {'log':'faws-admin', 'pwd':creds['FAWS_ADMIN_PASSWORD'], 'wp-submit':'Log In', 'testcookie':'1', 'redirect_to':base+'/wp-admin/'})
page = request(base+'/wp-admin/options-general.php?page=faws')
assert 'bootstrap-js-5.3.3' in page
passed('authenticated RTL settings page')
request(base+'/wp-admin/admin-post.php', {'action':'faws_action', 'op':'settings', '_wpnonce':'invalid'}, expected=403)
passed('CSRF invalid nonce denied')
nonce = re.search(r'name="_wpnonce" value="([^"]+)"', page).group(1)
request(base+'/wp-admin/admin-post.php', {'action':'faws_action', 'op':'download', 'resource':'bootstrap-css-5.3.3', '_wpnonce':nonce})
passed('admin consent schedules supported resource')
for label, url in [('HPOS orders screen',base+'/wp-admin/admin.php?page=wc-orders'),
                   ('edit product',base+'/wp-admin/post.php?post='+str(fixture['simple'])+'&action=edit')]:
    assert '<html' in request(url).lower()
    passed(label)
state.joinpath('http-results.json').write_text(json.dumps(results, indent=2))
print(f'PASS {len(results)} HTTP scenarios; browser JS and payment gateway untested')
