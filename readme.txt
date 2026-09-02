=== Update Zombie ===
Contributors: (your wordpress.org username)
Tags: updates, security, auto-update, ai, code review
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Don't let all these updates turn you into a zombie. Update Zombie reads each update's code diff and tells you whether it is a security fix, a good update, or a shit one.

== Description ==

WordPress asks you to install updates every day and tells you almost nothing about what is in them. Update Zombie reads them for you — and, if you let it, installs the security fixes within minutes while leaving everything else exactly as your settings would have.

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
* **Guarded** — security fixes install automatically; updates judged questionable or shit are held back from unattended installation until you approve them.
* **Autopilot** — security fixes and updates judged good install automatically; bad ones are held back.

Holding an update back never blocks you from installing it by hand. Guarded and Autopilot only change what happens unattended.

Major WordPress releases are never installed automatically unless you explicitly opt in. Point releases such as 7.1 to 7.1.1 are.

**Which AI it uses**

WordPress 7.0 added the AI Client API, but core registers no AI providers and stores no credentials — it ships the plumbing only. So Update Zombie registers OpenRouter with core's provider registry and asks you for an OpenRouter API key.

Because it registers into the shared core registry rather than keeping a private client, the provider it adds is available to any other plugin using `wp_ai_client_prompt()`, and any provider another plugin registers works alongside it.

The default model is `z-ai/glm-5.3-flash` — a 1.3M token context window at roughly a cent per ten updates — but any OpenRouter model ID works.

For a production site, put the key in `wp-config.php` rather than the database:

`define( 'UPDATE_ZOMBIE_OPENROUTER_KEY', 'sk-or-v1-…' );`

The constant always overrides the settings field, and keeps the key out of the database and out of every database backup.

**Facts, then judgement**

Two different things appear on every report, and they are not equally trustworthy.

The **"What changed"** section is computed directly from the diff: how many lines changed, whether styling or HTML structure was touched, whether JavaScript changed, whether the release adds outbound HTTP calls, REST routes, scheduled tasks or database schema changes, and whether security checks were added or removed. These are facts about files. They are identical every run, cost nothing, and are still there if the AI call fails.

The **verdict** is the model's opinion about those changes. That is the part that can be wrong.

**Running without AI**

If you would rather not send code to a third party — or just do not want to pay for or configure anything — switch the analysis engine to **No AI** in the settings. It corroborates the changelog against those same computed signals: a release only counts as a security fix when the changelog says so *and* the diff actually adds matching capability, nonce, escaping or sanitisation calls. A changelog claiming "security fix" with no matching code change is reported as exactly that discrepancy.

It is deliberately more cautious than the AI engine. It can see that security checks were added; it cannot tell you whether they are correct or complete. So its confidence is capped at 75%, and by default a no-AI verdict never installs anything automatically — it reports and notifies only. There is an opt-in if you want otherwise.

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

Because it has not read the fix. It can tell that escaping was added to a file the changelog mentions, which is decent evidence, but not that the vulnerability is actually closed. Auto-installing on that is a bigger leap than auto-installing on a model that read the code. There is an opt-in checkbox if you disagree.

== Screenshots ==

1. The reports screen, listing every analysed update with its verdict and a row of computed change signals.
2. A single report: what changed, security findings, concerns, and exactly which files were reviewed.
3. The activity log, showing everything the plugin did unattended.
4. Settings, with the three enforcement modes and the AI/no-AI engine choice.

== Changelog ==

= 0.5.0 =
* Polls WordPress.org for updates every fifteen minutes from its own cron instead of waiting for core's twice-daily check, so a security patch is judged and installed within roughly ten to twenty minutes of being published.
* Malformed provider responses are treated as transient and retried once.
* Repository README, .gitignore.

= 0.4.0 =
* The security question is also asked per flagged file (the pattern scan knows which files gained nonce checks, escaping or prepared statements), since a fast model answers small focused prompts far more reliably than a whole diff. Findings from both passes are merged.
* The response schema is sent in the strict {name, strict, schema} form OpenAI-compatible APIs enforce; the SDK omits the wrapper. The parser also tolerates bare arrays and synonym keys, which had been silently discarding real findings.
* Fixed: a manual "Analyse now" could be double-processed by the cron queue between phases.
* Timeouts on small requests are retried once and reported as provider slowness, not prompt size.
* Analysis is now two model calls: one returns structured findings only (its schema has no prose field, so evidence has nowhere to go but the arrays), and a second small call writes the headline and summary from those findings.
* Whether an update is a security fix is derived from cited findings rather than asserted by the model, so a security verdict with nothing cited can no longer occur.
* Each finding carries its own confidence; the overall confidence is the strongest of them.

= 0.3.3 =
* Analysis now runs in two phases across separate cron ticks: download and diff on one, the model call on the next. No single PHP request has to survive both, which is what was killing long analyses.
* Only ever raise PHP's execution limit, never lower it. Calling set_time_limit(300) where max_execution_time was 0 imposed a ceiling that was not there before.
* Request timeouts scale with prompt size and are far more generous.

= 0.3.2 =
* Fixed: request timeouts. The per-request timeout was never passed to the HTTP request, so every analysis was cut off at WordPress's five second default. Timeouts now scale with prompt size.
* Fixed: timeout errors now say what to change instead of reporting a raw cURL code.
* A security verdict that cites no file is retried once, and is shown on the report as unsubstantiated rather than failing silently.
* Default diff budget lowered to 500,000 characters after measuring that oversized prompts produce worse analyses at far higher cost.

= 0.3.1 =
* Fixed: number fields rejected sensible values. A step attribute meant the diff budget only accepted 20,000 plus a multiple of 100,000, and the confidence threshold only multiples of 5.
* Diff budget and per-file size limit are now preset dropdowns; a custom value set by a filter is preserved and still shown.

= 0.3.0 =
* New: computed change signals (styling, markup, JavaScript, outbound HTTP, REST, cron, schema, security checks added or removed, risky functions), shown as chips on the reports screen and broken down per report.
* New: Activity log page recording everything the plugin did, including unattended.
* New: no-AI engine (changelog plus diff pattern scan) needing no API key, no cost, and sending nothing off-site.
* Signals are computed from the diff, not generated by the model, so they are exact and survive a failed analysis.
* Fixed: security-check detection now measures net change, so reformatting a line containing esc_attr() no longer reports "security checks removed".

= 0.2.0 =
* Registers OpenRouter with the core AI Client registry; WordPress ships the SDK but no providers.
* API key via wp-config constant or settings field, constant wins.
* Default model z-ai/glm-5.3-flash; any OpenRouter model ID accepted.
* Diff budget default raised to 4,000,000 characters, ceiling 8,000,000.
* Configurable request timeout for large diffs.
* Fixed: builder calls must be snake_case, or AI errors are silently swallowed.

= 0.1.0 =
* Initial release.
