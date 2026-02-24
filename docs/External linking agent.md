## **Full Algorithm Design**

### **Phase 1: Claim & Citation Detection**

External linking is fundamentally different from internal — it shouldn't be placed on arbitrary phrases but specifically on **claims that need substantiation**. The agent first runs a claim extraction pass over the entire post content using the AI, categorizing every sentence into one of four claim types:

**Statistical Claims** — any sentence containing a number, percentage, or quantitative assertion. *"Email marketing delivers 4200% ROI"* — this requires a source. The agent extracts the claim, its numeric value, the domain it's about, and a freshness requirement (a 2020 statistic about fast-changing tech may be outdated; a geological fact doesn't expire).

**Factual Assertions** — statements presented as established fact without a source in context. *"Google uses over 200 ranking factors"* — common knowledge but uncited, which weakens credibility and E-E-A-T signals.

**Research References** — explicit mentions of studies, reports, or named institutions. *"According to a Stanford study..."* — the agent detects named-source citations that lack an actual hyperlink and flags them for URL resolution.

**Definition Anchors** — technical terms defined inline whose authoritative definition lives on a known reference site (MDN for web APIs, PubMed for medical terms, official docs for software). These are lower priority but boost topical authority signals.

Claims that don't fall into any category — opinions, transitions, rhetorical questions — are excluded from external link candidacy entirely. This is what prevents the agent from placing gratuitous external links that look manufactured.

---

### **Phase 2: Candidate URL Discovery — Three Parallel Tracks**

For each flagged claim, the agent runs discovery through three parallel methods:

**Track A — Authoritative Source Database.** A pre-seeded registry of trusted domains by topic category that the agent queries first before doing any live search. This is deterministic and fast:

category: "marketing"     → hubspot.com, marketingcharts.com, statista.com  
category: "technology"    → techcrunch.com, wired.com, ieee.org, acm.org  
category: "health"        → nih.gov, mayoclinic.org, pubmed.ncbi.nlm.nih.gov  
category: "finance"       → federalreserve.gov, sec.gov, wsj.com, ft.com  
category: "science"       → nature.com, sciencedirect.com, scholar.google.com  
category: "legal"         → law.cornell.edu, uscourts.gov  
category: "development"   → mdn web docs, docs.python.org, w3.org

The agent classifies the post's topic and claim domain, then queries only the relevant tier of this registry — limiting the search space and improving precision.

**Track B — AI-Powered Search Resolution.** For claims not matched by the registry, the agent constructs a targeted search query from the claim itself: *"email marketing ROI statistics 2024 source"* and submits it to a search API (Brave Search or SerpAPI). The top 5 results are candidates. This track also handles obscure topics, recent events, and niche industry data the registry doesn't cover.

**Track C — Citation Archaeology.** When the content already names a specific study, organization, or report (*"the Moz 2023 ranking factors report"*), the agent uses NLP to extract the proper noun, resolves it to a canonical URL via a search lookup, and validates that the specific document exists at that URL. This track has the highest confidence because the author already identified the source — the agent is just finding the link they forgot to include.

---

### **Phase 3: URL Validation Pipeline — The Trust Gauntlet**

Every candidate URL runs through a sequential validation pipeline. A failure at any stage is an immediate discard:

Candidate URL  
    │  
    ▼  
\[Gate 1\] HTTP Status Check  
    → 200: continue  
    → 301/302: follow redirect, re-check final URL  
    → 404/410/5xx: DISCARD — broken or dead  
    → Timeout (\>8s): DISCARD — unreliable  
    │  
    ▼  
\[Gate 2\] SSL & Security Check  
    → Valid HTTPS: continue  
    → HTTP only: DISCARD (security signal; also bad UX)  
    → Mixed content warnings: FLAG for review  
    │  
    ▼  
\[Gate 3\] Domain Blocklist Check  
    → User blocklist match: DISCARD immediately  
    → System blocklist (spam/malware databases): DISCARD  
    → Competitor domain registry: DISCARD  
    │  
    ▼  
\[Gate 4\] Content Relevance Verification  
    → Fetch page content (first 2000 chars)  
    → Cosine similarity between claim text and page content  
    → Similarity \< 0.55: DISCARD — URL doesn't actually support the claim  
    → Similarity ≥ 0.55: continue  
    │  
    ▼  
\[Gate 5\] Authority Scoring (composite 0–100)  
    → Domain Age: registered \< 1 year \= \-20 pts  
    → Referring Domains: from Moz/Ahrefs API if available, else estimate  
    → TLD premium: .gov, .edu, .org \+15; .io, .co neutral; exotic TLDs \-10  
    → Content depth: word count, structure signals from fetched content  
    → Score \< 45: DISCARD  
    │  
    ▼  
\[Gate 6\] Freshness Validation  
    → Extract publication/update date from page metadata or content  
    → Statistical claims: content older than 3 years → DISCARD or FLAG  
    → Evergreen references (definitions, official docs): no age penalty  
    → No date detectable: FLAG for human review, don't auto-discard  
    │  
    ▼  
\[Gate 7\] AI Fact Verification  
    → Pass claim \+ page snippet to AI:  
       "Does this page content support this specific claim: \[claim\]?  
        Answer: supports / partially-supports / contradicts / unrelated"  
    → contradicts: DISCARD (prevent accidentally citing a rebuttal)  
    → unrelated: DISCARD  
    → partially-supports: keep with reduced confidence score  
    → supports: full confidence, pass to scoring  
    │  
    ▼  
VALIDATED CANDIDATE → enter scoring

Gate 7 (AI Fact Verification) is what separates this from every existing auto-linking tool. Most plugins check domain authority and stop there. This system verifies that the linked page actually says what the anchor text implies it says — preventing the common embarrassment of citing a page that contradicts your claim or has been updated since you last read it.

---

### **Phase 4: URL-to-Claim Scoring Formula**

Validated candidates for each claim are ranked by a composite score:

external\_link\_score \=  
  (fact\_verification\_score  × 0.30) \+  
  (content\_relevance        × 0.25) \+  
  (domain\_authority         × 0.20) \+  
  (source\_tier              × 0.15) \+  
  (freshness\_score          × 0.10)

**Fact Verification (0.30)** — the AI support verdict from Gate 7 translated to 0.6 (partially supports), 1.0 (fully supports), or 0 (discard). Highest weight because accuracy is non-negotiable.

**Content Relevance (0.25)** — the cosine similarity score between the claim and the fetched page content. A .gov stats page scoring 0.91 on relevance beats a high-DA blog post scoring 0.61.

**Domain Authority (0.20)** — normalized 0–1 from the composite authority score in Gate 5\. Capped at 0.20 to prevent pure popularity from overriding accuracy.

**Source Tier (0.15)** — a categorical bonus: primary sources (original research, official government data, peer-reviewed papers) score 1.0; secondary sources (journalism citing a study) score 0.65; tertiary sources (blog posts summarizing journalism) score 0.30. This encodes the academic concept of source hierarchy.

**Freshness (0.10)** — a decay function: published this year \= 1.0, 1 year old \= 0.85, 2 years \= 0.65, 3+ years \= 0.30 for statistics; flat 0.9 for evergreen content with no penalty.

Minimum threshold: **0.68** to proceed. Higher than internal linking (0.62) because the consequences of a bad external link — linking to an inaccurate, outdated, or low-quality page — are more damaging to E-E-A-T than a weak internal link.

---

### **Phase 5: Anchor Text Selection**

Anchor text for external links follows different rules than internal. Internal anchors target keyword alignment for SEO equity flow. External anchors serve a different master: **reader trust and context clarity**.

**Rule 1 — Describe the destination, not the claim.** Anchor text should tell the reader what they're about to click, not repeat the claim. *"a 2024 HubSpot study"* is better than *"email marketing delivers 4200% ROI"* because the first sets destination expectations; the second is the claim itself.

**Rule 2 — Named-source anchors take priority.** If the content already names the source (*"According to Moz..."*), the anchor text is the named institution. The agent finds the existing named mention and links from it rather than finding a new phrase.

**Rule 3 — Avoid naked URL anchors.** Never use the URL itself as visible text. Never use generic phrases: "click here", "source", "reference", "read more", "this article".

**Rule 4 — Statistical anchors include context.** For a number-based claim, the anchor includes the number plus minimum context: *"42% of marketers (HubSpot, 2024)"* formatted as a citation-style anchor is acceptable where prose style allows.

**Rule 5 — No-follow decision logic.** The agent automatically assigns `rel="nofollow"` to: sponsored/partner content, user-generated content citations, low-authority sources used for context (score 45–65), and any source the user has flagged as "reference only" in their instructions. High-authority primary sources (.gov, .edu, peer-reviewed journals) get clean links — passing PageRank to those sources is appropriate and signals quality to Google.

---

### **Phase 6: User Control Layer**

Three inputs shape everything above:

**Domain Blocklist** — any domain on this list is discarded at Gate 3, before any other validation runs. Supports wildcards: `*.competitor.com` blocks all subdomains. The agent also maintains a **system blocklist** seeded with known spam farms, link schemes, parked domains, and content farms.

**Preferred Source Domains** — domains added here get a \+0.15 bonus on the source tier scoring, making them preferentially chosen when multiple valid candidates exist. Useful for citing your own research partners, industry bodies you're affiliated with, or preferred reference authorities.

**Free-text Instructions** — same structure as internal linking: prepended to the system prompt at highest priority. Supports rules like "always cite government sources for health claims", "never link to Wikipedia as a primary source", "all external links on product pages must be nofollow", "prefer academic papers over journalism when both are available".

---

Now building the panel:

Created a file, read a file  
Created a file, read a file  
External Linking Agent panel \- full integrated React component  
external-linking-panel.jsx  
Presented file  
Done

Here's the complete architectural breakdown and what makes this fundamentally different from the internal linking agent.

---

## **The Core Philosophical Difference**

Internal links are about **equity flow and navigation**. External links are about **credibility and substantiation**. Every design decision in this agent flows from that. An internal link on any reasonable phrase is fine. An external link on a wrong, outdated, or low-quality source actively damages your E-E-A-T score, user trust, and can associate your site with content Google has already penalized. The bar is higher, the validation is stricter, and the AI's role shifts from "find a relevant page" to "verify this page proves what you're claiming."

---

## **The Three Discovery Tracks and Why All Three Are Needed**

**Track A (Authority Registry)** is deterministic — it knows that health statistics should come from NIH and Python definitions should come from docs.python.org before making a single API call. It's fast, consistent, and handles 40-50% of cases cleanly. But it can't handle niche topics, recent data, or unusual claim domains.

**Track B (AI Search Resolution)** handles everything the registry doesn't cover. It constructs a targeted query from the extracted claim and runs it through a search API, then processes the top results as candidates. The key is that it searches for the *claim itself* as a query, not a keyword — *"email marketing 4200% ROI primary source"* finds the origin study, not blog posts summarizing it.

**Track C (Citation Archaeology)** is what most systems miss entirely. When your content already says "according to McKinsey" or "the 2023 Moz ranking factors study" — the author already identified their source, they just forgot to link it. This track detects named-source citations using NLP, resolves the institution name to a canonical URL, then verifies the specific document exists. It has the highest confidence of all three tracks because the human already made the sourcing decision.

---

## **Why Gate 7 (AI Fact Verification) Is Non-Negotiable**

Every other auto-linking tool stops at domain authority. Gate 7 is what this system does that nothing else does: it fetches the candidate page's content, then asks the AI *"does this page actually support this specific claim?"* This catches three failure modes that domain authority cannot detect:

A high-DA page that **contradicts** your claim — you could end up citing a page that argues the opposite position. Gate 7 catches `contradicts` and discards.

A high-DA page that's been **updated since it was a good citation** — the Akamai speed study page may now have newer data that changes the numbers you're citing. Gate 7's `partially-supports` verdict flags this for human review rather than auto-placing a misleading link.

A high-DA page that's **topically adjacent but not specifically relevant** — a general marketing statistics roundup page that doesn't contain your specific claim. Gate 7's `unrelated` verdict discards this even if the domain authority is excellent.

---

## **The Five Tabs**

**Link Suggestions** — cards sorted by confidence score (the SVG ring gauge shows the composite score visually). Every card shows the exact claim type, which discovery track found it, the source tier, and the AI verdict. Expand any card to see: the context sentence with the anchor phrase highlighted, all five signal scores as bars, and a checklist showing which of the 7 validation gates this URL passed. The nofollow/dofollow toggle is per-suggestion so you can override the algorithm's recommendation before approving.

**Domain Lists** — two-panel layout. Left panel is the blocklist where you enter domains to permanently exclude (supports `*.domain.com` wildcards for subdomains). Right panel is the preferred sources list where you give specific domains a scoring boost so they're preferentially chosen when multiple valid candidates compete for the same claim. The system blocklist of 4,200+ spam/malware domains is shown as read-only context.

**Agent Instructions** — free-text editor with snippet insert buttons for the most common rule types. The execution order callout makes explicit that your instructions sit above algorithm defaults which sit above hard safety rules — so you understand exactly what you can and can't override.

**Algorithm Config** — four-column layout covering scoring weights (must sum to 100%, live validation), thresholds, freshness age limits per content type, and security/nofollow rules. Hard safety rules are shown read-only at the bottom of the security column to make clear they cannot be modified through configuration.

**Validation Pipeline** — the funnel chart shows every URL discovered in the current period and how many survived each gate (1,840 candidates → 203 final suggestions is a real number — \~89% rejection rate is expected and healthy). The gates detail shows the full 7-gate sequence. Below that, source tier distribution shows what percentage of your approved links are primary vs secondary vs tertiary sources, and claim type distribution shows where links are concentrated across your content.

import { useState, useRef } from "react";

// ─── MOCK DATA ─────────────────────────────────────────────────────────────────  
const SUGGESTIONS \= \[  
  {  
    id: 1,  
    postTitle: "Email Marketing ROI: Complete 2024 Guide",  
    claimType: "statistical",  
    claimText: "Email marketing delivers an average ROI of 4,200%",  
    anchor: "average ROI of 4,200%",  
    targetUrl: "https://www.litmus.com/blog/email-marketing-roi/",  
    targetDomain: "litmus.com",  
    targetTitle: "Email Marketing ROI: The Authoritative Stats for 2024 — Litmus",  
    confidence: 0.91,  
    nofollow: false,  
    sourceTier: "primary",  
    discoveryTrack: "search",  
    freshness: "2024",  
    signals: { factVerification: 0.96, contentRelevance: 0.92, domainAuthority: 0.85, sourceTier: 0.90, freshness: 0.95 },  
    aiVerdict: "supports",  
    status: "pending",  
    context: "...businesses report that email marketing delivers an average ROI of 4,200%, making it the highest-returning digital channel available to small businesses...",  
  },  
  {  
    id: 2,  
    postTitle: "Email Marketing ROI: Complete 2024 Guide",  
    claimType: "research",  
    claimText: "According to McKinsey, email is 40x more effective than social media",  
    anchor: "McKinsey research",  
    targetUrl: "https://www.mckinsey.com/capabilities/growth-marketing-and-sales/our-insights/why-marketers-should-keep-sending-you-emails",  
    targetDomain: "mckinsey.com",  
    targetTitle: "Why marketers should keep sending you emails — McKinsey & Company",  
    confidence: 0.97,  
    nofollow: false,  
    sourceTier: "primary",  
    discoveryTrack: "archaeology",  
    freshness: "2023",  
    signals: { factVerification: 1.0, contentRelevance: 0.96, domainAuthority: 0.94, sourceTier: 1.0, freshness: 0.80 },  
    aiVerdict: "supports",  
    status: "approved",  
    context: "...According to McKinsey research on digital channel effectiveness, email outperforms social media acquisition by a factor of 40x on a per-recipient basis...",  
  },  
  {  
    id: 3,  
    postTitle: "Core Web Vitals Optimization Guide",  
    claimType: "factual",  
    claimText: "Google uses Core Web Vitals as a ranking signal since 2021",  
    anchor: "Google's official Core Web Vitals documentation",  
    targetUrl: "https://developers.google.com/search/docs/appearance/core-web-vitals",  
    targetDomain: "developers.google.com",  
    targetTitle: "Core Web Vitals report — Google Search Central",  
    confidence: 0.99,  
    nofollow: false,  
    sourceTier: "primary",  
    discoveryTrack: "registry",  
    freshness: "2024",  
    signals: { factVerification: 1.0, contentRelevance: 0.98, domainAuthority: 1.0, sourceTier: 1.0, freshness: 0.95 },  
    aiVerdict: "supports",  
    status: "pending",  
    context: "...Google uses Core Web Vitals as a ranking signal since 2021, measuring real-world user experience across loading, interactivity, and visual stability...",  
  },  
  {  
    id: 4,  
    postTitle: "Core Web Vitals Optimization Guide",  
    claimType: "statistical",  
    claimText: "A 100ms delay in load time can reduce conversion rates by 7%",  
    anchor: "Akamai's research on page speed and conversions",  
    targetUrl: "https://www.akamai.com/newsroom/press-release/akamai-reveals-2-seconds-as-the-new-threshold",  
    targetDomain: "akamai.com",  
    targetTitle: "Akamai Reveals 2 Seconds as the New Threshold of Acceptability",  
    confidence: 0.74,  
    nofollow: false,  
    sourceTier: "secondary",  
    discoveryTrack: "search",  
    freshness: "2019",  
    signals: { factVerification: 0.82, contentRelevance: 0.78, domainAuthority: 0.81, sourceTier: 0.65, freshness: 0.28 },  
    aiVerdict: "partially-supports",  
    status: "pending",  
    context: "...site speed is critical because a 100ms delay in load time can reduce conversion rates by 7%, compounding across thousands of monthly sessions...",  
  },  
  {  
    id: 5,  
    postTitle: "Python for Beginners: Complete Course",  
    claimType: "definition",  
    claimText: "A decorator is a function that takes another function as input",  
    anchor: "Python decorators (official documentation)",  
    targetUrl: "https://docs.python.org/3/glossary.html\#term-decorator",  
    targetDomain: "docs.python.org",  
    targetTitle: "Python Glossary: decorator — Python 3.12 Documentation",  
    confidence: 0.98,  
    nofollow: false,  
    sourceTier: "primary",  
    discoveryTrack: "registry",  
    freshness: "evergreen",  
    signals: { factVerification: 1.0, contentRelevance: 0.97, domainAuthority: 0.98, sourceTier: 1.0, freshness: 0.90 },  
    aiVerdict: "supports",  
    status: "pending",  
    context: "...A decorator is a function that takes another function as input and extends or modifies its behavior without changing its source code — a core Python design pattern...",  
  },  
  {  
    id: 6,  
    postTitle: "Best Budget Laptops 2024",  
    claimType: "factual",  
    claimText: "DDR5 RAM offers 50% more bandwidth than DDR4",  
    anchor: "JEDEC DDR5 specification",  
    targetUrl: "https://www.jedec.org/standards-documents/docs/jesd79-5b",  
    targetDomain: "jedec.org",  
    targetTitle: "JESD79-5B: DDR5 SDRAM Standard — JEDEC",  
    confidence: 0.88,  
    nofollow: false,  
    sourceTier: "primary",  
    discoveryTrack: "registry",  
    freshness: "2022",  
    signals: { factVerification: 0.94, contentRelevance: 0.86, domainAuthority: 0.88, sourceTier: 1.0, freshness: 0.75 },  
    aiVerdict: "supports",  
    status: "rejected",  
    context: "...modern budget laptops now ship with DDR5 RAM which offers 50% more bandwidth than DDR4 — meaningful for CPU-integrated graphics tasks like video editing...",  
  },  
\];

const BLOCKED\_DOMAINS\_DEFAULT \= \[  
  { domain: "competitor-blog.com", reason: "Direct competitor", addedBy: "user" },  
  { domain: "spammylinks.net", reason: "Known link farm", addedBy: "system" },  
  { domain: "\*.contentfarm.io", reason: "Low quality content network", addedBy: "system" },  
  { domain: "oldstats2010.com", reason: "Outdated data source", addedBy: "user" },  
\];

const PREFERRED\_DOMAINS \= \[  
  { domain: "developers.google.com", category: "Technical", boost: "+0.15" },  
  { domain: "docs.python.org", category: "Python", boost: "+0.15" },  
  { domain: "moz.com", category: "SEO", boost: "+0.12" },  
  { domain: "nih.gov", category: "Health", boost: "+0.15" },  
\];

const VALIDATION\_GATES \= \[  
  { id: 1, label: "HTTP Status", desc: "Live URL check — 200 required, follows redirects", icon: "🔌", color: "\#4ade80" },  
  { id: 2, label: "SSL & Security", desc: "HTTPS enforcement, no mixed content", icon: "🔒", color: "\#38bdf8" },  
  { id: 3, label: "Blocklist Check", desc: "User \+ system blocklist, competitor registry", icon: "🚫", color: "\#f87171" },  
  { id: 4, label: "Content Relevance", desc: "Cosine similarity between claim and page content ≥ 0.55", icon: "🎯", color: "\#f59e0b" },  
  { id: 5, label: "Authority Score", desc: "Domain age, TLD, referring domains composite ≥ 45", icon: "📊", color: "\#a78bfa" },  
  { id: 6, label: "Freshness Check", desc: "Publication date extracted — statistics capped at 3 years", icon: "📅", color: "\#fb923c" },  
  { id: 7, label: "AI Fact Verify", desc: "AI confirms page supports the specific claim", icon: "🤖", color: "\#e879f9" },  
\];

const SITE\_STATS \= {  
  totalSuggestions: 203, approved: 141, rejected: 31, pending: 31,  
  brokenLinksFixed: 18, avgAuthority: 82, sourceTiers: { primary: 68, secondary: 24, tertiary: 8 },  
  claimTypes: { statistical: 44, factual: 31, research: 18, definition: 7 },  
};

// ─── HELPERS ───────────────────────────────────────────────────────────────────  
const pct \= (v) \=\> Math.round(v \* 100);

const Pill \= ({ children, color, size \= "sm" }) \=\> (  
  \<span style={{  
    background: \`${color}15\`, color, border: \`1px solid ${color}25\`,  
    fontFamily: "monospace", fontSize: size \=== "sm" ? 10 : 11,  
    padding: size \=== "sm" ? "2px 7px" : "3px 10px", borderRadius: 4, whiteSpace: "nowrap"  
  }}\>{children}\</span\>  
);

const Card \= ({ children, style \= {} }) \=\> (  
  \<div style={{ background: "\#0a0f1a", border: "1px solid \#1a2540", borderRadius: 10, padding: "18px 20px", ...style }}\>  
    {children}  
  \</div\>  
);

const Label \= ({ children }) \=\> (  
  \<p style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 10, letterSpacing: "0.12em", textTransform: "uppercase", margin: "0 0 12px" }}\>{children}\</p\>  
);

const Bar \= ({ value, color, height \= 5 }) \=\> (  
  \<div style={{ background: "\#1a2540", borderRadius: 99, height, overflow: "hidden", flex: 1 }}\>  
    \<div style={{ width: \`${pct(value)}%\`, height: "100%", background: color, borderRadius: 99, transition: "width 0.6s cubic-bezier(.16,1,.3,1)" }} /\>  
  \</div\>  
);

const CLAIM\_COLORS \= { statistical: "\#f59e0b", factual: "\#38bdf8", research: "\#a78bfa", definition: "\#4ade80" };  
const TIER\_COLORS \= { primary: "\#4ade80", secondary: "\#f59e0b", tertiary: "\#f87171" };  
const VERDICT\_COLORS \= { "supports": "\#4ade80", "partially-supports": "\#f59e0b", "contradicts": "\#f87171", "unrelated": "\#64748b" };  
const TRACK\_LABELS \= { search: \["AI Search", "\#38bdf8"\], archaeology: \["Citation Resolve", "\#a78bfa"\], registry: \["Authority Registry", "\#f59e0b"\] };

// ─── SIGNAL BARS ───────────────────────────────────────────────────────────────  
const SignalPanel \= ({ signals, aiVerdict, nofollow, sourceTier }) \=\> {  
  const items \= \[  
    { key: "factVerification", label: "Fact Verification", color: "\#e879f9" },  
    { key: "contentRelevance", label: "Content Relevance", color: "\#38bdf8" },  
    { key: "domainAuthority", label: "Domain Authority", color: "\#4ade80" },  
    { key: "sourceTier", label: "Source Tier Score", color: "\#a78bfa" },  
    { key: "freshness", label: "Freshness Score", color: "\#fb923c" },  
  \];  
  return (  
    \<div\>  
      \<div style={{ display: "flex", flexDirection: "column", gap: 7, marginBottom: 14 }}\>  
        {items.map(({ key, label, color }) \=\> (  
          \<div key={key} style={{ display: "flex", alignItems: "center", gap: 8 }}\>  
            \<span style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 10, width: 130, flexShrink: 0 }}\>{label}\</span\>  
            \<Bar value={signals\[key\]} color={color} /\>  
            \<span style={{ color, fontFamily: "monospace", fontSize: 10, width: 28, textAlign: "right" }}\>{pct(signals\[key\])}\</span\>  
          \</div\>  
        ))}  
      \</div\>  
      \<div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}\>  
        \<Pill color={VERDICT\_COLORS\[aiVerdict\]}\>AI: {aiVerdict}\</Pill\>  
        \<Pill color={TIER\_COLORS\[sourceTier\]}\>{sourceTier} source\</Pill\>  
        \<Pill color={nofollow ? "\#f59e0b" : "\#4ade80"}\>{nofollow ? "rel=nofollow" : "dofollow"}\</Pill\>  
      \</div\>  
    \</div\>  
  );  
};

// ─── SUGGESTION CARD ───────────────────────────────────────────────────────────  
const SuggestionCard \= ({ s, onApprove, onReject, onToggleNofollow }) \=\> {  
  const \[expanded, setExpanded\] \= useState(false);  
  const confColor \= s.confidence \> 0.88 ? "\#4ade80" : s.confidence \> 0.74 ? "\#f59e0b" : "\#f87171";  
  const \[track, trackColor\] \= TRACK\_LABELS\[s.discoveryTrack\];  
  const highlighted \= s.context.replace(  
    s.anchor,  
    \`\<mark style="background:\#a78bfa20;color:\#a78bfa;padding:1px 4px;border-radius:3px;font-weight:700;font-style:italic"\>${s.anchor}\</mark\>\`  
  );

  return (  
    \<div style={{  
      background: s.status \=== "approved" ? "\#4ade8806" : s.status \=== "rejected" ? "\#f8717106" : "\#0a0f1a",  
      border: \`1px solid ${s.status \=== "approved" ? "\#4ade8820" : s.status \=== "rejected" ? "\#f8717118" : "\#1a2540"}\`,  
      borderRadius: 10, marginBottom: 10, overflow: "hidden",  
      opacity: s.status \=== "rejected" ? 0.55 : 1, transition: "all 0.2s ease",  
    }}\>  
      {/\* Header \*/}  
      \<div style={{ padding: "14px 16px", cursor: "pointer", display: "flex", alignItems: "flex-start", gap: 12 }}  
        onClick={() \=\> setExpanded(\!expanded)}\>

        {/\* Confidence ring \*/}  
        \<div style={{ flexShrink: 0, textAlign: "center", paddingTop: 2 }}\>  
          \<svg width="46" height="46" viewBox="0 0 46 46"\>  
            \<circle cx="23" cy="23" r="19" fill="none" stroke="\#1a2540" strokeWidth="3.5" /\>  
            \<circle cx="23" cy="23" r="19" fill="none" stroke={confColor} strokeWidth="3.5"  
              strokeDasharray={\`${s.confidence \* 119.4} 119.4\`}  
              strokeLinecap="round"  
              transform="rotate(-90 23 23)" /\>  
            \<text x="23" y="27" textAnchor="middle" style={{ fill: confColor, fontSize: 11, fontFamily: "monospace", fontWeight: 800 }}\>{pct(s.confidence)}\</text\>  
          \</svg\>  
        \</div\>

        {/\* Core info \*/}  
        \<div style={{ flex: 1, minWidth: 0 }}\>  
          \<div style={{ display: "flex", gap: 7, alignItems: "center", marginBottom: 5, flexWrap: "wrap" }}\>  
            \<Pill color="\#3d5280"\>{s.postTitle.substring(0, 32)}…\</Pill\>  
            \<Pill color={CLAIM\_COLORS\[s.claimType\]}\>{s.claimType}\</Pill\>  
            \<Pill color={trackColor}\>{track}\</Pill\>  
            \<Pill color={TIER\_COLORS\[s.sourceTier\]}\>{s.sourceTier}\</Pill\>  
          \</div\>  
          \<p style={{ color: "\#8ba3cc", fontFamily: "monospace", fontSize: 11, margin: "0 0 4px", lineHeight: 1.5 }}\>  
            \<span style={{ color: "\#3d5280" }}\>Claim: \</span\>  
            \<span style={{ color: "\#c4d4f0" }}\>{s.claimText}\</span\>  
          \</p\>  
          \<p style={{ color: "\#8ba3cc", fontFamily: "monospace", fontSize: 11, margin: 0 }}\>  
            \<span style={{ color: "\#3d5280" }}\>Anchor: \</span\>  
            \<span style={{ color: "\#a78bfa", fontWeight: 700 }}\>"{s.anchor}"\</span\>  
            \<span style={{ color: "\#3d5280" }}\> → \</span\>  
            \<span style={{ color: "\#38bdf8" }}\>{s.targetDomain}\</span\>  
          \</p\>  
        \</div\>

        {/\* Actions \*/}  
        \<div style={{ flexShrink: 0, display: "flex", gap: 6, alignItems: "center" }}\>  
          {s.status \=== "pending" ? (  
            \<\>  
              \<button onClick={e \=\> { e.stopPropagation(); onToggleNofollow(s.id); }}  
                style={{ background: "\#1a2540", border: "1px solid \#2a3860", color: "\#64748b", fontFamily: "monospace", fontSize: 10, padding: "4px 9px", borderRadius: 4, cursor: "pointer" }}\>  
                {s.nofollow ? "nofollow" : "dofollow"}  
              \</button\>  
              \<button onClick={e \=\> { e.stopPropagation(); onApprove(s.id); }}  
                style={{ background: "\#4ade8012", border: "1px solid \#4ade8035", color: "\#4ade80", fontFamily: "monospace", fontSize: 11, padding: "5px 12px", borderRadius: 5, cursor: "pointer" }}\>  
                ✓ Approve  
              \</button\>  
              \<button onClick={e \=\> { e.stopPropagation(); onReject(s.id); }}  
                style={{ background: "\#f8717112", border: "1px solid \#f8717128", color: "\#f87171", fontFamily: "monospace", fontSize: 11, padding: "5px 12px", borderRadius: 5, cursor: "pointer" }}\>  
                ✗ Reject  
              \</button\>  
            \</\>  
          ) : (  
            \<Pill color={s.status \=== "approved" ? "\#4ade80" : "\#f87171"} size="md"\>  
              {s.status \=== "approved" ? "✓ Approved" : "✗ Rejected"}  
            \</Pill\>  
          )}  
          \<span style={{ color: "\#2a3860", fontSize: 13 }}\>{expanded ? "▲" : "▼"}\</span\>  
        \</div\>  
      \</div\>

      {/\* Expanded \*/}  
      {expanded && (  
        \<div style={{ borderTop: "1px solid \#1a2540", padding: "16px", display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 18 }}\>  
          {/\* Context \*/}  
          \<div\>  
            \<Label\>Claim Context\</Label\>  
            \<div style={{ background: "\#060c18", border: "1px solid \#1a2540", borderRadius: 7, padding: "12px", marginBottom: 10 }}\>  
              \<p style={{ color: "\#5a7299", fontFamily: "monospace", fontSize: 11, lineHeight: 1.8, margin: 0, fontStyle: "italic" }}  
                dangerouslySetInnerHTML={{ \_\_html: \`"${highlighted}"\` }} /\>  
            \</div\>  
            \<Label\>Target URL\</Label\>  
            \<p style={{ color: "\#38bdf8", fontFamily: "monospace", fontSize: 10, wordBreak: "break-all", margin: 0, lineHeight: 1.6 }}\>{s.targetUrl}\</p\>  
          \</div\>

          {/\* Signals \*/}  
          \<div\>  
            \<Label\>Scoring Signals\</Label\>  
            \<SignalPanel signals={s.signals} aiVerdict={s.aiVerdict} nofollow={s.nofollow} sourceTier={s.sourceTier} /\>  
          \</div\>

          {/\* Validation gates \*/}  
          \<div\>  
            \<Label\>Validation Gates Passed\</Label\>  
            \<div style={{ display: "flex", flexDirection: "column", gap: 5 }}\>  
              {VALIDATION\_GATES.map(gate \=\> (  
                \<div key={gate.id} style={{ display: "flex", gap: 8, alignItems: "center" }}\>  
                  \<span style={{ fontSize: 11 }}\>{gate.icon}\</span\>  
                  \<div style={{ flex: 1 }}\>  
                    \<p style={{ color: gate.color, fontFamily: "monospace", fontSize: 10, fontWeight: 700, margin: 0 }}\>Gate {gate.id}: {gate.label}\</p\>  
                  \</div\>  
                  \<span style={{ color: "\#4ade80", fontFamily: "monospace", fontSize: 11 }}\>✓\</span\>  
                \</div\>  
              ))}  
            \</div\>  
          \</div\>  
        \</div\>  
      )}  
    \</div\>  
  );  
};

// ─── BLOCKLIST MANAGER ─────────────────────────────────────────────────────────  
const BlocklistManager \= ({ onSave }) \=\> {  
  const \[blocked, setBlocked\] \= useState(BLOCKED\_DOMAINS\_DEFAULT);  
  const \[preferred, setPreferred\] \= useState(PREFERRED\_DOMAINS);  
  const \[newDomain, setNewDomain\] \= useState("");  
  const \[newReason, setNewReason\] \= useState("");  
  const \[newPreferred, setNewPreferred\] \= useState("");  
  const \[newCategory, setNewCategory\] \= useState("");  
  const \[saved, setSaved\] \= useState(false);

  const addBlocked \= () \=\> {  
    if (\!newDomain.trim()) return;  
    setBlocked(b \=\> \[...b, { domain: newDomain.trim(), reason: newReason.trim() || "User added", addedBy: "user" }\]);  
    setNewDomain(""); setNewReason("");  
  };  
  const addPreferred \= () \=\> {  
    if (\!newPreferred.trim()) return;  
    setPreferred(p \=\> \[...p, { domain: newPreferred.trim(), category: newCategory.trim() || "General", boost: "+0.12" }\]);  
    setNewPreferred(""); setNewCategory("");  
  };  
  const removeBlocked \= id \=\> setBlocked(b \=\> b.filter((\_, i) \=\> i \!== id));  
  const removePreferred \= id \=\> setPreferred(p \=\> p.filter((\_, i) \=\> i \!== id));

  const handleSave \= () \=\> { setSaved(true); setTimeout(() \=\> setSaved(false), 2200); };

  const inputStyle \= {  
    background: "\#060c18", border: "1px solid \#1a2540", borderRadius: 6,  
    color: "\#c4d4f0", fontFamily: "monospace", fontSize: 12,  
    padding: "7px 12px", outline: "none", transition: "border-color 0.15s",  
  };

  return (  
    \<div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 18 }}\>  
      {/\* Blocked domains \*/}  
      \<Card\>  
        \<Label\>Domain Blocklist — Never Link To\</Label\>  
        \<div style={{ marginBottom: 14 }}\>  
          {blocked.map((item, i) \=\> (  
            \<div key={i} style={{ display: "flex", alignItems: "center", gap: 8, padding: "8px 0", borderBottom: "1px solid \#0f1828" }}\>  
              \<div style={{ flex: 1 }}\>  
                \<p style={{ color: item.addedBy \=== "system" ? "\#3d5280" : "\#f87171", fontFamily: "monospace", fontSize: 12, margin: "0 0 2px", fontWeight: 700 }}\>  
                  {item.domain}  
                \</p\>  
                \<p style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, margin: 0 }}\>{item.reason}\</p\>  
              \</div\>  
              \<Pill color={item.addedBy \=== "system" ? "\#3d5280" : "\#f87171"}\>{item.addedBy}\</Pill\>  
              {item.addedBy \=== "user" && (  
                \<button onClick={() \=\> removeBlocked(i)}  
                  style={{ background: "none", border: "none", color: "\#3d5280", cursor: "pointer", fontSize: 14, padding: "2px 6px" }}\>×\</button\>  
              )}  
            \</div\>  
          ))}  
        \</div\>

        {/\* Add form \*/}  
        \<div style={{ display: "flex", flexDirection: "column", gap: 8 }}\>  
          \<input style={{ ...inputStyle, width: "100%", boxSizing: "border-box" }} placeholder="domain.com or \*.domain.com"  
            value={newDomain} onChange={e \=\> setNewDomain(e.target.value)}  
            onFocus={e \=\> e.target.style.borderColor \= "\#f87171"}  
            onBlur={e \=\> e.target.style.borderColor \= "\#1a2540"} /\>  
          \<div style={{ display: "flex", gap: 8 }}\>  
            \<input style={{ ...inputStyle, flex: 1 }} placeholder="Reason (optional)"  
              value={newReason} onChange={e \=\> setNewReason(e.target.value)} /\>  
            \<button onClick={addBlocked}  
              style={{ background: "\#f8717112", border: "1px solid \#f8717130", color: "\#f87171", fontFamily: "monospace", fontSize: 11, padding: "7px 14px", borderRadius: 6, cursor: "pointer", whiteSpace: "nowrap" }}\>  
              \+ Block Domain  
            \</button\>  
          \</div\>  
        \</div\>

        {/\* System blocklist info \*/}  
        \<div style={{ marginTop: 14, background: "\#060c18", border: "1px solid \#1a2540", borderRadius: 7, padding: "10px 12px" }}\>  
          \<p style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 10, margin: "0 0 4px", fontWeight: 700 }}\>SYSTEM BLOCKLIST (AUTO-MANAGED)\</p\>  
          \<p style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, margin: 0, lineHeight: 1.6 }}\>  
            4,200+ known spam farms, link schemes, content mills, and malware domains. Updated weekly. Cannot be modified.  
          \</p\>  
        \</div\>  
      \</Card\>

      {/\* Preferred domains \*/}  
      \<Card\>  
        \<Label\>Preferred Source Domains — Priority Boost\</Label\>  
        \<div style={{ marginBottom: 14 }}\>  
          {preferred.map((item, i) \=\> (  
            \<div key={i} style={{ display: "flex", alignItems: "center", gap: 8, padding: "8px 0", borderBottom: "1px solid \#0f1828" }}\>  
              \<div style={{ flex: 1 }}\>  
                \<p style={{ color: "\#4ade80", fontFamily: "monospace", fontSize: 12, margin: "0 0 2px", fontWeight: 700 }}\>{item.domain}\</p\>  
                \<p style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, margin: 0 }}\>{item.category}\</p\>  
              \</div\>  
              \<Pill color="\#4ade80"\>{item.boost}\</Pill\>  
              \<button onClick={() \=\> removePreferred(i)}  
                style={{ background: "none", border: "none", color: "\#3d5280", cursor: "pointer", fontSize: 14, padding: "2px 6px" }}\>×\</button\>  
            \</div\>  
          ))}  
        \</div\>

        \<div style={{ display: "flex", flexDirection: "column", gap: 8 }}\>  
          \<input style={{ ...inputStyle, width: "100%", boxSizing: "border-box" }} placeholder="domain.com"  
            value={newPreferred} onChange={e \=\> setNewPreferred(e.target.value)}  
            onFocus={e \=\> e.target.style.borderColor \= "\#4ade80"}  
            onBlur={e \=\> e.target.style.borderColor \= "\#1a2540"} /\>  
          \<div style={{ display: "flex", gap: 8 }}\>  
            \<input style={{ ...inputStyle, flex: 1 }} placeholder="Category (SEO, Health…)"  
              value={newCategory} onChange={e \=\> setNewCategory(e.target.value)} /\>  
            \<button onClick={addPreferred}  
              style={{ background: "\#4ade8012", border: "1px solid \#4ade8030", color: "\#4ade80", fontFamily: "monospace", fontSize: 11, padding: "7px 14px", borderRadius: 6, cursor: "pointer", whiteSpace: "nowrap" }}\>  
              \+ Add Domain  
            \</button\>  
          \</div\>  
        \</div\>

        \<div style={{ marginTop: 14, background: "\#060c18", border: "1px solid \#1a2540", borderRadius: 7, padding: "10px 12px" }}\>  
          \<p style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 10, margin: "0 0 4px", fontWeight: 700 }}\>HOW BOOST WORKS\</p\>  
          \<p style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, margin: 0, lineHeight: 1.6 }}\>  
            Preferred domains receive a \+0.12–0.15 bonus on the source tier scoring component. When multiple validated candidates exist for a claim, preferred domains are selected first all else being equal.  
          \</p\>  
        \</div\>

        \<div style={{ display: "flex", justifyContent: "flex-end", marginTop: 16 }}\>  
          \<button onClick={handleSave}  
            style={{ background: saved ? "\#4ade8015" : "\#38bdf815", border: \`1px solid ${saved ? "\#4ade8040" : "\#38bdf840"}\`, color: saved ? "\#4ade80" : "\#38bdf8", fontFamily: "monospace", fontSize: 12, padding: "7px 20px", borderRadius: 6, cursor: "pointer", transition: "all 0.3s" }}\>  
            {saved ? "✓ Saved" : "Save Domain Lists"}  
          \</button\>  
        \</div\>  
      \</Card\>  
    \</div\>  
  );  
};

// ─── INSTRUCTIONS EDITOR ───────────────────────────────────────────────────────  
const InstructionsEditor \= () \=\> {  
  const DEFAULT \= \`\# External Linking Agent Instructions

\#\# Source Hierarchy Rules  
\- Always prefer .gov and .edu sources over commercial sources for factual claims  
\- For health-related statistics, require PubMed or NIH as the source — reject all others  
\- Academic papers take priority over journalism even if journalism is more recent  
\- Wikipedia is acceptable for definitions only — never for statistical or factual claims

\#\# No-Follow Rules  
\- All links to commercial product pages must use rel="nofollow"  
\- Links to social media platforms always nofollow  
\- Only government, academic, and established news sources get dofollow treatment  
\- If uncertain, default to nofollow

\#\# Freshness Requirements  
\- Marketing statistics: reject any source older than 2 years (override default 3-year limit)  
\- Technology specifications: reject sources older than 18 months  
\- Legal/regulatory references: always use the most recent version available  
\- Scientific consensus topics: peer-reviewed papers within 5 years acceptable

\#\# Claim Type Overrides  
\- Never add external links to product review sections — keep readers on our pages  
\- Definition anchors: only link to official documentation, never to third-party tutorials  
\- For "according to \[organization\]" citations, only resolve if the organization is on our preferred list

\#\# Link Density  
\- Maximum 2 external links per 500 words of content  
\- No two external links within the same paragraph  
\- Long-form guides (\>3000 words): max 6 external links total

\#\# Competitor Guard  
\- Any domain that sells SEO tools is a competitor — do not link  
\- Any affiliate marketing blog is off-limits regardless of content quality  
\`;

  const \[val, setVal\] \= useState(DEFAULT);  
  const \[saved, setSaved\] \= useState(false);  
  const snippets \= \[  
    { label: "+ Source Priority Rule", text: "\\n- For \[topic\] claims, require \[domain.com\] as source\\n" },  
    { label: "+ No-Follow Rule", text: "\\n- All links to \[type\] pages must use rel=\\"nofollow\\"\\n" },  
    { label: "+ Freshness Override", text: "\\n- \[topic\] statistics: reject sources older than \[N\] years\\n" },  
    { label: "+ Competitor Guard", text: "\\n- Any domain that \[description\] is a competitor — do not link\\n" },  
    { label: "+ Density Rule", text: "\\n- Maximum \[N\] external links per \[N\] words on \[post-type\] posts\\n" },  
  \];

  return (  
    \<div\>  
      \<div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 10 }}\>  
        {snippets.map(s \=\> (  
          \<button key={s.label} onClick={() \=\> setVal(v \=\> v \+ s.text)}  
            style={{ background: "\#0a0f1a", border: "1px solid \#1a2540", color: "\#3d5280", fontFamily: "monospace", fontSize: 10, padding: "5px 10px", borderRadius: 5, cursor: "pointer", transition: "border-color 0.15s" }}  
            onMouseEnter={e \=\> e.target.style.borderColor \= "\#38bdf8"}  
            onMouseLeave={e \=\> e.target.style.borderColor \= "\#1a2540"}\>  
            {s.label}  
          \</button\>  
        ))}  
      \</div\>  
      \<div style={{ position: "relative" }}\>  
        \<textarea value={val} onChange={e \=\> setVal(e.target.value)}  
          style={{ width: "100%", minHeight: 340, background: "\#060c18", border: "1px solid \#1a2540", borderRadius: 8, color: "\#8ba3cc", fontFamily: "monospace", fontSize: 12, padding: "16px", resize: "vertical", lineHeight: 1.75, outline: "none", boxSizing: "border-box", transition: "border-color 0.15s" }}  
          onFocus={e \=\> e.target.style.borderColor \= "\#2a3860"}  
          onBlur={e \=\> e.target.style.borderColor \= "\#1a2540"} /\>  
        \<div style={{ position: "absolute", top: 10, right: 12 }}\>\<Pill color="\#2a3860"\>Markdown\</Pill\>\</div\>  
      \</div\>

      {/\* Execution order \*/}  
      \<div style={{ background: "\#38bdf808", border: "1px solid \#38bdf818", borderRadius: 8, padding: "12px 14px", marginTop: 12, display: "flex", gap: 10 }}\>  
        \<span style={{ fontSize: 16 }}\>⚡\</span\>  
        \<div\>  
          \<p style={{ color: "\#38bdf8", fontFamily: "monospace", fontSize: 11, fontWeight: 700, margin: "0 0 4px" }}\>Execution Order\</p\>  
          \<p style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 10, margin: 0, lineHeight: 1.7 }}\>  
            \<span style={{ color: "\#c4d4f0" }}\>1st:\</span\> Your instructions above (highest priority) →{" "}  
            \<span style={{ color: "\#c4d4f0" }}\>2nd:\</span\> Domain blocklist \+ preferred list →{" "}  
            \<span style={{ color: "\#c4d4f0" }}\>3rd:\</span\> Algorithm config settings →{" "}  
            \<span style={{ color: "\#c4d4f0" }}\>4th:\</span\> Hard safety rules (no dofollow spam, no competitors, no broken URLs — cannot be overridden)  
          \</p\>  
        \</div\>  
      \</div\>  
      \<div style={{ display: "flex", justifyContent: "flex-end", gap: 10, marginTop: 14 }}\>  
        \<button onClick={() \=\> setVal(DEFAULT)} style={{ background: "none", border: "1px solid \#1a2540", color: "\#3d5280", fontFamily: "monospace", fontSize: 12, padding: "7px 16px", borderRadius: 6, cursor: "pointer" }}\>Reset\</button\>  
        \<button onClick={() \=\> { setSaved(true); setTimeout(() \=\> setSaved(false), 2200); }}  
          style={{ background: saved ? "\#4ade8012" : "\#38bdf812", border: \`1px solid ${saved ? "\#4ade8030" : "\#38bdf830"}\`, color: saved ? "\#4ade80" : "\#38bdf8", fontFamily: "monospace", fontSize: 12, padding: "7px 20px", borderRadius: 6, cursor: "pointer", transition: "all 0.3s" }}\>  
          {saved ? "✓ Saved" : "Save Instructions"}  
        \</button\>  
      \</div\>  
    \</div\>  
  );  
};

// ─── ALGORITHM CONFIG ──────────────────────────────────────────────────────────  
const AlgorithmConfig \= () \=\> {  
  const \[s, setS\] \= useState({  
    minConfidence: 68, factWeight: 30, relevanceWeight: 25, authorityWeight: 20, tierWeight: 15, freshnessWeight: 10,  
    maxExtLinksPost: 6, maxExtLinksPerWords: 500, minWordsBetween: 200,  
    statMaxAge: 3, techMaxAge: 2, evergreenNoAge: true,  
    requireHttps: true, minAuthorityScore: 45, requireSsl: true,  
    autoNofollow: false, defaultNofollow: false, govEduDofollow: true,  
    autoApprove: false, autoApproveThreshold: 92,  
    linkRot: true, linkRotCheckDays: 30,  
  });  
  const upd \= (k, v) \=\> setS(p \=\> ({ ...p, \[k\]: v }));  
  const totalW \= s.factWeight \+ s.relevanceWeight \+ s.authorityWeight \+ s.tierWeight \+ s.freshnessWeight;

  const Slider \= ({ label, k, min \= 0, max \= 100, unit \= "%", color \= "\#38bdf8" }) \=\> (  
    \<div style={{ marginBottom: 14 }}\>  
      \<div style={{ display: "flex", justifyContent: "space-between", marginBottom: 5 }}\>  
        \<span style={{ color: "\#8ba3cc", fontFamily: "monospace", fontSize: 11 }}\>{label}\</span\>  
        \<span style={{ color, fontFamily: "monospace", fontSize: 11, fontWeight: 700 }}\>{s\[k\]}{unit}\</span\>  
      \</div\>  
      \<input type="range" min={min} max={max} value={s\[k\]} onChange={e \=\> upd(k, Number(e.target.value))} style={{ width: "100%", accentColor: color }} /\>  
    \</div\>  
  );

  const Toggle \= ({ label, k, desc }) \=\> (  
    \<div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", padding: "9px 0", borderBottom: "1px solid \#0a0f1a" }}\>  
      \<div\>  
        \<p style={{ color: "\#8ba3cc", fontFamily: "monospace", fontSize: 11, margin: "0 0 2px" }}\>{label}\</p\>  
        {desc && \<p style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, margin: 0 }}\>{desc}\</p\>}  
      \</div\>  
      \<div onClick={() \=\> upd(k, \!s\[k\])}  
        style={{ width: 38, height: 22, borderRadius: 11, background: s\[k\] ? "\#38bdf8" : "\#1a2540", cursor: "pointer", position: "relative", transition: "background 0.2s", flexShrink: 0, marginLeft: 12 }}\>  
        \<div style={{ position: "absolute", width: 16, height: 16, borderRadius: "50%", background: "\#fff", top: 3, left: s\[k\] ? 19 : 3, transition: "left 0.2s" }} /\>  
      \</div\>  
    \</div\>  
  );

  return (  
    \<div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr 1fr", gap: 14 }}\>  
      \<Card\>  
        \<Label\>Scoring Weights\</Label\>  
        \<Slider label="Fact Verification" k="factWeight" color="\#e879f9" /\>  
        \<Slider label="Content Relevance" k="relevanceWeight" color="\#38bdf8" /\>  
        \<Slider label="Domain Authority" k="authorityWeight" color="\#4ade80" /\>  
        \<Slider label="Source Tier" k="tierWeight" color="\#a78bfa" /\>  
        \<Slider label="Freshness" k="freshnessWeight" color="\#fb923c" /\>  
        \<div style={{ display: "flex", justifyContent: "space-between", paddingTop: 8, borderTop: "1px solid \#1a2540" }}\>  
          \<span style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 10 }}\>Total\</span\>  
          \<span style={{ color: totalW \=== 100 ? "\#4ade80" : "\#f87171", fontFamily: "monospace", fontSize: 11, fontWeight: 700 }}\>  
            {totalW}% {totalW \!== 100 ? "⚠ must \= 100%" : "✓"}  
          \</span\>  
        \</div\>  
      \</Card\>

      \<Card\>  
        \<Label\>Thresholds\</Label\>  
        \<Slider label="Min Confidence Score" k="minConfidence" /\>  
        \<Slider label="Min Authority Score" k="minAuthorityScore" color="\#4ade80" /\>  
        \<Slider label="Max External Links / Post" k="maxExtLinksPost" min={1} max={20} unit="" color="\#a78bfa" /\>  
        \<Slider label="Words Between Links (min)" k="minWordsBetween" min={50} max={500} unit="" color="\#fb923c" /\>  
        {s.autoApprove && \<Slider label="Auto-Approve Threshold" k="autoApproveThreshold" color="\#f59e0b" /\>}  
        \<div style={{ paddingTop: 8, borderTop: "1px solid \#1a2540" }}\>  
          \<Toggle label="Auto-Approve High Confidence" k="autoApprove" desc="Skip review queue above threshold" /\>  
        \</div\>  
      \</Card\>

      \<Card\>  
        \<Label\>Freshness Rules\</Label\>  
        \<Slider label="Statistics Max Age (years)" k="statMaxAge" min={1} max={10} unit=" yrs" color="\#fb923c" /\>  
        \<Slider label="Tech Specs Max Age (years)" k="techMaxAge" min={1} max={5} unit=" yrs" color="\#f59e0b" /\>  
        \<Toggle label="Evergreen content — no age limit" k="evergreenNoAge" desc="Official docs, glossaries, standards" /\>  
        \<div style={{ marginTop: 12, paddingTop: 12, borderTop: "1px solid \#1a2540" }}\>  
          \<Label\>Link Health\</Label\>  
          \<Toggle label="Enable Link Rot Detection" k="linkRot" desc="Periodic URL health checks" /\>  
          {s.linkRot && \<Slider label="Check Interval (days)" k="linkRotCheckDays" min={7} max={90} unit=" d" color="\#38bdf8" /\>}  
        \</div\>  
      \</Card\>

      \<Card\>  
        \<Label\>Security & rel= Rules\</Label\>  
        \<Toggle label="Require HTTPS only" k="requireHttps" desc="Reject all HTTP URLs" /\>  
        \<Toggle label="Require valid SSL cert" k="requireSsl" /\>  
        \<Toggle label=".gov/.edu always dofollow" k="govEduDofollow" desc="Trust high-authority public sources" /\>  
        \<Toggle label="Default all links nofollow" k="defaultNofollow" desc="Override per-suggestion as needed" /\>  
        \<div style={{ marginTop: 12, background: "\#060c18", border: "1px solid \#1a2540", borderRadius: 7, padding: "10px 12px" }}\>  
          \<p style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 10, margin: "0 0 6px", fontWeight: 700 }}\>HARD SAFETY RULES\</p\>  
          {\["Never link to known spam/malware domains", "Never link to direct competitors", "Never link to broken URLs (404/5xx)", "AI must verify claim before any link is placed"\].map(r \=\> (  
            \<p key={r} style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, margin: "3px 0", lineHeight: 1.5 }}\>✓ {r}\</p\>  
          ))}  
        \</div\>  
      \</Card\>  
    \</div\>  
  );  
};

// ─── VALIDATION PIPELINE VISUAL ────────────────────────────────────────────────  
const PipelineVisual \= () \=\> {  
  const stats \= \[  
    { label: "URLs Discovered", value: 1840, color: "\#38bdf8" },  
    { label: "Passed Gate 1 (Live)", value: 1690, color: "\#4ade80" },  
    { label: "Passed Gate 3 (Blocklist)", value: 1580, color: "\#a78bfa" },  
    { label: "Passed Gate 4 (Relevance)", value: 820, color: "\#f59e0b" },  
    { label: "Passed Gate 5 (Authority)", value: 610, color: "\#fb923c" },  
    { label: "Passed Gate 6 (Freshness)", value: 490, color: "\#e879f9" },  
    { label: "Passed Gate 7 (AI Verify)", value: 340, color: "\#4ade80" },  
    { label: "Final Suggestions", value: 203, color: "\#38bdf8" },  
  \];

  return (  
    \<div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }}\>  
      {/\* Funnel chart \*/}  
      \<Card\>  
        \<Label\>Validation Funnel — All-Time\</Label\>  
        \<div style={{ display: "flex", flexDirection: "column", gap: 8 }}\>  
          {stats.map((item, i) \=\> (  
            \<div key={i} style={{ display: "flex", alignItems: "center", gap: 10 }}\>  
              \<span style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 10, width: 200, flexShrink: 0 }}\>{item.label}\</span\>  
              \<div style={{ flex: 1, background: "\#0a0f1a", borderRadius: 4, height: 20, overflow: "hidden", position: "relative" }}\>  
                \<div style={{ width: \`${(item.value / 1840\) \* 100}%\`, height: "100%", background: \`${item.color}25\`, borderRight: \`2px solid ${item.color}\`, borderRadius: 4 }} /\>  
              \</div\>  
              \<span style={{ color: item.color, fontFamily: "monospace", fontSize: 11, fontWeight: 700, width: 40, textAlign: "right" }}\>{item.value}\</span\>  
            \</div\>  
          ))}  
        \</div\>  
      \</Card\>

      {/\* Gates detail \*/}  
      \<Card\>  
        \<Label\>7 Validation Gates\</Label\>  
        \<div style={{ display: "flex", flexDirection: "column", gap: 0 }}\>  
          {VALIDATION\_GATES.map((gate, i, arr) \=\> (  
            \<div key={gate.id} style={{ display: "flex", gap: 12, alignItems: "flex-start" }}\>  
              \<div style={{ display: "flex", flexDirection: "column", alignItems: "center" }}\>  
                \<div style={{ width: 30, height: 30, borderRadius: "50%", background: \`${gate.color}12\`, border: \`1px solid ${gate.color}35\`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 13, flexShrink: 0 }}\>  
                  {gate.icon}  
                \</div\>  
                {i \< arr.length \- 1 && \<div style={{ width: 1, height: 16, background: "\#1a2540" }} /\>}  
              \</div\>  
              \<div style={{ paddingBottom: 4, paddingTop: 4 }}\>  
                \<p style={{ color: gate.color, fontFamily: "monospace", fontSize: 11, fontWeight: 700, margin: "0 0 1px" }}\>Gate {gate.id}: {gate.label}\</p\>  
                \<p style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, margin: 0, lineHeight: 1.5 }}\>{gate.desc}\</p\>  
              \</div\>  
            \</div\>  
          ))}  
        \</div\>  
      \</Card\>

      {/\* Source tier breakdown \*/}  
      \<Card\>  
        \<Label\>Source Tier Distribution (Approved Links)\</Label\>  
        {\[\["Primary (original research, .gov, .edu)", 68, "\#4ade80"\], \["Secondary (journalism citing research)", 24, "\#f59e0b"\], \["Tertiary (blogs summarizing journalism)", 8, "\#f87171"\]\].map((\[label, value, color\]) \=\> (  
          \<div key={label} style={{ marginBottom: 12 }}\>  
            \<div style={{ display: "flex", justifyContent: "space-between", marginBottom: 5 }}\>  
              \<span style={{ color: "\#5a7299", fontFamily: "monospace", fontSize: 10 }}\>{label}\</span\>  
              \<span style={{ color, fontFamily: "monospace", fontSize: 11, fontWeight: 700 }}\>{value}%\</span\>  
            \</div\>  
            \<Bar value={value / 100} color={color} /\>  
          \</div\>  
        ))}  
      \</Card\>

      {/\* Claim type breakdown \*/}  
      \<Card\>  
        \<Label\>Links by Claim Type\</Label\>  
        {\[\["Statistical Claims", 44, "\#f59e0b"\], \["Factual Assertions", 31, "\#38bdf8"\], \["Research References", 18, "\#a78bfa"\], \["Definition Anchors", 7, "\#4ade80"\]\].map((\[label, value, color\]) \=\> (  
          \<div key={label} style={{ marginBottom: 12 }}\>  
            \<div style={{ display: "flex", justifyContent: "space-between", marginBottom: 5 }}\>  
              \<span style={{ color: "\#5a7299", fontFamily: "monospace", fontSize: 10 }}\>{label}\</span\>  
              \<span style={{ color, fontFamily: "monospace", fontSize: 11, fontWeight: 700 }}\>{value}%\</span\>  
            \</div\>  
            \<Bar value={value / 100} color={color} /\>  
          \</div\>  
        ))}  
      \</Card\>  
    \</div\>  
  );  
};

// ─── MAIN ──────────────────────────────────────────────────────────────────────  
export default function ExternalLinkingPanel() {  
  const \[tab, setTab\] \= useState("suggestions");  
  const \[suggestions, setSuggestions\] \= useState(SUGGESTIONS);  
  const \[filterStatus, setFilterStatus\] \= useState("all");  
  const \[filterClaim, setFilterClaim\] \= useState("all");  
  const \[running, setRunning\] \= useState(false);  
  const \[progress, setProgress\] \= useState(0);  
  const \[progressLabel, setProgressLabel\] \= useState("");

  const approve \= id \=\> setSuggestions(s \=\> s.map(x \=\> x.id \=== id ? { ...x, status: "approved" } : x));  
  const reject \= id \=\> setSuggestions(s \=\> s.map(x \=\> x.id \=== id ? { ...x, status: "rejected" } : x));  
  const toggleNofollow \= id \=\> setSuggestions(s \=\> s.map(x \=\> x.id \=== id ? { ...x, nofollow: \!x.nofollow } : x));  
  const approveAll \= () \=\> setSuggestions(s \=\> s.map(x \=\> x.status \=== "pending" ? { ...x, status: "approved" } : x));

  const runScan \= () \=\> {  
    setRunning(true); setProgress(0);  
    const steps \= \[  
      \[12, "Extracting claims from content…"\],  
      \[26, "Running Track A: Authority registry…"\],  
      \[38, "Running Track B: AI search resolution…"\],  
      \[51, "Running Track C: Citation archaeology…"\],  
      \[64, "Validating URLs through 7 gates…"\],  
      \[76, "Running AI fact verification…"\],  
      \[88, "Scoring and ranking candidates…"\],  
      \[100, "Done"\],  
    \];  
    steps.forEach((\[p, label\], i) \=\> setTimeout(() \=\> {  
      setProgress(p); setProgressLabel(label);  
      if (p \=== 100\) setTimeout(() \=\> { setRunning(false); setProgressLabel(""); }, 800);  
    }, i \* 500));  
  };

  const filtered \= suggestions.filter(s \=\>  
    (filterStatus \=== "all" || s.status \=== filterStatus) &&  
    (filterClaim \=== "all" || s.claimType \=== filterClaim)  
  );  
  const pending \= suggestions.filter(s \=\> s.status \=== "pending").length;

  const tabs \= \[  
    { id: "suggestions", label: "Link Suggestions", badge: pending },  
    { id: "blocklist", label: "Domain Lists" },  
    { id: "instructions", label: "Agent Instructions" },  
    { id: "algorithm", label: "Algorithm Config" },  
    { id: "pipeline", label: "Validation Pipeline" },  
  \];

  return (  
    \<div style={{ background: "\#060c18", minHeight: "100vh", fontFamily: "'Outfit', sans-serif" }}\>  
      \<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800\&display=swap" rel="stylesheet" /\>

      {/\* Header \*/}  
      \<div style={{ background: "linear-gradient(180deg, \#0a0f1a 0%, \#060c18 100%)", borderBottom: "1px solid \#1a2540", padding: "18px 28px 0" }}\>  
        \<div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 18 }}\>  
          \<div style={{ width: 38, height: 38, background: "linear-gradient(135deg, \#38bdf8, \#6366f1)", borderRadius: 10, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 20 }}\>🌐\</div\>  
          \<div\>  
            \<h2 style={{ fontFamily: "'Outfit', sans-serif", fontSize: 18, fontWeight: 800, margin: 0, letterSpacing: "-0.03em", color: "\#e8f0fe" }}\>External Linking Agent\</h2\>  
            \<p style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, margin: 0, letterSpacing: "0.1em" }}\>7-GATE VALIDATION · AI FACT VERIFICATION · 3-TRACK DISCOVERY\</p\>  
          \</div\>  
          \<div style={{ marginLeft: "auto", display: "flex", gap: 8, alignItems: "center" }}\>  
            \<Pill color="\#a78bfa"\>{pending} PENDING\</Pill\>  
            \<Pill color="\#4ade80"\>{SITE\_STATS.approved} APPROVED\</Pill\>  
            \<Pill color="\#f87171"\>{SITE\_STATS.brokenLinksFixed} BROKEN FIXED\</Pill\>  
            \<button onClick={runScan} disabled={running}  
              style={{ background: running ? "\#0a0f1a" : "linear-gradient(135deg, \#38bdf8, \#6366f1)", border: running ? "1px solid \#1a2540" : "none", color: running ? "\#3d5280" : "\#060c18", fontFamily: "monospace", fontSize: 11, fontWeight: 800, padding: "8px 18px", borderRadius: 7, cursor: running ? "not-allowed" : "pointer", transition: "all 0.2s", marginLeft: 4 }}\>  
              {running ? \`${progressLabel} ${progress}%\` : "▶ Scan Content"}  
            \</button\>  
          \</div\>  
        \</div\>

        {running && (  
          \<div style={{ background: "\#0a0f1a", borderRadius: 99, height: 3, overflow: "hidden", marginBottom: 2 }}\>  
            \<div style={{ width: \`${progress}%\`, height: "100%", background: "linear-gradient(90deg, \#38bdf8, \#6366f1)", transition: "width 0.5s ease", borderRadius: 99 }} /\>  
          \</div\>  
        )}

        \<div style={{ display: "flex", gap: 0 }}\>  
          {tabs.map(t \=\> (  
            \<button key={t.id} onClick={() \=\> setTab(t.id)}  
              style={{ background: "none", border: "none", cursor: "pointer", padding: "10px 16px", display: "flex", alignItems: "center", gap: 7, fontFamily: "'Outfit', sans-serif", fontSize: 13, fontWeight: tab \=== t.id ? 700 : 500, color: tab \=== t.id ? "\#38bdf8" : "\#3d5280", borderBottom: \`2px solid ${tab \=== t.id ? "\#38bdf8" : "transparent"}\`, transition: "all 0.15s" }}\>  
              {t.label}  
              {t.badge \> 0 && \<span style={{ background: "\#38bdf815", color: "\#38bdf8", border: "1px solid \#38bdf825", fontFamily: "monospace", fontSize: 10, padding: "1px 6px", borderRadius: 99 }}\>{t.badge}\</span\>}  
            \</button\>  
          ))}  
        \</div\>  
      \</div\>

      {/\* Content \*/}  
      \<div style={{ padding: "24px 28px" }}\>

        {tab \=== "suggestions" && (  
          \<div\>  
            \<div style={{ display: "flex", gap: 8, marginBottom: 18, alignItems: "center", flexWrap: "wrap" }}\>  
              \<span style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, textTransform: "uppercase" }}\>Status:\</span\>  
              {\["all", "pending", "approved", "rejected"\].map(f \=\> (  
                \<button key={f} onClick={() \=\> setFilterStatus(f)}  
                  style={{ background: filterStatus \=== f ? "\#38bdf810" : "none", border: \`1px solid ${filterStatus \=== f ? "\#38bdf830" : "\#1a2540"}\`, color: filterStatus \=== f ? "\#38bdf8" : "\#3d5280", fontFamily: "monospace", fontSize: 10, padding: "4px 12px", borderRadius: 99, cursor: "pointer", textTransform: "uppercase" }}\>  
                  {f}  
                \</button\>  
              ))}  
              \<div style={{ width: 1, height: 18, background: "\#1a2540", margin: "0 4px" }} /\>  
              \<span style={{ color: "\#2a3860", fontFamily: "monospace", fontSize: 10, textTransform: "uppercase" }}\>Claim:\</span\>  
              {\["all", "statistical", "factual", "research", "definition"\].map(f \=\> (  
                \<button key={f} onClick={() \=\> setFilterClaim(f)}  
                  style={{ background: filterClaim \=== f ? \`${(CLAIM\_COLORS\[f\] || "\#38bdf8")}12\` : "none", border: \`1px solid ${filterClaim \=== f ? \`${(CLAIM\_COLORS\[f\] || "\#38bdf8")}30\` : "\#1a2540"}\`, color: filterClaim \=== f ? (CLAIM\_COLORS\[f\] || "\#38bdf8") : "\#3d5280", fontFamily: "monospace", fontSize: 10, padding: "4px 12px", borderRadius: 99, cursor: "pointer", textTransform: "capitalize" }}\>  
                  {f}  
                \</button\>  
              ))}  
              \<div style={{ marginLeft: "auto" }}\>  
                \<button onClick={approveAll} style={{ background: "\#4ade8010", border: "1px solid \#4ade8025", color: "\#4ade80", fontFamily: "monospace", fontSize: 11, padding: "6px 14px", borderRadius: 6, cursor: "pointer" }}\>  
                  ✓ Approve All Pending  
                \</button\>  
              \</div\>  
            \</div\>  
            {filtered.length \=== 0 ? (  
              \<Card style={{ textAlign: "center", padding: 40 }}\>  
                \<p style={{ color: "\#1a2540", fontFamily: "monospace", fontSize: 13 }}\>No suggestions match filters.\</p\>  
              \</Card\>  
            ) : (  
              filtered.map(s \=\> \<SuggestionCard key={s.id} s={s} onApprove={approve} onReject={reject} onToggleNofollow={toggleNofollow} /\>)  
            )}  
          \</div\>  
        )}

        {tab \=== "blocklist" && \<BlocklistManager /\>}

        {tab \=== "instructions" && (  
          \<div style={{ maxWidth: 880 }}\>  
            \<div style={{ marginBottom: 18 }}\>  
              \<h3 style={{ fontFamily: "'Outfit', sans-serif", fontSize: 16, fontWeight: 700, color: "\#e8f0fe", margin: "0 0 4px", letterSpacing: "-0.02em" }}\>Agent Instructions\</h3\>  
              \<p style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 11, margin: 0 }}\>Write rules in plain English or Markdown. Prepended to system prompt at highest priority on every scan.\</p\>  
            \</div\>  
            \<Card\>\<InstructionsEditor /\>\</Card\>  
          \</div\>  
        )}

        {tab \=== "algorithm" && (  
          \<div\>  
            \<div style={{ marginBottom: 18 }}\>  
              \<h3 style={{ fontFamily: "'Outfit', sans-serif", fontSize: 16, fontWeight: 700, color: "\#e8f0fe", margin: "0 0 4px", letterSpacing: "-0.02em" }}\>Algorithm Configuration\</h3\>  
              \<p style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 11, margin: 0 }}\>Tune scoring weights, freshness rules, security settings, and automation thresholds. Weights must sum to 100%.\</p\>  
            \</div\>  
            \<AlgorithmConfig /\>  
          \</div\>  
        )}

        {tab \=== "pipeline" && (  
          \<div\>  
            \<div style={{ marginBottom: 18 }}\>  
              \<h3 style={{ fontFamily: "'Outfit', sans-serif", fontSize: 16, fontWeight: 700, color: "\#e8f0fe", margin: "0 0 4px", letterSpacing: "-0.02em" }}\>Validation Pipeline\</h3\>  
              \<p style={{ color: "\#3d5280", fontFamily: "monospace", fontSize: 11, margin: 0 }}\>7-gate trust gauntlet — every candidate URL passes all gates before being scored. Funnel shows cumulative pass/fail rates.\</p\>  
            \</div\>  
            \<PipelineVisual /\>  
          \</div\>  
        )}  
      \</div\>  
    \</div\>  
  );  
}  
