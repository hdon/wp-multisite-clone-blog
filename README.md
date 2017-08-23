Clone WordPress Blogs on WordPress Multi-Site
=============================================

Had a need for such a tool at work.

It would do no good to keep it to ourselves.

Limitations/Features:

* Uses `mysqli`
* Expects a `db-settings.php` file to `define()` some constants:
  * DB_HOST
  * DB_USER
  * DB_PASS
* The root of your WordPress Multi-Site installation is hard-coded in `clone-wp.php`
* Recursively hardlinks all uploads rather than copying them
* Recursively copies your theme files

Invocation: `wp-clone.php <source_domain> <destination_domain>`

Example `db-settings.php` file (remember to `touch db-settings.php && chmod 600 db-settings.php` before you write to the file):

```php
<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '********');
```
