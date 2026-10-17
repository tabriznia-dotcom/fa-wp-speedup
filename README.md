# شتاب وردپرس — FA WP Speedup

[English](#english) · [فارسی](#فارسی)

## English

FA WP Speedup is a modular WordPress performance plugin designed for Persian and RTL sites, WooCommerce stores, and unreliable access to external services from Iran. Version **0.2.0 is a beta release**. It provides local diagnostics, approved CDN localization, non-destructive image derivatives, and page-specific asset controls. All optimization options are disabled by default and can be enabled independently.

### Features

- **Local diagnostics:** inspect registered and rendered external resources during administrator visits, request timings, database query counts, external HTTP activity, cache status, autoloaded options, WP-Cron, and Action Scheduler. Diagnostics are bounded and stored locally. Server processing time is not the same as browser TTFB; detailed query timing depends on existing `SAVEQUERIES` instrumentation.
- **Approved CDN localization:** download and serve the exact supported Bootstrap 5.3.3 CSS and JavaScript bundle from jsDelivr after administrator approval. Downloads run through WP-Cron and validate HTTPS, MIME type, size, and official SHA-384 integrity hashes. Download approval and activation are separate steps. Missing or corrupt local files fall back to the original URL.
- **Image optimization:** generate separate WebP and AVIF derivatives of eligible local JPEG and static PNG images when the server supports the codec. Original files and WordPress attachment metadata are preserved. Generation runs in small background batches; smaller, verified WebP files can be activated per image. **AVIF is preview-only in this release.**
- **Responsive images and auditing:** preserve WordPress `srcset` widths, optionally fill missing image dimensions, and optionally prioritize the first recognized featured image on a singular page. An administrator-only browser audit reports oversized images using rendered dimensions and device pixel ratio. Featured-image priority is not automatic LCP detection.
- **Advanced asset controls:** create exact-handle rules for supported page contexts to unload eligible independent assets or use WordPress's native `defer` strategy. Start with a nonce-protected administrator preview. Public activation requires manual confirmation of baseline measurements and preview checks. Core, dependent, localized, inline-associated, and sensitive resources are protected.
- **Dashboard options:** optionally hide the WordPress news widget and set Heartbeat to 60 seconds on the administrator dashboard. The editor and post-locking behavior are preserved. Emoji loader removal is available on eligible public pages.

The plugin does not add a page-cache layer, clean the database, modify orders, combine assets, or provide arbitrary delayed script execution. Known optimizer conflicts block overlapping public optimizations. Detection does not establish compatibility with every third-party version.

### Requirements and tested environment

| Component | Requirement / scope | Tested version |
| --- | --- | --- |
| WordPress | 6.5 or later | 6.9 |
| PHP | 8.1 or later; use a currently supported PHP release in production | 8.1.34 |
| WooCommerce | Optional; 9.x is the initial compatibility target | 9.9.5 |
| Image codecs | WordPress image editor support is required for each output format | WebP and AVIF conversion exercised |
| Multisite | Network activation is blocked; network-wide lifecycle support is not implemented | Not validated |

Minimum versions and the full compatibility matrix have not been tested. Composer, Node.js, and external CDNs are not runtime dependencies of the plugin itself. Background downloads and image generation require working WP-Cron or a host-managed cron runner.

### Download and installation

1. Download the [installable v0.2.0 ZIP](https://github.com/tabriznia-dotcom/fa-wp-speedup/releases/download/v0.2.0/fa-wp-speedup-0.2.0.zip) from [GitHub Releases](https://github.com/tabriznia-dotcom/fa-wp-speedup/releases).
2. In WordPress, open **Plugins → Add New → Upload Plugin**, select the ZIP without extracting it, and activate the plugin. Alternatively, extract the complete `fa-wp-speedup` directory into `wp-content/plugins/`.
3. Open **Settings → شتاب وردپرس**. The current administrator interface uses Persian text with translation-ready strings and RTL support.
4. Enable diagnostics first and visit representative pages as an administrator. Record a baseline before enabling an optimization.
5. Approve supported CDN downloads or image generation, inspect the resulting files, then activate each approved resource and its corresponding global switch. For asset rules, test administrator preview before enabling public mode.
6. Purge existing HTML caches with your current cache plugin or hosting tools, then verify public pages in a fresh guest session and complete a staging checkout.

### Safety, privacy, and supported scope

CDN localization currently supports only the exact Bootstrap 5.3.3 jsDelivr CSS and bundle URLs, with either no query string or the supported `?ver=5.3.3` parameter. Unknown scripts, fonts, RTL variants, dynamic URLs, and CSS containing external imports or asset URLs are not automatically localized. Hardcoded HTML resources are reported rather than rewritten.

Public optimizations exclude sensitive requests, including cart, checkout, account, authenticated users, AJAX/REST, and recognized WooCommerce sessions. Additional query-string and resource guards apply. Custom theme galleries, CSS background images, payment integrations, CAPTCHA, and security scripts are not guessed or rewritten. Approved image changes use WordPress image APIs and supported WooCommerce variation data; original zoom images remain available.

There is no telemetry service. Reports remain on the site and do not store request bodies, cookies, SQL text, or customer/order content. Approved CDN downloads contact the selected CDN, which necessarily receives the server's IP address and HTTP request metadata. The image audit sends bounded measurements to the same site only.

### Rollback and cleanup

Disable the relevant global switch to stop an optimization, or revert an individual CDN resource or image to its original URL. Advanced rules provide immediate disable and previous-settings restoration. Generated files are retained when serving is disabled; explicit deletion removes only plugin-owned cached files or derivatives. Original media is preserved. Deactivation unschedules plugin jobs; uninstall cleanup requires the administrator's prior opt-in.

Purge existing page/CDN caches before deleting generated files, retiring image generations, or uninstalling. Previously cached HTML may still reference old files. The image module retains the current and previous successfully activated generations; file deletion cannot be undone by restoring settings.

### Validation and limitations

Recorded checks cover WordPress Coding Standards, PHP syntax, catalog boundaries, WordPress integration, HTTP scenarios, browser-driven COD checkout, image generation, advanced-rule safeguards, and controlled CDN slowdown/offline scenarios. See the [validation report](docs/VALIDATION.md) for exact results and limitations; that report is currently in Persian.

These tests do not establish real-world speed gains on Iranian networks or compatibility with Iranian payment gateways, heavy custom themes, large catalogs, every cache plugin, or all supported WordPress/PHP versions. Direct end-to-end CDN downloads through WordPress's safe HTTP API were blocked by DNS restrictions in the development environment; fixture-backed download logic and actual local frontend delivery were tested separately.

For development, use `./dev/install.sh` to prepare the disposable Docker environment and `./dev/test.sh` to run the local checks. Docker Compose, Python 3, Node.js, and Chromium are required. Tests create sample products and orders and must not run against a live store. Build the installable package with `python3 dev/package.py /tmp/fa-wp-speedup-0.2.0.zip`; the release workflow uses the same packaging allowlist.

Further documentation: [architecture](docs/ARCHITECTURE.md), [images and advanced settings](docs/IMAGES-ADVANCED.md), and [file inventory](docs/FILES.md). These guides are currently in Persian. License: [GPL-2.0-or-later](LICENSE); third-party notices are in `licenses/`.

## فارسی

نسخهٔ آزمایشی **۰٫۲٫۰** برای تشخیص محلی، جایگزینی کنترل‌شدهٔ CDN، مشتق‌های تصویر و قواعد امن منابع. [راهنمای تصاویر و تنظیمات پیشرفته](docs/IMAGES-ADVANCED.md) شامل تولید WebP/AVIF، پیش‌نمایش، محدودیت‌ها و بازگشت است. کش صفحه یا پاک‌سازی دیتابیس ایجاد نمی‌شود. حذف منابع فقط با قواعد دقیق و رضایت مدیر ممکن است. هیچ ادعای بهبود سرعت عمومی یا سازگاری با درگاه ایرانی ندارد.

## نصب و استفادهٔ امن

پوشهٔ `fa-wp-speedup` شامل فایل اصلی و پوشه‌های `includes` و `licenses` را در `wp-content/plugins` قرار دهید و افزونه را فعال کنید. وابستگی Composer، Node یا CDN برای اجرای افزونه لازم نیست. برای تولید از نسخهٔ PHP پشتیبانی‌شدهٔ میزبان استفاده کنید؛ ۸٫۱ فقط حداقل سازگاری کد است. حداقل طراحی: PHP 8.1 و WordPress 6.5؛ ووکامرس اختیاری است. آزمون فعلی: PHP 8.1.34، وردپرس 6.9 و ووکامرس 9.9.5. حداقل نسخه‌ها هنوز در ماتریس CI تأیید نشده‌اند.

۱. در «تنظیمات ← شتاب وردپرس»، تشخیص را روشن و صفحه‌های هدف را با حساب مدیر باز کنید. این حالت منابع HTML و enqueue را مشاهده می‌کند و رفتار آن‌ها را تغییر نمی‌دهد. حداکثر ۲۰۰ ردیف منبع و ۲۰ نمونهٔ درخواست نگهداری می‌شود.
۲. فهرست منابع را بررسی کنید. فقط Bootstrap 5.3.3، فایل CSS و JS bundle با URL دقیق jsDelivr پشتیبانی می‌شود. مجوز MIT، منشأ checksum و URL پیش‌نمایش در صفحه نشان داده می‌شوند. نسخهٔ RTL، فونت‌ها، CSS دارای import/URL خارجی و URL پویا پشتیبانی نمی‌شوند.
۳. برای هر فایل، دکمهٔ تأیید مجوز و زمان‌بندی دریافت را بزنید. این رضایت اجازهٔ تماس با همان CDN را می‌دهد؛ مدیر افزونه هیچ دادهٔ تشخیصی را برای سرویس بیرونی ارسال نمی‌کند. CDN مانند هر دانلود، IP و مشخصات درخواست HTTP را می‌بیند. دریافت در WP-Cron انجام می‌شود، نه در درخواست مشاهدهٔ صفحه. با cron غیرفعال، cron واقعی میزبان یا `wp cron event run faws_download --due-now` لازم است.
۴. پس از موفقیت دریافت و checksum، فعال‌سازی همان منبع را تأیید و سپس کلید عمومی جایگزینی را روشن کنید. صفحهٔ گزارش شامل وضعیت، زمان، hash، رضایت، فایل معتبر و آخرین fallback است. محلی‌بودن URL پیش‌نمایش، به‌تنهایی به معنای فعال‌بودن در همهٔ صفحه‌ها نیست.
۵. کش HTML موجود را از **ابزار همان افزونهٔ کش** پاک کنید، سپس مهمان بدون نشست و صفحه‌های عمومی را آزمایش کنید. افزونه کش‌های دیگر را خودکار پاک نمی‌کند. در فروشگاه عملیاتی حتماً staging و مسیر خرید کامل را بررسی کنید.

## بازگشت

کلید عمومی را خاموش کنید یا برای هر منبع «بازگشت به منبع اصلی» را بزنید. نسخهٔ محلی نگه داشته می‌شود ولی رضایت لغو و cron آن پاک می‌شود. «حذف فایل محلی» همان فایل و مجوز همراهش را هم حذف می‌کند. اگر در حین دریافت رضایت را لغو کنید، worker فایل نتیجهٔ آن دریافت را حذف می‌کند. بازگردانی تنظیمات قبلی فقط آخرین تنظیمات عمومی را برمی‌گرداند؛ حذف فایل قابل بازگشت نیست. کش HTML قبلی باید با ابزار کش سایت پاک شود.

حذف یا غیرفعال‌سازی افزونه، HTML کش‌شده را بازنویسی نمی‌کند. **پیش از حذف فایل‌ها یا افزونه** کش صفحه/CDN سایت را تخلیه کنید تا HTML قدیمی به فایل حذف‌شده اشاره نکند. غیرفعال‌سازی فایل‌ها را نگه می‌دارد. حذف افزونه، داده‌ها را فقط در صورت انتخاب قبلی مدیر پاک می‌کند.

## مرز ایمنی

- همهٔ گزینه‌ها پیش‌فرض خاموش هستند. دانلود و فعال‌سازی دو تصمیم جداگانه‌اند.
- فقط URL ثابت HTTPS یا همان URL با `?ver=5.3.3` پذیرفته می‌شود؛ ورودی URL دلخواه وجود ندارد.
- API امن HTTP وردپرس، TLS، صفر redirect، timeout هشت ثانیه، سقف یک مگابایت، MIME و SRI رسمی SHA-384 بررسی می‌شوند. checksum پیش از هر جایگزینی روی فایل محلی دوباره بررسی می‌شود.
- فایل دارای نام ثابت نسخه‌دار و پسوند فقط CSS/JS است؛ با فایل موقت و rename اتمیک نصب می‌شود. محتوای دانلودشده هرگز include یا اجرا در PHP نمی‌شود. پوشهٔ uploads باید سرویس فایل استاتیک داشته باشد و اجرای PHP در کل uploads در تنظیمات هاست ممنوع باشد. افزونه پیکربندی وب‌سرور را تغییر نمی‌دهد.
- خطا فایل سالم قبلی را حذف نمی‌کند. فایل مفقود/خراب باعث استفاده از URL اصلی و ثبت fallback می‌شود. خطای مرورگر، مسدودبودن URL uploads، SRI سفارشی ناسازگار یا تنظیم نادرست CDN سایت از سمت PHP قابل تشخیص قطعی نیست؛ پایش مرورگر و برگشت دستی لازم است.
- handle، dependency، inline data، localization و strategy دست‌نخورده می‌مانند. تگ‌های HTML هاردکد فقط گزارش می‌شوند. کتابخانهٔ مشابه صف‌شده با نام/مسیر Bootstrap باعث صرف‌نظر از جایگزینی می‌شود؛ تشخیص نسخهٔ دوم با نام کاملاً متفاوت تضمین‌شده نیست و نیازمند بررسی مدیر است.
- پیشخوان، کاربر واردشده، AJAX/REST/cron، همهٔ query stringهای صفحه، سبد، پرداخت، حساب و cookieهای شناخته‌شدهٔ نشست مستثنا هستند. روی منابع پرداخت/کپچا/امنیت/ووکامرس و handleهای حساس هیچ جایگزینی انجام نمی‌شود.
- cron از رضایت ذخیره‌شده استفاده می‌کند؛ درخواست مدیر با capability و nonce محافظت می‌شود. قفل ۱۲۰ ثانیه‌ای و فاصلهٔ حداقل یک ساعت از دریافت هم‌زمان/مکرر جلوگیری می‌کنند.
- نسخه‌ها ثابت‌اند؛ ارتقا به نسخهٔ جدید نیازمند انتشار نگاشت جدید با hash، مجوز و تأیید تازه است. برای فایل ثابت، بازدریافت موفق همان hash را اتمیک جایگزین می‌کند. حذف خودکار نسخه یا فایل ناشناخته انجام نمی‌شود؛ نگهداری تا حذف صریح مدیر است.
- گزارش URLهای ناشناخته را ذخیره نمی‌کند: فقط host، handle، نوع و زمینه ثبت می‌شوند. body، کوکی، query، SQL، اطلاعات مشتری و سفارش در گزارش ذخیره نمی‌شوند. تشخیص محدود به بازدید مدیر است؛ زمان سرور معادل TTFB مرورگر نیست.
- فعال‌سازی شبکه‌ای multisite مسدود است؛ چرخهٔ پاک‌سازی شبکه‌ای پشتیبانی نمی‌شود. از فعال‌سازی سراسری استفاده نکنید.

## توسعه و آزمون

هر کار ابری محیط ایزوله دارد؛ از checkout موجود استفاده کنید و بدون درخواست صریح worktree نسازید.

```sh
./dev/install.sh
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/integration.php
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/woo-fixtures.php > /workspace/.faws-dev/woo-fixtures.json
python3 tests/http-smoke.py
npm ci --cache /workspace/.faws-dev/npm-cache --ignore-scripts --no-audit --no-fund
npm run test:browser
```

Docker Compose، Python 3، Node و Chromium لازم‌اند. `./dev/test.sh` همهٔ بررسی‌های محلی را اجرا می‌کند؛ WPCS از Composer lockfile و TLS معتبر نصب می‌شود. برای راه‌اندازی دوبارهٔ سرویس‌ها از `./dev/start.sh` استفاده کنید. فایل‌های برنامه bind-mount می‌شوند و دیتابیس در volume جداست. پورت فقط روی loopback است و پیش‌نمایش عمومی ارائه نمی‌شود. رمزهای تصادفی فقط در `/workspace/.faws-dev/credentials.env` با مجوز محدود ذخیره می‌شوند؛ آن‌ها را در مخزن یا چت قرار ندهید. تست‌ها فقط برای این سایت دورریختنی هستند و سفارش آزمایشی ایجاد می‌کنند. هیچ‌کدام را روی فروشگاه واقعی اجرا نکنید.

جزئیات دریافت fixture، نتایج و محدودیت‌های محیط در [گزارش اعتبارسنجی](docs/VALIDATION.md) و برنامهٔ نسخه‌ها در [معماری](docs/ARCHITECTURE.md) آمده است.
