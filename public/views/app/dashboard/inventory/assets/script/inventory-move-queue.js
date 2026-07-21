/**
 * Fila / reserva de itens do inventário (Sprint C+F).
 * Moves usam enqueue serial; merge/socket/enhance usam reserveMany sem travar o inventário inteiro.
 */

export function createInventoryMoveQueue(options = {}) {
    const maxInFlight = Math.max(1, Number(options.maxInFlight) || 1);
    const maxQueued = Math.max(0, Number(options.maxQueued) || 4);
    const capacity = maxInFlight + maxQueued;
    const pendingByItem = new Map();
    const queue = [];
    let inFlight = 0;

    function isItemPending(itemPublicId) {
        return Boolean(itemPublicId) && pendingByItem.has(itemPublicId);
    }

    function pendingCount() {
        return pendingByItem.size;
    }

    function isBusy() {
        return inFlight > 0 || queue.length > 0 || pendingByItem.size > 0;
    }

    function canAccept(itemPublicId) {
        if (!itemPublicId) return false;
        if (pendingByItem.has(itemPublicId)) return false;
        if (pendingByItem.size >= capacity) return false;
        return true;
    }

    function reserve(itemPublicId) {
        if (!canAccept(itemPublicId)) return false;
        pendingByItem.set(itemPublicId, { status: 'reserved' });
        return true;
    }

    function reserveMany(itemPublicIds = []) {
        const unique = [...new Set((itemPublicIds || []).filter(Boolean))];
        if (!unique.length) return false;
        if (unique.some((id) => pendingByItem.has(id))) return false;
        if (pendingByItem.size + unique.length > capacity) return false;
        for (const id of unique) {
            pendingByItem.set(id, { status: 'reserved' });
        }
        return true;
    }

    function release(itemPublicId) {
        const state = pendingByItem.get(itemPublicId);
        if (!state || state.status !== 'reserved') return;
        pendingByItem.delete(itemPublicId);
    }

    function releaseMany(itemPublicIds = []) {
        for (const id of itemPublicIds || []) {
            if (id) pendingByItem.delete(id);
        }
    }

    function enqueue(itemPublicId, run) {
        if (!itemPublicId || typeof run !== 'function') {
            return Promise.reject(new Error('Movimento invalido.'));
        }

        if (!pendingByItem.has(itemPublicId) && !reserve(itemPublicId)) {
            return Promise.reject(new Error('Fila de movimentos cheia.'));
        }

        if (queue.some((job) => job.itemPublicId === itemPublicId)) {
            return Promise.reject(new Error('Item ainda sincronizando.'));
        }

        return new Promise((resolve, reject) => {
            pendingByItem.set(itemPublicId, { status: 'queued' });
            queue.push({ itemPublicId, run, resolve, reject });
            pump();
        });
    }

    function pump() {
        while (inFlight < maxInFlight && queue.length > 0) {
            const job = queue.shift();
            inFlight += 1;
            pendingByItem.set(job.itemPublicId, { status: 'inflight' });

            Promise.resolve()
                .then(() => job.run())
                .then((result) => job.resolve(result))
                .catch((error) => job.reject(error))
                .finally(() => {
                    pendingByItem.delete(job.itemPublicId);
                    inFlight -= 1;
                    pump();
                });
        }
    }

    return {
        enqueue,
        reserve,
        reserveMany,
        release,
        releaseMany,
        canAccept,
        isItemPending,
        pendingCount,
        isBusy,
    };
}
