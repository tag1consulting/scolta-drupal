/**
 * Scolta — AI-powered search with Pagefind integration.
 *
 * All site-specific references removed. Configuration is read from
 * window.scolta, which the host page must set before loading this script.
 *
 * Required window.scolta properties:
 *   scoring: { ... }        — Scoring parameters (see CONFIG below for keys)
 *   endpoints: {             — API endpoint paths
 *     expand: '/api/scolta/v1/expand-query',
 *     summarize: '/api/scolta/v1/summarize',
 *     followup: '/api/scolta/v1/followup',
 *   }
 *   pagefindPath: '/pagefind/pagefind.js'  — Path to Pagefind JS
 *   wasmPath: '/path/to/scolta_core.js'    — Path to browser WASM glue module
 *   siteName: 'My Site'                    — Display name for the site
 *   container: '#scolta-search'            — CSS selector for the search container
 *   allowedLinkDomains: []                 — Domains allowed in summary links (empty = all)
 *   disclaimer: ''                         — Disclaimer text below AI summary (empty = none)
 *   currentLanguage: null                  — Optional: 2-letter ISO language code (e.g. 'en', 'es').
 *                                            When set, search results are pre-filtered to this language.
 *                                            URL filter params (f_language=...) take precedence.
 *                                            Falls back to <html lang> detection when omitted.
 *
 * Entry point: Scolta.init(containerSelector)
 *
 * SCORING ALGORITHM: Preserved exactly from the original implementation.
 *   - Recency decay: exponential boost for new content, penalty for old
 *   - Title match boost: word-boundary matching, all-terms multiplier
 *   - Content match boost: word-boundary matching against excerpt
 *   - Expanded-term weight decay: 0.7 → 0.65 → 0.60 → ... min 0.4
 *   - Jaccard deduplication: 0.6 threshold on title word overlap
 *   - OR fallback: if AND search returns <5 results, search each term individually
 *   - Parallel data loading: all .data() calls across all searches in one Promise.all()
 *   - Dual scoring: expanded results scored vs source term AND original query, higher wins
 */

(function (global) {
  'use strict';

  function debugLog(/* ...args */) {
    if (global.SCOLTA_DEBUG) console.log.apply(console, arguments);
  }

  // ==========================================================================
  // CONFIGURATION — read from window.scolta.scoring, with defaults matching
  // the original implementation exactly.
  // ==========================================================================
  function getConfig() {
    const s = (global.scolta && global.scolta.scoring) || {};
    return {
      // Recency scoring
      RECENCY_BOOST_MAX: s.RECENCY_BOOST_MAX ?? 0.25,
      RECENCY_HALF_LIFE_DAYS: s.RECENCY_HALF_LIFE_DAYS ?? 365,
      RECENCY_PENALTY_AFTER_DAYS: s.RECENCY_PENALTY_AFTER_DAYS ?? 1825,
      RECENCY_MAX_PENALTY: s.RECENCY_MAX_PENALTY ?? 0.3,

      // Title/content match scoring
      TITLE_MATCH_BOOST: s.TITLE_MATCH_BOOST ?? 2.0,
      TITLE_ALL_TERMS_MULTIPLIER: s.TITLE_ALL_TERMS_MULTIPLIER ?? 1.5,
      EXACT_TITLE_MATCH_BOOST: s.EXACT_TITLE_MATCH_BOOST ?? 5.0,
      CONTENT_MATCH_BOOST: s.CONTENT_MATCH_BOOST ?? 0.4,

      // Display
      EXCERPT_LENGTH: s.EXCERPT_LENGTH ?? 300,
      RESULTS_PER_PAGE: s.RESULTS_PER_PAGE ?? 10,
      MAX_PAGEFIND_RESULTS: s.MAX_PAGEFIND_RESULTS ?? 50,

      // AI features
      AI_EXPAND_QUERY: s.AI_EXPAND_QUERY ?? true,
      AI_SUMMARIZE: s.AI_SUMMARIZE ?? true,
      AI_SUMMARY_TOP_N: s.AI_SUMMARY_TOP_N ?? 10,
      AI_SUMMARY_MAX_CHARS: s.AI_SUMMARY_MAX_CHARS ?? 4000,
      EXPAND_PRIMARY_WEIGHT: s.EXPAND_PRIMARY_WEIGHT ?? 0.5,
      CROSS_LIST_BONUS: s.CROSS_LIST_BONUS ?? 0.05,
      EXPAND_SUBWORD_MAX_FREQ: s.EXPAND_SUBWORD_MAX_FREQ ?? 0.05,
      EXPAND_SUBWORD_DENYLIST: s.EXPAND_SUBWORD_DENYLIST ?? [],
      EXPANSION_COMBINE_MODE: s.EXPANSION_COMBINE_MODE ?? 'relevance_union',
      EXPANSION_PER_TERM_TOP_K: s.EXPANSION_PER_TERM_TOP_K ?? 3,
      AI_MAX_FOLLOWUPS: s.AI_MAX_FOLLOWUPS ?? 3,
      AI_LANGUAGES: s.AI_LANGUAGES ?? ['en'],
      LANGUAGE: s.LANGUAGE ?? 'en',
      CUSTOM_STOP_WORDS: s.CUSTOM_STOP_WORDS ?? [],
      RECENCY_STRATEGY: s.RECENCY_STRATEGY ?? 'exponential',
      RECENCY_CURVE: s.RECENCY_CURVE ?? [],
    };
  }

  function getEndpoints() {
    const e = (global.scolta && global.scolta.endpoints) || {};
    return {
      expand: e.expand || '/api/scolta/v1/expand-query',
      summarize: e.summarize || '/api/scolta/v1/summarize',
      followup: e.followup || '/api/scolta/v1/followup',
    };
  }

  function getSiteName() {
    return (global.scolta && global.scolta.siteName) || 'this site';
  }

  function getAllowedLinkDomains() {
    return (global.scolta && global.scolta.allowedLinkDomains) || [];
  }

  function getDisclaimer() {
    return (global.scolta && global.scolta.disclaimer) || '';
  }

  // ==========================================================================
  // STOPWORDS — filter before Pagefind search and LLM expansion.
  // Ported from tag1.com search. Pagefind ANDs all query words, so "who is
  // Loreen Babcock" fails because pages rarely contain "who" + "is" + both
  // name words. Stripping stopwords turns it into "Loreen Babcock" which works.
  // ==========================================================================
  const STOPWORDS = new Set([
    // Articles
    'a', 'an', 'the',
    // Personal pronouns
    'i', 'me', 'my', 'myself', 'mine', 'we', 'us', 'our', 'ours', 'ourselves',
    'you', 'your', 'yours', 'yourself', 'yourselves',
    'he', 'him', 'his', 'himself', 'she', 'her', 'hers', 'herself',
    'it', 'its', 'itself', 'they', 'them', 'their', 'theirs', 'themselves',
    'one', 'ones',
    // Demonstrative & relative pronouns
    'this', 'that', 'these', 'those', 'who', 'whom', 'whose', 'which', 'what',
    // Prepositions
    'about', 'above', 'across', 'after', 'against', 'along', 'among', 'around',
    'at', 'before', 'behind', 'below', 'beneath', 'beside', 'besides', 'between',
    'beyond', 'by', 'despite', 'down', 'during', 'except', 'for', 'from',
    'in', 'inside', 'into', 'like', 'near', 'of', 'off', 'on', 'onto',
    'out', 'outside', 'over', 'past', 'per', 'since', 'through', 'throughout',
    'to', 'toward', 'towards', 'under', 'underneath', 'until', 'up', 'upon',
    'with', 'within', 'without',
    // Conjunctions
    'and', 'but', 'or', 'nor', 'so', 'yet', 'both', 'either', 'neither',
    'although', 'because', 'however', 'if', 'once', 'than',
    'though', 'unless', 'when', 'whenever', 'where', 'wherever', 'while', 'whether',
    // Auxiliary & modal verbs
    'am', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
    'have', 'has', 'had', 'having', 'do', 'does', 'did', 'doing', 'done',
    'will', 'would', 'shall', 'should', 'can', 'could', 'may', 'might', 'must', 'ought',
    // Contractions (punctuation-stripped)
    'dont', 'doesnt', 'didnt', 'isnt', 'arent', 'wasnt', 'werent',
    'wont', 'wouldnt', 'shouldnt', 'couldnt', 'cant', 'cannot',
    'hasnt', 'havent', 'hadnt', 'mustnt',
    'im', 'ive', 'ill', 'youre', 'youve', 'youd', 'youll',
    'hes', 'shes', 'weve', 'theyre', 'theyve', 'theyd', 'theyll',
    'whats', 'whos', 'thats', 'theres', 'heres', 'lets',
    // Adverbs & degree words
    'also', 'always', 'ever', 'here', 'there', 'how', 'just',
    'never', 'now', 'often', 'only', 'quite', 'really',
    'still', 'then', 'too', 'very', 'well', 'already',
    'almost', 'even', 'much', 'rather', 'again', 'perhaps',
    'anyway', 'anymore', 'elsewhere', 'everywhere', 'somehow', 'why',
    // Determiners & quantifiers
    'all', 'another', 'any', 'each', 'every', 'few', 'many',
    'more', 'most', 'no', 'none', 'not', 'other', 'others',
    'own', 'same', 'several', 'some', 'such', 'enough',
    // Query-intent verbs (meta-language, not what users seek)
    'find', 'finding', 'found', 'need', 'needs', 'needed', 'needing',
    'want', 'wants', 'wanted', 'wanting', 'look', 'looking', 'looked', 'looks',
    'search', 'searching', 'searched', 'show', 'showing', 'shown', 'shows',
    'tell', 'telling', 'told', 'tells', 'give', 'giving', 'gave', 'given', 'gives',
    'help', 'helping', 'helped', 'helps', 'know', 'knowing', 'knew', 'known', 'knows',
    'see', 'seeing', 'saw', 'seen', 'sees', 'try', 'trying', 'tried', 'tries',
    'ask', 'asking', 'asked', 'asks', 'think', 'thinking', 'thought', 'thinks',
    'seem', 'seems', 'seemed', 'seeming', 'say', 'saying', 'said', 'says',
    // Common filler & function words
    'able', 'ago', 'away', 'back', 'else', 'far', 'got', 'gonna', 'gotta',
    'hence', 'hereby', 'herein', 'instead', 'merely', 'please', 'regarding',
    'therefore', 'thus', 'via', 'vs', 'whereas', 'whereby', 'wherein',
    'whatever', 'whichever', 'whoever', 'yes', 'ok', 'okay',
  ]);

  // Extract meaningful search terms from a query (filter stopwords).
  // "who is Loreen Babcock" → ["loreen", "babcock"]
  // If everything is filtered, fall back to words longer than 2 chars.
  function extractSearchTerms(query) {
    // Honor CUSTOM_STOP_WORDS in JS just as the WASM scorer does — previously
    // this used only the built-in STOPWORDS, so JS query tokenization disagreed
    // with WASM scoring (issue #156 follow-up).
    const customStops = (getConfig().CUSTOM_STOP_WORDS || []).map(w => String(w).toLowerCase());
    const effectiveStopwords = customStops.length
      ? new Set([...STOPWORDS, ...customStops])
      : STOPWORDS;
    const words = query.toLowerCase().split(/\s+/).filter(w => w.length > 0);
    const meaningful = words
      .map(w => w.replace(/[^\w]/g, ''))
      .filter(w => !effectiveStopwords.has(w) && w.length > 1);
    if (meaningful.length === 0) {
      return words.filter(w => w.length > 2);
    }
    return meaningful;
  }

  // ==========================================================================
  // FILTER LABELS — human-readable display names for filter dimensions/values
  // ==========================================================================

  const LANGUAGE_NAMES = {
    en: 'English', es: 'Spanish', fr: 'French', de: 'German',
    it: 'Italian', pt: 'Portuguese', nl: 'Dutch', ru: 'Russian',
    zh: 'Chinese', ja: 'Japanese', ko: 'Korean', ar: 'Arabic',
    pl: 'Polish', sv: 'Swedish', da: 'Danish', fi: 'Finnish',
    no: 'Norwegian', tr: 'Turkish', he: 'Hebrew', uk: 'Ukrainian',
  };

  const FILTER_LABELS = {
    language: 'Language',
    site: 'Site',
    content_type: 'Content Type',
  };

  function filterDisplayValue(dimension, value) {
    if (dimension === 'language') return LANGUAGE_NAMES[value] || value;
    return value;
  }

  function filterDimLabel(dimension) {
    return FILTER_LABELS[dimension]
      || (dimension.charAt(0).toUpperCase() + dimension.slice(1).replace(/_/g, ' '));
  }

  // ==========================================================================
  // INSTANCE FACTORY
  // ==========================================================================
  // All mutable state is scoped to createInstance() closures, allowing
  // multiple independent search widgets on one page. The backward-compatible
  // Scolta.init() creates a default instance internally.

  // Pagefind uses a SharedWorker that persists across navigations; calling
  // pagefind.init() a second time corrupts the WASM pointer permanently for
  // the tab, producing "No pointer" errors. Cache the initialized module here
  // so every createInstance() call shares it without re-calling init().
  let pagefindInstance = null;

  function createInstance(containerSelector, instanceConfig) {

  // --- Instance state (local to this closure) ---
  let pagefind = null;
  let allScoredResults = [];
  let displayedCount = 0;
  let activeFilters = {};
  let conversationMessages = [];
  let followUpCount = 0;
  let abortController = null;
  let queryFacetCounts = {};   // { dimension: { value: count } } — fixed per typed query
  let currentQuery = "";
  let allHighlightTerms = [];
  let lastExpandedTerms = null;
  let searchVersion = 0;
  let usedOrFallback = false;
  let pagefindBase = '';   // Set during initPagefind(); used by resolveUrl().
  let currentSortOverride = null;    // { field, direction } or null — active sort override
  let llmAppliedFilters = {};        // { dimension: value } — filters injected by LLM expansion
  let expansionInFlight = false;     // true while an expand-query HTTP request is pending
  let cachedPagefindFilters = null;  // { dimension: { value: count } } — from pagefind.filters()

  // Detect default language filter from instanceConfig.currentLanguage or <html lang>.
  // Applied on every fresh search unless the URL already specifies f_language.
  var cfgLang = instanceConfig && typeof instanceConfig.currentLanguage === 'string'
    ? instanceConfig.currentLanguage.trim() : '';
  var defaultLangCode = cfgLang
    ? cfgLang.split('-')[0].toLowerCase()
    : (function() {
        if (typeof document === 'undefined' || !document.documentElement) return null;
        var hl = document.documentElement.lang;
        if (!hl) return null;
        var code = hl.split('-')[0].toLowerCase();
        return code.length === 2 ? code : null;
      })();

  // --- DOM references (set during init) ---
  let els = {};

  // Instance-specific config readers that use the provided config object.
  function getInstanceConfig() {
    const s = (instanceConfig && instanceConfig.scoring) || {};
    return {
      RECENCY_BOOST_MAX: s.RECENCY_BOOST_MAX ?? 0.25,
      RECENCY_HALF_LIFE_DAYS: s.RECENCY_HALF_LIFE_DAYS ?? 365,
      RECENCY_PENALTY_AFTER_DAYS: s.RECENCY_PENALTY_AFTER_DAYS ?? 1825,
      RECENCY_MAX_PENALTY: s.RECENCY_MAX_PENALTY ?? 0.3,
      TITLE_MATCH_BOOST: s.TITLE_MATCH_BOOST ?? 2.0,
      TITLE_ALL_TERMS_MULTIPLIER: s.TITLE_ALL_TERMS_MULTIPLIER ?? 1.5,
      EXACT_TITLE_MATCH_BOOST: s.EXACT_TITLE_MATCH_BOOST ?? 5.0,
      CONTENT_MATCH_BOOST: s.CONTENT_MATCH_BOOST ?? 0.4,
      PHRASE_ADJACENT_MULTIPLIER: s.PHRASE_ADJACENT_MULTIPLIER ?? 2.5,
      PHRASE_NEAR_MULTIPLIER: s.PHRASE_NEAR_MULTIPLIER ?? 1.5,
      PHRASE_NEAR_WINDOW: s.PHRASE_NEAR_WINDOW ?? 5,
      PHRASE_WINDOW: s.PHRASE_WINDOW ?? 15,
      EXCERPT_LENGTH: s.EXCERPT_LENGTH ?? 300,
      RESULTS_PER_PAGE: s.RESULTS_PER_PAGE ?? 10,
      MAX_PAGEFIND_RESULTS: s.MAX_PAGEFIND_RESULTS ?? 50,
      AI_EXPAND_QUERY: s.AI_EXPAND_QUERY ?? true,
      AI_SUMMARIZE: s.AI_SUMMARIZE ?? true,
      AI_SUMMARY_TOP_N: s.AI_SUMMARY_TOP_N ?? 10,
      AI_SUMMARY_MAX_CHARS: s.AI_SUMMARY_MAX_CHARS ?? 4000,
      EXPAND_PRIMARY_WEIGHT: s.EXPAND_PRIMARY_WEIGHT ?? 0.5,
      CROSS_LIST_BONUS: s.CROSS_LIST_BONUS ?? 0.05,
      EXPAND_SUBWORD_MAX_FREQ: s.EXPAND_SUBWORD_MAX_FREQ ?? 0.05,
      EXPAND_SUBWORD_DENYLIST: s.EXPAND_SUBWORD_DENYLIST ?? [],
      EXPANSION_COMBINE_MODE: s.EXPANSION_COMBINE_MODE ?? 'relevance_union',
      EXPANSION_PER_TERM_TOP_K: s.EXPANSION_PER_TERM_TOP_K ?? 3,
      AI_MAX_FOLLOWUPS: s.AI_MAX_FOLLOWUPS ?? 3,
      AI_LANGUAGES: s.AI_LANGUAGES ?? ['en'],
      AUTO_LANGUAGE_FILTER: s.AUTO_LANGUAGE_FILTER ?? false,
      LANGUAGE: s.LANGUAGE ?? 'en',
      CUSTOM_STOP_WORDS: s.CUSTOM_STOP_WORDS ?? [],
      RECENCY_STRATEGY: s.RECENCY_STRATEGY ?? 'exponential',
      RECENCY_CURVE: s.RECENCY_CURVE ?? [],
    };
  }

  function getInstanceEndpoints() {
    const e = (instanceConfig && instanceConfig.endpoints) || {};
    return {
      expand: e.expand || '/api/scolta/v1/expand-query',
      summarize: e.summarize || '/api/scolta/v1/summarize',
      followup: e.followup || '/api/scolta/v1/followup',
    };
  }

  function getInstanceSiteName() {
    return (instanceConfig && instanceConfig.siteName) || 'this site';
  }

  function getInstanceAllowedLinkDomains() {
    return (instanceConfig && instanceConfig.allowedLinkDomains) || [];
  }

  function getInstanceDisclaimer() {
    return (instanceConfig && instanceConfig.disclaimer) || '';
  }

  function getInstancePriorityPages() {
    return (instanceConfig && instanceConfig.priority_pages) || [];
  }

  // Sanitize a query before logging to strip PII (emails, phones, SSNs, etc.).
  // Use sanitizeQueryForLogging(query) whenever logging search queries.
  function sanitizeQueryForLogging(query) {
    if (!scoltaWasm || !scoltaWasm.sanitize_query) return query;
    try {
      return scoltaWasm.sanitize_query(JSON.stringify({ query: query }));
    } catch (e) {
      return query;
    }
  }

  // Initialize Pagefind and preload the WASM index.
  async function initPagefind() {
    const pagefindPath = (instanceConfig && instanceConfig.pagefindPath) || '/pagefind/pagefind.js';

    if (pagefindInstance) {
      pagefind = pagefindInstance;
      const base = pagefindPath.replace(/\/pagefind\/pagefind\.js.*$/, '');
      try {
        pagefindBase = base.startsWith('http') ? new URL(base).pathname : base;
      } catch (_) { pagefindBase = base; }
      if (!cachedPagefindFilters) {
        try {
          cachedPagefindFilters = await pagefind.filters();
        } catch (_) { /* ignore — filters are optional */ }
      }
      return;
    }

    pagefind = await import(pagefindPath);
    await pagefind.init();
    pagefindInstance = pagefind;

    // Record the path-only base so resolveUrl() can strip it back off.
    // pagefind's fullUrl() prepends baseUrl to every stored root-relative URL.
    // pagefind returns root-relative URLs (no domain), so we store only the path
    // portion by stripping the origin when pagefindPath is absolute.
    const rawBase = pagefindPath.replace(/\/pagefind\/pagefind\.js.*$/, '');
    try {
      pagefindBase = rawBase.startsWith('http') ? new URL(rawBase).pathname : rawBase;
    } catch (_) {
      pagefindBase = rawBase;
    }

    // Merge all language instances so multilingual facets appear.
    // pagefind.init() loads only the page language; without merging, the
    // taxonomy's language dimension has one value and renderFilters hides the facet.
    //
    // pagefind.mergeIndex() skips calls where indexPath is a prefix of the
    // primary instance's basePath (same-index dedup guard). The primary
    // basePath is a relative path; passing an absolute URL breaks the
    // string-prefix check while still resolving to the same files.
    const basePath = pagefindPath.replace(/pagefind\.js(\?.*)?$/, '');
    try {
      const resp = await fetch(basePath + 'pagefind-entry.json?ts=' + Date.now());
      const entry = await resp.json();
      const primaryLang = (document.querySelector('html')?.getAttribute('lang') || 'en')
        .toLowerCase().split('-')[0];
      const absoluteBase = new URL(basePath, window.location.href).href;
      for (const lang of Object.keys(entry.languages || {})) {
        if (lang !== primaryLang) {
          await pagefind.mergeIndex(absoluteBase, { language: lang });
        }
      }
    } catch (e) {
      console.warn('[scolta] Multilingual merge skipped:', e.message);
    }

    // Warm the index: triggers WASM compilation + fragment download.
    await pagefind.search("");

    try {
      cachedPagefindFilters = await pagefind.filters();
      debugLog('[scolta] Pagefind filters cached:', Object.keys(cachedPagefindFilters));
    } catch (e) {
      console.warn('[scolta] Failed to cache Pagefind filters:', e.message);
    }

    debugLog("[scolta] Pagefind index preloaded");
  }

  // Strip the pagefind base path that fullUrl() prepends to root-relative paths.
  function resolveUrl(raw) {
    if (!raw) return '';
    if (/^https?:\/\//.test(raw)) return raw;
    if (pagefindBase && raw.startsWith(pagefindBase + '/')) {
      return raw.slice(pagefindBase.length);
    }
    if (!raw.startsWith('/')) return '/' + raw;
    return raw;
  }

  // Scolta WASM module for client-side scoring.
  let scoltaWasm = null;

  async function initScoltaWasm() {
    const wasmPath = (instanceConfig && instanceConfig.wasmPath)
      || (global.scolta && global.scolta.wasmPath)
      || '/scolta/wasm/scolta_core.js';
    try {
      const wasm = await import(wasmPath);
      await wasm.default(); // wasm-pack init() — loads the .wasm binary
      scoltaWasm = wasm;
      debugLog("[scolta] WASM module loaded, version:", wasm.version());
    } catch (e) {
      console.warn("[scolta] WASM module not available, using JS fallback scoring:", e.message);
      scoltaWasm = null;
    }
  }

  // --- Scoring functions ---
  // When browser WASM is loaded, scoring delegates to the Rust implementation
  // for cross-platform consistency. Falls back to JS if WASM is unavailable.

  function recencyScoreFallback(dateStr) {
    const CONFIG = getInstanceConfig();
    if (!dateStr) return 0;
    try {
      const contentDate = new Date(dateStr);
      if (isNaN(contentDate.getTime())) return 0;
      const now = new Date();
      const ageDays = (now - contentDate) / (1000 * 60 * 60 * 24);
      if (ageDays < CONFIG.RECENCY_PENALTY_AFTER_DAYS) {
        return CONFIG.RECENCY_BOOST_MAX *
          Math.exp(-ageDays / CONFIG.RECENCY_HALF_LIFE_DAYS * Math.LN2);
      }
      const yearsOver = (ageDays - CONFIG.RECENCY_PENALTY_AFTER_DAYS) / 365;
      return -Math.min(CONFIG.RECENCY_MAX_PENALTY, yearsOver * 0.05);
    } catch { return 0; }
  }

  function titleMatchScoreFallback(title, query) {
    const CONFIG = getInstanceConfig();
    if (!title || !query) return 0;
    const titleLower = title.toLowerCase();
    const terms = query.toLowerCase().split(/\s+/).filter(t => t.length > 2);
    if (terms.length === 0) return 0;
    let matchCount = 0;
    for (const term of terms) {
      const regex = new RegExp(`\\b${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, "i");
      if (regex.test(titleLower)) matchCount++;
    }
    if (matchCount === 0) return 0;
    let boost = CONFIG.TITLE_MATCH_BOOST;
    if (matchCount === terms.length && terms.length > 1) {
      boost *= CONFIG.TITLE_ALL_TERMS_MULTIPLIER;
    }
    return boost * (matchCount / terms.length);
  }

  function contentMatchScoreFallback(excerpt, query) {
    const CONFIG = getInstanceConfig();
    if (!excerpt || !query) return 0;
    const terms = query.toLowerCase().split(/\s+/).filter(t => t.length > 2);
    if (terms.length === 0) return 0;
    const excerptLower = excerpt.toLowerCase();
    let matchCount = 0;
    for (const term of terms) {
      const regex = new RegExp(`\\b${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, "i");
      if (regex.test(excerptLower)) matchCount++;
    }
    if (matchCount === 0) return 0;
    return CONFIG.CONTENT_MATCH_BOOST * (matchCount / terms.length);
  }

  // --- AI features ---

  async function expandQuery(query) {
    const CONFIG = getInstanceConfig();
    const endpoints = getInstanceEndpoints();
    if (!CONFIG.AI_EXPAND_QUERY) return null;
    try {
      const resp = await fetch(endpoints.expand, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ query }),
        signal: abortController?.signal,
      });
      debugLog("[scolta:expand] status:", resp.status);
      if (!resp.ok) {
        const errText = await resp.text();
        console.warn("[scolta:expand] error response:", errText);
        return null;
      }
      const data = await resp.json();
      debugLog("[scolta:expand] response:", data);
      if (Array.isArray(data)) {
        return { terms: data, sort_hint: null, subject_terms: null, filter_hint: null };
      }
      const terms = Array.isArray(data?.terms) ? data.terms : null;
      if (!terms) return null;
      const sh = data.sort_hint;
      const sort_hint = (sh && typeof sh.field === 'string' && sh.field &&
                         (sh.direction === 'asc' || sh.direction === 'desc'))
        ? { field: sh.field, direction: sh.direction } : null;
      const subject_terms = Array.isArray(data?.subject_terms) ? data.subject_terms : null;
      const fh = data.filter_hint;
      const filter_hint = (fh && typeof fh === 'object' && !Array.isArray(fh))
        ? fh : null;
      return { terms, sort_hint, subject_terms, filter_hint };
    } catch (e) {
      if (e.name === 'AbortError') return null;
      if (e instanceof TypeError) return null;
      console.warn("[scolta:expand] failed:", e);
      return null;
    }
  }

  // Build the candidate set fed to the AI summarizer (issue #170).
  //
  // `relevance_union` (default) reproduces the historical behavior: take the
  // top-N off the already relevance-sorted, deduplicated pool.
  //
  // `round_robin` addresses sub-query domination — when a query fans out into
  // distinct sub-topics of unequal corpus size, the relevance-union top-N is
  // filled entirely by the single largest sub-query, so the summarizer never
  // sees the smaller ones and cannot mention them. Instead, group results by the
  // expansion sub-query that produced them (provenance stamped by
  // searchAndLoadParallel) and deal the top-K from each sub-query in turn until
  // AI_SUMMARY_TOP_N is filled. This reallocates *within* the existing top-N /
  // character budget — it never exceeds it — and does not touch the visible
  // ranked list. A single-bucket pool (focused single-intent query) is identical
  // to `relevance_union`.
  function selectSummaryCandidates(results, query, CONFIG) {
    const topN = CONFIG.AI_SUMMARY_TOP_N;
    if (CONFIG.EXPANSION_COMBINE_MODE !== 'round_robin') {
      return results.slice(0, topN);
    }

    const K = Math.max(1, CONFIG.EXPANSION_PER_TERM_TOP_K | 0);

    // Group by provenance, preserving the incoming relevance order within each
    // bucket. Results with no stamp (the primary query, or non-expanded
    // searches) fall under the original query.
    const buckets = new Map();
    for (const r of results) {
      const term = (r.data && r.data.__scoltaSourceTerm) || query;
      if (!buckets.has(term)) buckets.set(term, []);
      buckets.get(term).push(r);
    }

    // One sub-query → no breadth to balance; behave exactly like relevance_union.
    if (buckets.size <= 1) return results.slice(0, topN);

    // Deal the strongest sub-query first so the lead candidate still reflects
    // overall relevance.
    const order = [...buckets.keys()].sort(
      (a, b) => (buckets.get(b)[0]?.score || 0) - (buckets.get(a)[0]?.score || 0)
    );

    const picked = [];
    const seen = new Set();
    let round = 0;
    let progressed = true;
    while (picked.length < topN && progressed) {
      progressed = false;
      for (const term of order) {
        const bucket = buckets.get(term);
        for (let k = 0; k < K && picked.length < topN; k++) {
          const idx = round * K + k;
          if (idx >= bucket.length) break;
          progressed = true;
          const r = bucket[idx];
          // Dedup is by URL already, so `seen` is a safety net against a result
          // that somehow lands in two buckets.
          const key = resolveUrl(r.data?.url || '') || r;
          if (seen.has(key)) continue;
          seen.add(key);
          picked.push(r);
        }
      }
      round++;
    }
    return picked;
  }

  async function summarizeResults(query, results, expandedTerms = [], sortHint = null, filterHint = null, userFilters = {}) {
    const CONFIG = getInstanceConfig();
    const endpoints = getInstanceEndpoints();
    if (!CONFIG.AI_SUMMARIZE || results.length === 0) return null;
    const summaryEl = els.aiSummary;
    summaryEl.style.display = "block";
    summaryEl.className = "scolta-ai-summary loading";
    summaryEl.innerHTML = `
      <div class="scolta-ai-summary-label">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2z"/></svg>
        <span>AI Overview</span>
        <span class="scolta-ai-dots"><span>.</span><span>.</span><span>.</span></span>
      </div>
      <div class="scolta-ai-summary-text">
        <div class="scolta-ai-shimmer" style="width:95%"></div>
        <div class="scolta-ai-shimmer" style="width:88%"></div>
        <div class="scolta-ai-shimmer" style="width:72%"></div>
      </div>`;

    const topN = selectSummaryCandidates(results, query, CONFIG);
    let context;
    if (scoltaWasm && scoltaWasm.batch_extract_context) {
      try {
        const contextItems = topN.map(r => ({
          content: stripHtml(r.data.content || r.data.excerpt || ''),
          url: ((u) => u.startsWith('/') ? window.location.origin + u : u)(r.data.meta?.url || resolveUrl(r.data.url || '')),
          title: r.data.meta?.title || '',
        }));
        const extractInput = JSON.stringify({
          query: query,
          items: contextItems,
          config: {
            max_length: CONFIG.AI_SUMMARY_MAX_CHARS,
            intro_length: 200,
            snippet_radius: 80,
            separator: "\n\n---\n\n",
          },
        });
        const extractOutput = JSON.parse(scoltaWasm.batch_extract_context(extractInput));
        context = extractOutput.map((item, i) => {
          const metaLine = buildMetadataLine(topN[i], sortHint, filterHint);
          return `[${i + 1}] ${item.title}\n${item.url}\n${metaLine}${item.context}`;
        }).join('\n\n');
      } catch (e) {
        console.warn('[scolta] WASM context extraction failed, using fallback', e);
        context = buildLLMContext(topN, sortHint, filterHint);
      }
    } else {
      context = buildLLMContext(topN, sortHint, filterHint);
    }

    let contextHeader = '';
    if (sortHint) {
      contextHeader += `[Results are sorted by "${sortHint.field}" in ${sortHint.direction === 'desc' ? 'descending' : 'ascending'} order]\n`;
    }
    if (filterHint) {
      const filterParts = Object.entries(filterHint)
        .filter(([dim, val]) => dim && val)
        .map(([dim, val]) => `"${dim}: ${val}"`);
      if (filterParts.length > 0) {
        contextHeader += `[Results are filtered by ${filterParts.join(', ')}]\n`;
      }
    }
    if (userFilters && typeof userFilters === 'object') {
      const userFilterParts = [];
      for (const dim of Object.keys(userFilters)) {
        const vals = userFilters[dim];
        if (vals instanceof Set && vals.size > 0) {
          userFilterParts.push(dim + ': ' + [...vals].join(', '));
        }
      }
      if (userFilterParts.length > 0) {
        contextHeader += '[User has filtered results by ' + userFilterParts.join('; ') + ']\n';
      }
    }
    if (contextHeader) {
      context = contextHeader + '\n' + context;
    }

    try {
      const fullQuery = expandedTerms.length > 0
        ? `${query} (also searched: ${expandedTerms.join(', ')})`
        : query;

      const resp = await fetch(endpoints.summarize, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ query: fullQuery, context }),
        signal: abortController?.signal,
      });
      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();
      if (data.summary) {
        summaryEl.className = "scolta-ai-summary";
        const formatted = formatSummary(data.summary);

        const userContext = `Search query: ${fullQuery}\n\nSearch result excerpts:\n${context}`;
        conversationMessages = [
          { role: 'user', content: userContext },
          { role: 'assistant', content: data.summary },
        ];

        const disclaimer = getInstanceDisclaimer();
        const disclaimerHtml = disclaimer
          ? `<div class="scolta-ai-summary-disclaimer">${escapeHtml(disclaimer)}</div>`
          : '';

        summaryEl.innerHTML = `
          <div class="scolta-ai-summary-label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2z"/></svg>
            <span>AI Overview</span>
          </div>
          <div class="scolta-ai-summary-text">${formatted}</div>
          <div id="scolta-followup-thread" class="scolta-ai-followup-thread" style="display:none;"></div>
          <div class="scolta-ai-followup-input" id="scolta-followup-input">
            <input type="text" id="scolta-followup-field" placeholder="Ask a follow-up question..."
                   data-scolta-followup-input>
            <button id="scolta-followup-btn" data-scolta-followup-submit>Ask</button>
            <span class="scolta-ai-followup-counter" id="scolta-followup-counter">${CONFIG.AI_MAX_FOLLOWUPS} remaining</span>
          </div>
          ${disclaimerHtml}`;
      } else {
        summaryEl.style.display = "none";
      }
    } catch (e) {
      if (e.name === 'AbortError') return;
      if (e instanceof TypeError) {
        summaryEl.style.display = "none";
        return;
      }
      console.warn("[scolta:summarize] failed:", e);
      summaryEl.className = "scolta-ai-summary error";
      summaryEl.innerHTML = `<div class="scolta-ai-summary-label">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2z"/></svg>
          <span>AI Overview</span>
        </div>
        <div class="scolta-ai-summary-text">Summary unavailable. Results shown below.</div>`;
    }
  }

  function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  function stripHtml(text) {
    const div = document.createElement("div");
    div.innerHTML = text;
    return div.textContent || div.innerText || "";
  }

  // Build a metadata annotation line from a result's meta fields.
  // Annotates the sort field with direction and filter fields with ← markers.
  function buildMetadataLine(r, sortHint = null, filterHint = null) {
    const metaParts = [];
    if (r.data.meta) {
      for (const [key, value] of Object.entries(r.data.meta)) {
        if (key === 'title' || value === undefined || value === null || value === '') continue;
        const strVal = String(value).substring(0, 100);
        let annotation = '';
        if (sortHint && sortHint.field === key) {
          annotation = ` ← SORTED BY THIS FIELD (${sortHint.direction === 'desc' ? 'descending' : 'ascending'})`;
        }
        if (filterHint && filterHint[key]) {
          annotation += ` ← FILTERED BY THIS FIELD`;
        }
        metaParts.push(`${key}: ${strVal}${annotation}`);
      }
    }
    return metaParts.length > 0 ? `Metadata: ${metaParts.join(' | ')}\n` : '';
  }

  // Build LLM context string from an array of scored results.
  // Top 2 results get full page content for depth; remaining get excerpts.
  function buildLLMContext(results, sortHint = null, filterHint = null) {
    const CONFIG = getInstanceConfig();
    return results.map((r, i) => {
      const title = r.data.meta?.title || "Untitled";
      const _u = r.data.meta?.url || resolveUrl(r.data.url || ""); const url = _u.startsWith("/") ? window.location.origin + _u : _u;
      const useFullContent = i < 2;
      const text = useFullContent
        ? stripHtml(r.data.content || r.data.excerpt || "")
        : stripHtml(r.data.excerpt || "");
      const trimmed = text.substring(0, CONFIG.AI_SUMMARY_MAX_CHARS);
      const metaLine = buildMetadataLine(r, sortHint, filterHint);
      return `[${i + 1}] ${title}\n${url}\n${metaLine}${trimmed}`;
    }).join("\n\n");
  }

  // Repair markdown truncated by the AI hitting max_tokens mid-output.
  // Mirrors PHP MarkdownRenderer::cleanBrokenLinks() logic.
  function cleanBrokenMarkdown(text) {
    if (!text) return text;

    // Fix unclosed markdown links: [text](url  or  [text](  or  [text
    text = text.replace(/\[([^\]]+)\]\([^)]*$/g, '**$1**');
    text = text.replace(/\[([^\]]+)$/g, '**$1**');

    // Close unclosed bold/italic at end of string
    const boldCount = (text.match(/\*\*/g) || []).length;
    if (boldCount % 2 !== 0) text += '**';

    const italicMatches = text.match(/(?<!\*)\*(?!\*)/g) || [];
    if (italicMatches.length % 2 !== 0) text += '*';

    // Close unclosed backtick
    const backtickCount = (text.match(/`/g) || []).length;
    if (backtickCount % 2 !== 0) text += '`';

    return text;
  }

  // Convert lightweight markdown from Claude's summary into safe HTML.
  function formatSummary(text) {
    if (!text) return '';
    text = cleanBrokenMarkdown(text);
    const escaped = escapeHtml(text);
    const lines = escaped.split('\n');
    let html = '';
    let inList = false;

    for (const line of lines) {
      const trimmed = line.trim();
      if (trimmed === '') {
        if (inList) { html += '</ul>'; inList = false; }
        continue;
      }
      const headingMatch = trimmed.match(/^(#{1,3}) (.+)/);
      if (headingMatch) {
        if (inList) { html += '</ul>'; inList = false; }
        const tag = `h${headingMatch[1].length + 2}`;
        html += `<${tag}>${formatInline(headingMatch[2])}</${tag}>`;
      } else if (trimmed.startsWith('- ')) {
        if (!inList) { html += '<ul>'; inList = true; }
        html += `<li>${formatInline(trimmed.substring(2))}</li>`;
      } else {
        if (inList) { html += '</ul>'; inList = false; }
        html += `<p>${formatInline(trimmed)}</p>`;
      }
    }
    if (inList) html += '</ul>';
    return html;
  }

  function formatInline(text) {
    const allowedDomains = getInstanceAllowedLinkDomains();
    return text
      .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, linkText, url) => {
        if (allowedDomains.length === 0) {
          return `<a href="${url}" target="_blank" rel="noopener">${linkText}</a>`;
        }
        try {
          const parsed = new URL(url);
          const host = parsed.hostname.replace(/^www\./, '');
          if (allowedDomains.some(d => host === d || host.endsWith('.' + d))) {
            return `<a href="${url}" target="_blank" rel="noopener">${linkText}</a>`;
          }
        } catch {}
        // Non-allowed or invalid URL — show text only, no link
        return linkText;
      });
  }

  // --- Follow-up conversation ---

  async function searchForFollowUpContext(question) {
    if (!pagefind) return '';
    const terms = extractSearchTerms(question);
    const searchQuery = terms.length > 0 ? terms.join(' ') : question;
    try {
      const search = await pagefindSearch(searchQuery, {});
      const toLoad = Math.min(search.results.length, 20);
      if (toLoad === 0) return '';
      const loaded = await Promise.all(
        search.results.slice(0, toLoad).map(r => r.data())
      );
      const scored = scoreResults(loaded, searchQuery, 1.0);
      scored.sort((a, b) => b.score - a.score);
      const best = scored.slice(0, 5);
      const context = buildLLMContext(best);
      debugLog(`[scolta:followup] Found ${best.length} additional results for: ${searchQuery} (from ${toLoad} candidates)`);
      return context;
    } catch (e) {
      console.warn("[scolta:followup] context search failed:", e);
      return '';
    }
  }

  function updateFollowUpCounter(remaining) {
    const CONFIG = getInstanceConfig();
    const counter = document.getElementById("scolta-followup-counter");
    if (counter) counter.textContent = `${remaining} remaining`;

    if (remaining <= 0) {
      followUpCount = CONFIG.AI_MAX_FOLLOWUPS;
      const inputEl = document.getElementById("scolta-followup-input");
      if (inputEl) {
        inputEl.innerHTML = '<span class="scolta-ai-followup-counter" style="width:100%;text-align:center;">Follow-up limit reached. Start a new search to ask more questions.</span>';
      }
    }
  }

  async function submitFollowUp() {
    const CONFIG = getInstanceConfig();
    const endpoints = getInstanceEndpoints();
    const input = document.getElementById("scolta-followup-field");
    const btn = document.getElementById("scolta-followup-btn");
    const question = input.value.trim();
    if (!question || conversationMessages.length === 0) return;
    if (followUpCount >= CONFIG.AI_MAX_FOLLOWUPS) return;

    input.disabled = true;
    btn.disabled = true;
    input.value = '';

    // Capture the search version at the time the follow-up was initiated.
    // If a new search starts while this follow-up is in-flight, the response
    // is stale and must be discarded to prevent cross-query contamination.
    const followUpVersion = searchVersion;

    const threadEl = document.getElementById("scolta-followup-thread");
    threadEl.style.display = "block";
    const turnEl = document.createElement("div");
    turnEl.className = "scolta-ai-followup-turn";
    turnEl.innerHTML = `<div class="scolta-ai-followup-question">${escapeHtml(question)}</div>
      <div class="scolta-ai-followup-answer"><span class="scolta-ai-dots"><span>.</span><span>.</span><span>.</span></span></div>`;
    threadEl.appendChild(turnEl);
    turnEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    const extraContext = await searchForFollowUpContext(question);

    // Discard if a new search started while we were fetching context
    if (followUpVersion !== searchVersion) {
      debugLog('[scolta:followup] Discarding stale follow-up (version', followUpVersion, 'vs current', searchVersion, ')');
      return;
    }

    const userMessage = extraContext
      ? `${question}\n\nAdditional search results for this follow-up:\n${extraContext}`
      : question;

    conversationMessages.push({ role: 'user', content: userMessage });

    try {
      const resp = await fetch(endpoints.followup, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ messages: conversationMessages }),
        signal: abortController?.signal,
      });

      // Discard if a new search started while we were waiting for the response
      if (followUpVersion !== searchVersion) {
        debugLog('[scolta:followup] Discarding stale follow-up response (version', followUpVersion, 'vs current', searchVersion, ')');
        conversationMessages.pop();
        return;
      }

      if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
      const data = await resp.json();

      if (data.response) {
        conversationMessages.push({ role: 'assistant', content: data.response });
        turnEl.querySelector(".scolta-ai-followup-answer").innerHTML = formatSummary(data.response);
        const remaining = data.remaining ?? (CONFIG.AI_MAX_FOLLOWUPS - followUpCount - 1);
        followUpCount++;
        updateFollowUpCounter(remaining);
      } else {
        turnEl.querySelector(".scolta-ai-followup-answer").textContent = "No response available.";
      }
    } catch (e) {
      if (e.name === 'AbortError') {
        // Search was cancelled — follow-up is stale, clean up silently
        conversationMessages.pop();
        return;
      }
      if (e instanceof TypeError) {
        turnEl.querySelector(".scolta-ai-followup-answer").textContent = "Follow-up unavailable. Please try again.";
        conversationMessages.pop();
        return;
      }
      console.warn("[scolta:followup] failed:", e);
      if (e.message && e.message.includes('429')) {
        turnEl.querySelector(".scolta-ai-followup-answer").textContent = "Follow-up limit reached.";
        updateFollowUpCounter(0);
      } else {
        turnEl.querySelector(".scolta-ai-followup-answer").textContent = "Follow-up unavailable. Please try again.";
        conversationMessages.pop();
      }
    }

    if (followUpCount < CONFIG.AI_MAX_FOLLOWUPS) {
      input.disabled = false;
      btn.disabled = false;
      input.focus();
    }
  }

  function renderExpandedTerms(terms, originalQuery) {
    const container = els.expandedTerms;
    if (!terms || terms.length === 0) {
      container.style.display = "none";
      return;
    }
    const filtered = terms.filter(t => t.toLowerCase() !== originalQuery.toLowerCase());
    if (filtered.length === 0) {
      container.style.display = "none";
      return;
    }
    container.style.display = "flex";
    container.innerHTML = '<span style="font-size:0.8rem;color:#666;margin-right:0.2rem;">Also try:</span>' +
      filtered
        .map(t => `<span class="scolta-expanded-term" data-scolta-search-term="${escapeHtml(t)}">${escapeHtml(t)}</span>`)
        .join("");
  }

  function searchTerm(term) {
    els.queryInput.value = term;
    doSearch();
  }

  function renderSortIndicator(override) {
    const el = els.sortIndicator;
    if (!override || !override.field) {
      el.style.display = 'none';
      el.innerHTML = '';
      return;
    }
    const dirLabel = override.direction === 'desc' ? 'highest first' : 'lowest first';
    el.style.display = 'block';
    el.innerHTML = '<span class="scolta-sort-badge">Sorted by: ' + escapeHtml(override.field) +
      ' (' + dirLabel + ') ' +
      '<button class="scolta-sort-dismiss" data-scolta-sort-dismiss aria-label="Remove sort">×</button></span>';
  }

  function dismissSortOverride() {
    currentSortOverride = null;
    renderSortIndicator(null);
    // Re-run the full search without sort so all matching docs are reconsidered
    // by BM25 relevance. We can't simply swap arrays — the sorted result set
    // excluded pages that lacked price metadata, so the relevance set is different.
    doSearch(true);
  }

  function renderFilterBadges() {
    const el = els.filterIndicator;
    if (!el) return;
    if (Object.keys(llmAppliedFilters).length === 0) {
      el.style.display = 'none';
      el.innerHTML = '';
      return;
    }
    el.style.display = 'block';
    let html = '';
    for (const [dim, val] of Object.entries(llmAppliedFilters)) {
      html += '<span class="scolta-filter-badge">Filtered: ' + escapeHtml(dim) + ' = ' + escapeHtml(val) +
        ' <button class="scolta-filter-dismiss" data-scolta-filter-dismiss="' + escapeHtml(dim) +
        '" aria-label="Remove filter">×</button></span> ';
    }
    el.innerHTML = html;
  }

  function dismissLlmFilter(dim) {
    const val = llmAppliedFilters[dim];
    if (val !== undefined) {
      delete llmAppliedFilters[dim];
      if (activeFilters[dim]) {
        activeFilters[dim].delete(val);
        if (activeFilters[dim].size === 0) {
          delete activeFilters[dim];
        }
      }
    }
    renderFilterBadges();
    doSearch(true);
  }

  // --- Pagefind search helper ---

  async function pagefindSearch(query, filters, sortHint) {
    const searchOpts = {};
    if (filters && typeof filters === 'object') {
      const pagefindFilters = {};
      for (const [dim, vals] of Object.entries(filters)) {
        if (vals instanceof Set && vals.size > 0) {
          const arr = [...vals];
          pagefindFilters[dim] = arr.length === 1 ? arr[0] : { any: arr };
        }
      }
      if (Object.keys(pagefindFilters).length > 0) {
        searchOpts.filters = pagefindFilters;
      }
    }
    if (sortHint && sortHint.field && sortHint.direction) {
      searchOpts.sort = { [sortHint.field]: sortHint.direction };
    }
    return pagefind.search(query, searchOpts);
  }

  const SKIP_FILTER_DIMENSIONS = new Set(['site', 'language', 'content_type', 'entity_type']);

  function matchSubjectToFilters(subjectTerms, availableFilters, filterDescriptions) {
    if (!subjectTerms || !subjectTerms.length || !availableFilters) return {};

    const keywords = new Set();
    for (const term of subjectTerms) {
      const lower = term.toLowerCase().trim();
      if (lower.length > 2) keywords.add(lower);
      for (const word of lower.split(/\s+/)) {
        if (word.length > 2) keywords.add(word);
      }
    }

    const matched = {};
    for (const [dimension, values] of Object.entries(availableFilters)) {
      if (SKIP_FILTER_DIMENSIONS.has(dimension.toLowerCase())) continue;

      // Pass 1: exact match — prefer precise hits over substring overlap.
      for (const filterValue of Object.keys(values)) {
        const lowerValue = filterValue.toLowerCase();
        for (const keyword of keywords) {
          if (lowerValue === keyword) {
            matched[dimension] = filterValue;
            break;
          }
        }
        if (matched[dimension]) break;
      }

      // Pass 2: substring fallback — only if no exact match was found.
      if (!matched[dimension]) {
        for (const filterValue of Object.keys(values)) {
          const lowerValue = filterValue.toLowerCase();
          for (const keyword of keywords) {
            if ((lowerValue.length > 2 && keyword.includes(lowerValue))
                || (keyword.length > 2 && lowerValue.includes(keyword))) {
              matched[dimension] = filterValue;
              break;
            }
          }
          if (matched[dimension]) break;
        }
      }

      // Pass 3: subcategory matching via filter descriptions.
      // Descriptions like "Science (physics, chemistry, biology)" let us
      // match "physics" → "Science" even though "physics" isn't a filter value.
      if (!matched[dimension] && filterDescriptions) {
        const desc = (filterDescriptions[dimension] || '').toLowerCase();
        for (const filterValue of Object.keys(values)) {
          const escapedValue = filterValue.toLowerCase().replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
          const pattern = new RegExp(escapedValue + '\\s*\\(([^)]+)\\)');
          const m = desc.match(pattern);
          if (m) {
            const subcategories = m[1].split(',').map(s => s.trim());
            for (const sub of subcategories) {
              if (keywords.has(sub) || [...keywords].some(kw =>
                (sub.length > 2 && kw.includes(sub)) ||
                (kw.length > 2 && sub.includes(kw))
              )) {
                matched[dimension] = filterValue;
                break;
              }
            }
          }
          if (matched[dimension]) break;
        }
      }
    }

    return matched;
  }

  // Pagefind's data.locations are not word positions — compute from content instead.
  function computeContentWordLocations(content, queryTerms) {
    if (!content || !queryTerms || queryTerms.length < 2) return null;
    const words = content.toLowerCase().replace(/[^a-z0-9\s]/g, '').split(/\s+/).filter(w => w.length > 0);
    const termsLower = queryTerms.map(t => t.toLowerCase().replace(/[^a-z0-9]/g, ''));
    const locations = [];
    words.forEach((word, idx) => {
      if (termsLower.some(term => {
        if (word === term) return true;
        const minLen = Math.max(3, Math.min(word.length, term.length) - 2);
        return word.substring(0, minLen) === term.substring(0, minLen);
      })) {
        locations.push(idx);
      }
    });
    return locations.length >= queryTerms.length ? locations : null;
  }

  // Score a set of loaded results against a query.
  function scoreResults(loaded, query, sourceWeight, primaryQuery) {
    const CONFIG = getInstanceConfig();
    let scored;
    if (scoltaWasm) {
      // WASM scoring — canonical Rust implementation
      const queryTerms = extractSearchTerms(query);
      const results = loaded.map((data, i) => {
        const contentLocations = computeContentWordLocations(data.content || '', queryTerms);
        return {
          title: data.meta?.title || '',
          url: resolveUrl(data.url || ''),
          excerpt: data.excerpt || '',
          date: data.meta?.date || '',
          pagefind_index: i,
          score: loaded.length > 1 ? 1 - (i / (loaded.length - 1)) : 1,
          locations: contentLocations || data.locations || [],
        };
      });
      // WASM config keys are snake_case; getInstanceConfig() returns
      // SCREAMING_SNAKE_CASE for the platform adapter layer. Convert here.
      const wasmConfig = {};
      for (const [k, v] of Object.entries(CONFIG)) {
        wasmConfig[k.toLowerCase()] = v;
      }
      const input = JSON.stringify({
        query: query,
        results: results,
        config: wasmConfig,
        primary_query: primaryQuery || undefined,
      });
      try {
        const output = scoltaWasm.score_results(input);
        const wasmScored = JSON.parse(output);
        scored = wasmScored.map(item => ({
          data: loaded[item.pagefind_index] || loaded.find(d =>
            resolveUrl(d.url || '') === item.url
          ) || loaded[0],
          score: item.score * sourceWeight,
        }));
      } catch (e) {
        console.warn("[scolta] WASM score_results failed, using fallback:", e.message);
      }
    }
    if (!scored) {
      // JS fallback scoring
      const count = loaded.length;
      scored = loaded.map((data, i) => {
        const pagefindScore = count > 1 ? 1 - (i / (count - 1)) : 1;
        const recency = recencyScoreFallback(data.meta?.date);
        const titleBoost = titleMatchScoreFallback(data.meta?.title, query);
        const contentBoost = contentMatchScoreFallback(data.excerpt, query);
        const finalScore = (pagefindScore + recency + titleBoost + contentBoost) * sourceWeight;
        return { data, score: finalScore };
      });
    }
    // Exact title match: when the result's title IS the query, apply a large
    // multiplicative boost so it always ranks #1 regardless of BM25 scores.
    const normalizedQuery = (primaryQuery || query).toLowerCase().trim();
    if (normalizedQuery && CONFIG.EXACT_TITLE_MATCH_BOOST > 1.0) {
      for (const r of scored) {
        const title = (r.data.meta?.title || '').toLowerCase().trim();
        if (title && title === normalizedQuery) {
          r.score *= CONFIG.EXACT_TITLE_MATCH_BOOST;
        }
      }
    }
    return scored;
  }

  // Score multiple independent queries in one WASM call.
  // queries: [{ query, results, config? }, ...]
  // Returns an array of scored result arrays, one per input query.
  function batchScoreResults(queries) {
    if (!scoltaWasm) {
      console.warn("[scolta] WASM not loaded — batchScoreResults unavailable");
      return queries.map(() => []);
    }
    try {
      const input = JSON.stringify({ queries, default_config: getInstanceConfig() });
      const output = scoltaWasm.batch_score_results(input);
      return JSON.parse(output);
    } catch (e) {
      console.warn("[scolta] WASM batch_score_results failed:", e.message);
      return queries.map(() => []);
    }
  }

  // Compute the query-fixed facet counts for a typed query.
  //
  // Counts are a fixed property of the typed query: computed once when the query
  // is submitted, never recomputed on a facet toggle or after AI expansion. A
  // single Pagefind search returns per-value counts for every dimension in one
  // shot (`.filters`); the count next to a value means "N of the results for
  // your search are tagged this." To keep counts stable yet correctly scoped,
  // the search keeps only STRUCTURAL filter dimensions (language/site/etc. in
  // SKIP_FILTER_DIMENSIONS — typically the auto-language default) and drops
  // every user-facing facet selection, so the numbers are independent of which
  // facets the user has clicked. Expansion is LLM-driven and nondeterministic,
  // so deriving counts from the deterministic typed query keeps them stable
  // run-to-run. Returns { dimension: { value: count } }.
  async function computeQueryFacetCounts(query, baseFilters) {
    const structuralFilters = {};
    for (const [dim, vals] of Object.entries(baseFilters || {})) {
      if (SKIP_FILTER_DIMENSIONS.has(dim.toLowerCase())) {
        structuralFilters[dim] = vals;
      }
    }
    try {
      const search = await pagefindSearch(query, structuralFilters);
      return (search && search.filters) ? search.filters : {};
    } catch (_) {
      return {};   // facet counts are best-effort — never block render
    }
  }

  // Deduplicate results with near-identical titles using Jaccard similarity.
  // Run AFTER sorting — keeps the higher-scored result for each cluster.
  function deduplicateByTitle(results) {
    const kept = [];
    const seenTitles = [];

    for (const r of results) {
      const title = (r.data.meta?.title || '').toLowerCase();
      const base = title.split('|')[0].trim();
      const words = new Set(base.replace(/[^\w\s]/g, '').split(/\s+/).filter(w => w.length > 2));

      if (words.size === 0) {
        kept.push(r);
        continue;
      }

      // Check against all kept titles for high overlap (Jaccard >= 0.6)
      // or predominant overlap (>=3 shared words AND intersection/min >= 0.6)
      let isDuplicate = false;
      for (const seen of seenTitles) {
        const intersection = [...words].filter(w => seen.words.has(w)).length;
        const union = new Set([...words, ...seen.words]).size;
        const smaller = Math.min(words.size, seen.words.size);
        if ((union > 0 && intersection / union >= 0.6) ||
            (intersection >= 3 && intersection / smaller >= 0.6)) {
          isDuplicate = true;
          break;
        }
      }

      if (!isDuplicate) {
        seenTitles.push({ words });
        kept.push(r);
      }
    }

    if (kept.length < results.length) {
      debugLog(`[scolta:dedup] Removed ${results.length - kept.length} near-duplicate titles`);
    }
    return kept;
  }

  // Merge scored results, keeping highest score per URL.
  // currentWeight / expandedWeight: explicit set weights for the WASM merge. Defaults to
  // 1.0/1.0 (equal weight) for intra-expansion merges; the expand-vs-primary merge passes
  // CONFIG.EXPAND_PRIMARY_WEIGHT / (1 - CONFIG.EXPAND_PRIMARY_WEIGHT) so that a higher
  // expand_primary_weight value gives more weight to original results, as the config name implies.
  function mergeResults(currentResults, newResults, currentWeight, expandedWeight) {
    const cw = (currentWeight  !== undefined) ? currentWeight  : 1.0;
    const ew = (expandedWeight !== undefined) ? expandedWeight : 1.0;
    if (scoltaWasm) {
      const original = currentResults.map(r => ({
        title: r.data.meta?.title || '',
        url: resolveUrl(r.data.url || ''),
        score: r.score,
        excerpt: r.data.excerpt || '',
        date: r.data.meta?.date || '',
      }));
      const expanded = newResults.map(r => ({
        title: r.data.meta?.title || '',
        url: resolveUrl(r.data.url || ''),
        score: r.score,
        excerpt: r.data.excerpt || '',
        date: r.data.meta?.date || '',
      }));
      const input = JSON.stringify({
        sets: [
          { results: original, weight: cw },
          { results: expanded, weight: ew },
        ],
        deduplicate_by: "url",
        normalize_urls: true,
      });
      try {
        const output = scoltaWasm.merge_results(input);
        const merged = JSON.parse(output);
        // WASM may normalize URLs (strip .html, trailing slash, lowercase) before
        // deduplication, so its output URLs may not match the raw keys from pagefind.
        // Build a multi-key map with normalized variants so we can always find the
        // original result object to attach its full data.
        const normalizeUrl = u => (u || '').replace(/\.html$/, '').replace(/\/$/, '').toLowerCase();
        const dataByUrl = new Map();
        for (const r of [...currentResults, ...newResults]) {
          const rawUrl = resolveUrl(r.data.url || '');
          for (const key of [rawUrl, normalizeUrl(rawUrl), rawUrl.replace(/^\/+/, ''), normalizeUrl(rawUrl).replace(/^\/+/, '')]) {
            if (key && (!dataByUrl.has(key) || r.score > dataByUrl.get(key).score)) {
              dataByUrl.set(key, r);
            }
          }
        }
        let lookupMisses = 0;
        const resolvedMerged = merged.map(item => {
          const iUrl = item.url || '';
          const found = dataByUrl.get(iUrl)
            || dataByUrl.get(normalizeUrl(iUrl))
            || dataByUrl.get(iUrl.replace(/^\/+/, ''))
            || dataByUrl.get(normalizeUrl(iUrl).replace(/^\/+/, ''));
          if (!found) lookupMisses++;
          return { data: found?.data || item, score: item.score };
        });
        if (lookupMisses > 0) {
          console.warn('[scolta:merge] WASM URL lookup missed', lookupMisses, '/', merged.length);
        }
        return resolvedMerged;
      } catch (e) {
        console.warn("[scolta] WASM merge_results failed, using fallback:", e.message);
      }
    }
    // JS fallback merge
    const BONUS = getInstanceConfig().CROSS_LIST_BONUS;
    const urlMap = new Map();
    for (const r of currentResults) {
      const url = resolveUrl(r.data.url || '');
      if (!urlMap.has(url)) {
        urlMap.set(url, { ...r });
      } else {
        const prev = urlMap.get(url);
        prev.score = Math.max(prev.score, r.score) + BONUS;
      }
    }
    for (const r of newResults) {
      const url = resolveUrl(r.data.url || '');
      if (!urlMap.has(url)) {
        urlMap.set(url, { ...r });
      } else {
        const prev = urlMap.get(url);
        prev.score = Math.max(prev.score, r.score) + BONUS;
      }
    }
    return [...urlMap.values()];
  }

  // ==========================================================================
  // SHARED SEARCH HELPERS
  // ==========================================================================

  async function loadAndScoreSearch(search, query, weight) {
    const CONFIG = getInstanceConfig();
    const toLoad = Math.min(search.results.length, CONFIG.MAX_PAGEFIND_RESULTS);
    if (toLoad === 0) return [];
    const loaded = await Promise.all(
      search.results.slice(0, toLoad).map(r => r.data())
    );
    return scoreResults(loaded, query, weight);
  }

  async function searchAndLoadParallel(queries, filters, originalQuery) {
    const CONFIG = getInstanceConfig();
    if (queries.length === 0) return [];

    const searches = await Promise.all(
      queries.map(q => pagefindSearch(q.term, filters))
    );

    const loadPromises = [];
    for (let i = 0; i < searches.length; i++) {
      const search = searches[i];
      const { term, weight } = queries[i];
      const toLoad = Math.min(search.results.length, CONFIG.MAX_PAGEFIND_RESULTS);
      for (let j = 0; j < toLoad; j++) {
        loadPromises.push(
          search.results[j].data().then(data => ({ data, term, weight }))
        );
      }
    }
    const allLoaded = await Promise.all(loadPromises);

    const byTerm = new Map();
    for (const item of allLoaded) {
      if (!byTerm.has(item.term)) byTerm.set(item.term, []);
      byTerm.get(item.term).push(item);
    }

    let results = [];
    for (const [term, items] of byTerm) {
      const weight = items[0].weight;
      const loaded = items.map(i => i.data);
      // Stamp expansion provenance onto each loaded result so the summary
      // candidate selector can group by sub-query (issue #170). This survives
      // mergeResults (which preserves the original data object per URL) and is
      // invisible to the visible ranked list — only the summarizer consults it.
      for (const d of loaded) {
        if (d) d.__scoltaSourceTerm = term;
      }
      const scoredVsTerm = scoreResults(loaded, term, weight, originalQuery);
      const scoredVsOriginal = scoreResults(loaded, originalQuery, weight * 0.5);

      const BONUS = getInstanceConfig().CROSS_LIST_BONUS;
      const best = scoredVsTerm.map((r, idx) => ({
        data: r.data,
        score: r.score + (scoredVsOriginal[idx].score > 0 ? Math.min(scoredVsOriginal[idx].score * 0.3, BONUS) : 0),
      }));
      results = mergeResults(results, best);
    }

    return results;
  }

  async function mergeExpandedSearchResults(expandedTerms, originalQuery, searchQuery, preserveFilters, version, sortOverride, subjectTerms) {
    const CONFIG = getInstanceConfig();
    const validTerms = expandedTerms
      ? expandedTerms.filter(t => t.toLowerCase() !== originalQuery.toLowerCase())
      : [];

    // For the relevance path we need expanded terms; for the sort path we proceed
    // even with none (we still run the primary query with native sort).
    if (validTerms.length === 0 && !sortOverride) return;

    if (version !== searchVersion) {
      debugLog('[scolta:expand] Discarding stale expansion (version', version, 'vs current', searchVersion, ')');
      return;
    }

    for (const term of validTerms) {
      for (const word of term.toLowerCase().split(/\s+/)) {
        if (word.length > 2 && !allHighlightTerms.includes(word)) {
          allHighlightTerms.push(word);
        }
      }
    }

    // Sub-word frequency guard (issue #156). Multi-word expansion terms are
    // decomposed into their constituent words so broad queries recover the
    // recall lost in v1.0.0 — but a word is only added as a search term when
    // its corpus frequency is below EXPAND_SUBWORD_MAX_FREQ. Low-frequency
    // domain words ("vegetarian", "cuisine") get added; high-frequency noise
    // words ("recipes", "cooking") are blocked. Frequency is measured against
    // the same active filters the real search uses (including the language
    // partition when auto_language_filter is on), so numerator and denominator
    // share scope. 0 reproduces v1.0.0 (no sub-words); >=1 admits all sub-words.
    const subwordMaxFreq = CONFIG.EXPAND_SUBWORD_MAX_FREQ;
    // Fix A+D (issue #156 follow-up): the frequency guard must never drop a word
    // the USER actually typed — frequency is a leaky proxy for "generic," and in a
    // topical corpus the on-topic words are also the high-frequency ones. Exempt
    // query tokens from the frequency check, EXCEPT words on the guard denylist.
    const queryTokens = new Set(extractSearchTerms(searchQuery));
    const subwordDenylist = new Set(
      (CONFIG.EXPAND_SUBWORD_DENYLIST || []).map(w => String(w).toLowerCase())
    );
    const subwordFreqCache = new Map();
    let subwordCorpusTotal = null;
    async function subwordAllowed(word) {
      if (word.length <= 2) return false;
      if (subwordMaxFreq <= 0) return false;   // v1.0.0 behavior: no sub-words
      if (subwordMaxFreq >= 1) return true;    // pre-v1.0.0 behavior: all sub-words
      // Fix A: a sub-word the user literally typed is wanted by definition —
      // bypass the frequency check. Fix D: unless it's on the guard denylist.
      if (queryTokens.has(word) && !subwordDenylist.has(word)) return true;
      if (subwordFreqCache.has(word)) return subwordFreqCache.get(word);
      let allowed = false;
      try {
        if (subwordCorpusTotal === null) {
          const all = await pagefindSearch(null, activeFilters);
          subwordCorpusTotal = all.results.length;
        }
        if (subwordCorpusTotal > 0) {
          const hit = await pagefindSearch(word, activeFilters);
          allowed = (hit.results.length / subwordCorpusTotal) < subwordMaxFreq;
        }
      } catch (_) {
        allowed = false; // fail closed on pagefind error — preserve precision
      }
      subwordFreqCache.set(word, allowed);
      return allowed;
    }

    let useSortPath = !!(sortOverride && sortOverride.field && sortOverride.direction);
    let subjectFilters = {};

    if (useSortPath) {
      const filterDescs = (instanceConfig && instanceConfig.filterFieldDescriptions) || {};
      subjectFilters = matchSubjectToFilters(subjectTerms, cachedPagefindFilters, filterDescs);
      const hasFilterMatch = Object.keys(subjectFilters).length > 0;

      if (hasFilterMatch) {
        debugLog('[scolta:sort] Subject filter match:', JSON.stringify(subjectFilters));
      } else if (subjectTerms && subjectTerms.length > 0) {
        debugLog('[scolta:sort] No filter match for subject terms — dropping sort, using relevance');
        currentSortOverride = null;
        useSortPath = false;
      } else {
        debugLog('[scolta:sort] No subject terms, using sort only');
      }
    }

    if (useSortPath) {
      const hasFilterMatch = Object.keys(subjectFilters).length > 0;

      const mergedFilters = {};
      for (const [dim, vals] of Object.entries(activeFilters)) {
        mergedFilters[dim] = vals;
      }
      if (hasFilterMatch) {
        for (const [dim, val] of Object.entries(subjectFilters)) {
          if (!mergedFilters[dim]) {
            mergedFilters[dim] = new Set([val]);
          }
          if (!activeFilters[dim]) {
            activeFilters[dim] = new Set([val]);
          }
          if (!llmAppliedFilters[dim]) {
            llmAppliedFilters[dim] = val;
          }
        }
      }

      const termSet = new Set([searchQuery]);
      for (const term of validTerms) {
        termSet.add(term);
        const words = extractSearchTerms(term);
        if (words.length > 1) {
          for (const word of words) {
            if (!termSet.has(word) && await subwordAllowed(word)) {
              termSet.add(word);
            }
          }
        }
      }

      const searches = await Promise.all(
        [...termSet].map(t => pagefindSearch(t, mergedFilters, sortOverride))
      );

      if (version !== searchVersion) {
        debugLog('[scolta:expand] Discarding stale expansion after sort search (version', version, 'vs current', searchVersion, ')');
        return;
      }

      const urlMap = new Map();
      await Promise.all(searches.map(async (search) => {
        const toLoad = Math.min(search.results.length, CONFIG.MAX_PAGEFIND_RESULTS);
        if (toLoad === 0) return;
        const loaded = await Promise.all(search.results.slice(0, toLoad).map(r => r.data()));
        for (const data of loaded) {
          const url = resolveUrl(data.url || '');
          if (!urlMap.has(url)) urlMap.set(url, data);
        }
      }));

      if (version !== searchVersion) {
        debugLog('[scolta:expand] Discarding stale expansion after sort load (version', version, 'vs current', searchVersion, ')');
        return;
      }

      const field = sortOverride.field;
      const desc = sortOverride.direction === 'desc';
      let withField = [...urlMap.values()].filter(data => {
        const v = data.meta?.[field];
        return v !== undefined && v !== null && v !== '';
      });

      const SORT_FALLBACK_THRESHOLD = 20;
      if (withField.length > 0 && withField.length < SORT_FALLBACK_THRESHOLD) {
        debugLog('[scolta:sort] Sorted search returned only ' + withField.length + ' results with field "' + field + '", re-running unsorted for JS-side sort');
        const unsortedSearches = await Promise.all(
          [...termSet].map(t => pagefindSearch(t, mergedFilters, null))
        );
        if (version !== searchVersion) return;
        const fallbackMap = new Map();
        await Promise.all(unsortedSearches.map(async (search) => {
          const toLoad = Math.min(search.results.length, CONFIG.MAX_PAGEFIND_RESULTS);
          if (toLoad === 0) return;
          const loaded = await Promise.all(search.results.slice(0, toLoad).map(r => r.data()));
          for (const data of loaded) {
            const url = resolveUrl(data.url || '');
            if (!fallbackMap.has(url)) fallbackMap.set(url, data);
          }
        }));
        if (version !== searchVersion) return;
        withField = [...fallbackMap.values()].filter(data => {
          const v = data.meta?.[field];
          return v !== undefined && v !== null && v !== '';
        });
        debugLog('[scolta:sort] Fallback unsorted search yielded ' + withField.length + ' results with field "' + field + '"');
      }

      if (withField.length === 0) {
        debugLog('[scolta:sort] Sort field "' + field + '" absent from all results, falling back to relevance');
        currentSortOverride = null;
      } else {
        withField.sort((a, b) => {
          const av = parseFloat(a.meta[field]);
          const bv = parseFloat(b.meta[field]);
          const cmp = (!isNaN(av) && !isNaN(bv))
            ? av - bv
            : String(a.meta[field] || '').localeCompare(String(b.meta[field] || ''));
          return desc ? -cmp : cmp;
        });

        allScoredResults = withField.map(data => ({ data, score: 0 }));
      }

    } else {
      // Relevance path: existing multi-term expand-and-merge behavior.
      const queries = [];
      let weightIndex = 0;
      const expandBase = CONFIG.EXPAND_PRIMARY_WEIGHT;
      for (const term of validTerms) {
        const weight = Math.max(expandBase - (weightIndex * 0.05), 0.1);
        queries.push({ term, weight });
        weightIndex++;

        const words = extractSearchTerms(term);
        if (words.length > 1) {
          for (const word of words) {
            if (!queries.some(q => q.term === word) && await subwordAllowed(word)) {
              const wordWeight = Math.max(expandBase - (weightIndex * 0.05), 0.1);
              queries.push({ term: word, weight: wordWeight });
              weightIndex++;
            }
          }
        }
      }

      const expandedResults = await searchAndLoadParallel(queries, activeFilters, searchQuery);

      if (version !== searchVersion) {
        debugLog('[scolta:expand] Discarding stale expansion after load (version', version, 'vs current', searchVersion, ')');
        return;
      }

      allScoredResults = mergeResults(
        allScoredResults,
        expandedResults,
        1.0,
        1.0
      );
      allScoredResults.sort((a, b) => b.score - a.score);
      allScoredResults = deduplicateByTitle(allScoredResults);
    }

    displayedCount = 0;

    // queryFacetCounts is fixed for the typed query — computed once in doSearch's
    // primary pass and deliberately NOT recomputed here. The panel (dimensions,
    // values, counts, order) is therefore byte-identical before and after AI
    // expansion; only the result list and header count change.
    renderFilters();

    renderResults(true);
    debugLog(`[scolta:expand] ${sortOverride ? 'Native sort' : 'Merged'}: ${allScoredResults.length} results`);
  }

  // --- Main search ---

  async function doSearch(preserveFilters, initialFilters) {
    preserveFilters = preserveFilters || false;
    const CONFIG = getInstanceConfig();
    const query = els.queryInput.value.trim();
    if (!query || !pagefind) return;

    const version = ++searchVersion;

    if (abortController) abortController.abort();
    abortController = new AbortController();

    currentQuery = query;

    displayedCount = 0;
    allScoredResults = [];
    conversationMessages = [];
    followUpCount = 0;
    if (!preserveFilters) {
      var effectiveFilters = initialFilters ? Object.assign({}, initialFilters) : {};
      if (!effectiveFilters.language && defaultLangCode && CONFIG.AUTO_LANGUAGE_FILTER) {
        var langs = CONFIG.AI_LANGUAGES || [];
        if (langs.length > 1 && langs.includes(defaultLangCode)) {
          effectiveFilters.language = new Set([defaultLangCode]);
        }
      }
      activeFilters = effectiveFilters;
    }

    // Update URL with search query and active filter state.
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('q', query);
      for (const key of [...url.searchParams.keys()]) {
        if (key.startsWith('f_')) url.searchParams.delete(key);
      }
      for (const [dim, vals] of Object.entries(activeFilters)) {
        if (vals instanceof Set && vals.size > 0) {
          url.searchParams.set('f_' + dim, [...vals].join(','));
        }
      }
      history.replaceState(null, '', url.toString());
    } catch (e) {
      // Silently ignore — URL sync is non-critical.
    }

    els.layout.style.display = "grid";
    els.results.innerHTML = '<p class="scolta-searching">Searching...</p>';
    els.resultsHeader.innerHTML = "";
    els.noResults.style.display = "none";
    els.aiSummary.style.display = "none";
    els.loadMore.style.display = "none";
    if (!preserveFilters) {
      els.expandedTerms.style.display = "none";
    }

    const meaningfulTerms = extractSearchTerms(query);
    const searchQuery = meaningfulTerms.length > 0 ? meaningfulTerms.join(' ') : query;
    // Detect quoted phrase: user typed "hello world" with surrounding double-quotes.
    // Pagefind receives the unquoted terms; the Rust scorer receives the quoted form
    // so extract_query() can set forced_phrase = true and apply phrase multipliers.
    const trimmedQuery = query.trim();
    const isForcedPhrase =
      trimmedQuery.startsWith('"') && trimmedQuery.endsWith('"') && trimmedQuery.length > 2;
    const scorerQuery = isForcedPhrase ? trimmedQuery : searchQuery;
    debugLog('[scolta:search] Filtered query:', JSON.stringify(sanitizeQueryForLogging(searchQuery)), '(original:', JSON.stringify(sanitizeQueryForLogging(query)), ')');

    allHighlightTerms = meaningfulTerms.length > 0
      ? meaningfulTerms.filter(t => t.length > 2)
      : query.toLowerCase().split(/\s+/).filter(t => t.length > 2);

    // Phase 1: Primary search — render results IMMEDIATELY
    const expandPromise = preserveFilters
      ? Promise.resolve(lastExpandedTerms)
      : expandQuery(query);
    expansionInFlight = !preserveFilters && CONFIG.AI_EXPAND_QUERY;

    const primarySearch = await pagefindSearch(searchQuery, activeFilters);
    allScoredResults = await loadAndScoreSearch(primarySearch, scorerQuery, 1.0);

    // OR fallback: only activate when AND search returns ZERO results.
    // This prevents diluting precision when the user provides many terms
    // to find a specific piece of content. Forced-phrase queries (quoted)
    // never fall back to OR — the user explicitly asked for phrase results.
    usedOrFallback = false;
    if (!isForcedPhrase && meaningfulTerms.length > 1 && primarySearch.results.length === 0) {
      debugLog('[scolta:search] AND returned 0 results — running OR fallback');
      const orQueries = meaningfulTerms.map(term => ({ term, weight: 0.6 }));
      const orResults = await searchAndLoadParallel(orQueries, activeFilters, searchQuery);
      allScoredResults = mergeResults(allScoredResults, orResults);
      usedOrFallback = allScoredResults.length > 0;
    }

    allScoredResults.sort((a, b) => b.score - a.score);
    allScoredResults = deduplicateByTitle(allScoredResults);

    const priorityPages = getInstancePriorityPages();
    if (priorityPages.length > 0 && scoltaWasm && scoltaWasm.match_priority_pages) {
      try {
        const priorityInput = JSON.stringify({ query: currentQuery, priority_pages: priorityPages });
        const priorityMatches = JSON.parse(scoltaWasm.match_priority_pages(priorityInput));
        if (priorityMatches && priorityMatches.length > 0) {
          const priorityMap = {};
          priorityMatches.forEach(pm => {
            priorityMap[(pm.url || '').replace(/\/$/, '').toLowerCase()] = pm;
          });
          allScoredResults.forEach(result => {
            const url = resolveUrl(result.data.url || '').replace(/\/$/, '').toLowerCase();
            if (priorityMap[url]) {
              result.score = (result.score || 0) + (priorityMap[url].boost || 100);
            }
          });
          allScoredResults.sort((a, b) => b.score - a.score);
        }
      } catch (e) {
        console.warn('[scolta] Priority page matching failed', e);
      }
    }

    // Counts are a fixed property of the typed query: compute them once, only
    // when the typed query changes (!preserveFilters). A facet toggle, sort, or
    // load-more (preserveFilters === true) reuses the stored counts so the panel
    // numbers never move on click.
    if (!preserveFilters) {
      queryFacetCounts = await computeQueryFacetCounts(searchQuery, activeFilters);
    }

    renderFilters();
    renderResults();

    // Phase 2+3: Expand, merge, then summarize with the final reordered results.
    // Summarize is intentionally deferred until after expansion so the AI sees
    // the same ranking the user sees (expanded terms promote more relevant results).
    expandPromise.then(async expansion => {
      expansionInFlight = false;
      // expansion is { terms, sort_hint, subject_terms, filter_hint } or null (or a plain array for legacy cache hits).
      const expandedTerms = Array.isArray(expansion) ? expansion : (expansion?.terms ?? null);
      const sortHint = Array.isArray(expansion) ? null : (expansion?.sort_hint ?? null);
      const subjectTerms = Array.isArray(expansion) ? null : (Array.isArray(expansion?.subject_terms) ? expansion.subject_terms : null);
      const filterHint = Array.isArray(expansion) ? null : (expansion?.filter_hint ?? null);

      if (!preserveFilters) {
        lastExpandedTerms = expansion;
        currentSortOverride = sortHint;
        // Apply LLM-detected filter intent by merging into activeFilters.
        llmAppliedFilters = {};
        if (filterHint) {
          for (const [dim, val] of Object.entries(filterHint)) {
            if (typeof dim === 'string' && dim && typeof val === 'string' && val) {
              let canonicalVal = val;
              if (cachedPagefindFilters && cachedPagefindFilters[dim]) {
                const knownValues = Object.keys(cachedPagefindFilters[dim]);
                if (!knownValues.includes(val)) {
                  const lowerVal = val.toLowerCase();
                  const ciMatch = knownValues.find(v => v.toLowerCase() === lowerVal);
                  if (ciMatch) canonicalVal = ciMatch;
                }
              }
              llmAppliedFilters[dim] = canonicalVal;
              if (!activeFilters[dim]) {
                activeFilters[dim] = new Set();
              }
              activeFilters[dim].add(canonicalVal);
            }
          }
        }
      }
      renderExpandedTerms(expandedTerms, query);
      await mergeExpandedSearchResults(expandedTerms, query, searchQuery, preserveFilters, version, currentSortOverride, subjectTerms);

      if (version !== searchVersion) return;

      // If mergeExpandedSearchResults returned early (no valid terms, no sort override),
      // it did not call renderResults(); show the final state now.
      if (allScoredResults.length === 0) {
        renderResults();
      }

      renderSortIndicator(currentSortOverride);
      renderFilterBadges();

      const expandedLabel = expandedTerms
        ? expandedTerms.filter(t => t.toLowerCase() !== query.toLowerCase())
        : [];
      summarizeResults(query, allScoredResults, expandedLabel, sortHint, filterHint, activeFilters);
    });
  }

  function clearSearch() {
    if (abortController) abortController.abort();
    abortController = null;
    els.queryInput.value = '';
    els.searchClear.style.display = "none";
    els.layout.style.display = "none";
    els.expandedTerms.style.display = "none";
    els.aiSummary.style.display = "none";
    els.noResults.style.display = "none";
    els.sortIndicator.style.display = "none";
    els.sortIndicator.innerHTML = '';
    if (els.filterIndicator) {
      els.filterIndicator.style.display = "none";
      els.filterIndicator.innerHTML = '';
    }
    allScoredResults = [];
    displayedCount = 0;
    conversationMessages = [];
    followUpCount = 0;
    activeFilters = {};
    currentSortOverride = null;
    llmAppliedFilters = {};
    expansionInFlight = false;

    // Remove search query and filter params from URL.
    try {
      var url = new URL(window.location.href);
      url.searchParams.delete('q');
      for (const key of [...url.searchParams.keys()]) {
        if (key.startsWith('f_')) url.searchParams.delete(key);
      }
      history.replaceState(null, '', url.toString());
    } catch (e) {
      // Silently ignore.
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
    els.queryInput.focus();
  }

  // --- Filter handling ---

  function renderFilters() {
    const container = els.filters;
    const taxonomy = cachedPagefindFilters || {};

    // Dimensions are driven by the index taxonomy, NOT the result set: show
    // every dimension that is not infrastructure (SKIP_FILTER_DIMENSIONS) and
    // has more than one distinct value in the taxonomy. A globally single-value
    // dimension is not a useful facet. This gate is query-independent, so no
    // dimension ever appears, disappears, or reorders while searching.
    const dims = Object.keys(taxonomy).filter(
      dim => !SKIP_FILTER_DIMENSIONS.has(dim.toLowerCase())
          && Object.keys(taxonomy[dim]).length > 1
    );

    // Sort dimensions alphabetically by display label.
    dims.sort((a, b) => filterDimLabel(a).localeCompare(filterDimLabel(b)));

    if (dims.length === 0) {
      container.innerHTML = "";
      els.layout.classList.remove("has-filters");
      return;
    }

    els.layout.classList.add("has-filters");
    let html = "";
    for (const dim of dims) {
      html += `<div class="scolta-filter-group"><h3>${escapeHtml(filterDimLabel(dim))}</h3>`;
      const dimFilters = activeFilters[dim] || new Set();
      const dimCounts = queryFacetCounts[dim] || {};
      // Values come from the taxonomy, sorted alphabetically by display value —
      // never by count, which would reorder as counts change. The full value
      // list is fixed across searches and facet clicks.
      const vals = Object.keys(taxonomy[dim]).sort(
        (a, b) => filterDisplayValue(dim, a).localeCompare(filterDisplayValue(dim, b))
      );
      for (const val of vals) {
        const count = dimCounts[val] ?? 0;
        const isActive = dimFilters.has(val);
        // Uniform zero policy: a count-0 value is shown but disabled, UNLESS it
        // is currently active (an active value must always remain uncheckable).
        const disabled = (count === 0 && !isActive) ? " disabled" : "";
        const checked = isActive ? "checked" : "";
        const activeClass = isActive ? " active" : "";
        html += `<label class="scolta-filter-item${activeClass}">
          <input type="checkbox" value="${escapeHtml(val)}" ${checked}${disabled}
                 data-scolta-filter-dim="${escapeHtml(dim)}" data-scolta-filter-val="${escapeHtml(val)}">
          ${escapeHtml(filterDisplayValue(dim, val))} <span class="scolta-filter-count">(${count})</span>
        </label>`;
      }
      html += `</div>`;
    }
    container.innerHTML = html;
  }

  async function toggleFilter(dimension, value) {
    if (!activeFilters[dimension]) {
      activeFilters[dimension] = new Set();
    }
    if (activeFilters[dimension].has(value)) {
      activeFilters[dimension].delete(value);
      if (activeFilters[dimension].size === 0) {
        delete activeFilters[dimension];
      }
    } else {
      activeFilters[dimension].add(value);
    }
    await doSearch(true);
  }

  // --- Result rendering ---

  function highlightTerms(text) {
    if (!text || allHighlightTerms.length === 0) return text;
    let result = text;
    for (const term of allHighlightTerms) {
      const regex = new RegExp(`(${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, "gi");
      result = result.replace(regex, "<mark>$1</mark>");
    }
    return result;
  }

  function truncateExcerpt(text, maxLen) {
    const clean = escapeHtml(stripHtml(text));
    if (clean.length <= maxLen) return clean;
    const truncated = clean.substring(0, maxLen);
    const lastSpace = truncated.lastIndexOf(" ");
    return (lastSpace > maxLen * 0.8 ? truncated.substring(0, lastSpace) : truncated) + "\u2026";
  }

  function renderResults(isExpanded) {
    isExpanded = isExpanded || false;
    const CONFIG = getInstanceConfig();
    const container = els.results;
    const header = els.resultsHeader;
    const noResults = els.noResults;
    const loadMore = els.loadMore;

    const filtered = allScoredResults;

    if (filtered.length === 0) {
      container.innerHTML = "";
      header.innerHTML = "";
      if (expansionInFlight) {
        return;
      }
      noResults.style.display = "block";
      loadMore.style.display = "none";
      return;
    }

    noResults.style.display = "none";
    const showing = Math.min(displayedCount + CONFIG.RESULTS_PER_PAGE, filtered.length);
    const expandLabel = isExpanded ? ' (with expanded terms)' : '';
    const filterLabel = Object.keys(activeFilters).length > 0
      ? ' in ' + Object.entries(activeFilters)
          .filter(([, vals]) => vals instanceof Set && vals.size > 0)
          .map(([dim, vals]) => [...vals].map(v => filterDisplayValue(dim, v)).join(', '))
          .join('; ')
      : '';
    const orFallbackLabel = usedOrFallback ? ' — no exact matches found, showing partial matches' : '';
    header.innerHTML = `<span>${filtered.length.toLocaleString()} results for "${escapeHtml(currentQuery)}"${filterLabel}${expandLabel}${orFallbackLabel}</span>
                        <span>Showing ${showing}</span>`;

    let html = "";
    for (let i = displayedCount; i < showing; i++) {
      const { data } = filtered[i];
      const title = data.meta?.title || "Untitled";
      const url = data.meta?.url || resolveUrl(data.url || '') || data.url || "#";
      const site = data.meta?.site || "";
      const date = data.meta?.date || "";
      const excerpt = truncateExcerpt(data.excerpt || "", CONFIG.EXCERPT_LENGTH);
      const highlighted = highlightTerms(excerpt);

      const safeTitle = escapeHtml(stripHtml(title));
      const displayTitle = safeTitle.length > 90 ? safeTitle.substring(0, 87) + "\u2026" : safeTitle;

      html += `<div class="scolta-result-card">
        <a class="scolta-result-title" href="${url}" target="_blank" rel="noopener"
           title="${safeTitle.replace(/"/g, '&quot;')}">${highlightTerms(displayTitle)}</a>
        <div class="scolta-result-meta">
          ${site ? `<span class="scolta-site-badge">${escapeHtml(site)}</span>` : ""}
          ${date ? `<span class="scolta-result-date">${escapeHtml(date)}</span>` : ""}
        </div>
        <a class="scolta-result-url" href="${url}" target="_blank" rel="noopener">${escapeHtml(url)}</a>
        <div class="scolta-result-excerpt">${highlighted}</div>
      </div>`;
    }

    if (displayedCount === 0) {
      container.innerHTML = html;
    } else {
      // Append without re-parsing existing DOM nodes (avoids tearing down
      // and rebuilding all existing result cards on "show more").
      container.insertAdjacentHTML('beforeend', html);
    }
    displayedCount = showing;

    loadMore.style.display = (showing < filtered.length) ? "block" : "none";
  }

  function showMore() {
    const terms = Array.isArray(lastExpandedTerms) ? lastExpandedTerms : lastExpandedTerms?.terms;
    renderResults(terms && terms.length > 0);
  }

  // ==========================================================================
  // PUBLIC API
  // ==========================================================================

  function init(containerSelector) {
    const root = document.querySelector(containerSelector || '#scolta-search');
    if (!root) {
      console.error('[scolta] Container not found:', containerSelector);
      return;
    }

    // Build the search UI inside the container.
    root.innerHTML = `
      <div class="scolta-search-box">
        <div class="scolta-search-input-wrap">
          <input type="text" id="scolta-query" placeholder="Search..."
                 autofocus autocomplete="off">
          <button class="scolta-search-clear" id="scolta-search-clear"
                  style="display:none;" aria-label="Clear search">&times;</button>
        </div>
        <button class="scolta-search-btn" id="scolta-search-btn">Search</button>
      </div>

      <div id="scolta-expanded-terms" class="scolta-expanded-terms" style="display:none;"></div>

      <div class="scolta-layout" id="scolta-layout" style="display:none;">
        <aside class="scolta-filters" id="scolta-filters"></aside>
        <div>
          <div id="scolta-ai-summary" style="display:none;"></div>
          <div id="scolta-sort-indicator" style="display:none;"></div>
          <div id="scolta-filter-indicator" style="display:none;"></div>
          <div class="scolta-results-header" id="scolta-results-header"></div>
          <div id="scolta-results"></div>
          <button class="scolta-load-more" id="scolta-load-more" style="display:none;">Show more results</button>
        </div>
      </div>

      <div class="scolta-no-results" id="scolta-no-results" style="display:none;">
        <p style="font-size:1.2rem;">No results found.</p>
        <p style="margin-top:0.5rem;">Try different keywords or clear your site filters.</p>
      </div>
    `;

    // Cache DOM references.
    els = {
      queryInput: root.querySelector('#scolta-query'),
      searchClear: root.querySelector('#scolta-search-clear'),
      searchBtn: root.querySelector('#scolta-search-btn'),
      expandedTerms: root.querySelector('#scolta-expanded-terms'),
      layout: root.querySelector('#scolta-layout'),
      filters: root.querySelector('#scolta-filters'),
      aiSummary: root.querySelector('#scolta-ai-summary'),
      sortIndicator: root.querySelector('#scolta-sort-indicator'),
      filterIndicator: root.querySelector('#scolta-filter-indicator'),
      resultsHeader: root.querySelector('#scolta-results-header'),
      results: root.querySelector('#scolta-results'),
      loadMore: root.querySelector('#scolta-load-more'),
      noResults: root.querySelector('#scolta-no-results'),
    };

    // Event listeners.
    els.queryInput.addEventListener("keydown", (e) => {
      if (e.key === "Enter") doSearch();
    });

    els.queryInput.addEventListener("input", () => {
      els.searchClear.style.display = els.queryInput.value.length > 0 ? "block" : "none";
    });

    els.searchClear.addEventListener("click", clearSearch);
    els.searchBtn.addEventListener("click", () => doSearch());
    els.loadMore.addEventListener("click", showMore);

    // Event delegation for dynamically rendered elements.
    // This replaces inline onclick/onchange handlers with a single listener,
    // avoiding fragile string escaping and ensuring robust event handling
    // for all dynamically created UI elements.
    root.addEventListener("click", (e) => {
      // Expanded term click → search that term
      const termEl = e.target.closest("[data-scolta-search-term]");
      if (termEl) {
        searchTerm(termEl.dataset.scoltaSearchTerm);
        return;
      }
      // Sort indicator dismiss → fall back to relevance sort
      if (e.target.closest("[data-scolta-sort-dismiss]")) {
        dismissSortOverride();
        return;
      }
      // Filter badge dismiss → remove that LLM-applied filter
      const filterDismissEl = e.target.closest("[data-scolta-filter-dismiss]");
      if (filterDismissEl) {
        dismissLlmFilter(filterDismissEl.dataset.scoltaFilterDismiss);
        return;
      }
      // Follow-up submit button
      if (e.target.closest("[data-scolta-followup-submit]")) {
        submitFollowUp();
        return;
      }
    });

    root.addEventListener("change", (e) => {
      // Filter checkbox toggle
      const filterEl = e.target.closest("[data-scolta-filter-dim]");
      if (filterEl) {
        toggleFilter(filterEl.dataset.scoltaFilterDim, filterEl.dataset.scoltaFilterVal);
      }
    });

    root.addEventListener("keydown", (e) => {
      // Follow-up input Enter key
      if (e.key === "Enter" && e.target.closest("[data-scolta-followup-input]")) {
        submitFollowUp();
      }
    });

    // Handle browser back/forward navigation between searches.
    window.addEventListener("popstate", () => {
      try {
        var urlParams = new URLSearchParams(window.location.search);
        var urlQuery = urlParams.get('q');
        if (urlQuery) {
          els.queryInput.value = urlQuery;
          els.searchClear.style.display = "block";
          var restoredFilters = {};
          for (const [key, val] of urlParams.entries()) {
            if (key.startsWith('f_') && val) {
              var filterDim = key.slice(2);
              var filterVals = val.split(',').filter(Boolean);
              if (filterVals.length > 0) restoredFilters[filterDim] = new Set(filterVals);
            }
          }
          if (getInstanceConfig().AUTO_LANGUAGE_FILTER && defaultLangCode && restoredFilters.language) {
            if (!restoredFilters.language.has(defaultLangCode)) {
              restoredFilters.language = new Set([defaultLangCode]);
            }
          }
          doSearch(false, Object.keys(restoredFilters).length > 0 ? restoredFilters : null);
        } else {
          clearSearch();
        }
      } catch (e) {
        // Silently ignore.
      }
    });

    // Load Pagefind and Scolta WASM in parallel.
    Promise.all([initPagefind(), initScoltaWasm()]).then(() => {
      debugLog("[scolta] Ready — Pagefind + WASM loaded");

      // If URL contains ?q=<query>, auto-execute the search and restore filter state.
      try {
        var urlParams = new URLSearchParams(window.location.search);
        var urlQuery = urlParams.get('q');
        if (urlQuery) {
          els.queryInput.value = urlQuery;
          els.searchClear.style.display = "block";
          var initialFilters = {};
          for (const [key, val] of urlParams.entries()) {
            if (key.startsWith('f_') && val) {
              var filterDim = key.slice(2);
              var filterVals = val.split(',').filter(Boolean);
              if (filterVals.length > 0) initialFilters[filterDim] = new Set(filterVals);
            }
          }
          if (getInstanceConfig().AUTO_LANGUAGE_FILTER && defaultLangCode && initialFilters.language) {
            if (!initialFilters.language.has(defaultLangCode)) {
              initialFilters.language = new Set([defaultLangCode]);
            }
          }
          doSearch(false, Object.keys(initialFilters).length > 0 ? initialFilters : null);
        }
      } catch (e) {
        // Silently ignore — URL parsing is non-critical.
      }
    });

    debugLog("[scolta] Initialized");
  }

  // Initialize the instance by building the UI inside the container.
  init(containerSelector);
  // If init failed to find the container, root will be empty.
  var root = document.querySelector(containerSelector || '#scolta-search');
  if (!root || !root.hasChildNodes()) {
    return null;
  }

  // Return the instance's public API.
  return {
    searchTerm,
    submitFollowUp,
    toggleFilter,
    clearSearch,
    doSearch,
    batchScoreResults,
    showMore,
    destroy: function() {
      if (abortController) abortController.abort();
      root.innerHTML = '';
      els = {};
    },
  };

  } // end createInstance

  // ==========================================================================
  // BACKWARD-COMPATIBLE PUBLIC API
  // ==========================================================================
  // Scolta.init() creates a default instance using window.scolta config.
  // Scolta.createInstance() allows multiple independent widgets.

  global.Scolta = global.Scolta || {};

  global.Scolta.createInstance = function(containerSelector, config) {
    return createInstance(containerSelector, config);
  };

  // Backward-compatible init: creates a default instance from window.scolta.
  global.Scolta.init = function(containerSelector) {
    if (global.Scolta.defaultInstance) return; // already initialized
    global.Scolta.defaultInstance = createInstance(
      containerSelector || '#scolta-search',
      global.scolta
    );
    // Expose instance methods on Scolta for backward compat.
    if (global.Scolta.defaultInstance) {
      var inst = global.Scolta.defaultInstance;
      global.Scolta.searchTerm = inst.searchTerm;
      global.Scolta.submitFollowUp = inst.submitFollowUp;
      global.Scolta.toggleFilter = inst.toggleFilter;
      global.Scolta.clearSearch = inst.clearSearch;
      global.Scolta.doSearch = inst.doSearch;
      global.Scolta.showMore = inst.showMore;
      global.Scolta.batchScoreResults = inst.batchScoreResults;
    }
  };

  // Auto-initialize when the DOM is ready, if window.scolta config is present.
  function autoInit() {
    if (global.scolta && global.scolta.container) {
      var container = document.querySelector(global.scolta.container);
      if (container && !container.hasChildNodes()) {
        global.Scolta.init(global.scolta.container);
      }
    }
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', autoInit);
    } else {
      autoInit();
    }
  }

})(typeof window !== 'undefined' ? window : this);
