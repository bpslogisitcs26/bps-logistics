BPS LOGISTICS ONLINE PHP + MYSQL PACKAGE
========================================

এই প্যাকেজটি InfinityFree-র মতো PHP + MySQL hosting-এ আপলোড করার জন্য।

ফাইল:
- config.php    : MySQL connection details বসাতে হবে
- install.php   : ADMIN এবং BABU Login account তৈরি করবে (শুধু একবার)
- login.php     : Login page
- index.php     : BPS LOGISTICS management system
- logout.php    : Logout
- .htaccess     : Directory listing বন্ধ ও config file protection

UPLOAD:
1) InfinityFree Control Panel > File Manager > htdocs খুলুন।
2) এই ZIP extract করে সব ফাইল সরাসরি htdocs-এর ভিতরে রাখুন।
3) config.php খুলে YOUR_MYSQL_HOSTNAME, YOUR_DATABASE_NAME,
   YOUR_DATABASE_USERNAME, YOUR_DATABASE_PASSWORD-এর জায়গায় Control Panel-এ
   দেখানো সঠিক Database তথ্য বসান। Database host সাধারণত localhost নয়।
4) Browser-এ https://YOUR-SUBDOMAIN/install.php খুলুন।
5) Setup success হলে htdocs থেকে install.php DELETE করুন।
6) https://YOUR-SUBDOMAIN/login.php খুলুন।

LOGIN:
ADMIN / admin@1234  (সবসময় Edit/Delete)
BABU  / BABU@1234   (Entry তৈরির পর ৭ দিন Edit/Delete)

SECURITY:
- install.php সফল হওয়ার সঙ্গে সঙ্গে delete করতে হবে।
- প্রথম কাজ হিসেবে Login details গোপন রাখুন। এই demo build-এ Password Change page নেই;
  বাস্তবে ব্যবহার শুরুর আগে Password পরিবর্তনের ব্যবস্থা যোগ করা উচিত।
- এই PHP app server-side login/session ব্যবহার করে। তবু production use-এর আগে
  SSL active আছে কি না নিশ্চিত করুন এবং নিয়মিত database backup রাখুন।
- ADMIN/BABU-এর password hash app_users table-এ install.php তৈরি করে।

FEATURES IN THIS FIRST ONLINE BUILD:
- Login / Logout and roles
- Customer Details, 3-character name suggestion in New Booking
- Booking By Courier Company separate from Actual Vendor
- Booking, customer due ledger, receipts (no docket field)
- Vendor work and actual vendor ledger, vendor payments (no docket field)
- Rate Master, PIN lookup, basic auto-rate lookup
- Expenses, four cash/bank accounts, cash transactions
- Shipment tracking; Delivered blocks later tracking updates
- Pickups, date-range search, CSV export, browser PDF print
- Customer/Vendor full ledger and print-all pages

IMPORTANT LIMITATIONS:
- This is an initial functional online build, not a complete audited ERP.
- Old Demo Local Storage records are NOT automatically transferred to MySQL.
  Need to export/prepare/import them separately if required.
- Customer/Vendor/Cash linked transactions use ledger tables. Editing complex linked
  transactions after posting should be tested carefully; do backup before use.
- Customer PIN lookup uses the India Post public API and needs internet.
- Excel export is CSV (opens in Excel); browser Print can Save as PDF.
- Database schema must match the SQL script already executed in phpMyAdmin.
