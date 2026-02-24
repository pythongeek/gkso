## **How the Internal Linking Agent Works**

### **Phase 1: Site-Wide Content Indexing**

Before any linking decision is made, the agent builds a **semantic index** of every published post. This runs once on activation and incrementally on each new publish. For every post it stores:

* **TF-IDF vector** — a numerical fingerprint of the post's content, weighted by how rare each term is across the whole site. "WordPress" appearing on 80% of posts scores near zero; "schema markup for recipes" appearing on 2 posts scores very high.  
* **Named entities** — people, products, tools, concepts extracted by the AI (e.g. "Elementor", "Core Web Vitals", "REST API")  
* **Target keywords** — pulled directly from Yoast/Rank Math meta fields, so the index knows the *intended* topic not just the written topic  
* **Outbound link map** — which URLs this post already links to (prevents duplicates)  
* **Inbound link count** — how many posts currently link *to* this post (used to prioritize orphaned pages)

This index lives in a custom database table (`wp_gkso_link_index`) and is the foundation everything else queries against.

---

### **Phase 2: Anchor Text Candidate Generation**

When the agent processes a post for linking, it scans the content and extracts **anchor text candidates** — phrases that *could* become links. The algorithm generates candidates through three methods simultaneously:

**Method A — Noun Phrase Extraction.** The AI parses every sentence and pulls out noun phrases (2–5 words). "the best caching plugin" and "WordPress performance optimization" are candidates. Single words are rejected — too vague, too likely to cause over-linking.

**Method B — Keyword Reverse Lookup.** The agent queries its index: "which posts have target keywords that appear verbatim in this post's content?" If the content says "mobile-first indexing" and there's a post whose Rank Math focus keyword is exactly "mobile-first indexing", that's a direct match candidate with a high confidence score before any AI evaluation.

**Method C — Semantic Phrase Matching.** Using cosine similarity between the source paragraph's embedding and candidate target page embeddings, the agent finds phrases that are *topically close even if not keyword-identical*. "page experience signals" in the source might match a target post about Core Web Vitals.

Each candidate enters a **filtering pipeline** before scoring:

Candidate phrase  
    → Already linked in this post? → DISCARD  
    → Inside a heading tag (h1-h4)? → DISCARD  
    → Fewer than 2 words? → DISCARD  
    → Would exceed link density (1 per 150 words)? → DEFER to lower priority queue  
    → Is this the current post's own URL? → DISCARD  
    → PASS → enter scoring  
---

### **Phase 3: URL-to-Anchor Matching Algorithm**

This is the core decision engine. For each surviving candidate anchor phrase, the agent scores every possible target URL and picks the best match. The scoring formula:

match\_score \=   
  (semantic\_similarity    × 0.35)  \+  
  (keyword\_alignment      × 0.30)  \+  
  (authority\_score        × 0.15)  \+  
  (orphan\_priority\_boost  × 0.10)  \+  
  (recency\_score          × 0.10)

**Semantic Similarity (0.35 weight)** — cosine similarity between the anchor phrase's embedding and the target page's full TF-IDF vector. Ranges 0–1. A score below 0.45 is an automatic discard regardless of other scores.

**Keyword Alignment (0.30 weight)** — does the anchor phrase contain or semantically overlap with the target page's focus keyword? Exact match \= 1.0, partial match \= 0.5–0.8, semantic-only \= 0.3–0.5. This is the signal that says "the anchor text is *about* what the target page is *for*".

**Authority Score (0.15 weight)** — target page's normalized traffic \+ ranking position from the GSC/GA4 index already built by the SEO optimization system. High-traffic pages get a slight boost because linking to them is more valuable for the user; but orphan pages (next signal) counter-balance this.

**Orphan Priority Boost (0.10 weight)** — posts with zero or one inbound internal links get a 0.8–1.0 boost score. Posts with 10+ inbound links get 0.1. This prevents your homepage and top 3 posts from hoarding all internal link equity.

**Recency Score (0.10 weight)** — posts published in the last 60 days get a higher score to accelerate their indexing by Google. Older content scores lower here since it's already indexed.

A final **minimum threshold of 0.62** on the combined score gates what gets suggested. Below that, no link is placed — the agent would rather suggest nothing than place a weak link.

---

### **Phase 4: Placement & Position Logic**

Once a match is confirmed, the agent decides *where* in the content to place it:

* **First-third preference** — links in the opening third of a post carry more crawl and ranking weight. The agent prefers placement here if a natural candidate exists.  
* **One URL per post rule** — even if "WordPress caching" appears 6 times and all match the same target URL, only the first occurrence gets linked.  
* **Paragraph-level density check** — no paragraph gets more than 2 links regardless of overall link density passing.  
* **Proximity deduplication** — two links cannot be placed within 50 words of each other.  
* **Context window validation** — the agent passes the surrounding 2 sentences to the AI with the instruction: *"Does placing a hyperlink on \[anchor\] feel natural here, or does it interrupt reading flow?"* If the AI returns `natural: false`, it tries the next candidate occurrence of the same phrase.

---

### **Phase 5: User Instruction Override Layer**

This is where your custom instruction field integrates. The agent has a **default system prompt** but every execution prepends the user's stored instructions. The structure is:

\[USER INSTRUCTIONS — highest priority\]  
{user\_instructions\_field}

\[DEFAULT SYSTEM RULES — applied after user rules\]  
\- Never link to external domains  
\- Never use generic anchor text ("click here", "read more", "this post")  
\- Never place a link inside a blockquote or table cell  
\- Respect existing manual links — do not replace or move them  
\- Maximum {max\_links\_per\_post} internal links per post  
\- Minimum anchor text length: 2 words  
\- Preferred pillar pages: {pillar\_page\_ids}

If the user writes "always prioritize linking to our buyers guide" — that overrides the orphan boost algorithm for that page. If they write "never link from product review posts to tutorial posts" — that adds a post-type exclusion rule that filters candidates before scoring even starts.

---

### **Integration Architecture**

WordPress post save/update  
    → n8n webhook (trigger: content\_updated)  
    → Phase 1: Update index entry for this post  
    → Phase 2: Generate anchor candidates  
    → Phase 3: Score all URL matches  
    → Phase 4: Apply placement logic  
    → Phase 5: Prepend user instructions, validate with AI  
    → Return: \[{anchor, url, position, confidence, rationale}\]  
    → WordPress: Store as suggestions in \_gkso\_link\_suggestions  
    → Admin: Show in Link Suggestions panel (approve/reject per suggestion)  
    → On approve: inject \<a href="url"\>anchor\</a\> at exact character offset

Now building the full integrated panel:

Created a file, ran a command, read a file  
Created a file, ran a command, read a file  
Internal Linking Agent panel \- integrated with the main SEO dashboard  
internal-linking-panel.jsx  
Copy to outputs  
Script  
Presented file  
Done

Here's the complete breakdown of how everything works and connects.

---

## **The Algorithm in Full**

### **Why Three Candidate Methods Together**

Any single method fails alone. Keyword reverse lookup is precise but misses semantic context — it won't link "page experience signals" to a Core Web Vitals post. Semantic matching catches those but generates false positives on loosely related content. Noun phrase extraction catches naturally linkable phrases but doesn't know which ones have matching target pages. Running all three and letting scoring arbitrate is what makes the matches feel editorially natural rather than mechanically inserted.

### **The Scoring Formula's Logic**

The 35/30/15/10/10 weighting is intentional. Semantic similarity sits highest because a link that's topically irrelevant damages user experience regardless of keyword match — it's the quality floor. Keyword alignment is second because it's the signal Google cares most about for understanding link context. Authority is capped at 15% deliberately, otherwise the algorithm becomes a popularity contest that always links to your homepage. Orphan priority is a site health correction signal — it actively redistributes equity to content that needs it. Recency is last because it's a temporary boost, not a permanent relevance signal.

### **What "Context Window Validation" Means in Practice**

After scoring picks a winner, the agent passes this to the AI:

*"Here is a paragraph: \[text\]. I want to hyperlink the phrase '\[anchor\]'. Does this feel like a natural editorial link that aids the reader, or does it feel forced/spammy? Answer: natural or forced."*

This catches cases where "machine learning" scores well as an anchor phrase for a target URL, but in context it appears inside a sentence like "this requires no machine learning whatsoever" — technically a keyword match, but linking it there would be absurd. The AI validation step is what separates this from dumb keyword-matching plugins.

---

## **The Panel's Four Tabs**

**Suggestions** — every AI-generated link proposal shown as a card. Click any card to expand its signal breakdown (all 5 scoring components visualized as bars) plus the exact context sentence with the anchor phrase highlighted in amber. Approve or reject individually, or bulk-approve all pending at once. Filterable by status and by which generation method produced it.

**Agent Instructions** — the free-text field where you write rules in plain English or Markdown. Snippet insert buttons help you add common rule patterns without typing from scratch. The panel explains exactly where in the execution order your instructions sit (highest priority, before algorithm defaults, before hard safety rules). Save and reset controls at the bottom.

**Algorithm Config** — three-column settings panel. Left column controls confidence thresholds, max links per post, and minimum word spacing. Middle column has slider controls for all five signal weights with a live sum checker that warns you if they don't add up to 100%. Right column is placement rule toggles and automation settings including an auto-approve threshold for high-confidence suggestions.

**Index Monitor** — four health cards (posts indexed, links mapped, orphan count, avg links per post), a pillar page panel showing inbound equity per pillar with the ability to add new ones, and the 6-phase algorithm pipeline shown as a visual flow so anyone on the team can see exactly what each phase does without reading documentation.

---

## **Integration with the Main Panel**

This becomes a sixth tab in the existing SEO Intelligence dashboard alongside Site Overview, Active Tests, Win/Loss History, AI Models, and Post Detail. They share the same dark navy aesthetic, same monospace metric typography, same amber/blue/green accent language. The "Run Analysis" button in the top bar triggers the n8n workflow via the same webhook architecture already built — it just hits a new endpoint `/gemini-kimi-seo/v1/run-link-analysis` that you'd add to the plugin alongside the existing SEO test endpoints.

import { useState, useRef, useEffect } from "react";

// ─── MOCK DATA ─────────────────────────────────────────────────────────────────  
const MOCK\_SUGGESTIONS \= \[  
  {  
    id: 1, postTitle: "10 Best Budget Laptops for Students 2024",  
    anchor: "SSD storage speeds", targetUrl: "/how-to-benchmark-ssd-performance/",  
    targetTitle: "How to Benchmark SSD Performance",  
    confidence: 0.89, position: "paragraph 3", method: "semantic",  
    context: "...models that balance processing power and SSD storage speeds without breaking the budget. When comparing...",  
    signals: { semantic: 0.91, keyword: 0.88, authority: 0.72, orphan: 0.40, recency: 0.65 },  
    status: "pending"  
  },  
  {  
    id: 2, postTitle: "10 Best Budget Laptops for Students 2024",  
    anchor: "battery life benchmarks", targetUrl: "/laptop-battery-life-testing-guide/",  
    targetTitle: "Laptop Battery Life Testing: Complete Guide",  
    confidence: 0.94, position: "paragraph 6", method: "keyword",  
    context: "...we ran our standard battery life benchmarks across 8 hours of simulated workloads to find which...",  
    signals: { semantic: 0.95, keyword: 0.96, authority: 0.58, orphan: 0.90, recency: 0.80 },  
    status: "pending"  
  },  
  {  
    id: 3, postTitle: "Python for Beginners: Complete Course",  
    anchor: "virtual environments", targetUrl: "/python-virtual-environment-setup/",  
    targetTitle: "Python Virtual Environment Setup & Management",  
    confidence: 0.87, position: "paragraph 2", method: "keyword",  
    context: "...recommended to use virtual environments to isolate your project dependencies from the system Python...",  
    signals: { semantic: 0.84, keyword: 0.94, authority: 0.65, orphan: 0.70, recency: 0.55 },  
    status: "approved"  
  },  
  {  
    id: 4, postTitle: "Python for Beginners: Complete Course",  
    anchor: "debugging workflow", targetUrl: "/python-debugging-techniques/",  
    targetTitle: "Python Debugging Techniques for Beginners",  
    confidence: 0.78, position: "paragraph 9", method: "semantic",  
    context: "...once you're comfortable with the basics, establishing a consistent debugging workflow will save hours...",  
    signals: { semantic: 0.82, keyword: 0.74, authority: 0.71, orphan: 0.30, recency: 0.62 },  
    status: "rejected"  
  },  
  {  
    id: 5, postTitle: "Ultimate Guide to Email Marketing ROI",  
    anchor: "A/B split testing campaigns", targetUrl: "/email-ab-testing-guide/",  
    targetTitle: "Email A/B Testing: Subject Lines, CTAs & Timing",  
    confidence: 0.92, position: "paragraph 4", method: "ensemble",  
    context: "...measuring revenue lift becomes significantly more accurate when you implement A/B split testing campaigns alongside your main sends...",  
    signals: { semantic: 0.90, keyword: 0.93, authority: 0.80, orphan: 0.55, recency: 0.45 },  
    status: "pending"  
  },  
  {  
    id: 6, postTitle: "Shopify vs WooCommerce: Full Comparison",  
    anchor: "payment gateway fees", targetUrl: "/shopify-payment-processing-costs/",  
    targetTitle: "Shopify Payments vs Third-Party Gateways: Full Cost Breakdown",  
    confidence: 0.83, position: "paragraph 7", method: "semantic",  
    context: "...the real cost difference becomes apparent when you factor in payment gateway fees across different monthly transaction volumes...",  
    signals: { semantic: 0.85, keyword: 0.80, authority: 0.74, orphan: 0.82, recency: 0.38 },  
    status: "pending"  
  },  
\];

const LINK\_STATS \= {  
  totalSuggestions: 148, approved: 97, rejected: 23, pending: 28,  
  avgConfidence: 0.86, orphansSolved: 34, postsAnalyzed: 312,  
};

const INDEX\_HEALTH \= \[  
  { label: "Posts Indexed", value: 312, max: 384, color: "\#f59e0b" },  
  { label: "Links Mapped", value: 2840, max: 3200, color: "\#38bdf8" },  
  { label: "Orphan Pages", value: 18, max: 50, color: "\#f87171" },  
  { label: "Avg Links/Post", value: 4.2, max: 8, color: "\#4ade80" },  
\];

const PILLAR\_PAGES \= \[  
  { id: 1, title: "Complete WordPress SEO Guide", url: "/wordpress-seo-guide/", inboundLinks: 24, priority: "high" },  
  { id: 2, title: "Python Learning Roadmap", url: "/python-learning-roadmap/", inboundLinks: 17, priority: "high" },  
  { id: 3, title: "Email Marketing Masterclass", url: "/email-marketing-masterclass/", inboundLinks: 11, priority: "medium" },  
\];

const METHOD\_LABELS \= { keyword: { label: "Keyword Match", color: "\#f59e0b" }, semantic: { label: "Semantic", color: "\#38bdf8" }, ensemble: { label: "Ensemble", color: "\#a78bfa" } };

// ─── HELPERS ───────────────────────────────────────────────────────────────────  
const pct \= (v) \=\> Math.round(v \* 100);  
const Bar \= ({ value, color, height \= 4 }) \=\> (  
  \<div style={{ background: "\#1e293b", borderRadius: 99, height, overflow: "hidden", flex: 1 }}\>  
    \<div style={{ width: \`${pct(value)}%\`, height: "100%", background: color, borderRadius: 99, transition: "width 0.6s cubic-bezier(.16,1,.3,1)" }} /\>  
  \</div\>  
);

const Tag \= ({ children, color }) \=\> (  
  \<span style={{ background: \`${color}15\`, color, border: \`1px solid ${color}25\`, fontFamily: "monospace", fontSize: 10, padding: "2px 7px", borderRadius: 4, whiteSpace: "nowrap" }}\>  
    {children}  
  \</span\>  
);

const Card \= ({ children, style \= {} }) \=\> (  
  \<div style={{ background: "\#0d1117", border: "1px solid \#1e293b", borderRadius: 10, padding: "18px 20px", ...style }}\>  
    {children}  
  \</div\>  
);

const SectionLabel \= ({ children }) \=\> (  
  \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, letterSpacing: "0.1em", textTransform: "uppercase", margin: "0 0 12px" }}\>{children}\</p\>  
);

// ─── SIGNAL BARS ───────────────────────────────────────────────────────────────  
const SignalBreakdown \= ({ signals }) \=\> {  
  const items \= \[  
    { key: "semantic", label: "Semantic Match", color: "\#38bdf8" },  
    { key: "keyword", label: "Keyword Alignment", color: "\#f59e0b" },  
    { key: "authority", label: "Page Authority", color: "\#4ade80" },  
    { key: "orphan", label: "Orphan Priority", color: "\#a78bfa" },  
    { key: "recency", label: "Recency Boost", color: "\#fb923c" },  
  \];  
  return (  
    \<div style={{ display: "flex", flexDirection: "column", gap: 6 }}\>  
      {items.map(({ key, label, color }) \=\> (  
        \<div key={key} style={{ display: "flex", alignItems: "center", gap: 8 }}\>  
          \<span style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, width: 120, flexShrink: 0 }}\>{label}\</span\>  
          \<Bar value={signals\[key\]} color={color} height={5} /\>  
          \<span style={{ color, fontFamily: "monospace", fontSize: 10, width: 32, textAlign: "right" }}\>{pct(signals\[key\])}\</span\>  
        \</div\>  
      ))}  
    \</div\>  
  );  
};

// ─── SUGGESTION CARD ───────────────────────────────────────────────────────────  
const SuggestionCard \= ({ s, onApprove, onReject }) \=\> {  
  const \[expanded, setExpanded\] \= useState(false);  
  const method \= METHOD\_LABELS\[s.method\];  
  const highlightedContext \= s.context.replace(  
    s.anchor,  
    \`\<mark style="background:\#f59e0b25;color:\#f59e0b;padding:1px 3px;border-radius:3px;font-weight:700"\>${s.anchor}\</mark\>\`  
  );  
  const confidenceColor \= s.confidence \> 0.88 ? "\#4ade80" : s.confidence \> 0.75 ? "\#f59e0b" : "\#f87171";

  return (  
    \<div style={{  
      background: s.status \=== "approved" ? "\#4ade8008" : s.status \=== "rejected" ? "\#f8717108" : "\#0d1117",  
      border: \`1px solid ${s.status \=== "approved" ? "\#4ade8025" : s.status \=== "rejected" ? "\#f8717120" : "\#1e293b"}\`,  
      borderRadius: 10, marginBottom: 10, overflow: "hidden",  
      opacity: s.status \=== "rejected" ? 0.6 : 1,  
      transition: "all 0.2s ease",  
    }}\>  
      {/\* Header row \*/}  
      \<div style={{ padding: "14px 16px", cursor: "pointer", display: "flex", alignItems: "flex-start", gap: 12 }}  
        onClick={() \=\> setExpanded(\!expanded)}\>  
        {/\* Confidence dial \*/}  
        \<div style={{ flexShrink: 0, textAlign: "center" }}\>  
          \<div style={{ width: 44, height: 44, borderRadius: "50%", border: \`3px solid ${confidenceColor}\`, display: "flex", alignItems: "center", justifyContent: "center", background: \`${confidenceColor}10\` }}\>  
            \<span style={{ color: confidenceColor, fontFamily: "monospace", fontSize: 12, fontWeight: 800 }}\>{pct(s.confidence)}\</span\>  
          \</div\>  
          \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 9, margin: "4px 0 0", textAlign: "center" }}\>CONF\</p\>  
        \</div\>

        {/\* Main info \*/}  
        \<div style={{ flex: 1 }}\>  
          \<div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 6, flexWrap: "wrap" }}\>  
            \<span style={{ color: "\#94a3b8", fontFamily: "'Syne', sans-serif", fontSize: 12 }}\>{s.postTitle}\</span\>  
            \<span style={{ color: "\#334155", fontSize: 10 }}\>→\</span\>  
            \<Tag color={method.color}\>{method.label}\</Tag\>  
            \<Tag color="\#64748b"\>{s.position}\</Tag\>  
          \</div\>  
          \<p style={{ margin: "0 0 6px", fontFamily: "monospace", fontSize: 11 }}\>  
            \<span style={{ color: "\#475569" }}\>Anchor: \</span\>  
            \<span style={{ color: "\#f59e0b", fontWeight: 700 }}\>"{s.anchor}"\</span\>  
            \<span style={{ color: "\#475569" }}\> → \</span\>  
            \<span style={{ color: "\#38bdf8" }}\>{s.targetTitle}\</span\>  
          \</p\>  
          \<p style={{ margin: 0, color: "\#334155", fontFamily: "monospace", fontSize: 10 }}\>{s.targetUrl}\</p\>  
        \</div\>

        {/\* Actions \*/}  
        \<div style={{ display: "flex", gap: 6, flexShrink: 0, alignItems: "center" }}\>  
          {s.status \=== "pending" ? (  
            \<\>  
              \<button onClick={(e) \=\> { e.stopPropagation(); onApprove(s.id); }}  
                style={{ background: "\#4ade8015", border: "1px solid \#4ade8040", color: "\#4ade80", fontFamily: "monospace", fontSize: 11, padding: "5px 12px", borderRadius: 5, cursor: "pointer", transition: "all 0.15s" }}\>  
                ✓ Approve  
              \</button\>  
              \<button onClick={(e) \=\> { e.stopPropagation(); onReject(s.id); }}  
                style={{ background: "\#f8717115", border: "1px solid \#f8717130", color: "\#f87171", fontFamily: "monospace", fontSize: 11, padding: "5px 12px", borderRadius: 5, cursor: "pointer" }}\>  
                ✗ Reject  
              \</button\>  
            \</\>  
          ) : (  
            \<Tag color={s.status \=== "approved" ? "\#4ade80" : "\#f87171"}\>  
              {s.status \=== "approved" ? "✓ Approved" : "✗ Rejected"}  
            \</Tag\>  
          )}  
          \<span style={{ color: "\#334155", fontSize: 14, marginLeft: 4 }}\>{expanded ? "▲" : "▼"}\</span\>  
        \</div\>  
      \</div\>

      {/\* Expanded detail \*/}  
      {expanded && (  
        \<div style={{ borderTop: "1px solid \#1e293b", padding: "16px 16px", display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20 }}\>  
          \<div\>  
            \<SectionLabel\>Context Preview\</SectionLabel\>  
            \<p style={{ color: "\#64748b", fontFamily: "'Syne', sans-serif", fontSize: 12, lineHeight: 1.7, margin: 0 }}  
              dangerouslySetInnerHTML={{ \_\_html: \`"${highlightedContext}"\` }} /\>  
          \</div\>  
          \<div\>  
            \<SectionLabel\>Signal Breakdown (Algorithm Scores)\</SectionLabel\>  
            \<SignalBreakdown signals={s.signals} /\>  
          \</div\>  
        \</div\>  
      )}  
    \</div\>  
  );  
};

// ─── INSTRUCTIONS EDITOR ───────────────────────────────────────────────────────  
const InstructionsEditor \= () \=\> {  
  const DEFAULT\_INSTRUCTIONS \= \`\# Internal Linking Instructions

\#\# Priority Pages (always prefer linking to these when relevant)  
\- /wordpress-seo-guide/ — our main pillar for SEO content  
\- /python-learning-roadmap/ — priority for all Python-related posts

\#\# Exclusion Rules  
\- Never link FROM product review posts TO tutorial posts  
\- Never link to any post tagged "outdated" or "draft"  
\- Skip linking in the first paragraph — reserve it for content body

\#\# Anchor Text Preferences  
\- Prefer descriptive 3-4 word phrases over 2-word anchors  
\- Avoid anchors that start with "the", "a", or "an"  
\- For technical posts: prefer exact-match keyword anchors over semantic

\#\# Linking Style  
\- Informational posts: max 4 internal links per post  
\- Long-form guides (\>2000 words): up to 8 internal links allowed  
\- Product comparison posts: max 3 internal links, only to review pages

\#\# Special Rules  
\- Always include a link to our glossary page when a technical term first appears  
\- For any post mentioning "WordPress hosting", link to /best-wordpress-hosting/  
\`;  
  const \[instructions, setInstructions\] \= useState(DEFAULT\_INSTRUCTIONS);  
  const \[saved, setSaved\] \= useState(false);  
  const \[activeSnippet, setActiveSnippet\] \= useState(null);

  const snippets \= \[  
    { label: "+ Priority Page Rule", text: "\\n\#\# Priority Rule\\n- Always link to /your-url/ when topic matches \[keyword\]\\n" },  
    { label: "+ Exclusion Rule", text: "\\n- Never link FROM \[post-type\] TO \[post-type\]\\n" },  
    { label: "+ Max Links Rule", text: "\\n- \[post-type\] posts: max \[N\] internal links per post\\n" },  
    { label: "+ Keyword Trigger", text: "\\n- When post contains '\[keyword\]', always link to /target-url/\\n" },  
  \];

  const handleSave \= () \=\> {  
    setSaved(true);  
    setTimeout(() \=\> setSaved(false), 2500);  
  };

  return (  
    \<div\>  
      {/\* Snippet insert buttons \*/}  
      \<div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 10 }}\>  
        {snippets.map(s \=\> (  
          \<button key={s.label} onClick={() \=\> setInstructions(prev \=\> prev \+ s.text)}  
            style={{ background: "\#1e293b", border: "1px solid \#334155", color: "\#94a3b8", fontFamily: "monospace", fontSize: 10, padding: "5px 10px", borderRadius: 5, cursor: "pointer", transition: "all 0.15s" }}  
            onMouseEnter={e \=\> e.target.style.borderColor \= "\#f59e0b"}  
            onMouseLeave={e \=\> e.target.style.borderColor \= "\#334155"}\>  
            {s.label}  
          \</button\>  
        ))}  
      \</div\>

      {/\* Editor \*/}  
      \<div style={{ position: "relative" }}\>  
        \<textarea  
          value={instructions}  
          onChange={e \=\> setInstructions(e.target.value)}  
          style={{  
            width: "100%", minHeight: 320, background: "\#020817",  
            border: "1px solid \#1e293b", borderRadius: 8,  
            color: "\#94a3b8", fontFamily: "monospace", fontSize: 12,  
            padding: "16px", resize: "vertical", lineHeight: 1.7,  
            outline: "none", boxSizing: "border-box",  
            transition: "border-color 0.15s",  
          }}  
          onFocus={e \=\> e.target.style.borderColor \= "\#334155"}  
          onBlur={e \=\> e.target.style.borderColor \= "\#1e293b"}  
          spellCheck={false}  
        /\>  
        \<div style={{ position: "absolute", top: 10, right: 12, display: "flex", gap: 6, alignItems: "center" }}\>  
          \<Tag color="\#475569"\>Markdown supported\</Tag\>  
        \</div\>  
      \</div\>

      {/\* Info box \*/}  
      \<div style={{ background: "\#f59e0b08", border: "1px solid \#f59e0b20", borderRadius: 8, padding: "12px 14px", marginTop: 12, display: "flex", gap: 10 }}\>  
        \<span style={{ fontSize: 16, flexShrink: 0 }}\>⚡\</span\>  
        \<div\>  
          \<p style={{ color: "\#f59e0b", fontFamily: "monospace", fontSize: 11, fontWeight: 700, margin: "0 0 4px" }}\>How these instructions are used\</p\>  
          \<p style={{ color: "\#64748b", fontFamily: "monospace", fontSize: 11, margin: 0, lineHeight: 1.6 }}\>  
            Your instructions are prepended to the agent's system prompt at highest priority. They override algorithm defaults but cannot override hard safety rules (no external links, no duplicate anchors, no heading links). The agent reads them before every analysis run.  
          \</p\>  
        \</div\>  
      \</div\>

      {/\* Save row \*/}  
      \<div style={{ display: "flex", justifyContent: "flex-end", gap: 10, marginTop: 14 }}\>  
        \<button onClick={() \=\> setInstructions(DEFAULT\_INSTRUCTIONS)}  
          style={{ background: "none", border: "1px solid \#1e293b", color: "\#475569", fontFamily: "monospace", fontSize: 12, padding: "7px 16px", borderRadius: 6, cursor: "pointer" }}\>  
          Reset to Default  
        \</button\>  
        \<button onClick={handleSave}  
          style={{ background: saved ? "\#4ade8015" : "\#f59e0b15", border: \`1px solid ${saved ? "\#4ade8040" : "\#f59e0b40"}\`, color: saved ? "\#4ade80" : "\#f59e0b", fontFamily: "monospace", fontSize: 12, padding: "7px 20px", borderRadius: 6, cursor: "pointer", transition: "all 0.3s" }}\>  
          {saved ? "✓ Saved" : "Save Instructions"}  
        \</button\>  
      \</div\>  
    \</div\>  
  );  
};

// ─── ALGORITHM SETTINGS ────────────────────────────────────────────────────────  
const AlgorithmSettings \= () \=\> {  
  const \[settings, setSettings\] \= useState({  
    minConfidence: 62, maxLinksPost: 6, minWordsBetweenLinks: 150,  
    semanticWeight: 35, keywordWeight: 30, authorityWeight: 15,  
    orphanWeight: 10, recencyWeight: 10,  
    autoApprove: false, autoApproveThreshold: 90,  
    avoidHeadings: true, avoidFirstParagraph: true, avoidBlockquotes: true,  
    preferEarlyPlacement: true, oneUrlPerPost: true,  
    indexingMode: "incremental", scanDepth: "full",  
  });

  const set \= (key, val) \=\> setSettings(s \=\> ({ ...s, \[key\]: val }));  
  const totalWeight \= settings.semanticWeight \+ settings.keywordWeight \+ settings.authorityWeight \+ settings.orphanWeight \+ settings.recencyWeight;

  const SliderRow \= ({ label, settingKey, min \= 0, max \= 100, unit \= "%", color \= "\#f59e0b" }) \=\> (  
    \<div style={{ marginBottom: 14 }}\>  
      \<div style={{ display: "flex", justifyContent: "space-between", marginBottom: 5 }}\>  
        \<span style={{ color: "\#94a3b8", fontFamily: "monospace", fontSize: 11 }}\>{label}\</span\>  
        \<span style={{ color, fontFamily: "monospace", fontSize: 11, fontWeight: 700 }}\>{settings\[settingKey\]}{unit}\</span\>  
      \</div\>  
      \<input type="range" min={min} max={max} value={settings\[settingKey\]}  
        onChange={e \=\> set(settingKey, Number(e.target.value))}  
        style={{ width: "100%", accentColor: color }} /\>  
    \</div\>  
  );

  const Toggle \= ({ label, settingKey, description }) \=\> (  
    \<div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", padding: "10px 0", borderBottom: "1px solid \#0f172a" }}\>  
      \<div\>  
        \<p style={{ color: "\#94a3b8", fontFamily: "monospace", fontSize: 12, margin: "0 0 2px" }}\>{label}\</p\>  
        {description && \<p style={{ color: "\#334155", fontFamily: "monospace", fontSize: 10, margin: 0 }}\>{description}\</p\>}  
      \</div\>  
      \<div onClick={() \=\> set(settingKey, \!settings\[settingKey\])}  
        style={{ width: 38, height: 22, borderRadius: 11, background: settings\[settingKey\] ? "\#f59e0b" : "\#1e293b", cursor: "pointer", position: "relative", transition: "background 0.2s", flexShrink: 0, marginLeft: 12 }}\>  
        \<div style={{ position: "absolute", width: 16, height: 16, borderRadius: "50%", background: "\#fff", top: 3, left: settings\[settingKey\] ? 19 : 3, transition: "left 0.2s" }} /\>  
      \</div\>  
    \</div\>  
  );

  return (  
    \<div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 16 }}\>  
      {/\* Thresholds \*/}  
      \<Card\>  
        \<SectionLabel\>Scoring Thresholds\</SectionLabel\>  
        \<SliderRow label="Minimum Confidence Score" settingKey="minConfidence" unit="%" /\>  
        \<SliderRow label="Max Links per Post" settingKey="maxLinksPost" min={1} max={20} unit="" color="\#38bdf8" /\>  
        \<SliderRow label="Min Words Between Links" settingKey="minWordsBetweenLinks" min={50} max={500} unit="" color="\#4ade80" /\>  
        {settings.autoApprove && (  
          \<SliderRow label="Auto-Approve Threshold" settingKey="autoApproveThreshold" unit="%" color="\#a78bfa" /\>  
        )}  
      \</Card\>

      {/\* Signal weights \*/}  
      \<Card\>  
        \<SectionLabel\>Algorithm Signal Weights\</SectionLabel\>  
        \<SliderRow label="Semantic Similarity" settingKey="semanticWeight" unit="%" /\>  
        \<SliderRow label="Keyword Alignment" settingKey="keywordWeight" unit="%" color="\#38bdf8" /\>  
        \<SliderRow label="Authority Score" settingKey="authorityWeight" unit="%" color="\#4ade80" /\>  
        \<SliderRow label="Orphan Priority" settingKey="orphanWeight" unit="%" color="\#a78bfa" /\>  
        \<SliderRow label="Recency Boost" settingKey="recencyWeight" unit="%" color="\#fb923c" /\>  
        \<div style={{ display: "flex", justifyContent: "space-between", paddingTop: 8, borderTop: "1px solid \#1e293b" }}\>  
          \<span style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10 }}\>Total Weight\</span\>  
          \<span style={{ color: totalWeight \=== 100 ? "\#4ade80" : "\#f87171", fontFamily: "monospace", fontSize: 11, fontWeight: 700 }}\>  
            {totalWeight}% {totalWeight \!== 100 ? "⚠ must \= 100%" : "✓"}  
          \</span\>  
        \</div\>  
      \</Card\>

      {/\* Placement rules \+ automation \*/}  
      \<Card\>  
        \<SectionLabel\>Placement Rules\</SectionLabel\>  
        \<Toggle label="Avoid Heading Links" settingKey="avoidHeadings" description="Never place links inside h1–h4 tags" /\>  
        \<Toggle label="Avoid First Paragraph" settingKey="avoidFirstParagraph" description="Skip linking in opening paragraph" /\>  
        \<Toggle label="Avoid Blockquotes" settingKey="avoidBlockquotes" /\>  
        \<Toggle label="Prefer Early Placement" settingKey="preferEarlyPlacement" description="Bias links to top third of content" /\>  
        \<Toggle label="One URL Per Post" settingKey="oneUrlPerPost" description="Each target URL used once per post" /\>  
        \<div style={{ marginTop: 16, paddingTop: 16, borderTop: "1px solid \#1e293b" }}\>  
          \<SectionLabel\>Automation\</SectionLabel\>  
          \<Toggle label="Auto-Approve High Confidence" settingKey="autoApprove" description="Skip review queue above threshold" /\>  
        \</div\>  
      \</Card\>  
    \</div\>  
  );  
};

// ─── INDEX MONITOR ─────────────────────────────────────────────────────────────  
const IndexMonitor \= () \=\> {  
  return (  
    \<div\>  
      \<div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 12, marginBottom: 16 }}\>  
        {INDEX\_HEALTH.map(item \=\> (  
          \<Card key={item.label}\>  
            \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, textTransform: "uppercase", letterSpacing: "0.1em", margin: "0 0 8px" }}\>{item.label}\</p\>  
            \<p style={{ color: item.color, fontFamily: "monospace", fontSize: 28, fontWeight: 800, margin: "0 0 8px", lineHeight: 1 }}\>{item.value}\</p\>  
            \<Bar value={item.value / item.max} color={item.color} height={5} /\>  
          \</Card\>  
        ))}  
      \</div\>

      \<div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}\>  
        {/\* Pillar pages \*/}  
        \<Card\>  
          \<SectionLabel\>Pillar Page Link Equity\</SectionLabel\>  
          {PILLAR\_PAGES.map(p \=\> (  
            \<div key={p.id} style={{ padding: "10px 0", borderBottom: "1px solid \#0f172a" }}\>  
              \<div style={{ display: "flex", justifyContent: "space-between", marginBottom: 6 }}\>  
                \<span style={{ color: "\#e2e8f0", fontFamily: "'Syne', sans-serif", fontSize: 12 }}\>{p.title}\</span\>  
                \<Tag color={p.priority \=== "high" ? "\#4ade80" : "\#f59e0b"}\>{p.priority}\</Tag\>  
              \</div\>  
              \<div style={{ display: "flex", gap: 8, alignItems: "center" }}\>  
                \<Bar value={p.inboundLinks / 30} color="\#38bdf8" height={4} /\>  
                \<span style={{ color: "\#38bdf8", fontFamily: "monospace", fontSize: 11, flexShrink: 0 }}\>{p.inboundLinks} inbound\</span\>  
              \</div\>  
            \</div\>  
          ))}  
          \<button style={{ marginTop: 12, background: "none", border: "1px dashed \#1e293b", color: "\#475569", fontFamily: "monospace", fontSize: 11, padding: "7px 12px", borderRadius: 6, cursor: "pointer", width: "100%" }}\>  
            \+ Add Pillar Page  
          \</button\>  
        \</Card\>

        {/\* Phase flow diagram \*/}  
        \<Card\>  
          \<SectionLabel\>Algorithm Pipeline\</SectionLabel\>  
          {\[  
            { phase: "01", label: "Content Indexing", desc: "TF-IDF vectors \+ entity extraction", color: "\#f59e0b", status: "active" },  
            { phase: "02", label: "Anchor Candidate Gen", desc: "Noun phrases \+ keyword lookup \+ semantic", color: "\#38bdf8", status: "active" },  
            { phase: "03", label: "Candidate Filtering", desc: "Density, position, heading, duplicate checks", color: "\#4ade80", status: "active" },  
            { phase: "04", label: "URL Scoring", desc: "5-signal weighted composite score", color: "\#a78bfa", status: "active" },  
            { phase: "05", label: "Placement Logic", desc: "Position \+ context window AI validation", color: "\#fb923c", status: "active" },  
            { phase: "06", label: "User Rule Override", desc: "Your instructions applied last", color: "\#f8fafc", status: "active" },  
          \].map((step, i, arr) \=\> (  
            \<div key={step.phase} style={{ display: "flex", gap: 10, alignItems: "flex-start", position: "relative" }}\>  
              \<div style={{ display: "flex", flexDirection: "column", alignItems: "center" }}\>  
                \<div style={{ width: 28, height: 28, borderRadius: "50%", background: \`${step.color}15\`, border: \`1px solid ${step.color}40\`, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}\>  
                  \<span style={{ color: step.color, fontFamily: "monospace", fontSize: 9, fontWeight: 800 }}\>{step.phase}\</span\>  
                \</div\>  
                {i \< arr.length \- 1 && \<div style={{ width: 1, height: 18, background: "\#1e293b", margin: "2px 0" }} /\>}  
              \</div\>  
              \<div style={{ paddingBottom: 4 }}\>  
                \<p style={{ color: "\#e2e8f0", fontFamily: "monospace", fontSize: 11, fontWeight: 700, margin: "4px 0 2px" }}\>{step.label}\</p\>  
                \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, margin: 0 }}\>{step.desc}\</p\>  
              \</div\>  
            \</div\>  
          ))}  
        \</Card\>  
      \</div\>  
    \</div\>  
  );  
};

// ─── MAIN COMPONENT ────────────────────────────────────────────────────────────  
export default function InternalLinkingPanel() {  
  const \[subTab, setSubTab\] \= useState("suggestions");  
  const \[suggestions, setSuggestions\] \= useState(MOCK\_SUGGESTIONS);  
  const \[filterStatus, setFilterStatus\] \= useState("all");  
  const \[filterMethod, setFilterMethod\] \= useState("all");  
  const \[runningAnalysis, setRunningAnalysis\] \= useState(false);  
  const \[analysisProgress, setAnalysisProgress\] \= useState(0);

  const handleApprove \= (id) \=\> setSuggestions(s \=\> s.map(x \=\> x.id \=== id ? { ...x, status: "approved" } : x));  
  const handleReject \= (id) \=\> setSuggestions(s \=\> s.map(x \=\> x.id \=== id ? { ...x, status: "rejected" } : x));  
  const handleApproveAll \= () \=\> setSuggestions(s \=\> s.map(x \=\> x.status \=== "pending" ? { ...x, status: "approved" } : x));

  const runAnalysis \= () \=\> {  
    setRunningAnalysis(true);  
    setAnalysisProgress(0);  
    const steps \= \[12, 28, 41, 57, 69, 83, 94, 100\];  
    steps.forEach((p, i) \=\> setTimeout(() \=\> {  
      setAnalysisProgress(p);  
      if (p \=== 100\) setTimeout(() \=\> setRunningAnalysis(false), 800);  
    }, i \* 400));  
  };

  const filtered \= suggestions.filter(s \=\>  
    (filterStatus \=== "all" || s.status \=== filterStatus) &&  
    (filterMethod \=== "all" || s.method \=== filterMethod)  
  );

  const pending \= suggestions.filter(s \=\> s.status \=== "pending").length;  
  const approved \= suggestions.filter(s \=\> s.status \=== "approved").length;

  const subTabs \= \[  
    { id: "suggestions", label: \`Suggestions\`, badge: pending },  
    { id: "instructions", label: "Agent Instructions" },  
    { id: "algorithm", label: "Algorithm Config" },  
    { id: "index", label: "Index Monitor" },  
  \];

  return (  
    \<div style={{ background: "\#020817", minHeight: "100vh", fontFamily: "'Syne', sans-serif" }}\>  
      \<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800\&display=swap" rel="stylesheet" /\>

      {/\* Top bar \*/}  
      \<div style={{ background: "\#0d1117", borderBottom: "1px solid \#1e293b", padding: "16px 28px" }}\>  
        \<div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 0 }}\>  
          \<div style={{ width: 36, height: 36, background: "linear-gradient(135deg, \#38bdf8, \#a78bfa)", borderRadius: 9, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 18 }}\>🔗\</div\>  
          \<div\>  
            \<h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 17, fontWeight: 800, margin: 0, letterSpacing: "-0.03em", color: "\#f8fafc" }}\>Internal Linking Agent\</h2\>  
            \<p style={{ color: "\#334155", fontFamily: "monospace", fontSize: 10, margin: 0, letterSpacing: "0.08em" }}\>AI-POWERED · 6-PHASE ALGORITHM\</p\>  
          \</div\>

          {/\* Stats pill row \*/}  
          \<div style={{ marginLeft: "auto", display: "flex", gap: 8, alignItems: "center" }}\>  
            \<Tag color="\#f59e0b"\>{pending} PENDING\</Tag\>  
            \<Tag color="\#4ade80"\>{approved} APPROVED\</Tag\>  
            \<Tag color="\#94a3b8"\>{LINK\_STATS.orphansSolved} ORPHANS FIXED\</Tag\>

            {/\* Run analysis button \*/}  
            \<button onClick={runAnalysis} disabled={runningAnalysis}  
              style={{  
                background: runningAnalysis ? "\#1e293b" : "linear-gradient(135deg, \#38bdf8, \#a78bfa)",  
                border: "none", color: runningAnalysis ? "\#475569" : "\#020817",  
                fontFamily: "monospace", fontSize: 11, fontWeight: 700,  
                padding: "7px 16px", borderRadius: 6, cursor: runningAnalysis ? "not-allowed" : "pointer",  
                transition: "all 0.2s", marginLeft: 4,  
              }}\>  
              {runningAnalysis ? \`Analyzing… ${analysisProgress}%\` : "▶ Run Analysis"}  
            \</button\>  
          \</div\>  
        \</div\>

        {/\* Progress bar when running \*/}  
        {runningAnalysis && (  
          \<div style={{ marginTop: 12, background: "\#1e293b", borderRadius: 99, height: 3, overflow: "hidden" }}\>  
            \<div style={{ width: \`${analysisProgress}%\`, height: "100%", background: "linear-gradient(90deg, \#38bdf8, \#a78bfa)", transition: "width 0.4s ease", borderRadius: 99 }} /\>  
          \</div\>  
        )}  
      \</div\>

      {/\* Sub-tabs \*/}  
      \<div style={{ background: "\#0d1117", borderBottom: "1px solid \#1e293b", padding: "0 28px", display: "flex", gap: 0 }}\>  
        {subTabs.map(tab \=\> (  
          \<button key={tab.id} onClick={() \=\> setSubTab(tab.id)}  
            style={{  
              background: "none", border: "none", cursor: "pointer",  
              padding: "11px 16px", display: "flex", alignItems: "center", gap: 7,  
              fontFamily: "'Syne', sans-serif", fontSize: 13, fontWeight: subTab \=== tab.id ? 700 : 500,  
              color: subTab \=== tab.id ? "\#38bdf8" : "\#475569",  
              borderBottom: \`2px solid ${subTab \=== tab.id ? "\#38bdf8" : "transparent"}\`,  
              transition: "all 0.15s",  
            }}\>  
            {tab.label}  
            {tab.badge \> 0 && (  
              \<span style={{ background: "\#38bdf820", color: "\#38bdf8", border: "1px solid \#38bdf830", fontFamily: "monospace", fontSize: 10, padding: "1px 6px", borderRadius: 99 }}\>  
                {tab.badge}  
              \</span\>  
            )}  
          \</button\>  
        ))}  
      \</div\>

      {/\* Content area \*/}  
      \<div style={{ padding: "24px 28px" }}\>

        {/\* ── SUGGESTIONS ─────────────────────────────────── \*/}  
        {subTab \=== "suggestions" && (  
          \<div\>  
            {/\* Filter \+ bulk actions bar \*/}  
            \<div style={{ display: "flex", gap: 10, marginBottom: 18, alignItems: "center", flexWrap: "wrap" }}\>  
              \<SectionLabel\>Filter:\</SectionLabel\>  
              {\["all", "pending", "approved", "rejected"\].map(f \=\> (  
                \<button key={f} onClick={() \=\> setFilterStatus(f)}  
                  style={{ background: filterStatus \=== f ? "\#38bdf815" : "none", border: \`1px solid ${filterStatus \=== f ? "\#38bdf840" : "\#1e293b"}\`, color: filterStatus \=== f ? "\#38bdf8" : "\#475569", fontFamily: "monospace", fontSize: 10, padding: "4px 12px", borderRadius: 99, cursor: "pointer", textTransform: "uppercase" }}\>  
                  {f}  
                \</button\>  
              ))}  
              \<div style={{ width: 1, height: 20, background: "\#1e293b", margin: "0 4px" }} /\>  
              {\["all", "keyword", "semantic", "ensemble"\].map(m \=\> (  
                \<button key={m} onClick={() \=\> setFilterMethod(m)}  
                  style={{ background: filterMethod \=== m ? "\#a78bfa15" : "none", border: \`1px solid ${filterMethod \=== m ? "\#a78bfa40" : "\#1e293b"}\`, color: filterMethod \=== m ? "\#a78bfa" : "\#475569", fontFamily: "monospace", fontSize: 10, padding: "4px 12px", borderRadius: 99, cursor: "pointer", textTransform: "capitalize" }}\>  
                  {m}  
                \</button\>  
              ))}  
              \<div style={{ marginLeft: "auto" }}\>  
                \<button onClick={handleApproveAll}  
                  style={{ background: "\#4ade8010", border: "1px solid \#4ade8030", color: "\#4ade80", fontFamily: "monospace", fontSize: 11, padding: "6px 14px", borderRadius: 6, cursor: "pointer" }}\>  
                  ✓ Approve All Pending  
                \</button\>  
              \</div\>  
            \</div\>

            {/\* Suggestions list \*/}  
            {filtered.length \=== 0 ? (  
              \<Card style={{ textAlign: "center", padding: "40px 20px" }}\>  
                \<p style={{ color: "\#334155", fontFamily: "monospace", fontSize: 13 }}\>No suggestions match current filters.\</p\>  
              \</Card\>  
            ) : (  
              filtered.map(s \=\> (  
                \<SuggestionCard key={s.id} s={s} onApprove={handleApprove} onReject={handleReject} /\>  
              ))  
            )}  
          \</div\>  
        )}

        {/\* ── INSTRUCTIONS ─────────────────────────────────── \*/}  
        {subTab \=== "instructions" && (  
          \<div style={{ maxWidth: 860 }}\>  
            \<div style={{ marginBottom: 20 }}\>  
              \<h3 style={{ fontFamily: "'Syne', sans-serif", fontSize: 16, fontWeight: 700, color: "\#f8fafc", margin: "0 0 4px", letterSpacing: "-0.02em" }}\>Agent Instructions\</h3\>  
              \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 11, margin: 0 }}\>  
                Write rules in plain English or Markdown. These are prepended to the agent's system prompt at highest priority and followed on every analysis run.  
              \</p\>  
            \</div\>  
            \<Card\>  
              \<InstructionsEditor /\>  
            \</Card\>

            {/\* How instructions interact with algorithm \*/}  
            \<Card style={{ marginTop: 14 }}\>  
              \<SectionLabel\>Instruction Execution Order\</SectionLabel\>  
              \<div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 12 }}\>  
                {\[  
                  { order: "1st", label: "User Instructions", desc: "Your rules above — highest priority, override everything below", color: "\#f8fafc" },  
                  { order: "2nd", label: "Algorithm Defaults", desc: "Configured weights, thresholds, placement toggles from Algorithm Config tab", color: "\#38bdf8" },  
                  { order: "3rd", label: "Hard Safety Rules", desc: "Cannot be overridden: no external links, no duplicate anchors, no heading links", color: "\#f87171" },  
                \].map(step \=\> (  
                  \<div key={step.order} style={{ background: "\#0f172a", border: "1px solid \#1e293b", borderRadius: 8, padding: "14px" }}\>  
                    \<div style={{ display: "flex", gap: 8, alignItems: "center", marginBottom: 8 }}\>  
                      \<span style={{ background: \`${step.color}15\`, color: step.color, fontFamily: "monospace", fontSize: 10, fontWeight: 800, padding: "2px 8px", borderRadius: 4 }}\>{step.order}\</span\>  
                    \</div\>  
                    \<p style={{ color: step.color, fontFamily: "'Syne', sans-serif", fontSize: 13, fontWeight: 700, margin: "0 0 6px" }}\>{step.label}\</p\>  
                    \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 10, margin: 0, lineHeight: 1.6 }}\>{step.desc}\</p\>  
                  \</div\>  
                ))}  
              \</div\>  
            \</Card\>  
          \</div\>  
        )}

        {/\* ── ALGORITHM ─────────────────────────────────────── \*/}  
        {subTab \=== "algorithm" && (  
          \<div\>  
            \<div style={{ marginBottom: 20 }}\>  
              \<h3 style={{ fontFamily: "'Syne', sans-serif", fontSize: 16, fontWeight: 700, color: "\#f8fafc", margin: "0 0 4px", letterSpacing: "-0.02em" }}\>Algorithm Configuration\</h3\>  
              \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 11, margin: 0 }}\>  
                Tune scoring weights, thresholds, and placement rules. Signal weights must sum to 100%.  
              \</p\>  
            \</div\>  
            \<AlgorithmSettings /\>  
          \</div\>  
        )}

        {/\* ── INDEX MONITOR ──────────────────────────────────── \*/}  
        {subTab \=== "index" && (  
          \<div\>  
            \<div style={{ marginBottom: 20 }}\>  
              \<h3 style={{ fontFamily: "'Syne', sans-serif", fontSize: 16, fontWeight: 700, color: "\#f8fafc", margin: "0 0 4px", letterSpacing: "-0.02em" }}\>Content Index & Link Graph\</h3\>  
              \<p style={{ color: "\#475569", fontFamily: "monospace", fontSize: 11, margin: 0 }}\>  
                Site-wide semantic index health, pillar page equity, and algorithm pipeline status.  
              \</p\>  
            \</div\>  
            \<IndexMonitor /\>  
          \</div\>  
        )}  
      \</div\>  
    \</div\>  
  );  
}  
