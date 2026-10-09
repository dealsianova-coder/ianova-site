# IANOVA — PHP + MySQL e-commerce site

A dynamic version of the IANOVA storefront: categories, products, search, cart,
and checkout are all backed by a MySQL database instead of hard-coded HTML.

## Requirements
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+ (needs `FULLTEXT` index support, InnoDB)
- The `pdo_mysql` PHP extension enabled

## Setup

1. **Create the database and tables**
   ```bash
   mysql -u root -p < schema.sql
   ```
   This creates the `ianova` database, all tables, and seeds it with sample
   categories and products so the site works immediately.

2. **Set your database credentials**
   Open `config.php` and edit:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'ianova');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

3. **Run it**
   - With PHP's built-in server (quick local test):
     ```bash
     php -S localhost:8000
     ```
     then visit `http://localhost:8000`
   - Or drop the whole folder into your XAMPP/WAMP/MAMP `htdocs`/`www`
     directory and visit `http://localhost/ianova-php`

## What's dynamic

| Feature | How it works |
|---|---|
| Categories & nav | Pulled from the `categories` table, shown in header, homepage, and footer |
| Product listings | `products` table, filtered by category on `category.php` |
| Flash deals | Products flagged `is_flash_deal = 1` |
| Search | MySQL `FULLTEXT` search on product name/description, with a `LIKE` fallback |
| Cart | Stored in the PHP session (`$_SESSION['cart']`), joined live against the `products` table so prices/stock are always current |
| Checkout | Inserts a row into `orders` + `order_items` in a transaction, then empties the cart |
| Newsletter | Emails saved to `newsletter_subscribers` (deduplicated via `INSERT IGNORE`) |

## File structure

```
ianova-php/
├── config.php              # DB connection + site constants
├── schema.sql               # database schema + seed data
├── index.php                 # homepage
├── category.php              # category listing + sort
├── product.php                # product detail + related products
├── search.php                  # fulltext product search
├── cart.php                     # view/update/remove cart items
├── cart_action.php               # POST handler: add/update/remove
├── checkout.php                   # guest checkout form → creates order
├── newsletter.php                  # POST handler: subscribe
├── includes/
│   ├── header.php                   # nav, search, dynamic category bar
│   ├── footer.php                    # newsletter block, footer links
│   └── functions.php                  # cart logic, product card renderer
└── assets/
    ├── css/style.css
    └── js/app.js
```

## Extending it

- **Admin panel**: add an `admin/` area with forms that `INSERT`/`UPDATE`
  against `products` and `categories` — the schema already supports it.
- **Real accounts**: the `users` table has a `password_hash` column ready for
  `password_hash()` / `password_verify()`.
- **Product images**: swap the `emoji` + `bg_color` columns for an `image_url`
  column once you have real product photography.
- **Payments**: `orders.status` is an enum (`pending`, `paid`, `shipped`,
  `delivered`, `cancelled`) — wire it up to a payment gateway webhook.

All user input is handled through prepared statements (PDO) and output is
escaped via `htmlspecialchars()`, so it's safe to build on directly.
