# AFVeo Stream API — Render.com

## طريقة النشر على Render

### 1. ارفع هذا المجلد على GitHub
```
git init
git add .
git commit -m "AFVeo API"
git remote add origin https://github.com/USERNAME/afveo-api
git push -u origin main
```

### 2. أنشئ Web Service على Render
- اذهب إلى render.com
- New → Web Service
- اربطه بـ GitHub repo
- Runtime: **Docker**
- Plan: **Free**

### 3. أضف Environment Variables
| Key | Value |
|-----|-------|
| APP_SECRET | (اضغط Generate) |
| TMDB_API_KEY | 60a8d6ad3b8e5fbdbde539526b196d9b |
| BASE_URL | https://YOUR-APP.onrender.com |

### 4. بعد النشر اختبر
```
GET https://YOUR-APP.onrender.com/api/v1/stream.php?action=docs
```

### 5. احصل على توكن
```
GET https://YOUR-APP.onrender.com/api/v1/stream.php?action=token&type=movie&id=550
```

### 6. اطلب المصادر
```
GET https://YOUR-APP.onrender.com/api/v1/stream.php?action=sources&type=movie&id=550&token=TOKEN
```
