/**
 * Cache stale-while-revalidate de detalhes de container (Sprint D).
 * Permite reabrir baús a partir do snapshot local e revalidar em background.
 */

export function createContainerDetailCache(options = {}) {
    const ttlMs = Math.max(0, Number(options.ttlMs ?? 45_000));
    const entries = new Map();

    function peek(publicId) {
        if (!publicId) return null;
        return entries.get(publicId) || null;
    }

    function get(publicId) {
        const entry = peek(publicId);
        return entry?.container ? entry : null;
    }

    function isFresh(publicId, now = Date.now()) {
        const entry = peek(publicId);
        if (!entry?.container) return false;
        if (ttlMs <= 0) return false;
        return (now - Number(entry.fetchedAt || 0)) < ttlMs;
    }

    function set(publicId, container, summaryEntry = null) {
        if (!publicId || !container?.public_id) return;
        const previous = peek(publicId);
        entries.set(publicId, {
            container,
            summaryEntry: summaryEntry ?? previous?.summaryEntry ?? null,
            fetchedAt: Date.now(),
            inflight: previous?.inflight || null,
        });
    }

    function invalidate(publicId = null) {
        if (!publicId) {
            entries.clear();
            return;
        }
        entries.delete(publicId);
    }

    function markInflight(publicId, promise) {
        const entry = peek(publicId) || {
            container: null,
            summaryEntry: null,
            fetchedAt: 0,
            inflight: null,
        };
        entry.inflight = promise;
        entries.set(publicId, entry);
        return promise;
    }

    function clearInflight(publicId) {
        const entry = peek(publicId);
        if (!entry) return;
        entry.inflight = null;
        entries.set(publicId, entry);
    }

    function getInflight(publicId) {
        return peek(publicId)?.inflight || null;
    }

    return {
        get,
        set,
        peek,
        isFresh,
        invalidate,
        markInflight,
        clearInflight,
        getInflight,
    };
}
