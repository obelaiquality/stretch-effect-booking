# What going live costs

Prices were checked on 8 November 2026 on afrihost.com. The pages do not say if VAT is included.
Confirm the prices on afrihost.com before you buy. If the prices exclude VAT, add 15%.

## Must buy (the owner pays Afrihost directly)

| Item | Afrihost product | Price | Notes |
| --- | --- | --- | --- |
| Web hosting | Linux shared hosting, **Platinum** | R109 per month, month to month, no setup fee | 4 GB disk, 5 MySQL databases, 200 mailboxes, free Let's Encrypt SSL (AutoSSL), cron jobs, PHP |
| Domain | `.co.za`, for example `thestretcheffect.co.za` | Free in year 1 with shared hosting, then R109 per year | Registration alone is R197. A `.com` is R309. |

| Total | Amount |
| --- | --- |
| Year 1 | about **R1,308** (12 × R109, domain free) |
| Each year after | about **R1,417** (R1,308 hosting + R109 domain renewal) |

Bronze Pro (R135 per month) is the next step up if more disk or databases are needed.

## Free

- Google Calendar API: free at this scale (600 requests per minute per user). Google says that
  charges are planned later in 2026, with 90 days' notice. One studio uses a very small amount.
- SSL certificate: free Let's Encrypt through AutoSSL in the Afrihost control panel.
- The booking system code: built for the owner, no licence fee.

## Optional

| Item | Why | Cost |
| --- | --- | --- |
| ChatGPT plan with custom connectors | To run the studio from ChatGPT. OpenAI documents full connector support (including write actions) for **Business** and Enterprise plans. Reports for **Plus** and **Pro** conflict: some say read-only. Test on the owner's own account first. | The owner's existing ChatGPT subscription. Check openai.com/chatgpt/pricing for the current Business price. |
| Transactional email (Brevo, Resend or Mailgun free tier) | Afrihost does not include SMTP sending with shared hosting by default. Booking emails are more reliable through a provider. | Free tier covers a single studio |
| Online card payments (Yoco or PayFast) | Clients pay for packs online and sessions load automatically. Not built in the demo. | Per-transaction fees only |

## Fallback if shared hosting fails the tests

The shared hosting must pass four checks in the first week (see `AFRIHOST-SETUP.md`):
the `Authorization` header reaches PHP, ModSecurity accepts JSON POSTs, outbound HTTPS calls
to Google work, and cron runs. If a check fails, Afrihost support can often fix it. If they
cannot, the fallback is an Afrihost **Bronze cloud server** at R460 per month
(about R5,629 per year with the domain). That server is self-managed.

## Compared with off-the-shelf booking software

Third-party price figures, converted at about R18 per US dollar, per month:

| Product | Monthly cost | Session packs / credits |
| --- | --- | --- |
| Mindbody | about R1,400 to R12,600 | Yes |
| Glofox | about R1,500 to R4,500 | Yes |
| Acuity Scheduling | about R360 to R1,100 | Packages from the Standard plan |
| Calendly | free to about R290 | No credit ledger |
| Setmore | free to about R220 per user | Limited |
| Fresha | free software, about 20% fee on marketplace clients | Yes |

The custom system costs about R118 per month to run (hosting plus domain), with no
per-booking fees, and it is the owner's own system.
