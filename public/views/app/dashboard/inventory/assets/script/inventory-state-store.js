/**
 * Store autoritativo do inventario (P0).
 * Maps compartilhados: main.js e paineis usam as mesmas referencias.
 */

function createInventoryStateStore() {
    return {
        grids: new Map(),
        gridCellSizes: new Map(),
        itemIndex: new Map(),
        containerIndex: new Map(),
        dragSnapshots: new Map(),
        moveRollbacks: new Map(),
        itemActionsCache: new Map(),

        // Flags de interacao (atualizados pelo main via setters locais / sync)
        interaction: {
            activeDrag: null,
            paperdollDragState: null,
            loading: false,
            silent: false,
            actionInFlight: false,
            equipmentSyncInFlight: 0,
            inventoryReloadQueued: false,
            contextMenuState: null,
        },

        // Equipment snapshot leve (espelho do ultimo render)
        equipment: {
            current: [],
            links: [],
            setBonuses: [],
            backpackPublicId: null,
        },
    };
}

export const inventoryStore = createInventoryStateStore();

export function getInventoryStore() {
    return inventoryStore;
}

export function resetInventoryStoreMaps() {
    inventoryStore.grids.clear();
    inventoryStore.gridCellSizes.clear();
    inventoryStore.itemIndex.clear();
    inventoryStore.containerIndex.clear();
    inventoryStore.dragSnapshots.clear();
    inventoryStore.moveRollbacks.clear();
}
