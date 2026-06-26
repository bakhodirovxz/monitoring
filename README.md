# HikCentral Monitor — PHP 8.4 + MySQL

HikCentral Professional kamera monitoringi tizimi.

## Imkoniyatlar
- Real-time kamera holati monitoringi (online/offline)
- Telegram bot orqali bildirishnoma
- Hisobotlar (filial/NVR bo'yicha filter, CSV/Excel export)
- Kamera profili (snapshot, kunlik offline grafigi)
- Rollar: superadmin, branch_admin, sub-admin (NVR darajasida)
- NVR va kamera nomlarini saytdan o'zgartirish

## O'rnatish

```bash
# 1. MySQL baza yaratish
mysql -u root -p -e "CREATE DATABASE hcp_monitor CHARACTER SET utf8mb4"
mysql -u root -p hcp_monitor < schema.sql

# 2. Konfiguratsiya
cp config.example.php config.php
# config.php ni to'g'rilang (DB parol, HikCentral, Telegram)

# 3. Filiallar va NVR biriktirish
php seed.php

# 4. Poller ni ishga tushirish (CLI yoki cron)
php poller.php
# cron: * * * * * /usr/bin/php /path/to/poller.php

# 5. Web server
php -S 0.0.0.0:8000 index.php
# yoki Apache + .htaccess
```

## Fayllar
| Fayl | Tavsif |
|---|---|
| `index.php` | Front controller — barcha routelar va API |
| `config.php` | MySQL + HikCentral + Telegram konfiguratsiya |
| `db.php` | PDO singleton (MySQL) |
| `auth.php` | JWT (HS256) + bcrypt |
| `helpers.php` | Vaqt zona, date filter, template render |
| `poller.php` | HikCentral poller (CLI/cron) |
| `seed.php` | Filiallar + NVR biriktirish |
| `schema.sql` | MySQL jadval sxemasi |
| `.htaccess` | URL rewriting |

## Login
Default: `admin` / `admin123` (seed.php ishga tushirilgandan keyin)
