

\# Automated SEO Optimization System: Technical Architecture & Implementation Guide

\#\# 1\. System Architecture Overview

\#\#\# 1.1 Core Design Principles

\#\#\#\# 1.1.1 Separation of Concerns: n8n as "Brain", WordPress as "Execution Layer"

The architectural foundation of this system rests on a \*\*rigorous separation of computational responsibilities\*\* between two specialized platforms. \*\*n8n functions as the analytical "brain"\*\*—a centralized orchestration engine that handles multi-step workflows, external API integrations, conditional logic, and temporal scheduling. This leverages n8n's native strengths: visual workflow programming, extensive node ecosystem, built-in retry mechanisms, and resilient execution handling for long-running processes. The WordPress installation, enhanced by a custom plugin, operates as the \*\*"execution layer"\*\*—managing database persistence through \`wp\_postmeta\`, rendering administrative interfaces, applying final metadata changes to SEO plugins, and serving as the system of record for all optimization state.

This separation yields substantial operational advantages. \*\*Performance isolation\*\* ensures that n8n's computational demands—AI API calls, data aggregation, statistical calculations—never impact WordPress frontend response times, preserving the very SEO rankings the system optimizes for. \*\*Independent scalability\*\* allows the n8n instance to run on specialized infrastructure with higher memory allocations for AI processing, while WordPress remains on standard hosting optimized for content delivery. \*\*Multi-tenancy becomes feasible\*\*: a single n8n deployment can orchestrate optimization across dozens of WordPress properties, each with its own plugin instance but sharing centralized AI credentials, prompt engineering investments, and aggregated learning across sites.

The communication protocol between layers is \*\*intentionally minimal and stateless\*\*. WordPress initiates through webhook calls carrying only essential identifiers (\`post\_id\`, \`site\_url\`, \`priority\_flag\`). n8n responds via authenticated REST API calls with structured decision payloads. All intermediate state—the current test phase, historical baselines, pending variants—lives in WordPress's \`wp\_postmeta\` table, ensuring that neither component possesses "hidden" state that could desynchronize. This "dumb pipes, smart endpoints" pattern maximizes reliability: n8n workflows can be rebuilt or migrated without data loss, and WordPress remains the definitive source of truth for audit and recovery purposes.

\#\#\#\# 1.1.2 Feedback Loop: Continuous Performance Monitoring → AI Optimization → A/B Validation

The system's operational philosophy centers on a \*\*closed feedback loop\*\* that transforms raw search data into validated improvements through three interconnected stages. This structure prevents the common failure mode of "optimization theater"—changes made based on assumptions without empirical verification.

| Stage | Function | Key Activities | Output |  
|-------|----------|--------------|--------|  
| \*\*Monitoring\*\* | Establish empirical baseline | GA4/GSC data ingestion, metric normalization, trend detection | Performance snapshot with statistical metadata |  
| \*\*Optimization\*\* | AI-powered variant generation | Threshold evaluation, prompt engineering, Gemini/Kimi generation, quality validation | Test title/meta with confidence score |  
| \*\*Validation\*\* | Controlled empirical test | 14-day A/B execution, metric comparison, statistical significance testing | Permanent deployment of winner |

The \*\*monitoring stage\*\* operates continuously through scheduled API calls, building time-series understanding of each post's search visibility (impressions, position), click efficiency (CTR), and engagement quality (bounce rate, session duration). Rather than reacting to absolute thresholds, the system tracks \*\*relative degradation\*\*—performance decline from each post's established baseline, accounting for the wide variation in "good" performance across different query intents and competitive landscapes.

The \*\*optimization stage\*\* activates when degradation exceeds configurable thresholds. This is not template-based substitution but \*\*context-aware generation\*\*: prompts incorporate current performance metrics (indicating what specifically needs improvement), content excerpts (ensuring relevance), SERP competitive intelligence (identifying successful patterns), and keyword intent classification (guiding informational vs. transactional optimization strategies). The dual-model approach using \*\*both Gemini and Kimi 2.5\*\* enables ensemble validation—comparing outputs for consistency—or A/B testing of the AI models themselves to determine which produces superior outcomes for specific content categories.

The \*\*validation stage\*\* imposes empirical discipline through a \*\*fixed 14-day controlled experiment\*\*. This duration balances statistical validity against opportunity cost: long enough to accumulate meaningful click data even for moderate-traffic posts, short enough to avoid prolonged exposure to underperforming variants. At conclusion, a multi-factor decision algorithm evaluates whether the test variant achieved \*\*meaningful improvement in ranking position or CTR\*\*—not merely statistical significance, but practical significance that justifies permanent deployment. Winners become the new baseline; losers are archived for pattern analysis without affecting live content.

\#\#\#\# 1.1.3 State-Driven Workflow: Explicit Status Tracking Across System Boundaries

State management represents the \*\*most technically challenging aspect of distributed automation\*\*, addressed through explicit, queryable status fields that serve as the single source of truth. Every post under optimization management exists in exactly one of four states:

| State | Definition | Permissible Actions | Transition Triggers |  
|-------|-----------|---------------------|---------------------|  
| \`Baseline\` | No active test; original or previously optimized metadata in place | Initiate test (manual or automated threshold) | Degradation detection or admin trigger → \`Testing\` |  
| \`Testing\` | 14-day A/B test in progress; AI variant deployed and measuring | None (locked) | Test completion: \`Optimized\` or \`Failed\` |  
| \`Optimized\` | Previous test completed successfully; AI variant permanently deployed | Re-initiate test (after cooldown) | Degradation below new baseline → \`Testing\` |  
| \`Failed\` | Test completed without improvement; baseline retained; logged for analysis | Manual retry (after review) | Admin override → \`Testing\` |

These states are \*\*not merely labels but behavioral constraints\*\*. No new test can initiate while a post is \`Testing\`; no results can finalize while a post remains \`Baseline\`; automated retries are suppressed for \`Failed\` posts pending human review. The state machine enforces these rules in both systems: WordPress validates at the API layer before accepting test initiation requests, and n8n validates in workflow logic before executing optimization sequences. This \*\*defensive redundancy\*\* ensures that even if one system's validation is bypassed or bugged, the other maintains integrity.

\*\*Concurrency control\*\* prevents race conditions through a logical lock implemented via the \`\_seo\_ab\_test\_status\` field itself. Any initiation request first queries this field and rejects if not \`Baseline\` or \`Failed\`. The lock includes timestamp and initiating source for debugging, with automatic expiration after 24 hours as failsafe protection against orphaned locks from failed n8n executions. For operational visibility, the WordPress admin interface displays current state through \*\*color-coded badges\*\*: gray for \`Baseline\`, yellow for \`Testing\`, green for \`Optimized\`, red for \`Failed\`.

\#\#\# 1.2 Component Interaction Model

\#\#\#\# 1.2.1 Data Flow: WordPress → n8n Webhook → Google APIs → AI Services → WordPress REST API

The complete data flow traces a \*\*circuit that begins and ends in WordPress\*\*, with n8n as the orchestration hub:

| Step | Direction | Action | Data Exchanged |  
|------|-----------|--------|---------------|  
| 1 | WordPress → n8n | Webhook trigger | \`post\_id\`, \`site\_url\`, \`trigger\_type\`, \`priority\` |  
| 2 | n8n → WordPress | Context retrieval | Current SEO metadata, content excerpt, historical state |  
| 3 | n8n → Google APIs | Performance data | OAuth2/Service Account auth; date ranges, dimensions, metrics |  
| 4 | n8n → AI Services | Variant generation | Prompt with context; response with title/meta options |  
| 5 | n8n → WordPress | Test deployment | Test variant, baseline snapshot, scheduled re-check |  
| 6 | n8n → Google APIs | Test period monitoring | Same as step 3, 14 days later |  
| 7 | n8n → WordPress | Final decision | Winning variant, metrics comparison, status update |

\*\*Stage 1 (Initiation)\*\* uses \`wp\_remote\_post()\` with 30-second timeout and retry logic. WordPress expects immediate acknowledgment (\`test\_id\`, \`estimated\_completion\`) rather than waiting for full processing, since AI generation may require several minutes. This \*\*asynchronous pattern\*\* prevents WordPress request timeouts and enables n8n's internal queuing.

\*\*Stage 2 (Context Retrieval)\*\* ensures n8n has current information without relying on potentially stale webhook payload. The REST call fetches: Yoast/Rank Math meta fields, post content excerpt, target keywords, and any manual override parameters from the admin interface.

\*\*Stages 3-4 (External Services)\*\* execute with comprehensive error handling. Google API quota exhaustion triggers exponential backoff with jitter. AI API failures attempt fallback models or queue for human review. All external calls include \*\*circuit breaker patterns\*\*: after consecutive failures, the workflow enters degraded mode with alerting rather than repeated doomed attempts.

\*\*Stage 5 (Test Deployment)\*\* is the critical handoff where n8n's analytical output becomes WordPress's operational reality. The REST payload includes: generated title/description, AI model source, prompt hash for reproducibility, baseline metrics snapshot, and authentication signature. WordPress validates, updates \`wp\_postmeta\`, transitions status to \`Testing\`, and returns confirmation.

\*\*Stages 6-7 (Conclusion)\*\* repeat the data collection with identical methodology for fair comparison, then apply the decision logic specified in requirements: \*\*retain variant if ranking improved OR CTR improved by threshold percentage; otherwise retain baseline\*\*.

\#\#\#\# 1.2.2 Trigger Mechanisms: Scheduled Polling vs. Manual Admin Initiation

| Mechanism | Implementation | Use Case | Configuration |  
|-----------|---------------|----------|---------------|  
| \*\*Scheduled Polling\*\* | n8n Cron node, daily 02:00 UTC | Autonomous monitoring, broad coverage | Batch evaluation of all \`Baseline\`/\`Optimized\` posts; intelligent filtering by traffic volume and recency |  
| \*\*Manual Initiation\*\* | Admin button → immediate webhook | Strategic override, proactive optimization, system testing | Bypasses threshold requirements; optional parameters for AI model, custom context, accelerated schedule |  
| \*\*Threshold-Triggered\*\* | Real-time evaluation within scheduled flow | Responsive degradation detection | Evaluates composite health score; queues optimization if thresholds exceeded |

\*\*Scheduled polling\*\* implements \*\*intelligent prioritization\*\* to respect API quotas. Posts are scored by optimization potential: traffic volume × degradation severity × strategic importance. High-scoring posts process first; low-traffic posts may be deferred or excluded from automation. The batch size respects Google Analytics 4's 10,000 requests/day/property limit and AI service rate limits, with inter-batch delays configurable per environment.

\*\*Manual initiation\*\* serves essential governance functions. Content managers can \*\*force optimization\*\* of high-value pages before anticipated traffic surges, \*\*recover from known issues\*\* (corrupted metadata, editorial errors), or \*\*validate system behavior\*\* on specific content before enabling full automation. The manual path includes optional parameters not available to automated triggers: preferred AI model, custom prompt context, and accelerated test duration for urgent cases. These flow through the same n8n infrastructure but branch to specialized workflow paths that respect human-specified constraints.

\*\*Hybrid operational model\*\* combines both: scheduled polling for comprehensive coverage with manual override for strategic intervention. Configuration parameters tune this balance: \`auto\_evaluation\_enabled\` (boolean), \`evaluation\_interval\_days\` (integer), \`manual\_override\_roles\` (array of capabilities), and \`cooldown\_days\` (minimum interval between tests, preventing optimization fatigue).

\#\#\#\# 1.2.3 Authentication Chain: OAuth2 (GA4) \+ Service Account (GSC) \+ API Keys (AI Services)

| Service | Auth Type | Credential Location | Critical Configuration | Common Failure Modes |  
|---------|-----------|---------------------|------------------------|-------------------|  
| \*\*Google Analytics 4\*\* | OAuth2 with refresh token | n8n native credential store | "Published" app status; \`analytics.readonly\` scope; test user assignment | 403 "access\_denied" from testing-mode apps; expired refresh tokens from 7-day testing limits  |  
| \*\*Google Search Console\*\* | Service Account (JWT) | n8n HTTP Request credential | JSON key \`client\_email\` \+ \`private\_key\`; explicit GSC property ownership | 403 from missing property authorization; PEM formatting errors in private key |  
| \*\*Gemini (Google AI)\*\* | API key | n8n credential vault | \`x-goog-api-key\` header or query param; rate limit monitoring | Quota exhaustion; model version deprecation |  
| \*\*Kimi 2.5 (Moonshot AI)\*\* | Bearer token | n8n credential vault | \`Authorization: Bearer\` header; 200K context window for long content | Regional API availability; token context limits |

\*\*GA4 OAuth2\*\* requires particular attention to \*\*application publishing status\*\*. During development, the OAuth consent screen can remain in "Testing" with explicit test user assignments, but refresh tokens expire after 7 days—acceptable for development, catastrophic for production. Publishing to "In production" status removes this limitation but may trigger Google's verification process. The \`https://www.googleapis.com/auth/analytics.readonly\` scope is sufficient and preferred; broader scopes require additional justification during verification.

\*\*GSC Service Account\*\* authentication uses \*\*server-to-server JWT flow\*\* without user interaction. The JSON key file downloaded from Google Cloud Console contains \`client\_email\` and \`private\_key\` (RSA key in PEM format with \`\\n\` escaped newlines). Critically, this service account email must be \*\*explicitly added as a verified owner in the GSC property\*\*—unlike GA4, where OAuth grants access to the authorizing user's permissions, GSC service accounts have no inherent property access. This step is frequently overlooked, causing mysterious 403 errors despite valid credentials.

\*\*AI service API keys\*\* are simpler but require \*\*operational safeguards\*\*: IP allowlisting where supported, usage quotas with alerting, key rotation schedules, and separate credentials per environment (development/staging/production). The system should implement \*\*graceful degradation\*\*: if Gemini quota is exhausted, fallback to Kimi; if both fail, queue for human review with full context rather than blocking indefinitely.

\#\# 2\. Workflow Schema (n8n Implementation)

\#\#\# 2.1 Phase 1: Performance Data Ingestion

\#\#\#\# 2.1.1 Google Analytics 4 Integration

\#\#\#\#\# 2.1.1.1 Native n8n "Google Analytics" Node Configuration

The \*\*native "Google Analytics" node\*\* provides structured GA4 Data API access without manual HTTP construction. Configuration parameters determine data relevance and volume:

| Parameter | Setting | Rationale |  
|-----------|---------|-----------|  
| \*\*Property\*\* | Dynamic from site config | Enables multi-site orchestration |  
| \*\*Date Ranges\*\* | \`{{ $now.minus({days: 14}).toFormat('yyyy-MM-dd') }}\` to \`{{ $now.minus({days: 1}).toFormat('yyyy-MM-dd') }}\` | Excludes incomplete current day; 14-day default for statistical stability |  
| \*\*Metrics\*\* | \`screenPageViews\`, \`sessions\`, \`engagementRate\`, \`averageEngagementTime\`, \`conversions\` | Core engagement indicators; \`engagementRate\` replaces legacy bounce rate |  
| \*\*Dimensions\*\* | \`pagePath\`, \`sessionDefaultChannelGroup\`, \`date\` | URL-level aggregation; organic search segmentation; time-series capability |  
| \*\*Dimension Filters\*\* | \`pagePath=\~"/target-slug/"\` | Reduces quota consumption; focuses on specific post |

The node outputs nested JSON requiring transformation: \`{{ $json\["reports"\]\[0\]\["rows"\] }}\` accesses data rows, with each row containing dimension values as an array and metrics as corresponding numeric values. \*\*Data quality validation\*\* is essential: posts with fewer than 100 sessions in the evaluation window are flagged for extended lookback or exclusion from automated optimization, as statistical noise would dominate any signal of performance change.

\#\#\#\#\# 2.1.1.2 OAuth2 Credential Setup & 403 Error Resolution

The \*\*most common deployment blocker\*\* for GA4 integration is the \`Error 403: access\_denied\` response, which occurs even for accounts with explicit property access. Root cause: \*\*Google's OAuth consent screen testing restrictions\*\* .

| Scenario | Symptom | Resolution |  
|----------|---------|------------|  
| Testing mode, no test users | 403 "access\_denied" immediately | Add authenticating account email to OAuth consent screen \> Test users |  
| Testing mode, 7+ days since auth | Silent failure, expired refresh token | Re-authenticate, or publish app to production |  
| Published app, verification pending | Intermittent 403 with "unverified" warning | Complete verification process or use test users temporarily |  
| Scope mismatch | 403 "insufficient\_permissions" | Verify \`analytics.readonly\` scope in consent screen and credential |

\*\*Production hardening\*\* requires: published OAuth application, verified domain if using restricted scopes, monitoring for token refresh failures with alerting, and documented re-authentication procedure for credential rotation.

\#\#\#\#\# 2.1.1.3 Metric Extraction: Page Views, Sessions, Bounce Rate, CTR (from GA4)

\*\*GA4's event-based model\*\* differs significantly from Universal Analytics, requiring careful metric interpretation:

| Metric | GA4 Definition | SEO Optimization Relevance |  
|--------|---------------|---------------------------|  
| \`screenPageViews\` | Total page load events | Traffic volume; raw popularity indicator |  
| \`sessions\` | Engaged sessions (10+ sec, 2+ pages, or conversion) | Audience reach; deduplicated visit count |  
| \`engagementRate\` | Engaged sessions / total sessions | Content relevance; inverse of bounce rate |  
| \`averageEngagementTime\` | Active interaction time per session | Content depth; satisfaction proxy |  
| \`conversions\` | Configured key events | Business value; bottom-funnel performance |

\*\*Direct CTR from GA4 is not available\*\*—the platform lacks search impression data. The system approximates search-referenced CTR by: (1) filtering \`sessionDefaultChannelGroup \= 'Organic Search'\`, (2) combining with GSC click data, or (3) using \`sessionSource \= 'google'\` with engagement quality as proxy. For optimization decisions, \*\*GSC CTR is authoritative\*\*; GA4 metrics provide engagement context that distinguishes clickbait (high CTR, low engagement) from genuine relevance improvement.

\#\#\#\#\# 2.1.1.4 Date Range Parameterization: Dynamic Lookback Windows (7d, 14d, 30d)

| Window | Use Case | Implementation |  
|--------|----------|---------------|  
| \*\*7 days\*\* | High-traffic posts (\>1000 weekly sessions); rapid trend detection | \`{{ $now.minus({days: 7}) }}\` to \`{{ $now.minus({days: 1}) }}\` |  
| \*\*14 days\*\* | Default baseline; test period duration; statistical balance | Standard configuration |  
| \*\*28 days\*\* | Low-traffic posts (\<100 weekly sessions); seasonal smoothing | \`{{ $now.minus({days: 28}) }}\` to \`{{ $now.minus({days: 1}) }}\` |  
| \*\*56 days\*\* | Extended baseline for annual seasonality comparison | Year-over-year same-period analysis |

\*\*Adaptive window selection\*\* uses traffic volume thresholds: posts with \>500 weekly sessions use 7-day monitoring windows with 14-day baselines; posts with 100-500 sessions use standard 14-day; posts with \<100 sessions use 28-day or are excluded from automation. The \*\*test conclusion phase strictly uses fixed 14-day windows\*\* matching the test duration, ensuring fair comparison regardless of monitoring configuration.

\#\#\#\# 2.1.2 Google Search Console Integration

\#\#\#\#\# 2.1.2.1 HTTP Request Node for GSC API (No Native Node Available)

Unlike GA4, \*\*GSC requires direct HTTP Request node configuration\*\*. The implementation pattern:

\`\`\`  
Method: POST  
URL: https://www.googleapis.com/webmasters/v3/sites/{{ encodeURIComponent($siteUrl) }}/searchAnalytics/query  
Authentication: Generic Credential Type \> OAuth2 \> Service Account  
Headers: Content-Type: application/json  
Body: {  
  "startDate": "{{ $startDate }}",  
  "endDate": "{{ $endDate }}",  
  "dimensions": \["page", "query"\],  
  "dimensionFilterGroups": \[{  
    "filters": \[{  
      "dimension": "page",  
      "operator": "equals",  
      "expression": "{{ $postUrl }}"  
    }\]  
  }\],  
  "rowLimit": 25000  
}  
\`\`\`

\*\*Response handling\*\* requires JSON parsing to extract the \`rows\` array, where each row contains \`keys\` (dimension values), \`clicks\`, \`impressions\`, \`ctr\`, and \`position\`. The \*\*absence of \`rows\` indicates zero search traffic\*\*—valid data, not an error—distinguishable from HTTP error codes through status code checking.

\#\#\#\#\# 2.1.2.2 Service Account Authentication: JSON Key → client\_email \+ private\_key

The \*\*Service Account creation and configuration process\*\*:

| Step | Location | Action | Critical Detail |  
|------|----------|--------|---------------|  
| 1 | Google Cloud Console \> IAM & Admin \> Service Accounts | Create service account | Name: \`n8n-gsc-reader\`; no roles needed at creation |  
| 2 | Service Account \> Keys \> Add Key | Create JSON key | Download and secure immediately; cannot be retrieved later |  
| 3 | n8n \> Credentials \> Google Service Account | Configure credential | Paste \`client\_email\` exactly; paste \`private\_key\` with PEM markers intact |  
| 4 | Google Search Console \> Settings \> Users and Permissions | Add user | Enter service account email; assign \*\*Owner\*\* permission (Full/Restricted insufficient) |  
| 5 | n8n workflow test | Validate connectivity | Query site list or search analytics; expect 200 with data |

\*\*Security practices\*\*: store original JSON in password manager/secret vault; never commit to version control; rotate keys every 90 days; monitor Google Cloud audit logs for unexpected usage patterns.

\#\#\#\#\# 2.1.2.3 API Endpoint: \`https://www.googleapis.com/webmasters/v3/sites/{siteUrl}/searchAnalytics/query\`

The \*\*searchAnalytics/query endpoint\*\* provides comprehensive search performance data with flexible aggregation:

| Parameter | Type | Description | Optimization Use |  
|-----------|------|-------------|----------------|  
| \`startDate\`, \`endDate\` | ISO 8601 (YYYY-MM-DD) | Inclusive date range | Baseline establishment; test period evaluation |  
| \`dimensions\` | Array: \`page\`, \`query\`, \`device\`, \`country\`, \`date\` | Aggregation granularity | \`\["page"\]\` for post summary; \`\["page","query"\]\` for keyword analysis |  
| \`dimensionFilterGroups\` | Filter objects | Selective data extraction | Isolate specific post URL; exclude brand queries |  
| \`aggregationType\` | Enum: \`auto\`, \`byPage\`, \`byProperty\` | Rollup behavior | \`auto\` appropriate for most analyses |  
| \`rowLimit\` | Integer (max 25000\) | Pagination control | 25000 for comprehensive; reduce for performance |  
| \`startRow\` | Integer | Pagination offset | 0, 25000, 50000... for large datasets |

\*\*URL encoding requirements\*\*: The \`{siteUrl}\` path parameter must exactly match GSC property registration. Domain properties use \`sc-domain:example.com\` (encode colon as \`%3A\`); URL-prefix properties use full URL with protocol and trailing slash. Mismatches produce \*\*404 errors with no diagnostic detail\*\*—a common integration failure point.

\#\#\#\#\# 2.1.2.4 Metric Extraction: Impressions, Clicks, CTR, Average Position (Ranking)

| Metric | Definition | Interpretation | Optimization Signal |  
|--------|-----------|----------------|---------------------|  
| \*\*Impressions\*\* | Search result appearances | Visibility; query coverage | High impressions \+ low clicks \= title/meta appeal issue |  
| \*\*Clicks\*\* | Click-throughs to site | Traffic acquisition; ultimate value | Direct business impact |  
| \*\*CTR\*\* | Clicks / impressions | Snippet effectiveness; relevance match | \*\*Primary optimization target\*\* |  
| \*\*Position\*\* | Average ranking (lower=better) | Competitive strength; authority | \*\*Secondary optimization target\*\* |

\*\*Position-adjusted CTR evaluation\*\* is essential because CTR varies dramatically by position. The system maintains position-specific benchmarks:

| Position Range | Expected CTR | Interpretation |  
|---------------|-------------|----------------|  
| 1-3 | 25-35% | First page, above fold; high visibility |  
| 4-6 | 10-15% | First page, below fold; moderate visibility |  
| 7-10 | 5-8% | First page bottom; marginal visibility |  
| 11-20 | 1-3% | Second page; minimal traffic |  
| 20+ | \<1% | Deep results; essentially invisible |

A post at position 5 with 4% CTR \*\*significantly underperforms\*\* expectation (\~6-10%), indicating title/meta optimization opportunity. The same 4% CTR at position 8 would be \*\*strong performance\*\*, suggesting focus should be on ranking improvement rather than snippet optimization.

\#\#\#\#\# 2.1.2.5 Required OAuth Scope: \`https://www.googleapis.com/auth/webmasters.readonly\`

The \*\*readonly scope\*\* is sufficient and preferred for monitoring-only operations. Scope configuration occurs in two places that must align: Google Cloud Console OAuth consent screen (declared scopes) and actual API requests (scope claim in JWT). Mismatches produce \`403: scope\_not\_authorized\` errors that can be cryptic to diagnose.

For extended functionality (automatic sitemap submission, URL inspection API), the broader \`https://www.googleapis.com/auth/webmasters\` scope would be required. \*\*Separate service accounts\*\* for readonly monitoring and write operations follow least-privilege principles and limit blast radius from credential compromise.

\#\#\#\# 2.1.3 Data Normalization & Aggregation

\#\#\#\#\# 2.1.3.1 JSON Parsing Node for GSC Response Processing

The \*\*transformation pipeline\*\* converts GSC's nested response to analysis-ready flat structure:

\`\`\`  
Input: {"rows": \[{"keys": \["https://example.com/post/", "example query"\],   
                  "clicks": 152, "impressions": 3400, "ctr": 0.0447, "position": 8.3}\]}

Processing: (1) Split Out Items on rows array  
            (2) Set node: url \= {{ $json.keys\[0\] }}  
                         query \= {{ $json.keys\[1\] }}  
                         clicks \= {{ $json.clicks }}  
                         impressions \= {{ $json.impressions }}  
                         ctr \= {{ $json.ctr }}  
                         position \= {{ $json.position }}  
            (3) Aggregate by URL (if query-level data): sum clicks, impressions;   
                weighted average position

Output: \[{url: "https://example.com/post/", clicks: 152, impressions: 3400,   
          ctr: 0.0447, position: 8.3, query\_count: 23}\]  
\`\`\`

\*\*Quality validation\*\* includes: positive integers for impressions/clicks, CTR in \[0,1\], position \> 0 or null, and reasonable magnitude checks (CTR \> 50% suggests data anomaly requiring investigation).

\#\#\#\#\# 2.1.3.2 Merging GA4 \+ GSC Metrics by Post URL/Slug

\*\*URL normalization\*\* enables reliable joining across data sources with different URL representations:

| Source | Typical Format | Normalization |  
|--------|-------------|---------------|  
| GA4 | \`/post-slug/\` or \`/post-slug\` | Prepend domain, enforce trailing slash |  
| GSC | \`https://example.com/post-slug/\` or variant | Standardize protocol, enforce trailing slash |  
| WordPress | \`post-slug\` (slug only) | Expand to full URL for matching |

The \*\*Merge node\*\* configuration uses "Merge By Key" with normalized URL as match field. \*\*Inner join\*\* includes only posts with data from both sources—appropriate for optimization decisions requiring both visibility and engagement context. \*\*Left join from GSC\*\* includes search-visible posts even without GA4 traffic (tracking blockers, direct API access), enabling visibility-only analysis for low-engagement content.

\*\*Merged record structure\*\*:

\`\`\`json  
{  
  "post\_id": 123,  
  "url": "https://example.com/target-post/",  
  "slug": "target-post",  
  "gsc": {  
    "clicks": 152,  
    "impressions": 3400,  
    "ctr": 0.0447,  
    "position": 8.3,  
    "query\_count": 23  
  },  
  "ga4": {  
    "pageviews": 189,  
    "sessions": 156,  
    "engagement\_rate": 0.709,  
    "avg\_engagement\_time": 145  
  },  
  "calculated": {  
    "relative\_ctr": 0.89,  
    "position\_volatility": 2.1,  
    "traffic\_trend": \-0.15,  
    "optimization\_priority": 7.3  
  }  
}  
\`\`\`

\#\#\#\#\# 2.1.3.3 Calculated Fields: Relative CTR, Position Volatility, Traffic Trend

| Calculated Field | Formula | Purpose | Threshold Significance |  
|-----------------|---------|---------|------------------------|  
| \*\*Relative CTR\*\* | \`actual\_ctr / expected\_ctr\_for\_position\` | Position-normalized click efficiency | \<0.85 indicates underperformance; \>1.15 indicates overperformance |  
| \*\*Position Volatility\*\* | \`std\_dev(daily\_positions) / mean(position)\` | Ranking stability | \>0.3 suggests algorithmic testing or relevance issues; delay optimization |  
| \*\*Traffic Trend\*\* | \`slope(linear\_regression(daily\_sessions))\` | Momentum direction | Negative with p\<0.05 triggers priority evaluation |  
| \*\*Engagement Efficiency\*\* | \`engaged\_sessions / gsc\_clicks\` | Click-to-satisfaction conversion | \<0.5 suggests title/content mismatch |

These \*\*composite indicators\*\* feed into unified priority scoring that ranks posts for optimization processing when API quotas or execution time constraints limit simultaneous evaluation.

\#\#\# 2.2 Phase 2: Performance Threshold Evaluation

\#\#\#\# 2.2.1 Baseline Establishment Logic

\#\#\#\#\# 2.2.1.1 Historical Window Definition: Pre-Test Period (e.g., 14-28 days)

| Traffic Level | Recommended Window | Rationale |  
|-------------|-------------------|-----------|  
| \>1000 weekly sessions | 14 days | Sufficient statistical power; maximum recency |  
| 100-1000 weekly sessions | 21 days | Balance stability and responsiveness |  
| \<100 weekly sessions | 28-56 days | Accumulate meaningful sample; consider exclusion |

\*\*Window placement requirements\*\*: Must immediately precede test initiation without gaps; must exclude any prior test periods or known anomalies (site outages, viral spikes, manual edits). The \`post\_modified\` date is checked, and window adjusted if recent changes detected.

\#\#\#\#\# 2.2.1.2 Baseline Metric Storage: CTR\_mean, Position\_mean, PageViews\_mean

\*\*Statistical metadata\*\* enables rigorous comparison, not merely point estimates:

| Field | Type | Description | Use in Decision |  
|-------|------|-------------|---------------|  
| \`\_seo\_baseline\_ctr\` | float (0-1) | Mean click-through rate | Primary comparison metric |  
| \`\_seo\_baseline\_ctr\_std\` | float | CTR standard deviation | Significance weighting; confidence intervals |  
| \`\_seo\_baseline\_position\` | float | Mean ranking position | Secondary comparison metric |  
| \`\_seo\_baseline\_position\_std\` | float | Position standard deviation | Stability assessment |  
| \`\_seo\_baseline\_pageviews\` | integer | Total page views | Sample size validation |  
| \`\_seo\_baseline\_impressions\` | integer | Total search impressions | GSC sample size |  
| \`\_seo\_baseline\_date\_range\` | string | ISO 8601 period | Reproducibility; audit trail |  
| \`\_seo\_baseline\_calculated\` | datetime | Snapshot timestamp | Freshness indicator |

\*\*Atomic write pattern\*\*: All baseline fields update in single database transaction with \`Testing\` status transition. Partial failures roll back, preventing inconsistent state.

\#\#\#\# 2.2.2 Threshold Detection Rules

\#\#\#\#\# 2.2.2.1 CTR Decline Trigger: \`current\_ctr \< baseline\_ctr \* (1 \- threshold\_pct)\`

| Content Type | Default Threshold | Rationale |  
|-------------|-------------------|-----------|  
| Commercial/transactional | 15% (0.15) | High value, tight monitoring |  
| Informational/blog | 20% (0.20) | Natural variance, moderate sensitivity |  
| Evergreen/reference | 25% (0.25) | Stable expectations, avoid over-optimization |  
| News/time-sensitive | 30% (0.30) | High volatility, conservative triggering |

\*\*Expression implementation\*\*: \`{{ $json.current\_ctr \< $json.baseline\_ctr \* (1 \- $json.threshold\_pct) }}\`

\*\*Edge case handling\*\*: Zero baseline CTR returns \`null\` (cannot calculate decline); posts with \`null\` baseline CTR bypass this trigger, relying on position or traffic trends.

\#\#\#\#\# 2.2.2.2 Ranking Degradation Trigger: \`current\_position \> baseline\_position \+ position\_tolerance\`

| Position Band | Tolerance | Rationale |  
|--------------|-----------|-----------|  
| 1-3 (top results) | 0.5 positions | High sensitivity; small changes have large impact |  
| 4-10 (first page) | 1.0 position | Moderate sensitivity; maintain visibility |  
| 11-20 (second page) | 2.0 positions | Lower sensitivity; focus on page-one breakthrough |  
| 20+ (deep results) | 3.0 positions | Minimal automated response; manual review preferred |

\*\*Position-impact weighting\*\*: The system calculates \`position\_value \= 1 / position^2\`, reflecting the steep traffic drop-off across search results. A drop from position 2 to 4 reduces value by 75%; the same absolute drop from 15 to 17 reduces value by only 15%.

\#\#\#\#\# 2.2.2.3 Composite Score Trigger: Weighted Multi-Factor Degradation Detection

\*\*Default composite formula\*\*:

\`\`\`  
health\_score \= (0.40 \* ctr\_normalized) \+   
               (0.30 \* position\_normalized) \+   
               (0.20 \* traffic\_trend\_normalized) \+   
               (0.10 \* engagement\_normalized)  
\`\`\`

Where each component is normalized to 0-100 scale based on historical percentiles, and \`health\_score \< 75\` triggers optimization queue entry.

\*\*Component normalization\*\*:

| Component | Normalization Basis | Direction |  
|-----------|---------------------|-----------|  
| CTR | Relative to position-expected CTR | Higher \= better |  
| Position | Inverse: (100 \- position) | Higher \= better |  
| Traffic trend | Slope percentile vs. historical | Positive \= better |  
| Engagement | Engagement rate percentile | Higher \= better |

This \*\*multi-factor approach\*\* reduces false positives from single-metric volatility and captures degradation patterns that single thresholds miss—such as simultaneous moderate declines across multiple metrics indicating systemic issues.

\#\#\#\#\# 2.2.2.4 IF/Switch Node Implementation for Conditional Branching

| Node Type | Use Case | Configuration |  
|-----------|----------|---------------|  
| \*\*IF node\*\* | Binary single-condition decisions | Expression mode: \`{{ $json.health\_score \< 75 }}\` |  
| \*\*Switch node\*\* | Multi-way branching by value or condition | Rules: Case 1: \`health\_score \< 50\` → immediate; Case 2: \`health\_score \< 75\` → queued; Default: monitor |  
| \*\*Merge node\*\* | Reconverging branches after conditional processing | Wait for all branches, or prioritize first completion |

\*\*Rule ordering in Switch nodes\*\*: Most specific conditions first, with default case capturing unhandled states. Explicit "No Operation" nodes on unused branches maintain visual clarity and enable future extension.

\#\#\# 2.3 Phase 3: AI-Powered Title/Meta Generation

\#\#\#\# 2.3.1 Gemini API Integration

\#\#\#\#\# 2.3.1.1 HTTP Request Node Configuration: \`https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent\`

| Parameter | Value | Rationale |  
|-----------|-------|-----------|  
| Model | \`gemini-2.0-flash\` or \`gemini-pro\` | Speed vs. quality tradeoff; flash for volume, pro for strategic content |  
| Endpoint | \`.../v1beta/models/{model}:generateContent\` | Beta API for latest features; monitor for GA updates |  
| Temperature | 0.3-0.7 | 0.3 for conservative variations; 0.7 for creative exploration |  
| Max output tokens | 256 | Sufficient for title \+ description \+ rationale |  
| Top-p | 0.95 | Nucleus sampling diversity control |

\*\*Request body structure\*\*:

\`\`\`json  
{  
  "contents": \[{  
    "role": "user",  
    "parts": \[{"text": "{{ $prompt }}"}\]  
  }\],  
  "generationConfig": {  
    "temperature": 0.5,  
    "maxOutputTokens": 256,  
    "topP": 0.95,  
    "responseMimeType": "application/json"  
  },  
  "safetySettings": \[...\]  
}  
\`\`\`

\#\#\#\#\# 2.3.1.2 Prompt Engineering: SERP Context \+ Current Performance \+ Content Analysis

\*\*Structured prompt template\*\*:

\`\`\`  
You are an expert SEO copywriter. Generate 3 alternative title and meta description   
options for optimization testing.

CURRENT PERFORMANCE (indicates what needs improvement):  
\- Title: "{{current\_title}}"  
\- CTR: {{current\_ctr}}% ({{relative\_ctr\_description}})  
\- Position: {{current\_position}} ({{position\_description}})  
\- Engagement: {{engagement\_rate}}% ({{engagement\_description}})

CONTENT CONTEXT:  
\- Excerpt: {{content\_excerpt\_300\_chars}}  
\- Target keywords: {{target\_keywords}}  
\- Content type: {{content\_type}}

COMPETITIVE LANDSCAPE:  
\- Top 3 ranking titles: {{competitor\_titles}}  
\- Common patterns observed: {{pattern\_analysis}}

CONSTRAINTS:  
\- Title: 50-60 characters (optimal display in SERP)  
\- Meta description: 140-160 characters  
\- Include primary keyword naturally within first 50 characters of title  
\- Differentiate from competitors while matching search intent  
\- Compelling click-through appeal without clickbait

OUTPUT FORMAT (JSON):  
{  
  "options": \[  
    {  
      "title": "...",  
      "description": "...",  
      "rationale": "why this should improve performance",  
      "confidence": 0.0-1.0  
    }  
  \]  
}  
\`\`\`

\#\#\#\#\# 2.3.1.3 Output Parsing: Extracted Title, Meta Description, Confidence Score

\*\*Validation pipeline\*\*:

| Check | Action on Failure | Fallback |  
|-------|-----------------|----------|  
| JSON parseable | Retry with "output valid JSON only" prompt | Manual queue |  
| Required fields present | Retry with field enumeration | Lower confidence, flag for review |  
| Title length 30-60 chars | Truncate or regenerate with length emphasis | Truncate with ellipsis |  
| Description length 70-160 chars | Truncate or regenerate | Truncate at sentence boundary |  
| Primary keyword present | Retry with "include \[keyword\]" emphasis | Flag for manual review |  
| Confidence score ≥ 0.6 | Accept with warning; or regenerate | Second model attempt |

\*\*Selection logic\*\*: Highest confidence option passing all validation; or ensemble average if multiple models used.

\#\#\#\# 2.3.2 Kimi 2.5 API Integration (Alternative/Ensemble)

\#\#\#\#\# 2.3.2.1 HTTP Request Node: Moonshot AI API Endpoint

| Aspect | Configuration | Notes |  
|--------|-------------|-------|  
| Base URL | \`https://api.moonshot.cn/v1/chat/completions\` | OpenAI-compatible format |  
| Authentication | \`Authorization: Bearer {{ $credentials.kimi\_api\_key }}\` | Store in n8n credential vault |  
| Model | \`kimi-k2.5-latest\` | 200K context window; monitor for version updates |  
| Timeout | 60 seconds | Longer than Gemini due to context processing |

\*\*Request body\*\* (OpenAI chat format):

\`\`\`json  
{  
  "model": "kimi-k2.5-latest",  
  "messages": \[  
    {"role": "system", "content": "You are an expert SEO copywriter..."},  
    {"role": "user", "content": "{{ $prompt }}"}  
  \],  
  "temperature": 0.5,  
  "max\_tokens": 256  
}  
\`\`\`

\#\#\#\#\# 2.3.2.2 Model Selection: kimi-k2.5-latest for Long-Context SEO Analysis

\*\*Selection criteria\*\*:

| Scenario | Preferred Model | Rationale |  
|----------|---------------|-----------|  
| Standard posts (\<2000 words) | Gemini Pro | Speed, cost efficiency, proven SERP optimization |  
| Long-form content (\>2000 words) | Kimi 2.5 | Full content analysis; thematic depth capture |  
| Prior Gemini failures | Kimi 2.5 | Model diversity; different optimization philosophy |  
| Chinese/multilingual content | Kimi 2.5 | Native Chinese optimization; cross-lingual nuance |  
| Maximum confidence required | Both (ensemble) | Cross-validation; selection of best output |

\#\#\#\#\# 2.3.2.3 Ensemble Strategy: Gemini \+ Kimi Output Comparison or Averaging

| Ensemble Mode | Implementation | Use Case |  
|--------------|----------------|----------|  
| \*\*Parallel generation, selection\*\* | Both models receive identical prompt; evaluation function scores outputs; highest score wins | Standard optimization; quality prioritization |  
| \*\*Sequential fallback\*\* | Gemini first; if confidence \< threshold or validation fails, Kimi attempt | Cost optimization; speed prioritization |  
| \*\*A/B test of models\*\* | Gemini variant vs. Kimi variant as test arms | Meta-optimization; learning which model suits site |  
| \*\*Synthesis\*\* | Both outputs to third "judge" prompt: "Combine strengths of \[Gemini\] and \[Kimi\]" | Maximum creativity; hybrid approaches |

\*\*Evaluation function\*\* (for selection mode):

\`\`\`  
score \= (keyword\_presence \* 0.25) \+   
        (length\_compliance \* 0.20) \+   
        (emotional\_appeal\_score \* 0.20) \+   
        (differentiation\_from\_current \* 0.20) \+   
        (model\_confidence \* 0.15)  
\`\`\`

\#\#\#\# 2.3.3 Content-Aware Generation

\#\#\#\#\# 2.3.3.1 WordPress Content Fetch via REST API for Context

\*\*Content retrieval endpoint\*\*: \`GET /wp-json/wp/v2/posts/{post\_id}\`

| Field | Extraction | Processing |  
|-------|-----------|------------|  
| \`title.rendered\` | Current post title | Strip HTML; compare to SEO title |  
| \`content.rendered\` | Full post content | Strip HTML; truncate to context window |  
| \`excerpt.rendered\` | Manual or auto excerpt | Fallback if content too long |  
| \`yoast\_head\_json\` or \`rank\_math\_seo\` | Existing SEO metadata | Current optimization state |

\*\*Truncation strategy for long content\*\*: Preserve introduction (first 30%), conclusion (last 20%), and section headings (indicate structure); middle content summarized by word count and key entity extraction.

\#\#\#\#\# 2.3.3.2 Top-Ranking Competitor SERP Scraping (Bright Data Integration Pattern)

\*\*Bright Data SERP API integration\*\*:

| Parameter | Value | Description |  
|-----------|-------|-------------|  
| Engine | \`google\_search\` | Primary search engine |  
| Query | \`{{ target\_keyword }}\` | From GSC top query or content analysis |  
| Location | \`{{ target\_market }}\` | Geographic targeting (US, UK, etc.) |  
| Device | \`desktop\` or \`mobile\` | Match primary traffic source |  
| Results | 10 | First page analysis |

\*\*Competitive intelligence extraction\*\*:

| Element | Extraction | Prompt Integration |  
|---------|-----------|-------------------|  
| Top 3 titles | Exact text | "Competitors use: \[patterns\]" |  
| Common words | Frequency analysis | "Frequently appearing: \[words\]" |  
| Title structures | Pattern templates | "Successful structures: \[templates\]" |  
| Rich results presence | Featured snippets, etc. | "Opportunity: target \[rich result type\]" |  
| Description approaches | CTA patterns, formatting | "Description strategies: \[patterns\]" |

\#\#\#\#\# 2.3.3.3 Keyword Intent Classification: Informational vs. Transactional Optimization

| Intent Type | Indicators | Title Strategy | Example Patterns |  
|-------------|-----------|--------------|----------------|  
| \*\*Informational\*\* | "how to", "what is", "guide", "tutorial" | Educational promise; comprehensive coverage | "Complete Guide to...", "How to \[X\]: Step-by-Step", "Everything You Need to Know About..." |  
| \*\*Transactional\*\* | "buy", "price", "discount", "best", "review" | Value proposition; urgency; social proof | "Best \[Product\] 2024: Top Picks Reviewed", "\[X\]% Off \[Product\] — Limited Time", "Compare \[Product\] Prices & Save" |  
| \*\*Navigational\*\* | Brand names, specific URLs | Brand reinforcement; direct path | "\[Brand\] Official Site | \[Value Prop\]", "\[Product\] Login | Secure Access" |  
| \*\*Commercial Investigation\*\* | "vs", "compare", "alternative" | Balanced comparison; decision support | "\[A\] vs \[B\]: 2024 Comparison", "Best Alternatives to \[X\] (Tested)" |

\*\*Intent classification sources\*\*: GSC query report (majority query type), keyword research tools (intent labels), or AI classification of target keyword.

\#\#\# 2.4 Phase 4: A/B Test Execution & Tracking

\#\#\#\# 2.4.1 Test Initialization

\#\#\#\#\# 2.4.1.1 WordPress REST API Call: Store Test Title, Meta, Start Timestamp

\*\*Endpoint\*\*: \`POST /wp-json/gemini-kimi-seo/v1/initiate-test\`

| Request Field | Type | Description |  
|-------------|------|-------------|  
| \`post\_id\` | integer | WordPress post identifier |  
| \`test\_title\` | string | AI-generated title (50-60 chars) |  
| \`test\_description\` | string | AI-generated meta description (140-160 chars) |  
| \`ai\_model\` | enum | \`gemini\` / \`kimi\` / \`ensemble\` |  
| \`generation\_prompt\_hash\` | string | SHA-256 of prompt for reproducibility |  
| \`baseline\_metrics\` | object | \`{ctr, position, pageviews, impressions, date\_range}\` |  
| \`auth\_signature\` | string | HMAC-SHA256 of payload with shared secret |

\*\*WordPress processing\*\*: Validate signature → verify post exists and status eligible → acquire lock → update all meta fields atomically → return confirmation.

\#\#\#\#\# 2.4.1.2 Status Transition: \`Baseline\` → \`Testing\`

\*\*Atomic transaction\*\*:

\`\`\`sql  
START TRANSACTION;  
UPDATE wp\_postmeta SET meta\_value \= 'Testing'   
  WHERE post\_id \= {post\_id} AND meta\_key \= '\_seo\_ab\_test\_status';  
INSERT INTO wp\_postmeta (post\_id, meta\_key, meta\_value) VALUES  
  ({post\_id}, '\_seo\_ab\_test\_started', '{ISO\_8601\_timestamp}'),  
  ({post\_id}, '\_seo\_test\_title', '{test\_title}'),  
  ({post\_id}, '\_seo\_test\_description', '{test\_description}'),  
  ...;  
COMMIT;  
\`\`\`

\*\*Failure modes\*\*: Lock conflict (another test initiating) → return 409 Conflict with retry-after; validation failure → return 400 with specific error; database error → return 500, log for investigation, do not retry automatically.

\#\#\#\#\# 2.4.1.3 n8n Wait Node: 14-Day Pause or Scheduled Re-Check

| Implementation | Pattern | Pros | Cons |  
|---------------|---------|------|------|  
| \*\*Wait node\*\* | \`Wait\` node with 14-day duration | Simple; automatic resumption | Long-running execution; vulnerable to n8n restart |  
| \*\*Scheduled re-check\*\* | Store in database; daily scheduled workflow queries | Resilient; visible pending queue; manual early termination | More complex; requires external state store |

\*\*Recommended hybrid\*\*: Use scheduled re-check for production reliability, with Wait node acceptable for smaller deployments with execution persistence configured.

\#\#\#\# 2.4.2 Mid-Test Monitoring (Optional)

\#\#\#\#\# 2.4.2.1 Weekly Performance Snapshots

\*\*Snapshot schedule\*\*: Days 7 and 14 (conclusion) of test period.

| Captured Data | Purpose | Storage |  
|-------------|---------|---------|  
| Cumulative impressions, clicks, CTR | Trajectory analysis | \`\_seo\_test\_snapshots\` JSON array |  
| Daily position samples | Volatility assessment | Embedded in snapshot object |  
| Engagement metrics (GA4) | Quality validation | Referenced, not duplicated |  
| Week-over-week change rates | Early warning detection | Calculated field in snapshot |

\#\#\#\#\# 2.4.2.2 Early Termination Logic: Significant Negative Impact Detection

| Trigger Condition | Threshold | Action |  
|-----------------|-----------|--------|  
| CTR decline \>50% vs. baseline | Relative to pre-test | Alert \+ optional auto-terminate |  
| Position drop \>10 places | Absolute | Alert \+ mandatory review |  
| Zero impressions for 3+ days | Complete visibility loss | Auto-terminate, revert, investigate |  
| Negative engagement trend | Bounce rate \+50%, session duration \-50% | Alert, consider content mismatch |

\*\*Auto-termination workflow\*\*: Update status to \`Failed\` with \`termination\_reason\`; restore baseline metadata; notify admin; schedule post-mortem analysis.

\#\#\# 2.5 Phase 5: Results Analysis & Decision

\#\#\#\# 2.5.1 Metric Comparison Calculation

\#\#\#\#\# 2.5.1.1 Code Node JavaScript: CTR Improvement Percentage

\`\`\`javascript  
// Input: baseline\_metrics, test\_metrics objects  
// Output: ctr\_improvement\_pct, significance\_indicator

const baselineCtr \= parseFloat($json.baseline\_metrics.ctr);  
const testCtr \= parseFloat($json.test\_metrics.ctr);

let ctrImprovement \= null;  
let ctrSignificant \= false;

if (baselineCtr \> 0 && \!isNaN(baselineCtr) && \!isNaN(testCtr)) {  
    // Percentage change calculation  
    ctrImprovement \= ((testCtr \- baselineCtr) / baselineCtr) \* 100;  
      
    // Statistical significance (simplified; full implementation uses   
    // proper sample size and variance)  
    const baselineImpressions \= $json.baseline\_metrics.impressions;  
    const testImpressions \= $json.test\_metrics.impressions;  
    const pooledStdErr \= Math.sqrt(  
        (baselineCtr \* (1 \- baselineCtr) / baselineImpressions) \+  
        (testCtr \* (1 \- testCtr) / testImpressions)  
    );  
    const zScore \= Math.abs(testCtr \- baselineCtr) / pooledStdErr;  
    ctrSignificant \= zScore \> 1.96; // p \< 0.05  
}

return {  
    json: {  
        ctr\_improvement\_pct: ctrImprovement,  
        ctr\_significant: ctrSignificant,  
        ctr\_improvement\_absolute: testCtr \- baselineCtr  
    }  
};  
\`\`\`

\#\#\#\#\# 2.5.1.2 Code Node JavaScript: Ranking Improvement (Absolute)

\`\`\`javascript  
const baselineRank \= parseFloat($json.baseline\_metrics.position);  
const testRank \= parseFloat($json.test\_metrics.position);

let rankImprovement \= null;  
let rankImproved \= false;

if (\!isNaN(baselineRank) && \!isNaN(testRank)) {  
    // Note: lower position number \= better ranking  
    rankImprovement \= baselineRank \- testRank; // Positive \= improved  
    rankImproved \= rankImprovement \> 0;  
}

return {  
    json: {  
        rank\_improvement\_absolute: rankImprovement,  
        rank\_improved: rankImproved,  
        test\_position: testRank,  
        baseline\_position: baselineRank  
    }  
};  
\`\`\`

\#\#\#\#\# 2.5.1.3 Composite Score Calculation: Weighted Rank \+ CTR

\`\`\`javascript  
// Normalize metrics to 0-100 scale for combination  
const ctrScore \= Math.min(Math.max(  
    ($json.ctr\_improvement\_pct \+ 100\) / 2, 0), 100); // \-100% to \+100% → 0-100

const rankScore \= Math.min(Math.max(  
    $json.rank\_improvement\_absolute \* 10 \+ 50, 0), 100); // \-5 to \+5 positions → 0-100

// Weighted composite (configurable weights)  
const weights \= {  
    ctr: 0.6,      // CTR improvement weighted more heavily  
    rank: 0.4      // Ranking improvement secondary  
};

const compositeScore \= (ctrScore \* weights.ctr) \+ (rankScore \* weights.rank);

return {  
    json: {  
        composite\_score: compositeScore,  
        ctr\_component: ctrScore \* weights.ctr,  
        rank\_component: rankScore \* weights.rank,  
        recommendation: compositeScore \> 55 ? 'test\_wins' : 'baseline\_wins'  
        // 55 threshold: slight bias toward test to overcome status quo  
    }  
};  
\`\`\`

\#\#\#\# 2.5.2 Decision Logic Implementation

\#\#\#\#\# 2.5.2.1 Switch Node with Expression Mode: Multi-Condition Evaluation

| Condition | Expression | Outcome |  
|-----------|-----------|---------|  
| Rank improved AND CTR not significantly worse | \`rank\_improved && (\!ctr\_significant \\|\\| ctr\_improvement\_pct \> \-10)\` | \`test\_wins\` |  
| CTR significantly improved AND rank not significantly worse | \`ctr\_significant && ctr\_improvement\_pct \> 10 && rank\_improvement\_absolute \> \-2\` | \`test\_wins\` |  
| Both metrics neutral or degraded | Default case | \`baseline\_wins\` |

\#\#\#\#\# 2.5.2.2 Decision Rules

\*\*Primary decision framework\*\* (as specified in requirements):

| Scenario | Rule | Rationale |  
|----------|------|-----------|  
| \*\*Ranking improved\*\* (any amount) | Test wins | Visibility gain is fundamental; CTR can be optimized further |  
| \*\*Ranking same/degraded, CTR improved ≥ threshold\*\* (default 10%) | Test wins | Snippet optimization success; ranking may follow |  
| \*\*Both degraded or neutral\*\* | Baseline retained | Avoid harm; preserve proven performance |

\#\#\#\#\# 2.5.2.3 Tie-Breaker Logic: Traffic Volume, Conversion Rate Secondary Metrics

When primary metrics are inconclusive (e.g., CTR \+5% not significant, rank unchanged):

| Tie-Breaker | Threshold | Direction |  
|------------|-----------|-----------|  
| Traffic volume change | \+10% | Higher volume wins |  
| Engagement rate (GA4) | \+5% | Better engagement wins |  
| Conversion rate (if configured) | Any improvement | Higher conversion wins |  
| Model confidence | Original \>0.8 vs. test \>0.8 | Higher confidence wins |  
| Default | — | Retain baseline (status quo bias) |

\#\#\#\# 2.5.3 Finalization Actions

\#\#\#\#\# 2.5.3.1 Winning Version: WordPress REST API Call to Permanently Update Meta

\*\*Endpoint\*\*: \`POST /wp-json/gemini-kimi-seo/v1/update-meta\`

| Field | Value (Test Wins) | Value (Baseline Wins) |  
|-------|-------------------|----------------------|  
| \`decision\` | \`"test\_wins"\` | \`"baseline\_wins"\` |  
| \`final\_title\` | \`\_seo\_test\_title\` | Original baseline title |  
| \`final\_description\` | \`\_seo\_test\_description\` | Original baseline description |  
| \`status\_transition\` | \`"Testing"\` → \`"Optimized"\` | \`"Testing"\` → \`"Baseline"\` |  
| \`archive\_entry\` | Full test record | Full test record |

\#\#\#\#\# 2.5.3.2 Losing Version: Discard, Log Results, Retain Baseline

\*\*Archive structure in \`\_seo\_test\_history\`\*\*:

\`\`\`json  
{  
  "version": 3,  
  "initiated": "2024-01-15T08:30:00Z",  
  "completed": "2024-01-29T08:30:00Z",  
  "ai\_model": "gemini-2.0-flash",  
  "test\_title": "...",  
  "test\_description": "...",  
  "baseline\_metrics": {"ctr": 0.032, "position": 8.5, ...},  
  "test\_metrics": {"ctr": 0.041, "position": 7.2, ...},  
  "improvement": {"ctr\_pct": 28.1, "position\_abs": 1.3},  
  "winner": "test",  
  "decision\_factors": \["rank\_improved", "ctr\_significant"\]  
}  
\`\`\`

\#\#\#\#\# 2.5.3.3 Status Transition: \`Testing\` → \`Optimized\` or \`Baseline\`

| Transition | Next Actions | Cooldown Period |  
|-----------|------------|---------------|  
| \`Testing\` → \`Optimized\` | Update live SEO meta; clear test fields; notify success | 30 days before re-optimization eligible |  
| \`Testing\` → \`Baseline\` | Retain existing meta; clear test fields; analyze failure | 14 days before retry eligible |  
| \`Testing\` → \`Failed\` (error) | Manual review required; preserve state for debugging | Indefinite until manual reset |

\#\# 3\. Database/State Logic

\#\#\# 3.1 State Machine Design

\#\#\#\# 3.1.1 Status Values: \`Baseline\` | \`Testing\` | \`Optimized\` | \`Failed\`

| Status | Visual Indicator | Description | Permissible Transitions |  
|--------|---------------|-------------|------------------------|  
| \`Baseline\` | ⬜ Gray circle | No active test; original or previously optimized metadata | → \`Testing\` (manual or automated trigger) |  
| \`Testing\` | 🟡 Yellow circle | 14-day A/B test in progress; AI variant deployed | → \`Optimized\` (test wins), → \`Baseline\` (baseline wins), → \`Failed\` (error) |  
| \`Optimized\` | 🟢 Green circle | Previous test successful; AI variant permanently deployed | → \`Testing\` (after cooldown, if degradation detected) |  
| \`Failed\` | 🔴 Red circle | Test error or early termination; requires manual review | → \`Testing\` (manual override after investigation) |

\#\#\#\# 3.1.2 State Transitions & Validity Rules

\*\*Transition matrix\*\*:

| From \\ To | Baseline | Testing | Optimized | Failed |  
|-----------|----------|---------|-----------|--------|  
| \*\*Baseline\*\* | — | ✓ (initiate) | ✗ | ✗ |  
| \*\*Testing\*\* | ✓ (baseline wins) | ✗ | ✓ (test wins) | ✓ (error) |  
| \*\*Optimized\*\* | ✗ | ✓ (re-optimize) | — | ✗ |  
| \*\*Failed\*\* | ✗ | ✓ (retry) | ✗ | — |

\*\*Invalid transition handling\*\*: Return 400 Bad Request with \`invalid\_state\_transition\` error code; log attempted transition for security monitoring; notify admin of potential system misuse.

\#\#\#\# 3.1.3 Concurrent Test Prevention: Lock Mechanism per Post ID

\*\*Lock implementation\*\*:

| Mechanism | Implementation | Timeout |  
|-----------|---------------|---------|  
| Database-level | \`SELECT ... FOR UPDATE\` on status check | Transaction duration |  
| Application-level | \`wp\_cache\_set("seo\_lock\_{post\_id}", true, 86400)\` | 24 hours |  
| Fallback | Timestamp in \`\_seo\_ab\_test\_started\` \+ 14 days \+ 2 day grace | Manual override |

\*\*Lock acquisition flow\*\*:  
1\. Read current status  
2\. If \`Testing\`, check if lock expired (start \+ 16 days)  
3\. If expired, auto-release and proceed; if valid, reject with 409  
4\. If \`Baseline\` or \`Failed\`, attempt atomic update to \`Testing\`  
5\. On successful update, proceed; on conflict, another process acquired lock first

\#\#\# 3.2 WordPress Post Meta Schema

\#\#\#\# 3.2.1 Core State Fields

| Meta Key | Type | Example | Purpose |  
|----------|------|---------|---------|  
| \`\_seo\_ab\_test\_status\` | string | \`"Testing"\` | Current state machine position |  
| \`\_seo\_ab\_test\_started\` | ISO 8601 datetime | \`"2024-01-15T08:30:00Z"\` | Test initiation timestamp; lock timeout basis |  
| \`\_seo\_ab\_test\_version\` | integer | \`3\` | Incremental counter; history indexing |

\#\#\#\# 3.2.2 Baseline Performance Snapshot

| Meta Key | Type | Example | Statistical Use |  
|----------|------|---------|---------------|  
| \`\_seo\_baseline\_ctr\` | float (0-1) | \`0.032\` | Primary comparison metric |  
| \`\_seo\_baseline\_ctr\_std\` | float | \`0.008\` | Significance weighting |  
| \`\_seo\_baseline\_position\` | float | \`8.5\` | Secondary comparison metric |  
| \`\_seo\_baseline\_position\_std\` | float | \`1.2\` | Stability assessment |  
| \`\_seo\_baseline\_pageviews\` | integer | \`1523\` | Sample size validation |  
| \`\_seo\_baseline\_impressions\` | integer | \`3400\` | GSC sample size |  
| \`\_seo\_baseline\_date\_range\` | ISO period | \`"2024-01-01/2024-01-14"\` | Reproducibility; audit |

\#\#\#\# 3.2.3 Test Variant Storage

| Meta Key | Type | Example | Traceability |  
|----------|------|---------|------------|  
| \`\_seo\_test\_title\` | string (60) | \`"Complete Guide to..."\` | Variant under test |  
| \`\_seo\_test\_description\` | string (160) | \`"Learn everything..."\` | Variant under test |  
| \`\_seo\_test\_ai\_model\` | enum | \`"gemini"\` | Source attribution |  
| \`\_seo\_test\_generation\_prompt\_hash\` | string (64) | \`"a3f7c2..."\` | Reproducibility; debugging |

\#\#\#\# 3.2.4 Results Archive (Historical)

| Meta Key | Type | Structure | Retention |  
|----------|------|-----------|-----------|  
| \`\_seo\_test\_history\` | JSON array | \`\[{version, date, title, metrics\_before, metrics\_after, winner, improvement\_pct}\]\` | Last 10 tests; older archived to separate table |

\#\#\# 3.3 n8n Internal State Management

\#\#\#\# 3.3.1 Execution Store: Active Test Tracking Table/Database

For \*\*scheduled re-check pattern\*\* (recommended), external state storage tracks pending tests:

| Field | Type | Purpose |  
|-------|------|---------|  
| \`test\_id\` | UUID | Unique identifier; n8n execution correlation |  
| \`post\_id\` | integer | WordPress reference |  
| \`site\_url\` | string | Multi-site routing |  
| \`scheduled\_date\` | date | Conclusion evaluation date |  
| \`status\` | enum | \`pending\`, \`processing\`, \`completed\`, \`error\` |  
| \`n8n\_execution\_id\` | string | Debug and retry correlation |

\#\#\#\# 3.3.2 Scheduled Trigger Configuration: Cron-Based Daily Evaluation

| Environment | Schedule | Scope |  
|-------------|----------|-------|  
| Development | Manual trigger only | Single post testing |  
| Staging | Weekly, Sunday 02:00 UTC | Limited post set; full workflow validation |  
| Production | Daily, 02:00 UTC | All eligible posts; priority-queued processing |

\#\#\#\# 3.3.3 Error Handling & Retry Logic: Failed Webhook Recovery

| Failure Type | Retry Strategy | Escalation |  
|-------------|--------------|------------|  
| Transient network error | 3 retries, exponential backoff (1s, 5s, 25s) | Alert after final failure |  
| WordPress 5xx error | 3 retries; circuit breaker after 5 consecutive | Degrade to manual queue |  
| Authentication failure | No retry; immediate alert | Credential rotation procedure |  
| Validation error (4xx) | No retry; log and alert | Manual investigation |

\#\# 4\. WordPress REST API Implementation

\#\#\# 4.1 Custom Plugin Architecture

\#\#\#\# 4.1.1 Plugin Header & Namespace: \`gemini-kimi-seo-optimizer\`

\`\`\`php  
\<?php  
/\*\*  
 \* Plugin Name: Gemini-Kimi SEO Optimizer  
 \* Description: Automated SEO A/B testing with n8n orchestration and AI-powered title/meta generation  
 \* Version: 1.0.0  
 \* Author: \[Your Organization\]  
 \* Requires PHP: 7.4  
 \* Requires WP: 5.8  
 \*/

// Prevent direct access  
if (\!defined('ABSPATH')) {  
    exit;  
}

// Define constants  
define('GKSO\_VERSION', '1.0.0');  
define('GKSO\_PLUGIN\_DIR', plugin\_dir\_path(\_\_FILE\_\_));  
define('GKSO\_PLUGIN\_URL', plugin\_dir\_url(\_\_FILE\_\_));  
define('GKSO\_REST\_NAMESPACE', 'gemini-kimi-seo/v1');  
\`\`\`

\#\#\#\# 4.1.2 REST API Namespace: \`gemini-kimi-seo/v1\`

All endpoints registered under this namespace with consistent versioning strategy.

\#\#\#\# 4.1.3 Capability Checks: \`manage\_options\` or Custom \`seo\_optimize\` Capability

\`\`\`php  
// Register custom capability on plugin activation  
register\_activation\_hook(\_\_FILE\_\_, 'gkso\_register\_capabilities');

function gkso\_register\_capabilities() {  
    $role \= get\_role('administrator');  
    if ($role) {  
        $role-\>add\_cap('seo\_optimize');  
        $role-\>add\_cap('seo\_view\_tests');  
    }  
      
    // Add to editor role with option  
    if (get\_option('gkso\_editor\_access', false)) {  
        $editor \= get\_role('editor');  
        if ($editor) {  
            $editor-\>add\_cap('seo\_view\_tests');  
        }  
    }  
}  
\`\`\`

\#\#\# 4.2 Endpoint: Trigger n8n Workflow

\#\#\#\# 4.2.1 Route: \`POST /gemini-kimi-seo/v1/initiate-test\`

\`\`\`php  
add\_action('rest\_api\_init', function () {  
    register\_rest\_route(GKSO\_REST\_NAMESPACE, '/initiate-test', \[  
        'methods' \=\> 'POST',  
        'callback' \=\> 'gkso\_rest\_initiate\_test',  
        'permission\_callback' \=\> function () {  
            return current\_user\_can('seo\_optimize');  
        },  
        'args' \=\> \[  
            'post\_id' \=\> \[  
                'required' \=\> true,  
                'type' \=\> 'integer',  
                'validate\_callback' \=\> function($param, $request, $key) {  
                    return get\_post($param) \!== null;  
                }  
            \],  
            'manual\_override' \=\> \[  
                'required' \=\> false,  
                'type' \=\> 'boolean',  
                'default' \=\> false  
            \],  
            'priority' \=\> \[  
                'required' \=\> false,  
                'type' \=\> 'string',  
                'enum' \=\> \['normal', 'high'\],  
                'default' \=\> 'normal'  
            \]  
        \]  
    \]);  
});  
\`\`\`

\#\#\#\# 4.2.2 Request Parameters: \`post\_id\`, \`manual\_override\` (optional)

| Parameter | Required | Type | Description |  
|-----------|----------|------|-------------|  
| \`post\_id\` | Yes | integer | WordPress post to optimize |  
| \`manual\_override\` | No | boolean | Bypass threshold requirements |  
| \`priority\` | No | string | \`normal\` or \`high\` for queue prioritization |  
| \`preferred\_model\` | No | string | \`gemini\`, \`kimi\`, or \`auto\` |

\#\#\#\# 4.2.3 Validation: Post Exists, Status Allows Test, No Active Lock

\`\`\`php  
function gkso\_rest\_initiate\_test(WP\_REST\_Request $request) {  
    $post\_id \= $request-\>get\_param('post\_id');  
    $manual\_override \= $request-\>get\_param('manual\_override');  
      
    // Post existence validated by args, but double-check  
    $post \= get\_post($post\_id);  
    if (\!$post || $post-\>post\_status \!== 'publish') {  
        return new WP\_Error(  
            'invalid\_post',  
            'Post must be published to initiate SEO test',  
            \['status' \=\> 400\]  
        );  
    }  
      
    // Check current status  
    $current\_status \= get\_post\_meta($post\_id, '\_seo\_ab\_test\_status', true) ?: 'Baseline';  
      
    if ($current\_status \=== 'Testing') {  
        // Check for lock expiration  
        $started \= get\_post\_meta($post\_id, '\_seo\_ab\_test\_started', true);  
        if ($started) {  
            $start\_time \= strtotime($started);  
            $max\_duration \= 16 \* DAY\_IN\_SECONDS; // 14 days \+ 2 day grace  
            if (time() \- $start\_time \< $max\_duration) {  
                return new WP\_Error(  
                    'test\_in\_progress',  
                    'A test is already active for this post',  
                    \[  
                        'status' \=\> 409,  
                        'started' \=\> $started,  
                        'estimated\_completion' \=\> date('c', $start\_time \+ 14 \* DAY\_IN\_SECONDS)  
                    \]  
                );  
            }  
            // Lock expired, auto-release and continue  
            gkso\_release\_test\_lock($post\_id);  
        }  
    }  
      
    if (\!in\_array($current\_status, \['Baseline', 'Failed', 'Optimized'\], true)) {  
        return new WP\_Error(  
            'invalid\_status',  
            "Cannot initiate test from status: {$current\_status}",  
            \['status' \=\> 400\]  
        );  
    }  
      
    // Cooldown check for recent tests  
    if (\!$manual\_override && $current\_status \=== 'Optimized') {  
        $last\_test \= gkso\_get\_last\_test\_date($post\_id);  
        if ($last\_test && (time() \- strtotime($last\_test)) \< 30 \* DAY\_IN\_SECONDS) {  
            return new WP\_Error(  
                'cooldown\_active',  
                'Minimum 30 days between optimizations. Use manual\_override to bypass.',  
                \['status' \=\> 429, 'retry\_after' \=\> 30 \* DAY\_IN\_SECONDS \- (time() \- strtotime($last\_test))\]  
            );  
        }  
    }  
      
    // All validations passed, proceed to webhook dispatch  
    return gkso\_dispatch\_n8n\_webhook($post\_id, $request-\>get\_params());  
}  
\`\`\`

\#\#\#\# 4.2.4 Webhook Dispatch: \`wp\_remote\_post()\` to n8n Webhook URL

\`\`\`php  
function gkso\_dispatch\_n8n\_webhook($post\_id, $params) {  
    $webhook\_url \= get\_option('gkso\_n8n\_webhook\_url');  
    if (\!$webhook\_url) {  
        return new WP\_Error(  
            'configuration\_error',  
            'n8n webhook URL not configured',  
            \['status' \=\> 500\]  
        );  
    }  
      
    $post \= get\_post($post\_id);  
    $current\_seo \= gkso\_get\_current\_seo\_meta($post\_id);  
      
    $payload \= \[  
        'post\_id' \=\> $post\_id,  
        'site\_url' \=\> get\_site\_url(),  
        'post\_url' \=\> get\_permalink($post\_id),  
        'post\_title' \=\> $post-\>post\_title,  
        'post\_excerpt' \=\> gkso\_get\_content\_excerpt($post),  
        'current\_seo' \=\> $current\_seo,  
        'trigger\_type' \=\> $params\['manual\_override'\] ? 'manual' : 'scheduled',  
        'priority' \=\> $params\['priority'\],  
        'preferred\_model' \=\> $params\['preferred\_model'\] ?? 'auto',  
        'webhook\_timestamp' \=\> current\_time('c'),  
        'callback\_url' \=\> rest\_url(GKSO\_REST\_NAMESPACE . '/update-meta'),  
        'status\_url' \=\> rest\_url(GKSO\_REST\_NAMESPACE . '/test-status/' . $post\_id)  
    \];  
      
    // Sign payload for verification  
    $payload\['signature'\] \= gkso\_sign\_payload($payload);  
      
    $response \= wp\_remote\_post($webhook\_url, \[  
        'timeout' \=\> 30,  
        'headers' \=\> \['Content-Type' \=\> 'application/json'\],  
        'body' \=\> json\_encode($payload),  
        'data\_format' \=\> 'body'  
    \]);  
      
    if (is\_wp\_error($response)) {  
        return new WP\_Error(  
            'webhook\_failed',  
            'Failed to dispatch to n8n: ' . $response-\>get\_error\_message(),  
            \['status' \=\> 502\]  
        );  
    }  
      
    $response\_code \= wp\_remote\_retrieve\_response\_code($response);  
    if ($response\_code \!== 200 && $response\_code \!== 202\) {  
        return new WP\_Error(  
            'webhook\_rejected',  
            'n8n returned error: ' . wp\_remote\_retrieve\_body($response),  
            \['status' \=\> 502, 'n8n\_status' \=\> $response\_code\]  
        );  
    }  
      
    // Parse n8n acknowledgment  
    $body \= json\_decode(wp\_remote\_retrieve\_body($response), true);  
    $test\_id \= $body\['test\_id'\] ?? wp\_generate\_uuid4();  
      
    // Update status optimistically (will be confirmed by n8n callback)  
    update\_post\_meta($post\_id, '\_seo\_ab\_test\_status', 'Testing');  
    update\_post\_meta($post\_id, '\_seo\_ab\_test\_started', current\_time('c'));  
    update\_post\_meta($post\_id, '\_seo\_ab\_test\_version',   
        (int) get\_post\_meta($post\_id, '\_seo\_ab\_test\_version', true) \+ 1);  
    update\_post\_meta($post\_id, '\_seo\_test\_id', $test\_id);  
      
    return new WP\_REST\_Response(\[  
        'test\_id' \=\> $test\_id,  
        'status' \=\> 'initiated',  
        'estimated\_completion' \=\> date('c', time() \+ 14 \* DAY\_IN\_SECONDS),  
        'status\_url' \=\> rest\_url(GKSO\_REST\_NAMESPACE . '/test-status/' . $post\_id)  
    \], 202);  
}  
\`\`\`

\#\#\#\# 4.2.5 Response: \`test\_id\`, \`estimated\_completion\`, \`status\_url\`

| Field | Type | Description |  
|-------|------|-------------|  
| \`test\_id\` | UUID | Unique identifier for this test instance |  
| \`status\` | string | \`initiated\` (pending n8n confirmation) |  
| \`estimated\_completion\` | ISO 8601 datetime | 14 days from initiation |  
| \`status\_url\` | string | Polling endpoint for current status |

\#\#\# 4.3 Endpoint: Receive n8n Decisions

\#\#\#\# 4.3.1 Route: \`POST /gemini-kimi-seo/v1/update-meta\`

\`\`\`php  
register\_rest\_route(GKSO\_REST\_NAMESPACE, '/update-meta', \[  
    'methods' \=\> 'POST',  
    'callback' \=\> 'gkso\_rest\_update\_meta',  
    'permission\_callback' \=\> 'gkso\_verify\_n8n\_signature', // HMAC validation  
    'args' \=\> \[  
        'post\_id' \=\> \['required' \=\> true, 'type' \=\> 'integer'\],  
        'decision' \=\> \['required' \=\> true, 'type' \=\> 'string', 'enum' \=\> \['test\_wins', 'baseline\_wins'\]\],  
        'final\_title' \=\> \['required' \=\> true, 'type' \=\> 'string'\],  
        'final\_description' \=\> \['required' \=\> true, 'type' \=\> 'string'\],  
        'test\_metrics' \=\> \['required' \=\> true, 'type' \=\> 'object'\],  
        'baseline\_metrics' \=\> \['required' \=\> true, 'type' \=\> 'object'\],  
        'improvement' \=\> \['required' \=\> true, 'type' \=\> 'object'\]  
    \]  
\]);  
\`\`\`

\#\#\#\# 4.3.2 Authentication: HMAC Signature or Shared Secret Validation

\`\`\`php  
function gkso\_verify\_n8n\_signature(WP\_REST\_Request $request) {  
    $signature \= $request-\>get\_header('X-GKSO-Signature');  
    if (\!$signature) {  
        return new WP\_Error(  
            'missing\_signature',  
            'Request signature required',  
            \['status' \=\> 401\]  
        );  
    }  
      
    $body \= $request-\>get\_body();  
    $expected \= hash\_hmac('sha256', $body, get\_option('gkso\_shared\_secret'));  
      
    if (\!hash\_equals($expected, $signature)) {  
        // Log potential attack for security monitoring  
        do\_action('gkso\_signature\_verification\_failed', $request);  
        return new WP\_Error(  
            'invalid\_signature',  
            'Signature verification failed',  
            \['status' \=\> 403\]  
        );  
    }  
      
    return true;  
}  
\`\`\`

\#\#\#\# 4.3.3 Request Body Schema

\`\`\`json  
{  
  "post\_id": 123,  
  "decision": "test\_wins|baseline\_wins",  
  "final\_title": "Optimized Title for Maximum CTR",  
  "final\_description": "Compelling meta description that drives clicks...",  
  "test\_metrics": {  
    "ctr": 0.045,  
    "position": 6.2,  
    "impressions": 5200,  
    "clicks": 234,  
    "engagement\_rate": 0.72  
  },  
  "baseline\_metrics": {  
    "ctr": 0.032,  
    "position": 8.5,  
    "impressions": 3400,  
    "clicks": 109,  
    "engagement\_rate": 0.68  
  },  
  "improvement": {  
    "ctr\_pct": 40.6,  
    "position\_abs": 2.3,  
    "composite\_score": 67.5  
  },  
  "test\_metadata": {  
    "ai\_model": "gemini-2.0-flash",  
    "generation\_prompt\_hash": "a3f7c2...",  
    "test\_duration\_days": 14  
  }  
}  
\`\`\`

\#\#\#\# 4.3.4 Yoast SEO Integration

\#\#\#\#\# 4.3.4.1 Meta Key Mapping: \`\_yoast\_wpseo\_title\`, \`\_yoast\_wpseo\_metadesc\`

\`\`\`php  
function gkso\_update\_yoast\_meta($post\_id, $title, $description) {  
    // Yoast SEO stores in postmeta with specific keys  
    update\_post\_meta($post\_id, '\_yoast\_wpseo\_title', sanitize\_text\_field($title));  
    update\_post\_meta($post\_id, '\_yoast\_wpseo\_metadesc', sanitize\_textarea\_field($description));  
      
    // Clear Yoast cache to ensure immediate effect  
    if (function\_exists('YoastSEO')) {  
        YoastSEO()-\>helpers-\>indexable-\>invalidate($post\_id);  
    }  
      
    // Trigger sitemap update if configured  
    do\_action('gkso\_yoast\_updated', $post\_id, $title, $description);  
}  
\`\`\`

\#\#\#\#\# 4.3.4.2 Update via \`update\_post\_meta()\` with Sanitization

\*\*Sanitization pipeline\*\*:

| Input | Function | Output Constraint |  
|-------|----------|-----------------|  
| Title | \`sanitize\_text\_field()\` | No HTML; 60 char soft limit, 70 hard limit |  
| Description | \`sanitize\_textarea\_field()\` | No HTML; 160 char soft limit, 320 hard limit |  
| Both | \`wp\_kses()\` with empty allowed tags | Strip all markup |

\#\#\#\# 4.3.5 Rank Math Integration

\#\#\#\#\# 4.3.5.1 Meta Key Mapping: \`rank\_math\_title\`, \`rank\_math\_description\`

\`\`\`php  
function gkso\_update\_rank\_math\_meta($post\_id, $title, $description) {  
    // Rank Math uses different meta key pattern  
    update\_post\_meta($post\_id, 'rank\_math\_title', sanitize\_text\_field($title));  
    update\_post\_meta($post\_id, 'rank\_math\_description', sanitize\_textarea\_field($description));  
      
    // Clear Rank Math cache  
    if (class\_exists('RankMath\\Helper')) {  
        \\RankMath\\Helper::invalidate\_object($post\_id, 'post');  
    }  
      
    do\_action('gkso\_rank\_math\_updated', $post\_id, $title, $description);  
}  
\`\`\`

\#\#\#\#\# 4.3.5.2 Rank Math API Hook Usage (if available)

Rank Math provides \`rank\_math/seo\_score\` filters and \`rank\_math\_metadata\` hooks for advanced integration; use where available for cache-optimized updates.

\#\#\#\# 4.3.6 Generic Fallback: Direct \`wp\_postmeta\` for Custom SEO Plugins

\`\`\`php  
function gkso\_update\_generic\_seo\_meta($post\_id, $title, $description) {  
    // Standardized fallback keys for unknown SEO plugins  
    update\_post\_meta($post\_id, '\_seo\_title', sanitize\_text\_field($title));  
    update\_post\_meta($post\_id, '\_seo\_description', sanitize\_textarea\_field($description));  
      
    // Allow other plugins to hook and sync  
    do\_action('gkso\_seo\_meta\_updated', $post\_id, $title, $description, 'generic');  
}  
\`\`\`

\*\*Plugin detection and routing\*\*:

\`\`\`php  
function gkso\_detect\_seo\_plugin() {  
    if (defined('WPSEO\_VERSION')) {  
        return 'yoast';  
    }  
    if (class\_exists('RankMath')) {  
        return 'rank\_math';  
    }  
    if (function\_exists('aioseo')) {  
        return 'aioseo';  
    }  
    return 'generic';  
}

function gkso\_update\_seo\_meta($post\_id, $title, $description) {  
    $plugin \= gkso\_detect\_seo\_plugin();  
    $function \= "gkso\_update\_{$plugin}\_meta";  
    if (function\_exists($function)) {  
        return $function($post\_id, $title, $description);  
    }  
    return gkso\_update\_generic\_seo\_meta($post\_id, $title, $description);  
}  
\`\`\`

\#\#\# 4.4 Endpoint: Query Test Status

\#\#\#\# 4.4.1 Route: \`GET /gemini-kimi-seo/v1/test-status/{post\_id}\`

\`\`\`php  
register\_rest\_route(GKSO\_REST\_NAMESPACE, '/test-status/(?P\<post\_id\>\\d+)', \[  
    'methods' \=\> 'GET',  
    'callback' \=\> 'gkso\_rest\_test\_status',  
    'permission\_callback' \=\> function ($request) {  
        $post\_id \= $request-\>get\_param('post\_id');  
        return current\_user\_can('edit\_post', $post\_id) ||   
               current\_user\_can('seo\_view\_tests');  
    }  
\]);  
\`\`\`

\#\#\#\# 4.4.2 Response: Current status, progress percentage, elapsed time, preliminary metrics

\`\`\`php  
function gkso\_rest\_test\_status(WP\_REST\_Request $request) {  
    $post\_id \= $request-\>get\_param('post\_id');  
      
    $status \= get\_post\_meta($post\_id, '\_seo\_ab\_test\_status', true) ?: 'Baseline';  
    $started \= get\_post\_meta($post\_id, '\_seo\_ab\_test\_started', true);  
    $version \= (int) get\_post\_meta($post\_id, '\_seo\_ab\_test\_version', true);  
      
    $response \= \[  
        'post\_id' \=\> $post\_id,  
        'status' \=\> $status,  
        'version' \=\> $version,  
        'history\_count' \=\> count(gkso\_get\_test\_history($post\_id))  
    \];  
      
    if ($status \=== 'Testing' && $started) {  
        $start\_time \= strtotime($started);  
        $elapsed \= time() \- $start\_time;  
        $total \= 14 \* DAY\_IN\_SECONDS;  
        $progress \= min(100, round(($elapsed / $total) \* 100, 1));  
          
        $response\['testing'\] \= \[  
            'started' \=\> $started,  
            'elapsed\_days' \=\> floor($elapsed / DAY\_IN\_SECONDS),  
            'progress\_percent' \=\> $progress,  
            'estimated\_completion' \=\> date('c', $start\_time \+ $total),  
            'test\_title' \=\> get\_post\_meta($post\_id, '\_seo\_test\_title', true),  
            'ai\_model' \=\> get\_post\_meta($post\_id, '\_seo\_test\_ai\_model', true)  
        \];  
          
        // Include preliminary metrics if available (from mid-test snapshots)  
        $snapshots \= get\_post\_meta($post\_id, '\_seo\_test\_snapshots', true);  
        if ($snapshots) {  
            $response\['testing'\]\['latest\_snapshot'\] \= end($snapshots);  
        }  
    }  
      
    if (in\_array($status, \['Optimized', 'Failed'\], true)) {  
        $last\_test \= gkso\_get\_last\_test\_record($post\_id);  
        if ($last\_test) {  
            $response\['last\_test'\] \= $last\_test;  
        }  
    }  
      
    return new WP\_REST\_Response($response, 200);  
}  
\`\`\`

\#\#\# 4.5 Admin Interface: Post Edit Screen Integration

\#\#\#\# 4.5.1 Custom Meta Box Registration: \`add\_meta\_box()\`

\`\`\`php  
add\_action('add\_meta\_boxes', 'gkso\_register\_meta\_boxes');

function gkso\_register\_meta\_boxes() {  
    $post\_types \= get\_option('gkso\_enabled\_post\_types', \['post', 'page'\]);  
      
    foreach ($post\_types as $post\_type) {  
        add\_meta\_box(  
            'gkso\_status\_box',  
            \_\_('SEO Optimization Status', 'gemini-kimi-seo'),  
            'gkso\_render\_status\_box',  
            $post\_type,  
            'side',  
            'high'  
        );  
    }  
}  
\`\`\`

\#\#\#\# 4.5.2 Display Components

\#\#\#\#\# 4.5.2.1 Current Status Badge: Color-coded

\`\`\`php  
function gkso\_render\_status\_box($post) {  
    $status \= get\_post\_meta($post-\>ID, '\_seo\_ab\_test\_status', true) ?: 'Baseline';  
    $status\_config \= \[  
        'Baseline' \=\> \['color' \=\> '\#6c757d', 'label' \=\> \_\_('Baseline', 'gemini-kimi-seo'), 'icon' \=\> '⬜'\],  
        'Testing' \=\> \['color' \=\> '\#ffc107', 'label' \=\> \_\_('Testing', 'gemini-kimi-seo'), 'icon' \=\> '🟡'\],  
        'Optimized' \=\> \['color' \=\> '\#28a745', 'label' \=\> \_\_('Optimized', 'gemini-kimi-seo'), 'icon' \=\> '🟢'\],  
        'Failed' \=\> \['color' \=\> '\#dc3545', 'label' \=\> \_\_('Failed', 'gemini-kimi-seo'), 'icon' \=\> '🔴'\]  
    \];  
    $config \= $status\_config\[$status\] ?? $status\_config\['Baseline'\];  
      
    wp\_nonce\_field('gkso\_meta\_box', 'gkso\_meta\_box\_nonce');  
    ?\>  
    \<div class="gkso-status-box" style="padding: 10px; background: \<?= esc\_attr($config\['color'\]) ?\>20; border-left: 4px solid \<?= esc\_attr($config\['color'\]) ?\>;"\>  
        \<h4 style="margin: 0 0 10px; color: \<?= esc\_attr($config\['color'\]) ?\>;"\>  
            \<?= $config\['icon'\] ?\> \<?= esc\_html($config\['label'\]) ?\>  
        \</h4\>  
          
        \<?php if ($status \=== 'Testing'): ?\>  
            \<?= gkso\_render\_testing\_status($post-\>ID); ?\>  
        \<?php elseif ($status \=== 'Optimized'): ?\>  
            \<?= gkso\_render\_optimized\_status($post-\>ID); ?\>  
        \<?php elseif ($status \=== 'Failed'): ?\>  
            \<?= gkso\_render\_failed\_status($post-\>ID); ?\>  
        \<?php else: ?\>  
            \<?= gkso\_render\_baseline\_actions($post-\>ID); ?\>  
        \<?php endif; ?\>  
    \</div\>  
      
    \<?= gkso\_render\_history\_table($post-\>ID); ?\>  
    \<?php  
}  
\`\`\`

\#\#\#\#\# 4.5.2.2 Performance Mini-Chart: Sparkline of CTR/Position Trend

\`\`\`php  
function gkso\_render\_sparkline($post\_id) {  
    $history \= gkso\_get\_test\_history($post\_id);  
    if (count($history) \< 2\) return '';  
      
    $data\_points \= array\_slice($history, \-6); // Last 6 records  
    $ctr\_values \= array\_column($data\_points, 'ctr');  
    $position\_values \= array\_column($data\_points, 'position');  
      
    // Simple SVG sparkline  
    $width \= 200;  
    $height \= 40;  
    $max\_ctr \= max($ctr\_values) \* 1.2;  
    $min\_pos \= min($position\_values) \* 0.8;  
    $max\_pos \= max($position\_values) \* 1.2;  
      
    $points \= \[\];  
    foreach ($data\_points as $i \=\> $record) {  
        $x \= ($i / (count($data\_points) \- 1)) \* $width;  
        $y\_ctr \= $height \- (($record\['ctr'\] / $max\_ctr) \* $height);  
        $points\[\] \= "$x,$y\_ctr";  
    }  
      
    return '\<svg width="' . $width . '" height="' . $height . '" class="gkso-sparkline"\>  
        \<polyline fill="none" stroke="\#0073aa" stroke-width="2" points="' . implode(' ', $points) . '"/\>  
    \</svg\>';  
}  
\`\`\`

\#\#\#\#\# 4.5.2.3 Test History Table: Previous optimizations with improvement metrics

| Version | Date | Title | CTR Before | CTR After | Improvement | Winner |  
|---------|------|-------|-----------|-----------|-------------|--------|  
| 3 | Jan 29, 2024 | "Complete Guide..." | 3.2% | 4.5% | \+40.6% | 🟢 Test |  
| 2 | Dec 15, 2023 | "Ultimate Resource..." | 2.8% | 3.1% | \+10.7% | ⬜ Baseline |  
| 1 | Nov 01, 2023 | "How to..." | — | 2.8% | — | 🟢 Test |

\#\#\#\#\# 4.5.2.4 Action Buttons: "Start New Test" (if eligible), "View Details", "Force Revert"

\`\`\`php  
function gkso\_render\_baseline\_actions($post\_id) {  
    $eligible \= gkso\_is\_eligible\_for\_test($post\_id);  
    ?\>  
    \<p\>  
        \<?php if ($eligible\['eligible'\]): ?\>  
            \<button type="button" class="button button-primary gkso-start-test"   
                    data-post-id="\<?= $post\_id ?\>"\>  
                \<?= \_\_('Start SEO Test', 'gemini-kimi-seo') ?\>  
            \</button\>  
        \<?php else: ?\>  
            \<button type="button" class="button" disabled   
                    title="\<?= esc\_attr($eligible\['reason'\]) ?\>"\>  
                \<?= \_\_('Start SEO Test', 'gemini-kimi-seo') ?\>  
            \</button\>  
            \<p class="description"\>\<?= esc\_html($eligible\['reason'\]) ?\>\</p\>  
        \<?php endif; ?\>  
    \</p\>  
    \<?php  
}

function gkso\_render\_testing\_actions($post\_id) {  
    ?\>  
    \<p\>  
        \<button type="button" class="button gkso-view-details"   
                data-post-id="\<?= $post\_id ?\>"\>  
            \<?= \_\_('View Test Details', 'gemini-kimi-seo') ?\>  
        \</button\>  
        \<button type="button" class="button gkso-early-terminate"   
                data-post-id="\<?= $post\_id ?\>" style="color: \#dc3545;"\>  
            \<?= \_\_('Stop & Revert', 'gemini-kimi-seo') ?\>  
        \</button\>  
    \</p\>  
    \<?php  
}  
\`\`\`

\#\#\#\# 4.5.3 AJAX-Powered Status Refresh: Polling for \`Testing\` state updates

\`\`\`javascript  
// Admin JavaScript  
(function($) {  
    'use strict';  
      
    function pollTestStatus(postId) {  
        $.ajax({  
            url: wpApiSettings.root \+ 'gemini-kimi-seo/v1/test-status/' \+ postId,  
            method: 'GET',  
            beforeSend: function(xhr) {  
                xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);  
            },  
            success: function(response) {  
                updateStatusDisplay(response);  
                  
                // Continue polling if still testing  
                if (response.status \=== 'Testing') {  
                    setTimeout(function() {  
                        pollTestStatus(postId);  
                    }, 30000); // 30 second intervals  
                }  
            }  
        });  
    }  
      
    function updateStatusDisplay(data) {  
        var $box \= $('\#gkso\_status\_box');  
        // Update progress bar, metrics, etc.  
    }  
      
    // Initialize polling on page load if testing  
    $(document).ready(function() {  
        var postId \= $('\#post\_ID').val();  
        var currentStatus \= $('.gkso-status-box h4').text();  
        if (currentStatus.includes('Testing')) {  
            pollTestStatus(postId);  
        }  
    });  
})(jQuery);  
\`\`\`

\#\#\#\# 4.5.4 Bulk Actions: Multi-Post Test Initiation from Post List Screen

\`\`\`php  
add\_filter('bulk\_actions-edit-post', 'gkso\_add\_bulk\_actions');

function gkso\_add\_bulk\_actions($bulk\_actions) {  
    $bulk\_actions\['gkso\_start\_test'\] \= \_\_('Start SEO Test', 'gemini-kimi-seo');  
    return $bulk\_actions;  
}

add\_filter('handle\_bulk\_actions-edit-post', 'gkso\_handle\_bulk\_actions', 10, 3);

function gkso\_handle\_bulk\_actions($redirect\_to, $doaction, $post\_ids) {  
    if ($doaction \!== 'gkso\_start\_test') {  
        return $redirect\_to;  
    }  
      
    $initiated \= 0;  
    $failed \= 0;  
      
    foreach ($post\_ids as $post\_id) {  
        $result \= gkso\_initiate\_test\_async($post\_id); // Queue for background processing  
        if (\!is\_wp\_error($result)) {  
            $initiated++;  
        } else {  
            $failed++;  
        }  
    }  
      
    $redirect\_to \= add\_query\_arg(\[  
        'gkso\_bulk\_initiated' \=\> $initiated,  
        'gkso\_bulk\_failed' \=\> $failed  
    \], $redirect\_to);  
      
    return $redirect\_to;  
}  
\`\`\`

\#\#\# 4.6 Security & Rate Limiting

\#\#\#\# 4.6.1 Nonce Verification on Admin Actions

All admin-originating requests (meta box submissions, AJAX calls) include \`wp\_nonce\_field()\` verification with action-specific nonces.

\#\#\#\# 4.6.2 API Key Rotation for n8n ↔ WordPress Communication

| Rotation Trigger | Procedure | Downtime |  
|---------------|-----------|----------|  
| Scheduled (90 days) | Generate new secret; update n8n credential; update WordPress option; verify; revoke old | Zero (graceful transition) |  
| Suspected compromise | Immediate revocation; emergency rotation procedure; audit logs | Minimal (seconds) |  
| Personnel change | Same as scheduled with accelerated timeline | Zero |

\#\#\#\# 4.6.3 Per-User Daily Test Limits

\`\`\`php  
function gkso\_check\_user\_test\_limit($user\_id) {  
    $limit \= get\_option('gkso\_daily\_test\_limit\_per\_user', 10);  
    $today \= date('Y-m-d');  
    $key \= "gkso\_tests\_today\_{$user\_id}\_{$today}";  
      
    $today\_count \= (int) get\_transient($key);  
    if ($today\_count \>= $limit) {  
        return new WP\_Error(  
            'daily\_limit\_exceeded',  
            sprintf(\_\_('Daily test limit of %d exceeded. Try again tomorrow.', 'gemini-kimi-seo'), $limit),  
            \['status' \=\> 429, 'retry\_after' \=\> strtotime('tomorrow') \- time()\]  
        );  
    }  
      
    set\_transient($key, $today\_count \+ 1, DAY\_IN\_SECONDS);  
    return true;  
}  
\`\`\`

\#\#\#\# 4.6.4 IP Allowlisting for n8n Webhook Sources (Optional)

\`\`\`php  
function gkso\_verify\_webhook\_source($request) {  
    if (\!get\_option('gkso\_enable\_ip\_allowlist', false)) {  
        return true;  
    }  
      
    $allowlist \= get\_option('gkso\_n8n\_ip\_allowlist', \[\]);  
    $client\_ip \= $\_SERVER\['REMOTE\_ADDR'\]; // Simplified; use proper IP detection  
      
    if (\!in\_array($client\_ip, $allowlist, true)) {  
        do\_action('gkso\_ip\_blocked', $client\_ip, $request);  
        return new WP\_Error(  
            'ip\_not\_allowed',  
            'Request origin not in allowlist',  
            \['status' \=\> 403\]  
        );  
    }  
      
    return true;  
}  
\`\`\`

\#\# 5\. Integration Patterns & Edge Cases

\#\#\# 5.1 Error Handling & Recovery

\#\#\#\# 5.1.1 Google API Quota Exhaustion: Exponential Backoff Retry

| API | Daily Limit | Burst Limit | Backoff Strategy |  
|-----|-------------|-------------|----------------|  
| GA4 Data API | 10,000 requests/property | 100/minute | 1s, 5s, 25s, 125s, then hourly retry |  
| GSC Search Analytics | 50,000 rows/site | 600/minute | 1s, 5s, 25s, then queue for next day |  
| Gemini API | Variable by tier | 60/minute | 1s, 2s, 4s, 8s, then fallback model |

\#\#\#\# 5.1.2 AI API Failure: Fallback Model or Human Notification

| Failure Mode | Fallback | Escalation |  
|-------------|----------|------------|  
| Gemini quota exhausted | Kimi 2.5 attempt | Queue for manual if both fail |  
| Gemini model error | Retry with temperature 0.3 | Kimi attempt |  
| Kimi unavailable | Gemini with extended timeout | Queue for manual |  
| Both models fail | — | Slack/email alert with full context |

\#\#\#\# 5.1.3 WordPress REST Timeout: Async Queue with Status Polling

Long-running n8n operations (AI generation, extensive data processing) use \*\*async pattern\*\*: WordPress receives immediate acknowledgment with \`test\_id\`, polls \`status\_url\` for completion, n8n updates status via callback when ready.

\#\#\# 5.2 Multi-Environment Configuration

| Environment | GA4/GSC | AI APIs | Test Frequency | Automation Level |  
|-------------|---------|---------|---------------|------------------|  
| \*\*Development\*\* | Sandbox properties | Development keys | Manual only | Human-triggered, full review |  
| \*\*Staging\*\* | Production read-only | Production keys, rate limited | Weekly batch | Supervised, alert on all actions |  
| \*\*Production\*\* | Production full access | Production keys | Daily | Full automation with anomaly alerts |

\#\#\# 5.3 Reporting & Observability

\#\#\#\# 5.3.1 n8n Execution Log Retention

| Log Type | Retention | Purpose |  
|----------|-----------|---------|  
| Successful executions | 30 days | Operational verification, trend analysis |  
| Failed executions | 90 days | Debugging, pattern identification |  
| AI generation outputs | 365 days | Prompt engineering improvement, compliance |

\#\#\#\# 5.3.2 WordPress Admin Dashboard: Site-Wide Optimization Statistics

\*\*Dashboard widget\*\* showing: total tests run, success rate, average improvement, active tests, queue depth, recent activity feed.

\#\#\#\# 5.3.3 Slack/Email Notifications: Test Completion, Anomalies, System Errors

| Event | Channel | Recipients | Content |  
|-------|---------|------------|---------|  
| Test completed | Slack \#seo-optimizations | SEO team | Post, winner, improvement %, comparison link |  
| Significant negative impact | Slack \#seo-alerts \+ email | SEO lead, engineering | Immediate attention required |  
| System error | PagerDuty/ops channel | On-call engineer | Error details, affected posts, recovery status |  
| Weekly summary | Email | Stakeholders | Aggregate statistics, upcoming tests, recommendations |

