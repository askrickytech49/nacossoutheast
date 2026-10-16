# Hackathon registration and administration

The Hackathon form saves submissions in MySQL and then attempts to email the team and applicant through Brevo. Paid Convention, Sponsorship, and Mr & Miss NACOS forms are stored only after server-side Paystack verification, then send a receipt and an admin notification through Brevo. The admin portal at `/admin/` lets staff review Hackathon registrations and verified paid forms.

## Local XAMPP setup

1. Copy this project folder into `C:\xampp\htdocs\nacossoutheast-main` and start Apache and MySQL in the XAMPP Control Panel.
2. From a terminal in the project folder, run `C:\xampp\php\php.exe api\setup-xampp.php`. It creates the `nacos_portal` database and schema, creates a dedicated local database user, and writes private credentials to `api/config.local.php`. It refuses to overwrite an existing local config. If you set a MySQL root password, set `XAMPP_MYSQL_ROOT_PASSWORD` before running it.
3. Create the first admin account:

   ```powershell
   C:\xampp\php\php.exe admin\create-admin.php
   ```

   The command prints a randomly generated password once. Save it securely. Pass an email argument to use a different admin email. Running it again resets that account's password.
4. Visit `http://localhost:8080/nacossoutheast-main/admin/` and sign in.

To add the paid forms table to an existing database, run `C:\xampp\php\php.exe api\migrate-paid.php` from the project folder. To change an admin password from a terminal, set `NACOS_ADMIN_NEW_PASSWORD` in the environment and run `C:\xampp\php\php.exe admin\set-password.php [admin-email]`.

The default XAMPP database account is commonly `root` with an empty password. Use the credentials configured on your installation. For a shared or public server, create a separate database user with a strong password instead of using root.

## Paystack paid-form setup

Configure both Paystack keys in the private `paystack` section of `api/config.local.php`, or use the `PAYSTACK_PUBLIC_KEY` and `PAYSTACK_SECRET_KEY` environment variables. Change the keys in this one place to switch all paid forms between test and live mode. The readiness endpoint returns only the public key to the browser and enables checkout only when the public and secret keys belong to the same mode. Never put the secret key in JavaScript. After checkout, the server verifies the reference with Paystack, checks the exact expected amount and payer email, and only then saves the form as paid. The admin Paid forms view includes convention, sponsorship, and pageant submissions and supports details, admin notes, photo viewing, search, filters, and CSV export.

Paid transactions from before this feature was installed are not in the project database. The forms previously handled confirmation in the browser/email only. Historical entries need to be reconciled from Paystack transaction records and the submitted emails; the migration does not invent or import them.

## Brevo email setup

Add a valid Brevo transactional API key and a registered, verified sender address to the `brevo` section of `api/config.local.php`, or configure the matching environment variables listed below. Set `admin_email` to the inbox that should receive registration notifications; this may be different from the Brevo sender/account email. The API key must only be stored server-side. Without valid Brevo settings, submissions still save, but email delivery will report a failure in the response.

## cPanel setup

1. Create a MySQL database and database user in cPanel and grant the user access.
2. Import `schema.sql` in phpMyAdmin.
3. Upload the project, set PHP 8.0 or newer, and enable PDO MySQL and cURL.
4. Configure these environment values in the hosting panel, or create a private `api/config.local.php` outside public download access:

   - `NACOS_DB_HOST`, `NACOS_DB_NAME`, `NACOS_DB_USER`, `NACOS_DB_PASSWORD`
   - `BREVO_API_KEY`, `BREVO_SENDER_EMAIL`, `BREVO_SENDER_NAME`
   - `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY` (use matching test or live keys)
   - `PAYSTACK_SECRET_KEY` (required to verify and save paid form submissions)
   - `HACKATHON_ADMIN_EMAIL` (defaults to `nacosoutheast@gmail.com`)

5. Run `php admin/create-admin.php [admin-email]` over the hosting terminal/SSH and save the generated password. Serve the site over HTTPS.

The payment verification endpoint relies on the Paystack Transaction Verify API and a server-side secret key. See [Paystack's transaction verification documentation](https://paystack.com/docs/api/transaction/).
