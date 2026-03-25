## Project Description

### Product Name
ContentSpy AI — SaaS for Competitive Intelligence, Automated Content Generation,
Multi-Platform Publishing, and WordPress Plugin Integration

### Vision
A multi-tenant SaaS platform that allows bloggers, agencies, and content marketers
to spy on competitors, generate AI-powered content, and automatically publish to
multiple WordPress sites and social media platforms — all from a single dashboard.
A dedicated WordPress plugin bridges the SaaS with each WordPress installation,
enabling two-way sync, on-site widgets, and direct publishing without REST API
credentials exposed on the client side.

---

## 1. MULTI-SITE MANAGEMENT

- User can connect and manage unlimited WordPress sites via:
  OPTION A: WordPress REST API + Application Passwords (direct)
  OPTION B: ContentSpy WordPress Plugin (recommended, more secure)
- Each site has its own: spy configuration, content workflow, publishing schedule,
  AI model preference, social media accounts, credit allocation
- Site health monitoring: connection status, last sync, last post date,
  plugin version installed, WordPress version, PHP version
- Site grouping by niche / language / client (for agencies)
- Per-site timezone configuration for scheduling

---

## 2. COMPETITOR SPYING ENGINE (multi-method)

Each competitor can be monitored via one or multiple methods simultaneously.

METHOD 1 — RSS Feed Monitoring
- Parse RSS/Atom feeds at configurable intervals (15min / 1h / 6h / 24h)
- Detect new articles by guid/link comparison against stored snapshots
- Extract: title, excerpt, publication date, categories, tags, author
- Score articles by engagement potential (length, keyword density, freshness)

METHOD 2 — Full HTML Scraping
- Crawl competitor blog index pages via Playwright headless browser (Node.js microservice)
- Extract new articles even without RSS feed
- Detect pagination and crawl multiple pages
- Respect robots.txt with override option (user legal responsibility)
- Rotate proxies and user-agents to avoid detection
- Screenshot storage of detected pages for audit

METHOD 3 — Sitemap Monitoring
- Parse XML sitemaps and sitemap indexes recursively
- Detect new URLs by diff against stored snapshots
- Support sitemap-index with nested sitemaps
- Priority scoring based on <priority> and <changefreq> values

METHOD 4 — Google News & Trend Monitoring
- Monitor Google News for competitor brand/domain mentions
- Keyword-based niche trend monitoring
- Detect trending topics in a niche before competitors post
- Google Trends API integration for search volume signals

METHOD 5 — Social Signal Tracking
- Monitor competitor Twitter/X, LinkedIn, Pinterest for content announcements
- Detect high-engagement posts as content opportunity signals
- Track sharing velocity of competitor articles as virality indicator

METHOD 6 — Backlink & Keyword Gap Spy
- Integrate with SEMrush / Ahrefs / Moz API (user provides own API key)
- Detect keywords competitors rank for that the user does not
- Surface "content gap" opportunities automatically ranked by traffic potential

METHOD 7 — SERP Position Monitoring
- Track competitor rankings for target keywords via SERP API
- Detect when a competitor publishes content that shifts rankings
- Alert when a competitor outranks user on a tracked keyword

---

## 3. AUTO-SPY MODE

- Each competitor + each method can individually be set to AUTO mode
- Background jobs run spy scans on configured schedules via Laravel Horizon
- New content detected → automatically triggers content suggestion pipeline
- Configurable confidence threshold:
  score > X → auto-generate suggestion card only
  score > Y → auto-generate full article
  score > Z → auto-generate + auto-publish (full autopilot)
- Auto-spy pauses automatically if credits fall below 50
- Audit log of every auto-spy action with timestamp, method, result, credits consumed

---

## 4. CONTENT SUGGESTION ENGINE

- Every detected competitor article generates a "content opportunity card"
- Card contains: suggested title, content angle, target keywords, estimated
  traffic potential, competition difficulty, recommended word count, tone
- User actions per card: Accept now / Schedule / Edit brief / Reject
- AI ranking of suggestions by: traffic potential, competition level,
  site relevance, content freshness, keyword gap score
- Suggestions grouped by: site, niche, keyword cluster, urgency
- Bulk actions: accept/reject/schedule multiple suggestions at once
- Suggestion expiry: cards older than 30 days auto-archived

---

## 5. AI CONTENT GENERATION PIPELINE

### Multi-Provider AI Integration

TEXT GENERATION PROVIDERS (with automatic fallback chain):
- OpenAI — GPT-4o, GPT-4-turbo, GPT-3.5-turbo
- Anthropic Claude — claude-3-5-sonnet, claude-3-opus (via direct API)
- OpenRouter — unified access to: Mistral, LLaMA 3, Gemini Pro,
  Cohere, Perplexity and any future model added to OpenRouter
- Per-site AI model preference (user selects default model per site)
- Per-generation model override (user can pick model at generation time)
- Fallback chain: if primary model fails → try secondary → try tertiary

IMAGE GENERATION PROVIDERS:
- Midjourney — via Discord bot automation (user provides own Discord token + server)
- DALL-E 3 — via OpenAI API
- Stable Diffusion XL — via Replicate API
- Per-site image provider preference
- Fallback: if Midjourney fails → DALL-E 3 → Stable Diffusion

VIDEO GENERATION (for social media):
- ElevenLabs or OpenAI TTS — voice generation from article text
- FFmpeg microservice — assemble images + audio into video
- RunwayML API (optional) — AI video generation for premium users

### Token Consumption Tracking (CRITICAL)

EVERY AI API call must:
1. Record the model used (exact model string)
2. Record prompt_tokens consumed
3. Record completion_tokens consumed
4. Record total_tokens consumed
5. Calculate cost in USD based on model pricing table:

   MODEL PRICING TABLE (USD per 1M tokens):
   gpt-4o:                input $5.00    output $15.00
   gpt-4-turbo:           input $10.00   output $30.00
   gpt-3.5-turbo:         input $0.50    output $1.50
   claude-3-5-sonnet:     input $3.00    output $15.00
   claude-3-opus:         input $15.00   output $75.00
   openrouter models:     fetch live pricing from OpenRouter /models endpoint
   dall-e-3 (per image):  $0.040 (1024x1024) / $0.080 (1024x1792)
   stable-diffusion:      fetch from Replicate API response

6. Store per call: model, prompt_tokens, completion_tokens, total_tokens,
   cost_usd, action_type, site_id, user_id, article_id, timestamp
7. Aggregate into token_usage_logs table for billing and analytics
8. Display to user: tokens used, estimated cost in USD, credits consumed
9. Admin dashboard shows: total API cost across all users, per model breakdown,
   margin analysis (credits sold vs API cost)

### Content Generation Steps
1. Brief creation (title, keywords, angle, word count, tone, structure, internal links)
2. SEO research pass (search volume, competition, LSI keywords via API)
3. Outline generation (H2/H3 structure, estimated word count per section)
4. Full article generation — section by section (chunked for long articles >2000 words)
5. SEO optimization pass (meta title, meta description, slug, internal link suggestions)
6. Duplicate content check (compare against existing published posts via Meilisearch)
7. Image generation (featured image + one image per H2 if configured)
8. WordPress/social formatting
9. Optional human review step (configurable per site)
10. Publish or schedule

---

## 6. WORDPRESS AUTO-PUBLISHING (via REST API or Plugin)

- Create posts as: draft / pending / published / scheduled
- Set: categories, tags, featured image (upload via media endpoint),
  author, slug, excerpt, custom fields
- Support Yoast SEO fields: _yoast_wpseo_title, _yoast_wpseo_metadesc,
  _yoast_wpseo_focuskw
- Support Rank Math fields: rank_math_title, rank_math_description,
  rank_math_focus_keyword
- Gutenberg block format OR Classic editor HTML (per site config)
- Publishing queue with rate limiting: max X posts/day per site (configurable)
- Retry failed publishes (3 attempts with exponential backoff)
- Two-way sync: detect manually published posts on WP → mark in SaaS dashboard

---

## 6B. WORDPRESS PLUGIN (ContentSpy Connect)

The WordPress plugin is a first-class deliverable of this SaaS.
It must be developed as a standalone WordPress plugin, distributed via the SaaS
dashboard (direct download, not WordPress.org directory).

### Plugin Architecture
- Plugin slug: contentspy-connect
- Requires: WordPress 5.8+, PHP 8.0+
- No external dependencies — pure WordPress APIs only
- Settings stored in wp_options (encrypted API key)
- Custom REST API endpoints registered under /wp-json/contentspy/v1/

### Plugin Features

AUTHENTICATION & CONNECTION
- Plugin settings page in WP Admin: ContentSpy → Settings
- User pastes their SaaS API key → plugin verifies with SaaS handshake endpoint
- Connection status displayed: connected / disconnected / error
- Auto-reconnect on token expiry

CUSTOM REST ENDPOINTS (registered by plugin, called by SaaS)
- POST /wp-json/contentspy/v1/publish
  Receives full article payload, creates WP post, returns post ID and URL
  Handles: post creation, category/tag mapping, featured image upload,
  Yoast/RankMath meta, custom fields, scheduling
- GET /wp-json/contentspy/v1/status
  Returns: site health, WP version, PHP version, active plugins list,
  available categories, available tags, authors list, plugin version
- GET /wp-json/contentspy/v1/posts
  Returns list of published posts with: ID, title, slug, date, status,
  categories, tags, view count (if available)
- POST /wp-json/contentspy/v1/sync
  Triggers full sync of posts back to SaaS (for two-way sync)
- DELETE /wp-json/contentspy/v1/posts/{id}
  Deletes or moves to trash a post by ID

SECURITY
- All plugin endpoints protected by: WP nonce OR SaaS HMAC signature verification
- HMAC: SaaS signs every request with shared secret (SHA-256)
  Plugin verifies signature before processing any request
- IP whitelist option: only allow requests from SaaS server IPs
- Rate limiting: max 60 requests/minute per endpoint
- All plugin activity logged in custom DB table: contentspy_logs

ON-SITE WIDGET (optional, activatable)
- Shortcode [contentspy_suggestions] — displays content suggestions widget on WP admin
- Shows pending articles waiting for review directly in WP dashboard
- Quick approve/reject from WP admin without going to SaaS dashboard
- WP Admin bar indicator: pending articles count badge

PLUGIN AUTO-UPDATE
- Plugin checks for updates via SaaS API endpoint on every WP admin load
- Update available → show notice in WP admin → one-click update
- SaaS serves plugin zip file from S3/R2 storage
- Version compatibility matrix stored in SaaS DB

PLUGIN DISTRIBUTION
- Plugin zip downloadable from SaaS dashboard
- Installation guide with screenshots
- CLI install command provided: wp plugin install [url] --activate

---

## 7. SOCIAL MEDIA AUTO-PUBLISHING

Each platform has its own content adapter and publishing workflow.

FACEBOOK (Graph API v18)
- Page posts: link post, photo post, carousel
- Adapter: extracts key insight → 300-500 char post + image
- Scheduling via scheduled_publish_time
- Metrics fetch: likes, comments, shares, reach, impressions

INSTAGRAM (Graph API — Business/Creator accounts only)
- Single image post, carousel (up to 10 images)
- Reels: script from article → TTS audio → image slideshow → MP4 via FFmpeg
- Caption: max 2200 chars + hashtag block (AI-generated)
- Metrics fetch: likes, comments, saves, reach, plays (reels)

TIKTOK (Content Posting API)
- Video: 3-5 key points → TTS script → slideshow video via FFmpeg
- Upload as file (not URL) — pre-upload to TikTok then publish
- Hashtag and description AI-generated
- Metrics fetch: views, likes, comments, shares

PINTEREST (API v5)
- Standard pin: vertical image (1000x1500) + title + description + link to WP post
- Idea pin: multi-page (one page per H2 section)
- Board mapping: site category → Pinterest board (user configures)
- Metrics fetch: impressions, saves, clicks, outbound clicks

### Social Publishing Workflow
- Per platform per site: disabled / manual / semi-auto / full-auto
- Trigger options: after WP publish / after generation / custom cron schedule
- Platform rate limits enforced:
  Facebook: 25 posts/day/page
  Instagram: 25 posts/day/account
  TikTok: 5 videos/day/account
  Pinterest: 150 pins/day/account
- Failed posts: retry 3x with exponential backoff → mark failed → notify → refund credits
- Social post history: full log per platform per site per article

---

## 8. CREDIT SYSTEM & BILLING

### Credit Consumption Table (every action logged)

SPY ACTIONS:
- RSS feed scan (per competitor):              1 credit
- HTML scraping scan (per competitor):         3 credits
- Sitemap scan (per competitor):               1 credit
- Google News scan (per keyword):              2 credits
- Social signal scan (per platform):           2 credits
- SEMrush/Ahrefs keyword gap pull:             5 credits

CONTENT ACTIONS:
- Content suggestion card generation:          2 credits
- Article outline generation:                  3 credits
- Article generation (per 1000 words):         5 credits
- SEO optimization pass:                       2 credits
- Duplicate content check:                     1 credit

IMAGE & VIDEO ACTIONS:
- Image generation — DALL-E 3:                3 credits
- Image generation — Stable Diffusion:         2 credits
- Image generation — Midjourney:               4 credits
- TikTok/Reel video assembly (FFmpeg):         4 credits
- TTS audio generation (per 1000 chars):       2 credits

PUBLISHING ACTIONS:
- WordPress publish (via plugin or API):       1 credit
- Facebook post:                               1 credit
- Instagram post (image):                      2 credits
- Instagram reel:                              5 credits
- TikTok video:                               5 credits
- Pinterest pin:                               1 credit

### Token Cost Tracking (parallel to credits)
- Every AI text generation call records: model, prompt_tokens, completion_tokens,
  cost_usd (calculated from pricing table)
- Every image generation records: provider, resolution, cost_usd
- Every video/TTS generation records: provider, duration_seconds, chars_count, cost_usd
- Token logs linked to: user_id, site_id, action_type, article_id, job_id
- User-facing: credits consumed per action
- Admin-facing: real USD cost per action, per user, per model, margin analysis

### Credit Plans & Pricing
- Starter:  500 credits / $19/month  → max 3 sites, 5 competitors, 2 social platforms
- Pro:      2000 credits / $49/month  → max 15 sites, 50 competitors, 4 social platforms
- Agency:   10000 credits / $149/month → unlimited sites, competitors, platforms
- One-time credit packs: 200cr/$9, 1000cr/$39, 5000cr/$159
- Credit overage: $0.05 per credit after plan allowance
- Auto-recharge: user sets threshold (e.g. below 100cr → buy 500cr automatically)
- Credits never expire for paid plans, expire after 90 days for one-time packs

### Credit Integrity Rules (CRITICAL)
- Credits deducted BEFORE action starts (reservation system)
- If action succeeds → deduction confirmed
- If action fails → credits fully refunded within same DB transaction
- Concurrent job protection: use DB-level locking or Redis atomic decrement
  to prevent race conditions on credit balance
- Credit transaction log: every debit/credit with reason, action, timestamp, balance
- Negative balance never allowed — action blocked if insufficient credits

---

## 9. WORKFLOW ENGINE (per site)

- Each site has a fully configurable workflow:
  DETECT → SCORE → SUGGEST → REVIEW → GENERATE → OPTIMIZE → PUBLISH → DISTRIBUTE
- Each step independently configurable: manual / semi-auto / full-auto
- Workflow templates:
  "Full Autopilot": everything auto end-to-end
  "Human in the Loop": generate auto, human approves before publish
  "Spy Only": detect and suggest, no generation
  "Generate Only": no spy, manual brief input
  "Social Only": generate + publish to social only, skip WordPress
- Conditional logic per step:
  if opportunity_score > 80 AND keyword_difficulty < 40 → auto-generate
  if generated_article score > 75 → auto-publish
  else → queue for human review
- Workflow run history: full audit log per step per site
- Failed step → notify user → pause workflow → await manual action

---

## 10. DASHBOARD & ANALYTICS

GLOBAL DASHBOARD
- All sites overview: spy activity, pending suggestions, publishing queue, credit balance
- Activity feed: real-time competitor detections across all sites
- Credit consumption graph (daily/weekly/monthly)
- Token cost breakdown by model (for admin and power users)

PER-SITE DASHBOARD
- Spy activity timeline: what was detected, when, from which competitor, via which method
- Content pipeline kanban: Suggested → Generating → Review → Scheduled → Published
- Publishing calendar (drag-and-drop rescheduling)
- Social media performance per platform

COMPETITOR ANALYTICS
- Article frequency per competitor (posting cadence)
- Top performing topics per competitor
- Keyword overlap analysis

CONTENT PERFORMANCE
- Fetch WP post stats (views if available via WP plugin)
- Google Search Console API integration: impressions, clicks, CTR, position per post
- Social engagement per post per platform
- Best posting time heatmap per platform

ADMIN ANALYTICS (SaaS owner view)
- Total users, MRR, churn rate
- Credits sold vs API cost (margin per user, per plan)
- Most used AI models (token consumption leaderboard)
- Most used spy methods
- Error rate per integration

---

## 11. TEAM & AGENCY FEATURES

- Multi-user per workspace: Owner / Admin / Editor / Writer / Viewer
- Role permissions matrix: who can configure spy / approve content / publish / manage billing
- Client workspaces: full data isolation per client (separate sites, competitors, credits)
- Credit allocation per workspace (agency distributes credits to clients)
- White-label: custom domain (CNAME), custom logo, remove ContentSpy branding,
  custom email sender domain
- Bulk operations: apply spy config / workflow / AI model to multiple sites at once
- Activity log per team member

---

## 12. NOTIFICATIONS & ALERTS

- In-app notifications (real-time via Laravel Echo + Pusher/Soketi)
- Email notifications via Resend:
  new competitor article detected (digest or instant)
  article ready for review
  article published successfully
  credits low (threshold configurable)
  spy method blocked/failed
  social post failed
  WordPress connection lost
  plugin update available
- Webhook support: user configures URL → SaaS POSTs event payload on any trigger
- Slack integration: send notifications to Slack channel via webhook

---

## TECH STACK

Backend:
- Laravel 11 (PHP 8.3) — main application
- Laravel Horizon — queue monitoring and management
- Laravel Echo Server — WebSocket for real-time notifications
- Redis — queues, cache, rate limiting, atomic credit operations
- MySQL 8 — primary relational database
- MongoDB — raw spy snapshots, article diff storage, audit logs
- Meilisearch — article deduplication, full-text search on suggestions

Microservices:
- Playwright Node.js service — headless browser scraping (Docker container)
- FFmpeg Node.js service — video assembly for TikTok/Reels (Docker container)

Frontend:
- React 18 + Vite + TailwindCSS + shadcn/ui
- TanStack Query — server state management
- Zustand — client state
- FullCalendar — publishing calendar
- Recharts — analytics charts

WordPress Plugin:
- Pure PHP, no composer dependencies
- WordPress REST API custom endpoints
- HMAC signature verification
- wp_options for settings, custom table for logs

Auth & Payments:
- Laravel Sanctum (SPA auth)
- Google OAuth (social login)
- Stripe — subscriptions + one-time credit packs + usage-based overage

Storage & Infrastructure:
- Cloudflare R2 (S3-compatible) — generated images, videos, plugin zip files
- Resend — transactional email
- Sentry — error tracking
- Laravel Telescope — local dev debugging
- Docker + Laravel Forge or Coolify — deployment

External AI APIs:
- OpenAI API (GPT-4o, GPT-4-turbo, GPT-3.5-turbo, DALL-E 3)
- Anthropic API (claude-3-5-sonnet, claude-3-opus)
- OpenRouter API (multi-model gateway)
- Midjourney (Discord bot automation — user provides token)
- Replicate API (Stable Diffusion XL)
- ElevenLabs API (TTS for video)
- RunwayML API (optional AI video)

External Data APIs:
- SEMrush API (keyword data)
- Ahrefs API (backlink data)
- Moz API (domain authority)
- Google Search Console API (post performance)
- Google Trends API (trend signals)
- SERP API (rank tracking)
- Twitter/X API v2 (social monitoring)
- LinkedIn API (social monitoring)

---

## KEY BUSINESS RULES

- A site cannot publish if its WordPress connection is not verified
- Credits reserved before action, refunded on failure — never lost on system error
- Auto-spy pauses automatically below 50 credits
- Full autopilot requires Pro plan or above
- White-label requires Agency plan
- Midjourney requires user's own Discord token (legal/ToS responsibility on user)
- All scraped content stored as raw data — user is legally responsible
- Generated content owned by user — purged after 90 days from SaaS storage
- Every article must pass duplicate check before publish
- All API keys stored encrypted (AES-256) — never logged or exposed in responses
- Token cost calculated and stored for EVERY AI call without exception
- Plan limits enforced at job creation time, not at billing time
- Credits shared across workspace (not per user within workspace)
- Rate limits:
  Starter:  3 sites, 5 competitors/site, 2 social platforms/site, 10 articles/day
  Pro:      15 sites, 50 competitors/site, 4 social platforms/site, 100 articles/day
  Agency:   unlimited sites, unlimited competitors, all platforms, unlimited articles

---

## MAIN TECHNICAL RISKS

- Playwright at scale: memory leaks, proxy rotation management, anti-bot detection
  evasion, Cloudflare bypass — needs robust retry and circuit breaker logic
- Midjourney no official API: Discord bot automation brittle, may break with
  Discord API changes — needs fallback to DALL-E or SD automatically
- Long article generation timeouts: articles >3000 words must use chunked async
  generation with job queue — never synchronous HTTP request
- WordPress REST API inconsistencies: varies by WP version, installed plugins,
  server config — plugin approach more reliable than direct REST calls
- Article deduplication across spy methods: same article detected by RSS + Sitemap
  + HTML scraper must be deduplicated before creating suggestion cards
- Credit race conditions: concurrent jobs for same user can overdraft — must use
  Redis atomic operations (DECR with floor check) or DB pessimistic locking
- Multi-tenant data isolation: ALL database queries must be scoped by workspace_id
  — missing scope = data leak between clients (critical security risk)
- OAuth token management across 4 social platforms: different expiry rules,
  refresh flows, revocation detection — needs unified token refresh service
- TikTok Content Posting API: requires approved developer app, video pre-upload
  as file, strict codec requirements (H.264, specific resolution/duration)
- Instagram Reels via API: Business/Creator account only, H.264 video,
  specific aspect ratios, 3-90 second duration limit
- FFmpeg video assembly: CPU-intensive, must run isolated in Docker with
  resource limits — never inline in Laravel request cycle
- OpenRouter pricing volatility: model prices change — must fetch live pricing
  and not hardcode, recalculate cost_usd at query time
- Plugin security: HMAC verification must be timing-attack safe (hash_equals),
  never strcmp — plugin endpoints are public-facing attack surface
- Google Search Console API quota: 50 queries/day free tier —
  must implement smart caching and batch fetching strategy

---

## OUT OF SCOPE (do not implement)

- Mobile app (iOS/Android) — web only
- WordPress.org plugin directory submission — direct distribution only
- Personal Facebook profiles — Pages only via Graph API
- Automated purchasing of SEO tool subscriptions — user provides own API keys
- Content translation (future feature)
- Voice/podcast generation (future feature)