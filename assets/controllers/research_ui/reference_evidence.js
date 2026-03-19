/**
 * @typedef {object} TraceCallLike
 * @property {string} label
 * @property {string} [url]
 * @property {string} [result]
 * @property {number|null} [sequence]
 * @property {number|null} [turnNumber]
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
    toolCalls
        .filter((call) => call.label === 'websearch_open' && typeof call.url === 'string' && typeof call.result === 'string')
        .forEach((call) => {
            const lineMap = extractLineMap(call.result);
            if (lineMap.size === 0) {
                return;
            }

            if (!sourceMap.has(call.url)) {
                sourceMap.set(call.url, []);
            }
            sourceMap.get(call.url).push({ sequence: call.sequence, turnNumber: call.turnNumber, lineMap });
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
            const candidates = sourceMap.get(reference.url) || [];
            if (candidates.length > 0) {
                const withSpanMatch = candidates.find((source) => hasAnySpanMatch(source.lineMap, reference.spans));
                const chosen = withSpanMatch || candidates[0];
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
    if (!referencesSection) {
        return [];
    }

    const references = [];
    const pattern = /(?:^|\s)(?<marker>[0-9]+|[⁰¹²³⁴⁵⁶⁷⁸⁹]+)[\.)]?\s+(?<url>https?:\/\/[^\s)]+)(?:\s+\((?<lineInfo>lines?[^)]*)\))?/giu;

    for (const match of referencesSection.matchAll(pattern)) {
        if (!match.groups) {
            continue;
        }

        const marker = match.groups.marker;
        const id = referenceIdFromMarker(marker);
        if (id == null) {
            continue;
        }

        const url = match.groups.url.replace(/[.,;)]+$/u, '');
        const spans = parseLineSpans(match.groups.lineInfo || '');
        references.push({ id, marker, url, spans });
    }

    return references.sort((a, b) => a.id - b.id);
}

/** @param {string} markdown */
function extractReferencesSection(markdown) {
    const match = markdown.match(/(?:^|\n)#{1,6}\s+References\b[\s\S]*$/iu);
    if (match && match[0]) {
        return match[0];
    }

    const fallback = markdown.match(/(?:^|\n)References\s*[\s\S]*$/iu);

    return fallback ? fallback[0] : '';
}

/** @param {string} lineInfo */
function parseLineSpans(lineInfo) {
    if (!lineInfo) {
        return [];
    }

    const spans = [];
    const pattern = /L(?<start>\d+)(?:\s*-\s*L?(?<end>\d+))?/giu;
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
