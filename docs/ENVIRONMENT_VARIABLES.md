# Environment Variables Reference

This document provides a comprehensive specification of all environment variables recognized by the **Student Study Planner** for production deployment (Render, Docker, Kubernetes) and local development (XAMPP).

---

## 1. Database Configuration (MySQL / Aiven Cloud)

| Variable | Type | Default | Description |
|---|---|---|---|
| `DB_HOST` | string | `localhost` | Hostname or IP address of the MySQL server (e.g. `mysql-xxxx.aivencloud.com`). |
| `DB_PORT` | integer | `3306` | Port number of the MySQL server (e.g. `23456` on Aiven). |
| `DB_NAME` | string | `study-planner` | Database name. In shared MySQL environments, ensure database exists. |
| `DB_USER` | string | `root` | Database username (e.g. `avnadmin` on Aiven). |
| `DB_PASS` | string | `""` | Database password. |
| `DB_SSL_CA` | string | `""` | Optional filesystem path to the SSL CA certificate (e.g. `/etc/ssl/certs/ca.pem`). |
| `DB_SSL_CA_CONTENT` | string | `""` | Optional raw PEM certificate string. Useful for PaaS platforms (like Render) that do not allow mounting custom CA files. |

---

## 2. Application & Server Configuration

| Variable | Type | Default | Description |
|---|---|---|---|
| `APP_URL` | string | `http://localhost/...` | Fully qualified base URL of the application (e.g. `https://study-planner.onrender.com`). Used for email links and CORS. |
| `APP_DEBUG` | boolean | `false` | Set to `true` to enable verbose error responses and testing helpers. Must be `false` in production. |
| `PORT` | integer | `80` | Assigned automatically by Render. The Apache entrypoint script binds Apache to this port dynamically. |

---

## 3. Transactional Email & Password Reset (Resend)

| Variable | Type | Default | Description |
|---|---|---|---|
| `EMAIL_ENABLED` | boolean | `true` | Set to `true` to enable transactional email delivery via the Resend API. |
| `RESEND_API_KEY` | string | `""` | API key from Resend (`re_...`). |
| `MAIL_FROM_EMAIL` | string | `onboarding@resend.dev` | Sender email address. In production, use your custom domain (e.g. `noreply@yourdomain.com`). |
| `MAIL_FROM_NAME` | string | `Study Planner` | Friendly sender name displayed in email clients. |
| `EMAIL_TEST_MODE` | boolean | `false` | When `true`, emails are routed to `delivered@resend.dev` for safe testing. |
| `REMINDER_LEAD_HOURS` | integer | `24` | Number of hours before a deadline or task due time to generate automated reminders. |

> [!NOTE]
> **Resend Free Tier Rule**: If using the unverified default `onboarding@resend.dev` sender, Resend only delivers emails to the account owner's email address. For arbitrary user addresses, Resend returns HTTP 403. The application automatically handles this by safely preserving the reset token in MySQL and logging the link to `error_log` for administrative or test retrieval.

---

## 4. Web Push Notifications (VAPID)

| Variable | Type | Default | Description |
|---|---|---|---|
| `VAPID_PUBLIC_KEY` | string | `""` | VAPID base64url-encoded public key for browser push subscription. |
| `VAPID_PRIVATE_KEY` | string | `""` | VAPID base64url-encoded private key for signing Web Push requests. |
| `VAPID_SUBJECT` | string | `mailto:admin@...` | Contact URI for the push service provider (RFC 8292). |

---

## 5. Study AI Assistant

| Variable | Type | Default | Description |
|---|---|---|---|
| `AI_PROVIDER` | string | `gemini` | AI backend provider: `gemini` (Google Gemini) or `openai` (OpenAI GPT). |
| `AI_API_KEY` | string | `""` | API key for the selected AI provider. |
| `GEMINI_API_KEY` | string | `""` | Fallback key if using Google Gemini. |
| `OPENAI_API_KEY` | string | `""` | Fallback key if using OpenAI. |
| `AI_MODEL` | string | `""` | Optional model override (e.g. `gemini-1.5-flash` or `gpt-4o-mini`). |

---

## 6. Configuring Environment Variables in Render

1. Open your Web Service in the **Render Dashboard**.
2. Navigate to the **Environment** tab.
3. Click **Add Environment Variable** for each required key.
4. For database connections using Aiven:
   - Copy `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` directly from the Aiven Service Overview.
   - If SSL verification is required, paste the CA certificate contents into `DB_SSL_CA_CONTENT`.
5. Deploy or trigger a manual deploy for changes to take effect.
