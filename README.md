# OneERP Offline Desktop App

OneERP Offline Desktop App is a Windows-based offline ERP and POS desktop application. It is designed for small and medium businesses that need to manage products, inventory, sales, purchases, customers, suppliers, expenses, reports, and daily business operations without depending on an internet connection.

The application runs as a desktop app using Electron. The local backend/runtime can work with PHP and MySQL/MariaDB using either a bundled runtime or a local XAMPP-compatible setup. The data is stored locally on the user's computer, so the system can continue working even when the internet is not available.

---

## Main Features

- Offline desktop ERP/POS system
- Product management
- Category, brand, and unit management
- Stock and inventory management
- Sales/POS billing
- Purchase management
- Customer management
- Supplier management
- Expense management
- Reports and summaries
- Local database support
- Windows desktop installer build
- Electron-based desktop application
- Local PHP/MySQL runtime support
- XAMPP-compatible development setup

---

## How the Offline Desktop App Works

OneERP Offline Desktop App works locally on a Windows computer.

The desktop application opens through Electron. Electron provides the desktop window and runs the local application interface. The backend services and database can run locally through PHP and MySQL/MariaDB. Because the database is stored on the same machine, users can create invoices, manage products, update stock, and view reports without internet access.

Basic working flow:

1. User opens the desktop app.
2. Electron starts the local desktop window.
3. Local PHP/MySQL runtime or XAMPP handles backend and database operations.
4. Business data is saved locally on the user's computer.
5. App continues to work offline.
6. When needed, a Windows installer can be generated from the project.

---

## Tech Stack

- Electron.js
- Node.js
- JavaScript
- PHP
- MySQL / MariaDB
- HTML
- CSS
- Local runtime / XAMPP-compatible environment

---

## Required Software

Install the following software before running the project:

### 1. Git

Git is required to clone the project and manage updates.

Check Git version:

```bash
git --version
### 2. Node.js LTS

Node.js is required to install project dependencies and run/build the Electron desktop application.

Recommended:

```txt
Node.js LTS version
