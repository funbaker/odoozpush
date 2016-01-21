# odoozpush
Odoo Backend for z-push http://www.zpush.org/

# Features
* Calendar Sync
* Partner Contacts Sync (Persons only)
* Task Sync

# Installation
Change into the z-push backend directory<br>
`cd /path/to/z-push/backend/`

Clone this project<br>
```
git clone https://github.com/funbaker/odoozpush.git odoo
cd odoo
git submodule init ripcord
git submodule update ripcord
```

Configure z-push
```php
# /path/to/z-push/config.php
define('BACKEND_PROVIDER', 'Odoo');
define('USE_FULLEMAIL_FOR_LOGIN', true);
```
Define odoo url and database
```php
# /path/to/z-push/backend/odoo/config.php
define('ODOO_SERVER', 'http://localhost:8069');
define('ODOO_DB', 'DEMO');
```
