# اعتبارسنجی مرحلهٔ دوم — نسخهٔ ۰٫۲

کد اجرایی با WordPress Coding Standards 3.4.1 و PHP_CodeSniffer 3.13.6 بدون خطا و هشدار بررسی شد. syntax همهٔ فایل‌های PHP و اسکریپت محلی نیز بررسی شد. مجموعهٔ پیشین شامل ۲۰ آزمون مرزی، ۲۰ آزمون یکپارچه، ۱۵ سناریوی HTTP، خرید مرورگری COD و آزمون CDN پس از افزودن ماژول‌ها گذشت.

آزمون‌های جدید:

- **۴۵ بررسی یکپارچهٔ تصویر/منابع**: تولید واقعی WebP و AVIF با Image Editor، حفظ بایت‌های اصل و metadata، حفظ alpha PNG، رد APNG، batch دو فایلی، تأیید جداگانه، نگهداری دو نسل و idempotency، تشخیص فایل مخدوش، تأیید مجدد hash پیش از فعال‌سازی، لغو قبل/حین encoding، پاک‌سازی attachmentهای inherit و قلاب حذف، تداخل بهینه‌ساز، حفاظت گراف مستقیم/غیرمستقیم و چرخه، inline/localization، منابع حساس و حالت عمومی بدون baseline.
- **۲۸ assertion مرورگر جدید**: فرم جدول قواعد، خاموش/پیش‌نمایش/عمومی، رد پیش‌نمایش جعلی مهمان، no-cache، unload و strategy بومی defer، حفظ منو/وابستگی/localization، پیشخوان بدون ابزارک اخبار، Heartbeat پیشخوان ۶۰ ثانیه و ویرایشگر بدون تغییر، تصویر اصلی و variation قابل decode با WebP و srcset، استثنای نشست سبد، nonce نامعتبر، محدودیت endpoint و گزارش ابعاد بدون URL، بدون خطای uncaught JavaScript.
- **سنجش انتقال تصویر مصنوعی**: در viewport ثابت و context مهمان تازه، منبع انتخاب‌شدهٔ همان تصویر از `192519` بایت به `18616` بایت رسید. این `encodedBodySize` مرورگر برای fixture تولیدشده در تست است؛ TTFB، کیفیت دیداری عکس واقعی یا بهبود کل سایت/شبکهٔ ایران از آن نتیجه‌گیری نمی‌شود.

کدک‌های WebP و AVIF در محیط فعلی عملاً پشتیبانی شدند. AVIF در محصول فقط خروجی پیش‌نمایش است. WordPress 6.9، WooCommerce 9.9.5، PHP 8.1.34 و قالب پیش‌فرض همچنان محدودهٔ اجرای تست‌اند. اجرای ذخیره‌شده و جزئیات اندازه‌گیری در `/workspace/.faws-dev/media-advanced-results.json` هستند. `./dev/test.sh` آزمون‌های جدید را نیز اجرا می‌کند و در پایان حالت قواعد و کلیدهای تصویر را خاموش می‌کند. تست‌ها روی فروشگاه واقعی مجاز نیستند.

درگاه ایرانی، قالب/گالری اختصاصی، مرورگر قدیمی، تمام نسخه‌های افزونه‌های کش و تصویر، کاتالوگ بزرگ و ماتریس cache/هویت/شبکهٔ ایران هنوز تأیید نشده‌اند. محدودیت DNS دانلود مستقیم CDN از مرحلهٔ قبل همچنان برقرار است. راهنمای قابلیت‌ها و بازگشت در [IMAGES-ADVANCED.md](IMAGES-ADVANCED.md) است.

---

# گزارش اعتبارسنجی نسخهٔ ۰٫۱

## محیط

PHP 8.1.34، WordPress 6.9، WooCommerce 9.9.5، MariaDB 11.4 و قالب پیش‌فرض block وردپرس در Docker محلی. HPOS با API رسمی ووکامرس فعال و وجود سفارش‌های processing با روش COD و اقلام محصول تأیید شد. هیچ درگاه بانکی یا سایت مشتری در اختیار این آزمایش نبود.

## نتیجه‌های ثبت‌شده

| بررسی | نتیجه و محدوده |
| --- | --- |
| PHP syntax | همهٔ فایل‌های PHP افزونه و تست‌ها بدون خطای syntax |
| مرزهای امنیتی کاتالوگ | ۲۰ بررسی: URL دقیق و ver، رد query دلخواه و HTTP/private target، SRI رسمی واقعی CSS/JS، MIME، HTML، tamper و سقف اندازه |
| یکپارچهٔ وردپرس | ۲۰ بررسی: API واقعی وردپرس، نصب فایل، رضایت جداگانه از فعال‌سازی، source filter، تگ واقعی با defer/inline/localization، استثنای نشست/query/کاربر/handle، کتابخانهٔ تکراری، فایل خراب/مفقود، حفظ فایل قبلی در timeout، cooldown، جدابودن وضعیت worker از رضایت و لغو رضایت حین دانلود؛ transport دانلود در این suite fixture است |
| HTTP | ۱۵ سناریو: خانه، فروشگاه، ساده/متغیر، جست‌وجو، حساب، AJAX سبد، variation، پرداخت کلاسیک و تأیید سفارش COD، منع مهمان، تنظیمات مدیر، CSRF نامعتبر، رضایت زمان‌بندی، صفحهٔ HPOS و ویرایش محصول |
| Chromium | انتخاب variation، سبد، checkout AJAX و تأیید سفارش با افزونهٔ خاموش و فعال؛ بدون خطای uncaught JavaScript و با مسدودسازی درخواست بیرونی در مرورگر |
| جایگزینی واقعی فرانت‌اند | CSS و JS محلی از uploads با HTTP واقعی سرو شدند؛ Bootstrap Modal و رنگ `.btn-primary` در مرورگر بررسی شدند؛ URLهای enqueue به cache محلی تغییر کردند و درخواست به CDN صفر شد |

برای تست CDN، بایت‌های واقعی دو فایل با HTTPS از jsDelivr دریافت و با SRI رسمی مطابقت داده شدند. به‌دلیل محدودیت DNS محیط، نصب آن‌ها از مسیر `pre_http_request` با fixture همان بایت‌های تأییدشده انجام شد؛ بنابراین **دریافت end-to-end با `wp_safe_remote_get` از همین محیط هنوز تأیید نشده است**. تنظیمات TLS/SSRF کد اصلی خاموش یا ضعیف نشدند.

## سنجش مصنوعی CDN

در homepage آزمایشی دو منبع enqueue شدند. Playwright در حالت baseline پاسخ آن دو URL را با تأخیر ۱۵۰۰ میلی‌ثانیه و محتوای معتبر ارائه کرد. سپس همان صفحه با جایگزینی محلی و CDN مسدود اجرا شد. در اجرای ثبت‌شده:

| حالت | درخواست CDN | load مرورگر |
| --- | ---: | ---: |
| URL اصلی با تأخیر کنترل‌شده | ۲ | حدود ۱۸۲۷ ms |
| URL محلی با CDN قطع | ۰ | حدود ۴۹۳ ms |

این فقط یک نمونه از fixture کنترل‌شده است؛ نه میانگین آماری، نه اندازه‌گیری شبکهٔ ایران و نه ادعای بهبود فروشگاه واقعی. صحت JS و CSS در هر دو حالت جداگانه بررسی شد. فایل‌های JSON شامل timing و نتیجه در `/workspace/.faws-dev/` نگهداری می‌شوند. مقادیر اجرای مجدد می‌توانند متفاوت باشند.

## محدودیت شبکه و آزمون‌های باز

`gethostbyname('cdn.jsdelivr.net')` در محیط به IP تبدیل نشد و `wp_http_validate_url` پاسخ false داد. دانلود با API امن وردپرس به‌درستی fail closed شد. curl محیط از مسیر proxy با TLS معتبر، همان فایل CDN را دریافت کرد. دامنه‌های `api.wordpress.org` و `downloads.wordpress.org` پاسخ CONNECT 403 داشتند؛ نیازشان در پیش‌نویس شبکه ثبت شد. ووکامرس از بستهٔ رسمی انتشار GitHub 9.9.5 نصب شد. تغییر پیش‌نویس، اثبات اعمال runtime یا انتشار نیست.

درگاه ایرانی sandbox، SSL مرورگر فروشگاه واقعی، قالب سنگین/صفحه‌ساز، افزونه‌های فیلتر و جست‌وجو، کاتالوگ بزرگ، RTL بصری، افزونه‌های کش/امنیتی منتخب، حداقل نسخه‌های هدف، multisite، throughput اینترنت ایران، cold/warm page cache و خرید کاربر واردشده هنوز تأیید نشده‌اند. WordPress Coding Standards 3.4.1 با PHP_CodeSniffer 3.13.6 روی کد اجرایی بدون خطا و هشدار گذشت؛ استثناهای محدود و توضیح‌دار فقط برای SRI base64، نوشتن اتمیک محلی و aggregate تشخیصی وجود دارند. هشدارهای بروزرسانی WordPress.org و هشدار زودهنگام ترجمهٔ WooCommerce در محیط نیز دیده شدند؛ با تست موفق افزونه یکی محسوب نشده‌اند.

## اجرای مجدد

```sh
./dev/install.sh
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/woo-fixtures.php > /workspace/.faws-dev/woo-fixtures.json
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/integration.php
python3 tests/http-smoke.py
npm run test:browser
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/cdn-fixture.php setup
node tests/cdn-browser.mjs origin
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/cdn-fixture.php local
node tests/cdn-browser.mjs local
./dev/wp.sh eval-file wp-content/plugins/fa-wp-speedup/tests/cdn-fixture.php disable
```

برای تست مرزی، Docker PHP را با checkout در `/app` و `/workspace/.faws-dev/artifacts` در `/fixtures` اجرا کنید: `php /app/tests/catalog.php`. تست‌ها فقط سایت دورریختنی را می‌پذیرند و سفارش آزمایشی می‌سازند. test fixture در بستهٔ قابل نصب قرار نمی‌گیرد. روی staging واقعی، ماتریس مسیرها و حالت‌های شبکه/کش/هویت درخواست کاربر را پیش و پس از هر تغییر جداگانه ثبت کنید؛ از بروز اختلال عمدی برای کاربران واقعی خودداری کنید.
