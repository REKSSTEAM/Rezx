# Easy Gateway

اجعل التطبيق يتصل بهذا المسار فقط:

```text
/api/easy/index.php
```

## قائمة المصادر

```text
GET /api/easy/index.php?action=providers
```

## مصادر LookMovie

```text
GET /api/easy/index.php?action=sources&provider=lookmovie&type=movie&id=550
GET /api/easy/index.php?action=sources&provider=lookmovie&type=tv&id=1396&season=1&episode=1
```

## مصادر AnimeCurX

```text
GET /api/easy/index.php?action=sources&provider=animecurx&type=movie&id=550
GET /api/easy/index.php?action=sources&provider=animecurx&type=tv&id=1396&season=1&episode=1
```

يرجع Easy قائمة `sources` موحّدة. كل رابط داخلها يمر عبر `/api/easy/proxy.php`، والـ proxy يعيد كتابة playlist وروابط المقاطع والترجمات عند الحاجة.

## حماية المفتاح

غيّر قيمة `EASY_KEY` في `index.php` قبل الرفع. بعد ذلك أرسل المفتاح من التطبيق في header باسم `X-Easy-Key`. لا تضع مفتاحًا إداريًا أو مفتاح TMDB في التطبيق؛ المفتاح الموجود داخل APK يمكن استخراجه، لذلك استخدمه كحاجز إضافي فقط مع HTTPS وRate Limiting وتوقيع قصير الصلاحية من خادمك.

## ملاحظة تشغيلية

يجب أن تكون ملفات المزودين موجودة في المسارات التالية على نفس النطاق:

```text
/api/lookmovie/index.php
/api/lookmovie/proxy.php
/api/animecurx/index.php
/api/animecurx/proxy/
```

Easy لا يكشف هذه المسارات للتطبيق في الرد النهائي، بل يعيد روابطه من `/api/easy/proxy.php`.
