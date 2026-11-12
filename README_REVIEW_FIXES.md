# Coffee Shop PHP — Review Fixes

## Applied changes
- Added missing `require_login()` and `require_customer()` helpers (plus `require_cashier()`).
- Strengthened role checks so they require a valid logged-in session.
- Added reusable CSRF token helpers and applied them to product create/edit/delete, user creation, and logout.
- Changed logout from GET to POST.
- Escaped login/register error output.
- Added basic username/password validation during registration.
- Improved product price/name validation.
- Added CSV formula-injection mitigation for exported text fields.
- Added safer JSON encoding flags for chart data.

## Important limitations
The uploaded project did not include `cart.php` or `cashier_orders.php`, and no database schema/dump was supplied. Therefore:
- Cart/order creation and cashier claim/update workflows have not been audited or modified.
- Table constraints, foreign keys, column types, and order snapshot behavior cannot be verified.
- You must add CSRF fields and `verify_csrf()` to any state-changing POST forms in files not included here.

## Before using
1. Back up your original project and database.
2. Copy these files into a test environment first.
3. Run the app with PHP error reporting enabled in development.
4. Test login, registration, product CRUD, admin user creation, order details, and logout.
5. Use a non-root database account and environment variables for production.
6. Serve production over HTTPS and set secure session cookie settings.
