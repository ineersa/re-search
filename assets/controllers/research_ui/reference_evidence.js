/**
 * @typedef {object} TraceCallLike
 * @property {string} label
 * @property {string} [url]
 * @property {string} [result]
 * @property {number|null} [sequence]
 * @property {number|null} [turnNumber]
 */

/**
 * @typedef {object} SourceLike
 * @property {number|null} sequence
 * @property {number|null} turnNumber
 * @property {Map<number, string>} lineMap
 * @property {boolean} hasLineMap
 */

/**
 * @param {string} markdown
 * @param {TraceCallLike[]} toolCalls
 * @returns {Array<{
 *     url: string,
 *     markers: string[],
 *     markerIds: number[],
 *     sourceSequence: number|null,
 *     sourceTurnNumber: number|null,
 * }>}
 */
export function buildReferenceEvidence(markdown, toolCalls) {
    if (!markdown || markdown.trim().length === 0) {
        return [];
    }

    const references = parseReferencesFromMarkdown(markdown);
    if (references.length === 0) {
        return [];
    }

    const sourceMap = new Map();
    const sourceDomainMap = new Map();

    toolCalls
        .filter((call) => typeof call.url === 'string' && call.url.trim().length > 0)
        .forEach((call) => {
            const lineMap = typeof call.result === 'string' ? extractLineMap(call.result) : new Map();
            const source = {
                sequence: Number.isInteger(call.sequence) ? call.sequence : null,
                turnNumber: Number.isInteger(call.turnNumber) ? call.turnNumber : null,
                lineMap,
                hasLineMap: lineMap.size > 0,
            };

            addSource(sourceMap, call.url, source);
            addSource(sourceMap, normalizeUrl(call.url), source);

            const domain = extractDomain(call.url);
            if (domain) {
                addSource(sourceDomainMap, domain, source);
            }
        });

    const groups = new Map();
    references.forEach((reference) => {
        const key = reference.url;
        if (!groups.has(key)) {
            groups.set(key, {
                url: reference.url,
                markers: [],
                markerIds: [],
                sourceSequence: null,
                sourceTurnNumber: null,
            });
        }

        const group = groups.get(key);
        group.markers.push(reference.marker);
        group.markerIds.push(reference.id);

        if (group.sourceSequence == null) {
            const exactCandidates = dedupeSources([
                ...(sourceMap.get(reference.url) || []),
                ...(sourceMap.get(normalizeUrl(reference.url)) || []),
            ]);

            const domain = extractDomain(reference.url);
            const domainCandidates = domain ? dedupeSources(sourceDomainMap.get(domain) || []) : [];
            const searchPool = exactCandidates.length > 0 ? exactCandidates : domainCandidates;

            if (searchPool.length > 0) {
                const withSpanMatch = searchPool.find((source) => hasAnySpanMatch(source.lineMap, reference.spans));
                const withLineMap = searchPool.find((source) => source.hasLineMap);
                const chosen = withSpanMatch || withLineMap || searchPool[0];
                group.sourceSequence = Number.isInteger(chosen.sequence) ? chosen.sequence : null;
                group.sourceTurnNumber = Number.isInteger(chosen.turnNumber) ? chosen.turnNumber : null;
            }
        }
    });

    return Array.from(groups.values()).sort((a, b) => {
        const left = Math.min(...a.markerIds);
        const right = Math.min(...b.markerIds);

        return left - right;
    });
}

/**
 * @param {string} markdown
 */
function parseReferencesFromMarkdown(markdown) {
    const referencesSection = extractReferencesSection(markdown);
    const fromSection = parseReferencesFromText(referencesSection);
    if (fromSection.length > 0) {
        return fromSection;
    }

    const fromTail = parseReferencesFromText(extractReferenceTail(markdown));
    if (fromTail.length > 0) {
        return fromTail;
    }

    return parseReferencesFromText(markdown);
}

/** @param {string} text */
function parseReferencesFromText(text) {
    if (!text) {
        return [];
    }

    const references = [];
    const seen = new Set();

    for (const rawLine of text.split(/\r?\n/u)) {
        let line = rawLine.trim();
        if (!line) {
            continue;
        }

        line = line.replace(/^[-*•]\s+/u, '');

        const markerMatch = line.match(/^(?<marker>\[[0-9]+\]|\([0-9]+\)|[0-9]+|[⁰¹²³⁴⁵⁶⁷⁸⁹]+)[\.)]?\s+(?<rest>.+)$/u);
        if (!markerMatch?.groups?.marker || !markerMatch.groups.rest) {
            continue;
        }

        const marker = markerMatch.groups.marker.replace(/^[\[(]|[\])]$/gu, '');
        const id = referenceIdFromMarker(marker);
        if (id == null) {
            continue;
        }

        const urlMatch = markerMatch.groups.rest.trim().match(/^<?(?<url>https?:\/\/[^\s>]+)>?(?<tail>.*)$/iu);
        if (!urlMatch?.groups?.url) {
            continue;
        }

        const url = urlMatch.groups.url.replace(/[.,;)]+$/u, '');
        const lineInfo = extractLineInfo(urlMatch.groups.tail || '');
        const spans = parseLineSpans(lineInfo);
        const dedupeKey = `${id}|${url}|${lineInfo}`;
        if (seen.has(dedupeKey)) {
            continue;
        }

        seen.add(dedupeKey);
        references.push({ id, marker, url, spans });
    }

    return references.sort((a, b) => a.id - b.id);
}

/** @param {string} markdown */
function extractReferencesSection(markdown) {
    const match = markdown.match(/(?:^|\n)#{1,6}\s+(?:References|Sources|Citations|Bibliography|Works\s+Cited)\b[\s\S]*$/iu);
    if (match && match[0]) {
        return match[0];
    }

    const fallback = markdown.match(/(?:^|\n)(?:References|Sources|Citations|Bibliography|Works\s+Cited)\s*:?\s*[\s\S]*$/iu);

    return fallback ? fallback[0] : '';
}

/** @param {string} markdown */
function extractReferenceTail(markdown) {
    const lines = markdown.split(/\r?\n/u);

    return lines.slice(Math.max(0, lines.length - 80)).join('\n');
}

/** @param {string} tail */
function extractLineInfo(tail) {
    if (!tail) {
        return '';
    }

    const parenMatch = tail.match(/\((?<inside>[^)]*)\)/u);
    if (parenMatch?.groups?.inside && (/(?:\bline\b|\blines\b)/iu.test(parenMatch.groups.inside) || /L\d+/iu.test(parenMatch.groups.inside))) {
        return parenMatch.groups.inside;
    }

    if (/(?:\bline\b|\blines\b)/iu.test(tail) || /L\d+/iu.test(tail)) {
        return tail;
    }

    return '';
}

/** @param {string} lineInfo */
function parseLineSpans(lineInfo) {
    if (!lineInfo) {
        return [];
    }

    const spans = [];
    const pattern = /(?:^|[\s,;])L?(?<start>\d+)(?:\s*-\s*L?(?<end>\d+))?(?=$|[\s,;])/giu;
    for (const match of lineInfo.matchAll(pattern)) {
        const start = Number.parseInt(match.groups?.start, 10);
        const end = match.groups?.end ? Number.parseInt(match.groups.end, 10) : start;
        if (!Number.isInteger(start) || !Number.isInteger(end)) {
            continue;
        }

        spans.push({ startLine: Math.min(start, end), endLine: Math.max(start, end) });
    }

    return spans;
}

/** @param {string} marker */
function referenceIdFromMarker(marker) {
    if (/^\d+$/u.test(marker)) {
        return Number.parseInt(marker, 10);
    }

    const map = {
        '⁰': '0',
        '¹': '1',
        '²': '2',
        '³': '3',
        '⁴': '4',
        '⁵': '5',
        '⁶': '6',
        '⁷': '7',
        '⁸': '8',
        '⁹': '9',
    };

    let digits = '';
    for (const ch of marker) {
        if (!map[ch]) {
            return null;
        }
        digits += map[ch];
    }

    return digits ? Number.parseInt(digits, 10) : null;
}

/** @param {string} result */
function extractLineMap(result) {
    const lineMap = new Map();
    let currentLine = null;

    result.split(/\r?\n/u).forEach((row) => {
        const match = row.match(/^L(?<line>\d+):\s?(?<text>.*)$/u);
        if (match?.groups?.line) {
            currentLine = Number.parseInt(match.groups.line, 10);
            lineMap.set(currentLine, match.groups.text || '');

            return;
        }

        if (currentLine != null && row.trim() !== '') {
            lineMap.set(currentLine, `${lineMap.get(currentLine)} ${row.trim()}`.trim());
        }
    });

    return lineMap;
}

/**
 * @param {Map<number, string>} lineMap
 * @param {{ startLine: number, endLine: number }[]} spans
 */
function hasAnySpanMatch(lineMap, spans) {
    if (!(lineMap instanceof Map) || lineMap.size === 0 || !Array.isArray(spans) || spans.length === 0) {
        return false;
    }

    for (const span of spans) {
        const startLine = span.startLine;
        const normalizedEnd = Math.min(Math.max(span.endLine ?? startLine, startLine), startLine + 8);
        for (let line = startLine; line <= normalizedEnd; line += 1) {
            if (lineMap.has(line)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param {Map<string, SourceLike[]>} map
 * @param {string} key
 * @param {SourceLike} source
 */
function addSource(map, key, source) {
    if (!(map instanceof Map) || !key) {
        return;
    }

    if (!map.has(key)) {
        map.set(key, []);
    }

    map.get(key).push(source);
}

/**
 * @param {SourceLike[]} sources
 * @returns {SourceLike[]}
 */
function dedupeSources(sources) {
    const seen = new Set();
    const deduped = [];

    sources.forEach((source) => {
        const key = `${source.sequence ?? 'null'}|${source.turnNumber ?? 'null'}|${source.hasLineMap ? '1' : '0'}`;
        if (seen.has(key)) {
            return;
        }

        seen.add(key);
        deduped.push(source);
    });

    return deduped;
}

/** @param {string} rawUrl */
function normalizeUrl(rawUrl) {
    try {
        const parsed = new URL(rawUrl);
        parsed.hash = '';
        parsed.search = '';
        const pathname = parsed.pathname !== '/' ? parsed.pathname.replace(/\/+$/u, '') : '/';

        return `${parsed.protocol}//${parsed.host.toLowerCase()}${pathname || '/'}`;
    } catch {
        return rawUrl.trim().replace(/\/+$/u, '');
    }
}

/** @param {string} rawUrl */
function extractDomain(rawUrl) {
    try {
        return new URL(rawUrl).host.toLowerCase();
    } catch {
        return '';
    }
}
