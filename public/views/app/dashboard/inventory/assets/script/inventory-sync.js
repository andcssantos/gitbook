/**
 * Fila de sync ociosa do inventario (P0).
 * Evita resync destrutivo no meio de drag/paperdoll.
 */

let syncDeps = null;
let idleUiSyncQueue = [];
let idleUiSyncScheduled = false;

export function configureInventorySync(deps = {}) {
    syncDeps = deps;
}

function deps() {
    return syncDeps || {};
}

export function isInventoryInteractionBusy() {
    const d = deps();
    if (typeof d.isBusy === 'function') {
        return Boolean(d.isBusy());
    }
    return false;
}

export function scheduleIdleUiSync(task) {
    if (typeof task !== 'function') return;
    idleUiSyncQueue.push(task);
    pumpIdleUiSync();
}

export function pumpIdleUiSync() {
    if (idleUiSyncScheduled) return;
    idleUiSyncScheduled = true;

    const tick = () => {
        if (isInventoryInteractionBusy()) {
            window.setTimeout(tick, 48);
            return;
        }

        idleUiSyncScheduled = false;
        const batch = idleUiSyncQueue.splice(0, idleUiSyncQueue.length);
        if (!batch.length) return;

        void (async () => {
            for (const task of batch) {
                try {
                    await task();
                } catch (error) {
                    console.error('[inventory-idle-sync]', error);
                }
            }
            if (idleUiSyncQueue.length) {
                pumpIdleUiSync();
            }
        })();
    };

    tick();
}

export function clearIdleUiSyncQueue() {
    idleUiSyncQueue = [];
    idleUiSyncScheduled = false;
}

/**
 * Agenda resync de um container so quando a UI estiver livre.
 */
export function scheduleContainerResync(containerPublicId) {
    if (!containerPublicId) return;
    scheduleIdleUiSync(async () => {
        const d = deps();
        if (typeof d.resyncContainerPanel === 'function') {
            await d.resyncContainerPanel(containerPublicId);
            return;
        }
        if (typeof d.reloadContainerPanelsOnly === 'function') {
            await d.reloadContainerPanelsOnly();
        }
    });
}

/**
 * Agenda refresh de equipment + containers afetados.
 */
export function scheduleEquipmentAndContainersSync({
    refreshEquipmentOnly,
    containerPublicIds = [],
} = {}) {
    scheduleIdleUiSync(async () => {
        if (typeof refreshEquipmentOnly === 'function') {
            await refreshEquipmentOnly().catch(() => null);
        }
        const d = deps();
        const unique = [...new Set((containerPublicIds || []).filter(Boolean))];
        for (const id of unique) {
            if (typeof d.resyncContainerPanel === 'function') {
                await d.resyncContainerPanel(id).catch(() => null);
            }
        }
    });
}
