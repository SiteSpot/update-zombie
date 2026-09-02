=== Update Zombie ===
Contributors: (your wordpress.org username)
Tags: updates, security, auto-update, ai, code review
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reads the code in every WordPress update, installs the security fixes on its own, and leaves the rest to your settings. Stop panicking about updates.

== Description ==

WordPress asks you to install updates every day and tells you almost nothing about what is in them. Update Zombie reads them for you — and, if you let it, installs high-impact security fixes within minutes while leaving lower-impact fixes to your normal WordPress settings.

When WordPress offers an update, Update Zombie downloads the package to a temporary directory, diffs it against the copy actually running on your site, and sends the interesting parts to an AI model via OpenRouter. It comes back with two separate judgements: is this a security fix, and is this a good update.

**What it actually does**

* Downloads each pending plugin, theme and core update without installing it.
* Diffs the package against your installed copy, so local modifications show up too.
* Strips out vendor directories, `node_modules`, minified bundles, images, fonts and translation files, then prioritises PHP and JavaScript so the review budget is spent on code that matters.
* Asks the model whether the diff visibly closes a vulnerability — an added capability check, new escaping, a fixed injection — rather than trusting a changelog that says "security fix".
* Flags the things a careful admin wants to know about: new outbound HTTP calls, telemetry, obfuscated code, permission changes, schema changes, removed security checks, breaking changes.
* Reports every verdict on an admin screen, under the relevant row on the Plugins screen, by email, and by webhook.

**Three modes, your choice**

* **Advisory** (default) — reports only. WordPress installs exactly what it would have installed anyway. A wrong verdict can never strand you on a vulnerable version.
* **Guarded** — high- or critical-impact security fixes install automatically; lower-impact fixes follow your normal settings, and updates judged questionable or worth avoiding are held back until you approve them.
* **Autopilot** — high- or critical-impact security fixes and updates judged good install automatically; lower-impact fixes follow your normal settings, and bad ones are held back.

Holding an update back never blocks you from installing it by hand. Guarded and Autopilot only change what happens unattended.

Major WordPress releases are never installed automatically unless you explicitly opt in. Point releases such as 7.1 to 7.1.1 are.

**Which AI it uses**

WordPress 7.0 added the AI Client API, but core registers no AI providers and stores no credentials — it ships the plumbing only. So Update Zombie registers OpenRouter with core's provider registry and asks you for an OpenRouter API key.

Because it registers into the shared core registry rather than keeping a private client, the provider it adds is available to any other plugin using `wp_ai_client_prompt()`, and any provider another plugin registers works alongside it.

The default model is `z-ai/glm-5.3-flash` — a 1.3M token context window at roughly a cent per ten updates — but any OpenRouter model ID works.

For a production site, put the key in `wp-config.php` rather than the database:

`define( 'UPDATE_ZOMBIE_OPENROUTER_KEY', 'sk-or-v1-…' );`

The constant always overrides the settings field, and keeps the key out of the database and out of every database backup.

**What you see**

It lives under Tools → Update Zombie. The Reports tab is an inbox: by default it lists only updates that are not installed, security fixes first, each with the version actually running, a one-line outcome and an Update now link that hands off to WordPress's own updater. Installed updates move to a Done view. A single collapsible line above the list says what the zombie installed on its own in the last week. The Plugins screen shows the verdict under each plugin's row, and the Activity tab is the full audit trail.

**It's AI. Read this.**

Update Zombie uses an AI model to read code and decide whether an update is a security fix. It has a lot of checks and balances, but it is still AI, and AI gets things wrong. It can miss a real fix. It can call a routine change a security fix. It reads a filtered diff, not the whole package, and it is not a malware scanner.

What it is not allowed to do, no matter what the model says:

* Change anything in Advisory mode, the default. It only reports; WordPress installs exactly what it would have anyway.
* Install on hearsay. In Guarded or Autopilot, a security fix only installs itself when the model rated it high or critical impact, was confident above your threshold, and cited a specific file that was actually in the diff.
* Install anything judged Avoid or Questionable, or anything held back. Those wait for you.
* Install a major WordPress release unattended, unless you explicitly switch that on.
* Install anything from the No-AI engine by default.
* Stop you. It never blocks a manual update, and every install it makes goes through WordPress's own updater.
* Do anything quietly. Every verdict, install and failure is in the Activity log, and it emails you.

The "What changed" facts on every report are computed from the diff, not generated, and are exact. Only the verdict is an opinion. Treat it as a good second opinion that will occasionally be wrong.

**Facts, then judgement**

Two different things appear on every report, and they are not equally trustworthy.

The **"What changed"** section is computed directly from the diff: how many lines changed, whether styling or HTML structure was touched, whether JavaScript changed, whether the release adds outbound HTTP calls, REST routes, scheduled tasks or database schema changes, and whether security checks were added or removed. These are facts about files. They are identical every run, cost nothing, and are still there if the AI call fails.

The **verdict** is the model's opinion about those changes. That is the part that can be wrong.

**Running without AI**

If you would rather not send code to a third party — or just do not want to pay for or configure anything — switch the analysis engine to **No AI** in the settings. It corroborates the changelog against those same computed signals: a release only counts as a security fix when the changelog says so *and* the diff actually adds matching capability, nonce, escaping or sanitisation calls. A changelog claiming "security fix" with no matching code change is reported as exactly that discrepancy.

It is deliberately more cautious than the AI engine. It can see that security checks were added; it cannot tell you whether they are correct or complete, or grade their impact reliably. Its confidence is capped at 75%, and a no-AI verdict reports and notifies only.

**A verdict is a second opinion**

Every verdict comes from a language model reading a filtered code diff. It can be wrong, and it only sees the files that survived filtering. The reports say exactly which files were reviewed and which were left out. Treat it as a second pair of eyes, not a guarantee.

== External services ==

Update Zombie does not connect to any service operated by its author.

It makes three kinds of outbound request:

1. **OpenRouter (https://openrouter.ai).** This is the service that performs the analysis; the plugin does not function without it. For each update analysed it sends the filtered code diff, the item's name and version numbers, and the release notes found inside the update package. Requests also carry your site's public home URL and the string "Update Zombie" in the `HTTP-Referer` and `X-Title` headers, which is how OpenRouter attributes usage. Requests are authenticated with an OpenRouter API key that you supply. OpenRouter routes each request to the model you select, so that model's operator processes the diff too.
Terms of service: https://openrouter.ai/terms
Privacy policy: https://openrouter.ai/privacy

Your `wp-config.php`, `.htaccess`, `.env`, `.user.ini` and similar credential-bearing files are excluded from diffing and are never read or sent.

2. **Update packages.** It downloads the same update ZIP files WordPress itself would download, from whichever host the update offer names — usually downloads.wordpress.org, or a commercial plugin's own server for paid plugins. No data about your site is sent; these are plain downloads.

3. **Your webhook, if you enable one.** Turned off by default. When enabled, each verdict is POSTed to the URL you enter, containing your site URL, the item being updated, the verdict and its reasoning. It goes only where you point it.

== Frequently Asked Questions ==

= Does this install updates on its own? =

Only if you choose Guarded or Autopilot mode. The default is Advisory, which changes nothing about your update behaviour.

= Can it stop me installing an update? =

No. Held updates are excluded from unattended installation only. The "Update now" button keeps working, with the verdict shown next to it.

= What does it cost to run? =

One AI request per update, sized by your diff budget. On the default `z-ai/glm-5.3-flash` at roughly $0.075 per million input tokens, a typical plugin update costs a fraction of a cent, and even a very large one lands around nine cents. Analysing a full queue of thirty pending updates is normally well under a dollar.

= Why is there a diff budget at all, if my model has a huge context window? =

Because a large context window is not the same as reading well, and this is measurable rather than theoretical. Analysing one real plugin update with a 1 MB prompt produced a confident summary with zero cited findings and cost $0.086; the same update at 300 KB returned seven cited security fixes, four concerns and three breaking changes for $0.0067. Filtering the diff down to the code that matters beats sending everything.

It is a safety valve rather than a routine limit. Three things it protects against: a WordPress core diff between major versions can exceed any context window; very long requests risk hitting PHP's execution time limit during cron, not just costing money; and a model's ability to spot a subtle missing capability check tends to fall off well before its context window is actually full. Raise it freely for a large-context model — the setting goes to 8,000,000 characters — but the report always tells you which files it actually read.

= Why did an update come back "no reviewable code changes"? =

Every file that survived filtering was identical to what you have installed. The release probably only changed assets, translations or vendor dependencies.

= How long does an analysis take? =

Each update is handled in two stages, one per queue run, five minutes apart. Downloading and diffing a large plugin takes a couple of minutes; the model call takes two to five more. Nothing is done in a hurry and no single request has to survive the whole job, so a host with a modest max_execution_time is fine.

= It needs direct filesystem access? =

Yes. Packages are unpacked into a temporary directory to be read. Sites configured to install updates over FTP or SSH cannot do this, and Update Zombie will say so rather than failing quietly.

= Does it store the whole diff? =

No. It stores the verdict, the reasoning, and the short excerpts the model cited. Reports are pruned after 90 days by default.

= Can I use it without an API key? =

Yes. Set the analysis engine to "No AI" in the settings. You lose the code-reading judgement, but you keep the whole "What changed" breakdown (which is computed, not generated) and you get changelog corroboration: a release counts as a security fix only when the changelog claims one and the diff adds matching security checks. Nothing leaves your site and it costs nothing.

= Why does the no-AI engine refuse to auto-install? =

Because it has not read the fix. It can tell that escaping was added to a file the changelog mentions, which is decent evidence, but not that the vulnerability is actually closed or severe enough to justify overriding your normal update policy.

== Screenshots ==

1. The Reports screen: every pending update with its verdict, confidence, what was done about it, and the computed change chips.
2. A security report: the verdict, cited findings with file paths and excerpts, concerns, and what the model could not read.
3. The Plugins screen: the verdict sits under each plugin row, where you already look.
4. An analysis in progress: the diff facts appear first, then the live status while the model reads.
5. The Activity log: everything the zombie did, including while you were not looking.
6. Settings: engine, provider, mode, and how much to read.

== Changelog ==

= 0.6.0 =
* Reports is now an inbox: the default view lists only updates that are not installed, security fixes first, with installed ones in a separate Done view and a collapsible summary of what the zombie installed on its own in the last week.
* Each row shows the version actually running, a one-line outcome, and an Update now link to WordPress's own updater. Reports lead with the verdict and show the installed state beside it.
* Moved under Tools as a single tabbed page. Old links redirect.
* Any failure requeues the item for the next run, up to three attempts, with the reason shown in the list.

= 0.5.0 =
* Auto-install is gated on impact: only high- or critical-impact security fixes, cited to a file that was actually reviewed, install unattended.
* Verdict shown under each plugin on the Plugins and Updates screens.
* Analyse now runs in the background; the report page shows the phase, an honest time estimate, and refreshes itself.
* WordPress.org icon, banners and screenshots.

= 0.3.0 =
* Computed change signals (styling, markup, JavaScript, outbound HTTP, REST, cron, schema, security checks added or removed, risky functions) shown as chips and broken down per report.
* Activity log of everything the plugin did.
* No-AI engine: changelog corroborated against the diff pattern scan, no key, no cost, nothing off-site.
* Analysis in two phases across cron ticks; per-file security questions for a fast model; strict response schema; automatic retry of provider stalls; WordPress.org polled every fifteen minutes.

= 0.1.0 =
* Downloads each pending plugin, theme and core update, diffs it against the installed copy, and asks a model via OpenRouter whether the code visibly closes a vulnerability.
* Security verdicts are derived from cited findings, never asserted. Advisory, Guarded and Autopilot modes.
* Registers OpenRouter with core's AI Client registry; API key via wp-config constant or settings.
