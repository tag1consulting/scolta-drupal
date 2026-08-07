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
 *   hideEmptyFacets: true                  — Optional (default true): hide facet values whose count is
 *                                            zero for the current query (and their now-empty groups).
 *                                            Set false to render zero-count values as disabled (0) rows.
 *                                            An active value stays visible even at zero either way.
 *   saytEnabled: true                      — Optional: search as you type. See docs/SAYT.md for the
 *   saytMinChars: 2                          full behaviour and for when to change each of these.
 *   saytDebounceMs: 150                      Suggestions populate a dropdown while typing; the full
 *   saytMaxSuggestions: 6                    pipeline still runs only on Enter or on selecting a
 *   saytRecentSearches: true                 suggestion. saytEnabled:false restores the pre-1.1.0
 *   saytMaxRecent: 3                         widget exactly: no dropdown node, no combobox roles,
 *   saytExpand: true                         no storage access, no suggest searches.
 *   saytExpandPerMinute: 6
 *   saytExpansionDelayMs: 500
 *   saytSuggestionAction: 'navigate'       — 'navigate' | 'search'
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
      SPECIFICITY_WEIGHTING: s.SPECIFICITY_WEIGHTING ?? true,
      SPECIFICITY_FLOOR: s.SPECIFICITY_FLOOR ?? 0.15,
      SPECIFICITY_STRONG_MATCH: s.SPECIFICITY_STRONG_MATCH ?? 0.55,
      SPECIFICITY_COOCCURRENCE: s.SPECIFICITY_COOCCURRENCE ?? 0.9,
      SPECIFICITY_AGREEMENT_GATE: s.SPECIFICITY_AGREEMENT_GATE ?? 0.45,
      SPECIFICITY_AGREEMENT_DECAY: s.SPECIFICITY_AGREEMENT_DECAY ?? 1,
      FILTER_HINT_MIN_RESULTS: s.FILTER_HINT_MIN_RESULTS ?? 5,
      FILTER_HINT_MIN_RATIO: s.FILTER_HINT_MIN_RATIO ?? 0.1,
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

  // Index-chunk preloading (see schedulePreload()). The debounce keeps the
  // per-keystroke WASM chunk lookup off the typing path while still firing
  // long before a human reaches Enter.
  const PRELOAD_DEBOUNCE_MS = 150;
  const PRELOAD_MIN_CHARS = 2;

  // ==========================================================================
  // SEARCH AS YOU TYPE (SAYT)
  // ==========================================================================
  //
  // Suggestions populate a dropdown while the user types; the full pipeline
  // (expand -> merge -> summarize -> follow-up) still runs only on Enter, on the
  // search button, or on selecting a suggestion. The two paths are deliberately
  // separate machines: doSearch() owns searchVersion, the abortController, the
  // per-cycle search memo, history and every results-region element, and the
  // suggest path owns none of them. See docs/SAYT.md.
  //
  // Every default below is byte-equal to the corresponding ScoltaConfig PHP
  // default; BrowserConfigParityTest pins the two key sets together in both
  // directions, and ScoltaConfigTest pins the values.
  const SAYT_DEFAULTS = Object.freeze({
    enabled: true,
    minChars: 2,
    debounceMs: 150,
    maxSuggestions: 6,
    recentSearches: true,
    maxRecent: 3,
    expand: true,
    expandPerMinute: 6,
    expansionDelayMs: 500,
    suggestionAction: 'navigate',
  });

  const SAYT_ACTIONS = ['navigate', 'search'];

  // localStorage is per-origin, so every Scolta instance on one origin shares
  // one recent-search history. That is deliberate (a visitor's recent searches
  // are a property of the visitor, not of the widget) and documented.
  const SAYT_RECENT_KEY = 'scolta:recent-searches';

  // How many recent searches are STORED, independent of how many are shown
  // (sayt_max_recent). Keeping a couple of spares means the prefix filter still
  // has something to match after the newest entries stop matching.
  const SAYT_RECENT_STORED_MAX = 5;

  // How long an enrichment call stays in the sliding window, in ms.
  const SAYT_EXPAND_WINDOW_MS = 60000;

  // Weight applied when scoring documents an AI expansion term found, rather
  // than the prefix the user typed. Same reduction the result path's OR
  // fallback uses: an expansion hit is real but it is not what was typed, so it
  // must not outrank a direct prefix match in a six-row dropdown.
  const SAYT_EXPANDED_WEIGHT = 0.6;

  // Lazily built once: constructing an Intl.Segmenter is expensive relative to
  // the keystroke path it runs on. null means "not tried yet", false means
  // "unavailable in this engine".
  let saytSegmenter = null;

  // Count USER-PERCEIVED characters, not UTF-16 code units. "\u{1F1EE}\u{1F1F9}"
  // is one flag to a reader and four to `.length`, and a Devanagari or Hangul
  // cluster is one character to the person typing it. Intl.Segmenter is the
  // correct answer; the spread fallback at least collapses surrogate pairs.
  function saytGraphemeLength(str) {
    const s = String(str == null ? '' : str);
    if (saytSegmenter === null) {
      try {
        saytSegmenter = (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function')
          ? new Intl.Segmenter(undefined, { granularity: 'grapheme' })
          : false;
      } catch (e) {
        saytSegmenter = false;
      }
    }
    if (saytSegmenter) {
      try {
        let n = 0;
        for (const _ of saytSegmenter.segment(s)) n++;
        return n;
      } catch (e) {
        // Fall through to the spread count.
      }
    }
    return [...s].length;
  }

  // Coerce a config value that a CMS settings layer may hand over as a string.
  function saytBool(value, fallback) {
    if (value === undefined || value === null || value === '') return fallback;
    if (typeof value === 'string') return value !== '0' && value.toLowerCase() !== 'false';
    return !!value;
  }

  function saytInt(value, fallback, min) {
    const n = parseInt(value, 10);
    if (!isFinite(n)) return fallback;
    return (min !== undefined && n < min) ? min : n;
  }

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

  // Platform-supplied result renderer, registered through
  // Scolta.setResultRenderer(). Module-scoped rather than per-instance so
  // registration works before any instance exists — the common case, since a
  // platform registers on script load and Scolta.init() runs on DOMContentLoaded.
  // An instance that registers its own (instance.setResultRenderer) overrides it.
  //
  // Deliberately a registration function and NOT a config key: a function cannot
  // survive the PHP → ScoltaConfig::toBrowserConfig() → drupalSettings → JSON
  // round trip, and a browser config key that no PHP layer emits would need a
  // BrowserConfigParityTest::REVERSE_ALLOWLIST entry with a written
  // justification. Registration keeps the config surface untouched. Do not
  // "simplify" this into a config key.
  let globalResultRenderer = null;

  // Platform-supplied suggestion renderer, registered through
  // Scolta.setSuggestionRenderer(). Everything said above about
  // globalResultRenderer applies here for the same reasons: module-scoped so
  // registration works before any instance exists, overridable per instance,
  // and deliberately a registration function rather than a config key.
  let globalSuggestionRenderer = null;

  function createInstance(containerSelector, instanceConfig) {

  // --- Instance state (local to this closure) ---
  let pagefind = null;
  let allScoredResults = [];
  let displayedCount = 0;
  let activeFilters = {};
  let conversationMessages = [];
  let followUpCount = 0;
  let abortController = null;
  // Watches the resolved summary's text region so the clamp decision follows
  // the width instead of being frozen at the width it resolved in. Exactly one
  // at a time; see observeSummaryClamp().
  let summaryClampObserver = null;
  let queryFacetCounts = {};   // { dimension: { value: count } } — per typed query, folded once when expansion lands
  let currentQuery = "";
  let allHighlightTerms = [];
  let lastExpandedTerms = null;
  let searchVersion = 0;
  let usedOrFallback = false;
  // True when at least one high-specificity (rare) term produced results in the
  // current search. Distinguishes a retrieval-mode fallback that still found the
  // on-intent documents from a genuine content gap, so the "partial matches"
  // banner and the AI-summary absence hedge don't cry failure over a strong hit.
  let hadSpecificMatch = false;
  let pagefindBase = '';   // Set during initPagefind(); used by resolveUrl().
  let currentSortOverride = null;    // { field, direction } or null — active sort override
  let llmAppliedFilters = {};        // { dimension: value } — filters injected by LLM expansion
  let offeredLlmFilters = {};        // { dimension: value } — LLM hints the recall guard declined to auto-apply
  let expansionInFlight = false;     // true while an expand-query HTTP request is pending
  let cachedPagefindFilters = null;  // { dimension: { value: count } } — from pagefind.filters()
  let cachedPagefindPageCount = null; // total indexed pages across languages — from pagefind-entry.json
  let preloadTimer = null;           // trailing-debounce handle for schedulePreload()
  let lastPreloadedTerm = '';        // last term handed to pagefind.preload(); skips repeat work

  // --- SAYT state ---
  // suggestVersion is to the suggest path what searchVersion is to doSearch():
  // incremented at the start of every cycle and re-checked after every await, so
  // a late-resolving cycle whose input has moved on performs ZERO DOM writes.
  // Cancelling is just an increment (cancelSuggest), which is why nothing here
  // needs an AbortController of its own.
  let suggestVersion = 0;
  let suggestTimer = null;           // trailing-debounce handle for scheduleSuggest()
  let suggestExpandTimer = null;     // idle handle for the AI enrichment call
  let suggestions = [];              // the rendered dropdown model, in DOM order
  let activeSuggestion = -1;         // index into suggestions; -1 = nothing active
  let suggestOpen = false;
  let suggestQuery = '';             // the prefix the open dropdown describes
  let suggestBlurTimer = null;
  let saytExpandCalls = [];          // ms timestamps, for the sliding-window budget
  let saytActionWarned = false;      // warn once per instance, not once per keystroke
  // searchVersion of the doSearch() cycle that has started but not yet painted,
  // or 0 when none is. No suggest cycle runs while it is set: the user has
  // committed, and a dropdown repainting over a search that is mid-flight is
  // noise. A version rather than a boolean because doSearch() cycles overlap —
  // the window belongs to whichever cycle opened it last, and only that cycle
  // may release it. doSearch() releases it in a finally covering the whole
  // pre-paint region, so a search that throws anywhere cannot wedge the suggest
  // path off for the life of the page.
  let paintingVersion = 0;
  // Per-search-cycle memo of in-flight Pagefind searches, keyed by
  // searchMemoKey(). Cleared at the top of every doSearch() — see the comment
  // above pagefindSearch() for why identical searches within one cycle are both
  // safe to share and expensive to repeat.
  let searchMemo = new Map();

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
  let rootEl = null;                    // mount point; lifecycle events bubble through it
  let scaffoldNodes = [];               // exactly the nodes init() inserted — destroy() removes these and nothing else
  let instanceResultRenderer = null;    // per-instance override of globalResultRenderer
  let instanceSuggestionRenderer = null; // per-instance override of globalSuggestionRenderer
  // What is currently painted in #scolta-results, in DOM order:
  // [{ key, nodes: [Node, ...] }]. Drives the keyed reconcile in renderResults()
  // so a repaint that changes nothing moves no nodes.
  let paintedEntries = [];
  // Highlight terms the painted built-in cards were built with. Expansion grows
  // allHighlightTerms, so a card painted before it carries stale <mark> spans and
  // must be rebuilt even when its position and identity are unchanged.
  let paintedHighlightSignature = null;

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
      SPECIFICITY_WEIGHTING: s.SPECIFICITY_WEIGHTING ?? true,
      SPECIFICITY_FLOOR: s.SPECIFICITY_FLOOR ?? 0.15,
      SPECIFICITY_STRONG_MATCH: s.SPECIFICITY_STRONG_MATCH ?? 0.55,
      SPECIFICITY_COOCCURRENCE: s.SPECIFICITY_COOCCURRENCE ?? 0.9,
      SPECIFICITY_AGREEMENT_GATE: s.SPECIFICITY_AGREEMENT_GATE ?? 0.45,
      SPECIFICITY_AGREEMENT_DECAY: s.SPECIFICITY_AGREEMENT_DECAY ?? 1,
      FILTER_HINT_MIN_RESULTS: s.FILTER_HINT_MIN_RESULTS ?? 5,
      FILTER_HINT_MIN_RATIO: s.FILTER_HINT_MIN_RATIO ?? 0.1,
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

  // SAYT settings are TOP-LEVEL instance config, not `scoring` keys — the
  // hideEmptyFacets pattern. They govern UI behaviour, not ranking, and
  // toJsScoringConfig() stays at exactly 40 keys.
  function getSaytConfig() {
    if (!instanceConfig) return SAYT_DEFAULTS;

    let action = instanceConfig.saytSuggestionAction;
    if (action === undefined || action === null || action === '') {
      action = SAYT_DEFAULTS.suggestionAction;
    } else if (SAYT_ACTIONS.indexOf(String(action)) === -1) {
      // Clamp rather than throw: a typo in a site's settings form must degrade
      // to the safe default, not break the search box.
      if (!saytActionWarned) {
        saytActionWarned = true;
        console.warn('[scolta:sayt] Unknown sayt_suggestion_action', JSON.stringify(String(action)) +
          '; expected one of ' + SAYT_ACTIONS.join(', ') + '. Falling back to ' +
          SAYT_DEFAULTS.suggestionAction + '.');
      }
      action = SAYT_DEFAULTS.suggestionAction;
    } else {
      action = String(action);
    }

    return {
      enabled: saytBool(instanceConfig.saytEnabled, SAYT_DEFAULTS.enabled),
      minChars: saytInt(instanceConfig.saytMinChars, SAYT_DEFAULTS.minChars, 1),
      debounceMs: saytInt(instanceConfig.saytDebounceMs, SAYT_DEFAULTS.debounceMs, 0),
      maxSuggestions: saytInt(instanceConfig.saytMaxSuggestions, SAYT_DEFAULTS.maxSuggestions, 1),
      recentSearches: saytBool(instanceConfig.saytRecentSearches, SAYT_DEFAULTS.recentSearches),
      maxRecent: saytInt(instanceConfig.saytMaxRecent, SAYT_DEFAULTS.maxRecent, 0),
      expand: saytBool(instanceConfig.saytExpand, SAYT_DEFAULTS.expand),
      expandPerMinute: saytInt(instanceConfig.saytExpandPerMinute, SAYT_DEFAULTS.expandPerMinute, 0),
      expansionDelayMs: saytInt(instanceConfig.saytExpansionDelayMs, SAYT_DEFAULTS.expansionDelayMs, 0),
      suggestionAction: action,
    };
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

  // --- Scolta facet index ---
  //
  // Scolta needs exactly two things from Pagefind's filter feature: the values
  // of each dimension with their corpus-wide totals, and per-query counts. Both
  // come from `scolta.facets`, an artifact the index build emits, and reading it
  // means no `.pf_filter` chunk is ever fetched.
  //
  // Why it is worth an artifact: Pagefind's get_filters counts by scanning the
  // matched-result set linearly for every (value, page) posting in every LOADED
  // filter chunk, and it runs twice per search. So the moment any chunk is
  // loaded, every later search costs `matched results x loaded postings` — and
  // there is no unload path short of pagefind.destroy(). Measured on a
  // 109,308-page corpus (3,208,134 postings across ten dimensions), a query
  // matching 7,789 results took 155 ms with no chunk loaded and 18,589 ms with
  // all ten loaded. The cost tracks postings, not distinct values: a 19-value
  // dimension carrying 491,074 postings cost 2,859 ms, while a 55-value
  // dimension carrying 6,468 postings cost 104 ms, and collapsing a 3,421-value
  // dimension to 4 values while keeping every posting changed nothing
  // (10,151 ms to 10,178 ms). Doing the same counting here against the same
  // matched set takes about 4 ms.
  //
  // Two consequences for the code below. Counts must be computed over the FULL
  // matched set, not the ~75 fragments Scolta loads, or facet counts would move.
  // And filter application has to move too, not just counting: passing anything
  // in searchOpts.filters makes Pagefind lazily fetch that dimension's chunk,
  // after which every subsequent search pays the per-result cost for the life of
  // the page.
  //
  // Wire format, whole file gzipped:
  //   <json header>\n
  //   <one fragment hash per line, pageCount lines>
  //   <posting bodies, dimension order then value order from the header>
  // Each body is self-delimiting: tag 0x00 is a varint count then that many
  // varint deltas of ascending page indices, tag 0x01 is a ceil(pageCount / 8)
  // byte bitmap. The header carries every value's total, so the facet panel's
  // value list needs no posting decode at all.
  let facetIndex = null;
  // pf_meta hash of the index actually loaded, read from the cache-busted
  // pagefind-entry.json, and whether a secondary language index was merged in.
  let facetIndexExpectedHash = null;
  let facetIndexMergedLanguages = false;

  // Resolve the directory the index files live in, from the pagefind.js path.
  function facetIndexBase(pagefindPath) {
    return String(pagefindPath || '').replace(/pagefind\.js(\?.*)?$/, '');
  }

  async function gunzipToBytes(buffer) {
    const bytes = new Uint8Array(buffer);
    // A server may or may not have applied transport compression of its own. The
    // header is JSON, so an opening brace means the bytes arrived decompressed.
    if (bytes[0] === 0x7b) return bytes;
    if (typeof DecompressionStream !== 'function') {
      throw new Error('DecompressionStream unavailable');
    }
    const stream = new Response(bytes).body.pipeThrough(new DecompressionStream('gzip'));
    return new Uint8Array(await new Response(stream).arrayBuffer());
  }

  function readVarint(state) {
    let value = 0;
    let shift = 1;
    for (;;) {
      const byte = state.bytes[state.off++];
      if (byte === undefined) throw new Error('facet index truncated');
      value += (byte & 0x7f) * shift;
      if (byte < 0x80) return value;
      shift *= 128;
    }
  }

  function parseFacetIndex(bytes) {
    // One decoder for the whole parse: the id table is one entry per page, and
    // allocating a TextDecoder per line dominated the decode on a large corpus.
    const decoder = new TextDecoder();
    const newline = bytes.indexOf(10);
    if (newline < 0) throw new Error('facet index has no header');
    const header = JSON.parse(decoder.decode(bytes.subarray(0, newline)));
    if (header.format !== 'scolta-facets') {
      throw new Error('unexpected format ' + header.format);
    }
    if (header.version !== 1) {
      throw new Error('unsupported facet index version ' + header.version);
    }

    // The id table: pageCount newline-separated fragment hashes. Page index is
    // the line number, and that is the numbering the posting lists refer to.
    let off = newline + 1;
    const pageOf = new Map();
    for (let i = 0; i < header.pageCount; i++) {
      const end = bytes.indexOf(10, off);
      if (end < 0) throw new Error('facet index id table truncated');
      pageOf.set(decoder.decode(bytes.subarray(off, end)), i);
      off = end + 1;
    }

    const bitmapBytes = (header.pageCount + 7) >> 3;
    const state = { bytes: bytes, off: off };
    const postings = {};
    for (const dim of header.dimensions) {
      const values = header.values[dim] || [];
      const decoded = [];
      for (let v = 0; v < values.length; v++) {
        const tag = state.bytes[state.off++];
        if (tag === 1) {
          decoded.push({
            value: values[v][0],
            bitmap: state.bytes.subarray(state.off, state.off + bitmapBytes),
          });
          state.off += bitmapBytes;
        } else if (tag === 0) {
          const count = readVarint(state);
          const pages = new Int32Array(count);
          let prev = 0;
          for (let i = 0; i < count; i++) {
            prev += readVarint(state);
            pages[i] = prev;
          }
          decoded.push({ value: values[v][0], pages: pages });
        } else {
          throw new Error('facet index has unknown posting tag ' + tag);
        }
      }
      postings[dim] = decoded;
    }

    return {
      indexHash: header.indexHash || '',
      pageCount: header.pageCount,
      dimensions: header.dimensions,
      values: header.values,
      pageOf: pageOf,
      postings: postings,
      mask: new Uint8Array(header.pageCount),
    };
  }

  async function loadFacetIndex(basePath) {
    if (typeof fetch !== 'function') throw new Error('fetch unavailable');
    const url = basePath + 'scolta.facets';
    const resp = await fetch(url);
    if (!resp || !resp.ok) throw new Error('HTTP ' + (resp && resp.status));
    return parseFacetIndex(await gunzipToBytes(await resp.arrayBuffer()));
  }

  // The value list plus corpus-wide totals — byte for byte what
  // pagefind.filters() returned, since Pagefind reports posting-list lengths.
  function facetIndexTotals(index) {
    const totals = {};
    for (const dim of index.dimensions) {
      const map = {};
      for (const [value, total] of (index.values[dim] || [])) map[value] = total;
      totals[dim] = map;
    }
    return totals;
  }

  // Page indices of a result list, skipping ids the index does not know (an
  // index older than the artifact, or a merged secondary language index).
  function facetPageIndices(index, results) {
    const pages = [];
    for (const r of (results || [])) {
      const p = index.pageOf.get(r.id);
      if (p !== undefined) pages.push(p);
    }
    return pages;
  }

  function postingHasPage(posting, page) {
    if (posting.bitmap) return (posting.bitmap[page >> 3] & (1 << (page & 7))) !== 0;
    const pages = posting.pages;
    let lo = 0;
    let hi = pages.length - 1;
    while (lo <= hi) {
      const mid = (lo + hi) >> 1;
      const v = pages[mid];
      if (v === page) return true;
      if (v < page) lo = mid + 1; else hi = mid - 1;
    }
    return false;
  }

  // Per-value counts over the full matched set — the replacement for
  // search.filters. Every value is reported, including at zero, exactly as
  // Pagefind reported it.
  function facetCountsFor(index, results) {
    const pages = facetPageIndices(index, results);
    const mask = index.mask;
    mask.fill(0);
    for (let i = 0; i < pages.length; i++) mask[pages[i]] = 1;
    const counts = {};
    for (const dim of index.dimensions) {
      const dimCounts = {};
      for (const posting of index.postings[dim]) {
        let n = 0;
        if (posting.bitmap) {
          // Walk the smaller side: the matched set, testing the bitmap.
          const bitmap = posting.bitmap;
          for (let i = 0; i < pages.length; i++) {
            const p = pages[i];
            if (bitmap[p >> 3] & (1 << (p & 7))) n++;
          }
        } else {
          const list = posting.pages;
          for (let i = 0; i < list.length; i++) if (mask[list[i]]) n++;
        }
        dimCounts[posting.value] = n;
      }
      counts[dim] = dimCounts;
    }
    return counts;
  }

  // Apply the user's facet selection: OR within a dimension, AND across
  // dimensions — the same semantics Pagefind applies, just without handing it a
  // filter object and triggering a chunk load.
  function applyFacetFilters(index, results, filters) {
    if (!filters || typeof filters !== 'object') return results;
    const active = [];
    for (const [dim, vals] of Object.entries(filters)) {
      const selected = vals instanceof Set ? [...vals]
        : Array.isArray(vals) ? vals
          : (vals === undefined || vals === null || vals === '') ? [] : [vals];
      if (selected.length === 0) continue;
      // An unknown dimension matches nothing, which is what Pagefind does with
      // a filter it has no index for. A stale saved facet must not silently
      // widen the result set.
      const dimPostings = index.postings[dim] || [];
      const chosen = dimPostings.filter(p => selected.indexOf(p.value) !== -1);
      active.push(chosen);
    }
    if (active.length === 0) return results;
    return (results || []).filter(r => {
      const page = index.pageOf.get(r.id);
      // An id the artifact does not describe cannot be evaluated. The merge
      // guard and the index-hash check make that unreachable in practice; if it
      // happens anyway, keep the result. Admitting one result the facet should
      // have hidden is a smaller failure than silently dropping results the user
      // searched for.
      if (page === undefined) return true;
      for (const chosen of active) {
        let hit = false;
        for (const posting of chosen) {
          if (postingHasPage(posting, page)) { hit = true; break; }
        }
        if (!hit) return false;
      }
      return true;
    });
  }

  // Load the facet taxonomy. The artifact is the fast path; an index built
  // before it existed has none, and then Pagefind's own filters() is used so
  // facets keep working — slowly — rather than disappearing.
  async function loadFacetTaxonomy(pagefindPath) {
    try {
      // pagefind.mergeIndex() adds a second language index whose result ids the
      // artifact does not describe, and there is one artifact per built index.
      // Counting or filtering a merged corpus against it would be wrong in a way
      // the user would see, so the slow path is the correct path there.
      if (facetIndexMergedLanguages) {
        throw new Error('a secondary language index was merged in');
      }
      facetIndex = await loadFacetIndex(facetIndexBase(pagefindPath));
      if (facetIndexExpectedHash && facetIndex.indexHash
          && facetIndex.indexHash !== facetIndexExpectedHash) {
        throw new Error('artifact was built against index ' + facetIndex.indexHash
          + ' but the loaded index is ' + facetIndexExpectedHash + ' (stale cached artifact)');
      }
      cachedPagefindFilters = facetIndexTotals(facetIndex);
      debugLog('[scolta] Scolta facet index loaded:', facetIndex.dimensions.join(', '),
        '(' + facetIndex.pageCount + ' pages)');
      return;
    } catch (e) {
      facetIndex = null;
      console.warn(
        '[scolta] No Scolta facet index at ' + facetIndexBase(pagefindPath) + 'scolta.facets ('
        + (e && e.message ? e.message : e) + '). Falling back to pagefind.filters(), which loads '
        + 'every filter chunk and makes each subsequent search cost time proportional to matched '
        + 'results times filter postings. Rebuild the search index to emit the facet index.',
      );
    }
    try {
      cachedPagefindFilters = await pagefind.filters();
      debugLog('[scolta] Pagefind filters cached:', Object.keys(cachedPagefindFilters));
    } catch (e) {
      console.warn('[scolta] Failed to cache Pagefind filters:', e.message);
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
      // Re-entry against an instance a previous init() already created (a second
      // container on the page, or a re-mount). The taxonomy is module state, so
      // it is only loaded when it is not already in hand.
      if (!cachedPagefindFilters && !facetIndex) {
        await loadFacetTaxonomy(pagefindPath);
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
      // Record the total page count while the entry file is in hand — it is the
      // exact corpus size the sub-word frequency guard needs, and reading it
      // here avoids the match-all pagefind search that used to compute it (which
      // downloads the entire word index — see subwordCorpusSize()).
      const totalPages = Object.values(entry.languages || {})
        .reduce((sum, l) => sum + (l.page_count || 0), 0);
      if (totalPages > 0) {
        cachedPagefindPageCount = totalPages;
      }
      const primaryLang = (document.querySelector('html')?.getAttribute('lang') || 'en')
        .toLowerCase().split('-')[0];
      // The facet index is stamped with the pf_meta hash it was built against.
      // This entry file is cache-busted, so it is the trustworthy statement of
      // which index the browser is actually using.
      const primaryEntry = (entry.languages || {})[primaryLang]
        || Object.values(entry.languages || {})[0];
      facetIndexExpectedHash = (primaryEntry && primaryEntry.hash) || null;
      const absoluteBase = new URL(basePath, window.location.href).href;
      for (const lang of Object.keys(entry.languages || {})) {
        if (lang !== primaryLang) {
          await pagefind.mergeIndex(absoluteBase, { language: lang });
          facetIndexMergedLanguages = true;
        }
      }
    } catch (e) {
      console.warn('[scolta] Multilingual merge skipped:', e.message);
    }

    // Warm the index: triggers WASM compilation + fragment download.
    await pagefind.search("");

    await loadFacetTaxonomy(pagefindPath);

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
      if (data && data.degraded) {
        // Server AI call failed and the response is an unexpanded fallback
        // (HTTP 200 by design). Surface it so an AI outage is visible in the
        // console instead of masquerading as a normal empty expansion.
        console.warn("[scolta:expand] degraded response (server AI unavailable):", data.degraded_reason || 'unknown');
      }
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

  // ---- AI summary layout reservation ---------------------------------------
  //
  // The summarize call is deliberately deferred until query expansion settles,
  // so the model ranks what the user sees. That means the result list is
  // already painted when the summary lands above it, and every pixel the list
  // moves is cumulative layout shift.
  //
  // It moved twice, not once. The slot went from display:none to a loading
  // skeleton when expansion settled (measured +177px, 0.120), then from
  // skeleton to resolved summary (+342px, 0.317) — 0.437 total against a
  // "good" threshold of 0.1. The first of those is invisible in any harness
  // that stubs expansion faster than 500ms, because the browser then credits
  // it to the search click and excludes it; a real expansion is an LLM round
  // trip and is not that fast.
  //
  // So the slot takes a fixed height in the same frame the results paint, and
  // holds it through loading, resolution and error alike. A summary taller
  // than the box is clipped behind a "Show more" control; expanding is
  // user-initiated, which the metric excludes by definition, so the full text
  // stays reachable for free. A deployment with the summary off reserves
  // nothing and is byte-identical to before.
  const SUMMARY_RESERVED_CLASS = 'scolta-ai-summary--reserved';
  const SUMMARY_CLAMPED_CLASS = 'scolta-ai-summary--clamped';
  const SUMMARY_TEXT_ID = 'scolta-ai-summary-text';

  // Generous: the bars are clipped by the reserved height, so a theme that
  // raises the line budget still gets a full skeleton, and one that lowers it
  // pays nothing but a few unrendered divs.
  const SUMMARY_SHIMMER_LINES = 14;
  const SUMMARY_SHIMMER_WIDTHS = [95, 88, 72, 92, 84, 68, 90, 79];

  function summaryLabelHtml(withDots) {
    return `<div class="scolta-ai-summary-label">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2z"/></svg>
        <span>AI Overview</span>${withDots ? '\n        <span class="scolta-ai-dots"><span>.</span><span>.</span><span>.</span></span>' : ''}
      </div>`;
  }

  function summaryLoadingHtml() {
    let bars = '';
    for (let i = 0; i < SUMMARY_SHIMMER_LINES; i++) {
      bars += `<div class="scolta-ai-shimmer" style="width:${SUMMARY_SHIMMER_WIDTHS[i % SUMMARY_SHIMMER_WIDTHS.length]}%"></div>`;
    }
    return `${summaryLabelHtml(true)}
      <div class="scolta-ai-summary-text" id="${SUMMARY_TEXT_ID}">${bars}</div>`;
  }

  /**
   * Paint the reserved, loading slot.
   *
   * Called from the frame that paints the result list — the box has to exist
   * before anything can be pushed by it — and again by summarizeResults() so a
   * direct call still works. Idempotent: a slot already reserved, already
   * resolved, or already showing an error is left exactly as it is, which is
   * what keeps a load-more or facet repaint from flashing the skeleton back
   * over a summary the user is reading.
   */
  function reserveSummarySlot() {
    const summaryEl = els && els.aiSummary;
    if (!summaryEl) return;
    if (!getInstanceConfig().AI_SUMMARIZE) return;
    if (summaryEl.style.display !== 'none') return;
    // Cleared, not set: the stylesheet makes .scolta-ai-summary a flex column,
    // and an inline display would outrank it and break the reservation.
    summaryEl.style.display = '';
    summaryEl.className = `scolta-ai-summary loading ${SUMMARY_RESERVED_CLASS}`;
    summaryEl.innerHTML = summaryLoadingHtml();
  }

  /**
   * Collapse the slot: no reserved height, no skeleton, nothing.
   *
   * This is the state a deployment with the summary disabled is in for the
   * whole life of the page, and the state the slot returns to when the model
   * gives us nothing to show.
   */
  function releaseSummarySlot() {
    const summaryEl = els && els.aiSummary;
    if (!summaryEl) return;
    // The element it was watching is about to be emptied.
    disconnectSummaryClamp();
    summaryEl.style.display = 'none';
    summaryEl.className = '';
    summaryEl.innerHTML = '';
  }

  /**
   * Decide whether the resolved summary needs the "Show more" control.
   *
   * Measured, not estimated: the text is proportional, the box is themeable by
   * a custom property, and any guess at "how many characters fit" gets both of
   * those wrong. A summary that fits shows no control at all.
   */
  function updateSummaryClamp() {
    const summaryEl = els && els.aiSummary;
    if (!summaryEl) return;
    const textEl = summaryEl.querySelector('.scolta-ai-summary-text');
    const toggle = summaryEl.querySelector('[data-scolta-summary-toggle]');
    if (!textEl || !toggle) return;
    if (!summaryEl.classList.contains(SUMMARY_RESERVED_CLASS)) {
      summaryEl.classList.remove(SUMMARY_CLAMPED_CLASS);
      return;
    }
    // +1 absorbs sub-pixel rounding on fractional line heights.
    const overflows = textEl.scrollHeight > textEl.clientHeight + 1;
    if (overflows) {
      summaryEl.classList.add(SUMMARY_CLAMPED_CLASS);
    } else {
      summaryEl.classList.remove(SUMMARY_CLAMPED_CLASS);
    }
    toggle.hidden = !overflows;
  }

  /**
   * Keep the clamp decision honest as the text region's width changes.
   *
   * updateSummaryClamp() measures, so its answer is only true for the width it
   * measured at. It ran once on resolve and again on a toggle click, which
   * froze the decision at resolve-time width: rotate a phone to portrait, or
   * shrink a responsive column, and a summary that fitted reflows to more
   * lines and overflows the reserved height. The text is still clipped —
   * .scolta-ai-summary-text is overflow:hidden while reserved — but without
   * the clamped class there is no fade and the control stays hidden, so a
   * sighted reader sees text cut off at the box edge with no way to open it.
   * (The full text is in the DOM throughout, so find-in-page and assistive
   * tech were never affected; the visible affordance was.) Widening has the
   * mirror problem: a pointless control on a summary that now fits.
   *
   * Recomputing costs no layout shift. It toggles a mask class and the
   * control's hidden flag, and the control lives inside the fixed-height,
   * overflow-hidden panel, so nothing outside the box can move.
   */
  function observeSummaryClamp() {
    // Feature-detected: older engines and JSDOM have no ResizeObserver, and
    // the resolved path must not throw for want of it. Without one the
    // behaviour is exactly what it was before this existed.
    if (typeof ResizeObserver === 'undefined') return;
    const summaryEl = els && els.aiSummary;
    if (!summaryEl) return;
    if (!getInstanceConfig().AI_SUMMARIZE) return;
    const textEl = summaryEl.querySelector('.scolta-ai-summary-text');
    if (!textEl) return;
    disconnectSummaryClamp();
    summaryClampObserver = new ResizeObserver(() => updateSummaryClamp());
    summaryClampObserver.observe(textEl);
  }

  function disconnectSummaryClamp() {
    if (!summaryClampObserver) return;
    summaryClampObserver.disconnect();
    summaryClampObserver = null;
  }

  /**
   * Drop the reserved height so the whole summary (or a follow-up answer) is
   * visible. Always the result of a click or a keypress, so the resulting
   * shift carries hadRecentInput and costs nothing.
   */
  function expandSummarySlot() {
    const summaryEl = els && els.aiSummary;
    if (!summaryEl) return;
    // The user has opened the summary. Stop watching rather than re-clamping
    // against that choice: updateSummaryClamp() would no-op on an unreserved
    // panel anyway, but an observer left running on an expanded summary is
    // just work nobody asked for.
    disconnectSummaryClamp();
    summaryEl.classList.remove(SUMMARY_RESERVED_CLASS, SUMMARY_CLAMPED_CLASS);
    const toggle = summaryEl.querySelector('[data-scolta-summary-toggle]');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'true');
      toggle.textContent = 'Show less';
    }
  }

  function collapseSummarySlot() {
    const summaryEl = els && els.aiSummary;
    if (!summaryEl) return;
    summaryEl.classList.add(SUMMARY_RESERVED_CLASS);
    const toggle = summaryEl.querySelector('[data-scolta-summary-toggle]');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
      toggle.textContent = 'Show more';
    }
    updateSummaryClamp();
    // Reserved again, so width changes matter again.
    observeSummaryClamp();
  }

  function toggleSummaryExpanded() {
    const summaryEl = els && els.aiSummary;
    if (!summaryEl) return;
    if (summaryEl.classList.contains(SUMMARY_RESERVED_CLASS)) {
      expandSummarySlot();
    } else {
      collapseSummarySlot();
    }
  }

  async function summarizeResults(query, results, expandedTerms = [], sortHint = null, filterHint = null, userFilters = {}) {
    const CONFIG = getInstanceConfig();
    const endpoints = getInstanceEndpoints();
    if (!CONFIG.AI_SUMMARIZE || results.length === 0) return null;
    const summaryEl = els.aiSummary;
    // Normally a no-op: the slot was already reserved in the frame the results
    // painted. It still matters for a direct call, and for the case where the
    // result list arrived empty and expansion later filled it.
    reserveSummarySlot();

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
    // Weak-match signal, graded by specificity. When the result set was
    // assembled by the broadened OR fallback the model needs to know it is a
    // fallback, or it generalizes a thin slice into a claim about the whole
    // collection ("this collection has no dedicated article on X") — a claim it
    // can never support from one search. But that decline is only warranted when
    // NO high-specificity term matched anything: a retrieval miss where a rare
    // on-intent term ("papilledema", "oxygen tank") did match is not a content
    // gap, and telling the model the excerpts are "not representative" makes it
    // wrongly hedge over documents that are exactly on point. So:
    //   - no specific match  -> strong marker, decline is appropriate
    //   - specific match hit  -> soft marker: the literal phrase missed, but the
    //     excerpts are on-intent; attribute any gap to the search, not the corpus
    if (usedOrFallback && !hadSpecificMatch) {
      contextHeader += '[No result matched the full query; these excerpts come from a broadened partial-match search and are not representative of the collection]\n';
    } else if (usedOrFallback) {
      contextHeader += '[The full query phrase did not match as-is, but specific on-topic terms did; these excerpts are relevant. Attribute any missing detail to this search, not to the collection.]\n';
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
        // Stays reserved: the resolved summary replaces the skeleton inside
        // the same box, so this swap moves nothing.
        summaryEl.className = `scolta-ai-summary ${SUMMARY_RESERVED_CLASS}`;
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

        // The full text is always in the DOM, clipped by the box rather than
        // truncated, so find-in-page and assistive tech reach all of it in
        // either state.
        summaryEl.innerHTML = `
          ${summaryLabelHtml(false)}
          <div class="scolta-ai-summary-text" id="${SUMMARY_TEXT_ID}">${formatted}</div>
          <button type="button" class="scolta-ai-summary-toggle" data-scolta-summary-toggle
                  aria-expanded="false" aria-controls="${SUMMARY_TEXT_ID}" hidden>Show more</button>
          <div id="scolta-followup-thread" class="scolta-ai-followup-thread" style="display:none;"></div>
          <div class="scolta-ai-followup-input" id="scolta-followup-input">
            <input type="text" id="scolta-followup-field" placeholder="Ask a follow-up question..."
                   data-scolta-followup-input>
            <button id="scolta-followup-btn" data-scolta-followup-submit>Ask</button>
            <span class="scolta-ai-followup-counter" id="scolta-followup-counter">${CONFIG.AI_MAX_FOLLOWUPS} remaining</span>
          </div>
          ${disclaimerHtml}`;
        updateSummaryClamp();
        // The decision above is only true for the width it measured at.
        observeSummaryClamp();
      } else {
        // Nothing to show. Collapse to exactly what a deployment with the
        // summary disabled looks like rather than leaving an empty box.
        releaseSummarySlot();
      }
    } catch (e) {
      if (e.name === 'AbortError') return;
      if (e instanceof TypeError) {
        releaseSummarySlot();
        return;
      }
      console.warn("[scolta:summarize] failed:", e);
      // Sized within the reserved height rather than collapsed: collapsing is
      // an upward shift, and it counts against the metric exactly as the
      // downward one did.
      summaryEl.className = `scolta-ai-summary error ${SUMMARY_RESERVED_CLASS}`;
      summaryEl.innerHTML = `${summaryLabelHtml(false)}
        <div class="scolta-ai-summary-text" id="${SUMMARY_TEXT_ID}">Summary unavailable. Results shown below.</div>`;
    }
  }

  function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  // Escape a value for interpolation into an HTML attribute. escapeHtml (a
  // textContent → innerHTML round-trip) does not escape quotes, so a value
  // containing `"` could break out of the attribute and inject new ones.
  // Use this for every `attr="${...}"` interpolation; escapeHtml stays for
  // text nodes.
  function escapeAttr(text) {
    return String(text)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  // Whether a URL is safe to emit as an href: absolute http(s) or relative
  // (scheme-less). Anything with another scheme (javascript:, data:, …) is
  // not. Control characters and whitespace are stripped before scheme
  // detection because browsers ignore them when parsing a scheme.
  // Mirrors PHP MarkdownRenderer::isSafeLinkUrl().
  function isSafeLinkUrl(url) {
    const cleaned = String(url).replace(/[\u0000-\u0020\u007f]+/g, "");
    if (/^https?:\/\//i.test(cleaned)) return true;
    return !/^[a-z][a-z0-9+.\-]*:/i.test(cleaned);
  }

  // Attribute-escape a URL for use in href; unsafe schemes become inert "#".
  function sanitizeUrlAttr(url) {
    return isSafeLinkUrl(url) ? escapeAttr(url) : "#";
  }

  // Prepare a raw query for display inside the results header, which wraps the
  // query in its own pair of double-quotes. A quoted-phrase search ("merge
  // conflict") already carries surrounding quotes, so without trimming a single
  // matched pair the header would render the doubled ""merge conflict"".
  // escapeHtml does not escape `"`, so the only quote level shown is the
  // template's — strip at most one surrounding pair here so it isn't doubled.
  function displayQuery(query) {
    const q = query || "";
    if (q.length >= 2 && q.startsWith('"') && q.endsWith('"')) {
      return q.slice(1, -1);
    }
    return q;
  }

  function stripHtml(text) {
    // DOMParser produces an inert document: scripts never run and resources
    // (e.g. <img onerror>) never load, unlike innerHTML on a live detached
    // div, whose subtree shares the page's document and loads eagerly.
    const doc = new DOMParser().parseFromString(String(text ?? ""), "text/html");
    return doc.body.textContent || "";
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
    // Collapse results that resolve to the same destination URL before numbering.
    // Two results sharing a URL would otherwise each get their own [n] block, so
    // the model is handed duplicate sources and cites the same href repeatedly in
    // its summary. Deduping here (the root layer) keeps the first — highest
    // ranked — occurrence of each URL; results with no URL are never collapsed.
    const seenUrls = new Set();
    const unique = [];
    for (const r of results) {
      const _u = r.data.meta?.url || resolveUrl(r.data.url || "");
      const url = _u.startsWith("/") ? window.location.origin + _u : _u;
      if (url && seenUrls.has(url)) continue;
      if (url) seenUrls.add(url);
      unique.push({ r, url });
    }
    return unique.map(({ r, url }, i) => {
      const title = r.data.meta?.title || "Untitled";
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
  // Superset of PHP MarkdownRenderer::cleanBrokenLinks(): both repair a
  // truncated [text](url link; this side also salvages a bare trailing
  // "[label" and closes unbalanced bold/italic/backtick markers. The shared
  // rendering contract between the two renderers is pinned by the fixtures
  // in tests/fixtures/render-parity/ (asserted by Jest and PHPUnit).
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
        // Scheme gate (mirrors PHP MarkdownRenderer): only http(s) and
        // relative URLs may become links, even when no domain allowlist is
        // configured. escapeAttr on the href: the summary was escaped with
        // escapeHtml, which leaves `"` intact — unescaped it could break out
        // of the href attribute.
        if (!isSafeLinkUrl(url)) {
          return linkText;
        }
        if (allowedDomains.length === 0) {
          return `<a href="${escapeAttr(url)}" target="_blank" rel="noopener">${linkText}</a>`;
        }
        try {
          const parsed = new URL(url);
          const host = parsed.hostname.replace(/^www\./, '');
          if (allowedDomains.some(d => host === d || host.endsWith('.' + d))) {
            return `<a href="${escapeAttr(url)}" target="_blank" rel="noopener">${linkText}</a>`;
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

    // The thread grows inside the summary panel, and the panel is clipped to
    // its reserved height until something releases it — an answer appended
    // under a clamp would be invisible. Asking a follow-up is a click or an
    // Enter key, so releasing it here costs no layout shift.
    expandSummarySlot();

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
        .map(t => `<span class="scolta-expanded-term" data-scolta-search-term="${escapeAttr(t)}">${escapeHtml(t)}</span>`)
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
    if (Object.keys(llmAppliedFilters).length === 0 && Object.keys(offeredLlmFilters).length === 0) {
      el.style.display = 'none';
      el.innerHTML = '';
      return;
    }
    el.style.display = 'block';
    let html = '';
    for (const [dim, val] of Object.entries(llmAppliedFilters)) {
      html += '<span class="scolta-filter-badge">Filtered: ' + escapeHtml(dim) + ' = ' + escapeHtml(val) +
        ' <button class="scolta-filter-dismiss" data-scolta-filter-dismiss="' + escapeAttr(dim) +
        '" aria-label="Remove filter">×</button></span> ';
    }
    // Hints the recall guard declined: suggested, not applied. Clicking
    // applies the filter explicitly — the user opted in, so no guard.
    for (const [dim, val] of Object.entries(offeredLlmFilters)) {
      html += '<span class="scolta-filter-badge scolta-filter-offer">' +
        '<button class="scolta-filter-apply" data-scolta-filter-offer-dim="' + escapeAttr(dim) +
        '" data-scolta-filter-offer-val="' + escapeAttr(val) +
        '">Filter by ' + escapeHtml(dim) + ': ' + escapeHtml(val) + '</button></span> ';
    }
    el.innerHTML = html;
  }

  function applyOfferedLlmFilter(dim, val) {
    if (offeredLlmFilters[dim] !== val) return;
    delete offeredLlmFilters[dim];
    llmAppliedFilters[dim] = val;
    if (!activeFilters[dim]) {
      activeFilters[dim] = new Set();
    }
    activeFilters[dim].add(val);
    renderFilterBadges();
    doSearch(true);
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

  // Stable key for a resolved Pagefind search: the query plus the options that
  // actually go to pagefind.search(). Keyed on the RESOLVED options, not the
  // caller's `filters` argument, so two callers that express the same scope in
  // different shapes (a one-value Set vs. the scalar it resolves to) share a
  // key — and, just as importantly, two callers whose scope genuinely DIFFERS
  // never do. Dimension keys and value arrays are sorted so key equality is
  // insertion-order independent; the sort hint is part of the key because a
  // sorted search returns a different result order.
  function searchMemoKey(query, searchOpts) {
    const filters = searchOpts.filters || null;
    const filterKey = filters
      ? Object.keys(filters).sort().map(dim => {
          const v = filters[dim];
          const vals = (v && typeof v === 'object' && Array.isArray(v.any)) ? [...v.any] : [v];
          return dim + '=' + vals.map(String).sort().join(',');
        }).join('&')
      : '';
    const sort = searchOpts.sort
      ? Object.keys(searchOpts.sort).sort().map(f => f + ':' + searchOpts.sort[f]).join(',')
      : '';
    return JSON.stringify([query, filterKey, sort]);
  }

  // Run a Pagefind search, memoized for the duration of one search cycle.
  //
  // Why memoize: on a production-size index, once pagefind.filters() has loaded
  // the filter chunks every pagefind.search() also computes per-value counts
  // across every distinct filter value, which costs roughly 1.45 ms per matched
  // result (measured: 7,789 results -> 115 ms before filters() had run, 11,255 ms
  // after). Scolta issues the same search twice per cycle in the common case:
  // computeQueryFacetCounts() searches the typed query under structural-only
  // filters, which is byte-identical to the primary search whenever the user has
  // applied no non-structural facet, and on the OR-fallback path
  // computeUnionFacetCounts() repeats every per-term search the result path just
  // ran. Within a single cycle the index is immutable, so an identical search
  // must return identical results and sharing one is free.
  //
  // The memo holds the PROMISE, not the awaited value, so two concurrent callers
  // with the same key share one in-flight search instead of racing. It is cleared
  // at the top of every doSearch() cycle, so no result is ever reused across
  // cycles. A search whose resolved filters differ still misses and runs: that is
  // the correctness boundary, because structuralFilters drops the user's facet
  // selections and collapsing it into the primary search would silently change
  // the facet counts.
  // When the facet index is present NOTHING is ever put in searchOpts.filters:
  // naming a dimension there makes Pagefind's browser client lazily fetch that
  // dimension's chunk before running the search, and from then on get_filters
  // iterates it on every subsequent search for the life of the page. So the
  // first facet click would reintroduce the whole cost the artifact exists to
  // remove. Scolta applies the selection itself instead, against the artifact,
  // before the result list is sliced and fragments are loaded.
  //
  // A useful side effect: with filters out of the search options, the same query
  // under different facet selections is ONE Pagefind search, shared through the
  // memo, and only the cheap post-filter differs.
  async function pagefindSearch(query, filters, sortHint) {
    const searchOpts = {};
    if (!facetIndex && filters && typeof filters === 'object') {
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
    const key = searchMemoKey(query, searchOpts);
    let search = searchMemo.get(key);
    if (!search) {
      search = pagefind.search(query, searchOpts);
      searchMemo.set(key, search);
    }
    if (!facetIndex) return search;

    const raw = await search;
    const index = facetIndex;
    const results = applyFacetFilters(index, raw.results, filters);
    let counts = null;
    const out = Object.assign({}, raw, { results: results });
    // Defined separately, and deliberately NOT passed through Object.assign:
    // Object.assign copies an accessor property by READING it, so a getter in
    // one of its sources fires immediately and lands as a plain value. That
    // would run the count over the full matched set on every single search,
    // including the warm-up and preload ones that never look at it.
    Object.defineProperty(out, 'filters', {
      enumerable: true,
      configurable: true,
      get() {
        if (counts === null) counts = facetCountsFor(index, results);
        return counts;
      },
    });
    return out;
  }

  // Warm the alphabetical index chunk(s) for the term being typed, so the
  // search that fires on Enter/click finds them already in memory.
  // pagefind.preload() runs the chunk resolution half of a search and bails
  // out before scoring; loaded chunks are memoized, so repeat calls are free.
  //
  // Deliberately NOT pagefind.debouncedSearch(): that is a plain input
  // debounce, and adopting it would bypass the abortController +
  // searchVersion staleness guards that protect the multi-phase pipeline
  // (expand → merge → summarize → follow-up).
  //
  // No filters are passed, and that is load-bearing rather than an optimization:
  // naming a dimension in a search's filter object makes Pagefind fetch that
  // dimension's filter chunk, and once a chunk is loaded every later search pays
  // a per-matched-result counting cost that nothing can unload. Scolta applies
  // facets itself against the facet index, so a warm-up never needs them.
  function schedulePreload(raw) {
    if (preloadTimer) {
      clearTimeout(preloadTimer);
      preloadTimer = null;
    }
    const term = (raw || '').trim();
    // One stray character would fetch a chunk the user may never search.
    if (term.length < PRELOAD_MIN_CHARS || term === lastPreloadedTerm) return;
    preloadTimer = setTimeout(() => {
      preloadTimer = null;
      // Feature-detected: index builds from older Pagefind releases predate
      // preload(), and a missing warm-up must never break the search box.
      if (!pagefind || typeof pagefind.preload !== 'function') return;
      lastPreloadedTerm = term;
      try {
        Promise.resolve(pagefind.preload(term)).catch((e) => {
          debugLog('[scolta] preload failed', e);
        });
      } catch (e) {
        debugLog('[scolta] preload failed', e);
      }
    }, PRELOAD_DEBOUNCE_MS);
  }

  // Cancel any pending preload — used by clearSearch(), which empties the
  // input without firing an "input" event.
  function cancelPreload() {
    if (preloadTimer) {
      clearTimeout(preloadTimer);
      preloadTimer = null;
    }
    lastPreloadedTerm = '';
  }

  // ==========================================================================
  // SEARCH AS YOU TYPE — suggest cycle, dropdown, recent searches, enrichment
  // ==========================================================================

  // --- Recent searches (localStorage) ---
  //
  // Every access is wrapped: Safari private browsing throws on setItem, some
  // enterprise policies throw on getItem, and a storage failure must never take
  // the search box with it. Stored values are user-typed strings and are treated
  // as untrusted on render, exactly like index metadata.

  function readRecentSearches() {
    if (!getSaytConfig().recentSearches) return [];
    try {
      const store = global.localStorage;
      if (!store) return [];
      const raw = store.getItem(SAYT_RECENT_KEY);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      if (!Array.isArray(parsed)) return [];
      return parsed
        .filter(v => typeof v === 'string' && v.trim() !== '')
        .slice(0, SAYT_RECENT_STORED_MAX);
    } catch (e) {
      debugLog('[scolta:sayt] recent-search read failed', e);
      return [];
    }
  }

  // Record a COMMITTED query. Called from doSearch(), never from the suggest
  // path: a prefix the user typed on the way to a real query is not a search.
  function recordRecentSearch(query) {
    const cfg = getSaytConfig();
    if (!cfg.enabled || !cfg.recentSearches) return;
    const q = String(query || '').trim();
    if (!q) return;
    try {
      const store = global.localStorage;
      if (!store) return;
      const lower = q.toLowerCase();
      const next = [q].concat(
        readRecentSearches().filter(v => v.toLowerCase() !== lower)
      ).slice(0, SAYT_RECENT_STORED_MAX);
      store.setItem(SAYT_RECENT_KEY, JSON.stringify(next));
    } catch (e) {
      debugLog('[scolta:sayt] recent-search write failed', e);
    }
  }

  // Recent searches that relate to what is being typed. Prefix matches come
  // first because they are what the user is most likely completing; substring
  // matches follow. The typed term itself is never offered back.
  function matchingRecentSearches(term, cfg) {
    if (!cfg.recentSearches || cfg.maxRecent <= 0) return [];
    const lower = term.toLowerCase();
    const prefix = [];
    const substring = [];
    for (const value of readRecentSearches()) {
      const v = value.toLowerCase();
      if (v === lower) continue;
      if (v.startsWith(lower)) prefix.push(value);
      else if (v.indexOf(lower) !== -1) substring.push(value);
    }
    return prefix.concat(substring).slice(0, cfg.maxRecent).map(value => ({
      type: 'recent',
      title: value,
      url: '',
      // Nothing to navigate to: acting on a recent search runs the search in
      // place. The field is present, and empty, so every suggestion has one
      // shape and a consumer never has to feature-test safeUrl or meta.
      safeUrl: '',
      excerpt: '',
      meta: {},
    }));
  }

  // --- Suggest cycle ---

  // Invalidate any pending or in-flight suggest work. Bumping the version is
  // the whole cancellation mechanism: every await in the cycle re-checks it, so
  // a search already in flight simply throws its results away on return.
  function cancelSuggest() {
    if (suggestTimer) {
      clearTimeout(suggestTimer);
      suggestTimer = null;
    }
    if (suggestExpandTimer) {
      clearTimeout(suggestExpandTimer);
      suggestExpandTimer = null;
    }
    if (suggestBlurTimer) {
      clearTimeout(suggestBlurTimer);
      suggestBlurTimer = null;
    }
    suggestVersion++;
  }

  // Extend the typing path. schedulePreload() still runs alongside this and is
  // untouched: it warms index chunks, this produces suggestions, and the two
  // debounces are independent by design (a site may want suggestions slower or
  // faster than chunk warm-up).
  function scheduleSuggest(raw) {
    const cfg = getSaytConfig();
    if (!cfg.enabled || !els.sayt) return;

    cancelSuggest();

    const term = (raw || '').trim();
    if (saytGraphemeLength(term) < cfg.minChars) {
      // Below the floor is a close, not a no-op: the user backspaced out of a
      // query that had a dropdown open.
      closeSuggestions();
      return;
    }

    suggestTimer = setTimeout(() => {
      suggestTimer = null;
      runSuggestCycle(term);
    }, cfg.debounceMs);
  }

  // Run one suggest cycle for `term`.
  //
  // Never uses pagefindSearch(): that memo belongs to the doSearch() cycle and
  // applies the user's facet selections. Suggestions are query completion, not
  // a filtered result list, so they run against the whole index — and the
  // options object is ALWAYS `{}`. Naming a dimension in a search's filters
  // makes Pagefind fetch that dimension's filter chunk, and on an index without
  // the scolta.facets artifact a loaded chunk taxes every later search with a
  // per-matched-result scan that nothing can unload short of destroy(). A
  // keystroke-rate path must never be what triggers that.
  async function runSuggestCycle(term) {
    const cfg = getSaytConfig();
    if (!cfg.enabled || !els.sayt || !pagefind || typeof pagefind.search !== 'function') return;
    // The user has committed and the results region is mid-paint; stand down.
    if (paintingVersion !== 0) return;

    const version = ++suggestVersion;

    let rows = [];
    let usedOr = false;
    try {
      const search = await pagefind.search(term, {});
      if (version !== suggestVersion) return;
      rows = (search && search.results) || [];

      // Pagefind ANDs every word, so a multi-word prefix ("chocolate br") often
      // matches nothing until the last word is complete. Same shape as the
      // result path's OR fallback: search each word, union by fragment id, and
      // score the union at the reduced weight the fallback uses.
      const words = term.split(/\s+/).filter(w => w.length > 0);
      if (rows.length === 0 && words.length > 1) {
        const perTerm = await Promise.all(words.map(
          w => Promise.resolve(pagefind.search(w, {})).catch(() => ({ results: [] }))
        ));
        if (version !== suggestVersion) return;
        const seenIds = new Set();
        rows = [];
        for (const s of perTerm) {
          for (const r of ((s && s.results) || [])) {
            if (seenIds.has(r.id)) continue;
            seenIds.add(r.id);
            rows.push(r);
          }
        }
        usedOr = rows.length > 0;
      }
    } catch (e) {
      // A genuine search failure is not the same as "no matches", and leaving
      // the previous prefix's suggestions on screen would claim it is. Take the
      // dropdown down instead — but only if this cycle is still the current one.
      debugLog('[scolta:sayt] suggest search failed', e);
      if (version === suggestVersion) closeSuggestions();
      return;
    }

    const merged = await buildSuggestions(term, rows, usedOr, version, cfg);
    if (merged === null) return;   // superseded mid-load

    renderSuggestions(merged, term);

    // Enrichment is scheduled from here rather than from the input handler so
    // it measures idle time from the settled prefix, not from the last
    // keystroke of a query the user is still extending.
    scheduleSuggestExpansion(term, version, cfg);
  }

  // Load fragments for at most maxSuggestions rows, score them through the same
  // path the result list uses, dedupe by title, and merge recent searches ahead
  // of them. Returns null if the cycle was superseded while loading.
  async function buildSuggestions(term, rows, usedOr, version, cfg) {
    const cap = cfg.maxSuggestions;
    let titleSuggestions = [];

    if (rows.length > 0) {
      // The hard cap on fragment loads for this pass. Each .data() is a network
      // fetch on a cold cache, and this runs at typing speed.
      const slice = rows.slice(0, cap);
      // allSettled, not all: one fragment that fails to fetch must cost the
      // user that one suggestion, not the whole dropdown.
      const settled = await Promise.allSettled(slice.map(r => r.data()));
      if (version !== suggestVersion) return null;
      const loaded = [];
      for (const outcome of settled) {
        if (outcome.status === 'fulfilled') loaded.push(outcome.value);
        else debugLog('[scolta:sayt] fragment load failed', outcome.reason);
      }

      // Every fragment failed. Fall through rather than returning: recent
      // searches do not depend on the index and are still worth showing.
      if (loaded.length > 0) {
        const scored = scoreResults(loaded, term, usedOr ? 0.6 : 1.0);
        scored.sort((a, b) => b.score - a.score);
        titleSuggestions = deduplicateByTitle(scored)
          .map(toTitleSuggestion)
          .filter(s => s.title !== '');
      }
    }

    return mergeSuggestions(matchingRecentSearches(term, cfg), titleSuggestions, cap);
  }

  // A scored result becomes a suggestion. `url` stays raw for the safety gate
  // and `safeUrl` is the same attribute-escaped, scheme-neutralized value the
  // result card puts in its href — one sanitizer, one behaviour.
  //
  // `meta` is the fragment's whole metadata map, carried through unchanged so a
  // suggestion renderer or a scolta:suggestions-rendered listener can reach a
  // thumbnail, an entity id or any other indexed display key. It is the same
  // surface the result seam exposes as `data.meta`, and the values are RAW
  // index content: nothing here escapes them, because escaping at the source
  // would corrupt a value a consumer wants for a request URL or a comparison.
  // A consumer that puts a meta value into markup escapes it itself.
  function toTitleSuggestion(scored) {
    const data = scored.data || {};
    const rawUrl = data.meta?.url || resolveUrl(data.url || '') || data.url || '';
    return {
      type: 'title',
      title: String(data.meta?.title || '').trim(),
      url: rawUrl,
      safeUrl: sanitizeUrlAttr(rawUrl),
      excerpt: data.excerpt || '',
      meta: data.meta || {},
    };
  }

  // Recent searches first (they are what the user already wanted), then title
  // suggestions, deduped case-insensitively by title across both groups, total
  // capped at `cap`.
  function mergeSuggestions(recents, titles, cap) {
    const out = [];
    const seen = new Set();
    for (const list of [recents, titles]) {
      for (const s of list) {
        if (out.length >= cap) break;
        const key = s.title.toLowerCase();
        if (seen.has(key)) continue;
        seen.add(key);
        out.push(s);
      }
    }
    return out;
  }

  // --- Dropdown rendering ---

  function suggestOptionId(index) {
    return 'scolta-sayt-option-' + index;
  }

  // Render lifecycle events for the suggest path, matching the result-render
  // seam exactly: dispatched on the element being written, bubbling,
  // non-cancellable, listener exceptions caught and logged. Documented in
  // docs/RENDER_SEAM.md.
  function emitBeforeSuggestions(query) {
    emitLifecycle(els.sayt, 'scolta:before-suggestions-render', {
      container: els.sayt,
      query: query,
    });
  }

  function emitSuggestionsRendered(list, query) {
    emitLifecycle(els.sayt, 'scolta:suggestions-rendered', {
      container: els.sayt,
      suggestions: list,
      query: query,
    });
  }

  // The built-in inner content of one suggestion row — the kind glyph, the
  // title, and the excerpt when there is one. Unchanged from before the
  // suggestion-renderer seam existed: it is what a consumer that registers
  // nothing sees, and what the renderer path falls back to.
  function buildDefaultSuggestionInner(parts, isRecent) {
    return `<span class="scolta-sayt-kind" aria-hidden="true">${isRecent ? '&#8635;' : '&#8250;'}</span>`
      + `<span class="scolta-sayt-title">${parts.titleHtml}</span>`
      + (parts.excerptHtml ? `<span class="scolta-sayt-excerpt">${parts.excerptHtml}</span>` : '');
  }

  // Build the INNER markup of one suggestion row: the registered platform
  // renderer if there is one, the built-in row otherwise. Inner and not the
  // whole row on purpose — the option element itself carries role="option", the
  // stable id the input's aria-activedescendant points at, aria-selected, the
  // data-scolta-sayt-index the keyboard and click handlers dispatch on, and in
  // navigate mode the sanitized href. Those are the combobox contract, so
  // renderSuggestions keeps them and a renderer cannot break them by omission.
  //
  // A renderer that returns anything other than a string — null, undefined, a
  // mistake — falls back to the built-in row for THAT suggestion only, matching
  // the result seam, so a platform able to decorate some suggestion types and
  // not others does not have to decorate any of them.
  function buildSuggestionInnerHtml(s, index, query, isRecent, renderer) {
    const parts = {
      index: index,
      // The prefix being suggested on, RAW and not html-escaped: it is here so
      // a renderer can build a request or compare terms, not to be pasted into
      // markup. Every value below whose name ends in Html, plus safeUrl, is
      // already escaped exactly as the built-in row escapes it — the same
      // division the result renderer's ctx draws.
      query: query,
      titleHtml: escapeHtml(s.title),
      excerptHtml: (!isRecent && s.excerpt) ? truncateExcerpt(s.excerpt, 120) : '',
      // The same attribute-escaped, scheme-neutralized value the option's href
      // carries in navigate mode. A recent search has no destination — acting
      // on one runs the search in place rather than navigating — so it gets ''
      // rather than a URL invented here that nothing else in the bundle emits.
      safeUrl: s.safeUrl || '',
    };

    if (renderer) {
      let out = null;
      try {
        out = renderer(s, parts);
      } catch (e) {
        console.warn('[scolta] suggestion renderer threw; falling back to the built-in row', e);
        out = null;
      }
      if (typeof out === 'string') return out;
    }
    return buildDefaultSuggestionInner(parts, isRecent);
  }

  function renderSuggestions(list, query) {
    if (!els.sayt) return;

    if (list.length === 0) {
      emitBeforeSuggestions(query);
      suggestions = [];
      activeSuggestion = -1;
      suggestQuery = query;
      els.sayt.innerHTML = '';
      setSuggestOpen(false);
      emitSuggestionsRendered([], query);
      return;
    }

    emitBeforeSuggestions(query);

    const navigates = getSaytConfig().suggestionAction === 'navigate';
    const renderer = activeSuggestionRenderer();
    let html = '';
    for (let i = 0; i < list.length; i++) {
      const s = list[i];
      const isRecent = s.type === 'recent';
      const cls = 'scolta-sayt-option' + (isRecent ? ' scolta-sayt-option-recent' : '');
      // In `navigate` mode a title suggestion is a real anchor, so middle-click,
      // ctrl-click and the browser's own link affordances all work and the href
      // is the card's sanitized URL rather than something JS assembles at click
      // time. Recent searches are never links: navigating to a stored query
      // string is meaningless, so they always run the search.
      const asLink = !isRecent && navigates && isSafeLinkUrl(s.url);
      const tag = asLink ? 'a' : 'div';
      const href = asLink ? ` href="${s.safeUrl}"` : '';
      html += `<${tag} class="${cls}" role="option" id="${suggestOptionId(i)}"`
        + ` aria-selected="false" data-scolta-sayt-index="${i}"${href}>`
        + buildSuggestionInnerHtml(s, i, query, isRecent, renderer)
        + `</${tag}>`;
    }
    els.sayt.innerHTML = html;

    suggestions = list;
    suggestQuery = query;
    activeSuggestion = -1;
    els.queryInput.removeAttribute('aria-activedescendant');
    setSuggestOpen(true);

    emitSuggestionsRendered(list, query);
  }

  function setSuggestOpen(open) {
    if (!els.sayt) return;
    suggestOpen = open;
    els.sayt.style.display = open ? 'block' : 'none';
    els.queryInput.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) els.queryInput.removeAttribute('aria-activedescendant');
  }

  function closeSuggestions() {
    if (!els.sayt) return;
    if (suggestBlurTimer) {
      clearTimeout(suggestBlurTimer);
      suggestBlurTimer = null;
    }
    suggestions = [];
    activeSuggestion = -1;
    suggestQuery = '';
    els.sayt.innerHTML = '';
    setSuggestOpen(false);
  }

  // Move the active option. DOM focus never leaves the input — the combobox
  // pattern tracks the active option through aria-activedescendant, so screen
  // readers announce it while typing keeps working.
  function setActiveSuggestion(index) {
    if (!els.sayt) return;
    const options = els.sayt.querySelectorAll('[role="option"]');
    if (options.length === 0) return;
    activeSuggestion = index;
    for (let i = 0; i < options.length; i++) {
      const on = i === index;
      options[i].setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) options[i].classList.add('scolta-sayt-option-active');
      else options[i].classList.remove('scolta-sayt-option-active');
    }
    if (index >= 0 && options[index]) {
      els.queryInput.setAttribute('aria-activedescendant', options[index].id);
    } else {
      els.queryInput.removeAttribute('aria-activedescendant');
    }
  }

  // --- Acting on a suggestion ---

  function actOnSuggestion(index) {
    const s = suggestions[index];
    if (!s) return;
    const cfg = getSaytConfig();
    const runsSearch = s.type === 'recent' || cfg.suggestionAction !== 'navigate';

    if (runsSearch) {
      els.queryInput.value = s.title;
      els.searchClear.style.display = 'block';
      closeSuggestions();
      cancelSuggest();
      doSearch();
      return;
    }

    // navigate: follow the option's own anchor, whose href is the card's
    // sanitized URL. A title suggestion whose URL failed isSafeLinkUrl() was
    // never rendered as a link, so there is nothing to follow and the dropdown
    // simply closes.
    // Follow the link while it is still in the document — a detached anchor's
    // activation behaviour is not something to rely on — then tear down.
    const el = els.sayt.querySelector('[data-scolta-sayt-index="' + index + '"]');
    if (el && typeof el.click === 'function' && el.hasAttribute('href')) el.click();
    closeSuggestions();
    cancelSuggest();
  }

  // --- Keyboard ---
  //
  // Returns true when the event was consumed, so the caller leaves the existing
  // Enter-runs-doSearch handler untouched for every case SAYT does not claim.
  function handleSuggestKeydown(e) {
    if (!getSaytConfig().enabled || !els.sayt) return false;

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!suggestOpen || suggestions.length === 0) return false;
      e.preventDefault();
      const n = suggestions.length;
      const delta = e.key === 'ArrowDown' ? 1 : -1;
      const next = activeSuggestion < 0
        ? (delta > 0 ? 0 : n - 1)
        : (activeSuggestion + delta + n) % n;   // wraps at both ends
      setActiveSuggestion(next);
      return true;
    }

    if (e.key === 'Escape') {
      // Close without clearing the input. A second Escape falls through to
      // whatever the host page does with it.
      if (!suggestOpen) return false;
      e.preventDefault();
      closeSuggestions();
      cancelSuggest();
      return true;
    }

    if (e.key === 'Enter') {
      // Enter with NO active option runs doSearch() exactly as it always has.
      if (!suggestOpen || activeSuggestion < 0) return false;
      e.preventDefault();
      actOnSuggestion(activeSuggestion);
      return true;
    }

    return false;
  }

  // --- AI enrichment ---

  // Sliding-window budget. SAYT expansions share the platform's AI flood bucket
  // with committed searches (expand + summarize + follow-up all count against
  // the same per-IP limit), so an unbudgeted suggest path would spend a
  // visitor's whole allowance on prefixes and starve the search they actually
  // ran. Over budget, the dropdown silently stays keyword-only.
  function saytExpandBudgetAllows(cfg) {
    if (cfg.expandPerMinute <= 0) return false;
    const cutoff = Date.now() - SAYT_EXPAND_WINDOW_MS;
    saytExpandCalls = saytExpandCalls.filter(t => t > cutoff);
    return saytExpandCalls.length < cfg.expandPerMinute;
  }

  function scheduleSuggestExpansion(term, version, cfg) {
    if (!cfg.expand || !getInstanceConfig().AI_EXPAND_QUERY) return;
    if (suggestExpandTimer) {
      clearTimeout(suggestExpandTimer);
      suggestExpandTimer = null;
    }
    suggestExpandTimer = setTimeout(() => {
      suggestExpandTimer = null;
      enrichSuggestions(term, version, cfg);
    }, cfg.expansionDelayMs);
  }

  // One expandQuery() call for a prefix the user has stopped typing, then merge
  // the expansion terms' title matches into the open dropdown. Every failure
  // mode — over budget, network error, degraded server response, no new terms,
  // superseded cycle — degrades silently to the keyword suggestions already on
  // screen, because there is nothing useful to tell a user mid-keystroke.
  async function enrichSuggestions(term, version, cfg) {
    if (version !== suggestVersion || !suggestOpen || !els.sayt) return;
    if (!saytExpandBudgetAllows(cfg)) {
      debugLog('[scolta:sayt] expansion budget spent (' + cfg.expandPerMinute +
        '/min); keyword suggestions only until the window rolls');
      return;
    }
    saytExpandCalls.push(Date.now());

    let expansion;
    try {
      expansion = await expandQuery(term);
    } catch (e) {
      debugLog('[scolta:sayt] expansion failed', e);
      return;
    }
    if (version !== suggestVersion || !suggestOpen) return;

    const rawTerms = Array.isArray(expansion) ? expansion : ((expansion && expansion.terms) || []);
    const lower = term.toLowerCase();
    const fresh = [];
    for (const t of rawTerms) {
      if (typeof t !== 'string') continue;
      const trimmed = t.trim();
      if (!trimmed || trimmed.toLowerCase() === lower) continue;
      if (fresh.indexOf(trimmed) === -1) fresh.push(trimmed);
    }
    if (fresh.length === 0) return;

    let rows = [];
    try {
      const searches = await Promise.all(fresh.map(
        t => Promise.resolve(pagefind.search(t, {})).catch(() => ({ results: [] }))
      ));
      if (version !== suggestVersion || !suggestOpen) return;
      const seenIds = new Set();
      for (const s of searches) {
        for (const r of ((s && s.results) || [])) {
          if (seenIds.has(r.id)) continue;
          seenIds.add(r.id);
          rows.push(r);
        }
      }
    } catch (e) {
      debugLog('[scolta:sayt] expansion search failed', e);
      return;
    }
    if (rows.length === 0) return;

    // Same per-pass fragment cap as the keyword pass.
    const slice = rows.slice(0, cfg.maxSuggestions);
    let loaded;
    try {
      loaded = await Promise.all(slice.map(r => r.data()));
    } catch (e) {
      debugLog('[scolta:sayt] expansion fragment load failed', e);
      return;
    }
    if (version !== suggestVersion || !suggestOpen) return;

    const scored = scoreResults(loaded, term, SAYT_EXPANDED_WEIGHT);
    scored.sort((a, b) => b.score - a.score);
    const expandedSuggestions = deduplicateByTitle(scored)
      .map(toTitleSuggestion)
      .filter(s => s.title !== '');

    const active = activeSuggestion >= 0 ? suggestions[activeSuggestion] : null;
    const merged = mergeSuggestions(
      suggestions,
      expandedSuggestions,
      cfg.maxSuggestions
    );
    if (merged.length === suggestions.length) return;   // nothing new survived the cap

    renderSuggestions(merged, term);

    // The list grew around the user's selection; keep it selected rather than
    // silently dropping them back to "nothing active" mid-keyboard-navigation.
    if (active) {
      const activeKey = active.title.toLowerCase();
      const idx = merged.findIndex(s => s.title.toLowerCase() === activeKey);
      if (idx >= 0) setActiveSuggestion(idx);
    }
  }

  // Count distinct results across the union of search terms under the given
  // filters. Mirrors the union the real merged search performs, but only
  // counts result ids — no fragment loads — so it is cheap enough to run as
  // a probe before committing to a filter.
  async function countUnionResults(terms, filters) {
    const seen = new Set();
    const searches = await Promise.all(terms.map(async (term) => {
      try {
        return await pagefindSearch(term, filters);
      } catch (_) {
        return { results: [] };
      }
    }));
    for (const search of searches) {
      for (const r of (search.results || [])) {
        seen.add(r.id);
      }
    }
    return seen.size;
  }

  // Recall guard for LLM filter hints (2026-06-09 regression: auto-applied
  // topic filters that are individually plausible but jointly near-empty
  // collapsed "most popular git workflows" from 76 results to 1 on the
  // git-manual corpus). The LLM cannot know corpus counts, so no prompt fix
  // can be complete — the client is the only layer holding ground truth.
  //
  // Hints are evaluated sequentially against the JOINT result count (each
  // accepted hint tightens the base for the next), so stacked hints whose
  // intersection collapses are caught even when each marginal looks healthy.
  // A hint is auto-applied only when the filtered union keeps at least
  // FILTER_HINT_MIN_RESULTS results (clamped to the unfiltered count for tiny
  // corpora) AND at least FILTER_HINT_MIN_RATIO of the unfiltered union.
  // Declined hints are returned as "offered" — rendered as a clickable
  // suggestion chip instead of silently narrowing the results. Setting both
  // knobs to 0 restores the previous always-apply behavior.
  async function partitionFilterHintByRecall(filterHint, probeTerms, baseFilters, CONFIG) {
    const applied = {};
    const offered = {};

    const minResults = CONFIG.FILTER_HINT_MIN_RESULTS;
    const minRatio = CONFIG.FILTER_HINT_MIN_RATIO;

    const acceptedFilters = {};
    for (const [dim, vals] of Object.entries(baseFilters)) {
      acceptedFilters[dim] = new Set(vals);
    }

    const baseCount = await countUnionResults(probeTerms, acceptedFilters);

    for (const [dim, val] of Object.entries(filterHint)) {
      const trialFilters = {};
      for (const [d, vals] of Object.entries(acceptedFilters)) {
        trialFilters[d] = new Set(vals);
      }
      if (!trialFilters[dim]) trialFilters[dim] = new Set();
      trialFilters[dim].add(val);

      const trialCount = await countUnionResults(probeTerms, trialFilters);

      if (trialCount >= Math.min(minResults, baseCount) && trialCount >= baseCount * minRatio) {
        applied[dim] = val;
        acceptedFilters[dim] = trialFilters[dim];
      } else if (trialCount > 0) {
        offered[dim] = val;
        debugLog('[scolta:filter] Recall guard declined hint', dim, '=', val,
          '(' + trialCount + ' of ' + baseCount + ' results) — offering instead of applying');
      } else {
        // Zero matches: the hint names a dimension or value the index does
        // not have (observed live: a filter_fields config naming "topic"
        // while the index exposes "section" — the hint can never match).
        // Offering a known-dead filter would just be a one-click path to an
        // empty page, so drop it entirely.
        debugLog('[scolta:filter] Recall guard dropped hint', dim, '=', val,
          '(0 of ' + baseCount + ' results — dimension/value absent from the index)');
      }
    }

    return { applied, offered };
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

  // The structural-only projection of a filter object: the dimensions that
  // scope the corpus (language/site/etc. in SKIP_FILTER_DIMENSIONS — typically
  // the auto-language default) with every user-facing facet selection dropped.
  // Every facet count in this file is computed under this scope and never under
  // activeFilters, so the numbers are independent of which facets the user has
  // clicked. Counting under the user's selection is the obvious shortcut and it
  // is wrong: a page loaded with ?f_difficulty=Beginner would report 0 for every
  // other value, hideEmptyFacets would hide them all, and the user could never
  // switch facet value.
  function structuralFilterScope(baseFilters) {
    const structuralFilters = {};
    for (const [dim, vals] of Object.entries(baseFilters || {})) {
      if (SKIP_FILTER_DIMENSIONS.has(dim.toLowerCase())) {
        structuralFilters[dim] = vals;
      }
    }
    return structuralFilters;
  }

  // Add two count maps, per dimension per value. A value present in one and
  // absent from the other starts at 0. Neither input is mutated.
  function addFacetCounts(base, extra) {
    const out = {};
    for (const [dim, vals] of Object.entries(base || {})) {
      out[dim] = Object.assign({}, vals);
    }
    for (const [dim, vals] of Object.entries(extra || {})) {
      if (!out[dim]) out[dim] = {};
      for (const [value, n] of Object.entries(vals)) {
        out[dim][value] = (out[dim][value] || 0) + n;
      }
    }
    return out;
  }

  // Zero any value the filtered search cannot reproduce.
  //
  // A count is a promise: click this value and results appear. After expansion
  // the tally and the list are built by different searches under different caps,
  // so the panel could offer a value no document in the list carries. Clicking
  // it re-ran the search with the filter applied and landed the user on "No
  // results found" while the expansion chips stayed on screen — 8 of 11 hashtag
  // values on the 12,541-page corpus in #265, the worst symptom there.
  //
  // A value carried by at least one document in the ranked list is always
  // reproducible: the click re-runs the same terms with that value added to the
  // filters, a filter only ever narrows, so the document carrying it still
  // matches its term and still comes back. Presence in the list is therefore a
  // sound reachability test, and it costs nothing — those fragments are loaded.
  //
  // Zeroed rather than deleted, so hideEmptyFacets treats it exactly as it
  // treats a genuine zero and a deployment that shows empty facets shows it at 0
  // instead of a number with nothing behind it.
  //
  // Applied ONLY when the user has no facet selection. The list is searched
  // under activeFilters while counts are deliberately computed under structural
  // scope alone; with a selection active the list holds only matching documents,
  // every other value would have no carrier, and gating on presence would hide
  // precisely the values structuralFilterScope() exists to keep reachable. With
  // no selection the two scopes coincide and the test is sound.
  function suppressUnreachableValues(counts, results, baseFilters) {
    const structural = structuralFilterScope(baseFilters);
    if (Object.keys(structural).length !== Object.keys(baseFilters || {}).length) {
      return counts;
    }
    const carried = {};
    for (const r of (results || [])) {
      const filters = r && r.data && r.data.filters;
      if (!filters) continue;
      for (const [dim, vals] of Object.entries(filters)) {
        if (!carried[dim]) carried[dim] = new Set();
        for (const v of (Array.isArray(vals) ? vals : [vals])) carried[dim].add(v);
      }
    }
    const out = {};
    let zeroed = 0;
    for (const [dim, vals] of Object.entries(counts || {})) {
      out[dim] = {};
      for (const [value, n] of Object.entries(vals)) {
        // If no document in the list carries this dimension AT ALL, the list
        // says nothing about it, and silence is not evidence of unreachability.
        // Stand down rather than blank the whole dimension: a deployment whose
        // fragments carry no `filters` (counts coming from the filter index
        // alone) would otherwise lose its entire panel the moment expansion ran.
        if (n > 0 && carried[dim] && !carried[dim].has(value)) {
          out[dim][value] = 0;
          zeroed++;
        } else {
          out[dim][value] = n;
        }
      }
    }
    if (zeroed > 0) {
      debugLog(`[scolta:facets] Zeroed ${zeroed} value(s) no result in the list carries`);
    }
    return out;
  }

  // The typed query's facet counts AND the identity of the documents they were
  // counted over, so the expansion pass can fold its own contribution in
  // without counting any document twice. Returns
  // { counts: { dimension: { value: count } }, ids: Set, urls: Set }.
  //
  // A single Pagefind search returns per-value counts for every dimension in one
  // shot (`.filters`); the count next to a value means "N of the results for
  // your search are tagged this."
  //
  // The count source must follow the same query-mode decision the result list
  // makes (see the OR fallback in doSearch). Pagefind ANDs every word of a
  // multi-word query, so a long conversational query frequently returns zero
  // AND matches; the result list then rebuilds from per-term OR searches, but a
  // single native `.filters` read off the empty AND search would report every
  // count as 0. So:
  //   1. AND search non-empty → return its native `.filters` (exact, uncapped).
  //   2. AND search empty AND the OR fallback would engage (>1 meaningful term,
  //      not a forced phrase) → tally the UNION of per-term matches (counted by
  //      fragment id so a doc matching several terms counts once). Pagefind has
  //      no term-level OR and summing per-term `.filters` would double-count, so
  //      the union must be tallied from loaded fragments, not summed.
  //   3. AND search empty and fallback would NOT engage (single term or forced
  //      phrase) → the result list is genuinely empty too, so all-zero counts
  //      are truthful; return the empty search's `.filters`.
  // The mode decision is made on THIS structural-only search, not the primary
  // search — they can diverge (a user-applied facet may empty the primary search
  // while the structural search still matches), and counts describe the typed
  // query against structural scope, so the structural search's verdict governs.
  //
  // `urls` is populated only where fragments were loaded anyway (mode 2), so the
  // expansion delta can collapse a document the result list collapses by URL. In
  // mode 1 nothing is loaded — `search.filters` counts the FULL matched set
  // without touching a fragment, which is the property that makes this pass
  // nearly free — so `urls` is empty there and the delta dedups by fragment id
  // alone. Two fragment ids sharing one normalized URL therefore still count
  // twice across the typed/expansion boundary in mode 1, exactly as Pagefind's
  // own native counts already do within it.
  async function computeTypedFacetCounts(query, structuralFilters, meaningfulTerms, isForcedPhrase) {
    const search = await pagefindSearch(query, structuralFilters);
    // Mode 1: AND search matched — native per-value counts, exact.
    if (search && search.results && search.results.length > 0) {
      return {
        counts: search.filters || {},
        // Uncapped and free: `results` carries every match, and only `.data()`
        // costs anything.
        ids: new Set(search.results.map(r => r.id)),
        urls: new Set(),
      };
    }
    // Mode 2: AND search empty but the OR fallback would populate the list —
    // counts must follow the same union the result path shows.
    const terms = Array.isArray(meaningfulTerms) ? meaningfulTerms : [];
    if (!isForcedPhrase && terms.length > 1) {
      return await computeUnionFacetCounts(terms, structuralFilters);
    }
    // Mode 3: empty and no fallback — zeros are truthful.
    return {
      counts: (search && search.filters) ? search.filters : {},
      ids: new Set(),
      urls: new Set(),
    };
  }

  // Compute the query-fixed facet counts for a typed query, before expansion.
  //
  // Counts are a fixed property of the search: computed once when the query is
  // submitted and never recomputed on a facet toggle, a sort or a load-more, so
  // the panel numbers never move on click. They are recomputed exactly once
  // more, when AI expansion lands and changes the result list under them — see
  // computeExpandedFacetCounts(). Returns { dimension: { value: count } }.
  //
  // The mode decision and the scoping rules live in computeTypedFacetCounts();
  // this is the thin caller the primary pass uses, which wants the counts alone.
  async function computeQueryFacetCounts(query, baseFilters, meaningfulTerms, isForcedPhrase) {
    const structuralFilters = structuralFilterScope(baseFilters);
    try {
      const typed = await computeTypedFacetCounts(
        query, structuralFilters, meaningfulTerms, isForcedPhrase);
      return typed.counts;
    } catch (_) {
      return {};   // facet counts are best-effort — never block render
    }
  }

  // Fold the AI expansion's contribution into the typed query's counts:
  //
  //   counts = countsOf(typed ids) + countsOf(expansion ids \ typed ids)
  //
  // Subtracting the typed set is what satisfies "never count the same document
  // twice", and it is why the delta is computed against the id set the typed
  // pass ended with rather than as a second independent tally. Both terms are
  // computed under structuralFilters, never under activeFilters — see
  // structuralFilterScope() for why.
  //
  // `expansionTerms` is the SEEDING query list, passed through from
  // mergeExpandedSearchResults() rather than rebuilt here: it is assembled
  // behind an async sub-word admission guard, and a second copy of that logic
  // would drift from the one that decided the result list. Terms that only lend
  // co-occurrence score (the user's typed words, agreement-only phrase
  // sub-words) introduce no documents of their own and are excluded upstream —
  // counting them would report documents that are not in the list.
  //
  // Returns the merged count map, or null when the typed pass itself failed, in
  // which case the caller keeps whatever the panel already shows rather than
  // blanking it.
  async function computeExpandedFacetCounts(query, baseFilters, countContext, expansionTerms) {
    const structuralFilters = structuralFilterScope(baseFilters);
    const ctx = countContext || {};
    let typed;
    try {
      typed = await computeTypedFacetCounts(
        query, structuralFilters, ctx.meaningfulTerms, ctx.isForcedPhrase);
    } catch (_) {
      return null;   // best-effort — never blank a panel that is already right
    }
    const terms = [];
    for (const term of (expansionTerms || [])) {
      // The typed query is already counted; searching it again would be a memo
      // hit but its documents are all in `typed.ids` anyway.
      if (typeof term === 'string' && term && term !== query && terms.indexOf(term) === -1) {
        terms.push(term);
      }
    }
    if (terms.length === 0) return typed.counts;
    try {
      const delta = await computeUnionFacetCounts(terms, structuralFilters, typed);
      return addFacetCounts(typed.counts, delta.counts);
    } catch (_) {
      return typed.counts;
    }
  }

  // Tally facet counts from the UNION of per-term matches, over the documents a
  // caller has not already counted. Two callers: the count-path mirror of the
  // result path's OR fallback (no seed — the union IS the count), and the
  // expansion delta (seeded with the typed pass's ids, so only documents
  // expansion actually added are counted).
  //
  // Runs one structural-only-filtered search per term, unions the results by
  // fragment id (so a document matching several terms is counted ONCE — never
  // sum per-term `.filters`, which double-counts every document that matched
  // more than one query), and caps each term's results at MAX_PAGEFIND_RESULTS,
  // the same cap the result path loads under, so the tally describes the
  // documents that actually reached the list.
  //
  // How the unique documents are then counted depends on the facet index:
  //   - With the artifact: facetCountsFor() reads nothing but `r.id`, so the
  //     delta costs ZERO fragment loads and is exact.
  //   - Without it: load each unique fragment and tally `data.filters`. Filter
  //     values may be a scalar or an array — both are handled — and a Set per
  //     document guards against a repeated value within one document's array, so
  //     a document carrying two values in one dimension adds one to each of
  //     those two values and never two to one. Every dimension found is tallied
  //     (renderFilters skips structural dims anyway). Fragment loads are
  //     Pagefind-cached by hash, and the result path loads largely the same
  //     fragments, so the marginal cost is small. Since the fragments are loaded
  //     here anyway, documents are additionally collapsed by the normalized URL
  //     mergeResults() dedups the RESULT list by, so the panel and the list
  //     agree when one page is indexed under two fragment ids.
  //
  // `seed` is an optional { ids, urls } to start from — both sets are copied,
  // never mutated. Returns { counts, ids, urls }: the counts for the documents
  // this call added, and the sets it ended with.
  async function computeUnionFacetCounts(terms, structuralFilters, seed) {
    const CONFIG = getInstanceConfig();
    const searches = await Promise.all(
      terms.map(term => pagefindSearch(term, structuralFilters))
    );
    const seenIds = new Set((seed && seed.ids) ? seed.ids : []);
    const seenUrls = new Set((seed && seed.urls) ? seed.urls : []);
    const fresh = [];
    for (const search of searches) {
      if (!search || !search.results) continue;
      const toLoad = Math.min(search.results.length, CONFIG.MAX_PAGEFIND_RESULTS);
      for (let j = 0; j < toLoad; j++) {
        const r = search.results[j];
        if (seenIds.has(r.id)) continue;   // union by id — count each doc once
        seenIds.add(r.id);
        fresh.push(r);
      }
    }
    // Collapse the delta exactly as the result list collapses it, BEFORE any
    // value is counted. mergeExpandedSearchResults() puts every expansion
    // document through mergeResults() (one row per normalized URL) and then
    // deduplicateByTitle() (near-duplicate titles, Jaccard >= 0.6). A document
    // the list folds away is not a row the user can ever reach, so counting it
    // was a count with nothing behind it: on a corpus with formulaic titles the
    // panel read one high per collapsed pair, which is the shape of the
    // off-by-one divergences in #265.
    //
    // This costs the artifact path the fragment loads it used to avoid. There is
    // no way around that: the collapse is defined on `url` and `meta.title`, and
    // the artifact stores neither — it maps a fragment hash to a page ordinal and
    // nothing else. The loads are Pagefind-cached by hash and the result path has
    // already loaded most of these same fragments, so the marginal cost is the
    // one the non-artifact path always paid. Counting still runs through
    // facetCountsFor() when the artifact is present, so only the collapse is new.
    const fragments = await Promise.all(fresh.map(r => r.data()));
    const candidates = [];
    for (let i = 0; i < fresh.length; i++) {
      const data = fragments[i] || {};
      const url = normalizeResultUrl(resolveUrl(data.url || ''));
      if (url) {
        if (seenUrls.has(url)) continue;   // one page, however many fragment ids
        seenUrls.add(url);
      }
      candidates.push({ result: fresh[i], data: data });
    }
    // Title collapse within the delta. It cannot reach across the typed/expansion
    // boundary in mode 1, where the typed pass counts natively and loads no
    // fragment, so it has no titles to compare against — the same limit already
    // documented there for the URL collapse. deduplicateByTitle() returns the
    // kept objects themselves, so each survivor still carries its Pagefind result
    // for the artifact path to count by page ordinal.
    const kept = deduplicateByTitle(candidates);
    if (facetIndex) {
      return {
        counts: facetCountsFor(facetIndex, kept.map(s => s.result)),
        ids: seenIds,
        urls: seenUrls,
      };
    }
    const counts = {};
    for (const { data } of kept) {
      const filters = data && data.filters;
      if (!filters) continue;
      for (const [dim, vals] of Object.entries(filters)) {
        const values = new Set(Array.isArray(vals) ? vals : [vals]);
        if (!counts[dim]) counts[dim] = {};
        for (const v of values) {
          counts[dim][v] = (counts[dim][v] || 0) + 1;
        }
      }
    }
    return { counts, ids: seenIds, urls: seenUrls };
  }

  // The URL identity the result list is deduplicated by: strip `.html`, strip a
  // trailing slash, lowercase — the same normalization the Rust merge applies
  // before it dedups. Shared with the facet-count path so the panel collapses
  // exactly the documents the list collapses, rather than a second, drifting
  // definition of "the same page".
  function normalizeResultUrl(u) {
    return (u || '').replace(/\.html$/, '').replace(/\/$/, '').toLowerCase();
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
        const normalizeUrl = normalizeResultUrl;
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
    // JS fallback merge. Keyed by the SAME normalized URL the Rust merge dedups
    // by (`normalize_urls: true` above), not the raw one: keeping two identities
    // for "the same page" meant /foo and /foo.html stayed two results here and
    // collapsed to one under WASM, and the facet-count path — which collapses
    // them — would then agree with only one of the two merges.
    const BONUS = getInstanceConfig().CROSS_LIST_BONUS;
    const urlMap = new Map();
    for (const r of currentResults) {
      const url = normalizeResultUrl(resolveUrl(r.data.url || ''));
      if (!urlMap.has(url)) {
        urlMap.set(url, { ...r });
      } else {
        const prev = urlMap.get(url);
        prev.score = Math.max(prev.score, r.score) + BONUS;
      }
    }
    for (const r of newResults) {
      const url = normalizeResultUrl(resolveUrl(r.data.url || ''));
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

  async function searchAndLoadParallel(queries, filters, originalQuery, specificityOpts) {
    const CONFIG = getInstanceConfig();
    if (queries.length === 0) return [];

    const searches = await Promise.all(
      queries.map(q => pagefindSearch(q.term, filters))
    );

    // Specificity weighting: damp each sub-query by how common its term is in
    // the corpus, so a rare on-intent term ("papilledema", "vegetarian")
    // outranks a ubiquitous one ("lunar", "apollo", "dinner") instead of
    // counting the same. df is the term's own (scoped) Pagefind match count;
    // total is the corpus size. Rare term -> multiplier ~1 (weight unchanged, so
    // already-discriminating corpora are untouched), ubiquitous term -> ~FLOOR.
    // Disabled or unknown total -> no change. This is what closes the two guard
    // bypasses: a high-frequency word the user TYPED (routed here via the OR
    // fallback) and a common sub-word leaked from an expansion PHRASE are both
    // down-weighted here rather than flooding the head of the list.
    const specByTerm = new Map();
    if (specificityOpts && specificityOpts.enabled) {
      const total = specificityOpts.corpusTotal;
      const strongCut = CONFIG.SPECIFICITY_STRONG_MATCH ?? 0.55;
      for (let i = 0; i < searches.length; i++) {
        const df = searches[i].results.length;
        const w = specificityWeight(df, total, CONFIG);
        if (w != null) {
          specByTerm.set(queries[i].term, w);
          // Report a strong (rare) match via the caller's opts object rather
          // than touching the module-level hadSpecificMatch here: this runs
          // before the caller's searchVersion staleness check, so a stale or
          // discarded search must not be allowed to flip the flag the CURRENT
          // search reads. The caller applies it only after confirming the
          // search is still current.
          if (w >= strongCut && df > 0) specificityOpts.strongMatched = true;
        }
      }
    }

    // Some terms are passed in purely to lend co-occurrence weight and must NOT
    // introduce documents of their own:
    //   - typed query terms (a real "apollo 1 fire" post matches the typed
    //     "fire" AND the crew-name expansions), because a page matching only a
    //     typed word belongs to the primary / OR search that already seeds the
    //     list, and emitting it here would broaden recall (e.g. the ubiquitous
    //     apostrophe-s "scary" hits);
    //   - agreement-only phrase sub-words ("grissom" out of "Gus Grissom"),
    //     which exist to credit documents the real search already found without
    //     dragging in everything that merely mentions the word.
    // A term counts as seeding if ANY query contributed it that way, so a word
    // that is both typed and an expansion sub-word keeps its documents. When
    // every term seeds (the OR fallback calls this too) nothing is non-seeding.
    const seedingTerms = new Set(
      queries.filter(q => !q.isTyped && !q.agreementOnly).map(q => q.term)
    );

    // Load full document fragments ONLY for seeding terms. A non-seeding term
    // never introduces a document of its own — a URL found only by non-seeding
    // terms is not emitted — and whether it lends co-occurrence credit is
    // decided entirely from result ids (result-set size, specificity, term
    // class), never from any data() field. Loading its fragments therefore only
    // inflated the per-query loaded-document count (#156, the failing
    // result-count-baseline guard) without moving a single seeding decision.
    // The non-seeding terms are instead reasoned about by id below: their
    // matched-id sets are intersected against the seeded documents, and any
    // survivor's agreement magnitude is scored against the seeded document's
    // already-loaded fragment.
    const loadPromises = [];
    for (let i = 0; i < searches.length; i++) {
      if (!seedingTerms.has(queries[i].term)) continue;
      const search = searches[i];
      const { term, weight } = queries[i];
      const toLoad = Math.min(search.results.length, CONFIG.MAX_PAGEFIND_RESULTS);
      for (let j = 0; j < toLoad; j++) {
        const entry = search.results[j];
        loadPromises.push(
          entry.data().then(data => ({ data, term, weight, id: entry.id }))
        );
      }
    }
    const allLoaded = await Promise.all(loadPromises);

    const byTerm = new Map();
    for (const item of allLoaded) {
      if (!byTerm.has(item.term)) byTerm.set(item.term, []);
      byTerm.get(item.term).push(item);
    }

    // Co-occurrence accumulation across the per-term sub-queries. The previous
    // merge kept only each URL's single highest-scoring term (Rust merge_results
    // dedups by max), so a document matching one discriminating term — rare OR
    // common — scored the same as one matching several of the query/expansion
    // terms together. That let a lone off-topic rare-word match ("crisis" → The
    // Cuban Missile Crisis) or a lone common-word match ("moment" → a solemn
    // post) take the top slot over documents that agreed with the whole intent.
    // Here every term a URL matches contributes: its strongest match sets the
    // base, and each additional distinct term adds SPECIFICITY_COOCCURRENCE of
    // that term's own (already specificity- and weight-scaled) score. Multi-term
    // agreement therefore outranks a lone strong single-term match, with no
    // per-query constant. COOCCURRENCE = 0 reproduces the old max-only merge.
    const COOCCUR = CONFIG.SPECIFICITY_COOCCURRENCE ?? 0;
    // Agreement gate: only a term that actually discriminates (specificity above
    // the floor — i.e. not ubiquitous) may count as a SECOND matching axis.
    // Every doc still keeps its single best match as a base score, so recall and
    // the exact/rare-single-term cases are untouched; but a document does not
    // earn a multi-term-agreement bonus for also matching a word that is in
    // almost every page ("apollo", "13", or the apostrophe-s noise "scary" hits).
    // That is what keeps the co-occurrence reward from re-flooding the head with
    // generic pages that merely share the common typed words.
    const GATE = CONFIG.SPECIFICITY_AGREEMENT_GATE ?? CONFIG.SPECIFICITY_FLOOR ?? 0.15;
    // Per-rank decay applied to the 2nd, 3rd, … agreeing term (see the emit
    // loop below). 1 = flat sum, 0 = strongest agreeing term only.
    const DECAY = CONFIG.SPECIFICITY_AGREEMENT_DECAY ?? 1;
    const BONUS = CONFIG.CROSS_LIST_BONUS;
    // The join is keyed by the Pagefind entry id, not the loaded fragment url.
    // The id is available on every result BEFORE data() is called, which is
    // exactly what lets the non-seeding terms below participate without a load:
    // they are matched into this map by id-overlap alone. Seeded documents are
    // the only ones keyed here, so a URL found only by non-seeding terms is
    // never created (the old explicit "drop unseeded" pass is now structural).
    const byId = new Map();
    for (const [term, items] of byTerm) {
      const spec = specByTerm.get(term);
      const rawWeight = items[0].weight;
      const weight = rawWeight * (spec != null ? spec : 1);
      // Agreement is normalized against the term's POSITIONAL weight. That
      // weight decays with the order the expansion listed its terms (0.6, 0.55,
      // … 0.1), which is a fine prior for "which term best restates the query"
      // but the wrong ruler for "does this document agree on several axes": a
      // highly discriminating term listed last ("scrubbers", "chaffee") would
      // otherwise contribute almost nothing to agreement purely because of its
      // position. Dividing it out leaves agreement governed by specificity,
      // which is the property we actually mean. The BASE score keeps the
      // positional weight untouched, so ordering by best-single-term is
      // unchanged.
      const agreementScale = rawWeight > 0 ? 1 / rawWeight : 0;
      // A term counts as a SECOND axis of agreement only if it actually
      // discriminates: specificity above the gate. Every document still keeps
      // its single best match as a base score, so recall and the exact- and
      // rare-single-term paths are untouched; what a document does NOT get is
      // an agreement reward for also matching a word that is in most of the
      // corpus ("apollo" at df 131/204, or "1" at 204/204).
      //
      // A graded hinge — credit rising smoothly from the gate instead of a
      // step — was tried here and scored strictly worse across the suite
      // (14/17 vs 15/17 acceptance checks, every configuration). Partial credit
      // for near-ubiquitous terms re-floods the head faster than it helps
      // documents that agree on several moderate terms, because the flooding
      // documents are far more numerous. The step is deliberate.
      //
      // spec == null means the frequency signal is unavailable, where behaviour
      // must stay exactly as before: full credit.
      const countsAsAgreement = (spec == null) || (spec > GATE);
      const loaded = items.map(i => i.data);
      // scoreResults returns the same data objects (reordered by score), so a
      // fragment-identity lookup recovers each scored result's Pagefind id.
      const idByData = new Map(items.map(it => [it.data, it.id]));
      // Stamp expansion provenance onto each loaded result so the summary
      // candidate selector can group by sub-query (issue #170). This survives
      // the merge (which preserves the strongest data object per URL) and is
      // invisible to the visible ranked list — only the summarizer consults it.
      for (const d of loaded) {
        if (d) d.__scoltaSourceTerm = term;
      }
      const scoredVsTerm = scoreResults(loaded, term, weight, originalQuery);
      const scoredVsOriginal = scoreResults(loaded, originalQuery, weight * 0.5);

      for (let idx = 0; idx < scoredVsTerm.length; idx++) {
        const r = scoredVsTerm[idx];
        const contribution = r.score + (scoredVsOriginal[idx].score > 0
          ? Math.min(scoredVsOriginal[idx].score * 0.3, BONUS) : 0);
        const agreementValue = contribution * agreementScale;
        const id = idByData.get(r.data);
        const e = byId.get(id);
        if (!e) {
          byId.set(id, {
            data: r.data, top: contribution, topAgreement: agreementValue,
            topCounts: countsAsAgreement, rest: [],
          });
        } else if (contribution > e.top) {
          // A stronger sub-query for this document becomes the new base; the old
          // base demotes into the agreement pool only if its term discriminated.
          // Keep the better-matching data object (its excerpt highlights the
          // strongest term, matching prior render behaviour).
          if (e.topCounts) e.rest.push(e.topAgreement);
          e.top = contribution;
          e.topAgreement = agreementValue;
          e.topCounts = countsAsAgreement;
          e.data = r.data;
        } else if (countsAsAgreement) {
          e.rest.push(agreementValue);
        }
      }
    }

    // Non-seeding terms (typed words, agreement-only sub-words) lend agreement
    // by id-overlap only — no document of their own is ever loaded or emitted.
    // For each, take its matched-id set straight from search().results and keep
    // only the ids that overlap a seeded document. A non-overlapping candidate
    // would be dropped anyway, so its (unloaded) fragment is never needed; a
    // surviving one is scored against the seeded document's ALREADY-LOADED
    // fragment (same document => same fragment, whichever term loaded it), so
    // its magnitude reuses the seeded copy instead of a fresh data() fetch.
    for (let i = 0; i < searches.length; i++) {
      const { term, weight } = queries[i];
      if (seedingTerms.has(term)) continue;
      const spec = specByTerm.get(term);
      // A non-seeding term below the agreement gate can be neither a second
      // agreement axis nor a seed, so it contributes nothing — skip it whole.
      // This is also why it never needed its documents loaded.
      const countsAsAgreement = (spec == null) || (spec > GATE);
      if (!countsAsAgreement) continue;
      const search = searches[i];
      const toLoad = Math.min(search.results.length, CONFIG.MAX_PAGEFIND_RESULTS);
      // Survivors in this term's own relevance order, so the positional prior
      // scoreResults applies (1 - i/(len-1)) ranks them the way this term would.
      const survivors = [];
      for (let j = 0; j < toLoad; j++) {
        const e = byId.get(search.results[j].id);
        if (e) survivors.push(e);
      }
      if (survivors.length === 0) continue;
      const rawWeight = weight;
      const wt = rawWeight * (spec != null ? spec : 1);
      const agreementScale = rawWeight > 0 ? 1 / rawWeight : 0;
      const frags = survivors.map(e => e.data);
      const entryByFrag = new Map(survivors.map(e => [e.data, e]));
      const scoredVsTerm = scoreResults(frags, term, wt, originalQuery);
      const scoredVsOriginal = scoreResults(frags, originalQuery, wt * 0.5);
      for (let idx = 0; idx < scoredVsTerm.length; idx++) {
        const r = scoredVsTerm[idx];
        const contribution = r.score + (scoredVsOriginal[idx].score > 0
          ? Math.min(scoredVsOriginal[idx].score * 0.3, BONUS) : 0);
        const agreementValue = contribution * agreementScale;
        const e = entryByFrag.get(r.data);
        if (e) e.rest.push(agreementValue);
      }
    }

    // Every entry in byId was seeded by construction — only seeding terms build
    // entries, and non-seeding terms merely add agreement to existing ones — so
    // there is nothing to drop here. A URL found only by non-seeding terms was
    // never created (it is the primary/OR search's job to seed it), and when NO
    // seeding term exists (an empty expansion leaves only typed terms) byId is
    // empty, leaving the count to the primary/OR path rather than inflating it
    // with typed-only matches.
    const results = [];
    for (const e of byId.values()) {
      // Diminishing returns on successive agreeing terms. A flat sum rewards
      // BREADTH of agreement without bound, which is not what "several terms
      // agree" is supposed to mean: the documents that match the most distinct
      // expansion terms are aggregation pages — a glossary, a mission timeline,
      // a resources index, a roll-call post naming every astronaut — that touch
      // every term shallowly and none of them topically. Unbounded summing put
      // exactly those at the head ("Resources" and "Eighteen Months Later" above
      // the real Apollo 1 fire posts; "The Astronauts I've Been Getting to Know"
      // above Apollo 10's descent).
      //
      // The marginal evidence of the Nth agreeing term falls off sharply: going
      // from one matching term to two is the signal that separates a topical
      // match from a lone coincidental word, while going from five to six says
      // little except that the page is long and enumerative. So the agreement
      // values are taken strongest-first and geometrically decayed. This keeps
      // the multi-term reward that fixed Cuban Missile Crisis and Gordon Cooper
      // (which turns on the FIRST extra term) while denying a directory page the
      // right to out-accumulate a focused post through sheer breadth.
      //
      // DECAY = 1 reproduces the flat sum exactly; DECAY = 0 keeps only the
      // single strongest agreeing term.
      const agreements = e.rest.slice().sort((a, b) => b - a);
      let agreementSum = 0;
      for (let i = 0; i < agreements.length; i++) {
        agreementSum += agreements[i] * Math.pow(DECAY, i);
      }
      // Base score is the document's single strongest sub-query — exactly what
      // the old max-merge produced. The multi-term agreement reward rides
      // alongside as `agreementBonus` instead of being folded in here, so the
      // CALLER can add it AFTER merging this list with the primary/OR list.
      // That is what lets agreement earned here outrank a lone strong
      // single-term match that scored higher in the other path: a max-merge
      // alone can never express cross-path agreement.
      results.push({ data: e.data, score: e.top, agreementBonus: COOCCUR * agreementSum });
    }

    return results;
  }

  // Add each URL's multi-term agreement bonus to an already-merged list. Kept
  // separate from mergeResults (a max by URL, implemented in WASM, which does
  // not carry extra fields) so the bonus survives the merge and applies even
  // when the higher base score came from the other search path.
  function applyAgreementBonus(merged, sourceResults) {
    const bonusByUrl = new Map();
    for (const r of sourceResults) {
      if (r && r.agreementBonus && r.data) {
        bonusByUrl.set(resolveUrl(r.data.url || ''), r.agreementBonus);
      }
    }
    if (bonusByUrl.size === 0) return merged;
    for (const r of merged) {
      if (!r || !r.data) continue;
      const bonus = bonusByUrl.get(resolveUrl(r.data.url || ''));
      if (bonus) r.score = (r.score || 0) + bonus;
    }
    return merged;
  }

  // Corpus size for the sub-word frequency guard's denominator. This used to
  // run pagefind.search(null, filters), but a match-all search makes pagefind
  // download the ENTIRE word index — on a large long-form corpus that is
  // thousands of requests (111 MB / 5,678 chunks on a 6,900-page site), and
  // because the AI summary is gated behind the expansion merge, the AI Overview
  // stalled for minutes on production while the index streamed in. The same
  // totals are available without touching the word index: pagefind.filters()
  // value counts and the per-language page counts in pagefind-entry.json, both
  // already cached at init.
  // Specificity (inverse-document-frequency) weight for a search term.
  //
  // The ranker rewards matching a term in proportion to how much intent it
  // carries, and a term's intent is inversely related to how many documents
  // contain it: a word in almost every page ("lunar", "apollo", "dinner")
  // discriminates nothing, a word in a handful of pages ("papilledema",
  // "vegetarian") pins the query. This reuses the same corpus-frequency signal
  // the sub-word guard already computes — df is the term's own Pagefind match
  // count (scoped to the active filters), total is the corpus size from
  // subwordCorpusSize().
  //
  //   weight = clamp( ln(total / df) / ln(total + 1), FLOOR, 1 )
  //
  //   df = 1        -> ~1.0  (unique term, full weight)
  //   df = total    -> FLOOR (ubiquitous term, floored, never zero so recall
  //                           and the good already-discriminating corpora are
  //                           preserved — a rare-term query is unaffected)
  //
  // Returns null when total is unknown (0), so callers keep their existing
  // weight and behavior is unchanged where the frequency signal is unavailable.
  function specificityWeight(df, total, CONFIG) {
    if (!total || total <= 0 || !df || df <= 0) return null;
    const floor = (CONFIG && CONFIG.SPECIFICITY_FLOOR != null) ? CONFIG.SPECIFICITY_FLOOR : 0.15;
    const clampedDf = Math.min(df, total);
    const idf = Math.log(total / clampedDf) / Math.log(total + 1);
    return Math.max(floor, Math.min(idf, 1));
  }

  function subwordCorpusSize(filters) {
    // Scoped: pagefind ORs values within a dimension and ANDs across
    // dimensions. The AND-count is unknowable from per-value totals, so use the
    // smallest dimension's selected-value sum as an upper bound. A too-large
    // denominator under-measures frequency and admits a sub-word the exact
    // count might have blocked — the recall-friendly direction.
    const dimCounts = [];
    if (filters && cachedPagefindFilters) {
      for (const [dim, vals] of Object.entries(filters)) {
        const counts = cachedPagefindFilters[dim];
        if (!counts) continue;
        const selected = vals instanceof Set ? [...vals] : Array.isArray(vals) ? vals : [vals];
        if (selected.length === 0) continue;
        dimCounts.push(selected.reduce((sum, v) => sum + (counts[v] || 0), 0));
      }
    }
    if (dimCounts.length > 0) return Math.min(...dimCounts);
    // Unscoped: exact total from pagefind-entry.json.
    if (cachedPagefindPageCount !== null) return cachedPagefindPageCount;
    // Last resort: the largest filter dimension's value-count sum. Exact when
    // any dimension covers every page; an undercount otherwise, which
    // over-measures frequency and blocks more sub-words — the fail-closed,
    // precision-preserving direction the guard already takes on errors.
    let max = 0;
    for (const counts of Object.values(cachedPagefindFilters || {})) {
      const sum = Object.values(counts).reduce((a, b) => a + b, 0);
      if (sum > max) max = sum;
    }
    return max; // 0 when no data — the caller fails closed
  }

  async function mergeExpandedSearchResults(expandedTerms, originalQuery, searchQuery, preserveFilters, version, sortOverride, subjectTerms, countContext) {
    const CONFIG = getInstanceConfig();
    // The seeding queries this pass ends up running — the ones that introduce
    // documents into the result list, and therefore the ones the facet counts
    // have to cover. Filled by whichever branch below builds the list, and
    // deliberately taken from the list that was actually searched rather than
    // rebuilt at the point of use.
    let countTerms = [];
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
    // words ("recipes", "cooking") are blocked. The numerator is probed with
    // the same active filters the real search uses (including the language
    // partition when auto_language_filter is on); the denominator comes from
    // subwordCorpusSize(), which scopes to those filters via cached totals
    // instead of a match-all search (the match-all downloaded the entire word
    // index and stalled the AI summary for minutes on large corpora).
    // 0 reproduces v1.0.0 (no sub-words); >=1 admits all sub-words.
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
      // bypass the ADMISSION check so recall is preserved. Fix D: unless it's on
      // the guard denylist. Note: admitting a ubiquitous typed word no longer
      // lets it flood — searchAndLoadParallel now damps it by specificity at
      // rank time (specificityWeight), so the exemption costs recall nothing
      // while the rare terms still lead.
      if (queryTokens.has(word) && !subwordDenylist.has(word)) return true;
      if (subwordFreqCache.has(word)) return subwordFreqCache.get(word).allowed;
      let allowed = false;
      let df = null;
      try {
        if (subwordCorpusTotal === null) {
          subwordCorpusTotal = subwordCorpusSize(activeFilters);
        }
        if (subwordCorpusTotal > 0) {
          const hit = await pagefindSearch(word, activeFilters);
          df = hit.results.length;
          allowed = (df / subwordCorpusTotal) < subwordMaxFreq;
        }
      } catch (_) {
        allowed = false; // fail closed on pagefind error — preserve precision
        df = null;
      }
      subwordFreqCache.set(word, { allowed, df });
      return allowed;
    }

    // A phrase sub-word that the admission guard REJECTED may still be the word
    // that carries the phrase's meaning. Expansion phrases are searched as AND
    // phrases, so "Gus Grissom" matches only documents containing BOTH words —
    // a post that says "Grissom" without "Gus" scores nothing from that phrase,
    // even though "Grissom" is the discriminating half. That is how the strongest
    // Apollo 1 post ("After the Fire — What Happens Now", which names Grissom,
    // White and Chaffee) ended up with zero multi-term agreement while an
    // off-topic post that merely shares the ubiquitous typed word outranked it.
    //
    // Admitting such words as ordinary search terms is the wrong fix: it inflates
    // recall (the result count jumped 81 -> 127 on this corpus) because each one
    // drags in every document that merely mentions it. So they are admitted as
    // AGREEMENT-ONLY terms instead — they lend co-occurrence credit to documents
    // the real search already found, but they never introduce a document of their
    // own. Recall is byte-identical; only the ordering improves.
    //
    // The bound is the agreement gate itself, expressed as a frequency: a word
    // too common to clear SPECIFICITY_AGREEMENT_GATE can never contribute
    // agreement anyway, so probing it would cost a search for nothing.
    async function subwordAgreementOnly(word) {
      if (word.length <= 2) return false;
      if (subwordMaxFreq <= 0 || subwordMaxFreq >= 1) return false;
      // The denylist is an absolute veto: a word configured out of sub-word
      // admission must not slip back in as an agreement-only term. (Without this
      // a denylisted-but-discriminating word would still be searched for its
      // co-occurrence credit, which the sub-word guard's contract forbids.)
      if (subwordDenylist.has(word)) return false;
      if (await subwordAllowed(word)) return false; // already a full search term
      const cached = subwordFreqCache.get(word);
      if (!cached || cached.df == null || !subwordCorpusTotal) return false;
      const spec = specificityWeight(cached.df, subwordCorpusTotal, CONFIG);
      const gate = CONFIG.SPECIFICITY_AGREEMENT_GATE ?? CONFIG.SPECIFICITY_FLOOR ?? 0.15;
      return cached.df > 0 && spec != null && spec > gate;
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
        // An unmatched subject must NOT drop the sort: generic subjects that
        // name the corpus itself ("posts" on a blog, "crystals" in a crystal
        // shop) never map to a facet, and dropping silently ignored the
        // user's explicit sort intent ("newest posts" → no reorder, no
        // badge). Fall through to an unscoped sort instead — the sort badge
        // stays visible and dismissible, topical subjects usually map via
        // the exact/substring/subcategory passes above, and a sort field
        // absent from all results still falls back to relevance below.
        debugLog('[scolta:sort] No filter match for subject terms — applying sort unscoped');
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
        // The union never replaced the list — allScoredResults is still the
        // primary typed search — so the typed counts still describe it exactly.
      } else {
        // Every term in the sorted union seeds: each one's documents go into
        // urlMap and out to the list. The typed query is counted by the typed
        // pass, so only the rest is the delta.
        countTerms = [...termSet].filter(t => t !== searchQuery);
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

      // Second pass: the discriminating phrase sub-words the admission guard
      // rejected, added as agreement-only terms (see subwordAgreementOnly).
      // Deliberately a SEPARATE pass after every seeding term is queued, so a
      // word that seeds for one phrase is never demoted to agreement-only just
      // because another phrase mentioned it first.
      for (const term of validTerms) {
        const words = extractSearchTerms(term);
        if (words.length <= 1) continue;
        for (const word of words) {
          if (queries.some(q => q.term === word)) continue;
          if (!(await subwordAgreementOnly(word))) continue;
          const wordWeight = Math.max(expandBase - (weightIndex * 0.05), 0.1);
          queries.push({ term: word, weight: wordWeight, agreementOnly: true });
          weightIndex++;
        }
      }

      // The user's own typed terms join the SAME per-term co-occurrence
      // accumulator as the expansion terms (searchAndLoadParallel), so a document
      // matching the typed intent AND several expansion terms outranks one that
      // matches only expansion terms. This is the anchor that separates a real
      // "apollo 1 fire" post (matches typed "fire" plus the crew-name expansions)
      // from an off-topic post that matches the crew names but not "fire", and
      // that keeps a common typed word from being the sole ranking signal.
      // Full weight (the primary-path 1.0), then specificity-damped like every
      // other term, so a ubiquitous typed word ("moment") is still down-weighted.
      // The typed terms already drive the primary AND / OR search that seeds
      // allScoredResults; adding them here only lets their match COUNT toward
      // agreement, and typed-only documents are dropped from this path.
      //
      // Appended AFTER the expansion terms deliberately: a word that is both
      // typed AND an expansion sub-word ("cernan" and "last" in "Cernan last
      // words") must stay expansion-derived, or the typed-only drop below would
      // delete every document it retrieved. Adding typed terms last means the
      // dedup leaves such a word owned by the expansion that already claimed it.
      for (const term of extractSearchTerms(searchQuery)) {
        // The denylist vetoes even a typed word: a word configured out of
        // sub-word admission must not be searched here for co-occurrence credit
        // either (mirrors the guard's exemption veto and subwordAgreementOnly).
        if (subwordDenylist.has(term)) continue;
        if (!queries.some(q => q.term === term)) {
          queries.push({ term, weight: 1.0, isTyped: true });
        }
      }

      // Specificity weighting so a common word leaked from an expansion phrase
      // ("dinner" out of "meat-free dinner recipes") is scored by its rarity,
      // not counted equal to the rare words that carry the intent. The phrase
      // itself and its rare sub-words keep near-full weight; ubiquitous
      // sub-words are damped toward the floor.
      const expandSpecificity = {
        enabled: CONFIG.SPECIFICITY_WEIGHTING,
        corpusTotal: subwordCorpusSize(activeFilters),
        strongMatched: false,
      };
      // The same seeding test searchAndLoadParallel() applies, over the same
      // array: a typed term or an agreement-only sub-word lends co-occurrence
      // score to documents another query already found and never emits a URL of
      // its own, so counting it would put documents in the panel that are not in
      // the list.
      countTerms = queries.filter(q => !q.isTyped && !q.agreementOnly).map(q => q.term);

      const expandedResults = await searchAndLoadParallel(queries, activeFilters, searchQuery, expandSpecificity);

      if (version !== searchVersion) {
        debugLog('[scolta:expand] Discarding stale expansion after load (version', version, 'vs current', searchVersion, ')');
        return;
      }

      // Adopt the specificity signal only now that the staleness check has
      // passed, so a discarded expansion cannot flip the current search's flag.
      if (expandSpecificity.strongMatched) hadSpecificMatch = true;

      allScoredResults = mergeResults(
        allScoredResults,
        expandedResults,
        1.0,
        1.0
      );
      applyAgreementBonus(allScoredResults, expandedResults);
      allScoredResults.sort((a, b) => b.score - a.score);
      allScoredResults = deduplicateByTitle(allScoredResults);
    }

    displayedCount = 0;

    // renderResults() reconciles the container by result identity, so when the
    // expansion pass produces the same ordered list as the first paint this
    // costs zero node churn: the header gains its "(with expanded terms)" label
    // and every result node — with whatever a platform swapped into it — stays
    // exactly where it is.
    //
    // Painted BEFORE the count pass below, for the reason doSearch() paints
    // before its own: the count pass awaits searches, and the list is what the
    // user is waiting for. The panel holds its pre-expansion state across the
    // gap rather than being repainted against counts that are about to change.
    renderResults(true, 'expansion');

    // Expansion changed the result list, so the counts describing it are
    // recomputed here — the one and only time they move within a typed query.
    //
    // They used to be left alone, on the argument that a panel derived from the
    // deterministic typed query stays stable run-to-run while an LLM expansion
    // does not. But the LIST is equally LLM-driven, and a panel that disagrees
    // with the list beside it is worse than one that varies with it: every count
    // read low, so filtering by a value returned more results than promised, and
    // under the default hideEmptyFacets policy a value whose only matches came
    // from expansion counted 0 and was hidden outright — taking its whole
    // dimension group with it when every value was expansion-derived, which left
    // the user unable to filter on content sitting in front of them.
    //
    // Still fixed against everything else: a facet click, a sort and a load-more
    // are all preserveFilters cycles, which skip this and reuse the stored
    // counts, so no count moves when the user clicks. Counts also stay
    // selection-independent (structural scope only), which means an LLM-applied
    // filter hint can narrow the list without narrowing the panel — a known and
    // deliberate gap, since making counts follow that selection would reintroduce
    // exactly the "every other value reads 0 and disappears" failure.
    if (!preserveFilters && countTerms.length > 0) {
      const counts = await computeExpandedFacetCounts(
        searchQuery, activeFilters, countContext, countTerms);
      // Several awaits deep: a newer doSearch() may own the panel by now, and
      // late counts from an abandoned query must neither be stored nor rendered.
      if (version !== searchVersion) {
        debugLog('[scolta:expand] Discarding stale post-expansion facet counts (version', version, 'vs current', searchVersion, ')');
        return;
      }
      // Gate on the list that was just painted: allScoredResults is post-merge
      // and post-deduplicateByTitle here, so it is exactly the set of rows the
      // user can reach.
      if (counts) {
        queryFacetCounts = suppressUnreachableValues(counts, allScoredResults, activeFilters);
      }
    }
    renderFilters();
    debugLog(`[scolta:expand] ${sortOverride ? 'Native sort' : 'Merged'}: ${allScoredResults.length} results`);
  }

  // --- Main search ---

  async function doSearch(preserveFilters, initialFilters) {
    preserveFilters = preserveFilters || false;
    const CONFIG = getInstanceConfig();
    const query = els.queryInput.value.trim();
    if (!query || !pagefind) return;

    // The user committed. Any pending or in-flight suggest work is now noise:
    // cancel it, take the dropdown down, and hold the suggest path off until
    // the primary paint lands.
    cancelSuggest();
    closeSuggestions();
    recordRecentSearch(query);

    const version = ++searchVersion;

    // The suggest path stands down from here until the primary paint lands.
    //
    // Two things this has to get right. The window is owned by a VERSION
    // rather than flagged by a boolean: two doSearch() cycles overlap whenever
    // the user commits again while one is in flight, and with a boolean the
    // first cycle's exit unsuppresses the suggest path in the middle of the
    // second cycle's paint. And EVERYTHING inside the window lives in the try
    // below, not just the awaits — anything that escapes without releasing the
    // window leaves suggestions dead for the rest of the page's life, which is
    // silent, permanent, and invisible in every test that does not fail a
    // search on purpose (see tests/js/sayt.test.js).
    paintingVersion = version;

    // Declared out here because the tail of the cycle — the facet-count pass
    // and the expansion phase, both of which run after the window closes —
    // still reads them.
    let meaningfulTerms, searchQuery, isForcedPhrase, expandPromise;

    try {
      if (abortController) abortController.abort();
      abortController = new AbortController();

      currentQuery = query;

      displayedCount = 0;
      allScoredResults = [];
      hadSpecificMatch = false;
      conversationMessages = [];
      followUpCount = 0;
      // Fresh cycle, fresh memo: identical searches are shared WITHIN a cycle
      // only, so a count from an earlier query can never leak into this one.
      searchMemo = new Map();
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
      emitBeforeResults('loading');
      els.results.innerHTML = '<p class="scolta-searching">Searching...</p>';
      paintedEntries = [];
      paintedHighlightSignature = null;
      emitResultsRendered([], [], [], false);
      els.resultsHeader.innerHTML = "";
      els.noResults.style.display = "none";
      // Full reset, not just display:none — the reserved class and the last
      // summary's markup must not survive into the next cycle.
      releaseSummarySlot();
      els.loadMore.style.display = "none";
      if (!preserveFilters) {
        els.expandedTerms.style.display = "none";
      }

      meaningfulTerms = extractSearchTerms(query);
      searchQuery = meaningfulTerms.length > 0 ? meaningfulTerms.join(' ') : query;
      // Detect quoted phrase: user typed "hello world" with surrounding double-quotes.
      // Pagefind receives the unquoted terms; the Rust scorer receives the quoted form
      // so extract_query() can set forced_phrase = true and apply phrase multipliers.
      const trimmedQuery = query.trim();
      isForcedPhrase =
        trimmedQuery.startsWith('"') && trimmedQuery.endsWith('"') && trimmedQuery.length > 2;
      const scorerQuery = isForcedPhrase ? trimmedQuery : searchQuery;
      debugLog('[scolta:search] Filtered query:', JSON.stringify(sanitizeQueryForLogging(searchQuery)), '(original:', JSON.stringify(sanitizeQueryForLogging(query)), ')');

      allHighlightTerms = meaningfulTerms.length > 0
        ? meaningfulTerms.filter(t => t.length > 2)
        : query.toLowerCase().split(/\s+/).filter(t => t.length > 2);

      // Phase 1: Primary search — render results IMMEDIATELY
      expandPromise = preserveFilters
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
        // Specificity weighting so the OR fallback leads with the rare on-intent
        // term, not the ubiquitous typed word. Closes the typed-word exemption:
        // a common word the user typed is still searched (recall) but no longer
        // floods the head of the list.
        const orSpecificity = {
          enabled: CONFIG.SPECIFICITY_WEIGHTING,
          corpusTotal: subwordCorpusSize(activeFilters),
          strongMatched: false,
        };
        const orResults = await searchAndLoadParallel(orQueries, activeFilters, searchQuery, orSpecificity);
        // Only adopt the specificity signal if this search is still current — a
        // newer doSearch() resets hadSpecificMatch, and a late-resolving stale OR
        // fallback must not repollute it.
        if (version === searchVersion && orSpecificity.strongMatched) hadSpecificMatch = true;
        allScoredResults = mergeResults(allScoredResults, orResults);
        applyAgreementBonus(allScoredResults, orResults);
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

      // Paint the results BEFORE computing facet counts. The count pass below is a
      // second full Pagefind search, and on a production-size index that is the
      // dominant cost of the whole cycle (measured: results ready at 24,558 ms,
      // first paint at 35,626 ms — the user waited 11 seconds for numbers in the
      // filter panel while the list they asked for sat finished in memory).
      //
      // The filter panel is deliberately NOT repainted in the gap. renderFilters()
      // is count-driven — under the default hideEmptyFacets policy a zero-count
      // value is hidden entirely — so rendering it against counts that have not
      // been updated yet would show the PREVIOUS query's visible value set and then
      // reshuffle when the real counts land. Holding the last painted panel until
      // then introduces no new visual state: it never flashes empty, and on the
      // first search of a page load the panel simply appears when the counts
      // arrive, exactly as it did before this reorder.
      renderResults(false, 'search');
    } finally {
      // Only the owner releases the window. If a newer cycle started while
      // this one was in flight it owns the window now, and releasing it here
      // would unsuppress the suggest path in the middle of that cycle's paint.
      // `finally` without `catch` still rethrows, so a caller sees a failed
      // search exactly as it did before any of this existed.
      if (paintingVersion === version) paintingVersion = 0;
    }

    // Counts are a fixed property of the search: compute them once, only when
    // the typed query changes (!preserveFilters). A facet toggle, sort, or
    // load-more (preserveFilters === true) reuses the stored counts so the panel
    // numbers never move on click. They are folded over exactly once more, in
    // mergeExpandedSearchResults(), when AI expansion changes the list they
    // describe.
    if (!preserveFilters) {
      const counts = await computeQueryFacetCounts(searchQuery, activeFilters, meaningfulTerms, isForcedPhrase);
      // The count pass is async, so a newer doSearch() may have superseded this
      // cycle while it ran. Late counts from an abandoned query must neither
      // overwrite the current ones nor repaint the panel.
      if (version !== searchVersion) {
        debugLog('[scolta:search] Discarding stale facet counts (version', version, 'vs current', searchVersion, ')');
      } else {
        queryFacetCounts = counts;
        renderFilters();
      }
    } else {
      // Nothing to wait for: preserveFilters reuses the stored counts.
      renderFilters();
    }

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
        // Apply LLM-detected filter intent by merging into activeFilters —
        // but only the hints that survive the recall guard; the rest become
        // offered (clickable, not applied) chips.
        llmAppliedFilters = {};
        offeredLlmFilters = {};
        if (filterHint) {
          const canonicalHint = {};
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
              canonicalHint[dim] = canonicalVal;
            }
          }

          // Probe with the same term union the merged search will run, under
          // the filters already active (including the language auto-filter),
          // so the guard's baseline is exactly what the user would see
          // without the hint.
          const probeTerms = [query];
          for (const t of (expandedTerms || [])) {
            if (typeof t === 'string' && t && !probeTerms.includes(t)) probeTerms.push(t);
          }
          const partition = await partitionFilterHintByRecall(
            canonicalHint, probeTerms, activeFilters, CONFIG);

          // The probes are async — a newer search may have started meanwhile.
          if (version !== searchVersion) return;

          offeredLlmFilters = partition.offered;
          for (const [dim, val] of Object.entries(partition.applied)) {
            llmAppliedFilters[dim] = val;
            if (!activeFilters[dim]) {
              activeFilters[dim] = new Set();
            }
            activeFilters[dim].add(val);
          }
        }
      }
      renderExpandedTerms(expandedTerms, query);
      // meaningfulTerms / isForcedPhrase ride along so the post-expansion count
      // pass can reproduce the typed query's own counts under the same
      // AND-or-OR-union mode decision this cycle made, without re-deriving
      // either from the query string.
      await mergeExpandedSearchResults(expandedTerms, query, searchQuery, preserveFilters, version, currentSortOverride, subjectTerms, { meaningfulTerms, isForcedPhrase });

      if (version !== searchVersion) return;

      // If mergeExpandedSearchResults returned early (no valid terms, no sort override),
      // it did not call renderResults(); show the final state now.
      if (allScoredResults.length === 0) {
        renderResults(false, 'expansion');
      }

      renderSortIndicator(currentSortOverride);
      renderFilterBadges();

      const expandedLabel = expandedTerms
        ? expandedTerms.filter(t => t.toLowerCase() !== query.toLowerCase())
        : [];
      // Deliberately not awaited — the summary is allowed to land after this
      // chain settles — which is exactly why it needs its own catch. Nothing
      // is chained onto the promise it returns, so a rejection from it does
      // NOT reach the .catch below; it becomes an unhandled rejection and the
      // reserved skeleton shimmers forever. summarizeResults() handles its own
      // fetch failures, but the work before that fetch (candidate selection,
      // context assembly) is outside them, and on a malformed result set a
      // throw there used to strand the slot with no way back.
      summarizeResults(query, allScoredResults, expandedLabel, sortHint, filterHint, activeFilters)
        .catch(e => {
          if (version !== searchVersion) return;
          console.warn('[scolta:summarize] failed before the request:', e);
          releaseSummarySlot();
        });
    }).catch(e => {
      // The slot is reserved from the result paint, so anything that throws in
      // the expansion phase — between that paint and the summarize call — now
      // leaves a skeleton shimmering forever instead of failing silently.
      // Collapse it and say why. This covers the awaited work above only; the
      // un-awaited summarizeResults() call carries its own catch, for the
      // reason given there. Only this cycle's slot: a newer search owns the
      // panel once it starts.
      if (version !== searchVersion) return;
      console.warn('[scolta:search] expansion phase failed:', e);
      releaseSummarySlot();
    });
  }

  function clearSearch() {
    if (abortController) abortController.abort();
    abortController = null;
    cancelPreload();
    cancelSuggest();
    closeSuggestions();
    paintingVersion = 0;
    els.queryInput.value = '';
    els.searchClear.style.display = "none";
    els.layout.style.display = "none";
    els.expandedTerms.style.display = "none";
    releaseSummarySlot();
    els.noResults.style.display = "none";
    els.sortIndicator.style.display = "none";
    els.sortIndicator.innerHTML = '';
    if (els.filterIndicator) {
      els.filterIndicator.style.display = "none";
      els.filterIndicator.innerHTML = '';
    }
    allScoredResults = [];
    displayedCount = 0;
    paintedEntries = [];
    paintedHighlightSignature = null;
    conversationMessages = [];
    followUpCount = 0;
    activeFilters = {};
    currentSortOverride = null;
    llmAppliedFilters = {};
    offeredLlmFilters = {};
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

  // --- Result renderer registration ---
  //
  // Register a function that returns the markup for one result, replacing the
  // built-in card. See the documented contract on Scolta.setResultRenderer()
  // below; this is the per-instance form and takes precedence over the global
  // one for this instance only. Pass null to go back to the built-in card.
  function setResultRenderer(fn) {
    if (fn !== null && fn !== undefined && typeof fn !== 'function') {
      throw new TypeError('[scolta] setResultRenderer expects a function or null');
    }
    instanceResultRenderer = fn || null;
    return instanceResultRenderer;
  }

  function activeResultRenderer() {
    return instanceResultRenderer || globalResultRenderer;
  }

  // --- Suggestion renderer registration ---
  //
  // Register a function that returns the inner markup for one suggestion row,
  // replacing the built-in row. See the documented contract on
  // Scolta.setSuggestionRenderer() below; this is the per-instance form and
  // takes precedence over the global one for this instance only. Pass null to
  // go back to the built-in row.
  function setSuggestionRenderer(fn) {
    if (fn !== null && fn !== undefined && typeof fn !== 'function') {
      throw new TypeError('[scolta] setSuggestionRenderer expects a function or null');
    }
    instanceSuggestionRenderer = fn || null;
    return instanceSuggestionRenderer;
  }

  function activeSuggestionRenderer() {
    return instanceSuggestionRenderer || globalSuggestionRenderer;
  }

  // --- Render lifecycle events ---
  //
  // Scolta owns the search UI, so a platform that decorates result markup
  // (server-rendered cards, a lazily swapped fragment, behaviours bound to a
  // card) needs to know when that markup is about to be destroyed and when it
  // has been rebuilt. These four events are that seam, and they are the only
  // supported one. Nothing here knows about any particular host platform.
  //
  //   scolta:before-results-render  { container, reason }
  //   scolta:results-rendered       { container, results, rendered, reused, appended, query }
  //   scolta:before-filters-render  { container }
  //   scolta:filters-rendered       { container }
  //
  // `container` is always the element being written and is identical to
  // event.target. The events bubble, so one listener on the mount point or on
  // document sees every render. They are deliberately NOT cancellable: a render
  // a consumer could veto would make every state assumption downstream
  // conditional, for a use case nobody has yet.
  function emitLifecycle(target, name, detail) {
    if (!target || typeof target.dispatchEvent !== 'function') return;
    if (typeof global.CustomEvent !== 'function') return;
    try {
      target.dispatchEvent(new global.CustomEvent(name, {
        bubbles: true,
        cancelable: false,
        detail: detail,
      }));
    } catch (e) {
      // A broken listener must never take the render down with it.
      console.warn('[scolta] lifecycle listener failed for', name, e);
    }
  }

  function emitBeforeResults(reason) {
    emitLifecycle(els.results, 'scolta:before-results-render', {
      container: els.results,
      reason: reason,
    });
  }

  // `results` is everything in the DOM after this write, in DOM order.
  // `rendered` is the slice this write produced. `reused` lists the results
  // whose DOM node was carried over rather than rebuilt — a superset check on
  // `appended` for consumers that initialise nodes once. `appended` is true only
  // on the additive "show more" path.
  function emitResultsRendered(results, rendered, reused, appended) {
    emitLifecycle(els.results, 'scolta:results-rendered', {
      container: els.results,
      results: results,
      rendered: rendered,
      reused: reused,
      appended: appended,
      query: currentQuery,
    });
  }

  function emitBeforeFilters() {
    emitLifecycle(els.filters, 'scolta:before-filters-render', { container: els.filters });
  }

  function emitFiltersRendered() {
    emitLifecycle(els.filters, 'scolta:filters-rendered', { container: els.filters });
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
      emitBeforeFilters();
      container.innerHTML = "";
      els.layout.classList.remove("has-filters");
      emitFiltersRendered();
      return;
    }

    els.layout.classList.add("has-filters");
    // hideEmptyFacets (top-level instance config, default true) governs the
    // zero-count policy. Default: a value matching none of this query's results
    // is hidden, and a dimension whose values are all hidden drops its whole
    // group — mainstream faceted-search UX, and the reason the social feed's tag
    // dimension (hundreds of hashtags, all but a few zero for any query) no
    // longer buries the useful values under dead ones. Opt-out (set false to
    // restore the prior behavior): every taxonomy value is rendered, a zero-
    // count one as a disabled (0) checkbox, so the value list stays positionally
    // fixed. Under either policy an active value is always visible and
    // uncheckable even at zero, so the user can remove it. The value list is
    // sorted alphabetically (never by count) and counts are fixed per typed
    // query, so ordering and the visible set stay stable across facet clicks.
    const hideEmptyFacets = !(instanceConfig && instanceConfig.hideEmptyFacets === false);
    let html = "";
    for (const dim of dims) {
      const dimFilters = activeFilters[dim] || new Set();
      const dimCounts = queryFacetCounts[dim] || {};
      // Values come from the taxonomy, sorted alphabetically by display value —
      // never by count, which would reorder as counts change. The full value
      // list is fixed across searches and facet clicks.
      const vals = Object.keys(taxonomy[dim]).sort(
        (a, b) => filterDisplayValue(dim, a).localeCompare(filterDisplayValue(dim, b))
      );
      let itemsHtml = "";
      for (const val of vals) {
        const count = dimCounts[val] ?? 0;
        const isActive = dimFilters.has(val);
        const isEmpty = count === 0 && !isActive;
        // Skip the value entirely under the default policy; under the opt-out,
        // fall through and render it disabled with its (0) count.
        if (isEmpty && hideEmptyFacets) continue;
        const checked = isActive ? "checked" : "";
        const activeClass = isActive ? " active" : "";
        const disabled = isEmpty ? " disabled" : "";
        itemsHtml += `<label class="scolta-filter-item${activeClass}">
          <input type="checkbox" value="${escapeAttr(val)}" ${checked}${disabled}
                 data-scolta-filter-dim="${escapeAttr(dim)}" data-scolta-filter-val="${escapeAttr(val)}">
          ${escapeHtml(filterDisplayValue(dim, val))} <span class="scolta-filter-count">(${count})</span>
        </label>`;
      }
      // Skip a dimension whose values are all hidden for this query — an empty
      // group is just a dangling header. Under the opt-out itemsHtml is never
      // empty, so every dimension with values keeps its group.
      if (itemsHtml === "") continue;
      html += `<div class="scolta-filter-group"><h3>${escapeHtml(filterDimLabel(dim))}</h3>${itemsHtml}</div>`;
    }
    emitBeforeFilters();
    container.innerHTML = html;
    emitFiltersRendered();
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

  // Identity of a scored result for DOM reconciliation. deduplicateByTitle() has
  // already collapsed near-duplicates by the time a list is painted, so the URL
  // a card links to is unique within it; the title is the fallback for a
  // fragment carrying no URL at all.
  function resultKey(scored) {
    const data = (scored && scored.data) || {};
    const meta = data.meta || {};
    return String(meta.url || data.url || meta.title || '');
  }

  // The built-in result card. Every value it interpolates arrives pre-escaped in
  // `parts`, which is the same object handed to a platform renderer as `ctx` —
  // so the markup below IS the reference implementation of the renderer
  // contract, and a platform that wants the default look plus one extra element
  // can reproduce it exactly without redoing any escaping.
  function buildDefaultCard(parts) {
    return `<div class="scolta-result-card">
        <a class="scolta-result-title" href="${parts.safeUrl}" target="_blank" rel="noopener"
           title="${parts.titleAttr}">${parts.titleHtml}</a>
        <div class="scolta-result-meta">
          ${parts.siteHtml ? `<span class="scolta-site-badge">${parts.siteHtml}</span>` : ""}
          ${parts.dateHtml ? `<span class="scolta-result-date">${parts.dateHtml}</span>` : ""}
        </div>
        <a class="scolta-result-url" href="${parts.safeUrl}" target="_blank" rel="noopener">${parts.urlText}</a>
        <div class="scolta-result-excerpt">${parts.excerptHtml}</div>
      </div>`;
  }

  // Build the markup for one result: the registered platform renderer if there
  // is one, the built-in card otherwise. A renderer that returns anything other
  // than a string — null, undefined, a mistake — falls back to the built-in card
  // for THAT result only, so a platform able to render some entity types and not
  // others does not have to render any of them.
  function buildResultHtml(scored, index, renderer, CONFIG) {
    const data = scored.data;
    const title = data.meta?.title || "Untitled";
    const url = data.meta?.url || resolveUrl(data.url || '') || data.url || "#";
    const site = data.meta?.site || "";
    const date = data.meta?.date || "";
    const excerpt = truncateExcerpt(data.excerpt || "", CONFIG.EXCERPT_LENGTH);
    const safeTitle = escapeHtml(stripHtml(title));
    const displayTitle = safeTitle.length > 90 ? safeTitle.substring(0, 87) + "\u2026" : safeTitle;

    const parts = {
      index: index,
      // Raw user input, NOT html-escaped: it is here so a renderer can build a
      // request URL or compare terms, not to be pasted into markup. Every value
      // whose name ends in Html/Attr/Text, plus safeUrl, is already escaped
      // exactly as the built-in card escapes it.
      query: currentQuery,
      highlightTerms: allHighlightTerms.slice(),
      excerptHtml: highlightTerms(excerpt),
      titleHtml: highlightTerms(displayTitle),
      titleAttr: escapeAttr(stripHtml(title)),
      // URLs come from index metadata; attribute-escaped and with non-http(s)
      // schemes neutralized so a poisoned document can't plant a javascript: link.
      safeUrl: sanitizeUrlAttr(url),
      urlText: escapeHtml(url),
      siteHtml: site ? escapeHtml(site) : "",
      dateHtml: date ? escapeHtml(date) : "",
      score: scored.score,
    };

    if (renderer) {
      let out = null;
      try {
        out = renderer(data, parts);
      } catch (e) {
        console.warn('[scolta] result renderer threw; falling back to the built-in card', e);
        out = null;
      }
      if (typeof out === 'string') return out;
    }
    return buildDefaultCard(parts);
  }

  // Parse one result's markup into detached nodes. <template> rather than a
  // detached div because a platform renderer is free to return markup a div
  // cannot host (a <tr>, say) and free to return more than one top-level node,
  // so a result is a GROUP of nodes, not necessarily a single element.
  function parseResultNodes(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = html;
    return Array.prototype.slice.call(tpl.content.childNodes);
  }

  function renderResults(isExpanded, renderReason) {
    isExpanded = isExpanded || false;
    const reason = renderReason || 'search';
    const CONFIG = getInstanceConfig();
    const container = els.results;
    const header = els.resultsHeader;
    const noResults = els.noResults;
    const loadMore = els.loadMore;

    const filtered = allScoredResults;

    if (filtered.length === 0) {
      if (expansionInFlight) {
        // Phase 1 found no matches, but the AI query-expansion request is still
        // in flight and may yet surface results (e.g. "rama" expanding to terms
        // that match). Show a neutral in-progress state rather than (a) blanking
        // the panel — the previous behavior, which left it empty for the entire
        // duration of the async expand round-trip (observed as a multi-second
        // blank) — or (b) flashing "No results found." that then disappears once
        // expansion adds hits. The terminal state (results or "No results
        // found.") is rendered by the final renderResults() call after the
        // expand promise settles.
        emitBeforeResults('loading');
        container.innerHTML = '<p class="scolta-searching">Searching…</p>';
        paintedEntries = [];
        paintedHighlightSignature = null;
        header.innerHTML = "";
        noResults.style.display = "none";
        loadMore.style.display = "none";
        emitResultsRendered([], [], [], false);
        return;
      }
      emitBeforeResults(reason);
      container.innerHTML = "";
      paintedEntries = [];
      paintedHighlightSignature = null;
      header.innerHTML = "";
      noResults.style.display = "block";
      loadMore.style.display = "none";
      emitResultsRendered([], [], [], false);
      return;
    }

    noResults.style.display = "none";

    // Reserve the summary slot in the same frame this paint happens in.
    // Waiting until the summarize call would put the box in later, on its own,
    // and push this list down — which is the shift. It is a sibling of
    // #scolta-results and touches nothing in the results write path below.
    reserveSummarySlot();

    const startIndex = displayedCount;
    const appended = startIndex > 0;
    const showing = Math.min(startIndex + CONFIG.RESULTS_PER_PAGE, filtered.length);
    const expandLabel = isExpanded ? ' (with expanded terms)' : '';
    const filterLabel = Object.keys(activeFilters).length > 0
      ? ' in ' + Object.entries(activeFilters)
          .filter(([, vals]) => vals instanceof Set && vals.size > 0)
          .map(([dim, vals]) => [...vals].map(v => filterDisplayValue(dim, v)).join(', '))
          .join('; ')
      : '';
    // The OR fallback is a retrieval mode, not a failure. Only call it out as
    // "no exact matches" when nothing specific was actually found; when a rare,
    // high-specificity term did match, the list is genuinely relevant, so frame
    // it as best matches rather than crying failure over a strong hit.
    const orFallbackLabel = usedOrFallback
      ? (hadSpecificMatch ? ' — showing best matches' : ' — no exact matches found, showing partial matches')
      : '';
    const resultNoun = filtered.length === 1 ? 'result' : 'results';
    header.innerHTML = `<span>${filtered.length.toLocaleString()} ${resultNoun} for "${escapeHtml(displayQuery(currentQuery))}"${filterLabel}${expandLabel}${orFallbackLabel}</span>
                        <span>Showing ${showing}</span>`;

    const renderer = activeResultRenderer();
    // Cheap identity of the current highlight set. Expansion grows
    // allHighlightTerms, so a built-in card painted before it carries <mark>
    // spans that no longer match the terms in play.
    const highlightSignature = allHighlightTerms.join(' ');

    if (appended) {
      // "Show more" is strictly additive: existing nodes are never re-parsed,
      // so a platform's already-initialised cards survive untouched. That is
      // what `appended: true` on the event promises.
      // Parse each result's markup on its own so the node-to-result grouping
      // stays exact: a platform renderer may emit any number of top-level nodes,
      // and the renderer is called exactly once per result either way.
      const addedEntries = [];
      for (let i = startIndex; i < showing; i++) {
        addedEntries.push({
          key: resultKey(filtered[i]),
          nodes: parseResultNodes(buildResultHtml(filtered[i], i, renderer, CONFIG)),
        });
      }
      emitBeforeResults('append');
      const addFrag = document.createDocumentFragment();
      for (const entry of addedEntries) {
        for (const node of entry.nodes) addFrag.appendChild(node);
      }
      container.appendChild(addFrag);
      paintedEntries = paintedEntries.concat(addedEntries);
      paintedHighlightSignature = highlightSignature;
      displayedCount = showing;
      loadMore.style.display = (showing < filtered.length) ? "block" : "none";
      emitResultsRendered(
        filtered.slice(0, showing),
        filtered.slice(startIndex, showing),
        [],
        true,
      );
      return;
    }

    // Full repaint. Reconcile by result identity rather than blowing the
    // container away: after AI query expansion resolves,
    // mergeExpandedSearchResults() repaints from index 0, and on most queries
    // the expansion pass returns the same results in the same order. Rebuilding
    // every node there destroyed whatever a platform had lazily swapped in one
    // to two seconds earlier and made it do the work over again — the entire
    // reason this seam exists.
    //
    // A carried-over node keeps its markup. That is exactly right when a
    // platform renderer owns it, and exactly wrong for the built-in card once
    // the highlight terms have moved, so built-in cards are only reused while
    // the highlight signature is unchanged (a facet toggle or sort change,
    // where every card's content is genuinely identical).
    const reusable = new Map();
    if (renderer || highlightSignature === paintedHighlightSignature) {
      for (const entry of paintedEntries) {
        if (!entry.key || reusable.has(entry.key)) continue;
        // A node the platform detached itself is not ours to move back.
        if (!entry.nodes.length || entry.nodes.some(n => n.parentNode !== container)) continue;
        reusable.set(entry.key, entry.nodes);
      }
    }

    const nextEntries = [];
    const reusedResults = [];
    for (let i = 0; i < showing; i++) {
      const key = resultKey(filtered[i]);
      const carried = key ? reusable.get(key) : undefined;
      if (carried) {
        reusable.delete(key);
        nextEntries.push({ key: key, nodes: carried });
        reusedResults.push(filtered[i]);
      } else {
        nextEntries.push({
          key: key,
          nodes: parseResultNodes(buildResultHtml(filtered[i], i, renderer, CONFIG)),
        });
      }
    }

    emitBeforeResults(reason);
    // Sync the container in place, touching only what actually has to move. A
    // node already sitting where it belongs is left completely alone — not
    // detached and re-attached, which would restart CSS transitions, reload an
    // iframe and fire disconnected/connectedCallback on a custom element, all
    // for a list that did not change. When the expansion pass reorders nothing,
    // this loop performs zero DOM mutations.
    //
    // insertBefore() on a node that is already in the document MOVES it: the
    // browser does not clone or re-parse it, so listeners, lazily swapped server
    // markup and any other platform state ride along through a genuine reorder.
    let cursor = container.firstChild;
    for (const entry of nextEntries) {
      for (const node of entry.nodes) {
        if (cursor === node) {
          cursor = cursor.nextSibling;
        } else {
          container.insertBefore(node, cursor);
        }
      }
    }
    // Whatever the walk did not claim is leaving.
    while (cursor) {
      const leaving = cursor;
      cursor = cursor.nextSibling;
      container.removeChild(leaving);
    }

    paintedEntries = nextEntries;
    paintedHighlightSignature = highlightSignature;
    displayedCount = showing;
    loadMore.style.display = (showing < filtered.length) ? "block" : "none";
    emitResultsRendered(
      filtered.slice(0, showing),
      filtered.slice(startIndex, showing),
      reusedResults,
      false,
    );
  }

  function showMore() {
    const terms = Array.isArray(lastExpandedTerms) ? lastExpandedTerms : lastExpandedTerms?.terms;
    renderResults(terms && terms.length > 0, 'append');
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

    rootEl = root;

    // Build the search UI inside the container.
    //
    // Every top-level node carries data-scolta-scaffold: it marks what this
    // instance owns, so a re-init can remove its own previous UI (duplicate ids
    // would be worse than useless) and destroy() can take out exactly that and
    // nothing else.
    // Search as you type is part of the scaffold only when it is on. With
    // sayt_enabled false the input carries no combobox roles and no dropdown
    // node exists, so the widget is byte-identical to the pre-1.1.0 one.
    const saytCfg = getSaytConfig();
    const comboAttrs = saytCfg.enabled
      ? ` role="combobox" aria-autocomplete="list" aria-haspopup="listbox"`
        + ` aria-expanded="false" aria-controls="scolta-sayt"`
      : '';
    const saytHtml = saytCfg.enabled
      ? `<div class="scolta-sayt" id="scolta-sayt" role="listbox"
                 aria-label="Search suggestions" style="display:none;"
                 data-scolta-scaffold></div>`
      : '';

    const scaffoldHtml = `
      <div class="scolta-search-box" data-scolta-scaffold>
        <div class="scolta-search-input-wrap">
          <input type="text" id="scolta-query" placeholder="Search..."
                 autofocus autocomplete="off"${comboAttrs}>
          <button class="scolta-search-clear" id="scolta-search-clear"
                  style="display:none;" aria-label="Clear search">&times;</button>
          ${saytHtml}
        </div>
        <button class="scolta-search-btn" id="scolta-search-btn">Search</button>
      </div>

      <div id="scolta-expanded-terms" class="scolta-expanded-terms" style="display:none;" data-scolta-scaffold></div>

      <div class="scolta-layout" id="scolta-layout" style="display:none;" data-scolta-scaffold>
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

      <div class="scolta-no-results" id="scolta-no-results" style="display:none;" data-scolta-scaffold>
        <p style="font-size:1.2rem;">No results found.</p>
        <p style="margin-top:0.5rem;">Try different keywords or clear your site filters.</p>
      </div>
    `;

    // Non-destructive mount. init() used to run `root.innerHTML = scaffold`,
    // which destroyed whatever the server had already rendered inside the mount
    // point — and a platform bridge calling Scolta.init() directly bypasses
    // autoInit()'s guard entirely, so any platform attempting server-side
    // rendering into the container silently lost it. It is a trap for exactly
    // the integration this render seam exists to support.
    //
    // Now: a previous Scolta scaffold in this container is removed, and
    // everything else is left where it is with its node identity intact. The
    // scaffold goes in at the top, so the search box still sits above whatever
    // the platform put there.
    //
    // Opt out with data-scolta-replace on the mount element to get the old
    // clear-everything behaviour back. That is a DOM attribute rather than a
    // config key: markup decisions belong on the platform side of the seam, and
    // it keeps the browser config surface — and BrowserConfigParityTest —
    // untouched.
    if (root.hasAttribute('data-scolta-replace')) {
      root.innerHTML = '';
    } else {
      const stale = [];
      for (let i = 0; i < root.children.length; i++) {
        if (root.children[i].hasAttribute('data-scolta-scaffold')) stale.push(root.children[i]);
      }
      for (const node of stale) root.removeChild(node);
    }

    const scaffold = document.createElement('template');
    scaffold.innerHTML = scaffoldHtml;
    scaffoldNodes = Array.prototype.slice.call(scaffold.content.childNodes);
    if (root.firstChild) {
      root.insertBefore(scaffold.content, root.firstChild);
    } else {
      root.appendChild(scaffold.content);
    }

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
      sayt: root.querySelector('#scolta-sayt'),
    };

    // Event listeners.
    els.queryInput.addEventListener("keydown", (e) => {
      // SAYT claims Arrow keys, Escape while the dropdown is open, and Enter
      // while an option is active. Everything else — including Enter with no
      // active option — falls through to the behaviour that shipped before it.
      if (handleSuggestKeydown(e)) return;
      if (e.key === "Enter") doSearch();
    });

    els.queryInput.addEventListener("input", () => {
      els.searchClear.style.display = els.queryInput.value.length > 0 ? "block" : "none";
      schedulePreload(els.queryInput.value);
      scheduleSuggest(els.queryInput.value);
    });

    if (els.sayt) {
      // mousedown, not click: preventing the default here stops the input from
      // losing focus at all, so the option's own click still lands and the blur
      // timer below never has to race it.
      els.sayt.addEventListener("mousedown", (e) => {
        if (e.target.closest('[data-scolta-sayt-index]')) e.preventDefault();
      });

      els.sayt.addEventListener("click", (e) => {
        const optionEl = e.target.closest('[data-scolta-sayt-index]');
        if (!optionEl) return;
        const index = parseInt(optionEl.dataset.scoltaSaytIndex, 10);
        if (!isFinite(index)) return;
        // In navigate mode the option IS an anchor and the browser is already
        // handling this click; only tidy up. Any other mode acts explicitly.
        if (optionEl.hasAttribute('href')) {
          closeSuggestions();
          cancelSuggest();
          return;
        }
        e.preventDefault();
        actOnSuggestion(index);
      });

      els.sayt.addEventListener("mouseover", (e) => {
        const optionEl = e.target.closest('[data-scolta-sayt-index]');
        if (!optionEl) return;
        const index = parseInt(optionEl.dataset.scoltaSaytIndex, 10);
        if (isFinite(index)) setActiveSuggestion(index);
      });

      // Close on blur, but only after a beat: a click that started inside the
      // dropdown must still land.
      els.queryInput.addEventListener("blur", () => {
        if (suggestBlurTimer) clearTimeout(suggestBlurTimer);
        suggestBlurTimer = setTimeout(() => {
          suggestBlurTimer = null;
          closeSuggestions();
        }, 150);
      });
    }

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
      // Offered filter chip → apply the recall-guard-declined hint explicitly
      const filterOfferEl = e.target.closest("[data-scolta-filter-offer-dim]");
      if (filterOfferEl) {
        applyOfferedLlmFilter(filterOfferEl.dataset.scoltaFilterOfferDim, filterOfferEl.dataset.scoltaFilterOfferVal);
        return;
      }
      // Summary "Show more" / "Show less"
      if (e.target.closest("[data-scolta-summary-toggle]")) {
        toggleSummaryExpanded();
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
  // If init failed to find the container it never cached any DOM reference.
  // (The old check — root.hasChildNodes() — no longer distinguishes success from
  // failure now that init() leaves pre-existing platform markup in place.)
  if (!rootEl || !els.results) {
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
    setResultRenderer,
    setSuggestionRenderer,
    destroy: function() {
      if (abortController) abortController.abort();
      // Timers outlive the DOM they would write to; cancelSuggest() clears the
      // debounce, the enrichment idle timer and the blur timer in one place.
      cancelPreload();
      cancelSuggest();
      // Remove only what init() inserted. Clearing the whole mount point would
      // take out platform markup this instance never owned — the same bug the
      // non-destructive init() fixes at the other end of the lifecycle.
      for (const node of scaffoldNodes) {
        if (node.parentNode) node.parentNode.removeChild(node);
      }
      scaffoldNodes = [];
      paintedEntries = [];
      paintedHighlightSignature = null;
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

  /**
   * Register the platform's result renderer.
   *
   *   Scolta.setResultRenderer(function (data, ctx) { return html || null; });
   *
   * Called once per result in place of the built-in card. `data` is the raw
   * Pagefind fragment object. `ctx` carries:
   *
   *   index          — position of this result in the full list
   *   query          — the current query, RAW (not escaped): it is here to build
   *                    a request or compare terms, not to be pasted into markup
   *   highlightTerms — array of raw highlight terms, same caveat
   *   excerptHtml    — the escaped, <mark>-wrapped excerpt the built-in card
   *                    would have shown, ready to drop into a slot
   *   titleHtml      — the escaped, truncated, <mark>-wrapped title text
   *   titleAttr      — attribute-escaped full title, for title="…"
   *   safeUrl        — attribute-escaped URL with non-http(s) schemes neutralized
   *   urlText        — html-escaped URL, as link text
   *   siteHtml       — html-escaped site badge value, or ""
   *   dateHtml       — html-escaped date, or ""
   *   score          — this result's score
   *
   * Return an HTML string, or null to fall back to the built-in card for that
   * one result — the right answer when a platform can render some result types
   * and not others. A renderer that throws also falls back, with a console
   * warning; one bad result never takes the list down.
   *
   * Two parts of the contract matter:
   *
   *   - ESCAPING. The returned string is inserted as markup, so from that point
   *     the platform owns its own escaping. Every ctx value whose name ends in
   *     Html/Attr/Text, plus safeUrl, is already escaped exactly as the built-in
   *     card escapes it, so composing from those is the safe path AND the easy
   *     one. `query` and `highlightTerms` are raw; escape them yourself.
   *   - DELEGATED HANDLERS. Scolta's click and change handlers are bound once on
   *     the mount point and dispatch on data-scolta-* attributes, so platform
   *     markup carrying those attributes keeps working with no re-binding after
   *     any render.
   *
   * Pass null to restore the built-in card. This is deliberately a registration
   * function, not a config key: a function cannot travel through
   * ScoltaConfig::toBrowserConfig() and the platform's settings JSON, and a
   * browser config key PHP never emits would be dead weight the parity test has
   * to be told to ignore.
   *
   * Applies to every instance that has not registered its own renderer via
   * instance.setResultRenderer(); safe to call before Scolta.init().
   */
  global.Scolta.setResultRenderer = function(fn) {
    if (fn !== null && fn !== undefined && typeof fn !== 'function') {
      throw new TypeError('[scolta] Scolta.setResultRenderer expects a function or null');
    }
    globalResultRenderer = fn || null;
  };

  /**
   * Register the platform's suggestion renderer.
   *
   *   Scolta.setSuggestionRenderer(function (suggestion, ctx) { return html || null; });
   *
   * Called once per row of the search-as-you-type dropdown in place of the
   * built-in row. `suggestion` is the same object the
   * `scolta:suggestions-rendered` event carries:
   *
   *   type    — "title" for an index match, "recent" for a stored search
   *   title   — the suggestion text, RAW
   *   url     — the result's URL, RAW; "" on a recent search
   *   safeUrl — attribute-escaped URL with non-http(s) schemes neutralized
   *   excerpt — the fragment excerpt, RAW; "" on a recent search
   *   meta    — the fragment's metadata map (thumbnail, entity id, anything the
   *             index carries), RAW; {} on a recent search
   *
   * `ctx` carries:
   *
   *   index       — position of this suggestion in the dropdown
   *   query       — the prefix being suggested on, RAW: it is here to build a
   *                 request or compare terms, not to be pasted into markup
   *   titleHtml   — the escaped title the built-in row would have shown
   *   excerptHtml — the escaped, truncated excerpt, or "" on a recent search
   *   safeUrl     — attribute-escaped URL with non-http(s) schemes neutralized,
   *                 the same value the option's href carries in navigate mode;
   *                 "" on a recent search, which has no destination
   *
   * The naming is the result renderer's: every ctx value whose name ends in
   * Html, plus safeUrl, is pre-escaped, and everything else is raw. There is no
   * highlightTerms here because the suggest path does not highlight — the terms
   * on the instance belong to the committed search cycle, not to this one.
   *
   * Return an HTML string, or null to fall back to the built-in row for that
   * one suggestion. A renderer that throws also falls back, with a console
   * warning; one bad row never takes the dropdown down.
   *
   * What the returned string owns is the INSIDE of the option element. Scolta
   * keeps the element itself — role="option", the stable id the input's
   * aria-activedescendant points at, aria-selected, data-scolta-sayt-index, and
   * in navigate mode the anchor and its sanitized href — because those are the
   * ARIA combobox and keyboard contract, and a renderer that forgot one would
   * break arrow-key navigation and screen-reader announcement silently.
   *
   * ESCAPING. The returned string is inserted as markup, so from that point the
   * platform owns its own escaping. `ctx.titleHtml` and `ctx.excerptHtml` are
   * already escaped exactly as the built-in row escapes them; `suggestion.title`,
   * `suggestion.url`, `suggestion.excerpt`, every `suggestion.meta` value and
   * `ctx.query` are raw index or visitor content, so escape them yourself.
   *
   * Pass null to restore the built-in row. Like setResultRenderer this is
   * deliberately a registration function, not a config key: a function cannot
   * travel through ScoltaConfig::toBrowserConfig() and the platform's settings
   * JSON, and a browser config key PHP never emits would be dead weight the
   * parity test has to be told to ignore.
   *
   * Applies to every instance that has not registered its own renderer via
   * instance.setSuggestionRenderer(); safe to call before Scolta.init().
   */
  global.Scolta.setSuggestionRenderer = function(fn) {
    if (fn !== null && fn !== undefined && typeof fn !== 'function') {
      throw new TypeError('[scolta] Scolta.setSuggestionRenderer expects a function or null');
    }
    globalSuggestionRenderer = fn || null;
  };

  // Backward-compatible init: creates a default instance from window.scolta.
  global.Scolta.init = function(containerSelector) {
    if (global.Scolta.defaultInstance) return; // already initialized
    global.Scolta.defaultInstance = createInstance(
      containerSelector || '#scolta-search',
      global.scolta
    );
    // Expose instance methods on Scolta for backward compat. setResultRenderer
    // and setSuggestionRenderer are deliberately NOT among them: the Scolta.*
    // forms are the global registrations, which must keep working when they are
    // called before init() — the usual order, since a platform registers on
    // script load.
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
      // Guard against double-init, not against pre-existing markup. The old
      // !hasChildNodes() test also blocked auto-init on any mount point holding
      // server-rendered content, which is why platform bridges call
      // Scolta.init() directly and bypass this guard. init() is non-destructive
      // now, so the only thing worth refusing is a second scaffold.
      if (container && !container.querySelector('[data-scolta-scaffold]')) {
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
