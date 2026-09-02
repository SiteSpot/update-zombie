# Update Zombie 🧟

![A pixel-art zombie at night, two pirates already down, two more with cutlasses raised](.wordpress-org/banner-1544x500.png)

**All these updates have turned you into a zombie. Get your own zombie instead.**

*By [AB Split Test](https://absplittest.com) — WordPress's best friend.*

<img src=".github/update-zombie-chomp.gif" alt="A pixel-art zombie eyeing the WordPress logo" width="240">

Every morning: seventeen plugin updates. Every morning: the same question. Is this the one that closes the hole, or the one that adds a nag bar and breaks checkout? You click *Update all* with the glassy stare of the undead, because reading seventeen changelogs is not a life.

Update Zombie reads them for you. Not the changelog — the **code**.

## What it actually does

When WordPress offers an update, Update Zombie:

1. **Downloads the package** to a temp directory. Nothing is installed yet.
2. **Diffs it against what's actually running** on your site — so local hacks show up too.
3. **Strips the noise** — `vendor/`, `node_modules`, minified bundles, images, translations, and (for core) your `wp-config.php`, which is never read.
4. **Scans the diff for facts**, no AI involved: lines changed, styling touched, HTML structure modified, new outbound HTTP calls, database schema changes, security checks added or removed, risky PHP functions. Exact, free, same every time.
5. **Sends the interesting parts to a model via OpenRouter**, which answers one question with evidence: *does this code visibly close a vulnerability?* Not "does the changelog say security" — the changelog says security when someone fixed a typo in an `esc_html()` call.
6. **Derives the verdict from the evidence.** The model lists what it found, its impact, and where the fix appears. A citation only counts when it exactly matches a file included in the review.

Then, in **Guarded** mode:

- High- or critical-impact security fix, confident, and cited to a file actually reviewed → **installed automatically** on the next scan and processing cycles. Timing depends on WP-Cron traffic and provider availability.
- Anything else → **left alone**. Your existing auto-update settings apply, exactly as before. Update Zombie never widens what WordPress would have done on its own.
- Judged actively bad → held back from unattended installation. The *Update now* button still works.

**Stop panicking about updates.** Let the zombie eat them.

## What you see

It lives under **Tools → Update Zombie**, three tabs: Reports, Activity, Settings.

**Reports is an inbox, not a ledger.** The default view shows only updates that are *not* installed, sorted with security fixes first, then anything held back, then routine stuff. Each row shows the version actually running (bold, green once it's the new one), a one-line outcome — *Automatically updated*, *Needs manual update*, *Held back*, *Reported only* — and an **Update now** link that hands off to WordPress's own updater. Installed updates move to a **Done** view so they stop nagging you; **Failed** and **All** are one click away.

Above the list, a single collapsible line takes the credit:

> ✓ The zombie installed 6 updates on its own in the last 7 days — 6 of them security fixes. *Show them*

**A report** leads with the verdict — badge, headline, four boxes for *Security fix / Recommendation / Action taken / Release notes back this up* — then the summary, then the computed **What changed** facts, then the findings with file paths and excerpts. Press **Re-analyse** and the work runs in the background while the page shows which phase it's in and refreshes itself; you can leave.

**The Plugins screen** gets the verdict under each plugin's row, so you see it where you already look.

**Activity** is the audit trail: every update spotted, analysis run, verdict, install and failure, including everything that happened while you weren't looking.

The menu badge counts what needs a human — uninstalled security fixes and held-back updates — not the size of the queue.

## ⚠️ It's AI. Read this.

Update Zombie uses an AI model to read code and decide whether an update is a security fix. It has a lot of checks and balances, but **it is still AI, and AI gets things wrong.** It can miss a real fix. It can call a routine change a security fix. It reads a filtered diff, not the whole package, and it is not a malware scanner — a compromised release that also fixes a real bug could get through.

What it is *not allowed* to do, no matter what the model says:

- **Change anything in Advisory mode**, the default. It only reports. WordPress installs exactly what it would have installed anyway.
- **Install on hearsay.** In Guarded or Autopilot, a security fix only installs itself when the model rated it high or critical impact, was confident above your threshold, *and* cited a specific file that was actually in the diff. No file, no install.
- **Install anything it judged "Avoid" or "Questionable"**, or anything held back. Those wait for you.
- **Install a major WordPress release** unattended, unless you explicitly switch that on.
- **Install anything from the No-AI engine** by default.
- **Stop you.** It never blocks a manual update. Every install it does make goes through WordPress's own updater, with WordPress's own checks.
- **Do anything quietly.** Every update spotted, every verdict, every install and every failure is in the Activity log, and it emails you.

The "What changed" facts on every report — lines changed, files touched, security checks added, new outbound calls — are computed from the diff, not generated. They're exact. Only the verdict is an opinion. Treat it as a good second opinion from something that has read more diffs than you have time to, and that will occasionally be wrong.

## Modes

| Mode | Security fixes | Good updates | Bad updates |
|---|---|---|---|
| **Advisory** (default) | reported | reported | reported |
| **Guarded** | **high/critical auto-installed; lower impact follows your settings** | your settings | held |
| **Autopilot** | **high/critical auto-installed; lower impact follows your settings** | **auto-installed** | held |

Advisory changes nothing about your site. It cannot strand you on a vulnerable version by being wrong, because it never decides anything. Start there, read a few reports, then turn on Guarded when you trust it.

## What a report looks like

```
Give 4.16.7.1 → 4.16.7.2                                  Security fix · 90%
Give 4.16.7.2 fixes PHP object injection and stored XSS — apply it, but back up first.

  8,412 lines changed · Security checks added · Risky PHP functions · New files added

  [90%] PHP object injection blocked when reading session data   includes/class-give-session.php
  [90%] Serialized payloads rejected in donation user_info        includes/process-donation.php
  [85%] Stored serialized object payloads scrubbed from meta      src/Donations/Migrations/…
  ...

  MEDIUM  Migration loads entire tables into memory unbatched
  LOW     Quiet database-wide data rewrite not described in release notes
```

Every finding names a file. The chips on the top line are computed from the diff, not generated — they're there even if the AI call fails.

## Setup

1. Install and activate. Needs **WordPress 7.0+** (it uses core's AI Client API) and PHP 7.4+.
2. Get an [OpenRouter API key](https://openrouter.ai/keys).
3. Put it in `wp-config.php`:

   ```php
   define( 'UPDATE_ZOMBIE_OPENROUTER_KEY', 'sk-or-v1-…' );
   ```

   Or paste it into the settings screen. The constant wins if both exist, and keeps the key out of your database backups.
4. Pick a mode. Advisory to watch, Guarded to let it work.

That's it. The default model is `z-ai/glm-5.3-flash`. A typical update costs well under a cent to analyse; a big one, a couple of cents.

## Why WordPress needs a plugin for this

WordPress 7.0 shipped an AI Client API — a provider registry, an HTTP adapter, a prompt builder. It shipped **no providers and no key storage**. So Update Zombie registers OpenRouter into core's registry itself, which means any other plugin using `wp_ai_client_prompt()` can use it too.

## Things it does not do

- **Read the whole internet.** It reads a filtered diff, capped at 300,000 characters by default. The report lists what it couldn't fit. (We measured: sending more makes the analysis *worse*, not better — a 1 MB prompt returned a confident summary with zero cited findings, at thirteen times the cost of the same update at 300 KB.)
- **Install on hearsay.** A "security fix" must be high or critical, meet the confidence threshold, and cite a file actually included in the review before it can auto-install.
- **Install a major WordPress release** unattended, unless you explicitly opt in. Point releases only.
- **Stop you.** Holding an update back only affects unattended installs. Manual updates always work.
- **Guarantee anything.** It's a model reading a diff. It's a very good second opinion. It's still an opinion.

## Without AI

Switch the engine to **No AI** and it still works: changelog corroborated against the pattern scan. Nothing leaves your site, nothing costs anything. It can tell you security checks were added, but it cannot establish impact or prove the fix is correct, so it reports rather than auto-installs.

## For developers

Hooks worth knowing:

```php
// Force advisory mode from an mu-plugin, regardless of settings.
add_filter( 'update_zombie_mode', fn() => 'advisory' );

// Use a stronger model for the findings step only.
add_filter( 'update_zombie_model', fn( $model, $step ) =>
    'findings' === $step ? 'anthropic/claude-sonnet-5' : $model, 10, 2 );

// React to a verdict.
add_action( 'update_zombie_verdict_recorded', fn( $report, $verdict ) => /* … */, 10, 2 );
```

Analysis runs in two phases across separate cron ticks — download and diff on one, model calls on the next — so no single PHP request has to survive the whole job. Any failure (a download timeout, a stalled provider, a malformed reply) requeues the item for the next run; three strikes and it stays failed, with a note saying why. It polls WordPress.org itself every 15 minutes rather than waiting for core's twice-daily check. A webhook (HMAC-signed) and email notifications are available in settings.

For production, run WP-Cron from system cron (`DISABLE_WP_CRON` plus `php wp-cron.php` every five minutes) so the web server's request timeout can't cut a long model call short.

## License

GPL-2.0-or-later. Same as WordPress. Same as your zombie.
