/**
 * Ciclo de vida dos painéis de container (mount / unmount / resync / SWR).
 * Extraído do main.js — Sprint E.
 */

import { reconcileMountedGrid } from './inventory-grid-reconcile.js';

let panelDeps = null;

export function configureContainerPanels(deps) {
    panelDeps = deps || {};
}

function deps() {
    return panelDeps || {};
}

export function unwrapContainerPayload(payload) {
    if (!payload || typeof payload !== 'object') return null;
    if (payload.public_id) return payload;
    if (payload.container?.public_id) return payload.container;
    return null;
}

export function snapshotContainerDetailToCache(containerPublicId) {
    const d = deps();
    const containerIndex = d.containerIndex;
    const itemIndex = d.itemIndex;
    const cache = d.containerDetailCache;
    if (!containerIndex || !itemIndex || !cache) return;

    const allContainers = d.getAllContainersCache?.() || [];
    const summaryMap = d.getInventorySummaryByPublicId?.() || new Map();
    const container = containerIndex.get(containerPublicId)
        || allContainers.find((entry) => entry.public_id === containerPublicId);
    if (!container?.public_id) return;

    const items = [];
    for (const [publicId, entry] of itemIndex.entries()) {
        if (entry.container_public_id !== containerPublicId || !entry.item) continue;
        items.push(entry.item);
    }

    const snapshot = {
        ...container,
        items: items.length ? items : (container.items || []),
    };
    cache.set(
        containerPublicId,
        snapshot,
        summaryMap.get(containerPublicId) || null
    );
    d.upsertContainerCache?.(snapshot);
}

function resolveCachedContainerDetail(containerPublicId) {
    const d = deps();
    const cached = d.containerDetailCache?.get(containerPublicId);
    if (cached?.container) return cached;

    const allContainers = d.getAllContainersCache?.() || [];
    const summaryMap = d.getInventorySummaryByPublicId?.() || new Map();
    const fromIndex = d.containerIndex?.get(containerPublicId);
    const fromAll = allContainers.find((entry) => entry.public_id === containerPublicId);
    const container = fromIndex || fromAll;
    if (!container?.public_id) return null;

    return {
        container,
        summaryEntry: summaryMap.get(containerPublicId) || null,
        fetchedAt: 0,
    };
}

function paintContainerPanel(container, summaryEntry = null) {
    const d = deps();
    if (!container?.public_id || d.grids?.has(container.public_id)) return false;

    const summaryMap = d.getInventorySummaryByPublicId?.() || new Map();
    d.containerIndex?.set(container.public_id, container);
    d.upsertContainerCache?.(container);
    if (summaryEntry) {
        summaryMap.set(container.public_id, summaryEntry);
    }

    const section = d.renderContainer?.(
        container,
        summaryEntry || summaryMap.get(container.public_id) || null
    );
    if (!section) return false;

    d.getContainerRoot?.()?.appendChild(section);

    const gridNode = section.querySelector('.inventory-grid');
    const grid = d.initializeGrid?.(container, gridNode);
    d.addItems?.(container, grid);
    d.prefetchItemActions?.(container);
    return true;
}

export async function fetchContainerDetail(containerPublicId) {
    const d = deps();
    const cache = d.containerDetailCache;
    const apiFetch = d.apiFetch;
    if (!cache || !apiFetch) {
        throw new Error('Container panels nao configurado.');
    }

    const inflight = cache.getInflight(containerPublicId);
    if (inflight) return inflight;

    const request = Promise.all([
        apiFetch(`/api/inventory/containers/${encodeURIComponent(containerPublicId)}`),
        apiFetch('/api/inventory/summary').catch(() => null),
    ]).then(([containerResponse, summaryResponse]) => {
        const container = unwrapContainerPayload(containerResponse.data);
        if (!container?.public_id) {
            throw new Error('Container indisponivel.');
        }

        const summaryEntry = (summaryResponse?.data?.containers || [])
            .find((entry) => entry.public_id === containerPublicId) || null;

        const summaryMap = d.getInventorySummaryByPublicId?.() || new Map();
        if (summaryEntry) {
            summaryMap.set(containerPublicId, summaryEntry);
        }
        if (summaryResponse?.data) {
            d.renderSummary?.(summaryResponse.data, d.getPlayerWallets?.() || []);
        }

        d.containerIndex?.set(container.public_id, container);
        d.upsertContainerCache?.(container);
        cache.set(containerPublicId, container, summaryEntry);
        return { container, summaryEntry, summaryResponse };
    }).finally(() => {
        cache.clearInflight(containerPublicId);
    });

    return cache.markInflight(containerPublicId, request);
}

async function applyContainerPayloadToMountedGrid(containerPublicId, container, options = {}) {
    const d = deps();
    if (!container?.public_id) return;

    // Nunca reconstrói o grid no meio de um drag (quebra o GridStack / getBoundingClientRect null).
    if (typeof document !== 'undefined' && document.documentElement.classList.contains('is-inventory-dragging')) {
        d.scheduleIdleUiSync?.(() => applyContainerPayloadToMountedGrid(containerPublicId, container, options));
        return;
    }

    if (typeof d.isBusy === 'function' && d.isBusy()) {
        d.scheduleIdleUiSync?.(() => applyContainerPayloadToMountedGrid(containerPublicId, container, options));
        return;
    }

    const itemIndex = d.itemIndex;
    const grids = d.grids;
    if (!itemIndex || !grids) return;

    const grid = grids.get(containerPublicId);
    if (!grid) return;

    d.containerIndex?.set(container.public_id, container);
    d.upsertContainerCache?.(container);

    const result = reconcileMountedGrid({
        containerPublicId,
        container,
        grids,
        itemIndex,
        setSilent: d.setSilent,
        dimensionsForState: d.dimensionsForState,
        renderItem: d.renderItem,
        bindItemWidget: d.bindItemWidget,
        updateContainerOccupancyBadge: d.updateContainerOccupancyBadge,
        applyInventoryFilters: d.applyInventoryFilters,
        isBusy: d.isBusy,
        forceRebuild: Boolean(options.forceRebuild),
    });

    if (result.skipped) {
        d.scheduleIdleUiSync?.(() => applyContainerPayloadToMountedGrid(containerPublicId, container, options));
        return;
    }

    // Fallback legado se o adapter nao tiver helpers de render (config incompleta).
    if (
        (result.added === 0 && result.updated === 0 && result.removed === 0)
        && Array.isArray(container.items)
        && container.items.length > 0
        && !(grid.engine?.nodes?.length)
        && typeof d.addItems === 'function'
        && typeof d.dimensionsForState !== 'function'
    ) {
        d.setSilent?.(true);
        try {
            d.addItems(container, grid);
        } finally {
            d.setSilent?.(false);
        }
        d.updateContainerOccupancyBadge?.(containerPublicId);
        d.applyInventoryFilters?.();
    }
}

async function revalidateMountedContainer(containerPublicId) {
    const d = deps();
    try {
        const { container } = await fetchContainerDetail(containerPublicId);
        if (!d.grids?.has(containerPublicId)) return;
        await applyContainerPayloadToMountedGrid(containerPublicId, container);
        d.setStatus?.('Sincronizado');
    } catch {
        // Mantem o snapshot local; o usuario ainda consegue interagir.
    }
}

export function unmountContainerPanel(containerPublicId) {
    const d = deps();
    snapshotContainerDetailToCache(containerPublicId);

    const grids = d.grids;
    const gridCellSizes = d.gridCellSizes;
    const itemIndex = d.itemIndex;
    const grid = grids?.get(containerPublicId);

    if (grid) {
        d.setSilent?.(true);
        try {
            grid.destroy(false);
        } finally {
            d.setSilent?.(false);
        }
        grids.delete(containerPublicId);
        gridCellSizes?.delete(containerPublicId);
    }

    if (itemIndex) {
        for (const [publicId, indexed] of itemIndex.entries()) {
            if (indexed.container_public_id === containerPublicId) {
                itemIndex.delete(publicId);
            }
        }
    }

    d.getContainerRoot?.()
        ?.querySelector(`[data-container-public-id="${containerPublicId}"]`)
        ?.remove();
}

export async function mountContainerPanel(containerPublicId) {
    const d = deps();
    if (d.grids?.has(containerPublicId)) return;
    if (d.isLoading?.() || d.isActionInFlight?.()) {
        d.setStatus?.('Aguarde a acao atual...');
        return;
    }

    const timer = d.inventoryUxTelemetry?.start?.('open_container', {
        container_public_id: containerPublicId,
    });
    const cached = resolveCachedContainerDetail(containerPublicId);
    const hasUsableCache = Boolean(cached?.container?.public_id);
    const cache = d.containerDetailCache;

    try {
        if (hasUsableCache) {
            paintContainerPanel(cached.container, cached.summaryEntry || null);
            d.setStatus?.(cache?.isFresh(containerPublicId) ? 'Sincronizado' : 'Atualizando...');
            timer?.end?.({ cache: 'hit', fresh: Boolean(cache?.isFresh(containerPublicId)) });
            void revalidateMountedContainer(containerPublicId);
            return;
        }

        d.setStatus?.('Abrindo...');
        const { container, summaryEntry } = await fetchContainerDetail(containerPublicId);
        paintContainerPanel(container, summaryEntry);
        d.setStatus?.('Sincronizado');
        timer?.end?.({ cache: 'miss' });
    } catch (error) {
        d.openContainerPublicIds?.delete(containerPublicId);
        d.persistContainerPanels?.();
        d.handleError?.(error, 'Nao foi possivel abrir o container.');
        timer?.end?.({ cache: 'error' });
        await resyncContainerPanel(containerPublicId).catch(() => d.loadInventory?.());
    }
}

export async function resyncContainerPanel(containerPublicId) {
    const d = deps();
    if (!containerPublicId) return;

    try {
        const { container, summaryEntry } = await fetchContainerDetail(containerPublicId);
        d.containerDetailCache?.set(containerPublicId, container, summaryEntry);

        if (d.grids?.has(containerPublicId)) {
            await applyContainerPayloadToMountedGrid(containerPublicId, container);
        }
        // Painel fechado: mantem cache fresco para a proxima abertura SWR.
    } catch {
        d.containerDetailCache?.invalidate(containerPublicId);
        await d.loadInventory?.();
    }
}
