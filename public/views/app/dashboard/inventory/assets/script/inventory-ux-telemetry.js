/**
 * Telemetria UX mínima do inventário/arena (Sprint D).
 * Acumula amostras em memória e expõe p95 no console / window.
 */

function percentile(sorted, p) {
    if (!sorted.length) return null;
    const index = Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1));
    return sorted[index];
}

export function createInventoryUxTelemetry(options = {}) {
    const maxSamples = Math.max(20, Number(options.maxSamples || 120));
    const samples = {
        move: [],
        open_container: [],
        pickup: [],
    };

    function push(metric, durationMs, meta = {}) {
        const key = String(metric || '');
        if (!samples[key]) return;
        const duration = Math.max(0, Number(durationMs) || 0);
        samples[key].push({
            duration_ms: duration,
            at: Date.now(),
            ...meta,
        });
        if (samples[key].length > maxSamples) {
            samples[key].splice(0, samples[key].length - maxSamples);
        }
    }

    function start(metric, meta = {}) {
        const startedAt = (typeof performance !== 'undefined' && performance.now)
            ? performance.now()
            : Date.now();
        return {
            end(extra = {}) {
                const endedAt = (typeof performance !== 'undefined' && performance.now)
                    ? performance.now()
                    : Date.now();
                push(metric, endedAt - startedAt, { ...meta, ...extra });
            },
        };
    }

    function summary(metric = null) {
        const keys = metric ? [String(metric)] : Object.keys(samples);
        const out = {};
        for (const key of keys) {
            const list = (samples[key] || []).map((entry) => entry.duration_ms).sort((a, b) => a - b);
            out[key] = {
                count: list.length,
                p50: percentile(list, 50),
                p95: percentile(list, 95),
                max: list.length ? list[list.length - 1] : null,
            };
        }
        return metric ? out[String(metric)] : out;
    }

    function logSummary(label = '[inventory-ux]') {
        const data = summary();
        // eslint-disable-next-line no-console
        console.info(label, data);
        return data;
    }

    const api = { start, push, summary, logSummary, samples };
    if (typeof window !== 'undefined') {
        window.__inventoryUxTelemetry = api;
    }
    return api;
}
