import { ApiError, apiFetch } from '/assets/framework/api.js';
import { openModal, installModalStyles } from '/assets/framework/modal.js';
import { installToastStyles, toast } from '/assets/framework/toast.js';
import {
    configureExplorationArena,
} from './expedition-arena.js';
import { renderMarketBreakdownHtml } from './market-breakdown.js';
import {
    configureItemInvestigation,
    openInvestigationModal,
} from './item-investigation.js';
import {
    closeMarketPanel,
    configureMarketUi,
    initMarketControls,
    isMarketPanelOpen,
    loadMarketListings,
    renderMarketWallets,
    setMarketPanelOpen,
    toggleMarketPanel,
} from './market-ui.js';
import { createInventoryMoveQueue } from './inventory-move-queue.js';
import { createContainerDetailCache } from './inventory-container-cache.js';
import { createInventoryUxTelemetry } from './inventory-ux-telemetry.js';
import {
    configureContainerPanels,
    fetchContainerDetail,
    mountContainerPanel,
    resyncContainerPanel,
    snapshotContainerDetailToCache,
    unmountContainerPanel,
    unwrapContainerPayload,
} from './container-panels.js';
import { inventoryStore } from './inventory-state-store.js';
import {
    configureInventorySync,
    pumpIdleUiSync,
    scheduleIdleUiSync,
} from './inventory-sync.js';
import { fingerprintInventoryItem } from './inventory-grid-reconcile.js';
import {
    baseDimensions,
    clampCell,
    clearAllGhostPreviews,
    configureInventoryGridEngine,
    dedupeItemWidgets,
    dimensionsForState,
    findFirstFreeSlot,
    findOverlapInSnapshot,
    findOverlappingItemFromSnapshot,
    findOverlappingPlacements,
    ghostElementForContainer,
    gridCellFromPointer,
    isInsideBounds,
    isItemMovingIntoOwnContainer,
    isPlacementValidAgainstSnapshot,
    overlaps,
    placementSnapshotForContainer,
    placementSnapshotForGrid,
    purgeItemWidgetFromAllGrids,
    reconcileContainerGrid,
    renderGhostPreview,
    scrubOrphanGridWidgets,
    snapshotContainerItems,
    snapshotNodes,
    targetSnapshotForGrid,
} from './inventory-grid-engine.js';
import {
    attachItemTooltip,
    closeContextMenu,
    configureInventoryOverlays,
    hideAllItemTooltips,
    invalidateItemActionsCache,
    openContextMenu,
    prefetchItemActionsForContainer,
} from './inventory-overlays.js';
import {
    applyOptimisticEquipToSlot,
    clearEquipmentDragHighlights,
    configureInventoryEquipmentUi,
    equipItemToEquipmentSlot,
    equipmentSlotMatchesItem,
    findEquipmentSlotUnderPointer,
    hideItemWidgetPendingEquip,
    onPaperdollUnequipDragOver,
    refreshEquipmentOnly,
    renderEquipment,
    resolvePaperdollEquipTargetSlot,
    unequipPaperdollItem,
    visualSlotCode,
} from './inventory-equipment-ui.js';
import {
    closeFloatingBagWindow,
    configureFloatingBags,
    getOpenFloatingContainerIds,
    isFloatingContainerOpen,
    openFloatingBagWindow,
    remountFloatingBagWindows,
    restoreFloatingBagWindows,
    softRefreshFloatingBagWindow,
} from './floating-bags.js';
import {
    closeExplorationPanel,
    configureExplorationUi,
    executeExplorationAction,
    executeExplorationAnalyze,
    explorationActionLabel,
    getExplorationBiomeCode,
    getExplorationExpedition,
    initExplorationControls,
    isExplorationPanelOpen,
    listOwnedToolsByType,
    loadExplorationObjects,
    renderExplorationPanel,
    setExplorationExpedition,
    setExplorationMap,
    setExplorationModifiers,
    setExplorationObjects,
    setExplorationPosition,
    softRefreshArenaStage,
    toggleExplorationPanel,
} from './exploration-ui.js';
import {
    bindMissionsUi,
    closeMissionsPanel,
    configureMissionsUi,
    isMissionsPanelOpen,
    loadMissionsJournal,
    openMissionsPanel,
    toggleMissionsPanel,
} from './missions-ui.js';
import {
    closeSetCodexPanel,
    compareBadgeForItem,
    configureInventoryMaxFeatures,
    initInventoryMaxFeatures,
    isSetCodexOpen,
    loadEquipmentLoadouts,
    loadExplorationLoadoutPanel,
    loadRecipeJournal,
    previewSocketPlan,
    toggleSetCodexPanel,
    unsocketGem,
} from './inventory-max-features.js';

const app = document.querySelector('[data-inventory-app]');
const containerRoot = document.querySelector('[data-inventory-containers]');
const equipmentRoot = document.querySelector('[data-inventory-equipment]');
const expeditionRoot = document.querySelector('[data-inventory-expedition]');
const leftDrawerRoot = document.querySelector('[data-inventory-drawer-left]');
const rightDrawerRoot = document.querySelector('[data-inventory-drawer-right]');
const backdropRoot = document.querySelector('[data-inventory-backdrop]');
const hubRoot = document.querySelector('[data-inventory-hub]');
const statsDrawerRoot = document.querySelector('[data-inventory-drawer-stats]');
const statsDrawerPanel = document.querySelector('[data-character-stats-drawer]');
const marketToggleButton = document.querySelector('[data-market-toggle]');
const statusNode = document.querySelector('[data-inventory-status]');
const summaryNode = document.querySelector('[data-inventory-summary]');
const playerHudRoot = document.querySelector('[data-player-hud]');
const refreshButton = document.querySelector('[data-inventory-refresh]');
const compareDockRoot = document.querySelector('[data-inventory-compare]');
const materialsPanelRoot = document.querySelector('[data-inventory-materials]');
const materialsTabsRoot = document.querySelector('[data-materials-tabs]');
const materialsListRoot = document.querySelector('[data-materials-list]');
const craftPanelRoot = document.querySelector('[data-inventory-craft]');
const explorationPanelRoot = document.querySelector('[data-inventory-exploration]');
const missionsPanelRoot = document.querySelector('[data-inventory-missions]');
const missionsListRoot = document.querySelector('[data-missions-list]');
const missionsTabsRoot = document.querySelector('[data-missions-tabs]');

const GAME_ITEM_ASSET_CODES = new Set([
    'epic_common_cloth_hood',
    'epic_common_dagger',
    'epic_iron_chest',
    'epic_rare_spear',
    'epic_test_pet_wolf',
    'epic_travel_bag',
    'epic_uncommon_chain_vest',
    'epic_uncommon_mace',
    'gem_amethyst_strength',
    'gem_emerald_vitality',
    'gem_onyx_defense',
    'gem_ruby_attack',
    'gem_sapphire_guard',
    'gem_topaz_agility',
    'jewel_blessing_minor',
    'jewel_chaos_minor',
    'jewel_reroll_minor',
    'jewel_soul_minor',
    'old_wood-metal_sword',
    'old_wood_sword',
    'showcase_common_earring',
    'showcase_common_gloves',
    'showcase_common_pants',
    'showcase_common_ring',
    'showcase_common_sword',
    'showcase_common_wings',
    'showcase_dragon_egg',
    'showcase_drake_helm',
    'showcase_ember_axe',
    'showcase_frost_shield',
    'showcase_gold_pouch',
    'showcase_health_potion',
    'showcase_iron_cuirass',
    'showcase_moon_ring',
    'showcase_oracle_amulet',
    'showcase_shadow_boots',
    'showcase_storm_staff',
    'showcase_sunblade',
    'small_leather_backpack',
    'small_pouch_bag',
    'stone',
    'stone_pickaxe',
    'wood',
    'wooden_storage_chest',
]);

installToastStyles();
installModalStyles();
installInventoryModalStyles();
installImageFallbackHandler();

function installInventoryModalStyles() {
    if (document.getElementById('inventory-modal-styles')) return;

    const style = document.createElement('style');
    style.id = 'inventory-modal-styles';
    style.textContent = `
.inventory-modal {
    display: grid;
    gap: 14px;
    min-width: min(420px, 86vw);
    padding: 4px 2px;
}
.inventory-modal h3 {
    margin: 0;
    font-size: 1.05rem;
    letter-spacing: .02em;
    color: #f3f6fb;
}
.inventory-modal-body { display: grid; gap: 8px; color: #cbd5e1; line-height: 1.45; }
.inventory-modal-body p { margin: 0; }
.inventory-modal-body ul { margin: 0; padding-left: 18px; }
.inventory-modal-action {
    justify-self: start;
    border: 1px solid rgba(217, 164, 65, .34);
    border-radius: 6px;
    padding: 10px 16px;
    font-weight: 700;
    cursor: pointer;
    background: linear-gradient(180deg, rgba(217, 164, 65, .92), rgba(180, 130, 40, .92));
    color: #1a1206;
}
.inventory-modal--success h3 { color: #86efac; }
.inventory-modal--success .inventory-modal-action {
    border-color: rgba(85, 197, 138, .4);
    background: linear-gradient(180deg, #55c58a, #2f9e66);
    color: #04140c;
}
.inventory-modal--warning h3 { color: #f6d48a; }
.inventory-modal--danger h3 { color: #fecaca; }
.inventory-modal--danger .inventory-modal-confirm {
    border-color: rgba(248, 113, 113, .4);
    background: linear-gradient(180deg, #ef4444, #b91c1c);
    color: #fff7ed;
}
.inventory-modal--info h3 { color: #93c5fd; }
.inventory-modal--info .inventory-modal-action {
    border-color: rgba(56, 189, 248, .35);
    background: linear-gradient(180deg, #38bdf8, #0284c7);
    color: #041018;
}
.inventory-modal--confirm .inventory-modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
    margin-top: 4px;
}
.inventory-modal-cancel,
.inventory-modal-confirm {
    border: 1px solid transparent;
    border-radius: 6px;
    padding: 10px 16px;
    font-weight: 800;
    cursor: pointer;
}
.inventory-modal-cancel {
    border-color: rgba(148, 163, 184, .22);
    background: rgba(15, 23, 42, .72);
    color: #e2e8f0;
}
.inventory-modal-confirm {
    border-color: rgba(217, 164, 65, .4);
    background: linear-gradient(180deg, rgba(217, 164, 65, .95), rgba(180, 130, 40, .95));
    color: #1a1206;
}
.inventory-modal--confirm h3 { color: #f8fafc; }
.inventory-modal--confirm .inventory-modal-body { color: #cbd5e1; }
.inventory-batch-result { display: grid; gap: 14px; min-width: min(560px, 86vw); }
.inventory-batch-result h3 { margin: 0; color: #f8fafc; }
.inventory-batch-id { margin: -6px 0 0; color: #8fb3d9; font-family: ui-monospace, monospace; font-size: 11px; }
.inventory-batch-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.inventory-batch-summary span { display: grid; gap: 2px; padding: 10px; border: 1px solid rgba(148, 163, 184, .18); border-radius: 8px; background: rgba(15, 23, 42, .62); color: #94a3b8; font-size: 11px; text-transform: uppercase; }
.inventory-batch-summary strong { color: #f8fafc; font-size: 18px; }
.inventory-batch-summary .is-success strong { color: #86efac; }
.inventory-batch-summary .is-warning strong { color: #facc15; }
.inventory-batch-failures { display: grid; gap: 7px; max-height: 240px; overflow: auto; margin: 0; padding: 0; list-style: none; }
.inventory-batch-failures li { display: grid; gap: 2px; padding: 9px 10px; border: 1px solid rgba(248, 113, 113, .18); border-radius: 8px; background: rgba(127, 29, 29, .16); }
.inventory-batch-failures strong { color: #fecaca; font-size: 13px; }
.inventory-batch-failures small { color: #cbd5e1; font-size: 11px; }
.inventory-batch-actions { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.inventory-batch-close,
.inventory-batch-retry,
.inventory-batch-audit { border: 0; border-radius: 8px; padding: 10px 14px; font-weight: 800; cursor: pointer; }
.inventory-batch-close { background: rgba(148, 163, 184, .16); color: #e2e8f0; }
.inventory-batch-retry { background: #f59e0b; color: #451a03; }
.inventory-batch-audit { background: rgba(56, 189, 248, .16); color: #bae6fd; border: 1px solid rgba(125, 211, 252, .24); }
.gb-modal {
    background:
        linear-gradient(180deg, rgba(18, 23, 34, .98), rgba(8, 11, 18, .99));
    color: #f8fafc;
    border: 1px solid rgba(217, 164, 65, .32);
    border-radius: 10px;
    box-shadow: 0 24px 80px rgba(0,0,0,.55), inset 0 0 0 1px rgba(255,255,255,.03);
}
.gb-modal-overlay { background: rgba(2, 6, 23, .72); }
.inventory-socket-nested-tooltip {
    display: grid;
    gap: 6px;
    min-width: 180px;
    max-width: 240px;
    color: #e2e8f0;
    font-size: 12px;
    line-height: 1.35;
}
.inventory-socket-nested-tooltip strong { color: #f8fafc; font-size: 13px; }
.inventory-socket-nested-tooltip .is-effect { color: #86efac; font-weight: 700; }
.inventory-socket-nested-tooltip small { color: #94a3b8; }
`;
    document.head.appendChild(style);
}

function installImageFallbackHandler() {
    document.addEventListener('error', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLImageElement)) {
            return;
        }

        target.closest('.inventory-item')?.classList.add('has-missing-art');
        target.closest('.inventory-tooltip-hero-art')?.classList.add('is-placeholder');
        target.closest('.craft-picker-cell-art')?.classList.add('is-placeholder');
        target.closest('.inventory-equipment-slot-icon')?.classList.add('is-missing-icon');
        target.remove();
    }, true);
}

function showInventoryModal({ title, tone = 'info', bodyHtml, primaryLabel = 'Entendi' }) {
    const content = document.createElement('div');
    content.className = `inventory-modal inventory-modal--${tone}`;
    content.innerHTML = `
        <h3>${escapeHtml(title)}</h3>
        <div class="inventory-modal-body">${bodyHtml}</div>
        <button type="button" class="inventory-modal-action">${escapeHtml(primaryLabel)}</button>
    `;

    const { close, element } = openModal(content, { closeOnBackdrop: true });
    element.querySelector('.inventory-modal-action')?.addEventListener('click', close);

    return close;
}

function confirmInventoryAction({
    title,
    bodyHtml,
    confirmLabel = 'Confirmar',
    cancelLabel = 'Cancelar',
    tone = 'warning',
    collectData = null,
}) {
    return new Promise((resolve) => {
        const content = document.createElement('div');
        content.className = `inventory-modal inventory-modal--confirm inventory-modal--${tone}`;
        content.innerHTML = `
            <h3>${escapeHtml(title)}</h3>
            <div class="inventory-modal-body">${bodyHtml}</div>
            <div class="inventory-modal-actions">
                <button type="button" class="inventory-modal-cancel">${escapeHtml(cancelLabel)}</button>
                <button type="button" class="inventory-modal-confirm">${escapeHtml(confirmLabel)}</button>
            </div>
        `;

        const { close, element } = openModal(content, { closeOnBackdrop: false });
        const finish = (value) => {
            close();
            resolve(value);
        };

        element.querySelector('.inventory-modal-cancel')?.addEventListener('click', () => finish(false));
        element.querySelector('.inventory-modal-confirm')?.addEventListener('click', () => {
            if (typeof collectData === 'function') {
                finish(collectData(element));
                return;
            }
            finish(true);
        });
    });
}

function listingPriceBoundsForItem(item) {
    const suggested = Number(item?.suggested_premium || 1);
    const min = Number(item?.listing_price_min || Math.max(1, Math.floor(suggested * 0.5)));
    const max = Number(item?.listing_price_max || Math.max(min, Math.ceil(suggested * 2)));
    const defaultPrice = Math.max(min, Math.min(max, suggested));

    return { min, max, suggested, defaultPrice };
}

function showEnhanceResultModal(jewelType, targetItem, data) {
    const targetName = escapeHtml(itemLabel(targetItem));

    if (data.success) {
        if (jewelType === 'bless') {
            const level = Number(data.to_level ?? 0);
            const props = Array.isArray(data.changed_properties) ? data.changed_properties : [];
            const propLines = props.length
                ? `<ul>${props.map((entry) => `<li><strong>${escapeHtml(entry.code)}</strong>: ${entry.from} → ${entry.to}</li>`).join('')}</ul>`
                : '<p>Sem alteracoes adicionais de atributos base.</p>';

            showInventoryModal({
                title: 'Bencao bem-sucedida!',
                tone: 'success',
                bodyHtml: `
                    <p><strong>${targetName}</strong> foi melhorado para <strong>+${level}</strong>.</p>
                    ${propLines}
                `,
            });
            return;
        }

        if (jewelType === 'chaos') {
            const fromBucket = escapeHtml(String(data.from_quality_bucket || 'common'));
            const toBucket = escapeHtml(String(data.to_quality_bucket || 'common'));
            const createdAffixes = Array.isArray(data.created_affixes) ? data.created_affixes : [];
            const scaledStats = Array.isArray(data.scaled_base_stats) ? data.scaled_base_stats : [];
            const scaledLines = scaledStats.length
                ? `<ul>${scaledStats.map((entry) => `<li><strong>${escapeHtml(entry.name || entry.code)}</strong>: ${entry.from} → ${entry.to}</li>`).join('')}</ul>`
                : '';
            const affixLines = createdAffixes.length
                ? `<ul>${createdAffixes.map((entry) => `<li><strong>${escapeHtml(entry.name || entry.code)}</strong> (+${escapeHtml(String(entry.value))})</li>`).join('')}</ul>`
                : '<p>Nenhum novo atributo foi revelado.</p>';

            showInventoryModal({
                title: 'Caos transformador!',
                tone: 'success',
                bodyHtml: `
                    <p><strong>${targetName}</strong> foi transformado de <strong>${fromBucket}</strong> para <strong>${toBucket}</strong>.</p>
                    ${scaledLines ? `<p>Atributos base fortalecidos:</p>${scaledLines}` : ''}
                    ${affixLines}
                `,
            });
            return;
        }

        if (jewelType === 'reroll') {
            const removed = data.removed_affix || {};
            const created = data.created_affix || {};
            showInventoryModal({
                title: 'Affix rerrolado!',
                tone: 'success',
                bodyHtml: `
                    <p><strong>${targetName}</strong> teve um atributo substituido.</p>
                    <p>Removido: <strong>${escapeHtml(removed.name || removed.code || '-')}</strong></p>
                    <p>Novo: <strong>${escapeHtml(created.name || created.code || '-')}</strong> (+${escapeHtml(String(created.value ?? '?'))})</p>
                `,
            });
            return;
        }

        const affixes = Array.isArray(data.changed_affixes) ? data.changed_affixes : [];
        const created = data.created_affix;
        const detail = created
            ? `<p>Novo atributo: <strong>${escapeHtml(created.code)}</strong> (+${escapeHtml(String(created.value))}).</p>`
            : affixes.length
                ? `<ul>${affixes.map((entry) => `<li><strong>${escapeHtml(entry.code)}</strong>: ${entry.from} → ${entry.to}</li>`).join('')}</ul>`
                : '<p>Um atributo do item foi fortalecido.</p>';

        showInventoryModal({
            title: 'Alma fortalecida!',
            tone: 'success',
            bodyHtml: `
                <p><strong>${targetName}</strong> recebeu um novo poder.</p>
                ${detail}
            `,
        });
        return;
    }

    if (jewelType === 'chaos') {
        const failTitle = 'Caos instavel';
        showInventoryModal({
            title: failTitle,
            tone: 'warning',
            bodyHtml: `
                <p>O caos nao transformou <strong>${targetName}</strong>.</p>
                <p>A joia foi consumida, mas o item permaneceu intacto.</p>
            `,
        });
        return;
    }

    const failTitle = jewelType === 'bless'
        ? 'Bencao falhou'
        : jewelType === 'reroll'
            ? 'Rerrolagem falhou'
            : 'Alma falhou';
    showInventoryModal({
        title: failTitle,
        tone: 'warning',
        bodyHtml: `
            <p>A melhoria em <strong>${targetName}</strong> nao teve sucesso.</p>
            <p>A joia foi consumida, mas o item permaneceu intacto.</p>
        `,
    });
}

function showSocketResultModal(targetItem, data) {
    const targetName = escapeHtml(itemLabel(targetItem));
    const effect = data.applied_effect || {};
    const propertyName = escapeHtml(effect.property_name || effect.property || 'Atributo');
    const value = escapeHtml(String(effect.value ?? '?'));
    const socketIndex = Number(data.socket_index ?? 0) + 1;

    showInventoryModal({
        title: 'Gema encaixada!',
        tone: 'success',
        bodyHtml: `
            <p><strong>${targetName}</strong> recebeu a gema no engaste ${socketIndex}.</p>
            <p><strong>${propertyName}</strong>: +${value}</p>
        `,
    });
}

const grids = inventoryStore.grids;
const itemIndex = inventoryStore.itemIndex;
const containerIndex = inventoryStore.containerIndex;
const dragSnapshots = inventoryStore.dragSnapshots;
let activeDrag = null;
let paperdollDragState = null;
let silent = false;
let loading = false;
let actionInFlight = false;
const inventoryMoveQueue = createInventoryMoveQueue({ maxInFlight: 1, maxQueued: 6 });
const containerDetailCache = createContainerDetailCache({ ttlMs: 45_000 });
const inventoryUxTelemetry = createInventoryUxTelemetry();
const moveRollbacks = inventoryStore.moveRollbacks;
let inventoryReloadQueued = false;
let equipmentSyncInFlight = 0;
let ghostPreviewFrame = 0;
let ghostPreviewCoords = null;
let dragMirrorEl = null;
let openContainerPublicIds = new Set(JSON.parse(localStorage.getItem('evolvaxe.inventory.openContainers') || '[]'));
let marketDeliveryOpen = localStorage.getItem('evolvaxe.inventory.marketDeliveryOpen') === '1';
let materialsPanelOpen = false;
let materialsVaultTab = 'materials';
let materialsActiveTab = 'metals';
let materialStash = { tabs: [], stacks: [], grid: { columns: 12, cell_px: 52 } };
let materialsLoading = false;
let materialStackIndex = new Map();
const CRAFT_DRAG_MIME = 'application/x-evolvaxe-craft-source';
const CRAFT_SLOT_COUNT = 6;
let craftPanelOpen = false;
let craftWorkspace = 'forge';
let craftSlots = {
    forge: Array.from({ length: CRAFT_SLOT_COUNT }, () => null),
    alchemy: Array.from({ length: CRAFT_SLOT_COUNT }, () => null),
};
let craftPreview = null;
let craftPreviewLoading = false;
let craftWorkspaces = [];
let craftPickerTab = 'inventory';
let craftPickerMaterialTab = 'fragments';
let craftPickerQuery = '';
let craftActiveSlotIndex = null;

configureExplorationUi({
    apiFetch,
    escapeHtml,
    handleError,
    toast,
    formatGameMoney,
    itemIndex,
    explorationPanelRoot,
    syncDrawerUi: () => syncDrawerUi(),
    closeMissionsPanel: () => closeMissionsPanel(),
    reloadContainerPanelsOnly: () => reloadContainerPanelsOnly(),
    invalidateContainerCache: () => containerDetailCache.invalidate(),
    refreshPlayerHud: () => loadPlayerHudEarly(),
    closeSiblingPanels: () => {
        setMarketPanelOpen(false);
        materialsPanelOpen = false;
        craftPanelOpen = false;
    },
});

configureExplorationArena({
    apiFetch,
    escapeHtml,
    handleError,
    toast,
    openModal,
    loadInventory,
    trackUx: (metric, meta) => inventoryUxTelemetry.start(metric, meta),
    renderExplorationPanel: () => renderExplorationPanel(),
    softRefreshArenaStage: () => softRefreshArenaStage(),
    getExplorationBiomeCode: () => getExplorationBiomeCode(),
    getExplorationExpedition: () => getExplorationExpedition(),
    setExplorationExpedition: (value) => setExplorationExpedition(value),
    setExplorationPosition: (value) => setExplorationPosition(value),
    setExplorationObjects: (value) => setExplorationObjects(value),
    setExplorationMap: (value) => setExplorationMap(value),
    setExplorationModifiers: (value) => setExplorationModifiers(value),
    executeExplorationAnalyze: (objectPublicId, options = {}) => executeExplorationAnalyze(objectPublicId, options),
    executeExplorationAction: (objectPublicId, actionCode, options = {}) => executeExplorationAction(objectPublicId, actionCode, options),
    listOwnedToolsByType: (toolType) => listOwnedToolsByType(toolType),
    explorationActionLabel: (actionCode) => explorationActionLabel(actionCode),
    loadExplorationObjects: (options = {}) => loadExplorationObjects(options),
    getPlayerHud: () => playerHudRoot?.dataset?.lastHud ? JSON.parse(playerHudRoot.dataset.lastHud) : null,
    getWallets: () => playerWallets,
    openEquipmentPanel: () => {
        leftDrawerTab = 'equipment';
        openLeftDrawer();
    },
    openStatsPanel: () => {
        openStatsDrawer();
    },
    openExpeditionCarryPanel: async () => {
        const expedition = [...containerIndex.values()].find((container) => containerKind(container) === 'expedition_carry')
            || allContainersCache.find((container) => containerKind(container) === 'expedition_carry');
        if (!expedition?.public_id) return false;
        openLeftDrawer();
        expeditionCarryOpen = true;
        persistContainerPanels();
        syncExpeditionBagPanel();
        highlightContainer(expedition.public_id);
        return true;
    },
});

configureMissionsUi({
    apiFetch,
    escapeHtml,
    handleError,
    toast,
    loadInventory: () => loadInventory(),
    missionsPanelRoot: () => missionsPanelRoot,
    missionsListRoot: () => missionsListRoot,
    missionsTabsRoot: () => missionsTabsRoot,
    onPanelVisibilityChange: (open) => {
        if (open) {
            setMarketPanelOpen(false);
            materialsPanelOpen = false;
            craftPanelOpen = false;
            if (isExplorationPanelOpen()) closeExplorationPanel();
        }
        app?.classList.toggle('is-missions-open', open);
        syncOverlayState();
        syncDrawerUi();
    },
});
bindMissionsUi();

const marketPanelRoot = document.querySelector('[data-inventory-market]');
const marketListingsRoot = document.querySelector('[data-market-listings]');
const marketWalletsRoot = document.querySelector('[data-market-wallets]');

configureMarketUi({
    apiFetch,
    escapeHtml,
    handleError,
    toast,
    setStatus: (message) => setStatus(message),
    syncDrawerUi: () => syncDrawerUi(),
    closeMissionsPanel: () => closeMissionsPanel(),
    closeExplorationPanel: () => closeExplorationPanel(),
    isExplorationPanelOpen: () => isExplorationPanelOpen(),
    reloadContainerPanelsOnly: () => reloadContainerPanelsOnly(),
    invalidateContainerCache: () => containerDetailCache.invalidate(),
    confirmInventoryAction: (options) => confirmInventoryAction(options),
    itemLabel: (item) => itemLabel(item),
    itemAssetUrl: (item) => itemAssetUrl(item),
    itemTooltip: (item, options = {}) => itemTooltip(item, options),
    upgradeLevelFromItem: (item) => upgradeLevelFromItem(item),
    resolveItemTypeMeta: (item) => resolveItemTypeMeta(item),
    walletBalance: (code) => walletBalance(code),
    isBusy: () => actionInFlight || loading,
    setActionInFlight: (value) => { actionInFlight = Boolean(value); },
    closeSiblingPanels: () => {
        materialsPanelOpen = false;
        craftPanelOpen = false;
    },
    marketPanelRoot,
    marketListingsRoot,
    marketWalletsRoot,
});

configureItemInvestigation({
    apiFetch,
    escapeHtml,
    handleError,
    openModal,
    setStatus: (message) => setStatus(message),
    itemLabel: (item) => itemLabel(item),
    itemAssetUrl: (item) => itemAssetUrl(item),
    upgradeLevelFromItem: (item) => upgradeLevelFromItem(item),
    resolveItemTypeMeta: (item) => resolveItemTypeMeta(item),
    isBusy: () => actionInFlight || loading,
    setActionInFlight: (value) => { actionInFlight = Boolean(value); },
    executeItemAction: (item, action) => executeItemAction(item, action),
    unsocketGem: (item, socketIndex) => unsocketGem(item, socketIndex, confirmInventoryAction),
    softRefreshAfterEnhanceSocket: (a, b) => softRefreshAfterEnhanceSocket(a, b),
    refreshEquipmentOnly: () => refreshEquipmentOnly(),
});

configureInventoryMaxFeatures({
    apiFetch,
    escapeHtml,
    toast,
    handleError,
    syncDrawerUi: () => syncDrawerUi(),
    refreshEquipmentOnly: () => refreshEquipmentOnly(),
    reloadContainerPanelsOnly: () => reloadContainerPanelsOnly(),
    closeOverlappingPanels: () => {
        setMarketPanelOpen(false);
        materialsPanelOpen = false;
        craftPanelOpen = false;
        closeMissionsPanel();
        if (isExplorationPanelOpen()) closeExplorationPanel();
    },
});
initInventoryMaxFeatures();

let playerWallets = [];
const INVENTORY_FILTER_STORAGE_KEY = 'evolvaxe.inventory.filters';
const inventoryFilterDefaults = {
    preset: '',
    q: '',
    rarity: '',
    category: '',
    flag: '',
};
let inventoryFilters = loadInventoryFilterState();
let inventoryFiltersCollapsed = localStorage.getItem('evolvaxe.inventory.filtersCollapsed') !== '0';
let expeditionCarryOpen = localStorage.getItem('evolvaxe.inventory.expeditionCarryOpen') === '1';
let leftDrawerOpen = localStorage.getItem('evolvaxe.inventory.leftDrawer') === '1';
let rightDrawerOpen = localStorage.getItem('evolvaxe.inventory.rightDrawer') !== '0';
let statsDrawerOpen = localStorage.getItem('evolvaxe.inventory.statsDrawer') === '1';
let leftDrawerTab = localStorage.getItem('evolvaxe.inventory.leftDrawerTab') || 'equipment';
let focusedDrawer = localStorage.getItem('evolvaxe.inventory.focusedDrawer') || 'right';
let lastCharacterStats = [];
let lastPlayerHud = null;
let equippedBackpackPublicId = null;
let playerPower = null;
let currentEquipment = [];
let currentEquipmentLinks = [];
let currentSetBonuses = [];
let comparePanelState = null;
let comparePickState = null;
let splitViewState = JSON.parse(localStorage.getItem('evolvaxe.inventory.splitView') || 'null');
let inventorySummaryByPublicId = new Map();
let allContainersCache = [];
let selectedItemPublicIds = new Set();
let lastSelectedItemPublicId = null;

const CELL_SIZE = 44;
const gridCellSizes = inventoryStore.gridCellSizes;

configureInventoryGridEngine({
    grids,
    itemIndex,
    containerIndex,
    containerDetailCache,
    getActiveDrag: () => activeDrag,
    getAllContainersCache: () => allContainersCache,
    cellSizeForContainer: (containerPublicId) => cellSizeForContainer(containerPublicId),
    resolveCellSize: (gridNode) => resolveCellSize(gridNode),
    setSilent: (value) => { silent = Boolean(value); },
    cssEscape,
});

configureContainerPanels({
    grids,
    gridCellSizes,
    itemIndex,
    containerIndex,
    openContainerPublicIds,
    containerDetailCache,
    inventoryUxTelemetry,
    apiFetch,
    getContainerRoot: () => containerRoot,
    getAllContainersCache: () => allContainersCache,
    getInventorySummaryByPublicId: () => inventorySummaryByPublicId,
    getPlayerWallets: () => playerWallets,
    isLoading: () => loading,
    isActionInFlight: () => actionInFlight,
    isBusy: () => Boolean(activeDrag || paperdollDragState || loading),
    scheduleIdleUiSync,
    setSilent: (value) => { silent = Boolean(value); },
    setStatus: (message) => setStatus(message),
    handleError: (error, fallback) => handleError(error, fallback),
    loadInventory: () => loadInventory(),
    persistContainerPanels: () => persistContainerPanels(),
    upsertContainerCache: (container) => upsertContainerCache(container),
    renderContainer: (container, summaryEntry, options) => renderContainer(container, summaryEntry, options || {}),
    initializeGrid: (container, gridNode) => initializeGrid(container, gridNode),
    addItems: (container, grid) => addItems(container, grid),
    dimensionsForState: (item, rotated) => dimensionsForState(item, rotated),
    renderItem: (item, options) => renderItem(item, options || {}),
    bindItemWidget: (container, item, widget) => bindItemWidget(container, item, widget),
    prefetchItemActions: (container) => prefetchItemActionsForContainer(container),
    renderSummary: (summary, wallets) => renderSummary(summary, wallets),
    updateContainerOccupancyBadge: (containerPublicId) => updateContainerOccupancyBadge(containerPublicId),
    applyInventoryFilters: () => applyInventoryFilters(),
});

configureFloatingBags({
    grids,
    gridCellSizes,
    itemIndex,
    containerIndex,
    openContainerPublicIds,
    containerDetailCache,
    escapeHtml,
    apiFetch,
    fetchContainerDetail,
    snapshotContainerDetailToCache,
    unmountContainerPanel,
    persistContainerPanels: () => persistContainerPanels(),
    setSilent: (value) => { silent = Boolean(value); },
    setStatus: (message) => setStatus(message),
    handleError: (error, fallback) => handleError(error, fallback),
    containerDisplayName: (container) => containerDisplayName(container),
    renderContainer: (container, summaryEntry, options) => renderContainer(container, summaryEntry, options || {}),
    initializeGrid: (container, gridNode) => initializeGrid(container, gridNode),
    addItems: (container, grid) => addItems(container, grid),
    prefetchItemActions: (container) => prefetchItemActionsForContainer(container),
    bindContainerLinks: () => bindContainerLinks(),
    applyInventoryFilters: () => applyInventoryFilters(),
    highlightContainer: (containerPublicId) => highlightContainer(containerPublicId),
});

const INVENTORY_DRAG_ENGINE = 'v2';
const BLESS_JEWEL_CODES = ['jewel_blessing_minor'];
const SOUL_JEWEL_CODES = ['jewel_soul_minor'];
const CHAOS_JEWEL_CODES = ['jewel_chaos_minor'];
const REROLL_JEWEL_CODES = ['jewel_reroll_minor'];
const ITEM_TYPE_META = {
    weapon: { label: 'Arma', icon: '⚔', tone: 'offense' },
    armor: { label: 'Armadura', icon: '🛡', tone: 'defense' },
    tool: { label: 'Ferramenta', icon: '🔧', tone: 'utility' },
    material: { label: 'Material', icon: '🪨', tone: 'material' },
    consumable: { label: 'Consumivel', icon: '🧪', tone: 'consumable' },
    currency: { label: 'Moeda', icon: '🪙', tone: 'currency' },
};
configureInventoryEquipmentUi({
    getEquipmentRoot: () => equipmentRoot,
    isPointerInsideElement: (element, clientX, clientY) => isPointerInsideElement(element, clientX, clientY),
    apiFetch,
    toast,
    escapeHtml,
    rarityKey: (item) => rarityKey(item),
    handleError: (error, fallback) => handleError(error, fallback),
    setStatus: (message) => setStatus(message),
    playInventoryFeedback: (kind) => playInventoryFeedback(kind),
    isEquippableItem: (item) => isEquippableItem(item),
    clearDragMirror: () => clearDragMirror(),
    clearAllGhostPreviews: () => clearAllGhostPreviews(),
    renderGhostPreview: (...args) => renderGhostPreview(...args),
    resolveUnequipDropTarget: (...args) => resolveUnequipDropTarget(...args),
    placeItemInContainerLocally: (...args) => placeItemInContainerLocally(...args),
    consumeItemLocally: (itemPublicId) => consumeItemLocally(itemPublicId),
    containerDetailCache,
    invalidateItemActionsCache,
    scheduleIdleUiSync,
    pumpIdleUiSync,
    resyncContainerPanel,
    reloadContainerPanelsOnly: () => reloadContainerPanelsOnly(),
    renderDockHotbar: (equipment) => renderDockHotbar(equipment),
    renderItem: (item, options) => renderItem(item, options || {}),
    renderPlayerHud: (hud) => renderPlayerHud(hud),
    renderCharacterStats: (...args) => renderCharacterStats(...args),
    renderEquipmentAttributes: (hud) => renderEquipmentAttributes(hud),
    getStatsDrawerPanel: () => statsDrawerPanel,
    isLeftDrawerOpen: () => leftDrawerOpen,
    isStatsDrawerOpen: () => statsDrawerOpen,
    getLastPlayerHud: () => lastPlayerHud,
    getActiveDrag: () => activeDrag,
    getPaperdollDragState: () => paperdollDragState,
    setPaperdollDragState: (value) => { paperdollDragState = value; },
    hideAllItemTooltips,
    closeContextMenu,
    openContextMenu,
    attachItemTooltip,
    openComparePanel: (item) => openComparePanel(item),
    toggleExpeditionBag: (forceOpen) => toggleExpeditionBag(forceOpen),
    openLinkedContainerForItem: (item) => openLinkedContainerForItem(item),
    findHotbarSlotUnderPointer: (x, y) => findHotbarSlotUnderPointer(x, y),
    findGridUnderPointer: (x, y) => findGridUnderPointer(x, y),
    getCurrentEquipment: () => currentEquipment,
    setCurrentEquipment: (value) => { currentEquipment = value; },
    getCurrentEquipmentLinks: () => currentEquipmentLinks,
    setCurrentEquipmentLinks: (value) => { currentEquipmentLinks = value; },
    getCurrentSetBonuses: () => currentSetBonuses,
    setCurrentSetBonuses: (value) => { currentSetBonuses = value; },
    getLastCharacterStats: () => lastCharacterStats,
    setLastCharacterStats: (value) => { lastCharacterStats = value; },
    getPlayerPower: () => playerPower,
    setPlayerPower: (value) => { playerPower = value; },
    getEquippedBackpackPublicId: () => equippedBackpackPublicId,
    setEquippedBackpackPublicId: (value) => { equippedBackpackPublicId = value; },
    getEquipmentSyncInFlight: () => equipmentSyncInFlight,
    setEquipmentSyncInFlight: (value) => { equipmentSyncInFlight = value; },
});

if (window.GridStack) {
    window.GridStack.renderCB = (element, widget) => {
        element.innerHTML = widget.content || '';
    };
}

function setStatus(message) {
    if (statusNode) statusNode.textContent = message;
}

function escapeHtml(value) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(value ?? '').replace(/[&<>"']/g, (char) => map[char]);
}

function cssEscape(value) {
    if (window.CSS?.escape) return window.CSS.escape(String(value));
    return String(value).replace(/["\\]/g, '\\$&');
}

function loadInventoryFilterState() {
    try {
        const raw = JSON.parse(localStorage.getItem('evolvaxe.inventory.filters') || '{}');
        return {
            preset: String(raw.preset || ''),
            q: String(raw.q || ''),
            rarity: String(raw.rarity || ''),
            category: String(raw.category || ''),
            flag: String(raw.flag || ''),
        };
    } catch {
        return { ...inventoryFilterDefaults };
    }
}

function persistInventoryFilters() {
    localStorage.setItem(INVENTORY_FILTER_STORAGE_KEY, JSON.stringify(inventoryFilters));
}

function itemLabel(item) {
    const containerName = item?.linked_container?.name;
    if (containerName && String(containerName).trim()) {
        return String(containerName).trim();
    }

    return item.item_name || item.definition?.name || item.definition?.code || 'Item';
}

function rarityKey(item) {
    const bucket = String(item.quality_bucket || 'common').trim().toLowerCase();
    if (['normal', 'basic', 'white'].includes(bucket)) return 'common';
    if (['uncommon', 'green'].includes(bucket)) return 'uncommon';
    if (['magic', 'blue'].includes(bucket)) return 'magic';
    if (['rare', 'yellow'].includes(bucket)) return 'rare';
    if (['legendary', 'gold', 'mythic', 'orange'].includes(bucket)) return 'legendary';
    if (['epic', 'heroic', 'purple'].includes(bucket)) return 'epic';
    if (['divine', 'unique', 'relic', 'pink', 'rosy'].includes(bucket)) return 'divine';

    return /^[a-z0-9_-]+$/i.test(bucket) ? bucket : 'common';
}

function rarityLabel(item) {
    const labels = {
        common: 'Comum',
        uncommon: 'Incomum',
        magic: 'Magico',
        rare: 'Raro',
        legendary: 'Lendario',
        epic: 'Epico',
        divine: 'Divino',
    };

    return labels[rarityKey(item)] || String(item.quality_bucket || 'Comum');
}

function formatItemPropertyValue(property) {
    const value = property.value ?? property.rolled_value ?? 0;
    const numeric = Number(value);
    const formatted = Number.isFinite(numeric) && !Number.isInteger(numeric)
        ? numeric.toFixed(1)
        : String(value);

    return `${formatted}${property.unit ? property.unit : ''}`;
}

function clampPropertyValueToRange(item, property) {
    const numeric = Number(property.value ?? property.rolled_value ?? 0);
    const code = String(property.code || '');
    if (!Number.isFinite(numeric) || !BASE_STAT_CODES.includes(code)) {
        return numeric;
    }

    const bounds = statRangeBounds(item, code);
    return Math.max(bounds.min, Math.min(bounds.max, numeric));
}

function formatGameMoney(value, unit = 'G') {
    const numeric = Number(value || 0);
    if (!Number.isFinite(numeric) || numeric <= 0) return '-';

    const formatted = numeric.toLocaleString('pt-BR', {
        minimumFractionDigits: Number.isInteger(numeric) ? 0 : 2,
        maximumFractionDigits: 2,
    });

    return `${formatted} ${unit}`;
}

function isSocketedGemProperty(property) {
    const source = String(property?.source || '');
    return source === 'gem' || source.startsWith('socketed_gem_');
}

function socketGemEffect(item, socket) {
    const source = `socketed_gem_${socket.index}`;
    const property = (Array.isArray(item.properties) ? item.properties : [])
        .find((entry) => String(entry.source || '') === source);

    if (!property) {
        return null;
    }

    return {
        name: property.name,
        value: formatItemPropertyValue(property),
    };
}

function socketGemAssetUrl(gem) {
    const code = String(gem?.definition_code || '').trim();
    if (!/^[a-z0-9_-]+$/i.test(code)) return null;
    if (!GAME_ITEM_ASSET_CODES.has(code)) return null;

    return `/assets/game/items/${code}.png`;
}

function socketNestedTooltipHtml(item, socket) {
    if (!socket?.gem) {
        return `
            <div class="inventory-socket-nested-tooltip">
                <strong>Engaste vazio</strong>
                <small>Arraste uma gema para preencher este espaco.</small>
            </div>
        `;
    }

    const effect = socketGemEffect(item, socket);
    const rarity = escapeHtml(String(socket.gem.rarity || socket.gem.quality_bucket || 'comum'));
    const code = escapeHtml(String(socket.gem.definition_code || ''));
    const effectLine = effect
        ? `<div class="is-effect">+${escapeHtml(effect.value)} ${escapeHtml(effect.name)}</div>`
        : '';

    return `
        <div class="inventory-socket-nested-tooltip">
            <strong>${escapeHtml(socket.gem.name || 'Gema')}</strong>
            ${effectLine}
            <small>${rarity}${code ? ` · ${code}` : ''}</small>
            <small>Gema engastada no item.</small>
        </div>
    `;
}

function renderSocketCell(item, socket) {
    if (!socket.gem) {
        return '<span class="inventory-tooltip-socket is-empty" title="Engaste vazio">+</span>';
    }

    const effect = socketGemEffect(item, socket);
    const assetUrl = socketGemAssetUrl(socket.gem);
    const effectLabel = effect
        ? `+${escapeHtml(effect.value)} ${escapeHtml(effect.name)}`
        : escapeHtml(socket.gem.name);
    const index = Number(socket.index ?? 0);

    return `
        <span class="inventory-tooltip-socket is-filled" data-socket-tooltip="1" data-socket-index="${index}" title="${effectLabel}">
            ${assetUrl
                ? `<img class="inventory-tooltip-socket-art" src="${escapeHtml(assetUrl)}" alt="${escapeHtml(socket.gem.name)}" loading="lazy">`
                : `<span class="inventory-tooltip-socket-fallback">${escapeHtml(socket.gem.name.charAt(0))}</span>`}
            <span class="inventory-tooltip-socket-effect">${effectLabel}</span>
        </span>
    `;
}

function containerStorageSummary(item) {
    const linked = item?.linked_container;
    if (!linked?.grid) return null;

    const columns = Number(linked.grid.columns || 0);
    const rows = Number(linked.grid.rows || 0);
    const capacity = Number(linked.capacity_cells || (columns * rows));
    const itemCount = Number(linked.item_count || 0);
    const percent = capacity > 0 ? Math.round((itemCount / capacity) * 100) : 0;

    return {
        label: `${columns}x${rows}`,
        detail: `${itemCount} item(ns) · ${percent}%`,
        itemCount,
        capacity,
    };
}

function containerItemBadge(item) {
    // Ocupacao (4x4 / N item(ns)) fica so no tooltip — evita ruido visual no grid.
    if (!item?.definition?.is_container) return '';
    return '';
}

function containerItemTooltipTag(item) {
    if (!item?.definition?.is_container) return null;
    if (item.definition?.equip_slot_code === 'backpack' && item.public_id === equippedBackpackPublicId) {
        return 'Expedicao ativa';
    }

    const storage = containerStorageSummary(item);
    return storage ? `Armazenamento ${storage.label}` : 'Armazenamento';
}

function isEquippableItem(item) {
    return Boolean(item?.definition?.equip_slot_code);
}

function isMergeableItem(item) {
    return Boolean(item?.definition?.stackable) && !isEquippableItem(item);
}

const BASE_STAT_CODES = ['strength', 'attack_power', 'defense', 'armor', 'agility', 'vitality', 'max_health', 'energy'];
const BASE_STAT_LABELS = {
    strength: 'Forca',
    attack_power: 'Forca',
    defense: 'Defesa',
    armor: 'Defesa',
    agility: 'Agilidade',
    vitality: 'Vitalidade',
    max_health: 'Vitalidade',
    energy: 'Energia',
};
const BASE_STAT_ORDER = ['strength', 'attack_power', 'defense', 'armor', 'agility', 'vitality', 'max_health', 'energy'];
const RARITY_RANGE_MULTIPLIER = {
    common: 1,
    uncommon: 1.06,
    magic: 1.12,
    rare: 1.2,
    legendary: 1.32,
    epic: 1.46,
    divine: 1.62,
};

function upgradeLevelFromItem(item) {
    const properties = Array.isArray(item?.properties) ? item.properties : [];
    const entry = properties.find((property) => String(property.code || '') === 'upgrade_level');
    if (!entry) return 0;
    return Number(entry.value ?? entry.integer_value ?? entry.numeric_value ?? 0) || 0;
}

function baseStatRangeLabel(item, propertyCode) {
    const { min, max } = statRangeBounds(item, propertyCode);
    return `${min}~${max}`;
}

function statRangeBounds(item, propertyCode) {
    const bounds = item?.stat_bounds?.[propertyCode];
    if (bounds) {
        const min = Math.max(1, Number(bounds.min ?? 1));
        const cap = Math.max(min, Number(bounds.cap ?? bounds.max ?? min));
        return { min, max: cap };
    }

    const quality = Number(item?.quality_value || 40);
    const mult = RARITY_RANGE_MULTIPLIER[rarityKey(item)] || 1;
    const levelBonus = 1 + (upgradeLevelFromItem(item) * 0.04);
    const ranges = {
        attack_power: [quality / 6, quality / 3],
        strength: [quality / 6, quality / 3],
        armor: [quality / 5, quality / 2.5],
        defense: [quality / 5, quality / 2.5],
        agility: [quality / 8, quality / 4],
        energy: [quality / 7, quality / 3.5],
        vitality: [quality / 4, quality / 2],
        max_health: [quality / 2, quality],
    };
    const [minFormula, maxFormula] = ranges[propertyCode] || [quality / 8, quality / 4];
    const min = Math.max(1, Math.round(minFormula * mult * levelBonus));
    const max = Math.max(min + 1, Math.round(maxFormula * mult * levelBonus));
    return { min, max };
}

function itemPowerValue(item) {
    const power = Number(item?.power ?? 0);
    return Number.isFinite(power) && power > 0 ? Math.round(power) : 0;
}

function propertyNumericValue(property) {
    const value = property?.value ?? property?.rolled_value ?? 0;
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : 0;
}

function compatibleComparisonSlots(slotCode) {
    if (slotCode === 'ring') return ['ring', 'ring_2'];
    if (slotCode === 'potion' || slotCode === 'consumable') return ['potion_1', 'potion_2', 'potion_3', 'potion_4'];
    if (slotCode === 'weapon') return ['weapon', 'weapon_offhand'];
    return [slotCode];
}

function comparisonEquippedItem(item) {
    const slotCode = item?.definition?.equip_slot_code;
    if (!slotCode || item?.equipped) return null;

    for (const code of compatibleComparisonSlots(slotCode)) {
        const slot = currentEquipment.find((entry) => entry.code === code);
        if (slot?.item && slot.item.public_id !== item.public_id) {
            return slot.item;
        }
    }

    return null;
}

function itemsShareCompareSlot(left, right) {
    const codeA = String(left?.definition?.equip_slot_code || '');
    const codeB = String(right?.definition?.equip_slot_code || '');
    if (!codeA || !codeB) return false;
    if (codeA === codeB) return true;
    const slotsA = new Set(compatibleComparisonSlots(codeA));
    return compatibleComparisonSlots(codeB).some((code) => slotsA.has(code)) || slotsA.has(codeB);
}

function clearComparePickHighlights() {
    document.querySelectorAll('.inventory-item.is-compare-target, .inventory-equipment-slot.is-compare-basis')
        .forEach((node) => node.classList.remove('is-compare-target', 'is-compare-basis'));
    document.documentElement.classList.remove('is-compare-picking');
}

function clearComparePick() {
    comparePickState = null;
    clearComparePickHighlights();
}

function beginComparePick(basisItem, mode = 'equipped') {
    if (!basisItem || !isEquippableItem(basisItem)) return;
    clearComparePickHighlights();
    comparePickState = { item: basisItem, mode };
    document.documentElement.classList.add('is-compare-picking');

    if (mode === 'equipped') {
        const slotEl = equipmentRoot?.querySelector(`[data-equipment-slot] .inventory-item[data-item-public-id="${cssEscape(basisItem.public_id)}"]`)
            ?.closest('.inventory-equipment-slot');
        slotEl?.classList.add('is-compare-basis');
    } else {
        document.querySelectorAll(`[data-item-public-id="${cssEscape(basisItem.public_id)}"]`)
            .forEach((node) => node.classList.add('is-compare-target'));
    }

    document.querySelectorAll('.inventory-item[data-item-public-id]').forEach((node) => {
        const publicId = node.getAttribute('data-item-public-id');
        if (!publicId || publicId === basisItem.public_id) return;
        const current = itemIndex.get(publicId)?.item;
        if (!current || current.equipped) return;
        if (!isEquippableItem(current) || !itemsShareCompareSlot(basisItem, current)) return;
        node.classList.add('is-compare-target');
    });

    toast(
        mode === 'equipped'
            ? 'Selecione um item do inventario para comparar. Esc cancela.'
            : 'Selecione outro item compativel para comparar. Esc cancela.',
        'info',
        4200
    );
}

function tryResolveComparePick(item) {
    if (!comparePickState?.item || !item) return false;
    if (item.public_id === comparePickState.item.public_id) return false;
    if (!isEquippableItem(item) || !itemsShareCompareSlot(comparePickState.item, item)) {
        toast('Item incompativel com a comparacao atual.', 'info', 2600);
        playInventoryFeedback('invalid');
        return true;
    }

    const basis = comparePickState.item;
    const mode = comparePickState.mode || 'equipped';
    clearComparePick();

    if (mode === 'equipped') {
        comparePanelState = { item, equipped: basis };
    } else {
        comparePanelState = { item: basis, equipped: item };
    }
    renderComparePanel();
    playInventoryFeedback('valid');
    return true;
}

function formatStatDelta(delta, unit = '') {
    const numeric = Number(delta);
    if (!Number.isFinite(numeric) || Math.abs(numeric) < 0.05) return '';
    const rounded = Number.isInteger(numeric) ? String(Math.round(numeric)) : numeric.toFixed(1);
    const sign = numeric > 0 ? '+' : '';
    const cls = numeric > 0 ? 'is-better' : 'is-worse';
    return `<span class="inventory-tooltip-delta ${cls}">${sign}${escapeHtml(rounded)}${escapeHtml(unit)}</span>`;
}

function renderTooltipStatLine(label, valueHtml, options = {}) {
    return `
        <li class="inventory-tooltip-stat-line">
            <span class="inventory-tooltip-stat-name">${escapeHtml(label)}</span>
            <span class="inventory-tooltip-stat-dots" aria-hidden="true"></span>
            <span class="inventory-tooltip-stat-value">${valueHtml}${options.delta || ''}</span>
        </li>
    `;
}

const BLESS_STAR_TIERS = ['bronze', 'silver', 'gold', 'purple', 'red'];

function blessStarState(level) {
    if (level <= 0) {
        return { tier: null, filled: 0 };
    }

    const tierIndex = Math.min(4, Math.floor((level - 1) / 5));
    const filled = ((level - 1) % 5) + 1;

    return { tier: BLESS_STAR_TIERS[tierIndex], filled };
}

function renderUpgradeStars(item) {
    if (!isEquippableItem(item)) return '';

    const level = upgradeLevelFromItem(item);
    if (level <= 0) return '';

    const { tier, filled } = blessStarState(level);
    const stars = Array.from({ length: 5 }, (_, index) => {
        const isFilled = index < filled;
        const tierClass = isFilled && tier ? ` is-tier-${tier}` : '';
        const filledClass = isFilled ? ' is-filled' : '';

        return `<span class="inventory-upgrade-star${filledClass}${tierClass}" aria-hidden="true">★</span>`;
    }).join('');

    return `<div class="inventory-upgrade-stars" aria-label="Nivel de melhoria ${level}">${stars}</div>`;
}

function baseStatRangeLabelBracketed(item, propertyCode) {
    return `[${baseStatRangeLabel(item, propertyCode).replace('~', ' - ')}]`;
}

function tooltipPropertyValue(item, property, options = {}) {
    const code = String(property.code || '');
    const value = options.showRange && BASE_STAT_CODES.includes(code)
        ? formatItemPropertyValue({ ...property, value: clampPropertyValueToRange(item, property) })
        : formatItemPropertyValue(property);
    const compareItem = options.compareWith || null;
    let delta = '';

    if (compareItem) {
        const compareProperty = (compareItem.properties || []).find((entry) => String(entry.code || '') === code);
        if (compareProperty) {
            delta = formatStatDelta(
                propertyNumericValue(property) - propertyNumericValue(compareProperty),
                property.unit || ''
            );
        }
    }

    if (options.showRange && BASE_STAT_CODES.includes(code)) {
        return `<strong>${escapeHtml(value)}</strong><small class="inventory-tooltip-range">${escapeHtml(baseStatRangeLabel(item, code).replace('~', '-'))}</small>${delta}`;
    }

    return `<strong>${escapeHtml(value)}</strong>${delta}`;
}

function uniqueBaseStatProperties(properties) {
    const byLabel = new Map();
    for (const property of properties || []) {
        const code = String(property.code || '');
        if (!code) continue;

        const label = String(BASE_STAT_LABELS[code] || property.name || code).toLowerCase();
        const previous = byLabel.get(label);
        if (!previous || propertyNumericValue(property) > propertyNumericValue(previous)) {
            byLabel.set(label, property);
        }
    }

    return Array.from(byLabel.values());
}

function jewelTooltipProperties(item) {
    const properties = (Array.isArray(item.properties) ? item.properties : [])
        .filter((property) => {
            const code = String(property.code || '');
            return ['upgrade_success_rate'].includes(code) || String(property.source || '') === 'upgrade_jewel';
        });

    if (!properties.length) return '';

    return `<ul class="inventory-tooltip-properties">${properties.map((property) => `<li><span>${escapeHtml(property.name)}</span>${tooltipPropertyValue(item, property)}</li>`).join('')}</ul>`;
}

function itemHasEconomy(item) {
    return Number(item?.market_value || 0) > 0
        || Number(item?.npc_value || 0) > 0
        || Number(item?.suggested_premium || 0) > 0;
}

function legacyTooltipEconomyFooter(item) {
    if (!itemHasEconomy(item)) return '';

    const quantity = Math.max(1, Number(item?.quantity || 1));
    const marketValue = Number(item?.market_value || 0);
    const npcValue = Number(item?.npc_value || 0);
    const suggestedPremium = Number(item?.suggested_premium || 0);
    const marketLabel = marketValue > 0 ? `${marketValue.toLocaleString('pt-BR')} G` : '—';
    const npcLabel = npcValue > 0 ? `${npcValue.toLocaleString('pt-BR')} G` : '—';
    const premiumLabel = suggestedPremium > 0 ? `${suggestedPremium.toLocaleString('pt-BR')} 💎` : '—';

    return `
        <footer class="inventory-tooltip-economy" aria-label="Valores de economia">
            <div class="inventory-tooltip-economy-item is-gold">
                <span class="inventory-tooltip-economy-label">Ouro NPC</span>
                <strong>${escapeHtml(npcLabel)}</strong>
            </div>
            <div class="inventory-tooltip-economy-item is-gold">
                <span class="inventory-tooltip-economy-label">Referencia</span>
                <strong>${escapeHtml(marketLabel)}</strong>
            </div>
            <div class="inventory-tooltip-economy-item is-premium">
                <span class="inventory-tooltip-economy-label">Eter Cristal</span>
                <strong>${escapeHtml(premiumLabel)}</strong>
            </div>
        </footer>
    `;
}

function tooltipSaleBlock(item) {
    return tooltipEconomyFooter(item);
}

function tooltipEconomyFooter(item) {
    if (!itemHasEconomy(item)) return '';

    const quantity = Math.max(1, Number(item?.quantity || 1));
    const npcValue = Number(item?.npc_value || 0);
    const suggestedPremium = Number(item?.suggested_premium || 0);
    const npcTotal = npcValue * quantity;
    const premiumTotal = suggestedPremium * quantity;
    const npcLabel = npcTotal > 0 ? formatGameMoney(npcTotal, 'G') : '-';
    const premiumLabel = premiumTotal > 0 ? formatGameMoney(premiumTotal, 'EC') : '-';
    const quantityHint = quantity > 1 ? `Valores totais da stack x${quantity.toLocaleString('pt-BR')}` : 'Valores por unidade';

    return `
        <footer class="inventory-tooltip-economy" aria-label="Valores de economia">
            <small class="inventory-tooltip-economy-note">${escapeHtml(quantityHint)}</small>
            <div class="inventory-tooltip-economy-item is-gold">
                <span class="inventory-tooltip-economy-label">Ouro venda</span>
                <strong>${escapeHtml(npcLabel)}</strong>
            </div>
            <div class="inventory-tooltip-economy-item is-premium">
                <span class="inventory-tooltip-economy-label">Eter Cristal</span>
                <strong>${escapeHtml(premiumLabel)}</strong>
            </div>
        </footer>
    `;
}

function itemCategoryCode(item) {
    const code = String(item?.category_code || item?.definition?.category_code || '').trim().toLowerCase();
    if (code) return code;
    if (isJewelItem(item)) return 'material';
    if (isEquippableItem(item)) {
        const slot = String(item?.definition?.equip_slot_code || '');
        if (['weapon', 'weapon_offhand'].includes(slot)) return 'weapon';
        return 'armor';
    }
    if (item?.definition?.is_container) return 'tool';
    if (isMergeableItem(item)) return 'consumable';
    return 'material';
}

function itemCategoryLabel(code) {
    const labels = {
        weapon: 'Armas',
        armor: 'Armaduras',
        material: 'Materiais',
        consumable: 'Consumiveis',
        tool: 'Utilitarios',
        currency: 'Moedas',
        gem: 'Gemas',
        jewel: 'Joias',
    };

    return labels[code] || String(code || 'Item');
}

function inventoryItemSearchText(item) {
    const parts = [
        itemLabel(item),
        item.definition?.name,
        item.definition?.code,
        item.item_name,
        item.category_code,
        item.definition?.category_code,
        rarityLabel(item),
        item.linked_container?.name,
    ];

    for (const property of (item.properties || [])) {
        parts.push(property.name, property.code, property.value);
    }
    for (const affix of (item.affixes || [])) {
        parts.push(affix.name, affix.code, affix.property_code, affix.value);
    }
    for (const socket of (item.sockets || [])) {
        parts.push(socket.gem?.name, socket.gem?.definition_code);
    }

    return parts.filter((value) => value !== null && value !== undefined)
        .join(' ')
        .toLowerCase();
}

function inventoryPresetMatchesItem(item) {
    const preset = String(inventoryFilters.preset || '');
    if (preset === '' || preset === 'all') return true;

    const flags = item.flags || {};
    const category = itemCategoryCode(item);
    const definitionCode = String(item.definition?.code || '').toLowerCase();

    if (preset === 'equipment') {
        return isEquippableItem(item);
    }

    if (preset === 'craft') {
        return ['material', 'consumable'].includes(category)
            || isJewelItem(item)
            || definitionCode.startsWith('gem_')
            || definitionCode.startsWith('jewel_');
    }

    if (preset === 'protected') {
        return Boolean(flags.locked || flags.favorite || flags.wishlist);
    }

    if (preset === 'sell') {
        return itemHasEconomy(item)
            && !flags.locked
            && !item.definition?.is_container
            && !item.equipped;
    }

    if (preset === 'containers') {
        return Boolean(item.definition?.is_container);
    }

    return true;
}

function inventoryItemMatchesFilters(item) {
    if (!inventoryPresetMatchesItem(item)) {
        return false;
    }

    const query = inventoryFilters.q.trim().toLowerCase();
    if (query !== '' && !inventoryItemSearchText(item).includes(query)) {
        return false;
    }

    if (inventoryFilters.rarity && rarityKey(item) !== inventoryFilters.rarity) {
        return false;
    }

    if (inventoryFilters.category && itemCategoryCode(item) !== inventoryFilters.category) {
        return false;
    }

    if (inventoryFilters.flag) {
        const flags = item.flags || {};
        if (inventoryFilters.flag === 'locked' && !flags.locked) return false;
        if (inventoryFilters.flag === 'favorite' && !flags.favorite) return false;
        if (inventoryFilters.flag === 'wishlist' && !flags.wishlist) return false;
        if (inventoryFilters.flag === 'equipped' && !item.equipped) return false;
        if (inventoryFilters.flag === 'container' && !item.definition?.is_container) return false;
        if (inventoryFilters.flag === 'upgrade' && upgradeLevelFromItem(item) <= 0) return false;
        if (inventoryFilters.flag === 'socketed' && !(item.sockets || []).some((socket) => socket.gem)) return false;
    }

    return true;
}

function hasActiveInventoryFilters() {
    return Boolean(inventoryFilters.preset || inventoryFilters.q.trim() || inventoryFilters.rarity || inventoryFilters.category || inventoryFilters.flag);
}

function resolveItemTypeMeta(item) {
    const code = itemCategoryCode(item);
    return ITEM_TYPE_META[code] || { label: 'Item', icon: '◆', tone: 'utility' };
}

function findSetContextForItem(item) {
    const definitionCode = String(item?.definition?.code || '');
    const publicId = String(item?.public_id || '');

    for (const link of currentEquipmentLinks) {
        const slots = Array.isArray(link.slots) ? link.slots : [];
        const matched = slots.some((slot) => (
            String(slot.definition_code || '') === definitionCode
            || String(slot.item_public_id || '') === publicId
        ));
        if (!matched) continue;

        const bonus = currentSetBonuses.find((entry) => entry.set_code === link.set_code);
        return {
            set_code: link.set_code,
            set_name: link.set_name,
            aura_color: link.aura_color,
            equipped_pieces: Number(bonus?.equipped_pieces || slots.length),
            bonuses: bonus?.bonuses || [],
        };
    }

    return null;
}

function renderTooltipHero(item) {
    const assetUrl = itemAssetUrl(item);
    const typeMeta = resolveItemTypeMeta(item);
    const upgradeLevel = upgradeLevelFromItem(item);

    return `
        <div class="inventory-tooltip-hero">
            <div class="inventory-tooltip-hero-art${assetUrl ? '' : ' is-placeholder'}">
                ${assetUrl ? `<img src="${escapeHtml(assetUrl)}" alt="" loading="lazy">` : `<span>${escapeHtml(typeMeta.icon)}</span>`}
            </div>
            <div class="inventory-tooltip-hero-copy">
                <div class="inventory-tooltip-hero-title">${escapeHtml(itemLabel(item))}</div>
                <div class="inventory-tooltip-hero-meta">
                    <span class="inventory-tooltip-type-badge is-${escapeHtml(typeMeta.tone)}">${escapeHtml(typeMeta.icon)} ${escapeHtml(typeMeta.label)}</span>
                    ${upgradeLevel > 0 ? `<span class="inventory-tooltip-upgrade">+${upgradeLevel}</span>` : ''}
                </div>
                ${isEquippableItem(item) ? `<div class="inventory-tooltip-stars">${renderUpgradeStars(item)}</div>` : ''}
            </div>
        </div>
    `;
}

function renderTooltipSetBlock(item) {
    const setContext = findSetContextForItem(item);
    if (!setContext) return '';

    const color = /^#[0-9a-f]{6}$/i.test(String(setContext.aura_color || ''))
        ? setContext.aura_color
        : '#55c58a';
    const bonusLines = (setContext.bonuses || [])
        .map((bonus) => `<small>${escapeHtml(bonus.description || `${bonus.name} +${bonus.value}${bonus.unit || ''}`)}</small>`)
        .join('');

    return `
        <div class="inventory-tooltip-set" style="--set-aura-color: ${escapeHtml(color)}">
            <strong>${escapeHtml(setContext.set_name)}</strong>
            <span>${Number(setContext.equipped_pieces || 0)} peca(s) equipada(s)</span>
            ${bonusLines}
        </div>
    `;
}

function renderToolMasteryBlock(item) {
    const mastery = item?.tool_mastery;
    if (!mastery) return '';

    const level = Number(mastery.level || 1);
    const xp = Number(mastery.xp || 0);
    const xpNext = Math.max(1, Number(mastery.xp_next || 1));
    const label = String(mastery.tool_type || 'tool')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (match) => match.toUpperCase());

    return `
        <div class="inventory-tooltip-tool-mastery">
            <div>
                <span>Maestria</span>
                <strong>${escapeHtml(label)} Nv. ${level.toLocaleString('pt-BR')}</strong>
            </div>
            <small>${xp.toLocaleString('pt-BR')} / ${xpNext.toLocaleString('pt-BR')} XP · ${Number(mastery.uses_count || 0).toLocaleString('pt-BR')} uso(s)</small>
            <i style="width:${percentBar(xp, xpNext)}%"></i>
        </div>
    `;
}

function formatAffixDisplayName(name) {
    return String(name || '').trim().replace(/^da\s+/i, '');
}

function isStorageContainerItem(item) {
    return Boolean(item?.definition?.is_container && item?.linked_container?.public_id);
}

/**
 * Bloqueia bag/bau dentro do proprio container vinculado (ex.: Alt+click bag → ela mesma).
 */
function canContainerAcceptItem(container, item) {
    if (!container || !item) return true;

    const summary = container.acceptance_summary;
    if (!summary) return true;

    if (summary.accepts_all || summary.allowed_categories == null) {
        if (item.definition?.is_container && summary.blocks_containers) {
            return false;
        }
        return true;
    }

    if (item.definition?.is_container) {
        return Boolean(summary.allows_container_items) && !summary.blocks_containers;
    }

    const category = itemCategoryCode(item);
    const allowed = new Set(summary.allowed_categories || []);
    return allowed.has(category);
}

function acceptanceRejectionMessage(container) {
    const summary = container?.acceptance_summary;
    if (!summary?.tooltip) {
        return 'Este container nao aceita o item selecionado.';
    }

    return summary.tooltip.endsWith('.') ? summary.tooltip : `${summary.tooltip}.`;
}

function renderItemTypeBadge() {
    return '';
}

function closeComparePanel() {
    comparePanelState = null;
    clearComparePick();
    if (!compareDockRoot) return;
    compareDockRoot.hidden = true;
    compareDockRoot.classList.remove('is-open');
    compareDockRoot.replaceChildren();
    compareDockRoot.onclick = null;
}

function isItemCurrentlyEquipped(item) {
    if (!item?.public_id) return false;
    if (item.equipped) return true;
    return (currentEquipment || []).some((slot) => slot?.item?.public_id === item.public_id);
}

function openComparePanel(item) {
    if (!compareDockRoot || !isEquippableItem(item)) return;

    if (comparePickState) {
        tryResolveComparePick(item);
        return;
    }

    if (isItemCurrentlyEquipped(item)) {
        beginComparePick({ ...item, equipped: true }, 'equipped');
        return;
    }

    const equipped = comparisonEquippedItem(item);
    if (equipped) {
        if (comparePanelState?.item?.public_id === item.public_id) {
            closeComparePanel();
            return;
        }
        comparePanelState = { item, equipped };
        renderComparePanel();
        return;
    }

    beginComparePick(item, 'inventory');
}

function renderCompareDetailMeta(item) {
    const typeMeta = resolveItemTypeMeta(item);
    const upgradeLevel = upgradeLevelFromItem(item);
    const rarity = rarityLabel(item);
    const sockets = Array.isArray(item.sockets) ? item.sockets : [];
    const filledSockets = sockets.filter((socket) => socket?.gem || socket?.status === 'filled').length;
    return `
        <div class="inventory-compare-detail-grid">
            <div class="inventory-compare-detail"><span>Raridade</span><strong>${escapeHtml(rarity)}</strong></div>
            <div class="inventory-compare-detail"><span>Tipo</span><strong>${escapeHtml(typeMeta.label)}</strong></div>
            <div class="inventory-compare-detail"><span>Melhoria</span><strong>${upgradeLevel > 0 ? `+${upgradeLevel}` : '—'}</strong></div>
            <div class="inventory-compare-detail"><span>Sockets</span><strong>${sockets.length ? `${filledSockets}/${sockets.length}` : '—'}</strong></div>
        </div>
    `;
}

function renderCompareBaseStats(item) {
    const properties = (Array.isArray(item.properties) ? item.properties : [])
        .filter((property) => BASE_STAT_CODES.includes(String(property.code || '')))
        .sort((a, b) => BASE_STAT_ORDER.indexOf(String(a.code)) - BASE_STAT_ORDER.indexOf(String(b.code)));
    const unique = uniqueBaseStatProperties(properties);
    if (!unique.length) return '';
    return `
        <ul class="inventory-compare-stats">
            ${unique.map((property) => {
                const value = propertyNumericValue(property);
                const formatted = Number.isInteger(value) ? String(value) : value.toFixed(1);
                return `<li><span>${escapeHtml(BASE_STAT_LABELS[property.code] || property.name || property.code)}</span><b>${escapeHtml(formatted)}${property.unit ? escapeHtml(property.unit) : ''}</b></li>`;
            }).join('')}
        </ul>
    `;
}

function renderComparePanel() {
    if (!compareDockRoot || !comparePanelState) {
        closeComparePanel();
        return;
    }

    const { item, equipped } = comparePanelState;
    const itemPower = itemPowerValue(item);
    const equippedPower = itemPowerValue(equipped);
    const powerDelta = itemPower - equippedPower;
    const candidatePros = renderCompareHighlights(item, equipped);
    const equippedPros = renderCompareHighlights(equipped, item);
    const canEquip = !item.equipped && isEquippableItem(item);
    const leftLabel = 'Candidato';
    const rightLabel = equipped?.equipped ? 'Equipado' : 'Referencia';

    compareDockRoot.hidden = false;
    compareDockRoot.classList.add('is-open');
    compareDockRoot.innerHTML = `
        <div class="inventory-compare-shell" role="dialog" aria-modal="true" aria-label="Comparacao de itens">
            <header class="inventory-compare-header">
                <div>
                    <p class="inventory-kicker">Comparacao</p>
                    <h3>${escapeHtml(itemLabel(item))}</h3>
                </div>
                <button type="button" class="inventory-compare-close" aria-label="Fechar comparacao">×</button>
            </header>
            <div class="inventory-compare-grid">
                ${renderCompareSideCard(leftLabel, item, equipped, candidatePros)}
                ${renderCompareSideCard(rightLabel, equipped, item, equippedPros)}
            </div>
            <footer class="inventory-compare-footer">
                <div class="inventory-compare-footer-power">
                    <span>Poder do candidato</span>
                    <strong>${itemPower}</strong>
                    ${formatStatDelta(powerDelta)}
                </div>
                <div class="inventory-compare-actions">
                    ${canEquip ? '<button type="button" class="inventory-button is-primary" data-compare-equip>Equipar candidato</button>' : ''}
                    <button type="button" class="inventory-button" data-compare-close>Fechar</button>
                </div>
            </footer>
            <small style="color:#64748b">Ctrl+clique no item ou no paperdoll · Esc / clique fora para fechar</small>
        </div>
    `;

    compareDockRoot.querySelectorAll('[data-compare-close], .inventory-compare-close').forEach((button) => {
        button.addEventListener('click', closeComparePanel);
    });
    compareDockRoot.querySelector('[data-compare-equip]')?.addEventListener('click', async () => {
        const actions = await apiFetch(`/api/items/${encodeURIComponent(item.public_id)}/actions`).catch(() => null);
        const equipAction = (actions?.data?.actions || []).find((entry) => entry.code === 'EQUIP');
        if (!equipAction) {
            toast('Nao foi possivel equipar este item agora.', 'warning', 2800);
            return;
        }
        await executeItemAction(item, equipAction);
        closeComparePanel();
    });
    compareDockRoot.onclick = (event) => {
        if (event.target === compareDockRoot) closeComparePanel();
    };
}

function renderCompareSideCard(label, item, compareWith, highlights) {
    const assetUrl = itemAssetUrl(item);
    const typeMeta = resolveItemTypeMeta(item);
    const upgradeLevel = upgradeLevelFromItem(item);
    const power = itemPowerValue(item);
    const comparePower = itemPowerValue(compareWith);
    const powerDelta = formatStatDelta(power - comparePower);

    return `
        <section class="inventory-compare-card rarity-${rarityKey(item)}">
            <span class="inventory-compare-label">${escapeHtml(label)}</span>
            <div class="inventory-compare-section inventory-compare-hero">
                <div class="inventory-compare-hero-art${assetUrl ? '' : ' is-placeholder'}">
                    ${assetUrl ? `<img src="${escapeHtml(assetUrl)}" alt="" loading="lazy">` : `<span>${escapeHtml(typeMeta.icon)}</span>`}
                </div>
                <div class="inventory-compare-hero-copy">
                    <strong>${escapeHtml(itemLabel(item))}</strong>
                    <div class="inventory-compare-hero-meta">
                        <span class="inventory-tooltip-type-badge is-${escapeHtml(typeMeta.tone)}">${escapeHtml(typeMeta.icon)} ${escapeHtml(typeMeta.label)}</span>
                        ${upgradeLevel > 0 ? `<span class="inventory-tooltip-upgrade">+${upgradeLevel}</span>` : ''}
                    </div>
                    <div class="inventory-compare-power-line">
                        <span>Poder</span>
                        <b>${power}</b>${powerDelta}
                    </div>
                </div>
            </div>
            <div class="inventory-compare-section">
                <span class="inventory-compare-section-title">Resumo</span>
                ${renderCompareDetailMeta(item)}
            </div>
            <div class="inventory-compare-section">
                <span class="inventory-compare-section-title">Atributos base</span>
                ${renderCompareBaseStats(item) || '<p class="inventory-compare-neutral">Sem atributos base.</p>'}
            </div>
            <div class="inventory-compare-section">
                <span class="inventory-compare-section-title">Diferencas</span>
                <div class="inventory-compare-highlights">
                    ${highlights.gains.length ? `<div class="inventory-compare-highlight is-positive"><span>Vantagens</span><ul>${highlights.gains.map((line) => `<li>${line}</li>`).join('')}</ul></div>` : ''}
                    ${highlights.losses.length ? `<div class="inventory-compare-highlight is-negative"><span>Desvantagens</span><ul>${highlights.losses.map((line) => `<li>${line}</li>`).join('')}</ul></div>` : ''}
                    ${!highlights.gains.length && !highlights.losses.length ? '<p class="inventory-compare-neutral">Sem diferencas relevantes.</p>' : ''}
                </div>
            </div>
            <div class="inventory-compare-section">
                <span class="inventory-compare-section-title">Set</span>
                ${renderTooltipSetBlock(item) || '<p class="inventory-compare-neutral">Sem set.</p>'}
            </div>
            <div class="inventory-compare-spacer" aria-hidden="true"></div>
        </section>
    `;
}

function renderCompareHighlights(item, compareWith) {
    const gains = [];
    const losses = [];
    const seen = new Set();

    const pushDelta = (label, delta, unit = '') => {
        const key = `${label}:${delta}`;
        if (seen.has(key) || Math.abs(delta) < 0.05) return;
        seen.add(key);
        const formatted = formatStatDelta(delta, unit).replace(/[()]/g, '');
        const text = `${escapeHtml(label)} <strong>${formatted || `${delta > 0 ? '+' : ''}${Number.isInteger(delta) ? delta : delta.toFixed(1)}${unit ? ` ${escapeHtml(unit)}` : ''}`}</strong>`;
        if (delta > 0) gains.push(text);
        if (delta < 0) losses.push(text);
    };

    for (const property of (item.properties || [])) {
        const code = String(property.code || '');
        if (!BASE_STAT_CODES.includes(code)) continue;
        const compareProperty = (compareWith.properties || []).find((entry) => String(entry.code || '') === code);
        if (!compareProperty) continue;
        pushDelta(BASE_STAT_LABELS[code] || property.name, propertyNumericValue(property) - propertyNumericValue(compareProperty), property.unit || '');
    }

    for (const affix of (item.affixes || [])) {
        const compareAffix = (compareWith.affixes || []).find((entry) => String(entry.property_code || '') === String(affix.property_code || ''));
        const currentValue = propertyNumericValue(affix);
        const compareValue = compareAffix ? propertyNumericValue(compareAffix) : 0;
        pushDelta(formatAffixDisplayName(affix.name), currentValue - compareValue, affix.unit || '');
    }

    return { gains: gains.slice(0, 8), losses: losses.slice(0, 8) };
}

function itemTooltip(item, options = {}) {
    const inline = Boolean(options.inline);
    const compareWith = options.compareWith || (inline ? null : comparisonEquippedItem(item));
    const quantity = Number(item.quantity || 1);
    const equippable = isEquippableItem(item);
    const jewel = isJewelItem(item);
    const mergeable = isMergeableItem(item);
    const categoryCode = itemCategoryCode(item);
    const typeMeta = resolveItemTypeMeta(item);
    const upgradeLevel = upgradeLevelFromItem(item);
    const power = itemPowerValue(item);
    const comparePower = compareWith ? itemPowerValue(compareWith) : 0;
    const powerDelta = compareWith ? formatStatDelta(power - comparePower) : '';
    const tags = [
        escapeHtml(rarityLabel(item)),
        item.flags?.locked ? 'Travado' : null,
        item.flags?.favorite ? 'Favorito' : null,
        item.flags?.wishlist ? 'Wishlist' : null,
        mergeable ? 'Empilhavel' : null,
        !equippable ? containerItemTooltipTag(item) : null,
    ].filter(Boolean);

    const allProperties = (Array.isArray(item.properties) ? item.properties : [])
        .filter((property) => !isSocketedGemProperty(property));
    const affixes = jewel ? [] : (Array.isArray(item.affixes) ? item.affixes : []);
    const sockets = equippable ? (Array.isArray(item.sockets) ? item.sockets : []) : [];

    const hiddenPropertyCodes = new Set(['upgrade_level', 'upgrade_success_rate', 'socket_count']);
    const baseProperties = equippable
        ? allProperties
            .filter((property) => {
                const code = String(property.code || '');
                const source = String(property.source || '');
                return BASE_STAT_CODES.includes(code) && (source === 'base' || source === 'definition' || source === 'upgrade');
            })
            .sort((a, b) => BASE_STAT_ORDER.indexOf(String(a.code)) - BASE_STAT_ORDER.indexOf(String(b.code)))
        : [];
    const otherProperties = equippable
        ? allProperties.filter((property) => {
            const code = String(property.code || '');
            return !BASE_STAT_CODES.includes(code) && !hiddenPropertyCodes.has(code);
        })
        : [];

    const visibleBaseProperties = uniqueBaseStatProperties(baseProperties);
    const baseList = visibleBaseProperties.length
        ? `<ul class="inventory-tooltip-base-stats">${visibleBaseProperties.map((property) => renderTooltipStatLine(
            BASE_STAT_LABELS[property.code] || property.name,
            tooltipPropertyValue(item, property, { showRange: true, compareWith })
        )).join('')}</ul>`
        : '';
    const affixList = affixes.length
        ? `<ul class="inventory-tooltip-affixes">${affixes.map((affix) => {
            const compareAffix = compareWith
                ? (compareWith.affixes || []).find((entry) => String(entry.property_code || '') === String(affix.property_code || ''))
                : null;
            const delta = compareAffix
                ? formatStatDelta(propertyNumericValue(affix) - propertyNumericValue(compareAffix), affix.unit || '')
                : '';
            return renderTooltipStatLine(
                formatAffixDisplayName(affix.name),
                `<strong>+${escapeHtml(formatItemPropertyValue(affix))} ${escapeHtml(affix.property_name || '')}</strong>${delta}`
            );
        }).join('')}</ul>`
        : '';
    const extraPropertyList = otherProperties.length
        ? `<ul class="inventory-tooltip-properties">${otherProperties.map((property) => renderTooltipStatLine(
            property.name,
            tooltipPropertyValue(item, property, { compareWith })
        )).join('')}</ul>`
        : '';
    const socketList = sockets.length
        ? `<div class="inventory-tooltip-sockets" aria-label="Engastes">${sockets.map((socket) => renderSocketCell(item, socket)).join('')}</div>`
        : '';
    const storage = !equippable ? containerStorageSummary(item) : null;
    const storageBlock = storage
        ? `<div class="inventory-tooltip-storage">
            <div><span>Espaco interno</span><strong>${escapeHtml(storage.label)}</strong></div>
            <div><span>Ocupacao</span><strong>${escapeHtml(storage.detail)}</strong></div>
        </div>`
        : '';

    const metaBlock = equippable
        ? ''
        : `
            <dl class="inventory-tooltip-meta">
                ${!mergeable ? `<div><dt>Codigo</dt><dd>${escapeHtml(item.definition?.code || '-')}</dd></div>` : ''}
                ${mergeable || quantity > 1 ? `<div><dt>Quantidade</dt><dd>${quantity}</dd></div>` : ''}
                ${!mergeable && item.quality_value !== null && item.quality_value !== undefined ? `<div><dt>Qualidade</dt><dd>${Number(item.quality_value).toFixed(1)}</dd></div>` : ''}
            </dl>
            <small class="inventory-tooltip-hint">Arraste para mover. Pressione R ou Q durante o arraste para rotacionar.</small>
        `;

    const jewelProperties = jewel ? jewelTooltipProperties(item) : '';
    const setBlock = equippable ? renderTooltipSetBlock(item) : '';
    const toolMasteryBlock = categoryCode === 'tool' ? renderToolMasteryBlock(item) : '';
    const powerBlock = equippable && power > 0
        ? `<div class="inventory-tooltip-power">
            <span>Poder do item</span>
            <strong>${power}${powerDelta}</strong>
        </div>`
        : '';
    const compareHeader = compareWith
        ? `<div class="inventory-tooltip-compare-note">
            <span>vs <strong>${escapeHtml(itemLabel(compareWith))}</strong>${item.equipped ? ' no inventario' : ' equipado'}</span>
            <small>Ctrl+clique para comparar lado a lado</small>
        </div>`
        : (!item.equipped && isEquippableItem(item)
            ? '<div class="inventory-tooltip-compare-hint">Ctrl+clique para comparar com o item equipado</div>'
            : '');

    const consumableBlock = categoryCode === 'consumable' || categoryCode === 'currency'
        ? `<div class="inventory-tooltip-section">
            <h4>Uso</h4>
            <p>${item.definition?.description ? escapeHtml(item.definition.description) : 'Item consumivel ou moeda.'}</p>
            ${mergeable || quantity > 1 ? `<p><strong>Quantidade:</strong> ${quantity}</p>` : ''}
        </div>`
        : '';

    const materialBlock = categoryCode === 'material' && !jewel
        ? `<div class="inventory-tooltip-section">
            <h4>Material</h4>
            ${mergeable || quantity > 1 ? `<p><strong>Quantidade:</strong> ${quantity}</p>` : ''}
            ${item.definition?.description ? `<p>${escapeHtml(item.definition.description)}</p>` : ''}
        </div>`
        : '';

    const details = [
        compareHeader,
        powerBlock,
        jewel ? jewelProperties : baseList,
        jewel ? '' : affixList,
        jewel ? '' : extraPropertyList,
        socketList,
        setBlock,
        toolMasteryBlock,
        storageBlock,
        consumableBlock,
        materialBlock,
        !inline && !jewel && categoryCode !== 'consumable' && categoryCode !== 'currency' && item.definition?.description
            ? `<p class="inventory-tooltip-description">${escapeHtml(item.definition.description)}</p>`
            : '',
        metaBlock,
    ].filter(Boolean).join('');

    const hero = inline ? '' : renderTooltipHero(item);
    const titleBlock = inline
        ? `<div class="inventory-tooltip-head">
            <div class="inventory-tooltip-title">${escapeHtml(itemLabel(item))}</div>
            ${equippable && upgradeLevel > 0 ? `<span class="inventory-tooltip-upgrade" title="Nivel de melhoria">+${upgradeLevel}</span>` : ''}
        </div>`
        : '';

    const economyFooter = tooltipEconomyFooter(item);

    return `
        <div class="inventory-tooltip rarity-${rarityKey(item)} is-type-${escapeHtml(categoryCode)}${inline ? ' is-inline' : ''}">
            ${hero}
            ${titleBlock}
            <div class="inventory-tooltip-tags">${tags.map((tag) => `<span>${tag}</span>`).join('')}</div>
            ${details}
            ${economyFooter}
        </div>
    `;
}

function itemAssetUrl(item) {
    const code = String(item.definition?.code || '').trim();
    if (!/^[a-z0-9_-]+$/i.test(code)) return null;
    if (!GAME_ITEM_ASSET_CODES.has(code)) return null;

    return `/assets/game/items/${code}.png`;
}

function persistContainerPanels() {
    localStorage.setItem('evolvaxe.inventory.openContainers', JSON.stringify([...openContainerPublicIds]));
    localStorage.setItem('evolvaxe.inventory.marketDeliveryOpen', marketDeliveryOpen ? '1' : '0');
    localStorage.setItem('evolvaxe.inventory.expeditionCarryOpen', expeditionCarryOpen ? '1' : '0');
}

function persistDrawerState() {
    localStorage.setItem('evolvaxe.inventory.leftDrawer', leftDrawerOpen ? '1' : '0');
    localStorage.setItem('evolvaxe.inventory.rightDrawer', rightDrawerOpen ? '1' : '0');
    localStorage.setItem('evolvaxe.inventory.statsDrawer', statsDrawerOpen ? '1' : '0');
    localStorage.setItem('evolvaxe.inventory.leftDrawerTab', leftDrawerTab);
    localStorage.setItem('evolvaxe.inventory.focusedDrawer', focusedDrawer);
}

const LAYOUT_TUTORIAL_KEY = 'evolvaxe.inventory.layoutTutorialV1';
const LAYOUT_TUTORIAL_STEPS = [
    {
        title: 'Equipamento (E)',
        body: 'Abre o paperdoll. Clique na mochila para abrir a bag ao lado. Use + para gastar pontos de atributo.',
    },
    {
        title: 'Inventario (I)',
        body: 'Grid limpo a direita. Arraste itens entre inventario, bag e containers.',
    },
    {
        title: 'Status (C) e comparacao',
        body: 'C mostra bonus equipados. Ctrl+clique no paperdoll ou no item compara com o inventario.',
    },
];

function dismissLayoutTutorial(completed = true) {
    const tip = document.querySelector('[data-layout-tutorial]');
    tip?.remove();
    if (completed) {
        localStorage.setItem(LAYOUT_TUTORIAL_KEY, '1');
    }
}

function maybeShowLayoutTutorial() {
    if (localStorage.getItem(LAYOUT_TUTORIAL_KEY) === '1') return;
    if (document.querySelector('[data-layout-tutorial]')) return;
    if (!app) return;

    let stepIndex = 0;
    const tip = document.createElement('aside');
    tip.className = 'inventory-layout-tutorial';
    tip.dataset.layoutTutorial = '1';
    tip.setAttribute('role', 'dialog');
    tip.setAttribute('aria-label', 'Guia rapido');

    const renderStep = () => {
        const step = LAYOUT_TUTORIAL_STEPS[stepIndex];
        const isLast = stepIndex >= LAYOUT_TUTORIAL_STEPS.length - 1;
        tip.innerHTML = `
            <p class="inventory-layout-tutorial-kicker">Guia rapido · ${stepIndex + 1}/${LAYOUT_TUTORIAL_STEPS.length}</p>
            <strong>${escapeHtml(step.title)}</strong>
            <p>${escapeHtml(step.body)}</p>
            <div class="inventory-layout-tutorial-actions">
                <button type="button" class="inventory-button" data-tutorial-skip>Pular</button>
                <button type="button" class="inventory-button is-primary" data-tutorial-next>${isLast ? 'Entendi' : 'Proximo'}</button>
            </div>
        `;
        tip.querySelector('[data-tutorial-skip]')?.addEventListener('click', () => dismissLayoutTutorial(true));
        tip.querySelector('[data-tutorial-next]')?.addEventListener('click', () => {
            if (isLast) {
                dismissLayoutTutorial(true);
                return;
            }
            stepIndex += 1;
            renderStep();
        });
    };

    renderStep();
    app.appendChild(tip);
}

function syncDrawerUi() {
    app?.classList.toggle('is-left-drawer-open', leftDrawerOpen);
    app?.classList.toggle('is-right-drawer-open', rightDrawerOpen);
    app?.classList.toggle('is-stats-drawer-open', statsDrawerOpen);
    app?.classList.toggle('is-expedition-bag-open', Boolean(expeditionCarryOpen && leftDrawerOpen));
    app?.classList.toggle('is-market-open', isMarketPanelOpen());
    app?.classList.toggle('is-materials-open', materialsPanelOpen);
    app?.classList.toggle('is-craft-open', craftPanelOpen);
    app?.classList.toggle('is-exploration-open', isExplorationPanelOpen());
    app?.classList.toggle('is-missions-open', isMissionsPanelOpen());
    app?.classList.toggle('is-set-codex-open', isSetCodexOpen());
    app?.classList.toggle('is-drawer-focus-left', focusedDrawer === 'left');
    app?.classList.toggle('is-drawer-focus-right', focusedDrawer === 'right');
    app?.classList.toggle('is-drawer-focus-stats', focusedDrawer === 'stats');
    if (backdropRoot) backdropRoot.hidden = !(leftDrawerOpen || rightDrawerOpen || statsDrawerOpen || isMarketPanelOpen() || materialsPanelOpen || craftPanelOpen || isExplorationPanelOpen() || isMissionsPanelOpen() || isSetCodexOpen());
    // Equipamento ARPG flutuante: mantém o hub visível por baixo
    if (hubRoot) {
        const heavyOverlay = rightDrawerOpen || statsDrawerOpen || isMarketPanelOpen() || materialsPanelOpen || craftPanelOpen || isExplorationPanelOpen() || isMissionsPanelOpen() || isSetCodexOpen();
        hubRoot.hidden = heavyOverlay;
    }
    if (statsDrawerRoot) statsDrawerRoot.hidden = !statsDrawerOpen;
    if (expeditionRoot) expeditionRoot.hidden = !(expeditionCarryOpen && leftDrawerOpen);
    equipmentRoot?.querySelector('.inventory-equipment-slot.is-backpack')
        ?.classList.toggle('is-bag-open', Boolean(expeditionCarryOpen && leftDrawerOpen));
    if (marketPanelRoot) {
        marketPanelRoot.hidden = !isMarketPanelOpen();
        marketPanelRoot.setAttribute('aria-hidden', isMarketPanelOpen() ? 'false' : 'true');
    }
    if (materialsPanelRoot) {
        materialsPanelRoot.hidden = !materialsPanelOpen;
        materialsPanelRoot.setAttribute('aria-hidden', materialsPanelOpen ? 'false' : 'true');
    }
    if (craftPanelRoot) {
        craftPanelRoot.hidden = !craftPanelOpen;
        craftPanelRoot.setAttribute('aria-hidden', craftPanelOpen ? 'false' : 'true');
    }
    const setCodexRoot = document.querySelector('[data-inventory-set-codex]');
    if (setCodexRoot) {
        setCodexRoot.hidden = !isSetCodexOpen();
        setCodexRoot.setAttribute('aria-hidden', isSetCodexOpen() ? 'false' : 'true');
    }
    if (explorationPanelRoot) {
        explorationPanelRoot.hidden = !isExplorationPanelOpen();
        explorationPanelRoot.setAttribute('aria-hidden', isExplorationPanelOpen() ? 'false' : 'true');
    }
    syncLeftDrawerOffset();
    if (expeditionCarryOpen && leftDrawerOpen) {
        window.requestAnimationFrame(() => alignExpeditionFlyoutToBackpack());
    }
}

function syncLeftDrawerOffset() {
    if (!app) return;
    const leftDrawer = document.querySelector('[data-inventory-drawer-left]');
    if (!leftDrawer || !leftDrawerOpen) {
        app.style.removeProperty('--left-drawer-width');
        return;
    }
    window.requestAnimationFrame(() => {
        const width = Math.ceil(leftDrawer.getBoundingClientRect().width);
        if (width > 0) {
            app.style.setProperty('--left-drawer-width', `${width}px`);
        }
    });
}

function syncOverlayState() {
    syncDrawerUi();
}

function walletBalance(code) {
    const wallet = playerWallets.find((entry) => entry.currency_code === code || entry.code === code);
    return Number(wallet?.balance || 0);
}

/* market panel UI moved to ./market-ui.js */

function materialAssetUrl(stack) {
    const url = String(stack?.icon_url || '').trim();
    if (url) return url;

    return null;
}

function materialTabIcon(tabCode) {
    const tab = (materialStash.tabs || []).find((entry) => entry.code === tabCode);
    return tab?.icon || '◆';
}

function materialStackKey(stack) {
    return String(stack?.stack_key || `${stack?.family_code || ''}::${stack?.origin_code || ''}`);
}

function buildCraftDragPayload(source) {
    if (!source || typeof source !== 'object') return null;

    if (source.kind === 'material_stack') {
        const stack = materialStackIndex.get(source.stack_key || materialStackKey(source)) || source;
        return {
            kind: 'material_stack',
            stack_key: materialStackKey(stack),
            family_code: stack.family_code || '',
            origin_code: stack.origin_code || '',
            label: stack.label || stack.family_name || 'Material',
            quantity_available: Number(stack.quantity || 1),
            asset_url: materialAssetUrl(stack),
            stash_tab: stack.stash_tab || materialsActiveTab,
        };
    }

    if (source.kind === 'item_instance' || source.public_id) {
        const item = source.item || source;
        return {
            kind: 'item_instance',
            item,
            public_id: item.public_id,
            label: itemLabel(item),
            quantity_available: Number(item.quantity || 1),
            asset_url: itemAssetUrl(item),
            category_code: itemCategoryCode(item),
            definition_code: item.definition?.code || '',
        };
    }

    return null;
}

function parseCraftDragPayload(dataTransfer) {
    if (!dataTransfer) return null;

    const raw = dataTransfer.getData(CRAFT_DRAG_MIME);
    if (!raw) return null;

    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

window.EvolvaxeCraft = {
    mime: CRAFT_DRAG_MIME,
    buildPayload: buildCraftDragPayload,
    parsePayload: parseCraftDragPayload,
};

function materialStackTooltip(stack) {
    const assetUrl = materialAssetUrl(stack);
    const tabIcon = materialTabIcon(stack.stash_tab);
    const familyDescription = String(stack.family_description || '').trim();
    const originDescription = String(stack.origin_description || '').trim();
    const description = familyDescription || originDescription || 'Material de crafting armazenado fora do inventario principal.';
    const originLine = stack.origin_name && stack.origin_name !== stack.family_name
        ? `<li><span>Origem</span><strong>${escapeHtml(stack.origin_name)}</strong></li>`
        : '';

    return `
        <div class="inventory-tooltip rarity-common is-type-material is-inline">
            <div class="inventory-tooltip-hero">
                <div class="inventory-tooltip-hero-art${assetUrl ? '' : ' is-placeholder'}">
                    ${assetUrl ? `<img src="${escapeHtml(assetUrl)}" alt="" loading="lazy">` : `<span>${escapeHtml(tabIcon)}</span>`}
                </div>
                <div class="inventory-tooltip-hero-copy">
                    <div class="inventory-tooltip-hero-title">${escapeHtml(stack.label || stack.family_name || 'Material')}</div>
                    <div class="inventory-tooltip-hero-meta">
                        <span class="inventory-tooltip-type-badge is-material">${escapeHtml(tabIcon)} Materia-prima</span>
                        <span class="inventory-tooltip-upgrade">x${Number(stack.quantity || 0).toLocaleString('pt-BR')}</span>
                    </div>
                </div>
            </div>
            <p class="inventory-tooltip-description">${escapeHtml(description)}</p>
            <ul class="inventory-tooltip-properties">
                <li><span>Familia</span><strong>${escapeHtml(stack.family_name || stack.family_code || '-')}</strong></li>
                ${originLine}
            </ul>
            <small class="inventory-tooltip-hint">Arraste para forja ou alquimia quando a receita estiver aberta.</small>
        </div>
    `;
}

function renderMaterialStack(stack) {
    const quantity = Number(stack.quantity || 0);
    const assetUrl = materialAssetUrl(stack);
    const tabIcon = materialTabIcon(stack.stash_tab);
    const stackKey = materialStackKey(stack);
    const label = stack.label || stack.family_name || 'Material';

    return `
        <div class="grid-stack-item inventory-materials-stack" data-material-stack-key="${escapeHtml(stackKey)}">
            <div class="grid-stack-item-content" data-material-stack-cell="${escapeHtml(stackKey)}">
                <div class="inventory-item is-tiny is-compact is-material-stack rarity-common${assetUrl ? ' has-art' : ''}"
                    data-material-stack-key="${escapeHtml(stackKey)}"
                    data-craft-draggable="material_stack"
                    draggable="true"
                    aria-label="${escapeHtml(label)}">
                    ${assetUrl ? `<img class="inventory-item-art" src="${escapeHtml(assetUrl)}" alt="" loading="lazy">` : `<span class="inventory-item-fallback" aria-hidden="true">${escapeHtml(tabIcon)}</span>`}
                    <span class="inventory-item-name">${escapeHtml(stack.family_name || label)}</span>
                    <span class="inventory-item-quantity">x${quantity.toLocaleString('pt-BR')}</span>
                </div>
            </div>
        </div>
    `;
}

function destroyMaterialStackTooltips() {
    if (!materialsListRoot) return;

    materialsListRoot.querySelectorAll('[data-material-stack-cell]').forEach((node) => {
        if (node._tippy) node._tippy.destroy();
    });
}

function bindMaterialStackInteractions() {
    destroyMaterialStackTooltips();
    if (!materialsListRoot) return;

    materialsListRoot.querySelectorAll('[data-material-stack-cell]').forEach((node) => {
        const stackKey = node.getAttribute('data-material-stack-cell') || '';
        const stack = materialStackIndex.get(stackKey);
        if (!stack || !window.tippy) return;

        window.tippy(node, {
            allowHTML: true,
            content: materialStackTooltip(stack),
            theme: 'evolvaxe-item',
            placement: 'auto',
            interactive: true,
            appendTo: () => document.body,
            popperOptions: { strategy: 'fixed' },
            delay: [160, 60],
        });
    });

    materialsListRoot.querySelectorAll('[data-craft-draggable]').forEach((node) => {
        node.addEventListener('dragstart', (event) => {
            const stackKey = node.getAttribute('data-material-stack-key') || '';
            const stack = materialStackIndex.get(stackKey);
            const payload = buildCraftDragPayload({ kind: 'material_stack', ...stack, stack_key: stackKey });
            if (!payload || !event.dataTransfer) return;

            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData(CRAFT_DRAG_MIME, JSON.stringify(payload));
            event.dataTransfer.setData('text/plain', payload.label || 'Material');
            node.classList.add('is-craft-dragging');
        });

        node.addEventListener('dragend', () => {
            node.classList.remove('is-craft-dragging');
        });
    });
}

function renderMaterialsPanel() {
    if (!materialsTabsRoot || !materialsListRoot) return;

    const vaultTabs = [
        { code: 'materials', name: 'Materiais', icon: '◆' },
        { code: 'gems', name: 'Gemas', icon: '💠' },
        { code: 'jewels', name: 'Joias', icon: '✦' },
        { code: 'sellables', name: 'Venda', icon: 'G' },
    ];
    const vaultMode = ['materials', 'gems', 'jewels', 'sellables'].includes(materialsVaultTab || 'materials')
        ? (materialsVaultTab || 'materials')
        : 'materials';

    const materialSubTabs = vaultMode === 'materials'
        ? (materialStash.material_tabs?.length
            ? materialStash.material_tabs
            : [
                { code: 'metals', name: 'Metais', icon: '⚙' },
                { code: 'gems', name: 'Gemas', icon: '💠' },
                { code: 'essences', name: 'Essencias', icon: '✦' },
                { code: 'fragments', name: 'Fragmentos', icon: '◆' },
            ])
        : [];

    materialsTabsRoot.innerHTML = `
        <div class="inventory-materials-vault-tabs">
            ${vaultTabs.map((tab) => `
                <button type="button" class="inventory-materials-tab${vaultMode === tab.code ? ' is-active' : ''}" data-materials-vault-tab="${escapeHtml(tab.code)}">
                    ${escapeHtml(tab.icon)} ${escapeHtml(tab.name)}
                </button>
            `).join('')}
        </div>
        ${materialSubTabs.length ? `
            <div class="inventory-materials-subtabs">
                ${materialSubTabs.map((tab) => `
                    <button type="button" class="inventory-materials-tab${materialsActiveTab === tab.code ? ' is-active' : ''}" data-materials-tab="${escapeHtml(tab.code)}">
                        ${escapeHtml(tab.icon || '◆')} ${escapeHtml(tab.name)}
                    </button>
                `).join('')}
            </div>
        ` : ''}
    `;

    if (materialsLoading) {
        materialsListRoot.className = 'inventory-materials-grid-host is-loading';
        materialsListRoot.innerHTML = '<p class="inventory-materials-empty">Carregando cofre...</p>';
        return;
    }

    if (vaultMode !== 'materials') {
        const items = materialStash.items || [];
        if (!items.length) {
            materialsListRoot.className = 'inventory-materials-grid-host is-empty';
            materialsListRoot.innerHTML = '<p class="inventory-materials-empty">Nenhum item nesta aba.</p>';
            return;
        }
        materialsListRoot.className = 'inventory-materials-grid-host';
        materialsListRoot.innerHTML = `
            <ul class="inventory-vault-item-list">
                ${items.map((item) => `
                    <li>
                        <strong>${escapeHtml(item.name || item.definition_name || item.definition_code)}</strong>
                        <small>${escapeHtml(item.quality_bucket || item.category_code || 'item')} · x${Number(item.quantity || 1)}</small>
                    </li>
                `).join('')}
            </ul>
        `;
        return;
    }

    const stacks = (materialStash.stacks || []).filter((stack) => stack.stash_tab === materialsActiveTab);
    const columns = Number(materialStash.grid?.columns || 12);
    const cellPx = Number(materialStash.grid?.cell_px || 52);
    materialStackIndex = new Map(stacks.map((stack) => [materialStackKey(stack), stack]));

    if (!stacks.length) {
        materialsListRoot.className = 'inventory-materials-grid-host is-empty';
        materialsListRoot.innerHTML = '<p class="inventory-materials-empty">Nenhum material nesta aba.</p>';
        return;
    }

    const rows = Math.max(1, Math.ceil(stacks.length / columns));
    materialsListRoot.className = 'inventory-materials-grid-host';
    materialsListRoot.innerHTML = `
        <div class="inventory-materials-grid"
            data-materials-grid
            style="--inventory-columns:${columns}; --inventory-rows:${rows}; --inventory-cell:${cellPx}px;">
            ${stacks.map((stack) => renderMaterialStack(stack)).join('')}
        </div>
    `;
    bindMaterialStackInteractions();
}

async function loadMaterialsStash() {
    if (!materialsPanelOpen || materialsLoading) return;

    materialsLoading = true;
    renderMaterialsPanel();

    try {
        const vaultTab = ['materials', 'gems', 'jewels', 'sellables'].includes(materialsVaultTab)
            ? materialsVaultTab
            : 'materials';
        if (vaultTab === 'materials') {
            const materialsResponse = await apiFetch('/api/inventory/materials');
            const materialData = materialsResponse.data || { tabs: [], stacks: [], grid: {} };
            materialStash = {
                material_tabs: materialData.tabs || [],
                stacks: materialData.stacks || [],
                items: [],
                grid: materialData.grid || { columns: 12, cell_px: 52 },
            };
            if (!(materialStash.material_tabs || []).some((tab) => tab.code === materialsActiveTab)) {
                materialsActiveTab = materialStash.material_tabs?.[0]?.code || 'metals';
            }
        } else {
            const response = await apiFetch(`/api/inventory/stash-vault?tab=${encodeURIComponent(vaultTab)}`);
            const data = response.data || {};
            materialStash = {
                material_tabs: [],
                stacks: [],
                items: data.items || [],
                grid: { columns: 8, cell_px: 52 },
            };
        }
    } catch (error) {
        materialStash = { material_tabs: [], stacks: [], items: [] };
        handleError(error, 'Nao foi possivel carregar o cofre.');
    } finally {
        materialsLoading = false;
        renderMaterialsPanel();
    }
}

function openMaterialsPanel() {
    setMarketPanelOpen(false);
    craftPanelOpen = false;
    if (isExplorationPanelOpen()) closeExplorationPanel();
    closeMissionsPanel();
    materialsPanelOpen = true;
    syncDrawerUi();
    loadMaterialsStash();
}

function closeMaterialsPanel() {
    materialsPanelOpen = false;
    syncDrawerUi();
}

function toggleMaterialsPanel() {
    if (materialsPanelOpen) closeMaterialsPanel();
    else openMaterialsPanel();
}

/* exploration UI moved to ./exploration-ui.js */

function craftStarPointStyle(index) {
    const angle = (-90 + (index * 60)) * (Math.PI / 180);
    const radius = 42;
    return {
        left: `${50 + (radius * Math.cos(angle))}%`,
        top: `${50 + (radius * Math.sin(angle))}%`,
    };
}

function currentCraftSlotState() {
    return craftSlots[craftWorkspace] || Array.from({ length: CRAFT_SLOT_COUNT }, () => null);
}

function craftWorkspaceMeta(code = craftWorkspace) {
    return craftWorkspaces.find((entry) => entry.code === code) || {
        code,
        name: code === 'alchemy' ? 'Alquimia' : 'Forja',
        subtitle: '',
        description: '',
        aura_color: code === 'alchemy' ? '#8b5cf6' : '#f59e0b',
        accent_color: code === 'alchemy' ? '#c084fc' : '#fbbf24',
    };
}

function craftSourceKey(payload) {
    if (!payload) return '';
    if (payload.kind === 'material_stack') return `material:${payload.stack_key || `${payload.family_code}::${payload.origin_code}`}`;
    if (payload.kind === 'item_instance') return `item:${payload.public_id}`;
    return '';
}

function craftAllocationCounts() {
    const counts = new Map();
    for (const slot of currentCraftSlotState()) {
        if (!slot?.source) continue;
        const key = craftSourceKey(slot.source);
        if (!key) continue;
        counts.set(key, (counts.get(key) || 0) + Number(slot.source.quantity || 1));
    }
    return counts;
}

function craftRemainingQuantity(payload) {
    if (!payload) return 0;
    const total = Number(payload.quantity_available || 1);
    const key = craftSourceKey(payload);
    const allocated = craftAllocationCounts().get(key) || 0;
    return Math.max(0, total - allocated);
}

function craftCanAddPayload(payload) {
    return craftRemainingQuantity(payload) > 0;
}

function craftItemEligibility(item) {
    if (!item) return { ok: false, reason: 'Item invalido' };
    if (item.flags?.locked) return { ok: false, reason: 'Travado' };
    if (item?.definition?.is_collectible) return { ok: false, reason: 'Colecionavel' };
    if (item?.definition?.is_event_item) return { ok: false, reason: 'Evento' };
    if (item?.definition?.is_container && Number(item?.linked_container?.item_count || 0) > 0) {
        return { ok: false, reason: 'Bau cheio' };
    }
    if (item?.state && item.state !== 'available') return { ok: false, reason: 'Indisponivel' };
    return { ok: true, reason: null };
}

function craftCompatibilityClass(label = '') {
    const normalized = String(label || '').toLowerCase();
    if (normalized === 'compativel') return 'is-compatible';
    if (normalized === 'parcial') return 'is-partial';
    if (normalized === 'incompativel') return 'is-incompatible';
    return 'is-insufficient';
}

function craftUsedSourceKeys() {
    return new Set([...craftAllocationCounts().keys()]);
}

function collectCraftInventoryEntries() {
    const entries = [];
    const seen = new Set();

    for (const container of containerIndex.values()) {
        const kind = containerKind(container);
        if (kind === 'market_escrow') continue;

        for (const item of container.items || []) {
            if (!item?.public_id || seen.has(item.public_id)) continue;
            seen.add(item.public_id);
            entries.push({
                item,
                badge: containerDisplayName(container),
                location: 'inventory',
            });
        }
    }

    return entries.sort((left, right) => itemLabel(left.item).localeCompare(itemLabel(right.item), 'pt-BR'));
}

function craftPickerMatchesQuery(label, extra = '') {
    const query = craftPickerQuery.trim().toLowerCase();
    if (!query) return true;

    return `${label} ${extra}`.toLowerCase().includes(query);
}

function resolveCraftPickTargetIndex() {
    if (craftActiveSlotIndex != null && !currentCraftSlotState()[craftActiveSlotIndex]) {
        return craftActiveSlotIndex;
    }

    return currentCraftSlotState().findIndex((slot) => !slot);
}

function craftSlotHintText() {
    if (craftActiveSlotIndex != null) {
        return `Ponta ${craftActiveSlotIndex + 1} selecionada — escolha um componente na biblioteca.`;
    }

    const nextIndex = resolveCraftPickTargetIndex();
    if (nextIndex >= 0) {
        return `Clique um componente para preencher a ponta ${nextIndex + 1}, ou selecione uma ponta vazia.`;
    }

    return 'Todas as pontas estao ocupadas. Remova um componente para trocar.';
}

function renderCraftPickerInventoryCells() {
    const allocations = craftAllocationCounts();
    const entries = collectCraftInventoryEntries().filter((entry) => craftPickerMatchesQuery(
        itemLabel(entry.item),
        `${entry.badge} ${entry.location}`
    ));

    if (!entries.length) {
        return '<p class="inventory-craft-picker-empty">Nenhum item disponivel no inventario.</p>';
    }

    return entries.map((entry) => {
        const payload = buildCraftDragPayload({ kind: 'item_instance', item: entry.item, public_id: entry.item.public_id });
        const key = craftSourceKey(payload);
        const eligibility = craftItemEligibility(entry.item);
        const remaining = craftRemainingQuantity(payload);
        const totalQty = Number(payload?.quantity_available || 1);
        const allocated = allocations.get(key) || 0;
        const canAdd = eligibility.ok && remaining > 0;
        const assetUrl = itemAssetUrl(entry.item);
        const label = itemLabel(entry.item);

        return `
            <button type="button"
                class="craft-picker-cell${canAdd ? '' : ' is-blocked'}${allocated > 0 ? ' is-partial-used' : ''}"
                data-craft-pick="1"
                data-craft-pick-kind="item_instance"
                data-craft-pick-key="${escapeHtml(key)}"
                draggable="${canAdd ? 'true' : 'false'}"
                aria-label="${escapeHtml(label)}"
                title="${escapeHtml(eligibility.ok ? (totalQty > 1 ? `${remaining} de ${totalQty} disponiveis` : 'Disponivel') : eligibility.reason)}"
                ${canAdd ? '' : 'disabled'}>
                <span class="craft-picker-cell-art${assetUrl ? '' : ' is-placeholder'}">
                    ${assetUrl ? `<img src="${escapeHtml(assetUrl)}" alt="" loading="lazy">` : `<span>${escapeHtml(resolveItemTypeMeta(entry.item).icon)}</span>`}
                </span>
                <span class="craft-picker-cell-name">${escapeHtml(label)}</span>
                <span class="craft-picker-cell-meta">${escapeHtml(entry.badge)}${totalQty > 1 ? ` · ${remaining}/${totalQty}` : ''}</span>
                ${!eligibility.ok ? `<span class="craft-picker-cell-lock">${escapeHtml(eligibility.reason)}</span>` : ''}
            </button>
        `;
    }).join('');
}

function renderCraftPickerMaterialCells() {
    const allocations = craftAllocationCounts();
    const tabs = materialStash.tabs?.length
        ? materialStash.tabs
        : [
            { code: 'metals', name: 'Metais', icon: '⚙' },
            { code: 'gems', name: 'Gemas', icon: '💠' },
            { code: 'essences', name: 'Essencias', icon: '✦' },
            { code: 'fragments', name: 'Fragmentos', icon: '◆' },
        ];
    const stacks = (materialStash.stacks || [])
        .filter((stack) => stack.stash_tab === craftPickerMaterialTab)
        .filter((stack) => craftPickerMatchesQuery(stack.label || stack.family_name || '', stack.origin_name || ''));

    const chips = tabs.map((tab) => `
        <button type="button" class="inventory-craft-material-chip${craftPickerMaterialTab === tab.code ? ' is-active' : ''}" data-craft-material-tab="${escapeHtml(tab.code)}">
            ${escapeHtml(tab.icon || '◆')} ${escapeHtml(tab.name)}
        </button>
    `).join('');

    if (!stacks.length) {
        return `
            <div class="inventory-craft-material-chips">${chips}</div>
            <p class="inventory-craft-picker-empty">Nenhum material nesta categoria.</p>
        `;
    }

    const cells = stacks.map((stack) => {
        const payload = buildCraftDragPayload({ kind: 'material_stack', ...stack, stack_key: materialStackKey(stack) });
        const key = craftSourceKey(payload);
        const remaining = craftRemainingQuantity(payload);
        const totalQty = Number(stack.quantity || 0);
        const allocated = allocations.get(key) || 0;
        const canAdd = remaining > 0;
        const assetUrl = materialAssetUrl(stack);

        return `
            <button type="button"
                class="craft-picker-cell${canAdd ? '' : ' is-blocked'}${allocated > 0 ? ' is-partial-used' : ''}"
                data-craft-pick="1"
                data-craft-pick-kind="material_stack"
                data-craft-pick-family="${escapeHtml(stack.family_code || '')}"
                data-craft-pick-origin="${escapeHtml(stack.origin_code || '')}"
                data-craft-pick-key="${escapeHtml(key)}"
                draggable="${canAdd ? 'true' : 'false'}"
                aria-label="${escapeHtml(stack.label || stack.family_name || 'Material')}"
                title="${escapeHtml(`${remaining} de ${totalQty} disponiveis`)}"
                ${canAdd ? '' : 'disabled'}>
                <span class="craft-picker-cell-art${assetUrl ? '' : ' is-placeholder'}">
                    ${assetUrl ? `<img src="${escapeHtml(assetUrl)}" alt="" loading="lazy">` : `<span>${escapeHtml(materialTabIcon(stack.stash_tab))}</span>`}
                </span>
                <span class="craft-picker-cell-name">${escapeHtml(stack.family_name || stack.label || 'Material')}</span>
                <span class="craft-picker-cell-meta">${remaining}/${totalQty} livre</span>
            </button>
        `;
    }).join('');

    return `
        <div class="inventory-craft-material-chips">${chips}</div>
        <div class="inventory-craft-picker-grid-inner">${cells}</div>
    `;
}

function renderCraftPickerLibrary() {
    const inventoryCount = collectCraftInventoryEntries().length;
    const materialCount = (materialStash.stacks || []).length;

    return `
        <div class="inventory-craft-library-head">
            <div>
                <h3>Biblioteca de componentes</h3>
                <p>Escolha itens do inventario ou materiais sem sair desta tela. Itens equipados, colecionaveis, de evento e baus cheios ficam bloqueados.</p>
            </div>
            <input type="search" class="inventory-craft-picker-search" data-craft-picker-search placeholder="Buscar item ou material..." value="${escapeHtml(craftPickerQuery)}">
            <div class="inventory-craft-picker-tabs" role="tablist" aria-label="Fontes de componentes">
                <button type="button" class="inventory-craft-picker-tab${craftPickerTab === 'inventory' ? ' is-active' : ''}" data-craft-picker-tab="inventory" role="tab">
                    Inventario <span>${inventoryCount}</span>
                </button>
                <button type="button" class="inventory-craft-picker-tab${craftPickerTab === 'materials' ? ' is-active' : ''}" data-craft-picker-tab="materials" role="tab">
                    Materiais <span>${materialCount}</span>
                </button>
            </div>
        </div>
        <div class="inventory-craft-picker-grid" data-craft-picker-grid>
            ${craftPickerTab === 'materials' ? renderCraftPickerMaterialCells() : `<div class="inventory-craft-picker-grid-inner">${renderCraftPickerInventoryCells()}</div>`}
        </div>
    `;
}

function buildCraftSlotsPayload() {
    return currentCraftSlotState()
        .map((slot, index) => (slot ? { index, source: slot.source } : null))
        .filter(Boolean);
}

function renderCraftSlotContent(slot) {
    if (!slot) {
        return '<span class="craft-star-slot-empty" aria-hidden="true">+</span>';
    }

    const assetUrl = slot.asset_url || '';
    const label = slot.label || 'Componente';
    const consumeQty = Number(slot.source?.quantity || 1);

    return `
        <div class="inventory-item is-tiny is-compact is-craft-source${assetUrl ? ' has-art' : ''}" aria-label="${escapeHtml(label)}">
            ${assetUrl ? `<img class="inventory-item-art" src="${escapeHtml(assetUrl)}" alt="" loading="lazy">` : '<span class="inventory-item-fallback">◆</span>'}
            <span class="inventory-item-name">${escapeHtml(label)}</span>
            ${consumeQty > 1 ? `<span class="inventory-item-quantity">x${consumeQty.toLocaleString('pt-BR')}</span>` : ''}
        </div>
        <button type="button" class="craft-star-slot-clear" data-craft-clear aria-label="Remover componente" title="Remover">×</button>
    `;
}

function renderCraftStar() {
    const meta = craftWorkspaceMeta();
    const slots = currentCraftSlotState();
    const filledCount = slots.filter(Boolean).length;
    const glowLevel = craftPreview?.synergy_level || (filledCount >= 5 ? 3 : filledCount >= 3 ? 2 : filledCount >= 1 ? 1 : 0);
    const auraColor = craftPreview?.aura_color || meta.aura_color;

    return `
        <div class="craft-star-stage" data-craft-stage style="--craft-aura:${escapeHtml(auraColor)};">
            <svg class="craft-star-links" data-craft-links aria-hidden="true"></svg>
            <div class="craft-star-core is-glow-${glowLevel}">
                <p class="inventory-kicker">${escapeHtml(meta.name)}</p>
                <strong data-craft-output-name>${escapeHtml(craftPreview?.predicted_output?.name || 'Aguardando componentes')}</strong>
                <small data-craft-output-meta>${escapeHtml(craftPreview?.synergy_label || 'Inerte')} · ${filledCount}/${CRAFT_SLOT_COUNT} pontas</small>
            </div>
            ${Array.from({ length: CRAFT_SLOT_COUNT }, (_, index) => {
                const pos = craftStarPointStyle(index);
                const hasItem = Boolean(slots[index]);
                const isSelected = craftActiveSlotIndex === index;
                return `
                    <button type="button"
                        class="craft-star-slot is-point-${index}${hasItem ? ' has-item' : ''}${isSelected ? ' is-selected' : ''}${glowLevel > 0 && hasItem ? ` is-set-glow-${glowLevel}` : ''}"
                        data-craft-slot="${index}"
                        data-craft-drop="1"
                        style="left:${pos.left};top:${pos.top};">
                        <span class="craft-star-slot-ring"></span>
                        <span class="craft-star-slot-content">${renderCraftSlotContent(slots[index])}</span>
                    </button>
                `;
            }).join('')}
        </div>
    `;
}

function craftSlotElement(index) {
    return craftPanelRoot?.querySelector(`[data-craft-slot="${index}"]`) || null;
}

function craftSlotCenterInStage(element, stage) {
    if (!element || !stage || !stage.contains(element)) return null;

    const elementRect = element.getBoundingClientRect();
    const stageRect = stage.getBoundingClientRect();
    if (!stageRect.width || !stageRect.height) return null;

    const scaleX = stage.offsetWidth / stageRect.width;
    const scaleY = stage.offsetHeight / stageRect.height;

    return {
        x: ((elementRect.left - stageRect.left) + (elementRect.width / 2)) * scaleX,
        y: ((elementRect.top - stageRect.top) + (elementRect.height / 2)) * scaleY,
    };
}

function renderCraftLinks() {
    const stage = craftPanelRoot?.querySelector('[data-craft-stage]');
    const svg = craftPanelRoot?.querySelector('[data-craft-links]');
    if (!stage || !svg) return;

    svg.replaceChildren();
    svg.setAttribute('viewBox', `0 0 ${stage.offsetWidth} ${stage.offsetHeight}`);
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');

    const connections = Array.isArray(craftPreview?.connections) ? craftPreview.connections : [];
    const auraColor = craftPreview?.aura_color || craftWorkspaceMeta().aura_color;
    const glowLevel = Number(craftPreview?.synergy_level || 0);

    for (const connection of connections) {
        const from = craftSlotElement(Number(connection.from));
        const to = craftSlotElement(Number(connection.to));
        const p1 = from ? craftSlotCenterInStage(from, stage) : null;
        const p2 = to ? craftSlotCenterInStage(to, stage) : null;
        if (!p1 || !p2) continue;

        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', String(p1.x));
        line.setAttribute('y1', String(p1.y));
        line.setAttribute('x2', String(p2.x));
        line.setAttribute('y2', String(p2.y));
        line.setAttribute('stroke', auraColor);
        line.setAttribute('class', `craft-star-link-line${glowLevel > 0 ? ` is-set-glow-${glowLevel}` : ''}`);
        if (glowLevel > 0) {
            line.setAttribute('stroke-width', String(1.5 + glowLevel));
            line.setAttribute('opacity', String(0.55 + (glowLevel * 0.15)));
        }
        svg.appendChild(line);
    }
}

function renderCraftPanel() {
    if (!craftPanelRoot) return;

    const meta = craftWorkspaceMeta();
    const output = craftPreview?.predicted_output || {};
    const canCraft = Boolean(craftPreview?.can_craft);
    const recipeMatch = craftPreview?.recipe_match || {};
    const compatibilityLabel = recipeMatch.compatibility_label || 'Insuficiente';
    const compatibilityClass = craftCompatibilityClass(compatibilityLabel);
    const goldCost = Number(craftPreview?.gold_cost || 0);
    const goldBalance = Number(craftPreview?.gold_balance ?? walletBalance('gold'));
    const canAfford = craftPreview?.can_afford !== false;
    const recipeName = recipeMatch.best_match?.recipe?.name || recipeMatch.recipe_code || '';
    const possibleOutputs = Array.isArray(output.possible_outputs) ? output.possible_outputs : [];
    const outputsHint = possibleOutputs.length > 1
        ? `Resultados possiveis: ${possibleOutputs.map((entry) => entry.name).filter(Boolean).join(', ')}`
        : '';
    const missingRequirements = Array.isArray(recipeMatch.best_match?.missing) ? recipeMatch.best_match.missing : [];
    const missingHint = missingRequirements.length
        ? `Falta: ${missingRequirements.map((entry) => `${entry.label} (${entry.available || 0}/${entry.required || 1})`).join(', ')}.`
        : '';
    const disabledReason = !canCraft
        ? (missingHint || craftPreview?.reason || 'Adicione os componentes corretos para habilitar a forja.')
        : '';

    craftPanelRoot.innerHTML = `
        <div class="inventory-craft-shell">
            <header class="inventory-craft-header">
                <div>
                    <p class="inventory-kicker">Transmutacao</p>
                    <h2>Workspace de Criacao</h2>
                    <p class="inventory-craft-lead">${escapeHtml(meta.subtitle || '')}</p>
                </div>
                <div class="inventory-craft-header-actions">
                    <button type="button" class="inventory-button" data-craft-clear-all>Limpar altar</button>
                    <button type="button" class="inventory-drawer-close" data-craft-close aria-label="Fechar criacao">×</button>
                </div>
            </header>
            <div class="inventory-craft-tabs" data-craft-tabs>
                <button type="button" class="inventory-craft-tab${craftWorkspace === 'forge' ? ' is-active' : ''}" data-craft-workspace="forge">⚒ Forja</button>
                <button type="button" class="inventory-craft-tab${craftWorkspace === 'alchemy' ? ' is-active' : ''}" data-craft-workspace="alchemy">✦ Alquimia</button>
            </div>
            <div class="inventory-craft-workspace">
                <section class="inventory-craft-lane inventory-craft-lane--altar">
                    <div class="inventory-craft-lane-head">
                        <h3>Altar</h3>
                        <p class="inventory-craft-slot-hint">${escapeHtml(craftSlotHintText())}</p>
                    </div>
                    <div class="inventory-craft-stage-panel">
                        ${renderCraftStar()}
                    </div>
                    <div class="inventory-craft-result-bar">
                        <div class="inventory-craft-preview-card rarity-${escapeHtml(output.quality_bucket || 'common')}">
                            <div class="inventory-craft-preview-top">
                                <span class="inventory-craft-preview-label">Resultado previsto</span>
                                <span class="inventory-craft-compat ${compatibilityClass}">${escapeHtml(compatibilityLabel)}</span>
                            </div>
                            <strong>${escapeHtml(output.name || 'Indefinido')}</strong>
                            <small>${escapeHtml(output.rarity_label || output.quality_bucket || '—')} · ${escapeHtml(craftPreview?.synergy_label || 'Inerte')}${recipeName ? ` · ${escapeHtml(recipeName)}` : ''}</small>
                            <p>${escapeHtml(output.description || craftPreview?.reason || meta.description || '')}</p>
                            ${missingHint ? `<p class="inventory-craft-missing">${escapeHtml(missingHint)}</p>` : ''}
                            ${outputsHint ? `<p class="inventory-craft-output-hint">${escapeHtml(outputsHint)}</p>` : ''}
                            ${recipeMatch.guaranteed_success ? '<p class="inventory-craft-guarantee">Forja garantida — esta receita sempre produz um item.</p>' : ''}
                            <div class="inventory-craft-cost-row">
                                <span>Custo</span>
                                <strong class="${canAfford ? '' : 'is-insufficient'}">${goldCost.toLocaleString('pt-BR')} G</strong>
                                <small>Saldo: ${goldBalance.toLocaleString('pt-BR')} G</small>
                            </div>
                        </div>
                        <button type="button" class="inventory-button inventory-craft-execute" data-craft-execute ${canCraft ? '' : 'disabled'} title="${escapeHtml(disabledReason)}">
                            ${craftWorkspace === 'forge' ? 'Forjar item' : 'Transmutar'}${goldCost > 0 ? ` · ${goldCost.toLocaleString('pt-BR')} G` : ''}
                        </button>
                    </div>
                </section>
                <section class="inventory-craft-lane inventory-craft-lane--library">
                    ${renderCraftPickerLibrary()}
                    <div data-craft-recipe-journal></div>
                </section>
            </div>
        </div>
    `;

    window.requestAnimationFrame(() => {
        renderCraftLinks();
        window.requestAnimationFrame(renderCraftLinks);
    });
    bindCraftPanelInteractions();
    void loadRecipeJournal(craftPanelRoot.querySelector('[data-craft-recipe-journal]'));
}

function applyCraftPickPayload(payload) {
    if (!payload) return false;

    if (!craftCanAddPayload(payload)) {
        toast('Quantidade disponivel esgotada para este componente.', 'info', 2600);
        return false;
    }

    const index = resolveCraftPickTargetIndex();
    if (index < 0) {
        toast('Todas as pontas da estrela estao ocupadas.', 'info', 2800);
        return false;
    }

    const ok = assignCraftSlot(index, payload);
    if (ok) {
        const nextEmpty = currentCraftSlotState().findIndex((slot) => !slot);
        craftActiveSlotIndex = nextEmpty >= 0 ? nextEmpty : null;
        renderCraftPanel();
    }

    return ok;
}

function resolveCraftPickPayloadFromButton(button) {
    if (!(button instanceof Element)) return null;

    const kind = button.getAttribute('data-craft-pick-kind') || '';
    if (kind === 'material_stack') {
        const familyCode = button.getAttribute('data-craft-pick-family') || '';
        const originCode = button.getAttribute('data-craft-pick-origin') || '';
        const stack = (materialStash.stacks || []).find((entry) => entry.family_code === familyCode && entry.origin_code === originCode)
            || materialStackIndex.get(`${familyCode}::${originCode}`);
        return buildCraftDragPayload({ kind: 'material_stack', ...stack, family_code: familyCode, origin_code: originCode });
    }

    if (kind === 'item_instance') {
        const key = button.getAttribute('data-craft-pick-key') || '';
        const publicId = key.startsWith('item:') ? key.slice(5) : '';
        const entry = collectCraftInventoryEntries().find((candidate) => candidate.item?.public_id === publicId);
        if (!entry?.item) return null;
        return buildCraftDragPayload({ kind: 'item_instance', item: entry.item, public_id: entry.item.public_id });
    }

    return null;
}

function resolveCraftItemFromPayload(payload) {
    if (!payload || payload.kind !== 'item_instance') return null;
    if (payload.item?.public_id) return payload.item;

    const publicId = payload.public_id || '';
    if (!publicId) return null;

    return itemIndex.get(publicId)?.item
        || collectCraftInventoryEntries().find((entry) => entry.item?.public_id === publicId)?.item
        || null;
}

function assignCraftSlot(index, payload, options = {}) {
    if (index < 0 || index >= CRAFT_SLOT_COUNT || !payload) return false;

    if (payload.kind === 'item_instance' && !payload.item) {
        const item = resolveCraftItemFromPayload(payload);
        if (item) payload = { ...payload, item };
    }

    const eligibility = payload.kind === 'item_instance' ? craftItemEligibility(payload.item) : { ok: true };
    if (!eligibility.ok) {
        if (!options.silent) toast(eligibility.reason || 'Item bloqueado para crafting.', 'info', 2600);
        return false;
    }

    const slots = currentCraftSlotState();
    const existingKey = slots[index] ? craftSourceKey(slots[index].source) : '';
    const key = craftSourceKey(payload);
    const remaining = craftRemainingQuantity(payload) + (existingKey === key ? Number(slots[index]?.source?.quantity || 1) : 0);

    if (remaining <= 0) {
        if (!options.silent) toast('Quantidade disponivel esgotada para este componente.', 'info', 2600);
        return false;
    }

    slots[index] = {
        source: {
            kind: payload.kind,
            public_id: payload.public_id || null,
            family_code: payload.family_code || null,
            origin_code: payload.origin_code || null,
            quantity: 1,
        },
        label: payload.label || 'Componente',
        asset_url: payload.asset_url || '',
        quantity_available: Number(payload.quantity_available || 1),
        item: payload.item || null,
    };

    renderCraftPanel();
    refreshCraftPreview();
    return true;
}

function clearCraftSlot(index) {
    const slots = currentCraftSlotState();
    if (!slots[index]) return;
    slots[index] = null;
    craftActiveSlotIndex = index;
    renderCraftPanel();
    refreshCraftPreview();
}

function clearCraftWorkspace() {
    craftSlots[craftWorkspace] = Array.from({ length: CRAFT_SLOT_COUNT }, () => null);
    craftPreview = null;
    craftActiveSlotIndex = 0;
    renderCraftPanel();
}

async function loadCraftPickerStash() {
    try {
        const response = await apiFetch('/api/inventory/materials');
        materialStash = response.data || { tabs: [], stacks: [], grid: { columns: 12, cell_px: 52 } };
        materialStackIndex = new Map((materialStash.stacks || []).map((stack) => [materialStackKey(stack), stack]));
    } catch {
        materialStash = { tabs: [], stacks: [], grid: { columns: 12, cell_px: 52 } };
        materialStackIndex = new Map();
    }
}

async function refreshCraftPreview() {
    if (!craftPanelOpen || craftPreviewLoading) return;

    const payload = buildCraftSlotsPayload();
    if (!payload.length) {
        craftPreview = {
            workspace: craftWorkspace,
            filled_slots: 0,
            synergy_level: 0,
            synergy_label: 'Inerte',
            aura_color: craftWorkspaceMeta().aura_color,
            connections: [],
            can_craft: false,
            reason: 'Preencha pelo menos 2 pontas da estrela.',
            predicted_output: {
                name: 'Aguardando componentes',
                quality_bucket: 'common',
                description: 'Arraste qualquer item, material ou pet para montar a receita.',
            },
        };
        renderCraftPanel();
        return;
    }

    craftPreviewLoading = true;
    try {
        const response = await apiFetch('/api/inventory/crafting/preview', {
            method: 'POST',
            body: {
                workspace: craftWorkspace,
                slots: payload,
            },
        });
        craftPreview = response.data || null;
    } catch (error) {
        craftPreview = null;
        handleError(error, 'Nao foi possivel prever o resultado.');
    } finally {
        craftPreviewLoading = false;
        renderCraftPanel();
    }
}

async function executeCraft() {
    if (actionInFlight || !craftPreview?.can_craft) return;

    const payload = buildCraftSlotsPayload();
    if (payload.length < 2) {
        toast('Preencha pelo menos 2 pontas da estrela.', 'error', 2800);
        return;
    }

    const outputName = craftPreview?.predicted_output?.name || 'item';
    const goldCost = Number(craftPreview?.gold_cost || 0);
    const confirmed = await confirmInventoryAction({
        title: craftWorkspace === 'forge' ? 'Confirmar forja' : 'Confirmar alquimia',
        bodyHtml: `<p>Consumir os componentes e criar <strong>${escapeHtml(outputName)}</strong>?</p>
            <p>Os itens e materiais usados serao removidos do inventario.${goldCost > 0 ? ` Custo: <strong>${goldCost.toLocaleString('pt-BR')} G</strong>.` : ''}</p>`,
        confirmLabel: craftWorkspace === 'forge' ? 'Forjar' : 'Transmutar',
        tone: 'warning',
    });
    if (!confirmed) return;

    actionInFlight = true;
    try {
        setStatus('Transmutando...');
        const response = await apiFetch('/api/inventory/crafting/execute', {
            method: 'POST',
            body: {
                workspace: craftWorkspace,
                slots: payload,
            },
        });
        const granted = response.data?.granted_item?.item_public_id || response.data?.granted_item?.public_id;
        toast(`Criacao concluida${granted ? `: ${outputName}` : '.'}`, 'success', 4200);

        const discovery = response.data?.discovery;
        if (discovery?.is_first_on_server && discovery?.can_share) {
            await promptCraftBlueprintDiscovery(discovery);
        }

        clearCraftWorkspace();
        setStatus('Sincronizado');
        containerDetailCache.invalidate();
        await reloadContainerPanelsOnly();
        await loadCraftPickerStash();
        if (craftPanelOpen) renderCraftPanel();
    } catch (error) {
        handleError(error, 'Nao foi possivel concluir a criacao.');
    } finally {
        actionInFlight = false;
    }
}

async function promptCraftBlueprintDiscovery(discovery) {
    const recipeName = discovery.recipe_name || discovery.recipe_code || 'Receita';
    const share = await confirmInventoryAction({
        title: 'Primeira descoberta no servidor!',
        bodyHtml: `<p>Voce foi o primeiro jogador a criar <strong>${escapeHtml(recipeName)}</strong>.</p>
            <p>Deseja <strong>compartilhar</strong> esta blueprint com todos os jogadores, ou manter apenas para voce?</p>`,
        confirmLabel: 'Compartilhar com todos',
        cancelLabel: 'Guardar para mim',
        tone: 'success',
    });

    if (!share) return;

    try {
        await apiFetch('/api/inventory/crafting/recipes/share', {
            method: 'POST',
            body: { recipe_code: discovery.recipe_code },
        });
        toast('Blueprint compartilhada com todo o servidor!', 'success', 4200);
    } catch (error) {
        handleError(error, 'Nao foi possivel compartilhar a receita.');
    }
}

function findCraftSlotUnderPointer(clientX, clientY) {
    if (!craftPanelOpen || clientX == null || clientY == null) return null;
    const elements = document.elementsFromPoint(clientX, clientY);
    const hit = elements.find((node) => node instanceof Element && node.closest('[data-craft-drop]'));
    return hit instanceof Element ? hit.closest('[data-craft-drop]') : null;
}

function applyCraftDropPayload(slotElement, payload) {
    if (!slotElement || !payload) return false;
    const index = Number(slotElement.getAttribute('data-craft-slot'));
    if (!Number.isInteger(index)) return false;
    return assignCraftSlot(index, payload);
}

async function tryAssignInventoryDragToCraftSlot(event) {
    if (!craftPanelOpen || !activeDrag) return false;

    const coords = dragPointerCoords(event);
    const slotElement = findCraftSlotUnderPointer(coords?.clientX, coords?.clientY);
    if (!slotElement) return false;

    const dragged = findDraggedWidget();
    const itemPublicId = dragged?.node?.id || activeDrag.itemPublicId;
    const indexed = itemIndex.get(itemPublicId);
    const item = indexed?.item;
    if (!item) return false;

    const eligibility = craftItemEligibility(item);
    if (!eligibility.ok) {
        toast(eligibility.reason || 'Item bloqueado para crafting.', 'info', 2600);
        return false;
    }

    const payload = buildCraftDragPayload({ kind: 'item_instance', item, public_id: item.public_id });
    if (!applyCraftDropPayload(slotElement, payload)) return false;

    revertItem(itemPublicId);
    activeDrag.handled = true;
    clearActiveDrag();
    return true;
}

async function loadCraftWorkspaces() {
    try {
        const response = await apiFetch('/api/inventory/crafting/workspaces');
        craftWorkspaces = response.data?.workspaces || [];
    } catch {
        craftWorkspaces = [
            { code: 'forge', name: 'Forja', subtitle: 'Cria itens base comuns', description: 'Combina materias-primas e componentes para forjar equipamentos comuns.', aura_color: '#f59e0b' },
            { code: 'alchemy', name: 'Alquimia', subtitle: 'Encanta e refina', description: 'Funde essencias, gemas e itens especiais para criar encantamentos e colecionaveis.', aura_color: '#8b5cf6' },
        ];
    }
}

function openCraftPanel() {
    setMarketPanelOpen(false);
    materialsPanelOpen = false;
    if (isExplorationPanelOpen()) closeExplorationPanel();
    closeMissionsPanel();
    closeSetCodexPanel();
    craftPanelOpen = true;
    craftPickerTab = 'materials';
    craftPickerMaterialTab = 'fragments';
    craftActiveSlotIndex = craftActiveSlotIndex ?? resolveCraftPickTargetIndex();
    if (craftActiveSlotIndex < 0) craftActiveSlotIndex = 0;
    syncDrawerUi();
    Promise.all([loadCraftWorkspaces(), loadCraftPickerStash()]).then(() => {
        renderCraftPanel();
        refreshCraftPreview();
        void loadRecipeJournal(document.querySelector('[data-craft-recipe-journal]'));
    });
}

function closeCraftPanel() {
    craftPanelOpen = false;
    craftActiveSlotIndex = null;
    syncDrawerUi();
}

function toggleCraftPanel() {
    if (craftPanelOpen) closeCraftPanel();
    else openCraftPanel();
}

let craftControlsInitialized = false;

function bindCraftPanelInteractions() {
    if (!craftPanelRoot) return;

    craftPanelRoot.querySelector('[data-craft-close]')?.addEventListener('click', closeCraftPanel);
    craftPanelRoot.querySelector('[data-craft-clear-all]')?.addEventListener('click', clearCraftWorkspace);
    craftPanelRoot.querySelector('[data-craft-execute]')?.addEventListener('click', executeCraft);

    craftPanelRoot.querySelectorAll('[data-craft-workspace]').forEach((button) => {
        button.addEventListener('click', () => {
            craftWorkspace = button.getAttribute('data-craft-workspace') || 'forge';
            craftPreview = null;
            craftActiveSlotIndex = resolveCraftPickTargetIndex();
            if (craftActiveSlotIndex < 0) craftActiveSlotIndex = 0;
            renderCraftPanel();
            refreshCraftPreview();
        });
    });

    craftPanelRoot.querySelectorAll('[data-craft-picker-tab]').forEach((button) => {
        button.addEventListener('click', () => {
            craftPickerTab = button.getAttribute('data-craft-picker-tab') || 'inventory';
            renderCraftPanel();
        });
    });

    craftPanelRoot.querySelectorAll('[data-craft-material-tab]').forEach((button) => {
        button.addEventListener('click', () => {
            craftPickerMaterialTab = button.getAttribute('data-craft-material-tab') || 'metals';
            renderCraftPanel();
        });
    });

    const searchInput = craftPanelRoot.querySelector('[data-craft-picker-search]');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            craftPickerQuery = searchInput.value || '';
            renderCraftPanel();
            const nextSearch = craftPanelRoot.querySelector('[data-craft-picker-search]');
            if (nextSearch instanceof HTMLInputElement) {
                nextSearch.focus();
                const cursor = nextSearch.value.length;
                nextSearch.setSelectionRange(cursor, cursor);
            }
        });
    }

    craftPanelRoot.querySelectorAll('[data-craft-clear]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const slot = button.closest('[data-craft-slot]');
            clearCraftSlot(Number(slot?.getAttribute('data-craft-slot')));
        });
    });

    craftPanelRoot.querySelectorAll('[data-craft-slot]').forEach((slot) => {
        slot.addEventListener('click', (event) => {
            if (event.target.closest('[data-craft-clear]')) return;
            const index = Number(slot.getAttribute('data-craft-slot'));
            if (!Number.isInteger(index)) return;
            craftActiveSlotIndex = index;
            renderCraftPanel();
        });
    });

    craftPanelRoot.querySelectorAll('[data-craft-pick]').forEach((button) => {
        button.addEventListener('click', () => {
            const payload = resolveCraftPickPayloadFromButton(button);
            applyCraftPickPayload(payload);
        });

        button.addEventListener('dragstart', (event) => {
            const payload = resolveCraftPickPayloadFromButton(button);
            if (!payload || !event.dataTransfer) return;
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData(CRAFT_DRAG_MIME, JSON.stringify(payload));
            event.dataTransfer.setData('text/plain', payload.label || 'Componente');
            button.classList.add('is-craft-dragging');
        });

        button.addEventListener('dragend', () => {
            button.classList.remove('is-craft-dragging');
        });
    });

    craftPanelRoot.querySelectorAll('[data-craft-drop]').forEach((slot) => {
        slot.addEventListener('dragover', (event) => {
            event.preventDefault();
            if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
            slot.classList.add('is-drop-hover');
        });
        slot.addEventListener('dragleave', () => slot.classList.remove('is-drop-hover'));
        slot.addEventListener('drop', (event) => {
            event.preventDefault();
            slot.classList.remove('is-drop-hover');
            const index = Number(slot.getAttribute('data-craft-slot'));
            const payload = parseCraftDragPayload(event.dataTransfer);
            if (!payload || !Number.isInteger(index)) return;
            craftActiveSlotIndex = index;
            assignCraftSlot(index, payload);
            const nextEmpty = currentCraftSlotState().findIndex((entry) => !entry);
            craftActiveSlotIndex = nextEmpty >= 0 ? nextEmpty : null;
            renderCraftPanel();
        });
    });
}

function initCraftControls() {
    if (craftControlsInitialized) return;
    craftControlsInitialized = true;

    document.querySelectorAll('[data-craft-open]').forEach((button) => {
        button.addEventListener('click', () => toggleCraftPanel());
    });

    craftPanelRoot?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        if (target.closest('[data-craft-close]')) {
            closeCraftPanel();
            return;
        }
        if (event.target === craftPanelRoot) {
            closeCraftPanel();
        }
    });
}

/* item investigation UI moved to ./item-investigation.js */

function openStatsDrawer() {
    statsDrawerOpen = true;
    focusedDrawer = 'stats';
    persistDrawerState();
    syncDrawerUi();
    renderCharacterStats(lastCharacterStats, currentSetBonuses, playerPower, statsDrawerPanel);
}

function closeStatsDrawer() {
    statsDrawerOpen = false;
    if (focusedDrawer === 'stats') {
        focusedDrawer = leftDrawerOpen ? 'left' : (rightDrawerOpen ? 'right' : 'right');
    }
    persistDrawerState();
    syncDrawerUi();
}

function toggleStatsDrawer() {
    if (statsDrawerOpen) closeStatsDrawer();
    else openStatsDrawer();
}

function setLeftDrawerTab(tab) {
    if (tab === 'stats') {
        openStatsDrawer();
        return;
    }
    leftDrawerTab = 'equipment';
    persistDrawerState();
    openLeftDrawer();
}

function openLeftDrawer() {
    leftDrawerOpen = true;
    focusedDrawer = 'left';
    persistDrawerState();
    syncDrawerUi();
    maybeShowLayoutTutorial();
    void loadEquipmentLoadouts();
    void loadExplorationLoadoutPanel();
}

function openRightDrawer() {
    rightDrawerOpen = true;
    focusedDrawer = 'right';
    persistDrawerState();
    syncDrawerUi();
}

function closeLeftDrawer() {
    leftDrawerOpen = false;
    if (focusedDrawer === 'left') focusedDrawer = rightDrawerOpen ? 'right' : 'right';
    persistDrawerState();
    syncDrawerUi();
}

function closeRightDrawer() {
    rightDrawerOpen = false;
    if (focusedDrawer === 'right') focusedDrawer = leftDrawerOpen ? 'left' : 'right';
    persistDrawerState();
    syncDrawerUi();
}

function toggleLeftDrawer() {
    if (leftDrawerOpen) {
        closeLeftDrawer();
    } else {
        openLeftDrawer();
    }
}

function toggleRightDrawer() {
    if (rightDrawerOpen) {
        closeRightDrawer();
    } else {
        openRightDrawer();
    }
}

function alternateDrawerFocus() {
    if (!leftDrawerOpen && !rightDrawerOpen) {
        openRightDrawer();
        return;
    }
    if (leftDrawerOpen && rightDrawerOpen) {
        focusedDrawer = focusedDrawer === 'left' ? 'right' : 'left';
        persistDrawerState();
        syncDrawerUi();
        return;
    }
    if (leftDrawerOpen) {
        openRightDrawer();
    } else {
        openLeftDrawer();
    }
}

function closeActiveDrawer() {
    if (isSetCodexOpen()) {
        closeSetCodexPanel();
        return;
    }
    if (isMissionsPanelOpen()) {
        closeMissionsPanel();
        syncDrawerUi();
        return;
    }
    if (isExplorationPanelOpen()) {
        closeExplorationPanel();
        return;
    }
    if (craftPanelOpen) {
        closeCraftPanel();
        return;
    }
    if (materialsPanelOpen) {
        closeMaterialsPanel();
        return;
    }
    if (isMarketPanelOpen()) {
        closeMarketPanel();
        return;
    }
    if (statsDrawerOpen && (focusedDrawer === 'stats' || !(leftDrawerOpen || rightDrawerOpen))) {
        closeStatsDrawer();
        return;
    }
    if (leftDrawerOpen && rightDrawerOpen) {
        if (focusedDrawer === 'left') closeLeftDrawer();
        else closeRightDrawer();
    } else if (leftDrawerOpen) {
        closeLeftDrawer();
    } else if (rightDrawerOpen) {
        closeRightDrawer();
    } else if (statsDrawerOpen) {
        closeStatsDrawer();
    }
    persistDrawerState();
    syncDrawerUi();
}

let drawerControlsInitialized = false;

function initDrawerControls() {
    if (drawerControlsInitialized) return;
    drawerControlsInitialized = true;

    document.querySelectorAll('[data-drawer-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const side = button.dataset.drawerOpen;
            if (side === 'left') toggleLeftDrawer();
            if (side === 'right') toggleRightDrawer();
            if (side === 'stats') toggleStatsDrawer();
        });
    });

    document.querySelectorAll('[data-drawer-close]').forEach((button) => {
        button.addEventListener('click', () => {
            const side = button.dataset.drawerClose;
            if (side === 'left') closeLeftDrawer();
            if (side === 'right') closeRightDrawer();
            if (side === 'stats') closeStatsDrawer();
            syncDrawerUi();
        });
    });

    backdropRoot?.addEventListener('click', () => {
        closeActiveDrawer();
    });

    marketToggleButton?.addEventListener('click', () => {
        marketDeliveryOpen = !marketDeliveryOpen;
        if (marketDeliveryOpen) {
            clearSplitView();
        }
        persistContainerPanels();
        void reloadContainerPanelsOnly();
    });

    document.querySelectorAll('[data-missions-open]').forEach((button) => {
        button.addEventListener('click', () => toggleMissionsPanel());
    });
    document.querySelector('[data-missions-close]')?.addEventListener('click', () => {
        closeMissionsPanel();
        syncDrawerUi();
    });
    document.querySelector('[data-missions-refresh]')?.addEventListener('click', () => loadMissionsJournal());

    document.querySelectorAll('[data-materials-open]').forEach((button) => {
        button.addEventListener('click', () => toggleMaterialsPanel());
    });

    initCraftControls();

    document.querySelector('[data-materials-refresh]')?.addEventListener('click', () => loadMaterialsStash());

    materialsPanelRoot?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        if (target.closest('[data-materials-close]')) {
            closeMaterialsPanel();
            return;
        }

        if (event.target === materialsPanelRoot) {
            closeMaterialsPanel();
            return;
        }

        const vaultTabButton = target.closest('[data-materials-vault-tab]');
        if (vaultTabButton) {
            materialsVaultTab = vaultTabButton.getAttribute('data-materials-vault-tab') || 'materials';
            void loadMaterialsStash();
            return;
        }

        const tabButton = target.closest('[data-materials-tab]');
        if (!tabButton) return;
        materialsActiveTab = tabButton.getAttribute('data-materials-tab') || 'metals';
        renderMaterialsPanel();
    });
}

function persistCharacterPanel() {
    persistDrawerState();
}

function persistSplitView() {
    if (splitViewState) {
        localStorage.setItem('evolvaxe.inventory.splitView', JSON.stringify(splitViewState));
    } else {
        localStorage.removeItem('evolvaxe.inventory.splitView');
    }
}

function clearSplitView() {
    splitViewState = null;
    persistSplitView();
}

function isChestContainerItem(item) {
    const linkedCode = String(item?.linked_container?.definition_code || '');
    if (linkedCode.includes('chest')) return true;

    const baseConfig = item?.definition?.base_config;
    if (typeof baseConfig === 'string' && baseConfig.includes('chest')) return true;
    if (baseConfig && typeof baseConfig === 'object' && String(baseConfig.container_definition || '').includes('chest')) {
        return true;
    }

    return false;
}

function findMainInventoryContainer(containers = []) {
    return containers.find((container) => containerKind(container) === 'main') || null;
}

function cellSizeForContainer(containerPublicId) {
    return gridCellSizes.get(containerPublicId) || CELL_SIZE;
}

function resolveCellSize(gridNode) {
    const containerPublicId = gridNode?.dataset?.containerPublicId;
    if (containerPublicId && gridCellSizes.has(containerPublicId)) {
        return gridCellSizes.get(containerPublicId);
    }

    const scope = gridNode?.closest('[data-floating-bag-window]')
        || gridNode?.closest('[data-inventory-expedition]')
        || gridNode?.closest('.inventory-drawer--right')
        || gridNode?.closest('[data-inventory-app]')
        || gridNode;
    const raw = getComputedStyle(scope).getPropertyValue('--inventory-cell').trim();
    const parsed = parseFloat(raw);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : CELL_SIZE;
}

function resolveSplitParentContainer(linkedPublicId, containers = []) {
    const linked = containers.find((container) => container.public_id === linkedPublicId)
        || containerIndex.get(linkedPublicId);
    if (!linked) {
        return findMainInventoryContainer(containers.length ? containers : [...containerIndex.values()]);
    }

    const chain = Array.isArray(linked.parent_chain) ? linked.parent_chain : [];
    if (chain.length > 0) {
        const parentPublicId = chain[chain.length - 1].container_public_id;
        return containers.find((container) => container.public_id === parentPublicId)
            || containerIndex.get(parentPublicId)
            || findMainInventoryContainer(containers.length ? containers : [...containerIndex.values()]);
    }

    return findMainInventoryContainer(containers.length ? containers : [...containerIndex.values()]);
}

function toggleExpeditionPanel() {
    toggleLeftDrawer();
}

function isRightDrawerContainer(container) {
    if (isEquippedBackpackContainer(container)) return false;

    const kind = containerKind(container);
    if (kind === 'expedition_carry') return false;
    if (kind === 'main') return true;
    if (kind === 'market_delivery') return marketDeliveryOpen;

    return openContainerPublicIds.has(container.public_id);
}

function isLeftDrawerContainer(container) {
    return containerKind(container) === 'expedition_carry';
}

function containerKind(container) {
    const type = String(container.type || '').toUpperCase();
    const code = String(container.definition_code || '').toLowerCase();

    if (type === 'MAIN_INVENTORY' || code.startsWith('main_inventory')) return 'main';
    if (type === 'MARKET_DELIVERY' || code === 'market_delivery') return 'market_delivery';
    if (type === 'EXPEDITION_CARRY' || code === 'expedition_carry') return 'expedition_carry';
    if (type === 'BACKPACK') return 'backpack';
    if (type === 'CHEST') return 'chest';

    return 'secondary';
}

function isEquippedBackpackContainer(container) {
    if (!equippedBackpackPublicId || !container?.source_item_public_id) return false;
    return container.source_item_public_id === equippedBackpackPublicId;
}

function isRightDrawerContainerVisible(container) {
    if (!isRightDrawerContainer(container)) return false;
    if (isFloatingContainerOpen(container.public_id)) return false;

    const kind = containerKind(container);
    if (kind === 'main') return true;
    if (kind === 'market_delivery') return false;

    return openContainerPublicIds.has(container.public_id);
}

function isContainerVisible(container) {
    if (isEquippedBackpackContainer(container)) {
        return false;
    }
    if (isFloatingContainerOpen(container.public_id)) {
        return false;
    }

    const kind = containerKind(container);
    if (kind === 'expedition_carry') return false;
    if (kind === 'main') return true;
    if (kind === 'market_delivery') return false;

    return openContainerPublicIds.has(container.public_id);
}

function openContainer(containerPublicId) {
    openContainerPublicIds.add(containerPublicId);
    persistContainerPanels();
}

function toggleContainer(container) {
    const kind = containerKind(container);
    const wasOpen = kind === 'market_delivery'
        ? marketDeliveryOpen
        : kind === 'expedition_carry'
            ? expeditionCarryOpen
            : openContainerPublicIds.has(container.public_id);

    if (kind === 'market_delivery') {
        marketDeliveryOpen = !marketDeliveryOpen;
    } else if (kind === 'expedition_carry') {
        expeditionCarryOpen = !expeditionCarryOpen;
    } else if (openContainerPublicIds.has(container.public_id)) {
        openContainerPublicIds.delete(container.public_id);
    } else {
        openContainerPublicIds.add(container.public_id);
    }

    persistContainerPanels();

    if (kind === 'expedition_carry' || kind === 'market_delivery') {
        if (kind === 'expedition_carry') {
            if (expeditionCarryOpen) openLeftDrawer();
            syncExpeditionBagPanel();
            syncDrawerUi();
            return;
        }
        void reloadContainerPanelsOnly();
        return;
    }

    if (splitViewState) {
        if (wasOpen && !isNowOpen) {
            clearSplitView();
            void closeSplitViewPanels();
            return;
        }

        const parent = containerIndex.get(splitViewState.parentPublicId)
            || allContainersCache.find((entry) => entry.public_id === splitViewState.parentPublicId);
        if (!wasOpen && isNowOpen && parent?.public_id) {
            void mountSplitView(parent.public_id, container.public_id);
            return;
        }

        void closeSplitViewPanels();
        return;
    }

    const isNowOpen = openContainerPublicIds.has(container.public_id);
    if (wasOpen && !isNowOpen) {
        unmountContainerPanel(container.public_id);
        renderContainerDock();
        bindContainerLinks();
        return;
    }

    if (!wasOpen && isNowOpen) {
        void mountContainerPanel(container.public_id).then(() => {
            renderContainerDock();
            bindContainerLinks();
            applyInventoryFilters();
        });
    }
}

function containerDisplayName(container) {
    const kind = containerKind(container);
    if (kind === 'expedition_carry') {
        const backpackSlot = currentEquipment.find((slot) => slot.code === 'backpack');
        if (backpackSlot?.item) {
            return `Bag de expedicao (${itemLabel(backpackSlot.item)})`;
        }

        return 'Bolsos de expedicao (2x2)';
    }

    if (container.name && String(container.name).trim()) {
        return String(container.name).trim();
    }

    if (container.source_item_public_id) {
        const sourceItem = [...itemIndex.values()].find((entry) => entry.item?.public_id === container.source_item_public_id)?.item;
        if (sourceItem) {
            return itemLabel(sourceItem);
        }
    }

    return container.definition_code || 'Container';
}

function renderAcceptanceBadges(container) {
    const badges = Array.isArray(container.acceptance_summary?.badges) ? container.acceptance_summary.badges : [];
    if (!badges.length) return '';

    const tooltip = container.acceptance_summary?.tooltip || container.acceptance_summary?.label || '';
    return `<div class="inventory-container-acceptance-badges" title="${escapeHtml(tooltip)}">${badges.map((badge) => `
        <span class="inventory-container-acceptance-badge is-${escapeHtml(badge.code || 'all')}">
            <span aria-hidden="true">${escapeHtml(badge.icon || '📦')}</span>
            ${escapeHtml(badge.label || '')}
        </span>
    `).join('')}</div>`;
}

async function renameContainerInline(container, titleNode) {
    if (!container?.can_rename) return;

    const current = containerDisplayName(container);
    openRenameModal({
        title: 'Renomear armazenamento',
        subtitle: 'Apenas baus e bags fisicos podem receber nome personalizado.',
        currentName: current,
        onSubmit: async (nextName) => {
            if (nextName === current) return;

            setStatus('Renomeando container...');
            await apiFetch(`/api/inventory/containers/${encodeURIComponent(container.public_id)}/rename`, {
                method: 'PATCH',
                body: { name: nextName },
            });
            toast('Container renomeado.', 'success', 2400);
            setStatus('Sincronizado');
            container.custom_name = nextName;
            container.name = nextName;
            containerDetailCache.invalidate(container.public_id);
            upsertContainerCache(container);
            if (grids.has(container.public_id)) {
                await resyncContainerPanel(container.public_id);
            } else {
                renderContainerDock();
            }
        },
    });
}

function ensureRenameModalRoot() {
    let modal = document.querySelector('[data-inventory-rename-modal]');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.className = 'inventory-rename-modal';
    modal.dataset.inventoryRenameModal = '';
    modal.hidden = true;
    modal.innerHTML = `
        <div class="inventory-rename-modal-backdrop" data-rename-modal-close></div>
        <form class="inventory-rename-modal-card" data-rename-modal-form>
            <header class="inventory-rename-modal-header">
                <div>
                    <p class="inventory-kicker" data-rename-modal-kicker>Renomear</p>
                    <h3 data-rename-modal-title>Armazenamento</h3>
                    <p class="inventory-rename-modal-subtitle" data-rename-modal-subtitle></p>
                </div>
                <button type="button" class="inventory-rename-modal-close" data-rename-modal-close aria-label="Fechar">×</button>
            </header>
            <label class="inventory-rename-modal-field">
                <span>Nome personalizado</span>
                <input type="text" maxlength="48" data-rename-modal-input placeholder="Deixe vazio para restaurar o padrao">
            </label>
            <footer class="inventory-rename-modal-actions">
                <button type="button" class="inventory-button inventory-button-ghost" data-rename-modal-close>Cancelar</button>
                <button type="submit" class="inventory-button">Salvar</button>
            </footer>
        </form>
    `;
    document.body.appendChild(modal);

    modal.querySelectorAll('[data-rename-modal-close]').forEach((node) => {
        node.addEventListener('click', closeRenameModal);
    });

    modal.querySelector('[data-rename-modal-form]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const input = modal.querySelector('[data-rename-modal-input]');
        const submit = modal.querySelector('[type="submit"]');
        if (!renameModalState?.onSubmit || !input || !submit) return;

        submit.disabled = true;
        try {
            await renameModalState.onSubmit(input.value.trim());
            closeRenameModal();
        } catch (error) {
            handleError(error, 'Nao foi possivel renomear.');
        } finally {
            submit.disabled = false;
        }
    });

    return modal;
}

let renameModalState = null;

function openRenameModal({ title, subtitle = '', currentName = '', onSubmit }) {
    const modal = ensureRenameModalRoot();
    renameModalState = { onSubmit };
    modal.querySelector('[data-rename-modal-title]').textContent = title;
    modal.querySelector('[data-rename-modal-subtitle]').textContent = subtitle;
    const input = modal.querySelector('[data-rename-modal-input]');
    input.value = currentName;
    modal.hidden = false;
    window.requestAnimationFrame(() => {
        input.focus();
        input.select();
    });
}

function closeRenameModal() {
    const modal = document.querySelector('[data-inventory-rename-modal]');
    if (!modal) return;
    modal.hidden = true;
    renameModalState = null;
}

async function renameStorageContainerItem(item) {
    const containerPublicId = item?.linked_container?.public_id;
    if (!isStorageContainerItem(item) || !containerPublicId) {
        toast('Apenas baus e bags podem ser renomeados.', 'info', 2800);
        return;
    }

    openRenameModal({
        title: 'Renomear armazenamento',
        subtitle: 'O nome aparece no titulo do bau ou bag quando aberto.',
        currentName: item.linked_container?.name || itemLabel(item),
        onSubmit: async (nextName) => {
            setStatus('Renomeando container...');
            await apiFetch(`/api/inventory/containers/${encodeURIComponent(containerPublicId)}/rename`, {
                method: 'PATCH',
                body: { name: nextName },
            });
            toast('Armazenamento renomeado.', 'success', 2400);
            setStatus('Sincronizado');
            if (item.linked_container) {
                item.linked_container.name = nextName;
            }
            containerDetailCache.invalidate(containerPublicId);
            const entry = itemIndex.get(item.public_id);
            if (entry?.item) {
                entry.item = item;
                refreshItemWidget(item.public_id);
            }
            if (grids.has(containerPublicId)) {
                await resyncContainerPanel(containerPublicId);
            } else {
                renderContainerDock();
            }
        },
    });
}

function containerDisplayHint(container) {
    const kind = containerKind(container);
    if (kind === 'expedition_carry') {
        const backpackSlot = currentEquipment.find((slot) => slot.code === 'backpack');
        if (!backpackSlot?.item) {
            return 'Espaco minimo de expedicao nos bolsos';
        }

        const linked = backpackSlot.item.linked_container?.grid;
        const backpackCols = linked
            ? Number(linked.columns || 0)
            : Math.max(0, Number(container.grid.columns) - 2);
        const backpackRows = linked
            ? Number(linked.rows || 0)
            : Number(container.grid.rows);
        return `Bolsos 2x2 + Mochila ${backpackCols}x${backpackRows}`;
    }

    return container.definition_code;
}

function shouldShowContainerInDock(container) {
    if (containerKind(container) === 'main') return false;
    if (isEquippedBackpackContainer(container)) return false;
    if (container.source_item_public_id && !openContainerPublicIds.has(container.public_id)) {
        return false;
    }
    return true;
}

function gridElement(container) {
    const host = document.createElement('div');
    host.className = 'inventory-grid-host';
    host.dataset.containerPublicId = container.public_id;

    if (containerKind(container) === 'expedition_carry' && equippedBackpackPublicId) {
        host.dataset.expeditionLayout = 'combined';
        host.style.setProperty('--pocket-columns', '2');
    }

    const grid = document.createElement('div');
    grid.className = 'grid-stack inventory-grid';
    grid.dataset.containerPublicId = container.public_id;
    grid.style.setProperty('--inventory-columns', String(container.grid.columns));
    grid.style.setProperty('--inventory-rows', String(container.grid.rows));

    const ghost = document.createElement('div');
    ghost.className = 'inventory-placement-ghost';
    ghost.dataset.placementGhost = container.public_id;
    ghost.hidden = true;

    host.appendChild(grid);
    host.appendChild(ghost);

    return host;
}

function occupancyLabel(container, summaryEntry) {
    if (!summaryEntry) {
        const count = (container.items || []).length;
        return `${count} item(ns)`;
    }

    const percent = Math.round(Number(summaryEntry.occupancy_ratio || 0) * 100);
    return `${summaryEntry.item_count} item(ns) - ${percent}%`;
}

function renderItem(item, options = {}) {
    const quantity = Number(item.quantity || 1);
    const name = itemLabel(item);
    const assetUrl = itemAssetUrl(item);
    const isContainer = Boolean(item.definition?.is_container);
    const placement = item.placement || {};
    const footprintW = Number(placement.grid_w || item.definition?.grid_w || 1);
    const footprintH = Number(placement.grid_h || item.definition?.grid_h || 1);
    const footprintArea = footprintW * footprintH;
    const rarity = rarityKey(item);
    const flags = item.flags || {};
    const classes = [
        'inventory-item',
        isContainer ? 'is-container-item' : '',
        assetUrl ? 'has-art' : '',
        `rarity-${rarity}`,
        flags.locked ? 'is-safety-locked' : '',
        flags.favorite ? 'is-favorite' : '',
        flags.wishlist ? 'is-wishlist' : '',
        options.ghost ? 'is-equipment-ghost' : '',
        placement.rotated ? 'is-rotated' : '',
        footprintArea <= 1 ? 'is-tiny' : '',
        footprintArea <= 2 ? 'is-compact' : '',
        footprintW > footprintH ? 'is-wide' : '',
        footprintH > footprintW ? 'is-tall' : '',
        footprintArea >= 4 ? 'is-large' : '',
    ].filter(Boolean).join(' ');
    const rarityFx = `
        <span class="inventory-rarity-aura" aria-hidden="true"></span>
        <span class="inventory-rarity-runner" aria-hidden="true"></span>
    `;

    return `
        <div class="${classes}" data-item-public-id="${escapeHtml(item.public_id)}" aria-label="${escapeHtml(itemLabel(item))}">
            ${rarityFx}
            ${assetUrl ? `<img class="inventory-item-art" src="${escapeHtml(assetUrl)}" alt="" loading="lazy">` : ''}
            ${options.hideTypeBadge ? '' : renderItemTypeBadge(item)}
            ${renderItemSafetyBadges(item)}
            ${containerItemBadge(item)}
            ${isEquippableItem(item) ? renderUpgradeStars(item) : ''}
            ${compareBadgeForItem(item, comparisonEquippedItem, itemPowerValue)}
            <span class="inventory-item-name">${escapeHtml(name)}</span>
            ${quantity > 1 ? `<span class="inventory-item-quantity">x${quantity}</span>` : ''}
        </div>
    `;
}

function decorateAllItemRarityFx(root = document) {
    const items = root instanceof Element && root.matches('.inventory-item')
        ? [root]
        : root.querySelectorAll?.('.inventory-item') || [];

    items.forEach((item) => {
        if (!item.querySelector(':scope > .inventory-rarity-aura')) {
            const aura = document.createElement('span');
            aura.className = 'inventory-rarity-aura';
            aura.setAttribute('aria-hidden', 'true');
            item.prepend(aura);
        }
        if (!item.querySelector(':scope > .inventory-rarity-runner')) {
            const runner = document.createElement('span');
            runner.className = 'inventory-rarity-runner';
            runner.setAttribute('aria-hidden', 'true');
            item.prepend(runner);
        }
        item.querySelector(':scope > .inventory-rarity-motes')?.remove();
    });
}

function observeAllItemRarityFx() {
    let scheduled = false;
    const decorate = () => {
        scheduled = false;
        decorateAllItemRarityFx(document);
    };
    const observer = new MutationObserver(() => {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(decorate);
    });
    observer.observe(document.body, { childList: true, subtree: true });
    decorate();
}

function renderItemSafetyBadges(item) {
    const flags = item.flags || {};
    const badges = [];
    if (flags.locked) {
        badges.push('<span class="inventory-item-safety-badge is-locked" title="Item travado">L</span>');
    }
    if (flags.favorite) {
        badges.push('<span class="inventory-item-safety-badge is-favorite" title="Item favorito">F</span>');
    }
    if (flags.wishlist) {
        badges.push('<span class="inventory-item-safety-badge is-wishlist" title="Na wishlist">W</span>');
    }

    return badges.length ? `<span class="inventory-item-safety-badges">${badges.join('')}</span>` : '';
}

function renderContainerBreadcrumb(container) {
    const chain = Array.isArray(container.parent_chain) ? container.parent_chain : [];
    if (!chain.length) return '';

    const crumbs = chain.map((entry) => {
        const label = entry.source_item_name || entry.container_name || entry.definition_code || 'Container';
        return `<button type="button" class="inventory-breadcrumb-link" data-breadcrumb-container="${escapeHtml(entry.container_public_id)}">${escapeHtml(label)}</button>`;
    });

    crumbs.push(`<span class="inventory-breadcrumb-current">${escapeHtml(containerDisplayName(container))}</span>`);

    return `<nav class="inventory-container-breadcrumb" aria-label="Navegacao de containers">${crumbs.join('<span aria-hidden="true">›</span>')}</nav>`;
}

function inventoryFilterOptions(containers, mapper) {
    const values = new Set();
    for (const container of containers || []) {
        for (const item of container.items || []) {
            const value = mapper(item);
            if (value) values.add(value);
        }
    }

    return Array.from(values).sort((a, b) => String(a).localeCompare(String(b), 'pt-BR'));
}

function closeInventoryFilterModal() {
    const modal = document.querySelector('[data-inventory-filter-modal]');
    if (modal) modal.hidden = true;
}

function openInventoryFilterModal(containers = []) {
    let modal = document.querySelector('[data-inventory-filter-modal]');
    if (!modal) {
        modal = document.createElement('div');
        modal.className = 'inventory-filter-modal';
        modal.dataset.inventoryFilterModal = '1';
        modal.hidden = true;
        document.body.appendChild(modal);
    }

    const rarities = inventoryFilterOptions(containers, rarityKey);
    const categories = inventoryFilterOptions(containers, itemCategoryCode);
    const flagOptions = [
        ['locked', 'Travados'],
        ['favorite', 'Favoritos'],
        ['wishlist', 'Wishlist'],
        ['container', 'Bags/Baus'],
        ['upgrade', 'Melhorados'],
        ['socketed', 'Com gemas'],
    ];
    const presetOptions = [
        ['all', 'Tudo'],
        ['equipment', 'Equipamentos'],
        ['craft', 'Craft'],
        ['protected', 'Protegidos'],
        ['sell', 'Venda'],
        ['containers', 'Containers'],
    ];
    const active = hasActiveInventoryFilters();

    modal.hidden = false;
    modal.innerHTML = `
        <div class="inventory-filter-modal-backdrop" data-filter-modal-close></div>
        <form class="inventory-filter-modal-card" data-filter-modal-form>
            <header class="inventory-filter-modal-header">
                <div>
                    <p class="inventory-kicker">Filtros</p>
                    <h3>Organizar inventario</h3>
                    <p class="inventory-filter-modal-subtitle">Escolha views, raridade, categoria e flags. Aplica ao fechar ou ao limpar.</p>
                </div>
                <button type="button" class="inventory-filter-modal-close" data-filter-modal-close aria-label="Fechar">×</button>
            </header>
            <section class="inventory-filter-modal-section">
                <span>Views</span>
                <div class="inventory-filter-modal-presets">
                    ${presetOptions.map(([preset, label]) => {
                        const selected = (inventoryFilters.preset || 'all') === preset || (preset === 'all' && !inventoryFilters.preset);
                        return `<button type="button" class="inventory-filter-preset${selected ? ' is-active' : ''}" data-inventory-filter-preset="${escapeHtml(preset)}">${escapeHtml(label)}</button>`;
                    }).join('')}
                </div>
            </section>
            <section class="inventory-filter-modal-section">
                <span>Busca e categorias</span>
                <div class="inventory-filter-modal-fields">
                    <label>
                        Buscar
                        <input type="search" placeholder="Nome, codigo, affix, gema..." value="${escapeHtml(inventoryFilters.q)}" data-inventory-filter-q>
                    </label>
                    <label>
                        Raridade
                        <select data-inventory-filter-rarity>
                            <option value="">Todas</option>
                            ${rarities.map((rarity) => `<option value="${escapeHtml(rarity)}" ${inventoryFilters.rarity === rarity ? 'selected' : ''}>${escapeHtml(rarityLabel({ quality_bucket: rarity }))}</option>`).join('')}
                        </select>
                    </label>
                    <label>
                        Categoria
                        <select data-inventory-filter-category>
                            <option value="">Todas</option>
                            ${categories.map((category) => `<option value="${escapeHtml(category)}" ${inventoryFilters.category === category ? 'selected' : ''}>${escapeHtml(itemCategoryLabel(category))}</option>`).join('')}
                        </select>
                    </label>
                </div>
            </section>
            <section class="inventory-filter-modal-section">
                <span>Flags</span>
                <div class="inventory-filter-modal-flags">
                    ${flagOptions.map(([flag, label]) => `
                        <button type="button" class="inventory-filter-chip${inventoryFilters.flag === flag ? ' is-active' : ''}" data-inventory-filter-flag="${escapeHtml(flag)}">
                            ${escapeHtml(label)}
                        </button>
                    `).join('')}
                </div>
            </section>
            <footer class="inventory-filter-modal-actions">
                <button type="button" class="inventory-button inventory-button-ghost" data-inventory-filter-clear ${active ? '' : 'disabled'}>Limpar</button>
                <button type="submit" class="inventory-button is-primary">Aplicar</button>
            </footer>
        </form>
    `;

    const bindFilterChange = () => {
        persistInventoryFilters();
        syncInventoryFilterControls();
        applyInventoryFilters();
    };

    modal.querySelectorAll('[data-filter-modal-close]').forEach((node) => {
        node.addEventListener('click', closeInventoryFilterModal);
    });
    modal.querySelector('[data-filter-modal-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        bindFilterChange();
        closeInventoryFilterModal();
    });
    modal.querySelectorAll('[data-inventory-filter-preset]').forEach((button) => {
        button.addEventListener('click', () => {
            const preset = button.getAttribute('data-inventory-filter-preset') || 'all';
            inventoryFilters.preset = preset === 'all' ? '' : preset;
            bindFilterChange();
            modal.querySelectorAll('[data-inventory-filter-preset]').forEach((entry) => {
                entry.classList.toggle('is-active', entry.getAttribute('data-inventory-filter-preset') === (inventoryFilters.preset || 'all'));
            });
        });
    });
    modal.querySelector('[data-inventory-filter-q]')?.addEventListener('input', (event) => {
        inventoryFilters.q = event.currentTarget.value || '';
        bindFilterChange();
    });
    modal.querySelector('[data-inventory-filter-rarity]')?.addEventListener('change', (event) => {
        inventoryFilters.rarity = event.currentTarget.value || '';
        bindFilterChange();
    });
    modal.querySelector('[data-inventory-filter-category]')?.addEventListener('change', (event) => {
        inventoryFilters.category = event.currentTarget.value || '';
        bindFilterChange();
    });
    modal.querySelectorAll('[data-inventory-filter-flag]').forEach((button) => {
        button.addEventListener('click', () => {
            const flag = button.getAttribute('data-inventory-filter-flag') || '';
            inventoryFilters.flag = inventoryFilters.flag === flag ? '' : flag;
            bindFilterChange();
            modal.querySelectorAll('[data-inventory-filter-flag]').forEach((entry) => {
                entry.classList.toggle('is-active', entry.getAttribute('data-inventory-filter-flag') === inventoryFilters.flag);
            });
            const clear = modal.querySelector('[data-inventory-filter-clear]');
            if (clear) clear.disabled = !hasActiveInventoryFilters();
        });
    });
    modal.querySelector('[data-inventory-filter-clear]')?.addEventListener('click', () => {
        inventoryFilters = { ...inventoryFilterDefaults };
        bindFilterChange();
        openInventoryFilterModal(containers);
    });
}

function renderInventoryFilterToolbar(containers = []) {
    const toolbar = document.createElement('section');
    toolbar.className = 'inventory-filter-bar is-compact is-header-only';
    toolbar.dataset.inventoryFilters = '1';
    const active = hasActiveInventoryFilters();

    toolbar.innerHTML = `
        <div class="inventory-filter-topline is-minimal">
            ${active ? '<button type="button" class="inventory-filter-clear" data-inventory-filter-clear>Limpar filtros</button>' : '<span class="inventory-filter-spacer" aria-hidden="true"></span>'}
        </div>
        <div class="inventory-selection-bar" data-inventory-selection-bar hidden>
            <div>
                <strong data-inventory-selection-count>0 selecionado(s)</strong>
                <small>Shift+clique seleciona itens. Acoes em lote usam a API autoritativa item a item.</small>
            </div>
            <div class="inventory-selection-actions">
                <button type="button" class="inventory-selection-button" data-inventory-select-visible>Selecionar visiveis</button>
                <button type="button" class="inventory-selection-button" data-inventory-batch-action="LOCK_ITEM">Travar</button>
                <button type="button" class="inventory-selection-button" data-inventory-batch-action="UNLOCK_ITEM">Destravar</button>
                <button type="button" class="inventory-selection-button" data-inventory-batch-action="FAVORITE_ITEM">Favoritar</button>
                <button type="button" class="inventory-selection-button" data-inventory-batch-action="UNFAVORITE_ITEM">Remover favorito</button>
                <button type="button" class="inventory-selection-button" data-inventory-batch-action="WISHLIST_ITEM">Wishlist</button>
                <button type="button" class="inventory-selection-button" data-inventory-batch-action="UNWISHLIST_ITEM">Remover wishlist</button>
                <button type="button" class="inventory-selection-button is-clear" data-inventory-selection-clear>Limpar selecao</button>
            </div>
        </div>
    `;

    toolbar.querySelector('[data-inventory-filter-clear]')?.addEventListener('click', () => {
        inventoryFilters = { ...inventoryFilterDefaults };
        persistInventoryFilters();
        syncInventoryFilterControls();
        applyInventoryFilters();
        toolbar.querySelector('[data-inventory-filter-clear]')?.remove();
    });
    toolbar.querySelector('[data-inventory-select-visible]')?.addEventListener('click', selectVisibleInventoryItems);
    toolbar.querySelector('[data-inventory-selection-clear]')?.addEventListener('click', clearInventorySelection);
    toolbar.querySelectorAll('[data-inventory-batch-action]').forEach((button) => {
        button.addEventListener('click', async () => {
            await executeBatchItemAction(button.getAttribute('data-inventory-batch-action') || '');
        });
    });

    return toolbar;
}

function bindInventoryHeaderFilterButton() {
    const button = document.querySelector('[data-inventory-drawer-right] [data-inventory-filter-open]');
    if (!button || button.dataset.bound === '1') return;
    button.dataset.bound = '1';
    button.addEventListener('click', () => {
        openInventoryFilterModal(allContainersCache || []);
    });
}

function syncInventoryFilterControls() {
    const toolbar = containerRoot?.querySelector('[data-inventory-filters]');
    if (!toolbar) return;
    const clear = toolbar.querySelector('[data-inventory-filter-clear]');
    if (clear) clear.disabled = !hasActiveInventoryFilters();
}

function isItemCurrentlyVisible(publicId) {
    const itemNode = document.querySelector(`[data-item-public-id="${cssEscape(publicId)}"]`);
    const widget = itemNode?.closest('.grid-stack-item');
    return Boolean(widget && !widget.classList.contains('inventory-filter-hidden-item'));
}

function selectedItems() {
    return Array.from(selectedItemPublicIds)
        .map((publicId) => itemIndex.get(publicId)?.item)
        .filter(Boolean);
}

function syncInventorySelectionUi() {
    for (const [publicId] of itemIndex.entries()) {
        const itemNode = document.querySelector(`[data-item-public-id="${cssEscape(publicId)}"]`);
        const widget = itemNode?.closest('.grid-stack-item');
        widget?.classList.toggle('inventory-selected-item', selectedItemPublicIds.has(publicId));
    }

    const bar = containerRoot?.querySelector('[data-inventory-selection-bar]');
    const count = containerRoot?.querySelector('[data-inventory-selection-count]');
    const selectedCount = selectedItems().length;
    if (bar) bar.hidden = selectedCount === 0;
    if (count) count.textContent = `${selectedCount.toLocaleString('pt-BR')} selecionado(s)`;
}

function pruneInventorySelection() {
    for (const publicId of Array.from(selectedItemPublicIds)) {
        if (!itemIndex.has(publicId)) selectedItemPublicIds.delete(publicId);
    }
    if (lastSelectedItemPublicId && !itemIndex.has(lastSelectedItemPublicId)) {
        lastSelectedItemPublicId = null;
    }
}

function toggleInventorySelection(publicId) {
    if (!publicId || !itemIndex.has(publicId)) return;
    if (selectedItemPublicIds.has(publicId)) {
        selectedItemPublicIds.delete(publicId);
    } else {
        selectedItemPublicIds.add(publicId);
    }
    lastSelectedItemPublicId = publicId;
    syncInventorySelectionUi();
}

function clearInventorySelection() {
    selectedItemPublicIds.clear();
    lastSelectedItemPublicId = null;
    syncInventorySelectionUi();
}

function selectVisibleInventoryItems() {
    for (const [publicId] of itemIndex.entries()) {
        if (isItemCurrentlyVisible(publicId)) {
            selectedItemPublicIds.add(publicId);
        }
    }
    syncInventorySelectionUi();
}

const BATCH_ACTION_LABELS = {
    LOCK_ITEM: 'Travar selecionados',
    UNLOCK_ITEM: 'Destravar selecionados',
    FAVORITE_ITEM: 'Favoritar selecionados',
    UNFAVORITE_ITEM: 'Remover favorito dos selecionados',
    WISHLIST_ITEM: 'Adicionar selecionados a wishlist',
    UNWISHLIST_ITEM: 'Remover selecionados da wishlist',
};

async function showBatchOperationAudit(batchId, itemNames = new Map()) {
    if (!batchId) return;

    try {
        setStatus('Carregando auditoria...');
        const response = await apiFetch(`/api/items/actions/bulk/${encodeURIComponent(batchId)}`);
        const details = response.data || {};
        setStatus('Sincronizado');
        showBatchActionResultModal(details.action || '', details, itemNames, { fromAudit: true });
    } catch (error) {
        setStatus('Sincronizado');
        toast(error.message || 'Nao foi possivel carregar a auditoria do lote.', 'error', 3200);
    }
}

function showBatchActionResultModal(actionCode, result, itemNames = new Map(), options = {}) {
    const failedResults = (result.results || []).filter((entry) => !entry.success);
    const retryableIds = failedResults
        .map((entry) => String(entry.item_public_id || ''))
        .filter((publicId) => publicId !== '' && itemIndex.has(publicId));
    const title = BATCH_ACTION_LABELS[actionCode] || actionCode;
    const content = document.createElement('div');
    content.className = 'inventory-batch-result';
    content.innerHTML = `
        <h3>${escapeHtml(title)}</h3>
        ${result.batch_id ? `<p class="inventory-batch-id">Operacao ${escapeHtml(result.batch_id)}${result.status ? ` · ${escapeHtml(result.status)}` : ''}${result.completed_at ? ` · ${escapeHtml(result.completed_at)}` : ''}</p>` : ''}
        <div class="inventory-batch-summary">
            <span><strong>${Number(result.requested || 0).toLocaleString('pt-BR')}</strong> solicitados</span>
            <span class="is-success"><strong>${Number(result.succeeded || 0).toLocaleString('pt-BR')}</strong> concluidos</span>
            <span class="is-warning"><strong>${Number(result.failed || 0).toLocaleString('pt-BR')}</strong> recusados</span>
        </div>
        ${failedResults.length ? `
            <ul class="inventory-batch-failures">
                ${failedResults.slice(0, 12).map((entry) => {
                    const publicId = String(entry.item_public_id || '');
                    const indexedItem = itemIndex.get(publicId)?.item || null;
                    const name = itemNames.get(publicId) || (indexedItem ? itemLabel(indexedItem) : publicId);
                    return `
                        <li>
                            <strong>${escapeHtml(name || publicId || 'Item')}</strong>
                            <small>${escapeHtml(entry.code || 'RECUSADO')} - ${escapeHtml(entry.message || 'Acao recusada pelo servidor.')}</small>
                        </li>
                    `;
                }).join('')}
            </ul>
            ${failedResults.length > 12 ? `<small>${failedResults.length - 12} falha(s) adicional(is) ocultas.</small>` : ''}
        ` : '<p>Todos os itens foram atualizados com sucesso.</p>'}
        <div class="inventory-batch-actions">
            ${retryableIds.length ? '<button type="button" class="inventory-batch-retry">Tentar falhos novamente</button>' : ''}
            ${result.batch_id && !options.fromAudit ? '<button type="button" class="inventory-batch-audit">Ver auditoria</button>' : ''}
            <button type="button" class="inventory-batch-close">Fechar</button>
        </div>
    `;

    const { close, element } = openModal(content, { closeOnBackdrop: true });
    element.querySelector('.inventory-batch-close')?.addEventListener('click', close);
    element.querySelector('.inventory-batch-audit')?.addEventListener('click', async () => {
        close();
        await showBatchOperationAudit(String(result.batch_id || ''), itemNames);
    });
    element.querySelector('.inventory-batch-retry')?.addEventListener('click', async () => {
        close();
        selectedItemPublicIds = new Set(retryableIds);
        syncInventorySelectionUi();
        await executeBatchItemAction(actionCode);
    });
}

async function executeBatchItemAction(actionCode) {
    if (actionInFlight || loading || !actionCode) return;

    const items = selectedItems();
    if (!items.length) {
        toast('Selecione ao menos um item com Shift+clique.', 'info', 2600);
        return;
    }

    const label = BATCH_ACTION_LABELS[actionCode] || actionCode;
    const itemNames = new Map(items.map((item) => [item.public_id, itemLabel(item)]));
    const confirmed = await confirmInventoryAction({
        title: label,
        bodyHtml: `<p>Aplicar esta acao em <strong>${items.length.toLocaleString('pt-BR')} item(ns)</strong>?</p><p>Cada item sera validado pelo servidor individualmente.</p>`,
        confirmLabel: 'Executar',
        tone: actionCode === 'UNLOCK_ITEM' ? 'warning' : 'info',
    });
    if (!confirmed) return;

    actionInFlight = true;

    try {
        setStatus('Executando lote...');
        const response = await apiFetch('/api/items/actions/bulk', {
            method: 'POST',
            body: {
                action_code: actionCode,
                item_public_ids: items.map((item) => item.public_id),
                confirm: true,
            },
        });
        const result = response.data || {};
        const success = Number(result.succeeded || 0);
        const failed = Number(result.failed || 0);

        selectedItemPublicIds.clear();
        lastSelectedItemPublicId = null;
        setStatus('Sincronizado');
        containerDetailCache.invalidate();
        await reloadContainerPanelsOnly();
        showBatchActionResultModal(actionCode, result, itemNames);
        toast(
            failed > 0
                ? `${success} atualizado(s); ${failed} recusado(s).`
                : `${success} item(ns) atualizados.`,
            failed > 0 ? 'warning' : 'success',
            2600
        );
    } finally {
        actionInFlight = false;
    }
}

function applyInventoryFilters() {
    const active = hasActiveInventoryFilters();
    let visible = 0;
    let total = 0;
    const visibleByContainer = new Map();
    const totalByContainer = new Map();

    for (const [publicId, entry] of itemIndex.entries()) {
        const item = entry.item;
        const matches = !active || inventoryItemMatchesFilters(item);
        total += 1;
        if (matches) visible += 1;
        totalByContainer.set(entry.container_public_id, (totalByContainer.get(entry.container_public_id) || 0) + 1);
        visibleByContainer.set(entry.container_public_id, (visibleByContainer.get(entry.container_public_id) || 0) + (matches ? 1 : 0));

        const itemNode = document.querySelector(`[data-item-public-id="${cssEscape(publicId)}"]`);
        const widget = itemNode?.closest('.grid-stack-item');
        widget?.classList.toggle('inventory-filter-hidden-item', active && !matches);
        widget?.classList.toggle('inventory-filter-matched-item', active && matches);
    }

    document.querySelectorAll('[data-container-public-id]').forEach((section) => {
        const containerPublicId = section.getAttribute('data-container-public-id') || '';
        const containerTotal = totalByContainer.get(containerPublicId) || 0;
        const containerVisible = visibleByContainer.get(containerPublicId) || 0;
        section.classList.toggle('inventory-filter-empty-container', active && containerTotal > 0 && containerVisible === 0);
    });

    const count = containerRoot?.querySelector('[data-inventory-filter-count]');
    if (count) {
        count.textContent = active
            ? `${visible.toLocaleString('pt-BR')}/${total.toLocaleString('pt-BR')} visiveis`
            : `${total.toLocaleString('pt-BR')} item(ns)`;
    }

    syncInventoryFilterControls();
    pruneInventorySelection();
    syncInventorySelectionUi();
}

function renderContainer(container, summaryEntry = null, options = {}) {
    const isPhysical = Boolean(container.source_item_public_id);
    const acceptanceTone = container.acceptance_summary?.tone || 'all';
    const floating = Boolean(options.floating);
    const compact = Boolean(options.compact) || floating;
    const slim = Boolean(options.slim) || (!compact && options.full !== true);
    const section = document.createElement('section');
    section.className = `inventory-container acceptance-${escapeHtml(acceptanceTone)}${isPhysical ? ' inventory-container-physical' : ''}${compact ? ' is-arpg-compact' : ''}${slim && !compact ? ' is-arpg-slim' : ''}${floating ? ' is-floating-bag' : ''}`;
    section.dataset.containerPublicId = container.public_id;
    section.dataset.containerKind = containerKind(container);
    if (container.source_item_public_id) {
        section.dataset.sourceItemPublicId = container.source_item_public_id;
    }

    const badge = isPhysical
        ? '<span class="inventory-container-badge">Fisico</span>'
        : '';
    const acceptanceBadges = '';
    const breadcrumb = floating ? '' : renderContainerBreadcrumb(container);
    const canOrganize = !floating && !compact && !slim;
    const canRename = Boolean(container.can_rename) && !floating;
    const titleAttrs = canRename ? ' data-container-rename-title title="Duplo clique para renomear"' : '';
    const canClose = floating
        || isPhysical
        || containerKind(container) === 'market_delivery'
        || containerKind(container) === 'expedition_carry';
    const canExpand = !floating && !compact && containerKind(container) === 'main';
    const expandButton = canExpand
        ? '<button type="button" class="inventory-button inventory-container-expand" data-container-expand title="Carregando custo...">Expandir…</button>'
        : '';

    if (canExpand) {
        const rightDrawer = document.querySelector('[data-inventory-drawer-right]');
        rightDrawer?.style.setProperty('--inventory-columns', String(Number(container.grid.columns || 12)));
    }

    section.innerHTML = floating
        ? `
        <div class="inventory-grid-wrap"></div>
    `
        : compact
        ? `
        <header class="inventory-container-header is-arpg-compact">
            ${canClose ? '<button type="button" class="inventory-container-close" aria-label="Fechar mochila">×</button>' : ''}
            <div class="inventory-container-title-wrap">
                <strong class="inventory-container-title">${escapeHtml(containerDisplayName(container))}</strong>
                <span class="inventory-container-meta">${escapeHtml(containerDisplayHint(container))} · ${escapeHtml(occupancyLabel(container, summaryEntry))}</span>
            </div>
        </header>
        <div class="inventory-grid-wrap"></div>
    `
        : slim
            ? `
        <header class="inventory-container-header is-arpg-slim">
            <div class="inventory-container-title">
                <div class="inventory-container-title-row">
                    <h2${titleAttrs}>${escapeHtml(containerDisplayName(container))}</h2>
                    ${badge}
                </div>
            </div>
            <div class="inventory-container-meta-block">
                ${expandButton}
                ${canClose ? '<button type="button" class="inventory-container-close" aria-label="Fechar container">×</button>' : ''}
            </div>
        </header>
        <div class="inventory-grid-wrap"></div>
    `
            : `
        <header class="inventory-container-header">
            <div class="inventory-container-title">
                ${breadcrumb}
                <div class="inventory-container-title-row">
                    <h2${titleAttrs}>${escapeHtml(containerDisplayName(container))}</h2>
                    ${badge}
                </div>
                ${acceptanceBadges}
                <p>${escapeHtml(containerDisplayHint(container))}</p>
                ${isPhysical ? '<p class="inventory-container-link">Duplo clique no item para abrir · duplo clique no titulo para renomear</p>' : ''}
            </div>
            <div class="inventory-container-meta-block">
                ${expandButton}
                ${canOrganize ? `
                    <div class="inventory-organize-group">
                        <select data-container-organize-mode aria-label="Modo de organizacao">
                            <option value="compact">Compactar</option>
                            <option value="type">Por tipo</option>
                            <option value="rarity">Por raridade</option>
                            <option value="size">Por tamanho</option>
                            <option value="name">Por nome</option>
                        </select>
                        <button type="button" class="inventory-button inventory-container-organize" data-container-organize>Organizar</button>
                    </div>
                ` : ''}
                ${canClose ? '<button type="button" class="inventory-container-close" aria-label="Fechar container">×</button>' : ''}
            </div>
        </header>
        <div class="inventory-grid-wrap"></div>
    `;

    if (canRename && !compact && !floating) {
        section.querySelector('[data-container-rename-title]')?.addEventListener('dblclick', (event) => {
            event.preventDefault();
            event.stopPropagation();
            renameContainerInline(container, event.currentTarget);
        });
    }

    if (!floating) {
        section.querySelector('.inventory-container-close')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (typeof options.onClose === 'function') {
                options.onClose();
                return;
            }
            if (isFloatingContainerOpen(container.public_id)) {
                closeFloatingBagWindow(container.public_id);
                return;
            }
            if (splitViewState?.childPublicId === container.public_id) {
                clearSplitView();
            }
            toggleContainer(container);
        });
    }

    section.querySelector('[data-container-expand]')?.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        await expandMainInventory(container.public_id);
    });

    if (canExpand) {
        void refreshExpandButtonLabel(section, container.public_id);
    }

    section.querySelector('[data-container-organize]')?.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        const mode = section.querySelector('[data-container-organize-mode]')?.value || 'compact';
        await organizeContainer(container.public_id, mode);
    });

    section.querySelectorAll('[data-breadcrumb-container]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const targetPublicId = button.dataset.breadcrumbContainer;
            if (!targetPublicId) return;
            void openFloatingBagWindow(targetPublicId);
        });
    });

    section.querySelector('.inventory-grid-wrap').appendChild(gridElement(container));
    return section;
}

function renderSplitLayout(parentContainer, childContainer, summaryByPublicId) {
    const host = document.createElement('section');
    host.className = 'inventory-split-layout';
    const trail = Array.isArray(splitViewState?.trail) ? splitViewState.trail : [];
    const breadcrumbParts = [];
    const rootId = splitViewState?.rootPublicId || parentContainer.public_id;
    breadcrumbParts.push(`<button type="button" data-split-crumb="${escapeHtml(rootId)}">${escapeHtml(containerDisplayName(
        containerIndex.get(rootId) || allContainersCache.find((entry) => entry.public_id === rootId) || parentContainer
    ))}</button>`);
    for (const entry of trail) {
        const isCurrent = entry.public_id === childContainer.public_id;
        breadcrumbParts.push('<span aria-hidden="true">›</span>');
        breadcrumbParts.push(isCurrent
            ? `<span class="is-current">${escapeHtml(entry.name || 'Container')}</span>`
            : `<button type="button" data-split-crumb="${escapeHtml(entry.public_id)}">${escapeHtml(entry.name || 'Container')}</button>`);
    }
    if (!trail.some((entry) => entry.public_id === childContainer.public_id)) {
        breadcrumbParts.push('<span aria-hidden="true">›</span>');
        breadcrumbParts.push(`<span class="is-current">${escapeHtml(containerDisplayName(childContainer))}</span>`);
    }

    host.innerHTML = `
        <header class="inventory-split-header">
            <div>
                <p class="inventory-kicker">Armazenamento aninhado</p>
                <h2>${escapeHtml(containerDisplayName(childContainer))}</h2>
                <nav class="inventory-split-breadcrumb" aria-label="Caminho do bau">${breadcrumbParts.join('')}</nav>
            </div>
            <button type="button" class="inventory-button inventory-split-close">Voltar ao inventario</button>
        </header>
        <div class="inventory-split-panels"></div>
    `;

    const panels = host.querySelector('.inventory-split-panels');
    panels.appendChild(renderContainer(parentContainer, summaryByPublicId.get(parentContainer.public_id) || null, { full: true }));
    panels.appendChild(renderContainer(childContainer, summaryByPublicId.get(childContainer.public_id) || null, { full: true }));

    host.querySelector('.inventory-split-close')?.addEventListener('click', () => {
        clearSplitView();
        if (childContainer.public_id) {
            openContainerPublicIds.delete(childContainer.public_id);
            persistContainerPanels();
        }
        void closeSplitViewPanels();
    });

    host.querySelectorAll('[data-split-crumb]').forEach((button) => {
        button.addEventListener('click', async () => {
            const targetId = button.getAttribute('data-split-crumb');
            if (!targetId) return;
            await navigateSplitBreadcrumb(targetId);
        });
    });

    return host;
}

async function navigateSplitBreadcrumb(targetPublicId) {
    if (!splitViewState) return;
    const rootId = splitViewState.rootPublicId || splitViewState.parentPublicId;
    if (targetPublicId === rootId) {
        clearSplitView();
        openContainerPublicIds.delete(splitViewState.childPublicId);
        persistContainerPanels();
        await closeSplitViewPanels();
        return;
    }

    const trail = Array.isArray(splitViewState.trail) ? splitViewState.trail : [];
    const index = trail.findIndex((entry) => entry.public_id === targetPublicId);
    if (index < 0) return;

    const nextTrail = trail.slice(0, index + 1);
    const parentId = index === 0 ? rootId : nextTrail[index - 1].public_id;
    splitViewState = {
        rootPublicId: rootId,
        parentPublicId: parentId,
        childPublicId: targetPublicId,
        trail: nextTrail,
    };
    persistSplitView();
    openContainer(targetPublicId);
    await mountSplitView(parentId, targetPublicId);
}

async function refreshExpandButtonLabel(section, containerPublicId) {
    const button = section?.querySelector('[data-container-expand]');
    if (!button || !containerPublicId) return;

    try {
        const previewResponse = await apiFetch(
            `/api/inventory/containers/${encodeURIComponent(containerPublicId)}/expand`
        );
        const preview = previewResponse.data || {};
        if (!preview.can_expand || preview.maxed) {
            button.textContent = 'Maximo';
            button.disabled = true;
            button.title = 'Inventario ja esta no tamanho maximo';
            return;
        }

        const after = preview.grid_after || {};
        const cost = Number(preview.gold_cost || 0);
        button.disabled = false;
        button.textContent = `Expandir · ${cost}G`;
        button.title = `Expandir para ${after.columns || '?'}x${after.rows || '?'} por ${cost} ouro`;
    } catch {
        button.textContent = 'Expandir';
        button.title = 'Expandir inventario com ouro';
    }
}

async function expandMainInventory(containerPublicId) {
    if (actionInFlight || loading || !containerPublicId) return;

    actionInFlight = true;
    try {
        setStatus('Consultando expansao...');
        const previewResponse = await apiFetch(
            `/api/inventory/containers/${encodeURIComponent(containerPublicId)}/expand`
        );
        const preview = previewResponse.data || {};
        if (!preview.can_expand || preview.maxed) {
            toast('Inventario principal ja esta no tamanho maximo.', 'info', 2800);
            setStatus('Sincronizado');
            return;
        }

        const before = preview.grid_before || {};
        const after = preview.grid_after || {};
        const cost = Number(preview.gold_cost || 0);
        const balance = Number(preview.gold_balance || 0);
        const confirmed = window.confirm(
            `Expandir inventario de ${before.columns || '?'}x${before.rows || '?'} para ${after.columns || '?'}x${after.rows || '?'} por ${cost} ouro?\n\nSaldo atual: ${balance} ouro`
        );
        if (!confirmed) {
            setStatus('Sincronizado');
            return;
        }

        setStatus('Expandindo...');
        const response = await apiFetch(
            `/api/inventory/containers/${encodeURIComponent(containerPublicId)}/expand`,
            { method: 'POST', body: {} }
        );
        const data = response.data || {};
        if (data.player_hud) {
            renderPlayerHud(data.player_hud);
        }
        if (Array.isArray(data.player_hud?.wallets)) {
            playerWallets = data.player_hud.wallets;
            renderSummary(data.summary || null, playerWallets);
        }
        const gridAfter = data.grid_after || after;
        toast(
            `Inventario expandido para ${gridAfter.columns}x${gridAfter.rows} (−${Number(data.gold_cost || cost)} ouro).`,
            'success',
            3200
        );
        containerDetailCache.invalidate(containerPublicId);
        await loadInventory({ skipFloatingRestore: false });
    } catch (error) {
        handleError(error, 'Nao foi possivel expandir o inventario.');
    } finally {
        actionInFlight = false;
    }
}

async function organizeContainer(containerPublicId, mode = 'compact') {
    if (actionInFlight || loading) return;

    actionInFlight = true;
    try {
        setStatus('Organizando...');
        const response = await apiFetch(`/api/inventory/containers/${encodeURIComponent(containerPublicId)}/organize`, {
            method: 'POST',
            body: { mode },
        });
        const moved = Number(response.data?.moved_items || 0);
        toast(moved > 0 ? `Container reorganizado (${moved} item(ns) movido(s)).` : 'Container ja estava organizado.', 'success', 2800);
        setStatus('Sincronizado');
        containerDetailCache.invalidate(containerPublicId);
        await resyncContainerPanel(containerPublicId);
    } catch (error) {
        handleError(error, 'Nao foi possivel organizar o container.');
    } finally {
        actionInFlight = false;
    }
}

async function useEquippedPotionHotkey(slotCode) {
    const slot = currentEquipment.find((entry) => entry.code === slotCode);
    if (!slot?.item) {
        toast('Nenhum consumivel equipado neste atalho.', 'info', 2400);
        playInventoryFeedback('invalid');
        return;
    }

    const item = slot.item;
    const effect = item?.definition?.base_config?.use_effect || null;
    const effectKind = String(effect?.kind || '');

    try {
        setStatus('Usando consumivel...');
        if (effectKind === 'food') {
            await apiFetch('/api/player/consume', {
                method: 'POST',
                body: { item_public_id: item.public_id },
            });
            toast(`${itemLabel(item)} consumido.`, 'success', 2200);
            playInventoryFeedback('valid');
            await refreshEquipmentOnly();
            await reloadContainerPanelsOnly().catch(() => null);
            setStatus('Sincronizado');
            return;
        }

        if (effectKind === 'expedition' || effectKind === 'potion' || effect?.stats || effect?.buff_code) {
            try {
                await apiFetch('/api/expeditions/arena/potions/use', {
                    method: 'POST',
                    body: {
                        slot_code: slotCode,
                        item_public_id: item.public_id,
                    },
                });
                toast(`${itemLabel(item)} usado na expedicao.`, 'success', 2200);
                playInventoryFeedback('valid');
                await refreshEquipmentOnly();
                setStatus('Sincronizado');
                return;
            } catch (expeditionError) {
                // Sem expedicao ativa: tenta fluxo generico abaixo.
                if (!(expeditionError instanceof ApiError) || Number(expeditionError.status || 0) >= 500) {
                    throw expeditionError;
                }
            }
        }

        const response = await apiFetch(`/api/items/${encodeURIComponent(item.public_id)}/actions`);
        const useAction = (response.data?.actions || []).find((action) => action.code === 'USE');
        if (!useAction) {
            toast('Este consumivel nao tem uso rapido disponivel agora.', 'info', 2800);
            playInventoryFeedback('invalid');
            return;
        }

        await executeItemAction(item, useAction);
        playInventoryFeedback('valid');
    } catch (error) {
        playInventoryFeedback('invalid');
        handleError(error, 'Nao foi possivel usar o consumivel.');
    }
}

function renderEquipmentAttributes(hud = null) {
    const root = equipmentRoot?.querySelector('[data-equipment-attributes]');
    if (!root) return;

    const attributes = Array.isArray(hud?.attributes) ? hud.attributes : [];
    const unspent = Math.max(0, Number(hud?.unspent_attribute_points || 0));
    const coreCodes = ['strength', 'defense', 'agility', 'energy'];
    const skillCodes = ['investigation', 'exploration', 'mining', 'botany', 'lockpicking'];
    const byCode = new Map(attributes.map((entry) => [String(entry.code || ''), entry]));
    const totalAllocated = coreCodes.reduce((sum, code) => sum + Math.max(0, Number(byCode.get(code)?.allocated_points || 0)), 0);
    const power = hud?.power || {};

    const renderRow = (code, { allocatable = false } = {}) => {
        const entry = byCode.get(code);
        if (!entry) return '';
        const value = Number(entry.value || 0);
        const level = Number(entry.level || 1);
        const allocated = Number(entry.allocated_points || 0);
        const label = escapeHtml(entry.name || code);
        const icon = escapeHtml(entry.icon || code.slice(0, 3).toUpperCase());
        const display = Number.isInteger(value) ? String(value) : value.toFixed(1);
        const canSpend = allocatable && unspent > 0;
        const preview = allocatable ? attributeAllocationPreview(code, hud) : null;
        const previewTitle = preview?.summary || (canSpend ? 'Gastar 1 ponto' : 'Sem pontos');
        const plus = allocatable
            ? `<button type="button" class="inventory-arpg-attr-plus${canSpend ? '' : ' is-disabled'}" data-allocate-attribute="${escapeHtml(code)}" ${canSpend ? '' : 'disabled'} title="${escapeHtml(previewTitle)}">+</button>`
            : '';
        const meta = allocatable
            ? `<small>${allocated > 0 ? `+${allocated} alocado` : 'Pontos de nivel'}</small>`
            : `<small>Nv. ${level}</small>`;

        return `
            <article class="inventory-arpg-attr${allocatable ? ' is-combat' : ' is-skill'}" title="${label}" data-attr-code="${escapeHtml(code)}">
                <span class="inventory-arpg-attr-icon">${icon}</span>
                <div class="inventory-arpg-attr-meta">
                    <strong>${label}</strong>
                    ${meta}
                </div>
                <b>${escapeHtml(display)}</b>
                ${plus}
            </article>
        `;
    };

    const coreHtml = coreCodes.map((code) => renderRow(code, { allocatable: true })).filter(Boolean).join('');
    const skillHtml = skillCodes.map((code) => renderRow(code)).filter(Boolean).join('');

    if (!coreHtml && !skillHtml) {
        root.innerHTML = `
            <header class="inventory-arpg-attributes-header">
                <span>Atributos</span>
                <small>Base do personagem</small>
            </header>
            <p class="inventory-arpg-attributes-empty">Carregando atributos...</p>
        `;
        return;
    }

    const powerLine = `
        <div class="inventory-arpg-power-line" data-attribute-power-preview>
            <span>Poder</span>
            <strong>${Number(power.total || 0).toLocaleString('pt-BR')}</strong>
            <small>ATK ${Number(power.attack || 0)} · DEF ${Number(power.armor || 0)} · AGI ${Number(power.agility || 0)} · HP ${Number(power.life || 0)}</small>
        </div>
    `;

    root.innerHTML = `
        <header class="inventory-arpg-attributes-header">
            <span>Atributos</span>
            <div class="inventory-arpg-points-wrap">
                <small class="inventory-arpg-points${unspent > 0 ? ' has-points' : ''}">${unspent} ponto(s)</small>
                ${totalAllocated > 0 ? `<button type="button" class="inventory-arpg-reset" data-reset-attributes title="Devolve pontos alocados (custa ouro)">Resetar${Number(hud?.next_reset_gold_cost || 0) > 0 ? ` · ${Number(hud.next_reset_gold_cost).toLocaleString('pt-BR')}G` : ''}</button>` : ''}
            </div>
        </header>
        ${powerLine}
        <div class="inventory-arpg-attr-grid is-core">${coreHtml}</div>
        ${skillHtml ? `
            <p class="inventory-arpg-skills-label">Skills de mundo (sobem com uso)</p>
            <div class="inventory-arpg-attr-grid is-skills">${skillHtml}</div>
        ` : ''}
    `;

    root.querySelectorAll('[data-allocate-attribute]').forEach((button) => {
        const code = button.getAttribute('data-allocate-attribute') || '';
        button.addEventListener('click', () => {
            void allocateAttributePoint(code);
        });
        button.addEventListener('pointerenter', () => {
            showAttributeAllocationPreview(code, hud);
        });
        button.addEventListener('focus', () => {
            showAttributeAllocationPreview(code, hud);
        });
        button.addEventListener('pointerleave', () => {
            clearAttributeAllocationPreview(hud);
        });
        button.addEventListener('blur', () => {
            clearAttributeAllocationPreview(hud);
        });
    });

    root.querySelector('[data-reset-attributes]')?.addEventListener('click', () => {
        void resetAttributePoints();
    });
}

function attributeAllocationPreview(attributeCode, hud = lastPlayerHud) {
    const entry = (hud?.attributes || []).find((attr) => String(attr.code || '') === attributeCode);
    if (!entry) return null;
    const from = Number(entry.value || 0);
    const to = from + 1;
    const power = hud?.power || {};
    const deltas = [];
    if (attributeCode === 'defense') {
        deltas.push({ label: 'DEF', delta: 1, next: Number(power.armor || 0) + 1 });
        deltas.push({ label: 'HP', delta: 2, next: Number(power.life || 0) + 2 });
        deltas.push({ label: 'Poder', delta: 2, next: Number(power.total || 0) + 2 });
    } else if (attributeCode === 'strength') {
        deltas.push({ label: 'ATK', delta: 1, next: Number(power.attack || 0) + 1 });
        deltas.push({ label: 'Poder', delta: 2, next: Number(power.total || 0) + 2 });
    } else if (attributeCode === 'agility') {
        deltas.push({ label: 'AGI', delta: 1, next: Number(power.agility || from) + 1 });
        deltas.push({ label: 'Poder', delta: 1, next: Number(power.total || 0) + 1 });
    } else if (attributeCode === 'energy') {
        deltas.push({ label: 'ENE', delta: 1, next: to });
        deltas.push({ label: 'Poder', delta: 1, next: Number(power.total || 0) + 1 });
    }
    const deltaText = deltas.map((row) => `${row.label} ${row.delta > 0 ? '+' : ''}${row.delta}`).join(' · ');
    return {
        code: attributeCode,
        name: entry.name || attributeCode,
        from,
        to,
        deltas,
        summary: `${entry.name || attributeCode}: ${from} → ${to}${deltaText ? ` (${deltaText})` : ''}`,
    };
}

function showAttributeAllocationPreview(attributeCode, hud = lastPlayerHud) {
    const previewRoot = equipmentRoot?.querySelector('[data-attribute-power-preview]');
    const preview = attributeAllocationPreview(attributeCode, hud);
    if (!previewRoot || !preview) return;
    const deltaHtml = preview.deltas
        .map((row) => `<em class="is-gain">${escapeHtml(row.label)} +${row.delta}</em>`)
        .join('');
    previewRoot.classList.add('is-preview');
    previewRoot.innerHTML = `
        <span>Preview</span>
        <strong>${escapeHtml(String(preview.from))}→${escapeHtml(String(preview.to))}</strong>
        <small>${escapeHtml(preview.name)} ${deltaHtml}</small>
    `;
}

function clearAttributeAllocationPreview(hud = lastPlayerHud) {
    const previewRoot = equipmentRoot?.querySelector('[data-attribute-power-preview]');
    if (!previewRoot) return;
    const power = hud?.power || {};
    previewRoot.classList.remove('is-preview');
    previewRoot.innerHTML = `
        <span>Poder</span>
        <strong>${Number(power.total || 0).toLocaleString('pt-BR')}</strong>
        <small>ATK ${Number(power.attack || 0)} · DEF ${Number(power.armor || 0)} · AGI ${Number(power.agility || 0)} · HP ${Number(power.life || 0)}</small>
    `;
}

function applyAttributeMutationResponse(response) {
    const hud = response?.data?.player_hud || null;
    const powerFromHud = hud?.power && typeof hud.power === 'object' ? hud.power : null;
    const powerFromApi = response?.data?.player_power && typeof response.data.player_power === 'object'
        ? response.data.player_power
        : null;

    if (powerFromHud || powerFromApi) {
        playerPower = {
            ...(playerPower || {}),
            ...(powerFromApi || {}),
            ...(powerFromHud || {}),
        };
    }

    if (Array.isArray(response?.data?.character_stats)) {
        lastCharacterStats = response.data.character_stats;
    }

    if (hud) {
        renderPlayerHud(hud);
    } else if (response?.data?.attributes) {
        lastPlayerHud = {
            ...(lastPlayerHud || {}),
            attributes: response.data.attributes,
            unspent_attribute_points: response?.data?.unspent_attribute_points ?? lastPlayerHud?.unspent_attribute_points,
            power: playerPower || lastPlayerHud?.power || null,
        };
        renderEquipmentAttributes(lastPlayerHud);
    }

    renderCharacterStats(lastCharacterStats, currentSetBonuses, playerPower || hud?.power || null, statsDrawerPanel);
}

async function allocateAttributePoint(attributeCode) {
    if (!attributeCode || actionInFlight || loading) return;

    actionInFlight = true;
    try {
        const response = await apiFetch('/api/player/attributes/allocate', {
            method: 'POST',
            body: { attribute_code: attributeCode, points: 1 },
        });
        applyAttributeMutationResponse(response);
        playInventoryFeedback('valid');
        toast('Ponto alocado.', 'success', 1800);
    } catch (error) {
        playInventoryFeedback('invalid');
        handleError(error, 'Nao foi possivel alocar o ponto.');
    } finally {
        actionInFlight = false;
    }
}

async function resetAttributePoints() {
    if (actionInFlight || loading) return;
    const totalAllocated = (lastPlayerHud?.attributes || [])
        .filter((entry) => entry?.allocatable || ['strength', 'defense', 'agility', 'energy'].includes(String(entry?.code || '')))
        .reduce((sum, entry) => sum + Math.max(0, Number(entry.allocated_points || 0)), 0);
    if (totalAllocated <= 0) {
        toast('Nenhum ponto alocado para resetar.', 'info', 2400);
        return;
    }
    const cost = Math.max(0, Number(lastPlayerHud?.next_reset_gold_cost || 150));
    if (!window.confirm(`Resetar ${totalAllocated} ponto(s) por ${cost.toLocaleString('pt-BR')} ouro? Cada reset fica mais caro.`)) {
        return;
    }

    actionInFlight = true;
    try {
        const response = await apiFetch('/api/player/attributes/reset', {
            method: 'POST',
            body: {},
        });
        applyAttributeMutationResponse(response);
        if (Array.isArray(response?.data?.player_hud?.wallets)) {
            playerWallets = response.data.player_hud.wallets;
        }
        playInventoryFeedback('valid');
        const paid = Number(response?.data?.gold_cost || cost);
        toast(`${Number(response?.data?.refunded_points || totalAllocated)} ponto(s) devolvidos${paid > 0 ? ` (−${paid.toLocaleString('pt-BR')}G)` : ''}.`, 'success', 2800);
    } catch (error) {
        playInventoryFeedback('invalid');
        handleError(error, 'Nao foi possivel resetar os pontos.');
    } finally {
        actionInFlight = false;
    }
}

function destroyExpeditionGrid() {
    if (!expeditionRoot) return;

    expeditionRoot.querySelectorAll('[data-container-public-id]').forEach((section) => {
        const publicId = section.getAttribute('data-container-public-id');
        if (!publicId) return;

        const grid = grids.get(publicId);
        if (!grid) return;

        silent = true;
        try {
            grid.destroy(false);
        } finally {
            silent = false;
        }

        grids.delete(publicId);
        gridCellSizes.delete(publicId);

        for (const [itemPublicId, entry] of itemIndex.entries()) {
            if (entry.container_public_id === publicId) {
                itemIndex.delete(itemPublicId);
            }
        }
    });
}

function resolveExpeditionCarryContainer() {
    return [...containerIndex.values()].find((container) => containerKind(container) === 'expedition_carry')
        || allContainersCache.find((container) => containerKind(container) === 'expedition_carry')
        || null;
}

function sizeExpeditionFlyout(container) {
    if (!expeditionRoot || !container?.grid) return;

    const cols = Math.max(1, Number(container.grid.columns) || 6);
    const rows = Math.max(1, Number(container.grid.rows) || 4);
    const cell = 40;
    const panelWidth = cols * cell + 8;

    expeditionRoot.style.setProperty('--expedition-cols', String(cols));
    expeditionRoot.style.setProperty('--expedition-rows', String(rows));
    expeditionRoot.style.setProperty('--inventory-cell', `${cell}px`);
    expeditionRoot.style.setProperty('--expedition-panel-width', `${panelWidth}px`);
}

function alignExpeditionFlyoutToBackpack() {
    if (!expeditionRoot) return;

    if (!(expeditionCarryOpen && leftDrawerOpen)) {
        expeditionRoot.style.removeProperty('--expedition-offset-y');
        return;
    }

    const backpack = equipmentRoot?.querySelector('.inventory-equipment-slot.is-backpack');
    const leftDrawer = document.querySelector('[data-inventory-drawer-left]');
    if (!backpack || !leftDrawer) return;

    const bagRect = backpack.getBoundingClientRect();
    const drawerRect = leftDrawer.getBoundingClientRect();
    if (!bagRect.height || !drawerRect.height) return;

    // Mede depois do layout; centraliza o painel na altura do slot da mochila
    const flyoutHeight = expeditionRoot.getBoundingClientRect().height || 0;
    const backpackCenter = bagRect.top + (bagRect.height / 2) - drawerRect.top;
    const offsetY = Math.max(8, Math.round(backpackCenter - (flyoutHeight / 2)));
    expeditionRoot.style.setProperty('--expedition-offset-y', `${offsetY}px`);
}

function syncExpeditionBagPanel() {
    if (!expeditionRoot) return;

    destroyExpeditionGrid();
    expeditionRoot.replaceChildren();

    if (!expeditionCarryOpen || !equippedBackpackPublicId) {
        if (!equippedBackpackPublicId) expeditionCarryOpen = false;
        expeditionRoot.style.removeProperty('--expedition-offset-y');
        syncDrawerUi();
        return;
    }

    const expeditionContainer = resolveExpeditionCarryContainer();
    if (!expeditionContainer) {
        expeditionCarryOpen = false;
        persistContainerPanels();
        expeditionRoot.style.removeProperty('--expedition-offset-y');
        syncDrawerUi();
        return;
    }

    sizeExpeditionFlyout(expeditionContainer);
    renderExpeditionDrawerSection(
        expeditionContainer,
        inventorySummaryByPublicId.get(expeditionContainer.public_id) || null
    );
    syncDrawerUi();
    window.requestAnimationFrame(() => {
        alignExpeditionFlyoutToBackpack();
        window.requestAnimationFrame(() => alignExpeditionFlyoutToBackpack());
    });
}

function toggleExpeditionBag(forceOpen = null) {
    if (!equippedBackpackPublicId && forceOpen !== false) {
        toast('Equipe uma mochila para abrir a Expedicao.', 'info', 2600);
        return false;
    }

    expeditionCarryOpen = forceOpen == null ? !expeditionCarryOpen : Boolean(forceOpen);
    persistContainerPanels();
    if (expeditionCarryOpen) openLeftDrawer();
    syncExpeditionBagPanel();
    return expeditionCarryOpen;
}

function renderExpeditionDrawerSection(container, summaryEntry = null) {
    if (!expeditionRoot) return;

    sizeExpeditionFlyout(container);
    expeditionRoot.replaceChildren();
    expeditionRoot.appendChild(renderContainer(container, summaryEntry, { compact: true }));
    const section = expeditionRoot.querySelector(`[data-container-public-id="${container.public_id}"]`);
    const gridNode = section?.querySelector('.inventory-grid');
    if (!gridNode) return;

    const grid = initializeGrid(container, gridNode);
    addItems(container, grid);
}

function renderCharacterStats(stats = [], setBonuses = [], power = null, root = null) {
    const target = root || equipmentRoot?.querySelector('[data-character-stats-panel]') || equipmentRoot?.querySelector('[data-character-stats]');
    if (!target) return;

    const coreCodes = ['strength', 'defense', 'agility', 'energy', 'attack_power', 'armor', 'max_health'];
    const byCode = new Map((stats || []).map((stat) => [String(stat.code || ''), stat]));
    const visibleStats = stats
        .filter((stat) => Number(stat.value || 0) !== 0)
        .slice()
        .sort((a, b) => {
            const aCore = coreCodes.indexOf(String(a.code || ''));
            const bCore = coreCodes.indexOf(String(b.code || ''));
            if (aCore !== -1 || bCore !== -1) {
                if (aCore === -1) return 1;
                if (bCore === -1) return -1;
                return aCore - bCore;
            }
            return Math.abs(Number(b.value || 0)) - Math.abs(Number(a.value || 0));
        });
    const attack = Number(power?.attack || 0);
    const armor = Number(power?.armor || 0);
    const life = Number(power?.life || 0);
    const agility = Number(power?.agility ?? byCode.get('agility')?.value ?? 0);
    const total = Number(power?.total || 0);
    const hasCorePower = attack > 0 || armor > 0 || life > 0 || agility > 0 || total > 0;

    const coreBlock = hasCorePower
        ? `<div class="inventory-character-power-core">
            <div class="inventory-character-power-total">
                <span>Poder</span>
                <strong>${total > 0 ? total.toLocaleString('pt-BR') : '—'}</strong>
            </div>
            <div class="inventory-character-power-metrics">
                <div><span>ATK</span><strong>${attack > 0 ? attack.toLocaleString('pt-BR') : '—'}</strong></div>
                <div><span>DEF</span><strong>${armor > 0 ? armor.toLocaleString('pt-BR') : '—'}</strong></div>
                <div><span>AGI</span><strong>${agility > 0 ? agility.toLocaleString('pt-BR') : '—'}</strong></div>
                <div><span>HP</span><strong>${life > 0 ? life.toLocaleString('pt-BR') : '—'}</strong></div>
            </div>
        </div>`
        : '';

    if (!visibleStats.length && !setBonuses.length && !hasCorePower) {
        target.innerHTML = '<span class="inventory-character-stat-empty">Sem bonus de equipamento ativos.</span>';
        return;
    }

    const topStats = visibleStats.slice(0, 12);
    const bonusList = setBonuses.length
        ? `<div class="inventory-set-bonuses">${setBonuses.map((set) => `
            <section>
                <strong style="color: ${/^#[0-9a-f]{6}$/i.test(String(set.aura_color || '')) ? escapeHtml(set.aura_color) : '#55c58a'}">${escapeHtml(set.set_name)}</strong>
                <span>${Number(set.equipped_pieces || 0)} peca(s)</span>
                ${(set.bonuses || []).map((bonus) => `<small>${escapeHtml(bonus.description || `${bonus.name} +${bonus.value}${bonus.unit || ''}`)}</small>`).join('')}
            </section>
        `).join('')}</div>`
        : '';

    target.innerHTML = `
        ${coreBlock}
        ${topStats.length ? `<div class="inventory-character-stat-list">${topStats.map((stat) => {
            const numeric = Number(stat.value || 0);
            const value = Number.isInteger(numeric) ? String(numeric) : numeric.toFixed(1);
            const sign = numeric > 0 ? '+' : '';
            return `<div class="inventory-character-stat-row"><span>${escapeHtml(stat.name)}</span><b>${sign}${escapeHtml(value)}${stat.unit ? escapeHtml(stat.unit) : ''}</b></div>`;
        }).join('')}</div>` : ''}
        ${visibleStats.length > topStats.length ? `<p class="inventory-character-stat-more">+${visibleStats.length - topStats.length} bonus menores</p>` : ''}
        ${bonusList}
    `;
}

function toggleCharacterPanel() {
    toggleLeftDrawer();
}

function renderContainerDock() {
    if (marketToggleButton) {
        marketToggleButton.hidden = true;
        marketToggleButton.classList.toggle('is-active', marketDeliveryOpen);
        marketToggleButton.textContent = marketDeliveryOpen ? 'Fechar entregas' : 'Entregas';
    }
}

function resolveJewelType(item) {
    const code = String(item?.definition?.code || '');
    if (BLESS_JEWEL_CODES.includes(code) || code.includes('bless')) return 'bless';
    if (SOUL_JEWEL_CODES.includes(code) || code.includes('soul')) return 'soul';
    if (CHAOS_JEWEL_CODES.includes(code) || code.includes('chaos')) return 'chaos';
    if (REROLL_JEWEL_CODES.includes(code) || code.includes('reroll')) return 'reroll';

    const configured = String(
        item?.definition?.base_config?.jewel_type
        || item?.definition?.base_config?.enhancement_jewel_type
        || ''
    ).toLowerCase();
    if (['bless', 'soul', 'chaos', 'reroll'].includes(configured)) {
        return configured;
    }

    if (code.startsWith('jewel_') || item?.definition?.base_config?.enhancement_type === 'upgrade_jewel') {
        return 'bless';
    }

    return null;
}

function findItemUnderPointer(clientX, clientY, exceptItemPublicId = null) {
    const stack = typeof document.elementsFromPoint === 'function'
        ? document.elementsFromPoint(clientX, clientY)
        : [];

    for (const el of stack) {
        if (!(el instanceof Element)) continue;
        if (el.closest('.ui-draggable-dragging, .inventory-placement-ghost, .inventory-drag-mirror, .tippy-box')) {
            continue;
        }
        const itemEl = el.closest('.inventory-item[data-item-public-id], .grid-stack-item[gs-id]');
        if (!itemEl) continue;
        const itemPublicId = itemEl.dataset.itemPublicId
            || itemEl.getAttribute('gs-id')
            || itemEl.closest('.grid-stack-item')?.getAttribute('gs-id');
        if (!itemPublicId || itemPublicId === exceptItemPublicId) continue;
        const entry = itemIndex.get(itemPublicId);
        if (!entry?.item) continue;
        return entry.item;
    }

    // Fallback: celula do grid sob o ponteiro (arte pequena / gaps no widget).
    const hover = findGridUnderPointer(clientX, clientY);
    if (!hover?.grid?.el) return null;
    const container = containerIndex.get(hover.containerPublicId);
    if (!container) return null;

    const cell = gridCellFromPointer(
        hover.grid.el,
        clientX,
        clientY,
        1,
        1,
        Number(container.grid.columns || 0),
        Number(container.grid.rows || 0)
    );
    const snapshot = placementSnapshotForContainer(hover.containerPublicId, exceptItemPublicId);
    const hit = findOverlapInSnapshot(snapshot, cell.x, cell.y, 1, 1);
    if (!hit) return null;
    return itemIndex.get(hit.id)?.item || null;
}

const equipmentSlotVisualCode = visualSlotCode;

function isJewelItem(item) {
    const code = String(item?.definition?.code || '');
    return resolveJewelType(item) !== null || code.startsWith('jewel_') || item?.definition?.base_config?.enhancement_type === 'upgrade_jewel';
}

function isEnhanceableEquipment(item) {
    if (!item?.definition?.equip_slot_code) return false;
    if (item.definition?.stackable) return false;
    return true;
}

function canAttemptEnhance(jewel, target) {
    if (!jewel || !target) return false;
    if (jewel.public_id === target.public_id) return false;
    if (!isJewelItem(jewel)) return false;
    return isEnhanceableEquipment(target);
}

function findEnhanceTarget(snapshot, x, y, w, h, sourceItem) {
    const overlaps = findOverlappingPlacements(snapshot, x, y, w, h);
    for (const placement of overlaps) {
        const target = itemIndex.get(placement.id)?.item;
        if (!target || !canAttemptEnhance(sourceItem, target)) continue;
        const jewelType = resolveJewelType(sourceItem);
        if (!jewelType) continue;
        return { target, jewelType };
    }
    return null;
}

function isGemItem(item) {
    const code = String(item?.definition?.code || '');
    return code.startsWith('gem_');
}

function hasEmptySocket(item) {
    const sockets = Array.isArray(item?.sockets) ? item.sockets : [];
    return sockets.some((socket) => socket.status === 'empty' || !socket.gem);
}

function canAttemptSocket(gem, target) {
    if (!gem || !target) return false;
    if (gem.public_id === target.public_id) return false;
    if (!isGemItem(gem)) return false;
    if (!isEnhanceableEquipment(target)) return false;
    return hasEmptySocket(target);
}

function findSocketTarget(snapshot, x, y, w, h, sourceItem) {
    const overlaps = findOverlappingPlacements(snapshot, x, y, w, h);
    for (const placement of overlaps) {
        const target = itemIndex.get(placement.id)?.item;
        if (!target || !canAttemptSocket(sourceItem, target)) continue;
        return { target };
    }
    return null;
}

function cleanupDragUi() {
    clearAllGhostPreviews();
    clearEquipmentDragHighlights();
    clearHotbarDropHighlights();
    clearDragMirror();
    document.querySelectorAll('.grid-stack-placeholder').forEach((placeholder) => placeholder.remove());
    document
        .querySelectorAll('.inventory-placement-valid, .inventory-placement-invalid, .inventory-placement-merge, .inventory-placement-deposit, .inventory-placement-bless, .inventory-placement-soul, .inventory-placement-chaos, .inventory-placement-reroll, .inventory-placement-socket, .inventory-rotated-preview')
        .forEach((element) => clearPlacementHint(element));
    document.querySelectorAll('.inventory-rotation-helper').forEach((element) => clearRotationHelper(element));
}

function updateEquipmentDragHighlights(clientX, clientY) {
    clearEquipmentDragHighlights();
    clearHotbarDropHighlights();
    if (!activeDrag?.itemPublicId) return;

    document.documentElement.classList.add('is-inventory-dragging');

    const current = itemIndex.get(activeDrag.itemPublicId);
    const sourceSection = document.querySelector(`[data-container-public-id="${activeDrag.sourceContainerPublicId}"]`);
    sourceSection?.classList.add('is-drag-source');

    if (clientX != null && clientY != null) {
        const hover = findGridUnderPointer(clientX, clientY);
        if (hover?.containerPublicId) {
            document
                .querySelector(`[data-container-public-id="${hover.containerPublicId}"]`)
                ?.classList.add('is-drag-target');
        }
    }

    if (current?.item && isHotbarCompatibleItem(current.item)) {
        document.querySelectorAll('[data-hotbar-slot]:not(:disabled)').forEach((slot) => {
            slot.classList.add('is-drop-hint');
        });
        if (clientX != null && clientY != null) {
            const hotbarSlot = findHotbarSlotUnderPointer(clientX, clientY);
            if (hotbarSlot) {
                document
                    .querySelector(`[data-hotbar-slot="${hotbarSlot}"]`)
                    ?.classList.add('is-hotbar-drop');
            }
        }
    }

    if (!current?.item || current.item.equipped || !isEquippableItem(current.item)) return;
    if (!leftDrawerOpen || !equipmentRoot) return;

    equipmentRoot.querySelectorAll('.inventory-equipment-slot').forEach((slot) => {
        if (!equipmentSlotMatchesItem(slot, current.item)) return;
        slot.classList.add('is-drag-compatible');
        if (clientX != null && clientY != null && isPointerInsideElement(slot, clientX, clientY)) {
            slot.classList.add('is-drag-hover');
        }
    });
}

function findContainerItemUnderPointer(clientX, clientY, gridEl, containerPublicId, exceptItemPublicId) {
    const grid = grids.get(containerPublicId);
    if (!grid?.el) return null;

    const rect = gridEl.getBoundingClientRect();
    const cellSize = cellSizeForContainer(containerPublicId);
    const cellX = Math.floor((clientX - rect.left) / cellSize);
    const cellY = Math.floor((clientY - rect.top) / cellSize);
    const snapshot = targetSnapshotForGrid(containerPublicId, grid, exceptItemPublicId);

    for (const placement of snapshot) {
        if (
            cellX >= placement.x
            && cellX < placement.x + placement.w
            && cellY >= placement.y
            && cellY < placement.y + placement.h
        ) {
            const item = itemIndex.get(placement.id)?.item;
            if (item?.definition?.is_container && item.linked_container?.public_id) {
                return item;
            }
        }
    }

    return null;
}

function findDepositSlot(linkedContainerPublicId, item, preferredRotated = false) {
    ensureContainerIndexForDeposit(linkedContainerPublicId);

    const base = baseDimensions(item);
    const orientations = base.w === base.h
        ? [preferredRotated]
        : [preferredRotated, !preferredRotated];

    for (const rotated of orientations) {
        const size = dimensionsForState(item, rotated);
        const slot = findFirstFreeSlot(linkedContainerPublicId, size.w, size.h);
        if (slot) {
            return { slot, rotated };
        }
    }

    return null;
}

function ensureContainerIndexForDeposit(containerPublicId) {
    if (!containerPublicId) return;
    if (containerIndex.get(containerPublicId)?.grid?.columns) return;

    const cached = containerDetailCache.get(containerPublicId)?.container;
    if (cached?.public_id) {
        containerIndex.set(cached.public_id, cached);
        return;
    }

    // Fallback: metadados do linked_container no itemIndex.
    for (const entry of itemIndex.values()) {
        const linked = entry.item?.linked_container || entry.linked_container;
        if (linked?.public_id !== containerPublicId) continue;
        const columns = Number(linked.grid?.columns || linked.columns || 0);
        const rows = Number(linked.grid?.rows || linked.rows || 0);
        if (columns > 0 && rows > 0) {
            containerIndex.set(containerPublicId, {
                public_id: containerPublicId,
                name: linked.name || 'Bau',
                grid: { columns, rows },
                items: [],
            });
        }
        break;
    }
}

async function refreshClosedContainerOccupancy(containerPublicId) {
    if (!containerPublicId || grids.has(containerPublicId)) return true;

    const inflight = containerDetailCache.getInflight(containerPublicId);
    if (inflight) {
        try {
            await inflight;
        } catch {
            // keep going with cache
        }
        return Boolean(containerDetailCache.get(containerPublicId)?.container || containerIndex.get(containerPublicId));
    }

    try {
        const detailPromise = fetchContainerDetail(containerPublicId);
        containerDetailCache.markInflight(containerPublicId, detailPromise);
        const detail = await detailPromise;
        if (detail?.container?.public_id) {
            containerIndex.set(detail.container.public_id, detail.container);
            return true;
        }
    } catch {
        // keep cache
    } finally {
        containerDetailCache.clearInflight(containerPublicId);
    }
    return Boolean(containerDetailCache.get(containerPublicId)?.container);
}

function ensureClosedContainerReady(containerPublicId) {
    if (!containerPublicId || grids.has(containerPublicId)) return true;
    ensureContainerIndexForDeposit(containerPublicId);
    const cached = containerDetailCache.get(containerPublicId)?.container;
    if (cached?.public_id) {
        containerIndex.set(cached.public_id, cached);
        if (!containerDetailCache.isFresh(containerPublicId)) {
            void refreshClosedContainerOccupancy(containerPublicId);
        }
        return true;
    }
    return Boolean(containerIndex.get(containerPublicId)?.grid?.columns);
}

function prefetchClosedContainerOccupancy(containerPublicId) {
    if (!containerPublicId || grids.has(containerPublicId)) return;
    if (containerDetailCache.isFresh(containerPublicId)) return;
    if (containerDetailCache.getInflight(containerPublicId)) return;
    void refreshClosedContainerOccupancy(containerPublicId);
}

function resolveDepositTarget(containerPublicId, grid, itemPublicId, x, y, w, h, pointerCoords = null) {
    const current = itemIndex.get(itemPublicId);
    if (!current || current.item.definition?.is_container) return null;

    const snapshot = targetSnapshotForGrid(containerPublicId, grid, itemPublicId);
    const candidates = [];

    if (pointerCoords && grid?.el) {
        const underPointer = findContainerItemUnderPointer(
            pointerCoords.clientX,
            pointerCoords.clientY,
            grid.el,
            containerPublicId,
            itemPublicId
        );
        if (underPointer) candidates.push(underPointer);
    }

    for (const placement of findOverlappingPlacements(snapshot, x, y, w, h)) {
        const overlapItem = itemIndex.get(placement.id)?.item;
        if (overlapItem?.definition?.is_container && overlapItem.linked_container?.public_id) {
            candidates.push(overlapItem);
        }
    }

    const seen = new Set();
    for (const overlapItem of candidates) {
        if (!overlapItem?.public_id || seen.has(overlapItem.public_id)) continue;
        seen.add(overlapItem.public_id);

        const slotResult = findDepositSlot(
            overlapItem.linked_container.public_id,
            current.item,
            Boolean(current.rotated)
        );
        if (!slotResult) continue;

        return {
            linkedContainer: overlapItem.linked_container,
            overlapItem,
            slot: slotResult.slot,
            rotated: slotResult.rotated,
        };
    }

    return null;
}

function evaluatePlacement(containerPublicId, grid, itemPublicId, x, y, w, h, pointerCoords = null) {
    const current = itemIndex.get(itemPublicId);
    if (!current) return { state: 'invalid' };

    if (isItemMovingIntoOwnContainer(current.item, containerPublicId)) {
        return { state: 'invalid', reason: 'self_container' };
    }

    const targetContainer = containerIndex.get(containerPublicId);
    if (targetContainer && !canContainerAcceptItem(targetContainer, current.item)) {
        return { state: 'invalid', reason: 'acceptance' };
    }

    // Acoes especiais (joia/gema/merge) priorizam o item sob o ponteiro — mais fiel ao visual.
    if (pointerCoords?.clientX != null && pointerCoords?.clientY != null) {
        const underPointer = findItemUnderPointer(
            pointerCoords.clientX,
            pointerCoords.clientY,
            itemPublicId
        );

        if (underPointer) {
            if (isJewelItem(current.item) && canAttemptEnhance(current.item, underPointer)) {
                const jewelType = resolveJewelType(current.item);
                if (jewelType) {
                    return { state: jewelType, overlapItem: underPointer };
                }
            }

            if (isGemItem(current.item) && canAttemptSocket(current.item, underPointer)) {
                return { state: 'socket', overlapItem: underPointer };
            }

            if (canAttemptMerge(current.item, underPointer)) {
                return { state: 'merge', overlapItem: underPointer };
            }
        }
    }

    const snapshot = targetSnapshotForGrid(containerPublicId, grid, itemPublicId);

    if (isJewelItem(current.item)) {
        const enhanceTarget = findEnhanceTarget(snapshot, x, y, w, h, current.item);
        if (enhanceTarget) {
            return {
                state: enhanceTarget.jewelType,
                overlapItem: enhanceTarget.target,
            };
        }
    }

    if (isGemItem(current.item)) {
        const socketTarget = findSocketTarget(snapshot, x, y, w, h, current.item);
        if (socketTarget) {
            return {
                state: 'socket',
                overlapItem: socketTarget.target,
            };
        }
    }

    const mergeTarget = findMergeTarget(snapshot, x, y, w, h, current.item);
    if (mergeTarget) {
        return { state: 'merge', overlapItem: mergeTarget };
    }

    const depositTarget = resolveDepositTarget(
        containerPublicId,
        grid,
        itemPublicId,
        x,
        y,
        w,
        h,
        pointerCoords
    );
    if (depositTarget) {
        return {
            state: 'deposit',
            linkedContainer: depositTarget.linkedContainer,
            slot: depositTarget.slot,
            overlapItem: depositTarget.overlapItem,
            rotated: depositTarget.rotated,
        };
    }

    const overlapPlacement = findOverlapInSnapshot(snapshot, x, y, w, h);
    const overlapItem = overlapPlacement ? itemIndex.get(overlapPlacement.id)?.item : null;

    if (overlapItem?.definition?.is_container) {
        return { state: 'invalid', reason: 'container_full' };
    }

    const valid = isPlacementValidAgainstSnapshot(containerPublicId, snapshot, x, y, w, h);
    return { state: valid ? 'valid' : 'invalid' };
}

function updateAllGhostPreviews(clientX, clientY) {
    clearAllGhostPreviews();
    if (!activeDrag?.itemPublicId) return;

    const dragged = findDraggedWidget();
    const hover = findGridUnderPointer(clientX, clientY);
    if (!hover) return;

    const container = containerIndex.get(hover.containerPublicId);
    const current = itemIndex.get(activeDrag.itemPublicId);
    if (!container || !current) return;

    const size = dimensionsForState(current.item, activeDrag.rotated ?? current.rotated);
    const useRotationAnchor = activeDrag.rotationAnchor
        && activeDrag.rotationAnchor.containerPublicId === hover.containerPublicId
        && !hasPointerMovedFromRotationAnchor(clientX, clientY);
    const pointerCoords = !useRotationAnchor && activeDrag.pointerX != null && activeDrag.pointerY != null
        ? { clientX: activeDrag.pointerX, clientY: activeDrag.pointerY }
        : null;
    const cell = pointerCoords
        ? gridCellFromPointer(
            hover.grid.el,
            pointerCoords.clientX,
            pointerCoords.clientY,
            size.w,
            size.h,
            Number(container.grid.columns || 0),
            Number(container.grid.rows || 0)
        )
        : {
            x: Math.round(Number(activeDrag.hoverX ?? dragged?.node?.x ?? 0)),
            y: Math.round(Number(activeDrag.hoverY ?? dragged?.node?.y ?? 0)),
        };

    activeDrag.hoverContainerPublicId = hover.containerPublicId;
    activeDrag.hoverGrid = hover.grid;
    activeDrag.hoverX = cell.x;
    activeDrag.hoverY = cell.y;

    const evaluation = evaluatePlacement(
        hover.containerPublicId,
        hover.grid,
        activeDrag.itemPublicId,
        cell.x,
        cell.y,
        size.w,
        size.h,
        { clientX, clientY }
    );

    activeDrag.hoverState = evaluation.state;
    activeDrag.depositContainerPublicId = null;
    activeDrag.depositSlot = null;
    activeDrag.depositRotated = null;

    if (evaluation.state === 'deposit' && evaluation.slot && evaluation.linkedContainer) {
        activeDrag.depositContainerPublicId = evaluation.linkedContainer.public_id;
        activeDrag.depositSlot = evaluation.slot;
        activeDrag.depositRotated = evaluation.rotated ?? Boolean(current.rotated);
        prefetchClosedContainerOccupancy(evaluation.linkedContainer.public_id);
        const depositSize = dimensionsForState(current.item, activeDrag.depositRotated);
        renderGhostPreview(
            evaluation.linkedContainer.public_id,
            evaluation.slot.grid_x,
            evaluation.slot.grid_y,
            depositSize.w,
            depositSize.h,
            'deposit'
        );
        return;
    }

    renderGhostPreview(hover.containerPublicId, cell.x, cell.y, size.w, size.h, evaluation.state);
}

function scheduleGhostPreviewUpdate(clientX, clientY) {
    ghostPreviewCoords = { clientX, clientY };
    if (ghostPreviewFrame) return;

    ghostPreviewFrame = window.requestAnimationFrame(() => {
        ghostPreviewFrame = 0;
        const coords = ghostPreviewCoords;
        ghostPreviewCoords = null;
        if (!coords || !activeDrag) return;
        updateAllGhostPreviews(coords.clientX, coords.clientY);
    });
}

function applyPlacementHintClasses(element, state, rotated = false) {
    element?.classList.remove(
        'inventory-placement-valid',
        'inventory-placement-invalid',
        'inventory-placement-merge',
        'inventory-placement-deposit',
        'inventory-placement-bless',
        'inventory-placement-soul',
        'inventory-placement-chaos',
        'inventory-placement-reroll',
        'inventory-placement-socket',
        'inventory-rotated-preview'
    );
    if (state === 'valid') element?.classList.add('inventory-placement-valid');
    if (state === 'invalid') element?.classList.add('inventory-placement-invalid');
    if (state === 'merge') element?.classList.add('inventory-placement-merge');
    if (state === 'deposit') element?.classList.add('inventory-placement-deposit');
    if (state === 'bless') element?.classList.add('inventory-placement-bless');
    if (state === 'soul') element?.classList.add('inventory-placement-soul');
    if (state === 'chaos') element?.classList.add('inventory-placement-chaos');
    if (state === 'reroll') element?.classList.add('inventory-placement-reroll');
    if (state === 'socket') element?.classList.add('inventory-placement-socket');
    if (rotated) element?.classList.add('inventory-rotated-preview');
}

function updatePlacementHintFromPointer(element, coords) {
    if (!activeDrag?.itemPublicId || !coords) return;

    const dragged = findDraggedWidget();
    const hover = findGridUnderPointer(coords.clientX, coords.clientY);
    if (!hover) {
        applyPlacementHintClasses(element, 'invalid', Boolean(itemIndex.get(activeDrag.itemPublicId)?.rotated));
        return;
    }

    if (dragged?.containerPublicId === hover.containerPublicId) {
        updatePlacementHint(element);
        return;
    }

    const container = containerIndex.get(hover.containerPublicId);
    const current = itemIndex.get(activeDrag.itemPublicId);
    if (!container || !current) return;

    const size = dimensionsForState(current.item, current.rotated);
    const cell = gridCellFromPointer(
        hover.grid.el,
        coords.clientX,
        coords.clientY,
        size.w,
        size.h,
        Number(container.grid.columns || 0),
        Number(container.grid.rows || 0)
    );

    const evaluation = evaluatePlacement(
        hover.containerPublicId,
        hover.grid,
        activeDrag.itemPublicId,
        cell.x,
        cell.y,
        size.w,
        size.h,
        coords
    );

    applyPlacementHintClasses(element, evaluation.state, Boolean(current.rotated));
}

function clearDragMirror() {
    if (dragMirrorEl) {
        dragMirrorEl.remove();
        dragMirrorEl = null;
    }
    document.querySelectorAll('.grid-stack-item.is-drag-mirrored')
        .forEach((element) => element.classList.remove('is-drag-mirrored'));
}

function buildDragMirrorCard(sourceElement) {
    const content = sourceElement.querySelector('.grid-stack-item-content') || sourceElement;
    const itemNode = sourceElement.querySelector('.inventory-item');
    const rect = content.getBoundingClientRect();
    const width = Math.max(36, Math.round(rect.width || Number(activeDrag?.mirrorWidth) || 44));
    const height = Math.max(36, Math.round(rect.height || Number(activeDrag?.mirrorHeight) || 44));

    const mirror = document.createElement('div');
    mirror.className = 'inventory-drag-mirror';
    mirror.setAttribute('aria-hidden', 'true');
    mirror.style.width = `${width}px`;
    mirror.style.height = `${height}px`;

    const card = document.createElement('div');
    card.className = 'inventory-drag-mirror-card';
    if (itemNode) {
        itemNode.classList.forEach((className) => {
            if (className.startsWith('rarity-') || className === 'inventory-item' || className === 'is-set-neon') {
                card.classList.add(className);
            }
        });
    }

    const art = content.querySelector('.inventory-item-art, img');
    if (art instanceof HTMLImageElement && art.src) {
        const img = document.createElement('img');
        img.src = art.currentSrc || art.src;
        img.alt = '';
        img.draggable = false;
        card.appendChild(img);
    } else {
        const clone = content.cloneNode(true);
        clone.querySelectorAll('script, .tippy-box, [data-tippy-root]').forEach((node) => node.remove());
        card.appendChild(clone);
    }

    const qty = content.querySelector('.inventory-item-qty, .inventory-item-quantity');
    if (qty) {
        const badge = document.createElement('span');
        badge.className = 'inventory-drag-mirror-qty';
        badge.textContent = qty.textContent || '';
        card.appendChild(badge);
    }

    mirror.appendChild(card);
    return { mirror, width, height, contentRect: rect };
}

function syncDragMirror(clientX, clientY, sourceElement) {
    if (clientX == null || clientY == null || !(sourceElement instanceof HTMLElement)) return;

    if (!dragMirrorEl) {
        const built = buildDragMirrorCard(sourceElement);
        if (activeDrag) {
            const rect = built.contentRect;
            activeDrag.mirrorOffsetX = Number.isFinite(clientX - rect.left)
                ? Math.max(6, Math.min(built.width - 6, clientX - rect.left))
                : built.width / 2;
            activeDrag.mirrorOffsetY = Number.isFinite(clientY - rect.top)
                ? Math.max(6, Math.min(built.height - 6, clientY - rect.top))
                : built.height / 2;
            activeDrag.mirrorWidth = built.width;
            activeDrag.mirrorHeight = built.height;
        }

        document.body.appendChild(built.mirror);
        dragMirrorEl = built.mirror;
        sourceElement.classList.add('is-drag-mirrored');
        document.documentElement.classList.add('is-inventory-dragging');
    }

    const offsetX = Number(activeDrag?.mirrorOffsetX ?? (dragMirrorEl.offsetWidth / 2));
    const offsetY = Number(activeDrag?.mirrorOffsetY ?? (dragMirrorEl.offsetHeight / 2));
    dragMirrorEl.style.transform = `translate3d(${Math.round(clientX - offsetX)}px, ${Math.round(clientY - offsetY)}px, 0)`;
}

function onDocumentDragPointer(event) {
    if (!activeDrag) return;

    activeDrag.pointerX = event.clientX;
    activeDrag.pointerY = event.clientY;
    if (activeDrag.rotationAnchor && hasPointerMovedFromRotationAnchor(event.clientX, event.clientY)) {
        activeDrag.rotationAnchor = null;
    }

    if (syncHoverFromPointer(event.clientX, event.clientY)) {
        scheduleGhostPreviewUpdate(event.clientX, event.clientY);
    }

    updateEquipmentDragHighlights(event.clientX, event.clientY);

    const dragged = findDraggedWidget();
    if (dragged?.element) {
        syncDragMirror(event.clientX, event.clientY, dragged.element);
        enforceDraggedFootprint(dragged);
        updatePlacementHint(dragged.element);
    }
}

function beginDragSession() {
    document.addEventListener('mousemove', onDocumentDragPointer, true);
    document.addEventListener('pointermove', onDocumentDragPointer, true);
}

function endDragSession() {
    document.removeEventListener('mousemove', onDocumentDragPointer, true);
    document.removeEventListener('pointermove', onDocumentDragPointer, true);
    if (ghostPreviewFrame) {
        window.cancelAnimationFrame(ghostPreviewFrame);
        ghostPreviewFrame = 0;
    }
    ghostPreviewCoords = null;
    unlockStaticNodesForDrag();
    cleanupDragUi();
}

function destroyGrids() {
    endDragSession();
    for (const grid of grids.values()) {
        try {
            grid.destroy(false);
        } catch {
            // ignore grids ja desmontados
        }
    }
    // Importante: clear() (nao new Map) para manter as mesmas referencias
    // passadas a container-panels / floating-bags.
    grids.clear();
    gridCellSizes.clear();
    itemIndex.clear();
    containerIndex.clear();
    dragSnapshots.clear();
    activeDrag = null;
}

function findGridUnderPointer(clientX, clientY) {
    const stack = typeof document.elementsFromPoint === 'function'
        ? document.elementsFromPoint(clientX, clientY)
        : [];

    for (const el of stack) {
        if (!(el instanceof Element)) continue;
        if (el.closest('.ui-draggable-dragging, .inventory-placement-ghost, .inventory-drag-mirror, .tippy-box')) {
            continue;
        }
        const gridEl = el.closest('.grid-stack.inventory-grid');
        const containerPublicId = gridEl?.dataset?.containerPublicId;
        if (!containerPublicId) continue;
        const grid = grids.get(containerPublicId);
        if (!grid?.el) continue;
        return { containerPublicId, grid };
    }

    // Fallback por retangulo, priorizando janelas flutuantes com maior z-index.
    const hits = [];
    for (const [containerPublicId, grid] of grids) {
        if (!grid?.el) continue;
        if (!isPointerInsideElement(grid.el, clientX, clientY)) continue;
        const win = grid.el.closest('[data-floating-bag-window]');
        hits.push({
            containerPublicId,
            grid,
            floating: Boolean(win),
            z: win ? Number(win.style.zIndex || 0) : 0,
        });
    }
    if (!hits.length) return null;
    hits.sort((a, b) => {
        if (a.floating !== b.floating) return a.floating ? -1 : 1;
        return b.z - a.z;
    });
    return { containerPublicId: hits[0].containerPublicId, grid: hits[0].grid };
}

function getDragEventCoords(event) {
    const native = event?.originalEvent || event;
    if (!native) return null;

    const clientX = native.clientX ?? native.touches?.[0]?.clientX;
    const clientY = native.clientY ?? native.touches?.[0]?.clientY;
    if (clientX == null || clientY == null) return null;

    return { clientX, clientY };
}

function dragPointerCoords(event = null) {
    const fromEvent = event ? getDragEventCoords(event) : null;
    if (fromEvent) return fromEvent;

    if (activeDrag?.pointerX != null && activeDrag?.pointerY != null) {
        return { clientX: activeDrag.pointerX, clientY: activeDrag.pointerY };
    }

    return null;
}

function isPointerInsideElement(element, clientX, clientY) {
    if (!element) return false;
    const rect = element.getBoundingClientRect();
    return clientX >= rect.left && clientX <= rect.right && clientY >= rect.top && clientY <= rect.bottom;
}

function hasPointerMovedFromRotationAnchor(clientX, clientY) {
    if (!activeDrag?.rotationAnchor) return true;

    return Math.abs(Number(clientX) - Number(activeDrag.rotationAnchor.clientX)) > 2
        || Math.abs(Number(clientY) - Number(activeDrag.rotationAnchor.clientY)) > 2;
}

function syncHoverFromPointer(clientX, clientY) {
    if (!activeDrag) return false;

    const hover = findGridUnderPointer(clientX, clientY);
    if (!hover) return false;

    const container = containerIndex.get(hover.containerPublicId);
    const current = itemIndex.get(activeDrag.itemPublicId);
    if (!container || !current || !hover.grid?.el) return false;
    if (!isPointerInsideElement(hover.grid.el, clientX, clientY)) return false;
    if (
        activeDrag.rotationAnchor
        && activeDrag.rotationAnchor.containerPublicId === hover.containerPublicId
        && !hasPointerMovedFromRotationAnchor(clientX, clientY)
    ) {
        return true;
    }

    const size = dimensionsForState(current.item, current.rotated);
    const cell = gridCellFromPointer(
        hover.grid.el,
        clientX,
        clientY,
        size.w,
        size.h,
        Number(container.grid.columns || 0),
        Number(container.grid.rows || 0)
    );

    activeDrag.hoverContainerPublicId = hover.containerPublicId;
    activeDrag.hoverGrid = hover.grid;
    activeDrag.hoverX = cell.x;
    activeDrag.hoverY = cell.y;
    activeDrag.lastX = cell.x;
    activeDrag.lastY = cell.y;

    const evaluation = evaluatePlacement(
        hover.containerPublicId,
        hover.grid,
        activeDrag.itemPublicId,
        cell.x,
        cell.y,
        size.w,
        size.h,
        { clientX, clientY }
    );
    activeDrag.hoverState = evaluation.state;
    return true;
}

function findDraggedWidget() {
    if (!activeDrag?.itemPublicId) return null;

    for (const [containerPublicId, grid] of grids) {
        const node = grid.engine.nodes.find((entry) => entry.id === activeDrag.itemPublicId);
        if (!node?.el) continue;

        return {
            containerPublicId,
            grid,
            node,
            element: node.el,
        };
    }

    return null;
}

function updateContainerSummaryEntry(containerPublicId) {
    const container = containerIndex.get(containerPublicId);
    if (!container) return;

    const items = container.items || [];
    const columns = Number(container.grid?.columns || 1);
    const rows = Number(container.grid?.rows || 1);
    const cells = Math.max(1, columns * rows);
    const occupiedCells = items.reduce((sum, item) => {
        const placement = item.placement || {};
        const rotated = Boolean(placement.rotated);
        const size = dimensionsForState(item, rotated);
        return sum + (size.w * size.h);
    }, 0);

    const previous = inventorySummaryByPublicId.get(containerPublicId) || {};
    inventorySummaryByPublicId.set(containerPublicId, {
        ...previous,
        public_id: containerPublicId,
        item_count: items.length,
        occupancy_ratio: occupiedCells / cells,
    });
    updateContainerOccupancyBadge(containerPublicId);
}

function refreshLinkedContainerSourceItem(containerPublicId) {
    const container = containerIndex.get(containerPublicId);
    const sourceItemPublicId = container?.source_item_public_id;
    if (!sourceItemPublicId) return;
    refreshItemWidget(sourceItemPublicId);
}

function findGridForElement(element) {
    let node = element;
    while (node) {
        if (node.classList?.contains('grid-stack') && node.dataset.containerPublicId) {
            return {
                containerPublicId: node.dataset.containerPublicId,
                grid: grids.get(node.dataset.containerPublicId),
            };
        }
        node = node.parentElement;
    }

    return null;
}

function resolveDropCoordinates(targetContainerPublicId, node, coords = null) {
    const itemPublicId = node?.id || node?.el?.getAttribute?.('gs-id') || activeDrag?.itemPublicId;
    const current = itemIndex.get(itemPublicId);
    if (!current || !activeDrag) return null;

    const rotated = activeDrag.hoverState === 'deposit' && activeDrag.depositRotated != null
        ? Boolean(activeDrag.depositRotated)
        : Boolean(activeDrag.rotated ?? current.rotated);
    const size = dimensionsForState(current.item, rotated);
    const grid = grids.get(targetContainerPublicId);
    const container = containerIndex.get(targetContainerPublicId);
    if (!grid || !container) return null;

    const snapshot = placementSnapshotForContainer(targetContainerPublicId, itemPublicId);
    const columns = Number(container.grid.columns || 0);
    const rows = Number(container.grid.rows || 0);
    const candidates = [];

    const pushCandidate = (x, y) => {
        const cell = {
            x: Math.round(Number(x || 0)),
            y: Math.round(Number(y || 0)),
        };
        if (!candidates.some((entry) => entry.x === cell.x && entry.y === cell.y)) {
            candidates.push(cell);
        }
    };

    // Prioridade: onde o jogador apontou — nao "procurar" outro slot livre (compactacao).
    if (
        activeDrag.hoverContainerPublicId === targetContainerPublicId
        && activeDrag.hoverX != null
        && activeDrag.hoverY != null
    ) {
        pushCandidate(activeDrag.hoverX, activeDrag.hoverY);
    }

    if (coords?.clientX != null && coords?.clientY != null && grid.el) {
        const pointerCell = gridCellFromPointer(
            grid.el,
            coords.clientX,
            coords.clientY,
            size.w,
            size.h,
            columns,
            rows
        );
        pushCandidate(pointerCell.x, pointerCell.y);
    }

    if (activeDrag.pointerX != null && activeDrag.pointerY != null && grid.el) {
        const pointerCell = gridCellFromPointer(
            grid.el,
            activeDrag.pointerX,
            activeDrag.pointerY,
            size.w,
            size.h,
            columns,
            rows
        );
        pushCandidate(pointerCell.x, pointerCell.y);
    }

    for (const cell of candidates) {
        if (isPlacementValidAgainstSnapshot(
            targetContainerPublicId,
            snapshot,
            cell.x,
            cell.y,
            size.w,
            size.h
        )) {
            return {
                x: cell.x,
                y: cell.y,
                w: size.w,
                h: size.h,
                rotated,
            };
        }
    }

    // Celula pretendida mesmo se ocupada — necessario para joia/gema/merge
    // (antes falhava em buildInteraction e nunca chegava no enhance).
    if (candidates.length) {
        const cell = candidates[0];
        return {
            x: cell.x,
            y: cell.y,
            w: size.w,
            h: size.h,
            rotated,
            blocked: true,
        };
    }

    return null;
}

function restoreOtherNodes(grid, exceptItemPublicId) {
    if (!grid || !activeDrag) return;

    let snapshot = placementSnapshotForGrid(grid, exceptItemPublicId);
    if (grid === activeDrag.grid) {
        snapshot = activeDrag.sourceSnapshot;
    } else {
        for (const [containerPublicId, candidate] of grids) {
            if (candidate !== grid) continue;
            snapshot = targetSnapshotForGrid(containerPublicId, grid, exceptItemPublicId);
            break;
        }
    }

    silent = true;
    for (const snap of snapshot) {
        if (snap.id === exceptItemPublicId) continue;

        const node = grid.engine.nodes.find((entry) => entry.id === snap.id);
        if (!node?.el) continue;

        if (node.x !== snap.x || node.y !== snap.y || node.w !== snap.w || node.h !== snap.h) {
            grid.update(node.el, {
                x: snap.x,
                y: snap.y,
                w: snap.w,
                h: snap.h,
            });
        }
    }
    silent = false;
}

function splitQuantityForItem(item) {
    const quantity = Number(item.quantity || 1);
    if (!item.definition?.stackable || quantity <= 1) return 0;

    const half = Math.floor(quantity / 2);
    return half >= 1 && half < quantity ? half : 0;
}

function mergeQuantityForItems(source, target) {
    const sourceQty = Number(source.quantity || 1);
    const targetQty = Number(target.quantity || 1);
    const maxStack = Number(target.definition?.max_stack || sourceQty);
    const available = maxStack - targetQty;

    return Math.max(0, Math.min(sourceQty, available));
}

function canAttemptMerge(source, target) {
    if (!source || !target) return false;
    if (source.public_id === target.public_id) return false;
    if (!source.definition?.stackable || !target.definition?.stackable) return false;
    if (source.definition?.code !== target.definition?.code) return false;
    if ((source.quality_bucket || '') !== (target.quality_bucket || '')) return false;

    return mergeQuantityForItems(source, target) > 0;
}

function findMergeTarget(snapshot, x, y, w, h, sourceItem) {
    const overlaps = findOverlappingPlacements(snapshot, x, y, w, h);
    for (const placement of overlaps) {
        const target = itemIndex.get(placement.id)?.item;
        if (!target || !canAttemptMerge(sourceItem, target)) continue;
        return target;
    }
    return null;
}

function clearPlacementHint(element) {
    element?.classList.remove(
        'inventory-placement-valid',
        'inventory-placement-invalid',
        'inventory-placement-merge',
        'inventory-placement-deposit',
        'inventory-placement-bless',
        'inventory-placement-soul',
        'inventory-placement-chaos',
        'inventory-placement-reroll',
        'inventory-placement-socket',
        'inventory-rotated-preview'
    );
}

function clearRotationHelper(element) {
    element?.classList.remove('inventory-rotation-helper');
    element?.style.removeProperty('--inventory-rotation-w');
    element?.style.removeProperty('--inventory-rotation-h');
}

function applyRotationHelper(itemPublicId, width, height) {
    const selector = `.grid-stack-item.ui-draggable-dragging[gs-id="${String(itemPublicId).replace(/"/g, '\\"')}"]`;
    const elements = document.querySelectorAll(selector);

    elements.forEach((element) => {
        if (!isConnectedElement(element)) return;
        element.style.setProperty('--inventory-rotation-w', String(width));
        element.style.setProperty('--inventory-rotation-h', String(height));
        element.classList.add('inventory-rotation-helper');
    });
}

function enforceDraggedFootprint(dragged = null) {
    if (!activeDrag?.itemPublicId) return;

    const widget = dragged || findDraggedWidget();
    if (!widget?.node?.el || !widget.grid || !isGridNodeLive(widget.node)) return;

    const current = itemIndex.get(activeDrag.itemPublicId);
    if (!current) return;

    const size = dimensionsForState(current.item, Boolean(current.rotated));
    const node = widget.node;
    if (Number(node.w || 1) === size.w && Number(node.h || 1) === size.h) {
        if (node.el.classList?.contains('ui-draggable-dragging')) {
            applyRotationHelper(activeDrag.itemPublicId, size.w, size.h);
        }
        return;
    }

    const originalDragPosition = node._orig;
    silent = true;
    widget.grid.update(node.el, {
        w: size.w,
        h: size.h,
    });
    if (originalDragPosition) {
        node._orig = originalDragPosition;
    }
    silent = false;

    if (node.el.classList?.contains('ui-draggable-dragging')) {
        applyRotationHelper(activeDrag.itemPublicId, size.w, size.h);
        window.requestAnimationFrame(() => {
            if (activeDrag?.itemPublicId) {
                applyRotationHelper(activeDrag.itemPublicId, size.w, size.h);
            }
        });
    }
}

function updatePlacementHint(element) {
    if (!isConnectedElement(element)) return;
    const node = element?.gridstackNode;
    if (!node?.id || !activeDrag) return;

    const located = findGridForElement(element);
    if (!located?.grid) return;

    const current = itemIndex.get(node.id);
    if (!current) return;

    const size = dimensionsForState(current.item, current.rotated);
    const container = containerIndex.get(located.containerPublicId);
    const useRotationAnchor = activeDrag.rotationAnchor
        && activeDrag.rotationAnchor.containerPublicId === located.containerPublicId
        && activeDrag.pointerX != null
        && activeDrag.pointerY != null
        && !hasPointerMovedFromRotationAnchor(activeDrag.pointerX, activeDrag.pointerY);
    const pointerCell = !useRotationAnchor && activeDrag.pointerX != null && activeDrag.pointerY != null && located.grid.el && container
        ? gridCellFromPointer(
            located.grid.el,
            activeDrag.pointerX,
            activeDrag.pointerY,
            size.w,
            size.h,
            Number(container.grid.columns || 0),
            Number(container.grid.rows || 0)
        )
        : null;
    const x = pointerCell ? pointerCell.x : Math.round(Number(activeDrag.hoverX ?? node.x ?? 0));
    const y = pointerCell ? pointerCell.y : Math.round(Number(activeDrag.hoverY ?? node.y ?? 0));
    activeDrag.lastX = x;
    activeDrag.lastY = y;

    const evaluation = evaluatePlacement(
        located.containerPublicId,
        located.grid,
        node.id,
        x,
        y,
        size.w,
        size.h,
        activeDrag.pointerX != null && activeDrag.pointerY != null
            ? { clientX: activeDrag.pointerX, clientY: activeDrag.pointerY }
            : null
    );

    applyPlacementHintClasses(element, evaluation.state, Boolean(current.rotated));

    const pointerHover = activeDrag.pointerX != null && activeDrag.pointerY != null
        ? findGridUnderPointer(activeDrag.pointerX, activeDrag.pointerY)
        : null;
    const pointerOverOtherContainer = pointerHover
        && pointerHover.containerPublicId !== located.containerPublicId;

    if (evaluation.state === 'deposit' && evaluation.slot && evaluation.linkedContainer) {
        activeDrag.hoverState = 'deposit';
        activeDrag.depositContainerPublicId = evaluation.linkedContainer.public_id;
        activeDrag.depositSlot = evaluation.slot;
        activeDrag.depositRotated = evaluation.rotated ?? Boolean(current.rotated);
        prefetchClosedContainerOccupancy(evaluation.linkedContainer.public_id);
        if (!pointerOverOtherContainer) {
            const depositSize = dimensionsForState(current.item, activeDrag.depositRotated);
            clearAllGhostPreviews();
            renderGhostPreview(
                evaluation.linkedContainer.public_id,
                evaluation.slot.grid_x,
                evaluation.slot.grid_y,
                depositSize.w,
                depositSize.h,
                'deposit'
            );
        }
        return;
    }

    activeDrag.hoverState = evaluation.state;
    activeDrag.depositContainerPublicId = null;
    activeDrag.depositSlot = null;
    activeDrag.depositRotated = null;
    activeDrag.hoverX = x;
    activeDrag.hoverY = y;

    if (pointerOverOtherContainer) {
        return;
    }

    clearAllGhostPreviews();
    renderGhostPreview(located.containerPublicId, x, y, size.w, size.h, evaluation.state);
}

function revertItem(itemPublicId) {
    const snapshot = dragSnapshots.get(itemPublicId);
    const current = itemIndex.get(itemPublicId);
    if (!snapshot || !current) return;

    cleanupDragUi();

    const grid = grids.get(snapshot.container_public_id);
    const node = grid?.engine.nodes.find((entry) => entry.id === itemPublicId);
    if (!grid || !node?.el) {
        void resyncContainerPanel(snapshot.container_public_id);
        if (current.container_public_id && current.container_public_id !== snapshot.container_public_id) {
            void resyncContainerPanel(current.container_public_id);
        }
        return;
    }

    silent = true;
    restoreOtherNodes(grid, itemPublicId);
    grid.update(node.el, {
        x: snapshot.grid_x,
        y: snapshot.grid_y,
        w: snapshot.grid_w,
        h: snapshot.grid_h,
    });
    current.rotated = snapshot.rotated;
    current.grid_w = snapshot.grid_w;
    current.grid_h = snapshot.grid_h;
    current.container_public_id = snapshot.container_public_id;
    if (current.item) {
        current.item.placement = {
            ...(current.item.placement || {}),
            grid_x: snapshot.grid_x,
            grid_y: snapshot.grid_y,
            grid_w: snapshot.grid_w,
            grid_h: snapshot.grid_h,
            rotated: snapshot.rotated,
        };
    }
    clearPlacementHint(node.el);
    silent = false;
}

function rotateDraggedItem() {
    if (!activeDrag || actionInFlight || loading) return;

    const dragged = findDraggedWidget();
    if (!dragged?.node?.el || !dragged.grid) return;

    const movingItemPublicId = dragged.node.id || activeDrag.itemPublicId;
    activeDrag.itemPublicId = movingItemPublicId;

    const current = itemIndex.get(movingItemPublicId);
    if (!current) return;

    const base = baseDimensions(current.item);
    if (base.w === base.h) {
        toast('Este item nao pode ser rotacionado.', 'info', 2200);
        return;
    }

    const nextRotated = !Boolean(current.rotated);
    const nextSize = dimensionsForState(current.item, nextRotated);
    const container = containerIndex.get(dragged.containerPublicId);
    const columns = Number(container?.grid?.columns || 0);
    const rows = Number(container?.grid?.rows || 0);
    const currentX = Math.round(Number(dragged.node.x ?? activeDrag.lastX ?? 0));
    const currentY = Math.round(Number(dragged.node.y ?? activeDrag.lastY ?? 0));
    const currentSize = dimensionsForState(current.item, Boolean(current.rotated));
    const candidates = [];

    const pushCandidate = (x, y) => {
        if (!container || columns <= 0 || rows <= 0) return;
        const cell = clampCell(x, y, nextSize.w, nextSize.h, columns, rows);
        if (!candidates.some((candidate) => candidate.x === cell.x && candidate.y === cell.y)) {
            candidates.push(cell);
        }
    };

    if (container && activeDrag.pointerX != null && activeDrag.pointerY != null && dragged.grid?.el) {
        const pointerCell = gridCellFromPointer(
            dragged.grid.el,
            activeDrag.pointerX,
            activeDrag.pointerY,
            nextSize.w,
            nextSize.h,
            columns,
            rows
        );
        pushCandidate(pointerCell.x, pointerCell.y);
    }

    pushCandidate(
        currentX + Math.round((currentSize.w - nextSize.w) / 2),
        currentY + Math.round((currentSize.h - nextSize.h) / 2)
    );
    pushCandidate(currentX, currentY);
    pushCandidate(currentX - (nextSize.w - currentSize.w), currentY);
    pushCandidate(currentX, currentY - (nextSize.h - currentSize.h));
    pushCandidate(activeDrag.hoverX ?? currentX, activeDrag.hoverY ?? currentY);

    for (let radius = 1; radius <= 2; radius += 1) {
        pushCandidate(currentX - radius, currentY);
        pushCandidate(currentX + radius, currentY);
        pushCandidate(currentX, currentY - radius);
        pushCandidate(currentX, currentY + radius);
        pushCandidate(currentX - radius, currentY - radius);
        pushCandidate(currentX + radius, currentY - radius);
        pushCandidate(currentX - radius, currentY + radius);
        pushCandidate(currentX + radius, currentY + radius);
    }

    if (container && columns > 0 && rows > 0) {
        const sourceCellSize = cellSizeForContainer(container.public_id);
        const originX = activeDrag.pointerX != null && dragged.grid?.el
            ? Math.floor((activeDrag.pointerX - dragged.grid.el.getBoundingClientRect().left) / sourceCellSize)
            : currentX + Math.floor(currentSize.w / 2);
        const originY = activeDrag.pointerY != null && dragged.grid?.el
            ? Math.floor((activeDrag.pointerY - dragged.grid.el.getBoundingClientRect().top) / sourceCellSize)
            : currentY + Math.floor(currentSize.h / 2);
        const allCells = [];

        for (let y = 0; y <= rows - nextSize.h; y += 1) {
            for (let x = 0; x <= columns - nextSize.w; x += 1) {
                allCells.push({
                    x,
                    y,
                    distance: Math.abs(x - originX) + Math.abs(y - originY),
                });
            }
        }

        allCells
            .sort((a, b) => a.distance - b.distance || a.y - b.y || a.x - b.x)
            .forEach((cell) => pushCandidate(cell.x, cell.y));
    }

    const snapshot = placementSnapshotForContainer(dragged.containerPublicId, movingItemPublicId);
    const nextCell = candidates.find((candidate) => isPlacementValidAgainstSnapshot(
        dragged.containerPublicId,
        snapshot,
        candidate.x,
        candidate.y,
        nextSize.w,
        nextSize.h
    ));

    if (!nextCell) {
        applyPlacementHintClasses(dragged.element, 'invalid', Boolean(current.rotated));
        toast('Sem espaco livre para rotacionar aqui.', 'warning', 2400);
        return;
    }

    silent = true;
    restoreOtherNodes(dragged.grid, movingItemPublicId);
    const originalDragPosition = dragged.node._orig;
    dragged.grid.update(dragged.node.el, {
        x: nextCell.x,
        y: nextCell.y,
        w: nextSize.w,
        h: nextSize.h,
    });
    if (originalDragPosition) {
        dragged.node._orig = originalDragPosition;
    }
    applyRotationHelper(movingItemPublicId, nextSize.w, nextSize.h);
    window.requestAnimationFrame(() => applyRotationHelper(movingItemPublicId, nextSize.w, nextSize.h));
    silent = false;

    current.rotated = nextRotated;
    current.grid_w = nextSize.w;
    current.grid_h = nextSize.h;
    if (current.item) {
        current.item.placement = {
            ...(current.item.placement || {}),
            grid_x: nextCell.x,
            grid_y: nextCell.y,
            grid_w: nextSize.w,
            grid_h: nextSize.h,
            rotated: nextRotated,
        };
    }
    activeDrag.rotated = nextRotated;
    activeDrag.lastX = nextCell.x;
    activeDrag.lastY = nextCell.y;
    activeDrag.hoverX = nextCell.x;
    activeDrag.hoverY = nextCell.y;
    activeDrag.hoverContainerPublicId = dragged.containerPublicId;
    activeDrag.hoverGrid = dragged.grid;
    activeDrag.rotationAnchor = activeDrag.pointerX != null && activeDrag.pointerY != null
        ? {
            clientX: activeDrag.pointerX,
            clientY: activeDrag.pointerY,
            containerPublicId: dragged.containerPublicId,
        }
        : null;

    if (activeDrag.pointerX != null && activeDrag.pointerY != null) {
        syncHoverFromPointer(activeDrag.pointerX, activeDrag.pointerY);
        updateAllGhostPreviews(activeDrag.pointerX, activeDrag.pointerY);
    }

    updatePlacementHint(dragged.element);
}

function hasPlacementChanged(snapshot, interaction) {
    return snapshot.container_public_id !== interaction.target_container_public_id
        || snapshot.grid_x !== interaction.grid_x
        || snapshot.grid_y !== interaction.grid_y
        || snapshot.rotated !== interaction.rotated;
}

function isInventoryInteractionBusy() {
    return Boolean(activeDrag || paperdollDragState || loading);
}

configureInventorySync({
    isBusy: () => isInventoryInteractionBusy(),
    resyncContainerPanel,
});

configureInventoryOverlays({
    apiFetch,
    handleError: (error, fallback) => handleError(error, fallback),
    isBusy: () => Boolean(loading || activeDrag || paperdollDragState),
    itemTooltip: (item) => itemTooltip(item),
    socketNestedTooltipHtml: (item, socket) => socketNestedTooltipHtml(item, socket),
    escapeHtml,
    itemLabel: (item) => itemLabel(item),
    rarityLabel: (item) => rarityLabel(item),
    rarityKey: (item) => rarityKey(item),
    isEquippableItem: (item) => isEquippableItem(item),
    isItemCurrentlyEquipped: (item) => isItemCurrentlyEquipped(item),
    isStorageContainerItem: (item) => isStorageContainerItem(item),
    renameStorageContainerItem: (item) => renameStorageContainerItem(item),
    openComparePanel: (item) => openComparePanel(item),
    executeItemAction: (item, action) => executeItemAction(item, action),
});

function clearActiveDrag() {
    if (activeDrag) {
        unlockStaticNodesForDrag();
        cleanupDragUi();
    }
    activeDrag = null;
    pumpIdleUiSync();

    if (inventoryReloadQueued && !actionInFlight && !loading && !inventoryMoveQueue.isBusy()) {
        inventoryReloadQueued = false;
        window.setTimeout(() => loadInventory(), 0);
    }
}

function runAfterGridDragSettles(callback) {
    window.requestAnimationFrame(() => {
        window.requestAnimationFrame(() => {
            callback().catch((error) => {
                console.error('[inventory-post-drop]', error);
                handleError(error, 'Nao foi possivel concluir a acao do item.');
            });
        });
    });
}

function isConnectedElement(element) {
    return element instanceof Element && element.isConnected;
}

function isGridNodeLive(node) {
    return Boolean(node?.el && node.el.isConnected);
}

function upsertContainerCache(container) {
    if (!container?.public_id) return;

    const index = allContainersCache.findIndex((entry) => entry.public_id === container.public_id);
    if (index >= 0) {
        allContainersCache[index] = container;
        return;
    }

    allContainersCache.push(container);
}

function syncContainerItemPlacement(sourcePublicId, targetPublicId, itemPublicId, item) {
    const removeFromContainer = (containerPublicId) => {
        const container = containerIndex.get(containerPublicId);
        if (!container?.items) return;
        container.items = container.items.filter((entry) => entry.public_id !== itemPublicId);
    };

    const addToContainer = (containerPublicId) => {
        const container = containerIndex.get(containerPublicId);
        if (!container) return;
        if (!Array.isArray(container.items)) container.items = [];
        const existingIndex = container.items.findIndex((entry) => entry.public_id === itemPublicId);
        if (existingIndex >= 0) container.items[existingIndex] = item;
        else container.items.push(item);
    };

    if (sourcePublicId !== targetPublicId) {
        removeFromContainer(sourcePublicId);
    }
    addToContainer(targetPublicId);

    for (const cached of allContainersCache) {
        if (cached.public_id === sourcePublicId && sourcePublicId !== targetPublicId) {
            cached.items = (cached.items || []).filter((entry) => entry.public_id !== itemPublicId);
        }
        if (cached.public_id === targetPublicId) {
            if (!Array.isArray(cached.items)) cached.items = [];
            const existingIndex = cached.items.findIndex((entry) => entry.public_id === itemPublicId);
            if (existingIndex >= 0) cached.items[existingIndex] = item;
            else cached.items.push(item);
        }
    }
}

function updateContainerOccupancyBadge(containerPublicId) {
    const section = containerRoot?.querySelector(`[data-container-public-id="${containerPublicId}"]`);
    const container = containerIndex.get(containerPublicId);
    if (!section || !container) return;

    const occupancy = section.querySelector('.inventory-container-occupancy');
    if (occupancy) {
        occupancy.textContent = occupancyLabel(container, inventorySummaryByPublicId.get(containerPublicId));
    }
}

function bindItemWidget(container, item, widget) {
    if (!widget) return;

    bindItemShortcuts(container, item, widget);

    const content = widget.querySelector('.grid-stack-item-content');
    if (!content) return;
    attachItemTooltip(content, item);
}

function patchItemPlacement(interaction, moveResult = {}) {
    const itemPublicId = interaction.item_public_id;
    const entry = itemIndex.get(itemPublicId);
    if (!entry) return false;

    const sourcePublicId = dragSnapshots.get(itemPublicId)?.container_public_id
        || interaction.source_container_public_id
        || entry.container_public_id;
    const targetPublicId = interaction.target_container_public_id || sourcePublicId;
    const sameContainer = sourcePublicId === targetPublicId;
    const rotated = Boolean(moveResult.rotated ?? interaction.rotated);
    const gridW = Number(moveResult.grid_w ?? interaction.grid_w ?? entry.grid_w);
    const gridH = Number(moveResult.grid_h ?? interaction.grid_h ?? entry.grid_h);
    const gridX = Number(moveResult.grid_x ?? interaction.grid_x);
    const gridY = Number(moveResult.grid_y ?? interaction.grid_y);
    const placementVersion = Number(moveResult.placement_version ?? entry.placement_version);

    entry.container_public_id = targetPublicId;
    entry.placement_version = placementVersion;
    entry.rotated = rotated;
    entry.grid_w = gridW;
    entry.grid_h = gridH;

    if (entry.item) {
        entry.item.placement = {
            ...(entry.item.placement || {}),
            grid_x: gridX,
            grid_y: gridY,
            grid_w: gridW,
            grid_h: gridH,
            rotated,
            placement_version: placementVersion,
        };
        syncContainerItemPlacement(sourcePublicId, targetPublicId, itemPublicId, entry.item);
    }

    const sourceGrid = grids.get(sourcePublicId);
    const targetGrid = grids.get(targetPublicId);
    if (!targetGrid) {
        // Baú/container fechado: estado lógico ja atualizado no itemIndex.
        if (sourceGrid && !sameContainer) {
            purgeItemWidgetFromAllGrids(itemPublicId);
            scrubOrphanGridWidgets(sourcePublicId);
            dedupeItemWidgets(itemPublicId);
        }
        // Mantem cache do bau fechado sincronizado para o proximo deposit.
        const cached = containerDetailCache.get(targetPublicId);
        if (cached?.container && entry.item) {
            const items = Array.isArray(cached.container.items) ? [...cached.container.items] : [];
            const idx = items.findIndex((candidate) => candidate.public_id === itemPublicId);
            if (idx >= 0) items[idx] = entry.item;
            else items.push(entry.item);
            containerDetailCache.set(targetPublicId, { ...cached.container, items }, cached.summaryEntry);
        }
        updateContainerSummaryEntry(sourcePublicId);
        if (targetPublicId !== sourcePublicId) {
            updateContainerSummaryEntry(targetPublicId);
            refreshLinkedContainerSourceItem(sourcePublicId);
            refreshLinkedContainerSourceItem(targetPublicId);
        } else {
            updateContainerOccupancyBadge(sourcePublicId);
        }
        dragSnapshots.delete(itemPublicId);
        applyInventoryFilters();
        return true;
    }

    silent = true;
    try {
        if (sameContainer) {
            const node = targetGrid.engine.nodes.find((candidate) => candidate.id === itemPublicId);
            if (isGridNodeLive(node)) {
                targetGrid.update(node.el, {
                    x: gridX,
                    y: gridY,
                    w: gridW,
                    h: gridH,
                });
            } else {
                // Widget sumiu do main (comum apos drag falho): recria.
                purgeItemWidgetFromAllGrids(itemPublicId);
                const container = containerIndex.get(targetPublicId);
                targetGrid.addWidget({
                    id: itemPublicId,
                    x: gridX,
                    y: gridY,
                    w: gridW,
                    h: gridH,
                    noResize: true,
                    noMove: Boolean(entry.item?.placement?.locked),
                    locked: Boolean(entry.item?.placement?.locked),
                    content: renderItem(entry.item),
                });
                const widget = targetGrid.engine.nodes.find((candidate) => candidate.id === itemPublicId)?.el;
                if (container && entry.item) {
                    bindItemWidget(container, entry.item, widget);
                }
            }
        } else {
            purgeItemWidgetFromAllGrids(itemPublicId, targetPublicId);

            const container = containerIndex.get(targetPublicId);
            targetGrid.addWidget({
                id: itemPublicId,
                x: gridX,
                y: gridY,
                w: gridW,
                h: gridH,
                noResize: true,
                noMove: Boolean(entry.item?.placement?.locked),
                locked: Boolean(entry.item?.placement?.locked),
                content: renderItem(entry.item),
            });
            const widget = targetGrid.engine.nodes.find((candidate) => candidate.id === itemPublicId)?.el;
            if (container && entry.item) {
                bindItemWidget(container, entry.item, widget);
            }

            reconcileContainerGrid(sourcePublicId);
            reconcileContainerGrid(targetPublicId);
            scrubOrphanGridWidgets(sourcePublicId);
            scrubOrphanGridWidgets(targetPublicId);
        }
    } finally {
        silent = false;
    }

    updateContainerSummaryEntry(sourcePublicId);
    if (targetPublicId !== sourcePublicId) {
        updateContainerSummaryEntry(targetPublicId);
        refreshLinkedContainerSourceItem(sourcePublicId);
        refreshLinkedContainerSourceItem(targetPublicId);
    } else {
        updateContainerOccupancyBadge(sourcePublicId);
    }

    dragSnapshots.delete(itemPublicId);
    applyInventoryFilters();
    return true;
}

function lockStaticNodesForDrag() {
    if (!activeDrag) return;

    activeDrag.lockedNodes = [];

    for (const [containerPublicId, grid] of grids) {
        for (const node of grid.engine.nodes) {
            if (!node?.id || node.id === activeDrag.itemPublicId) continue;

            activeDrag.lockedNodes.push({
                containerPublicId,
                id: node.id,
                locked: Boolean(node.locked),
            });
            node.locked = true;
            node.el?.setAttribute('gs-locked', 'true');
        }
    }
}

function unlockStaticNodesForDrag() {
    const lockedNodes = activeDrag?.lockedNodes || [];

    for (const locked of lockedNodes) {
        const grid = grids.get(locked.containerPublicId);
        const node = grid?.engine.nodes.find((entry) => entry.id === locked.id);
        if (!node) continue;

        node.locked = locked.locked;
        if (locked.locked) {
            node.el?.setAttribute('gs-locked', 'true');
        } else {
            node.el?.removeAttribute('gs-locked');
        }
    }

    if (activeDrag) {
        activeDrag.lockedNodes = [];
    }
}

function pointerCellForDrag(containerPublicId, coords = null) {
    const current = itemIndex.get(activeDrag?.itemPublicId);
    const grid = grids.get(containerPublicId);
    const container = containerIndex.get(containerPublicId);
    const resolved = coords || dragPointerCoords();
    if (!current || !grid?.el || !container || !resolved) return null;
    if (!isPointerInsideElement(grid.el, resolved.clientX, resolved.clientY)) return null;

    const size = dimensionsForState(current.item, current.rotated);
    return gridCellFromPointer(
        grid.el,
        resolved.clientX,
        resolved.clientY,
        size.w,
        size.h,
        Number(container.grid.columns || 0),
        Number(container.grid.rows || 0)
    );
}

function resolveDropCell(containerPublicId, node, coords = null) {
    if (
        activeDrag?.hoverContainerPublicId === containerPublicId
        && activeDrag.hoverX != null
        && activeDrag.hoverY != null
    ) {
        return { x: activeDrag.hoverX, y: activeDrag.hoverY };
    }

    const pointerCell = pointerCellForDrag(containerPublicId, coords);
    if (pointerCell) return pointerCell;

    return {
        x: Math.round(Number(node?.x ?? activeDrag?.lastX ?? 0)),
        y: Math.round(Number(node?.y ?? activeDrag?.lastY ?? 0)),
    };
}

function syncRotatedStateFromNode(current, node) {
    if (!current || !node) return;

    const base = baseDimensions(current.item);
    if (base.w === base.h) {
        current.rotated = false;
        current.grid_w = Number(node.w || base.w);
        current.grid_h = Number(node.h || base.h);
        return;
    }

    current.rotated = Number(node.w) === base.h && Number(node.h) === base.w;
    current.grid_w = Number(node.w || base.w);
    current.grid_h = Number(node.h || base.h);
}

function intendedCellForDrop(containerPublicId, node, coords = null) {
    const current = itemIndex.get(activeDrag?.itemPublicId);
    const grid = grids.get(containerPublicId);
    const container = containerIndex.get(containerPublicId);
    if (!current || !grid?.el || !container) {
        return {
            x: Math.round(Number(node.x ?? 0)),
            y: Math.round(Number(node.y ?? 0)),
        };
    }

    const size = dimensionsForState(current.item, current.rotated);
    const nodeX = Math.round(Number(node.x ?? activeDrag?.lastX ?? 0));
    const nodeY = Math.round(Number(node.y ?? activeDrag?.lastY ?? 0));

    if (!coords) {
        return { x: nodeX, y: nodeY };
    }

    const pointerCell = gridCellFromPointer(
        grid.el,
        coords.clientX,
        coords.clientY,
        size.w,
        size.h,
        Number(container.grid.columns || 0),
        Number(container.grid.rows || 0)
    );
    const snapshot = targetSnapshotForGrid(containerPublicId, grid, node.id);
    if (isPlacementValidAgainstSnapshot(
        containerPublicId,
        snapshot,
        pointerCell.x,
        pointerCell.y,
        size.w,
        size.h
    )) {
        return pointerCell;
    }

    return { x: nodeX, y: nodeY };
}

function buildInteraction(targetContainerPublicId, node, coords = null) {
    const itemPublicId = node.id || node.el?.getAttribute('gs-id');
    const current = itemIndex.get(itemPublicId);
    if (!itemPublicId || !current || !activeDrag) return null;

    const drop = resolveDropCoordinates(targetContainerPublicId, node, coords);
    if (!drop) return null;

    activeDrag.hoverX = drop.x;
    activeDrag.hoverY = drop.y;
    activeDrag.hoverContainerPublicId = targetContainerPublicId;

    return {
        item_public_id: itemPublicId,
        source_container_public_id: activeDrag.sourceContainerPublicId,
        target_container_public_id: targetContainerPublicId,
        grid_x: drop.x,
        grid_y: drop.y,
        grid_w: drop.w,
        grid_h: drop.h,
        rotated: drop.rotated,
    };
}

function resolveDepositMove(interaction, sourceItem) {
    if (activeDrag?.hoverState === 'deposit' && activeDrag.depositSlot && activeDrag.depositContainerPublicId) {
        return {
            ...interaction,
            target_container_public_id: activeDrag.depositContainerPublicId,
            grid_x: activeDrag.depositSlot.grid_x,
            grid_y: activeDrag.depositSlot.grid_y,
            rotated: activeDrag.depositRotated != null
                ? Boolean(activeDrag.depositRotated)
                : interaction.rotated,
        };
    }

    // Sem hover de deposit explicito, nao forca deposit por overlap residual.
    if (activeDrag?.hoverState && activeDrag.hoverState !== 'deposit') {
        return null;
    }

    const sourceGrid = grids.get(interaction.target_container_public_id);
    if (!sourceGrid) return null;

    const pointerCoords = activeDrag?.pointerX != null && activeDrag?.pointerY != null
        ? { clientX: activeDrag.pointerX, clientY: activeDrag.pointerY }
        : null;

    const depositTarget = resolveDepositTarget(
        interaction.target_container_public_id,
        sourceGrid,
        interaction.item_public_id,
        interaction.grid_x,
        interaction.grid_y,
        interaction.grid_w,
        interaction.grid_h,
        pointerCoords
    );
    if (!depositTarget) return null;

    const finalSize = dimensionsForState(sourceItem, depositTarget.rotated);

    return {
        ...interaction,
        target_container_public_id: depositTarget.linkedContainer.public_id,
        grid_x: depositTarget.slot.grid_x,
        grid_y: depositTarget.slot.grid_y,
        grid_w: finalSize.w,
        grid_h: finalSize.h,
        rotated: Boolean(depositTarget.rotated),
    };
}

async function handleDrop(targetContainerPublicId, node, coords = null) {
    if (silent || loading || actionInFlight || !node?.id || !activeDrag || activeDrag.handled) return;

    const grid = grids.get(targetContainerPublicId);
    const itemPublicId = node.id || node.el?.getAttribute('gs-id');
    const source = itemIndex.get(itemPublicId);
    const snapshot = dragSnapshots.get(itemPublicId);
    if (!source || !snapshot || !grid) {
        if (itemPublicId) revertItem(itemPublicId);
        clearActiveDrag();
        return;
    }

    const pointerCoords = coords?.clientX != null
        ? coords
        : (activeDrag.pointerX != null && activeDrag.pointerY != null
            ? { clientX: activeDrag.pointerX, clientY: activeDrag.pointerY }
            : null);

    // Joia/gema/merge: resolve pelo ponteiro ANTES de exigir celula livre.
    if (pointerCoords) {
        const underPointer = findItemUnderPointer(pointerCoords.clientX, pointerCoords.clientY, itemPublicId);
        if (underPointer) {
            if (isJewelItem(source.item) && canAttemptEnhance(source.item, underPointer)) {
                const jewelType = resolveJewelType(source.item);
                if (jewelType) {
                    activeDrag.handled = true;
                    revertItem(itemPublicId);
                    clearActiveDrag();
                    runAfterGridDragSettles(() => attemptEnhance(source.item, underPointer, jewelType));
                    return;
                }
            }
            if (isGemItem(source.item) && canAttemptSocket(source.item, underPointer)) {
                activeDrag.handled = true;
                revertItem(itemPublicId);
                clearActiveDrag();
                runAfterGridDragSettles(() => attemptSocket(source.item, underPointer));
                return;
            }
            if (canAttemptMerge(source.item, underPointer)) {
                activeDrag.handled = true;
                clearActiveDrag();
                runAfterGridDragSettles(() => attemptMerge(source.item, underPointer));
                return;
            }
        }
    }

    const interaction = buildInteraction(targetContainerPublicId, node, coords);
    if (!interaction) {
        revertItem(itemPublicId);
        toast('Posicao invalida. O item voltou para a posicao original.', 'error', 3200);
        playInventoryFeedback('invalid');
        clearActiveDrag();
        scrubOrphanGridWidgets(targetContainerPublicId);
        dedupeItemWidgets(itemPublicId);
        return;
    }

    if (inventoryMoveQueue.isItemPending(interaction.item_public_id)) {
        revertItem(interaction.item_public_id);
        toast('Este item ainda esta sincronizando. Aguarde um instante.', 'info', 2600);
        clearActiveDrag();
        return;
    }

    if (!inventoryMoveQueue.canAccept(interaction.item_public_id)) {
        revertItem(interaction.item_public_id);
        toast('Muitos movimentos ao mesmo tempo. Aguarde a fila.', 'info', 2800);
        clearActiveDrag();
        return;
    }

    restoreOtherNodes(grid, interaction.item_public_id);
    clearPlacementHint(node.el);

    const sourceItem = source.item;
    const dropEvaluation = evaluatePlacement(
        targetContainerPublicId,
        grid,
        interaction.item_public_id,
        interaction.grid_x,
        interaction.grid_y,
        interaction.grid_w,
        interaction.grid_h,
        pointerCoords
    );

    if (dropEvaluation.state === 'merge' && dropEvaluation.overlapItem) {
        activeDrag.handled = true;
        clearActiveDrag();
        runAfterGridDragSettles(() => attemptMerge(sourceItem, dropEvaluation.overlapItem));
        return;
    }

    if ((dropEvaluation.state === 'bless' || dropEvaluation.state === 'soul' || dropEvaluation.state === 'chaos' || dropEvaluation.state === 'reroll') && dropEvaluation.overlapItem) {
        activeDrag.handled = true;
        revertItem(interaction.item_public_id);
        clearActiveDrag();
        runAfterGridDragSettles(() => attemptEnhance(sourceItem, dropEvaluation.overlapItem, dropEvaluation.state));
        return;
    }

    if (dropEvaluation.state === 'socket' && dropEvaluation.overlapItem) {
        activeDrag.handled = true;
        revertItem(interaction.item_public_id);
        clearActiveDrag();
        runAfterGridDragSettles(() => attemptSocket(sourceItem, dropEvaluation.overlapItem));
        return;
    }

    // Deposit so quando a avaliacao/hover indica deposit (nao rouba merge/joia).
    if (dropEvaluation.state === 'deposit' || activeDrag.hoverState === 'deposit') {
        let depositMove = resolveDepositMove(interaction, sourceItem);
        if (depositMove) {
            const depositTargetId = depositMove.target_container_public_id;
            // Bau fechado: usa cache fresco quando possivel (sem esperar rede).
            if (depositTargetId && !grids.has(depositTargetId)) {
                let ready = ensureClosedContainerReady(depositTargetId);
                let slotResult = ready
                    ? findDepositSlot(depositTargetId, sourceItem, Boolean(depositMove.rotated))
                    : null;

                if (!slotResult) {
                    ready = await refreshClosedContainerOccupancy(depositTargetId);
                    if (!ready) {
                        revertItem(interaction.item_public_id);
                        toast('Nao foi possivel ler o bau. Tente abrir e tentar de novo.', 'error', 3200);
                        playInventoryFeedback('invalid');
                        clearActiveDrag();
                        return;
                    }
                    slotResult = findDepositSlot(
                        depositTargetId,
                        sourceItem,
                        Boolean(depositMove.rotated)
                    );
                }

                if (!slotResult) {
                    revertItem(interaction.item_public_id);
                    toast('Bau cheio ou sem espaco para este item.', 'error', 3200);
                    playInventoryFeedback('invalid');
                    clearActiveDrag();
                    return;
                }
                const finalSize = dimensionsForState(sourceItem, slotResult.rotated);
                depositMove = {
                    ...depositMove,
                    grid_x: slotResult.slot.grid_x,
                    grid_y: slotResult.slot.grid_y,
                    grid_w: finalSize.w,
                    grid_h: finalSize.h,
                    rotated: Boolean(slotResult.rotated),
                };
            }

            if (hasPlacementChanged(snapshot, depositMove)) {
                activeDrag.handled = true;
                clearActiveDrag();
                if (!inventoryMoveQueue.reserve(depositMove.item_public_id)) {
                    revertItem(depositMove.item_public_id);
                    toast('Muitos movimentos ao mesmo tempo. Aguarde a fila.', 'info', 2800);
                    return;
                }
                runAfterGridDragSettles(async () => {
                    const saved = await attemptMove(source, depositMove);
                    if (saved) {
                        toast('Item guardado no bau.', 'success', 2600);
                        playInventoryFeedback('valid');
                        highlightContainer(depositMove.target_container_public_id);
                        scrubOrphanGridWidgets(snapshot.container_public_id);
                    } else {
                        playInventoryFeedback('invalid');
                        scrubOrphanGridWidgets(snapshot.container_public_id);
                    }
                });
                return;
            }
        }
    }

    const targetSnapshot = targetSnapshotForGrid(targetContainerPublicId, grid, interaction.item_public_id);

    if (dropEvaluation.state === 'invalid') {
        revertItem(interaction.item_public_id);
        toast('Posicao invalida. O item voltou para a posicao original.', 'error', 3200);
        playInventoryFeedback('invalid');
        clearActiveDrag();
        scrubOrphanGridWidgets(targetContainerPublicId);
        return;
    }

    if (!hasPlacementChanged(snapshot, interaction)) {
        clearPlacementHint(node.el);
        clearActiveDrag();
        return;
    }

    const overlapPlacement = findOverlapInSnapshot(
        targetSnapshot,
        interaction.grid_x,
        interaction.grid_y,
        interaction.grid_w,
        interaction.grid_h
    );
    const overlapTarget = overlapPlacement
        ? itemIndex.get(overlapPlacement.id)?.item || { public_id: overlapPlacement.id }
        : null;

    if (overlapTarget?.definition?.is_container) {
        revertItem(interaction.item_public_id);
        toast('Sem espaco na mochila ou item nao pode ser guardado ali.', 'error', 3400);
        playInventoryFeedback('invalid');
        clearActiveDrag();
        return;
    }

    const targetContainer = containerIndex.get(interaction.target_container_public_id);
    if (targetContainer && !canContainerAcceptItem(targetContainer, sourceItem)) {
        revertItem(interaction.item_public_id);
        toast(acceptanceRejectionMessage(targetContainer), 'error', 3800);
        clearActiveDrag();
        return;
    }

    if (!isPlacementValidAgainstSnapshot(
        interaction.target_container_public_id,
        targetSnapshot,
        interaction.grid_x,
        interaction.grid_y,
        interaction.grid_w,
        interaction.grid_h
    )) {
        revertItem(interaction.item_public_id);
        toast('Posicao invalida. O item voltou para a posicao original.', 'error', 3200);
        clearActiveDrag();
        scrubOrphanGridWidgets(targetContainerPublicId);
        return;
    }

    activeDrag.handled = true;
    clearActiveDrag();
    if (!inventoryMoveQueue.reserve(interaction.item_public_id)) {
        revertItem(interaction.item_public_id);
        toast('Muitos movimentos ao mesmo tempo. Aguarde a fila.', 'info', 2800);
        return;
    }
    runAfterGridDragSettles(() => attemptMove(source, interaction));
}

async function finalizeDrag(event) {
    if (!activeDrag || activeDrag.handled) {
        clearActiveDrag();
        return;
    }

    const dragged = findDraggedWidget();
    if (!dragged?.node?.id) {
        clearActiveDrag();
        return;
    }

    try {
        const node = dragged.node;
        const coords = dragPointerCoords(event);

        if (await tryAssignInventoryDragToCraftSlot(event)) {
            return;
        }

        if (coords?.clientX != null && coords?.clientY != null) {
            const hotbarSlot = findHotbarSlotUnderPointer(coords.clientX, coords.clientY);
            if (hotbarSlot) {
                const current = itemIndex.get(activeDrag.itemPublicId);
                if (current?.item && isHotbarCompatibleItem(current.item)) {
                    activeDrag.handled = true;
                    revertItem(activeDrag.itemPublicId);
                    clearActiveDrag();
                    await equipItemToHotbarSlot(current.item, hotbarSlot);
                    return;
                }
                toast('Este item nao pode ir para a hotbar.', 'info', 2600);
                playInventoryFeedback('invalid');
                revertItem(activeDrag.itemPublicId);
                clearActiveDrag();
                return;
            }

            const equipmentSlotEl = findEquipmentSlotUnderPointer(coords.clientX, coords.clientY);
            if (equipmentSlotEl) {
                const current = itemIndex.get(activeDrag.itemPublicId);
                const targetSlot = current?.item
                    ? resolvePaperdollEquipTargetSlot(equipmentSlotEl, current.item)
                    : null;
                if (targetSlot && current?.item) {
                    activeDrag.handled = true;
                    const itemToEquip = current.item;
                    const sourceContainerPublicId = activeDrag.sourceContainerPublicId || current.container_public_id || null;
                    // Esconde na hora, mas so remove o widget depois do GridStack terminar o mouseup.
                    hideItemWidgetPendingEquip(itemToEquip.public_id);
                    applyOptimisticEquipToSlot(itemToEquip, targetSlot);
                    clearDragMirror();
                    toast('Item equipado.', 'success', 2200);
                    playInventoryFeedback('valid');
                    clearActiveDrag();
                    runAfterGridDragSettles(() => equipItemToEquipmentSlot(itemToEquip, targetSlot, {
                        alreadyOptimistic: true,
                        sourceContainerPublicId,
                    }));
                    return;
                }
                toast('Este item nao encaixa neste slot.', 'info', 2600);
                playInventoryFeedback('invalid');
                revertItem(activeDrag.itemPublicId);
                clearActiveDrag();
                return;
            }
        }

        let targetContainerPublicId = activeDrag.sourceContainerPublicId;

        if (activeDrag.hoverState === 'deposit' && activeDrag.depositContainerPublicId) {
            await handleDrop(activeDrag.sourceContainerPublicId, node, coords);
            return;
        }

        if (coords?.clientX != null && coords?.clientY != null) {
            const hover = findGridUnderPointer(coords.clientX, coords.clientY);
            if (hover && hover.containerPublicId !== activeDrag.sourceContainerPublicId) {
                targetContainerPublicId = hover.containerPublicId;
            }
        }

        await handleDrop(targetContainerPublicId, node, coords);
    } finally {
        clearHotbarDropHighlights();
        if (!actionInFlight) {
            clearActiveDrag();
        }
    }
}

function initializeGrid(container, gridNode) {
    const cellSize = resolveCellSize(gridNode);
    gridCellSizes.set(container.public_id, cellSize);
    gridNode.style.setProperty('--inventory-cell', `${cellSize}px`);

    const grid = GridStack.init({
        column: Number(container.grid.columns),
        minRow: Number(container.grid.rows),
        maxRow: Number(container.grid.rows),
        cellHeight: cellSize,
        margin: 0,
        // float on: permite deixar gaps / colocar o item onde o jogador soltar.
        // float off compactava o grid e gerava overlap fantasma vs servidor.
        float: true,
        animate: false,
        acceptWidgets: false,
        disableOneColumnMode: true,
        draggable: {
            handle: '.grid-stack-item-content',
            scroll: false,
        },
        disableResize: true,
        removable: false,
        staticGrid: false,
    }, gridNode);

    if (grid.engine) {
        grid.engine.float = true;
    }

    grid.on('dragstart', (_event, element) => {
        const node = element?.gridstackNode;
        if (!node?.id) return;
        if (loading || inventoryMoveQueue.isItemPending(node.id)) return;
        if (activeDrag) return;

        const current = itemIndex.get(node.id);
        if (!current) return;

        const size = dimensionsForState(current.item, current.rotated);

        dragSnapshots.set(node.id, {
            container_public_id: container.public_id,
            grid_x: Number(current.item?.placement?.grid_x ?? node.x ?? 0),
            grid_y: Number(current.item?.placement?.grid_y ?? node.y ?? 0),
            grid_w: size.w,
            grid_h: size.h,
            rotated: Boolean(current.rotated),
        });

        // Snapshot autoritativo: posicao do itemIndex (nao compactacao visual do GridStack).
        const sourceSnapshot = [];
        for (const [publicId, entry] of itemIndex.entries()) {
            if (entry.container_public_id !== container.public_id || publicId === node.id) continue;
            const placement = entry.item?.placement || {};
            const entryRotated = Boolean(entry.rotated ?? placement.rotated);
            const entrySize = entry.item
                ? dimensionsForState(entry.item, entryRotated)
                : { w: Number(entry.grid_w || 1), h: Number(entry.grid_h || 1) };
            sourceSnapshot.push({
                id: publicId,
                x: Number(placement.grid_x ?? 0),
                y: Number(placement.grid_y ?? 0),
                w: Math.max(1, entrySize.w),
                h: Math.max(1, entrySize.h),
            });
        }

        activeDrag = {
            itemPublicId: node.id,
            sourceContainerPublicId: container.public_id,
            grid,
            sourceSnapshot,
            lastX: Number(node.x || 0),
            lastY: Number(node.y || 0),
            pointerX: null,
            pointerY: null,
            hoverContainerPublicId: container.public_id,
            hoverGrid: grid,
            hoverX: Number(node.x || 0),
            hoverY: Number(node.y || 0),
            hoverState: 'valid',
            depositContainerPublicId: null,
            depositSlot: null,
            depositRotated: null,
            rotated: Boolean(current.rotated),
            handled: false,
            lockedNodes: [],
            sessionId: `${node.id}:${Date.now()}`,
        };

        lockStaticNodesForDrag();
        beginDragSession();
        hideAllItemTooltips();
        closeContextMenu();
        updateEquipmentDragHighlights(null, null);

        const startRect = element.getBoundingClientRect();
        const startX = startRect.left + (startRect.width / 2);
        const startY = startRect.top + (startRect.height / 2);
        syncDragMirror(startX, startY, element);
    });

    grid.on('drag', (event, element) => {
        if (silent || loading || !activeDrag || !isConnectedElement(element)) return;

        const node = element?.gridstackNode;
        if (!node?.id || node.id !== activeDrag.itemPublicId) return;

        const coords = getDragEventCoords(event);
        if (!coords) {
            updatePlacementHint(element);
            return;
        }

        activeDrag.pointerX = coords.clientX;
        activeDrag.pointerY = coords.clientY;

        if (syncHoverFromPointer(coords.clientX, coords.clientY)) {
            scheduleGhostPreviewUpdate(coords.clientX, coords.clientY);
        }

        updateEquipmentDragHighlights(coords.clientX, coords.clientY);
        syncDragMirror(coords.clientX, coords.clientY, element);
        enforceDraggedFootprint({ containerPublicId: container.public_id, grid, node, element });
        updatePlacementHint(element);
    });

    grid.on('change', (_event, items) => {
        if (silent || loading || actionInFlight || !activeDrag) return;

        const changedOthers = (items || []).some((item) => item.id && item.id !== activeDrag.itemPublicId);
        if (!changedOthers) return;

        const dragged = findDraggedWidget();
        if (dragged?.node) {
            activeDrag.lastX = Math.round(Number(dragged.node.x || 0));
            activeDrag.lastY = Math.round(Number(dragged.node.y || 0));
        }

        restoreOtherNodes(grid, activeDrag.itemPublicId);
    });

    grid.on('dragstop', async (event, element) => {
        if (silent || loading || activeDrag?.handled) return;

        if (!isConnectedElement(element)) {
            endDragSession();
            clearActiveDrag();
            return;
        }

        const node = element?.gridstackNode;
        if (!node?.id || !activeDrag || node.id !== activeDrag.itemPublicId) return;
        if (inventoryMoveQueue.isItemPending(node.id)) {
            endDragSession();
            clearActiveDrag();
            return;
        }

        const sessionId = activeDrag.sessionId;
        if (activeDrag.finalizing) return;
        activeDrag.finalizing = true;

        const coords = dragPointerCoords(event);

        if (coords) {
            syncHoverFromPointer(coords.clientX, coords.clientY);
        }

        enforceDraggedFootprint({ containerPublicId: container.public_id, grid, node, element });

        try {
            if (!activeDrag || activeDrag.sessionId !== sessionId) return;
            await finalizeDrag(event);
        } catch (error) {
            console.error('[inventory-drag]', error);
            revertItem(node.id);
            scrubOrphanGridWidgets(container.public_id);
        } finally {
            endDragSession();
            clearActiveDrag();
            scrubOrphanGridWidgets(container.public_id);
            dedupeItemWidgets(node.id);
        }
    });

    grids.set(container.public_id, grid);
    return grid;
}

function bindItemShortcuts(container, item, widget) {
    const content = widget?.querySelector('.grid-stack-item-content');
    if (!content) return;

    content.addEventListener('click', async (event) => {
        if (event.altKey) {
            event.preventDefault();
            event.stopPropagation();
            await quickMoveItem(container.public_id, item);
            return;
        }

        if (event.shiftKey) {
            event.preventDefault();
            event.stopPropagation();
            toggleInventorySelection(item.public_id);
            return;
        }

        if (!event.ctrlKey && !event.metaKey) {
            if (comparePickState && isEquippableItem(item)) {
                event.preventDefault();
                event.stopPropagation();
                tryResolveComparePick(item);
            }
            return;
        }
        event.preventDefault();
        event.stopPropagation();

        if (isEquippableItem(item)) {
            openComparePanel(item);
            return;
        }

        if (isMergeableItem(item) && Number(item.quantity || 1) > 1) {
            await quickSplit(container.public_id, item);
        }
    });

    content.addEventListener('contextmenu', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        await openContextMenu(event, item);
    });
}

function resolveQuickMoveTargetContainer(sourceContainerPublicId, item = null) {
    const source = containerIndex.get(sourceContainerPublicId)
        || allContainersCache.find((entry) => entry.public_id === sourceContainerPublicId);
    const sourceKind = source ? containerKind(source) : null;
    const main = findMainInventoryContainer(allContainersCache)
        || [...containerIndex.values()].find((entry) => containerKind(entry) === 'main');

    const isValidTarget = (candidateId) => {
        if (!candidateId || candidateId === sourceContainerPublicId) return false;
        if (!grids.has(candidateId)) return false;
        if (item && isItemMovingIntoOwnContainer(item, candidateId)) return false;
        return true;
    };

    if (sourceKind === 'main') {
        const backpackId = equippedBackpackPublicId;
        if (isValidTarget(backpackId)) {
            return backpackId;
        }
        const openBag = getOpenFloatingContainerIds?.()?.find((id) => isValidTarget(id));
        if (openBag) return openBag;
        return null;
    }

    return isValidTarget(main?.public_id) ? main.public_id : null;
}

async function quickMoveItem(sourceContainerPublicId, item) {
    if (!item?.public_id || loading || activeDrag || paperdollDragState || actionInFlight) return;
    if (item.flags?.locked || item.placement?.locked) {
        toast('Item travado nao pode ser movido rapidamente.', 'info', 2400);
        playInventoryFeedback('invalid');
        return;
    }

    const targetContainerPublicId = resolveQuickMoveTargetContainer(sourceContainerPublicId, item);
    if (!targetContainerPublicId || targetContainerPublicId === sourceContainerPublicId) {
        toast(
            isStorageContainerItem(item)
                ? 'Nao e possivel colocar a bag dentro dela mesma. Abra outro destino.'
                : 'Abra uma bag ou bau de destino para o quick-move.',
            'info',
            2800
        );
        playInventoryFeedback('invalid');
        return;
    }

    if (isItemMovingIntoOwnContainer(item, targetContainerPublicId)) {
        toast('Nao e possivel colocar um container dentro dele mesmo.', 'info', 2800);
        playInventoryFeedback('invalid');
        return;
    }

    if (!grids.has(targetContainerPublicId)) {
        toast('Destino precisa estar aberto.', 'info', 2400);
        playInventoryFeedback('invalid');
        return;
    }

    const rotated = Boolean(item.placement?.rotated);
    const size = dimensionsForState(item, rotated);
    const free = findFirstFreeSlot(targetContainerPublicId, size.w, size.h);
    if (!free) {
        toast('Sem espaco livre no destino.', 'info', 2400);
        playInventoryFeedback('invalid');
        return;
    }

    const entry = itemIndex.get(item.public_id);
    const source = {
        item_public_id: item.public_id,
        container_public_id: sourceContainerPublicId,
        placement_version: Number(entry?.placement_version || item.placement?.placement_version || 1),
        grid_x: Number(item.placement?.grid_x || 0),
        grid_y: Number(item.placement?.grid_y || 0),
        rotated,
    };
    const interaction = {
        item_public_id: item.public_id,
        source_container_public_id: sourceContainerPublicId,
        target_container_public_id: targetContainerPublicId,
        grid_x: free.grid_x,
        grid_y: free.grid_y,
        rotated,
    };

    hideAllItemTooltips();
    closeContextMenu();
    await attemptMove(source, interaction);
}

function highlightContainer(containerPublicId) {
    const section = document.querySelector(`[data-container-public-id="${containerPublicId}"]`);
    if (!section) return;

    section.classList.add('inventory-container-highlight');
    section.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    window.setTimeout(() => section.classList.remove('inventory-container-highlight'), 1800);
}

function highlightItem(itemPublicId) {
    const widget = document.querySelector(`[data-item-public-id="${itemPublicId}"]`)?.closest('.grid-stack-item');
    if (!widget) return;

    widget.classList.add('inventory-item-highlight');
    widget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    window.setTimeout(() => widget.classList.remove('inventory-item-highlight'), 1800);
}

function remountVisibleContainerPanelsFromCache() {
    if (!containerRoot) return false;

    destroyContainerPanelGrids();
    containerRoot.querySelectorAll('.inventory-container, .inventory-split-layout, .inventory-empty').forEach((node) => node.remove());
    containerRoot.querySelector('[data-inventory-filters]')?.remove();

    const visibleContainers = allContainersCache.filter(isRightDrawerContainerVisible);
    if (!visibleContainers.length) {
        containerRoot.innerHTML = '<div class="inventory-empty">Nenhum container encontrado.</div>';
        return false;
    }

    containerRoot.appendChild(renderInventoryFilterToolbar(visibleContainers));

    for (const container of visibleContainers) {
        containerIndex.set(container.public_id, container);
        const section = renderContainer(container, inventorySummaryByPublicId.get(container.public_id) || null);
        containerRoot.appendChild(section);
        const gridNode = section.querySelector('.inventory-grid');
        const grid = initializeGrid(container, gridNode);
        addItems(container, grid);
    }

    bindContainerLinks();
    applyInventoryFilters();
    return true;
}

function refreshItemWidget(publicId) {
    const entry = itemIndex.get(publicId);
    if (!entry?.item) return;

    const grid = grids.get(entry.container_public_id);
    const node = grid?.engine.nodes.find((candidate) => candidate.id === publicId);
    const content = node?.el?.querySelector('.grid-stack-item-content');
    if (!content) return;

    content.innerHTML = renderItem(entry.item);
}

function removeItemFromContainerIndex(itemPublicId, containerPublicId) {
    const container = containerIndex.get(containerPublicId);
    if (!container?.items) return;
    container.items = container.items.filter((entry) => entry.public_id !== itemPublicId);

    for (const cached of allContainersCache) {
        if (cached.public_id !== containerPublicId || !Array.isArray(cached.items)) continue;
        cached.items = cached.items.filter((entry) => entry.public_id !== itemPublicId);
    }
}

function patchStackMerge(sourceItem, targetItem, mergeResult = {}) {
    const sourceId = sourceItem.public_id;
    const targetId = targetItem.public_id;
    const sourceQty = Number(mergeResult.source_quantity ?? 0);
    const targetQty = Number(mergeResult.target_quantity ?? 0);
    const sourceEntry = itemIndex.get(sourceId);
    const targetEntry = itemIndex.get(targetId);

    if (targetEntry?.item) {
        targetEntry.item.quantity = targetQty;
        targetEntry.quantity = targetQty;
        if (mergeResult.target_quality_value != null) {
            targetEntry.item.quality_value = mergeResult.target_quality_value;
        }
        if (mergeResult.target_placement_version != null) {
            targetEntry.placement_version = Number(mergeResult.target_placement_version);
            if (targetEntry.item.placement) {
                targetEntry.item.placement.placement_version = targetEntry.placement_version;
            }
        }
        refreshItemWidget(targetId);
    }

    if (sourceQty <= 0) {
        purgeItemWidgetFromAllGrids(sourceId);
        if (sourceEntry?.container_public_id) {
            removeItemFromContainerIndex(sourceId, sourceEntry.container_public_id);
            scrubOrphanGridWidgets(sourceEntry.container_public_id);
        }
        itemIndex.delete(sourceId);
    } else if (sourceEntry?.item) {
        sourceEntry.item.quantity = sourceQty;
        sourceEntry.quantity = sourceQty;
        if (mergeResult.source_placement_version != null) {
            sourceEntry.placement_version = Number(mergeResult.source_placement_version);
            if (sourceEntry.item.placement) {
                sourceEntry.item.placement.placement_version = sourceEntry.placement_version;
            }
        }
        refreshItemWidget(sourceId);
    }

    if (targetEntry?.container_public_id) {
        updateContainerOccupancyBadge(targetEntry.container_public_id);
    }
    applyInventoryFilters();
    return true;
}

function destroyContainerPanelGrids() {
    const containerIds = new Set();

    containerRoot?.querySelectorAll('[data-container-public-id]').forEach((section) => {
        const publicId = section.getAttribute('data-container-public-id');
        if (publicId) containerIds.add(publicId);
    });

    for (const publicId of containerIds) {
        const grid = grids.get(publicId);
        if (!grid) continue;

        silent = true;
        try {
            grid.destroy(false);
        } finally {
            silent = false;
        }

        grids.delete(publicId);
        gridCellSizes.delete(publicId);

        for (const [itemPublicId, entry] of itemIndex.entries()) {
            if (entry.container_public_id === publicId) {
                itemIndex.delete(itemPublicId);
            }
        }
    }
}

async function reloadContainerPanelsOnly() {
    if (!containerRoot || loading) {
        await loadInventory();
        return;
    }

    loading = true;
    silent = true;

    try {
        const [response, summaryResponse] = await Promise.all([
            apiFetch('/api/inventory'),
            apiFetch('/api/inventory/summary').catch(() => null),
        ]);

        const containers = response.data?.containers || [];
        allContainersCache = containers;

        for (const container of containers) {
            containerIndex.set(container.public_id, container);
            upsertContainerCache(container);
        }

        inventorySummaryByPublicId = new Map(
            (summaryResponse?.data?.containers || []).map((entry) => [entry.public_id, entry])
        );
        renderSummary(summaryResponse?.data || null, playerWallets);

        destroyContainerPanelGrids();
        containerRoot.querySelectorAll('.inventory-container, .inventory-split-layout, .inventory-empty').forEach((node) => node.remove());
        containerRoot.querySelector('[data-inventory-filters]')?.remove();

        if (expeditionCarryOpen) {
            syncExpeditionBagPanel();
        }

        const summaryByPublicId = inventorySummaryByPublicId;
        const visibleContainers = containers.filter(isRightDrawerContainerVisible);

        if (!visibleContainers.length) {
            containerRoot.innerHTML = '<div class="inventory-empty">Nenhum container encontrado.</div>';
            setStatus('Vazio');
            return;
        }

        containerRoot.appendChild(renderInventoryFilterToolbar(visibleContainers));

        const splitParent = splitViewState?.parentPublicId
            ? containers.find((container) => container.public_id === splitViewState.parentPublicId)
            : null;
        const splitChild = splitViewState?.childPublicId
            ? containers.find((container) => container.public_id === splitViewState.childPublicId)
            : null;

        if (splitParent && splitChild && isRightDrawerContainerVisible(splitChild)) {
            const splitSection = renderSplitLayout(splitParent, splitChild, summaryByPublicId);
            containerRoot.appendChild(splitSection);

            for (const container of [splitParent, splitChild]) {
                const section = splitSection.querySelector(`[data-container-public-id="${container.public_id}"]`);
                const gridNode = section?.querySelector('.inventory-grid');
                if (!gridNode) continue;
                initializeGrid(container, gridNode);
                addItems(container, grids.get(container.public_id));
            }
        } else {
            if (splitViewState) clearSplitView();

            for (const container of visibleContainers) {
                const section = renderContainer(container, summaryByPublicId.get(container.public_id) || null);
                containerRoot.appendChild(section);
                const gridNode = section.querySelector('.inventory-grid');
                const grid = initializeGrid(container, gridNode);
                addItems(container, grid);
            }
        }

        bindContainerLinks();
        applyInventoryFilters();

        const openFloatingIds = getOpenFloatingContainerIds();
        if (openFloatingIds.length > 0) {
            await Promise.all(openFloatingIds.map((id) => softRefreshFloatingBagWindow(id).catch(() => null)));
        }

        setStatus('Sincronizado');
    } catch (error) {
        handleError(error, 'Nao foi possivel atualizar os containers.');
        await loadInventory();
    } finally {
        silent = false;
        loading = false;
    }
}

async function closeSplitViewPanels() {
    const mainContainer = allContainersCache.find((container) => containerKind(container) === 'main');
    if (mainContainer?.public_id) {
        try {
            const response = await apiFetch(`/api/inventory/containers/${encodeURIComponent(mainContainer.public_id)}`);
            const mainPayload = unwrapContainerPayload(response.data);
            if (mainPayload?.public_id) {
                containerIndex.set(mainPayload.public_id, mainPayload);
                upsertContainerCache(mainPayload);
            }
        } catch {
            // keep cached main inventory
        }
    }

    if (!remountVisibleContainerPanelsFromCache()) {
        await reloadContainerPanelsOnly();
    }
}

async function mountSplitView(parentPublicId, childPublicId) {
    if (!containerRoot) return;

    setStatus('Abrindo...');

    try {
        const [parentResponse, childResponse, summaryResponse] = await Promise.all([
            apiFetch(`/api/inventory/containers/${encodeURIComponent(parentPublicId)}`),
            apiFetch(`/api/inventory/containers/${encodeURIComponent(childPublicId)}`),
            apiFetch('/api/inventory/summary').catch(() => null),
        ]);

        const parent = unwrapContainerPayload(parentResponse.data);
        const child = unwrapContainerPayload(childResponse.data);
        if (!parent?.public_id || !child?.public_id) {
            throw new Error('Containers indisponiveis.');
        }

        containerIndex.set(parent.public_id, parent);
        containerIndex.set(child.public_id, child);
        upsertContainerCache(parent);
        upsertContainerCache(child);

        for (const entry of summaryResponse?.data?.containers || []) {
            inventorySummaryByPublicId.set(entry.public_id, entry);
        }
        if (summaryResponse?.data) {
            renderSummary(summaryResponse.data, playerWallets);
        }

        destroyContainerPanelGrids();
        containerRoot.querySelectorAll('.inventory-container, .inventory-split-layout, .inventory-empty').forEach((node) => node.remove());
        containerRoot.querySelector('[data-inventory-filters]')?.remove();

        const containers = allContainersCache.map((cached) => {
            if (cached.public_id === parent.public_id) return parent;
            if (cached.public_id === child.public_id) return child;
            return cached;
        });

        if (!containers.some((entry) => entry.public_id === parent.public_id)) {
            containers.push(parent);
        }
        if (!containers.some((entry) => entry.public_id === child.public_id)) {
            containers.push(child);
        }

        allContainersCache = containers;
        const visibleContainers = containers.filter(isRightDrawerContainerVisible);
        containerRoot.appendChild(renderInventoryFilterToolbar(visibleContainers));

        const summaryByPublicId = inventorySummaryByPublicId;
        const splitSection = renderSplitLayout(parent, child, summaryByPublicId);
        containerRoot.appendChild(splitSection);

        for (const container of [parent, child]) {
            const section = splitSection.querySelector(`[data-container-public-id="${container.public_id}"]`);
            const gridNode = section?.querySelector('.inventory-grid');
            if (!gridNode) continue;
            const grid = initializeGrid(container, gridNode);
            addItems(container, grid);
        }

        bindContainerLinks();
        applyInventoryFilters();
        setStatus('Sincronizado');
    } catch (error) {
        handleError(error, 'Nao foi possivel abrir o armazenamento aninhado.');
        await reloadContainerPanelsOnly();
    }
}

let floatingBagOpenLockUntil = 0;
let containerLinksBound = false;

async function openLinkedContainerForItem(item) {
    if (!item) return false;

    if (item?.definition?.equip_slot_code === 'backpack' && item.public_id === equippedBackpackPublicId) {
        toggleExpeditionBag();
        const expedition = resolveExpeditionCarryContainer();
        if (expeditionCarryOpen && expedition?.public_id) {
            highlightContainer(expedition.public_id);
        }
        return true;
    }

    const linked = item?.linked_container
        || itemIndex.get(item?.public_id)?.linked_container
        || itemIndex.get(item?.public_id)?.item?.linked_container
        || null;
    if (!linked?.public_id) return false;

    const now = Date.now();
    if (now < floatingBagOpenLockUntil) return true;
    floatingBagOpenLockUntil = now + 450;

    // Se ja esta aberto: so foca (nao fecha no duplo clique — fecha pelo X).
    if (isFloatingContainerOpen(linked.public_id)) {
        return openFloatingBagWindow(linked.public_id, {
            sourceItemPublicId: item.public_id || null,
        });
    }

    if (splitViewState) {
        clearSplitView();
        await closeSplitViewPanels().catch(() => null);
    }

    openContainerPublicIds.delete(linked.public_id);
    persistContainerPanels();

    return openFloatingBagWindow(linked.public_id, {
        sourceItemPublicId: item.public_id || null,
    });
}

function bindContainerLinks() {
    for (const section of document.querySelectorAll('.inventory-container-physical[data-source-item-public-id]')) {
        const sourceItemPublicId = section.dataset.sourceItemPublicId;
        const link = section.querySelector('.inventory-container-link');
        if (!link || !sourceItemPublicId || link.dataset.boundSourceLink === '1') continue;
        link.dataset.boundSourceLink = '1';
        link.addEventListener('click', (event) => {
            event.preventDefault();
            highlightItem(sourceItemPublicId);
        });
    }

    if (containerLinksBound) return;
    containerLinksBound = true;

    document.addEventListener('dblclick', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        if (target.closest('.tippy-box')) return;
        if (activeDrag) return;

        const content = target.closest('.inventory-item.is-container-item');
        if (!content) return;

        const itemPublicId = content.dataset.itemPublicId;
        if (!itemPublicId) return;

        event.preventDefault();
        event.stopPropagation();
        void openLinkedContainerForItem(itemIndex.get(itemPublicId)?.item || null);
    }, true);
}

function renderSummary(summary, wallets = []) {
    const containers = summary?.containers?.length || 0;
    const items = summary?.item_count || 0;
    const equipped = summary?.equipped_item_count || 0;
    const gold = walletBalance('gold') || Number(wallets.find((entry) => (entry.currency_code || entry.code) === 'gold')?.balance || 0);
    const premium = walletBalance('premium') || Number(wallets.find((entry) => (entry.currency_code || entry.code) === 'premium')?.balance || 0);

    if (summaryNode) {
        summaryNode.textContent = `${containers} containers · ${items} itens · ${equipped} equipado(s)`;
    }

    const goldNode = playerHudRoot?.querySelector('[data-hud-gold]');
    const premiumNode = playerHudRoot?.querySelector('[data-hud-premium]');
    if (goldNode) goldNode.textContent = gold.toLocaleString('pt-BR');
    if (premiumNode) premiumNode.textContent = premium.toLocaleString('pt-BR');
}

function syncDockVitals(hud) {
    const vitals = hud?.vitals || {};
    const player = hud?.player || {};
    const hpFill = document.querySelector('[data-dock-hp-fill]');
    const enFill = document.querySelector('[data-dock-en-fill]');
    const xpBar = document.querySelector('[data-dock-xp]');
    if (hpFill) {
        const health = vitals.health || {};
        hpFill.style.setProperty('--orb-fill', `${percentBar(health.current, health.max)}%`);
    }
    if (enFill) {
        const energy = vitals.energy || {};
        enFill.style.setProperty('--orb-fill', `${percentBar(energy.current, energy.max)}%`);
    }
    if (xpBar) {
        xpBar.style.width = `${percentBar(player.experience || 0, player.experience_next || 1)}%`;
    }
}

const DOCK_HOTBAR_SIZE = 7;
const DOCK_HOTBAR_UNLOCKED = 3;
let hotbarDragState = null;
let inventoryAudioCtx = null;

function playInventoryFeedback(kind = 'valid') {
    try {
        if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
            navigator.vibrate(kind === 'valid' ? 14 : [10, 28, 10]);
        }
    } catch (_vibrateError) {
        // ignore
    }

    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        if (!inventoryAudioCtx) inventoryAudioCtx = new AudioCtx();
        if (inventoryAudioCtx.state === 'suspended') {
            inventoryAudioCtx.resume().catch(() => null);
        }
        const now = inventoryAudioCtx.currentTime;
        const osc = inventoryAudioCtx.createOscillator();
        const gain = inventoryAudioCtx.createGain();
        osc.type = kind === 'valid' ? 'sine' : 'triangle';
        osc.frequency.setValueAtTime(kind === 'valid' ? 680 : 210, now);
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(kind === 'valid' ? 0.045 : 0.035, now + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + (kind === 'valid' ? 0.09 : 0.12));
        osc.connect(gain);
        gain.connect(inventoryAudioCtx.destination);
        osc.start(now);
        osc.stop(now + 0.14);
    } catch (_audioError) {
        // ignore
    }
}

function dockHotbarEquipmentCode(slotNumber) {
    if (slotNumber >= 1 && slotNumber <= 4) return `potion_${slotNumber}`;
    return null;
}

function itemUseEffect(item) {
    return item?.definition?.base_config?.use_effect || null;
}

function isHotbarCompatibleItem(item) {
    if (!item) return false;
    const code = String(item?.definition?.equip_slot_code || '');
    if (code === 'potion' || code === 'consumable' || /^potion_\d+$/.test(code)) {
        return true;
    }
    const category = itemCategoryCode(item);
    const effect = itemUseEffect(item);
    if (category === 'consumable' && effect && typeof effect === 'object') {
        return true;
    }
    return Boolean(effect && ['food', 'expedition', 'potion'].includes(String(effect.kind || '')));
}

function hotbarItemShortLabel(item) {
    if (!item) return '';
    const raw = String(item.name || item.definition_code || itemLabel?.(item) || '?').trim();
    const compact = raw.replace(/^pocao\s+/i, 'P ').replace(/^poção\s+/i, 'P ');
    return compact.length > 8 ? `${compact.slice(0, 7)}…` : compact;
}

function findHotbarSlotUnderPointer(clientX, clientY) {
    const root = document.querySelector('[data-dock-hotbar]');
    if (!root) return null;
    for (const button of root.querySelectorAll('[data-hotbar-slot]')) {
        if (!(button instanceof HTMLElement) || button.disabled) continue;
        if (isPointerInsideElement(button, clientX, clientY)) {
            return Number(button.dataset.hotbarSlot || 0) || null;
        }
    }
    return null;
}

function clearHotbarDropHighlights() {
    document.querySelectorAll('.game-dock-slot.is-hotbar-drop, .game-dock-slot.is-hotbar-dragging, .game-dock-slot.is-drop-hint')
        .forEach((node) => node.classList.remove('is-hotbar-drop', 'is-hotbar-dragging', 'is-drop-hint'));
}

function applyOptimisticHotbarSwap(fromSlotNumber, toSlotNumber) {
    const fromCode = dockHotbarEquipmentCode(fromSlotNumber);
    const toCode = dockHotbarEquipmentCode(toSlotNumber);
    if (!fromCode || !toCode) return;
    const next = (currentEquipment || []).map((slot) => ({ ...slot, item: slot.item || null }));
    const fromIndex = next.findIndex((slot) => slot.code === fromCode);
    const toIndex = next.findIndex((slot) => slot.code === toCode);
    if (fromIndex < 0 || toIndex < 0) return;
    const fromItem = next[fromIndex].item;
    next[fromIndex] = { ...next[fromIndex], item: next[toIndex].item };
    next[toIndex] = { ...next[toIndex], item: fromItem };
    currentEquipment = next;
    renderDockHotbar(currentEquipment);
}

async function equipItemToHotbarSlot(item, slotNumber) {
    const equipmentCode = dockHotbarEquipmentCode(slotNumber);
    if (!equipmentCode || !item?.public_id) return false;
    if (!isHotbarCompatibleItem(item)) {
        toast('Este item nao pode ir para a hotbar.', 'info', 2600);
        playInventoryFeedback('invalid');
        return false;
    }

    // Itens com use_effect mas sem slot ainda: tenta equipar como consumable via target_slot.
    const slotCode = String(item?.definition?.equip_slot_code || '');
    if (!slotCode) {
        toast('Este consumivel ainda nao tem slot de equipamento configurado.', 'warning', 3200);
        playInventoryFeedback('invalid');
        return false;
    }

    actionInFlight = true;
    try {
        setStatus('Equipando na hotbar...');
        await apiFetch(`/api/items/${encodeURIComponent(item.public_id)}/actions/EQUIP`, {
            method: 'POST',
            body: { target_slot: equipmentCode },
        });
        toast(`Item colocado no atalho ${slotNumber}.`, 'success', 1800);
        playInventoryFeedback('valid');
        containerDetailCache.invalidate();
        await refreshEquipmentOnly();
        await reloadContainerPanelsOnly().catch(() => null);
        return true;
    } catch (error) {
        playInventoryFeedback('invalid');
        handleError(error, 'Nao foi possivel colocar o item na hotbar.');
        return false;
    } finally {
        actionInFlight = false;
        setStatus('Sincronizado');
    }
}

function resolveUnequipDropTarget(item, clientX, clientY, preferredContainerPublicId = null) {
    const hover = (clientX != null && clientY != null)
        ? findGridUnderPointer(clientX, clientY)
        : null;
    const containerPublicId = preferredContainerPublicId || hover?.containerPublicId || null;
    const grid = containerPublicId ? grids.get(containerPublicId) : null;
    const container = containerPublicId ? containerIndex.get(containerPublicId) : null;
    if (!item || !grid?.el || !container) return null;

    const size = dimensionsForState(item, false);
    const columns = Number(container.grid?.columns || 0);
    const rows = Number(container.grid?.rows || 0);
    if (columns <= 0 || rows <= 0) return null;

    const cell = gridCellFromPointer(
        grid.el,
        clientX,
        clientY,
        size.w,
        size.h,
        columns,
        rows
    );
    if (!cell) return null;

    const snapshot = placementSnapshotForContainer(containerPublicId, item.public_id);
    if (!isPlacementValidAgainstSnapshot(containerPublicId, snapshot, cell.x, cell.y, size.w, size.h)) {
        return null;
    }

    return {
        containerPublicId,
        grid_x: cell.x,
        grid_y: cell.y,
        grid_w: size.w,
        grid_h: size.h,
        rotated: false,
    };
}

function placeItemInContainerLocally(item, containerPublicId, gridX, gridY, options = {}) {
    if (!item?.public_id || !containerPublicId) return false;
    const grid = grids.get(containerPublicId);
    const container = containerIndex.get(containerPublicId);
    if (!grid || !container) return false;

    const rotated = Boolean(options.rotated);
    const fallbackSize = dimensionsForState(item, rotated);
    const size = {
        w: Math.max(1, Number(options.grid_w || fallbackSize.w)),
        h: Math.max(1, Number(options.grid_h || fallbackSize.h)),
    };
    const placementVersion = Number(options.placement_version || 1);
    const nextItem = {
        ...item,
        equipped: false,
        placement: {
            ...(item.placement || {}),
            grid_x: gridX,
            grid_y: gridY,
            grid_w: size.w,
            grid_h: size.h,
            rotated,
            placement_version: placementVersion,
            locked: false,
        },
    };

    itemIndex.set(item.public_id, {
        container_public_id: containerPublicId,
        placement_version: placementVersion,
        quantity: Number(nextItem.quantity || 1),
        definition_code: nextItem.definition?.code || '',
        stackable: Boolean(nextItem.definition?.stackable),
        max_stack: Number(nextItem.definition?.max_stack || 1),
        quality_bucket: nextItem.quality_bucket || null,
        grid_w: size.w,
        grid_h: size.h,
        rotated,
        linked_container: nextItem.linked_container || null,
        item: nextItem,
    });

    purgeItemWidgetFromAllGrids(item.public_id);
    silent = true;
    try {
        grid.addWidget({
            id: item.public_id,
            x: gridX,
            y: gridY,
            w: size.w,
            h: size.h,
            noResize: true,
            noMove: false,
            locked: false,
            content: renderItem(nextItem),
        });
        const widget = grid.engine.nodes.find((node) => node.id === item.public_id)?.el;
        bindItemWidget(container, nextItem, widget);
    } finally {
        silent = false;
    }

    updateContainerOccupancyBadge(containerPublicId);
    applyInventoryFilters();
    return true;
}

async function unequipHotbarSlot(slotNumber) {
    const equipmentCode = dockHotbarEquipmentCode(slotNumber);
    if (!equipmentCode) return false;
    const slot = (currentEquipment || []).find((entry) => entry.code === equipmentCode);
    if (!slot?.item?.public_id) return false;

    actionInFlight = true;
    try {
        setStatus('Removendo da hotbar...');
        await apiFetch(`/api/items/${encodeURIComponent(slot.item.public_id)}/actions/UNEQUIP`, {
            method: 'POST',
            body: {},
        });
        toast(`Atalho ${slotNumber} liberado.`, 'success', 1800);
        playInventoryFeedback('valid');
        containerDetailCache.invalidate();
        await refreshEquipmentOnly();
        await reloadContainerPanelsOnly().catch(() => null);
        return true;
    } catch (error) {
        playInventoryFeedback('invalid');
        handleError(error, 'Nao foi possivel remover o item da hotbar.');
        return false;
    } finally {
        actionInFlight = false;
        setStatus('Sincronizado');
    }
}

async function swapHotbarSlots(fromSlotNumber, toSlotNumber) {
    const fromCode = dockHotbarEquipmentCode(fromSlotNumber);
    const toCode = dockHotbarEquipmentCode(toSlotNumber);
    if (!fromCode || !toCode || fromCode === toCode) return false;

    applyOptimisticHotbarSwap(fromSlotNumber, toSlotNumber);
    actionInFlight = true;
    try {
        await apiFetch('/api/player/equipment/swap', {
            method: 'POST',
            body: { from_slot: fromCode, to_slot: toCode },
        });
        playInventoryFeedback('valid');
        await refreshEquipmentOnly();
        return true;
    } catch (error) {
        playInventoryFeedback('invalid');
        handleError(error, 'Nao foi possivel trocar os atalhos.');
        await refreshEquipmentOnly();
        return false;
    } finally {
        actionInFlight = false;
    }
}

function bindDockHotbar() {
    const root = document.querySelector('[data-dock-hotbar]');
    if (!root || root.dataset.bound === '1') return;
    root.dataset.bound = '1';

    root.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        event.stopPropagation();
    });

    root.addEventListener('click', (event) => {
        if (hotbarDragState) return;
        if (event.button != null && event.button !== 0) return;
        const button = event.target.closest('[data-hotbar-slot]');
        if (!(button instanceof HTMLButtonElement) || button.disabled) return;
        const slotNumber = Number(button.dataset.hotbarSlot || 0);
        useDockHotbarSlot(slotNumber);
    });

    root.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) {
            event.preventDefault();
        }
    });

    root.addEventListener('dragstart', (event) => {
        if (event.button != null && event.button !== 0) {
            event.preventDefault();
            return;
        }
        const button = event.target.closest('[data-hotbar-slot]');
        if (!(button instanceof HTMLButtonElement) || button.disabled || !button.classList.contains('is-filled')) {
            event.preventDefault();
            return;
        }
        const slotNumber = Number(button.dataset.hotbarSlot || 0);
        const equipmentCode = dockHotbarEquipmentCode(slotNumber);
        if (!equipmentCode) {
            event.preventDefault();
            return;
        }
        hotbarDragState = { fromSlot: slotNumber, equipmentCode };
        button.classList.add('is-hotbar-dragging');
        event.dataTransfer?.setData('text/evolvaxe-hotbar', String(slotNumber));
        event.dataTransfer.effectAllowed = 'move';
    });

    root.addEventListener('dragover', (event) => {
        const button = event.target.closest('[data-hotbar-slot]');
        if (!(button instanceof HTMLButtonElement) || button.disabled) return;
        event.preventDefault();
        clearHotbarDropHighlights();
        button.classList.add('is-hotbar-drop');
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    });

    root.addEventListener('dragleave', (event) => {
        const button = event.target.closest('[data-hotbar-slot]');
        button?.classList.remove('is-hotbar-drop');
    });

    root.addEventListener('drop', async (event) => {
        event.preventDefault();
        const button = event.target.closest('[data-hotbar-slot]');
        clearHotbarDropHighlights();
        if (!(button instanceof HTMLButtonElement) || button.disabled) return;
        const toSlot = Number(button.dataset.hotbarSlot || 0);
        const fromSlot = Number(event.dataTransfer?.getData('text/evolvaxe-hotbar') || hotbarDragState?.fromSlot || 0);
        hotbarDragState = null;
        if (!fromSlot || !toSlot || fromSlot === toSlot) return;
        await swapHotbarSlots(fromSlot, toSlot);
    });

    root.addEventListener('dragend', async (event) => {
        const fromSlot = hotbarDragState?.fromSlot || null;
        clearHotbarDropHighlights();
        hotbarDragState = null;
        if (!fromSlot) return;

        const overHotbar = findHotbarSlotUnderPointer(event.clientX, event.clientY);
        if (overHotbar) return;

        const overGrid = findGridUnderPointer(event.clientX, event.clientY);
        if (overGrid) {
            await unequipHotbarSlot(fromSlot);
        }
    });
}

function renderDockHotbar(equipment = currentEquipment) {
    const root = document.querySelector('[data-dock-hotbar]');
    if (!root) return;
    bindDockHotbar();

    const byCode = new Map((equipment || []).map((slot) => [slot.code, slot]));
    const parts = [];
    for (let slotNumber = 1; slotNumber <= DOCK_HOTBAR_SIZE; slotNumber += 1) {
        const unlocked = slotNumber <= DOCK_HOTBAR_UNLOCKED;
        const equipmentCode = dockHotbarEquipmentCode(slotNumber);
        const equipped = equipmentCode ? byCode.get(equipmentCode) : null;
        const item = equipped?.item || null;
        const qty = Number(item?.quantity || 0);
        const short = hotbarItemShortLabel(item);
        const artUrl = item ? itemAssetUrl(item) : null;
        const title = !unlocked
            ? `Slot ${slotNumber} bloqueado`
            : (item ? `${itemLabel?.(item) || item.name || 'Consumivel'} [${slotNumber}]` : `Slot vazio [${slotNumber}] · solte consumiveis aqui`);
        const iconHtml = !unlocked
            ? '<span class="game-dock-slot-lock" aria-hidden="true">×</span>'
            : (artUrl
                ? `<span class="game-dock-slot-icon has-art"><img src="${escapeHtml(artUrl)}" alt="" loading="lazy" draggable="false"></span>`
                : `<span class="game-dock-slot-icon${item ? '' : ' is-empty'}">${item ? escapeHtml(short) : '·'}</span>`);

        parts.push(`
            <button
                type="button"
                class="game-dock-slot${!unlocked ? ' is-locked' : ''}${item ? ' is-filled' : ''}"
                data-hotbar-slot="${slotNumber}"
                title="${escapeHtml(title)}"
                ${unlocked ? '' : 'disabled'}
                ${unlocked && item ? 'draggable="true"' : ''}
            >
                <span class="game-dock-slot-key">${slotNumber}</span>
                ${iconHtml}
                ${unlocked && qty > 1 ? `<em class="game-dock-slot-qty">${qty}</em>` : ''}
            </button>
        `);
    }
    root.innerHTML = parts.join('');
}

async function useDockHotbarSlot(slotNumber) {
    if (slotNumber < 1 || slotNumber > DOCK_HOTBAR_SIZE) return;
    if (slotNumber > DOCK_HOTBAR_UNLOCKED) {
        toast(`Slot ${slotNumber} ainda esta bloqueado.`, 'info', 2200);
        return;
    }
    const equipmentCode = dockHotbarEquipmentCode(slotNumber);
    if (!equipmentCode) {
        toast('Este slot ainda nao aceita itens.', 'info', 2200);
        return;
    }
    await useEquippedPotionHotkey(equipmentCode);
}

function percentBar(value, max) {
    const numeric = Number(value || 0);
    const numericMax = Math.max(1, Number(max || 1));
    return Math.max(0, Math.min(100, Math.round((numeric / numericMax) * 100)));
}

function bindPlayerHudActions() {
    if (!playerHudRoot || playerHudRoot.dataset.actionsBound === '1') return;
    playerHudRoot.dataset.actionsBound = '1';
    playerHudRoot.addEventListener('click', (event) => {
        const button = event.target.closest('[data-hud-action]');
        if (!(button instanceof HTMLElement)) return;
        const action = button.getAttribute('data-hud-action');
        if (action === 'stats') {
            toggleStatsDrawer();
            return;
        }
        if (action === 'equip') {
            openLeftDrawer();
            setLeftDrawerTab('equipment');
            return;
        }
        if (action === 'bag') {
            toggleRightDrawer();
            return;
        }
        if (action === 'missions') {
            openMissionsPanel();
        }
    });
}

function renderPlayerHud(hud) {
    if (!playerHudRoot) return;
    bindPlayerHudActions();
    lastPlayerHud = hud || null;

    if (!hud?.player) {
        playerHudRoot.innerHTML = '';
        delete playerHudRoot.dataset.lastHud;
        syncDockVitals(null);
        renderEquipmentAttributes(null);
        return;
    }
    playerHudRoot.dataset.lastHud = JSON.stringify(hud);
    if (hud.power && typeof hud.power === 'object') {
        playerPower = {
            ...(playerPower || {}),
            ...hud.power,
        };
    }
    renderEquipmentAttributes(hud);

    if (Array.isArray(hud.wallets)) {
        playerWallets = hud.wallets;
    }

    const player = hud.player || {};
    const vitals = hud.vitals || {};
    const power = hud.power || {};
    const avatarLabel = String(player.name || 'E').trim().charAt(0).toUpperCase() || 'E';
    const missions = Array.isArray(hud.missions) ? hud.missions : [];
    const gold = walletBalance('gold');
    const premium = walletBalance('premium');

    const health = vitals.health || {};
    const energy = vitals.energy || {};
    const hunger = vitals.hunger || {};
    const thirst = vitals.thirst || {};
    const healthCurrent = Number(health.current || 0);
    const healthMax = Number(health.max || 0);
    const energyCurrent = Number(energy.current || 0);
    const energyMax = Number(energy.max || 0);
    const hungerCurrent = Number(hunger.current || 0);
    const hungerMax = Number(hunger.max || 0);
    const thirstCurrent = Number(thirst.current || 0);
    const thirstMax = Number(thirst.max || 0);
    const xpPct = percentBar(player.experience || 0, player.experience_next || 1);
    const level = Number(player.level || 1);

    const vitalChip = (label, current, max, tone, title) => `
        <div class="game-hud-vital is-${escapeHtml(tone)}" title="${escapeHtml(title)}">
            <span class="game-hud-vital-label">${escapeHtml(label)}</span>
            <strong>${Number(current).toLocaleString('pt-BR')}<small>/${Number(max).toLocaleString('pt-BR')}</small></strong>
            <i style="width:${percentBar(current, max)}%"></i>
        </div>
    `;

    playerHudRoot.classList.add('is-arpg', 'is-toprail');
    playerHudRoot.innerHTML = `
        <button type="button" class="game-hud-identity" data-hud-action="stats" title="Abrir status [C]">
            <span class="game-hud-avatar-wrap">
                <span class="game-hud-avatar" aria-hidden="true">${escapeHtml(avatarLabel)}</span>
                <em class="game-hud-level-badge">${level.toLocaleString('pt-BR')}</em>
            </span>
            <span class="game-hud-id-text">
                <span class="game-hud-place">Base</span>
                <strong>${escapeHtml(player.name || 'Jogador')}</strong>
                <span class="game-hud-xp" aria-hidden="true"><i style="width:${xpPct}%"></i></span>
            </span>
        </button>
        <div class="game-hud-vitals" aria-label="Vitais">
            <div class="game-hud-vital-col">
                ${vitalChip('HP', healthCurrent, healthMax, 'health', 'Vida')}
                ${vitalChip('EN', energyCurrent, energyMax, 'energy', 'Energia')}
            </div>
            <div class="game-hud-vital-col">
                ${vitalChip('SE', thirstCurrent, thirstMax, 'thirst', 'Sede')}
                ${vitalChip('FO', hungerCurrent, hungerMax, 'hunger', 'Fome')}
            </div>
        </div>
        <div class="game-hud-economy" aria-label="Moedas">
            <span class="game-hud-coin is-gold" title="Ouro">
                <b>G</b><strong data-hud-gold>${gold.toLocaleString('pt-BR')}</strong>
            </span>
            <span class="game-hud-coin is-premium" title="Moeda especial">
                <b>◆</b><strong data-hud-premium>${premium.toLocaleString('pt-BR')}</strong>
            </span>
        </div>
        <div class="game-hud-power" title="Poder de combate">
            <span>Poder</span>
            <strong>${Number(power.total || 0).toLocaleString('pt-BR')}</strong>
        </div>
    `;

    syncDockVitals(hud);
    syncChromeInsets();
}

function inspectSummary(data) {
    const item = data.item || {};
    const parts = [
        `Codigo: ${item.definition_code || '-'}`,
        `Quantidade: ${Number(item.quantity || 1)}`,
        `Estado: ${item.state || '-'}`,
    ];

    if (item.quality_bucket) parts.push(`Qualidade: ${item.quality_bucket}`);
    if (item.is_container) parts.push('Tipo: container');

    return parts.join(' | ');
}

async function executeItemAction(item, action) {
    if (actionInFlight || loading) return;

    if (action.code === 'INSPECT') {
        await openInvestigationModal(item);
        return;
    }

    if (action.code === 'OPEN' && item?.definition?.is_container) {
        await openLinkedContainerForItem(item);
        return;
    }

    if (action.requires_confirmation) {
        const label = action.name || action.code;
        let bodyHtml = `<p>Confirmar esta acao em <strong>${escapeHtml(itemLabel(item))}</strong>?</p>`;

        if (action.code === 'SELL') {
            const npcValue = Number(item?.npc_value || 0);
            const marketValue = Number(item?.market_value || 0);
            const quantity = Math.max(1, Number(item?.quantity || 1));
            let breakdownHtml = '';
            try {
                const preview = await apiFetch(`/api/market/items/${encodeURIComponent(item.public_id)}/price-preview`);
                breakdownHtml = renderMarketBreakdownHtml(preview.data?.breakdown || {}, escapeHtml);
            } catch (_error) {
                breakdownHtml = '';
            }
            bodyHtml = `<p>Vender <strong>${escapeHtml(itemLabel(item))}</strong> ao NPC?</p>
                <p>Valor de mercado: <strong>${formatGameMoney(marketValue * quantity, 'G')}</strong><br>Venda NPC: <strong>${formatGameMoney(npcValue * quantity, 'G')}</strong>${quantity > 1 ? `<br><small>Total da stack x${quantity.toLocaleString('pt-BR')}</small>` : ''}</p>
                ${breakdownHtml}`;
        }

        if (action.code === 'LIST_MARKET') {
            const bounds = listingPriceBoundsForItem(item);
            const inputId = `listing-price-${item.public_id}`;
            bodyHtml = `<p>Anunciar <strong>${escapeHtml(itemLabel(item))}</strong> no mercado P2P.</p>
                <p>Faixa permitida: <strong>${bounds.min.toLocaleString('pt-BR')} – ${bounds.max.toLocaleString('pt-BR')} 💎</strong><br>
                Sugestao: <strong>${bounds.suggested.toLocaleString('pt-BR')} 💎</strong></p>
                <label class="inventory-action-field" for="${inputId}">Preco em Eter Cristal (💎)</label>
                <input id="${inputId}" type="number" min="${bounds.min}" max="${bounds.max}" step="1" value="${bounds.defaultPrice}" data-listing-price-input>`;
        }

        if (action.code === 'LIST_MARKET') {
            const bounds = listingPriceBoundsForItem(item);
            const pricePremium = await confirmInventoryAction({
                title: label,
                bodyHtml,
                confirmLabel: label,
                tone: action.is_destructive ? 'danger' : 'warning',
                collectData: (modalElement) => {
                    const priceInput = modalElement.querySelector('[data-listing-price-input]');
                    return Number(priceInput?.value || 0);
                },
            });
            if (pricePremium === false) return;

            if (!Number.isFinite(pricePremium) || pricePremium < bounds.min || pricePremium > bounds.max) {
                toast(`Informe um preco entre ${bounds.min.toLocaleString('pt-BR')} e ${bounds.max.toLocaleString('pt-BR')} 💎.`, 'error', 3200);
                return;
            }

            actionInFlight = true;
            try {
                setStatus('Anunciando item...');
                await apiFetch(
                    `/api/items/${encodeURIComponent(item.public_id)}/actions/${encodeURIComponent(action.code)}`,
                    { method: 'POST', body: { confirm: true, price_premium: pricePremium } }
                );
                toast('Item anunciado no mercado.', 'success', 3200);
                setStatus('Sincronizado');
                containerDetailCache.invalidate();
                await reloadContainerPanelsOnly();
                if (isMarketPanelOpen()) await loadMarketListings();
            } catch (error) {
                handleError(error, 'Nao foi possivel anunciar o item.');
            } finally {
                actionInFlight = false;
            }
            return;
        }

        const confirmed = await confirmInventoryAction({
            title: label,
            bodyHtml,
            confirmLabel: label,
            tone: action.is_destructive ? 'danger' : 'warning',
        });
        if (!confirmed) return;
    } else if (action.code === 'LIST_MARKET') {
        toast('Informe o preco para anunciar no mercado.', 'info', 2800);
        return;
    }

    actionInFlight = true;

    try {
        setStatus('Executando acao...');
        const body = action.requires_confirmation ? { confirm: true } : {};
        const response = await apiFetch(
            `/api/items/${encodeURIComponent(item.public_id)}/actions/${encodeURIComponent(action.code)}`,
            {
                method: 'POST',
                body,
            }
        );

        const data = response.data || {};

        if (data.action === 'DISMANTLE') {
            const materials = Array.isArray(data.materials) ? data.materials : [];
            const lines = materials.map((entry) => `${entry.label} x${entry.quantity}`).join(', ');
            toast(lines ? `Materiais recebidos: ${lines}` : 'Item desmanchado.', 'success', 4200);
            setStatus('Sincronizado');
            containerDetailCache.invalidate();
            await reloadContainerPanelsOnly();
            if (materialsPanelOpen) await loadMaterialsStash();
            return;
        }

        if (data.action === 'INSPECT') {
            toast(inspectSummary(data), 'info', 5200);
            setStatus('Sincronizado');
            return;
        }

        if (data.action === 'SELL') {
            toast(`Vendido por ${formatGameMoney(data.gold_received || 0, 'G')} (saldo: ${formatGameMoney(data.gold_balance || 0, 'G')}).`, 'success', 3600);
            setStatus('Sincronizado');
            containerDetailCache.invalidate();
            await reloadContainerPanelsOnly();
            return;
        }

        if (data.action === 'LIST_MARKET') {
            toast(`Anunciado por ${Number(data.price_premium || 0)} 💎.`, 'success', 3200);
            setStatus('Sincronizado');
            containerDetailCache.invalidate();
            await reloadContainerPanelsOnly();
            if (isMarketPanelOpen()) await loadMarketListings();
            return;
        }

        if (['LOCK_ITEM', 'UNLOCK_ITEM', 'FAVORITE_ITEM', 'UNFAVORITE_ITEM', 'WISHLIST_ITEM', 'UNWISHLIST_ITEM'].includes(data.action)) {
            const messages = {
                LOCK_ITEM: 'Item travado.',
                UNLOCK_ITEM: 'Item destravado.',
                FAVORITE_ITEM: 'Item favoritado.',
                UNFAVORITE_ITEM: 'Favorito removido.',
                WISHLIST_ITEM: 'Item adicionado a wishlist.',
                UNWISHLIST_ITEM: 'Item removido da wishlist.',
            };
            toast(messages[data.action] || 'Item atualizado.', 'success', 2400);
            setStatus('Sincronizado');
            patchItemSafetyFlags(item.public_id, data.action);
            return;
        }

        if (data.action === 'OPEN') {
            toast(`Container aberto: ${data.container_name || data.container_definition_code}`, 'success', 3200);
            setStatus('Sincronizado');
            openContainer(data.container_public_id);
            containerDetailCache.invalidate(data.container_public_id);
            await mountContainerPanel(data.container_public_id);
            renderContainerDock();
            bindContainerLinks();
            highlightContainer(data.container_public_id);
            return;
        }

        if (action.code === 'EQUIP' || action.code === 'UNEQUIP' || data.action === 'EQUIP' || data.action === 'UNEQUIP') {
            toast(action.code === 'UNEQUIP' || data.action === 'UNEQUIP' ? 'Item desequipado.' : 'Item equipado.', 'success', 2200);
            setStatus('Sincronizado');
            invalidateItemActionsCache(item.public_id);
            containerDetailCache.invalidate();
            await refreshEquipmentOnly();
            await reloadContainerPanelsOnly().catch(() => null);
            return;
        }

        toast('Acao concluida.', 'success', 2600);
        setStatus('Sincronizado');
        containerDetailCache.invalidate();
        await reloadContainerPanelsOnly();
    } catch (error) {
        handleError(error, 'Acao rejeitada pelo servidor.');
        await reloadContainerPanelsOnly();
        if (action.code === 'EQUIP' || action.code === 'UNEQUIP') {
            await refreshEquipmentOnly().catch(() => null);
        }
    } finally {
        actionInFlight = false;
    }
}

function patchItemSafetyFlags(itemPublicId, actionCode) {
    invalidateItemActionsCache(itemPublicId);
    const entry = itemIndex.get(itemPublicId);
    if (!entry?.item) {
        void reloadContainerPanelsOnly();
        return;
    }

    const flags = entry.item.safety_flags || entry.item.flags || {};
    const next = { ...flags };
    if (actionCode === 'LOCK_ITEM') next.locked = true;
    if (actionCode === 'UNLOCK_ITEM') next.locked = false;
    if (actionCode === 'FAVORITE_ITEM') next.favorite = true;
    if (actionCode === 'UNFAVORITE_ITEM') next.favorite = false;
    if (actionCode === 'WISHLIST_ITEM') next.wishlist = true;
    if (actionCode === 'UNWISHLIST_ITEM') next.wishlist = false;

    entry.item.safety_flags = next;
    if (entry.item.placement && (actionCode === 'LOCK_ITEM' || actionCode === 'UNLOCK_ITEM')) {
        entry.item.placement.locked = Boolean(next.locked);
        const grid = grids.get(entry.container_public_id);
        const node = grid?.engine.nodes.find((candidate) => candidate.id === itemPublicId);
        if (node) {
            node.locked = Boolean(next.locked);
            node.noMove = Boolean(next.locked);
            if (next.locked) node.el?.setAttribute('gs-locked', 'true');
            else node.el?.removeAttribute('gs-locked');
        }
    }
    refreshItemWidget(itemPublicId);
}

function addItems(container, grid) {
    for (const item of container.items || []) {
        const placement = item.placement || {};
        const rotated = Boolean(placement.rotated);
        const size = dimensionsForState(item, rotated);

        itemIndex.set(item.public_id, {
            container_public_id: container.public_id,
            placement_version: Number(placement.placement_version || 1),
            quantity: Number(item.quantity || 1),
            definition_code: item.definition?.code || '',
            stackable: Boolean(item.definition?.stackable),
            max_stack: Number(item.definition?.max_stack || 1),
            quality_bucket: item.quality_bucket || null,
            grid_w: size.w,
            grid_h: size.h,
            rotated,
            linked_container: item.linked_container || null,
            item,
        });

        grid.addWidget({
            id: item.public_id,
            x: Number(placement.grid_x || 0),
            y: Number(placement.grid_y || 0),
            w: size.w,
            h: size.h,
            noResize: true,
            noMove: Boolean(placement.locked),
            locked: Boolean(placement.locked),
            content: renderItem(item),
        });

        const widget = grid.engine.nodes.find((node) => node.id === item.public_id)?.el;
        if (widget) {
            widget.dataset.inventoryFp = fingerprintInventoryItem(item, size);
        }
        bindItemWidget(container, item, widget);
    }
}

function consumeItemLocally(itemPublicId) {
    if (!itemPublicId) return null;
    const entry = itemIndex.get(itemPublicId);
    const containerPublicId = entry?.container_public_id || null;
    purgeItemWidgetFromAllGrids(itemPublicId);
    if (containerPublicId) {
        removeItemFromContainerIndex(itemPublicId, containerPublicId);
        scrubOrphanGridWidgets(containerPublicId);

        const cached = containerDetailCache.get(containerPublicId);
        if (cached?.container?.items) {
            containerDetailCache.set(
                containerPublicId,
                {
                    ...cached.container,
                    items: cached.container.items.filter((item) => item.public_id !== itemPublicId),
                },
                cached.summaryEntry
            );
        }
    }
    itemIndex.delete(itemPublicId);
    dragSnapshots.delete(itemPublicId);
    invalidateItemActionsCache(itemPublicId);
    return containerPublicId;
}

function patchTargetAfterSocket(targetItem, data) {
    const entry = itemIndex.get(targetItem?.public_id);
    if (!entry?.item) return;

    const effect = data?.applied_effect || {};
    const socketIndex = Number(data?.socket_index ?? 0);
    const sockets = Array.isArray(entry.item.sockets) ? entry.item.sockets.map((socket) => ({ ...socket })) : [];
    const socket = sockets.find((entrySocket) => Number(entrySocket.index) === socketIndex)
        || sockets.find((entrySocket) => entrySocket.status === 'empty' || !entrySocket.gem);

    if (socket) {
        socket.status = 'filled';
        socket.gem = {
            name: effect.property_name || targetItem.definition?.name || 'Gema',
            definition_code: data?.gem_code || '',
            rarity: 'uncommon',
        };
    }

    const properties = Array.isArray(entry.item.properties) ? [...entry.item.properties] : [];
    if (effect.property) {
        const source = effect.source || `socketed_gem_${socketIndex}`;
        const existingIdx = properties.findIndex((property) => String(property.source || '') === source);
        const nextProperty = {
            code: effect.property,
            name: effect.property_name || effect.property,
            value: effect.value,
            source,
        };
        if (existingIdx >= 0) properties[existingIdx] = { ...properties[existingIdx], ...nextProperty };
        else properties.push(nextProperty);
    }

    entry.item.sockets = sockets;
    entry.item.properties = properties;
    entry.placement_version = Number(entry.placement_version || 1) + 1;
    if (entry.item.placement) {
        entry.item.placement.placement_version = entry.placement_version;
    }
    refreshItemWidget(targetItem.public_id);
}

function patchTargetAfterEnhance(targetItem, jewelType, data) {
    const entry = itemIndex.get(targetItem?.public_id);
    if (!entry?.item) return;

    if (jewelType === 'bless' && data?.to_level != null) {
        const properties = Array.isArray(entry.item.properties) ? [...entry.item.properties] : [];
        const upgradeIdx = properties.findIndex((property) => String(property.code || '') === 'upgrade_level');
        const upgradeProperty = {
            code: 'upgrade_level',
            name: 'Nivel de melhoria',
            value: Number(data.to_level),
            integer_value: Number(data.to_level),
            source: 'upgrade',
        };
        if (upgradeIdx >= 0) properties[upgradeIdx] = { ...properties[upgradeIdx], ...upgradeProperty };
        else properties.push(upgradeProperty);

        for (const change of (Array.isArray(data.changed_properties) ? data.changed_properties : [])) {
            const idx = properties.findIndex((property) => String(property.code || '') === String(change.code || ''));
            if (idx < 0) continue;
            properties[idx] = {
                ...properties[idx],
                value: change.to,
                integer_value: change.to,
                numeric_value: change.to,
            };
        }
        entry.item.properties = properties;
    }

    if (jewelType === 'soul') {
        const affixes = Array.isArray(entry.item.affixes) ? [...entry.item.affixes] : [];
        if (data?.created_affix) {
            affixes.push(data.created_affix);
            entry.item.affixes = affixes;
        }
        for (const change of (Array.isArray(data.changed_affixes) ? data.changed_affixes : [])) {
            const idx = affixes.findIndex((affix) => String(affix.code || affix.property_code || '') === String(change.code || ''));
            if (idx < 0) continue;
            affixes[idx] = { ...affixes[idx], value: change.to };
        }
        entry.item.affixes = affixes;
    }

    if (jewelType === 'chaos') {
        if (data?.to_quality_bucket) {
            entry.item.quality_bucket = data.to_quality_bucket;
            entry.quality_bucket = data.to_quality_bucket;
        }
        if (Array.isArray(data.created_affixes) && data.created_affixes.length) {
            entry.item.affixes = [
                ...(Array.isArray(entry.item.affixes) ? entry.item.affixes : []),
                ...data.created_affixes,
            ];
        }
    }

    if (jewelType === 'reroll' && data?.created_affix) {
        const affixes = Array.isArray(entry.item.affixes) ? [...entry.item.affixes] : [];
        const removedCode = String(data.removed_affix?.code || data.removed_affix?.property_code || '');
        const next = affixes.filter((affix) => String(affix.code || affix.property_code || '') !== removedCode);
        next.push(data.created_affix);
        entry.item.affixes = next;
    }

    entry.placement_version = Number(entry.placement_version || 1) + 1;
    if (entry.item.placement) {
        entry.item.placement.placement_version = entry.placement_version;
    }
    refreshItemWidget(targetItem.public_id);
}

function softRefreshAfterEnhanceSocket(...containerPublicIds) {
    const unique = [...new Set(containerPublicIds.filter(Boolean))];
    for (const id of unique) {
        containerDetailCache.invalidate(id);
    }
    // Nao bloqueia a UI — revalida so os containers afetados.
    void Promise.all(unique.map((id) => resyncContainerPanel(id).catch(() => null)));
    void loadPlayerHudEarly().catch(() => null);
}

async function attemptEnhance(jewelItem, targetItem, jewelType) {
    const lockedIds = [jewelItem?.public_id, targetItem?.public_id].filter(Boolean);
    if (!inventoryMoveQueue.reserveMany(lockedIds)) {
        toast('Estes itens ainda estao sincronizando. Aguarde um instante.', 'info', 2600);
        return false;
    }

    try {
        const jewelLabel = jewelType === 'bless'
            ? 'Joia da Bencao'
            : jewelType === 'soul'
                ? 'Joia da Alma'
                : jewelType === 'chaos'
                    ? 'Joia do Caos'
                    : 'Joia de Rerrolagem';

        // Chaos/reroll precisam do preview (odds). Bless/soul confirmam na hora.
        let preview = null;
        if (jewelType === 'chaos' || jewelType === 'reroll') {
            setStatus('Avaliando melhoria...');
            const previewResponse = await apiFetch('/api/inventory/enhance/preview', {
                method: 'POST',
                body: {
                    jewel_item_public_id: jewelItem.public_id,
                    target_item_public_id: targetItem.public_id,
                },
            });
            preview = previewResponse.data || {};
            if (!preview.can_apply) {
                toast(preview.reason_message || 'Esta joia nao pode ser aplicada neste item.', 'error', 3600);
                setStatus('Sincronizado');
                return false;
            }
        }

        const lines = [`${jewelLabel} em ${itemLabel(targetItem)}`];
        if (preview && jewelType === 'chaos') {
            lines.push(`Raridade atual: ${preview.current_quality_bucket || 'common'}`);
            const outcomes = Array.isArray(preview.outcome_chances) ? preview.outcome_chances : [];
            for (const outcome of outcomes) {
                const tier = String(outcome.tier || '');
                const chance = Number(outcome.chance || 0).toFixed(1);
                lines.push(`${tier === 'failure' ? 'Falha instavel' : `Virar ${tier}`}: ${chance}%`);
            }
        } else if (preview) {
            const rate = Number(preview.success_rate || 0).toFixed(1);
            lines.push(`Chance de sucesso: ${rate}%`);
        } else {
            lines.push('A chance de sucesso sera aplicada pelo servidor.');
        }
        lines.push('A joia sera consumida independentemente do resultado.');

        const confirmed = await confirmInventoryAction({
            title: jewelLabel,
            bodyHtml: lines.map((line) => `<p>${escapeHtml(line)}</p>`).join(''),
            confirmLabel: 'Aplicar joia',
            tone: jewelType === 'chaos' ? 'danger' : 'warning',
        });
        if (!confirmed) {
            setStatus('Sincronizado');
            return false;
        }

        // Some a joia na hora — feedback imediato enquanto o POST roda.
        const jewelContainerId = itemIndex.get(jewelItem.public_id)?.container_public_id
            || dragSnapshots.get(jewelItem.public_id)?.container_public_id
            || null;
        const targetContainerId = itemIndex.get(targetItem.public_id)?.container_public_id || null;
        const jewelPlacementVersion = Number(
            itemIndex.get(jewelItem.public_id)?.placement_version
            ?? jewelItem.placement?.placement_version
            ?? 1
        );
        const targetPlacementVersion = Number(
            itemIndex.get(targetItem.public_id)?.placement_version
            ?? targetItem.placement?.placement_version
            ?? 1
        );
        consumeItemLocally(jewelItem.public_id);

        setStatus('Aplicando joia...');
        const result = await apiFetch('/api/inventory/enhance', {
            method: 'POST',
            body: {
                jewel_item_public_id: jewelItem.public_id,
                target_item_public_id: targetItem.public_id,
                expected_jewel_placement_version: jewelPlacementVersion,
                expected_target_placement_version: targetPlacementVersion,
                confirm: true,
            },
        });

        const data = result.data || {};
        setStatus('Sincronizado');
        patchTargetAfterEnhance(targetItem, jewelType, data);
        showEnhanceResultModal(jewelType, targetItem, data);
        softRefreshAfterEnhanceSocket(jewelContainerId, targetContainerId);
        return true;
    } catch (error) {
        handleError(error, 'Melhoria rejeitada pelo servidor.');
        softRefreshAfterEnhanceSocket(
            itemIndex.get(jewelItem.public_id)?.container_public_id,
            itemIndex.get(targetItem.public_id)?.container_public_id
        );
        return false;
    } finally {
        inventoryMoveQueue.releaseMany(lockedIds);
        clearActiveDrag();
    }
}

function localGemEffect(gemItem) {
    const properties = Array.isArray(gemItem?.properties) ? gemItem.properties : [];
    const preferred = properties.find((property) => BASE_STAT_CODES.includes(String(property.code || '')))
        || properties.find((property) => String(property.source || '') === 'definition' || String(property.source || '') === 'base')
        || properties[0];
    if (!preferred) return null;

    return {
        property: String(preferred.code || ''),
        property_name: String(preferred.name || preferred.code || 'Atributo'),
        value: formatItemPropertyValue(preferred),
    };
}

async function attemptSocket(gemItem, targetItem) {
    const lockedIds = [gemItem?.public_id, targetItem?.public_id].filter(Boolean);
    if (!inventoryMoveQueue.reserveMany(lockedIds)) {
        toast('Estes itens ainda estao sincronizando. Aguarde um instante.', 'info', 2600);
        return false;
    }

    try {
        const emptyCount = (Array.isArray(targetItem?.sockets) ? targetItem.sockets : [])
            .filter((socket) => socket.status === 'empty' || !socket.gem).length;
        const localEffect = localGemEffect(gemItem);
        let preview = null;
        try {
            preview = await previewSocketPlan(gemItem, targetItem);
        } catch {
            preview = null;
        }
        const previewEffect = preview?.gem_effect || preview?.applied_effect || null;
        const effectLabel = previewEffect
            ? `+${previewEffect.value} ${previewEffect.property_name || previewEffect.property}`
            : (localEffect ? `+${localEffect.value} ${localEffect.property_name}` : itemLabel(gemItem));
        const powerHint = preview?.power_delta != null
            ? `Poder estimado: ${preview.power_delta > 0 ? '+' : ''}${preview.power_delta}`
            : null;
        const lines = [
            `Encaixar gema em ${itemLabel(targetItem)}`,
            `Efeito: ${effectLabel}`,
            `Engastes livres: ${Math.max(1, emptyCount)}`,
            powerHint,
            'A gema sera consumida ao confirmar.',
        ].filter(Boolean);

        const confirmed = await confirmInventoryAction({
            title: 'Encaixar gema',
            bodyHtml: lines.map((line) => `<p>${escapeHtml(line)}</p>`).join(''),
            confirmLabel: 'Encaixar',
            tone: 'warning',
        });
        if (!confirmed) {
            setStatus('Sincronizado');
            return false;
        }

        const gemContainerId = itemIndex.get(gemItem.public_id)?.container_public_id
            || dragSnapshots.get(gemItem.public_id)?.container_public_id
            || null;
        const targetContainerId = itemIndex.get(targetItem.public_id)?.container_public_id || null;
        const gemPlacementVersion = Number(
            itemIndex.get(gemItem.public_id)?.placement_version
            ?? gemItem.placement?.placement_version
            ?? 1
        );
        const targetPlacementVersion = Number(
            itemIndex.get(targetItem.public_id)?.placement_version
            ?? targetItem.placement?.placement_version
            ?? 1
        );

        // Some a gema na hora.
        consumeItemLocally(gemItem.public_id);

        setStatus('Encaixando gema...');
        const result = await apiFetch('/api/inventory/socket', {
            method: 'POST',
            body: {
                gem_item_public_id: gemItem.public_id,
                target_item_public_id: targetItem.public_id,
                expected_gem_placement_version: gemPlacementVersion,
                expected_target_placement_version: targetPlacementVersion,
                confirm: true,
            },
        });

        const data = result.data || {};
        setStatus('Sincronizado');
        patchTargetAfterSocket(targetItem, data);
        showSocketResultModal(targetItem, data);
        softRefreshAfterEnhanceSocket(gemContainerId, targetContainerId);
        return true;
    } catch (error) {
        handleError(error, 'Engaste rejeitado pelo servidor.');
        softRefreshAfterEnhanceSocket(
            itemIndex.get(gemItem.public_id)?.container_public_id,
            itemIndex.get(targetItem.public_id)?.container_public_id
        );
        return false;
    } finally {
        inventoryMoveQueue.releaseMany(lockedIds);
        clearActiveDrag();
    }
}

async function attemptMerge(sourceItem, targetItem) {
    const quantity = mergeQuantityForItems(sourceItem, targetItem);
    if (quantity <= 0) return false;

    const lockedIds = [sourceItem?.public_id, targetItem?.public_id].filter(Boolean);
    if (!inventoryMoveQueue.reserveMany(lockedIds)) {
        toast('Estes itens ainda estao sincronizando. Aguarde um instante.', 'info', 2600);
        revertItem(sourceItem.public_id);
        clearActiveDrag();
        return false;
    }

    silent = true;

    try {
        setStatus('Mesclando...');
        const response = await apiFetch('/api/inventory/stacks/merge', {
            method: 'POST',
            body: {
                source_item_public_id: sourceItem.public_id,
                target_item_public_id: targetItem.public_id,
                quantity,
                expected_source_placement_version: Number(
                    itemIndex.get(sourceItem.public_id)?.placement_version
                    ?? sourceItem.placement?.placement_version
                    ?? 0
                ),
                expected_target_placement_version: Number(
                    itemIndex.get(targetItem.public_id)?.placement_version
                    ?? targetItem.placement?.placement_version
                    ?? 0
                ),
            },
        });

        toast('Stacks mesclados.', 'success', 2400);
        setStatus('Sincronizado');
        dragSnapshots.delete(sourceItem.public_id);
        if (!patchStackMerge(sourceItem, targetItem, response.data || {})) {
            await reloadContainerPanelsOnly();
        }
        return true;
    } catch (error) {
        handleError(error, 'Merge rejeitado pelo servidor.');
        revertItem(sourceItem.public_id);
        await resyncContainerPanel(itemIndex.get(sourceItem.public_id)?.container_public_id);
        return false;
    } finally {
        inventoryMoveQueue.releaseMany(lockedIds);
        silent = false;
        clearActiveDrag();
    }
}

async function quickSplit(containerPublicId, item) {
    if (loading) return;

    const splitQuantity = splitQuantityForItem(item);
    if (splitQuantity <= 0) {
        toast('Este item nao pode ser dividido.', 'error', 2800);
        return;
    }

    const current = itemIndex.get(item.public_id);
    const size = dimensionsForState(item, Boolean(current?.rotated));
    const slot = findFirstFreeSlot(containerPublicId, size.w, size.h);
    if (!slot) {
        toast('Sem espaco livre para dividir a stack.', 'error', 3200);
        return;
    }

    if (!current) return;
    if (!inventoryMoveQueue.reserve(item.public_id)) {
        toast('Este item ainda esta sincronizando. Aguarde um instante.', 'info', 2600);
        return;
    }

    silent = true;

    try {
        setStatus('Dividindo...');
        await apiFetch('/api/inventory/stacks/split', {
            method: 'POST',
            body: {
                source_item_public_id: item.public_id,
                source_container_public_id: containerPublicId,
                target_container_public_id: containerPublicId,
                quantity: splitQuantity,
                grid_x: slot.grid_x,
                grid_y: slot.grid_y,
                expected_placement_version: current.placement_version,
            },
        });

        toast(`Stack dividida (${splitQuantity}).`, 'success', 2600);
        setStatus('Sincronizado');
        containerDetailCache.invalidate(containerPublicId);
        await resyncContainerPanel(containerPublicId);
    } catch (error) {
        handleError(error, 'Split rejeitado pelo servidor.');
        await resyncContainerPanel(containerPublicId);
    } finally {
        inventoryMoveQueue.release(item.public_id);
        silent = false;
    }
}

function captureMoveRollback(source, interaction) {
    const itemPublicId = interaction.item_public_id;
    const snapshot = dragSnapshots.get(itemPublicId);
    const entry = itemIndex.get(itemPublicId) || source;
    const placement = entry?.item?.placement || {};

    return {
        item_public_id: itemPublicId,
        container_public_id: snapshot?.container_public_id
            || interaction.source_container_public_id
            || entry?.container_public_id
            || source.container_public_id,
        grid_x: Number(snapshot?.grid_x ?? placement.grid_x ?? 0),
        grid_y: Number(snapshot?.grid_y ?? placement.grid_y ?? 0),
        grid_w: Number(snapshot?.grid_w ?? entry?.grid_w ?? placement.grid_w ?? 1),
        grid_h: Number(snapshot?.grid_h ?? entry?.grid_h ?? placement.grid_h ?? 1),
        rotated: Boolean(snapshot?.rotated ?? entry?.rotated ?? placement.rotated),
        placement_version: Number(entry?.placement_version ?? source.placement_version ?? 0),
        target_container_public_id: interaction.target_container_public_id,
    };
}

function applyMoveServerConfirmation(itemPublicId, interaction, moveResult = {}) {
    const entry = itemIndex.get(itemPublicId);
    if (!entry) return;

    const placementVersion = Number(moveResult.placement_version ?? entry.placement_version);
    const gridX = Number(moveResult.grid_x ?? interaction.grid_x);
    const gridY = Number(moveResult.grid_y ?? interaction.grid_y);
    const gridW = Number(moveResult.grid_w ?? interaction.grid_w ?? entry.grid_w);
    const gridH = Number(moveResult.grid_h ?? interaction.grid_h ?? entry.grid_h);
    const rotated = Boolean(moveResult.rotated ?? interaction.rotated ?? entry.rotated);

    entry.placement_version = placementVersion;
    entry.rotated = rotated;
    entry.grid_w = gridW;
    entry.grid_h = gridH;
    entry.container_public_id = interaction.target_container_public_id || entry.container_public_id;

    if (entry.item) {
        entry.item.placement = {
            ...(entry.item.placement || {}),
            grid_x: gridX,
            grid_y: gridY,
            grid_w: gridW,
            grid_h: gridH,
            rotated,
            placement_version: placementVersion,
        };
    }

    const serverAdjusted = moveResult.grid_x != null
        && (
            Number(moveResult.grid_x) !== Number(interaction.grid_x)
            || Number(moveResult.grid_y) !== Number(interaction.grid_y)
            || Number(moveResult.grid_w ?? gridW) !== Number(interaction.grid_w ?? gridW)
            || Number(moveResult.grid_h ?? gridH) !== Number(interaction.grid_h ?? gridH)
        );

    if (serverAdjusted) {
        const targetGrid = grids.get(entry.container_public_id);
        const node = targetGrid?.engine.nodes.find((candidate) => candidate.id === itemPublicId);
        if (isGridNodeLive(node)) {
            silent = true;
            try {
                targetGrid.update(node.el, {
                    x: gridX,
                    y: gridY,
                    w: gridW,
                    h: gridH,
                });
            } finally {
                silent = false;
            }
        }
    }
}

async function rollbackOptimisticMove(rollback) {
    if (!rollback?.item_public_id) return;

    const itemPublicId = rollback.item_public_id;
    const entry = itemIndex.get(itemPublicId);
    const restoreContainer = rollback.container_public_id;
    const currentContainer = entry?.container_public_id || rollback.target_container_public_id || restoreContainer;

    if (!entry) {
        await resyncContainerPanel(restoreContainer).catch(() => loadInventory());
        if (currentContainer && currentContainer !== restoreContainer) {
            await resyncContainerPanel(currentContainer).catch(() => null);
        }
        return;
    }

    entry.container_public_id = restoreContainer;
    entry.placement_version = Number(rollback.placement_version);
    entry.rotated = Boolean(rollback.rotated);
    entry.grid_w = Number(rollback.grid_w);
    entry.grid_h = Number(rollback.grid_h);

    if (entry.item) {
        entry.item.placement = {
            ...(entry.item.placement || {}),
            grid_x: Number(rollback.grid_x),
            grid_y: Number(rollback.grid_y),
            grid_w: Number(rollback.grid_w),
            grid_h: Number(rollback.grid_h),
            rotated: Boolean(rollback.rotated),
            placement_version: Number(rollback.placement_version),
        };
        syncContainerItemPlacement(currentContainer, restoreContainer, itemPublicId, entry.item);
    }

    const restoreGrid = grids.get(restoreContainer);
    silent = true;
    try {
        if (currentContainer !== restoreContainer) {
            purgeItemWidgetFromAllGrids(itemPublicId);
            if (restoreGrid) {
                const container = containerIndex.get(restoreContainer);
                restoreGrid.addWidget({
                    id: itemPublicId,
                    x: Number(rollback.grid_x),
                    y: Number(rollback.grid_y),
                    w: Number(rollback.grid_w),
                    h: Number(rollback.grid_h),
                    noResize: true,
                    noMove: Boolean(entry.item?.placement?.locked),
                    locked: Boolean(entry.item?.placement?.locked),
                    content: renderItem(entry.item),
                });
                const widget = restoreGrid.engine.nodes.find((candidate) => candidate.id === itemPublicId)?.el;
                if (container && entry.item) {
                    bindItemWidget(container, entry.item, widget);
                }
                reconcileContainerGrid(restoreContainer);
                if (grids.has(currentContainer)) {
                    reconcileContainerGrid(currentContainer);
                }
            }
        } else if (restoreGrid) {
            const node = restoreGrid.engine.nodes.find((candidate) => candidate.id === itemPublicId);
            if (isGridNodeLive(node)) {
                restoreGrid.update(node.el, {
                    x: Number(rollback.grid_x),
                    y: Number(rollback.grid_y),
                    w: Number(rollback.grid_w),
                    h: Number(rollback.grid_h),
                });
            } else {
                await resyncContainerPanel(restoreContainer);
            }
        }
    } finally {
        silent = false;
    }

    updateContainerSummaryEntry(restoreContainer);
    if (currentContainer && currentContainer !== restoreContainer) {
        updateContainerSummaryEntry(currentContainer);
        refreshLinkedContainerSourceItem(restoreContainer);
        refreshLinkedContainerSourceItem(currentContainer);
    } else {
        updateContainerOccupancyBadge(restoreContainer);
    }

    dragSnapshots.delete(itemPublicId);
    applyInventoryFilters();
    scrubOrphanGridWidgets(restoreContainer);
    if (currentContainer && currentContainer !== restoreContainer) {
        scrubOrphanGridWidgets(currentContainer);
    }
}

async function attemptMove(source, interaction) {
    const itemPublicId = interaction.item_public_id;
    if (!itemPublicId) return false;

    const movingItem = itemIndex.get(itemPublicId)?.item || null;
    if (
        movingItem
        && interaction.target_container_public_id
        && isItemMovingIntoOwnContainer(movingItem, interaction.target_container_public_id)
    ) {
        toast('Nao e possivel colocar um container dentro dele mesmo.', 'info', 2800);
        playInventoryFeedback('invalid');
        revertItem(itemPublicId);
        clearActiveDrag();
        return false;
    }

    const alreadyReserved = inventoryMoveQueue.isItemPending(itemPublicId);
    if (!alreadyReserved && !inventoryMoveQueue.reserve(itemPublicId)) {
        revertItem(itemPublicId);
        toast('Muitos movimentos ao mesmo tempo. Aguarde a fila.', 'info', 2800);
        clearActiveDrag();
        return false;
    }

    const expectedVersion = Number(source.placement_version);
    const rollback = captureMoveRollback(source, interaction);
    moveRollbacks.set(itemPublicId, rollback);

    // Commit otimista imediato: itemIndex/HUD atualizam antes do POST.
    if (!patchItemPlacement(interaction, { placement_version: expectedVersion })) {
        inventoryMoveQueue.release(itemPublicId);
        moveRollbacks.delete(itemPublicId);
        await reloadContainerPanelsOnly();
        clearActiveDrag();
        return false;
    }

    clearActiveDrag();
    setStatus('Salvando...');
    const moveTimer = inventoryUxTelemetry.start('move', {
        item_public_id: itemPublicId,
        target_container_public_id: interaction.target_container_public_id,
    });

    try {
        await inventoryMoveQueue.enqueue(itemPublicId, async () => {
            const response = await apiFetch('/api/inventory/move', {
                method: 'POST',
                body: {
                    item_public_id: itemPublicId,
                    source_container_public_id: rollback.container_public_id,
                    target_container_public_id: interaction.target_container_public_id,
                    grid_x: interaction.grid_x,
                    grid_y: interaction.grid_y,
                    rotated: interaction.rotated,
                    expected_placement_version: expectedVersion,
                },
            });

            applyMoveServerConfirmation(itemPublicId, interaction, response.data || {});
            return true;
        });

        moveRollbacks.delete(itemPublicId);
        containerDetailCache.invalidate(rollback.container_public_id);
        if (interaction.target_container_public_id !== rollback.container_public_id) {
            containerDetailCache.invalidate(interaction.target_container_public_id);
        }
        snapshotContainerDetailToCache(interaction.target_container_public_id);
        if (rollback.container_public_id !== interaction.target_container_public_id) {
            snapshotContainerDetailToCache(rollback.container_public_id);
        }
        scrubOrphanGridWidgets(rollback.container_public_id);
        if (interaction.target_container_public_id !== rollback.container_public_id) {
            scrubOrphanGridWidgets(interaction.target_container_public_id);
        }

        moveTimer.end({ ok: true });
        setStatus('Sincronizado');
        playInventoryFeedback('valid');
        return true;
    } catch (error) {
        moveRollbacks.delete(itemPublicId);
        inventoryMoveQueue.release(itemPublicId);
        moveTimer.end({ ok: false });

        if (error instanceof Error && (
            error.message === 'Item ainda sincronizando.'
            || error.message === 'Fila de movimentos cheia.'
            || error.message === 'Movimento invalido.'
        )) {
            await rollbackOptimisticMove(rollback);
            toast(error.message, 'info', 2600);
            playInventoryFeedback('invalid');
            return false;
        }

        const overlapMessage = error instanceof ApiError
            && String(error.message || '').toLowerCase().includes('overlaps another item');
        handleError(error, overlapMessage
            ? 'Posicao ocupada por outro item.'
            : 'Movimento rejeitado pelo servidor.');
        playInventoryFeedback('invalid');

        await rollbackOptimisticMove(rollback);
        scrubOrphanGridWidgets(interaction.target_container_public_id);
        if (rollback.container_public_id) {
            scrubOrphanGridWidgets(rollback.container_public_id);
        }
        dedupeItemWidgets(itemPublicId);
        await resyncContainerPanel(interaction.target_container_public_id).catch(() => null);
        if (rollback.container_public_id && rollback.container_public_id !== interaction.target_container_public_id) {
            await resyncContainerPanel(rollback.container_public_id).catch(() => null);
        }
        return false;
    }
}

async function loadPlayerHudEarly() {
    try {
        const response = await apiFetch('/api/player/hud');
        const hud = response.data || null;
        if (Array.isArray(hud?.wallets)) {
            playerWallets = hud.wallets;
        }
        renderPlayerHud(hud);
        renderSummary(null, playerWallets);
    } catch (_error) {
        // Inventario completo ainda atualiza o HUD depois.
    }
}

async function loadInventory(options = {}) {
    if (loading || !app) return;
    if (activeDrag) {
        inventoryReloadQueued = true;
        return;
    }
    const skipExplorationRender = Boolean(options?.skipExplorationRender);

    loading = true;
    silent = true;
    activeDrag = null;
    closeContextMenu();
    setStatus('Carregando...');

    try {
        const [response, summaryResponse] = await Promise.all([
            apiFetch('/api/inventory'),
            apiFetch('/api/inventory/summary').catch(() => null),
        ]);
        const containers = response.data?.containers || [];
        allContainersCache = containers;
        for (const container of containers) {
            if (container?.public_id) {
                containerDetailCache.set(
                    container.public_id,
                    container,
                    (summaryResponse?.data?.containers || []).find((entry) => entry.public_id === container.public_id) || null
                );
            }
        }
        const equipment = response.data?.equipment || [];
        const equipmentLinks = response.data?.equipment_links || [];
        const activeSetBonuses = response.data?.active_set_bonuses || [];
        currentEquipment = equipment;
        currentEquipmentLinks = equipmentLinks;
        currentSetBonuses = activeSetBonuses;
        equippedBackpackPublicId = equipment.find((slot) => slot.code === 'backpack' && slot.item)?.item?.public_id || null;
        if (!equippedBackpackPublicId) {
            expeditionCarryOpen = false;
        }
        const characterStats = response.data?.character_stats || [];
        playerPower = response.data?.player_power || null;
        playerWallets = response.data?.wallets || [];
        renderPlayerHud(response.data?.player_hud || null);
        if (isMarketPanelOpen()) renderMarketWallets();
        inventorySummaryByPublicId = new Map(
            (summaryResponse?.data?.containers || []).map((entry) => [entry.public_id, entry])
        );
        const summaryByPublicId = inventorySummaryByPublicId;

        destroyGrids();
        containerRoot.textContent = '';
        expeditionRoot?.replaceChildren();
        renderEquipment(equipment, characterStats, equipmentLinks, activeSetBonuses);
        renderContainerDock(containers);
        renderSummary(summaryResponse?.data || null, playerWallets);
        syncDrawerUi();

        for (const container of containers) {
            containerIndex.set(container.public_id, container);
            upsertContainerCache(container);
        }

        const expeditionContainer = containers.find((container) => containerKind(container) === 'expedition_carry');
        if (expeditionContainer && expeditionCarryOpen && equippedBackpackPublicId) {
            renderExpeditionDrawerSection(
                expeditionContainer,
                summaryByPublicId.get(expeditionContainer.public_id) || null
            );
        } else {
            destroyExpeditionGrid();
            expeditionRoot?.replaceChildren();
        }
        syncDrawerUi();

        const visibleContainers = containers.filter(isRightDrawerContainerVisible);

        if (!visibleContainers.length) {
            containerRoot.innerHTML = '<div class="inventory-empty">Nenhum container encontrado.</div>';
            setStatus('Vazio');
            return;
        }

        containerRoot.appendChild(renderInventoryFilterToolbar(visibleContainers));

        const splitParent = splitViewState?.parentPublicId
            ? containers.find((container) => container.public_id === splitViewState.parentPublicId)
            : null;
        const splitChild = splitViewState?.childPublicId
            ? containers.find((container) => container.public_id === splitViewState.childPublicId)
            : null;

        if (splitParent && splitChild && isRightDrawerContainerVisible(splitChild)) {
            const splitSection = renderSplitLayout(splitParent, splitChild, summaryByPublicId);
            containerRoot.appendChild(splitSection);

            for (const container of [splitParent, splitChild]) {
                const section = splitSection.querySelector(`[data-container-public-id="${container.public_id}"]`);
                const gridNode = section?.querySelector('.inventory-grid');
                if (!gridNode) continue;
                const grid = initializeGrid(container, gridNode);
                addItems(container, grid);
            }
        } else {
            if (splitViewState) {
                clearSplitView();
            }

            for (const container of visibleContainers) {
                containerIndex.set(container.public_id, container);
                const section = renderContainer(container, summaryByPublicId.get(container.public_id) || null);
                containerRoot.appendChild(section);
                const gridNode = section.querySelector('.inventory-grid');
                const grid = initializeGrid(container, gridNode);
                addItems(container, grid);
            }
        }

        bindContainerLinks();
        applyInventoryFilters();
        if (comparePanelState?.item?.public_id) {
            const refreshed = itemIndex.get(comparePanelState.item.public_id)?.item;
            if (refreshed) {
                comparePanelState.item = refreshed;
                renderComparePanel();
            } else {
                closeComparePanel();
            }
        }
        // Bags flutuantes: soft-refresh das abertas, ou restaura do localStorage apos boot.
        const openFloatingIds = getOpenFloatingContainerIds();
        if (openFloatingIds.length > 0) {
            await Promise.all(openFloatingIds.map((id) => softRefreshFloatingBagWindow(id).catch(() => null)));
        } else if (!options.skipFloatingRestore) {
            await restoreFloatingBagWindows();
        }
        setStatus('Sincronizado');
        if (craftPanelOpen) {
            renderCraftPanel();
            refreshCraftPreview();
        }
        if (isExplorationPanelOpen() && !skipExplorationRender) {
            renderExplorationPanel();
        }
    } catch (error) {
        handleError(error, 'Nao foi possivel carregar o inventario.');
    } finally {
        silent = false;
        loading = false;
    }
}

function handleError(error, fallback) {
    if (error instanceof ApiError) {
        toast(error.message || fallback, 'error', 4200);
        setStatus(`Erro ${error.status}`);
        return;
    }

    toast(fallback, 'error', 4200);
    setStatus('Erro');
}

document.addEventListener('keydown', (event) => {
    if (!activeDrag) return;
    if (event.key === 'r' || event.key === 'R' || event.key === 'q' || event.key === 'Q') {
        event.preventDefault();
        rotateDraggedItem();
    }
});

document.addEventListener('keydown', (event) => {
    const target = event.target;
    const isTyping = target instanceof HTMLElement
        && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);

    if (event.key === 'Escape') {
        const menu = document.querySelector('[data-inventory-context-menu]');
        if (menu && !menu.hidden) {
            closeContextMenu();
            return;
        }
        const filterModal = document.querySelector('[data-inventory-filter-modal]');
        if (filterModal && !filterModal.hidden) {
            closeInventoryFilterModal();
            return;
        }
        if (comparePickState) {
            clearComparePick();
            toast('Comparacao cancelada.', 'info', 1800);
            return;
        }
        if (comparePanelState) {
            closeComparePanel();
            return;
        }
        const focusedFloating = document.querySelector('.inventory-floating-bag.is-focused');
        if (focusedFloating?.dataset?.floatingBagWindow) {
            event.preventDefault();
            closeFloatingBagWindow(focusedFloating.dataset.floatingBagWindow);
            return;
        }
        if (getOpenFloatingContainerIds().length > 0) {
            event.preventDefault();
            const lastId = getOpenFloatingContainerIds().at(-1);
            if (lastId) closeFloatingBagWindow(lastId);
            return;
        }
        const renameModal = document.querySelector('[data-inventory-rename-modal]');
        if (renameModal && !renameModal.hidden) {
            closeRenameModal();
            return;
        }
        if (selectedItemPublicIds.size > 0) {
            event.preventDefault();
            clearInventorySelection();
            return;
        }
        if (!isTyping) {
            event.preventDefault();
            closeActiveDrawer();
        }
        return;
    }

    if (isTyping || event.ctrlKey || event.metaKey || event.altKey) return;

    if (event.key === 'i' || event.key === 'I') {
        event.preventDefault();
        toggleRightDrawer();
    }
    if (event.key === 'e' || event.key === 'E') {
        event.preventDefault();
        toggleLeftDrawer();
    }
    if (event.key === 'b' || event.key === 'B') {
        event.preventDefault();
        if (!leftDrawerOpen) openLeftDrawer();
        toggleExpeditionBag();
    }
    if (event.key === 'Tab') {
        event.preventDefault();
        alternateDrawerFocus();
    }
    if (event.key === 'c' || event.key === 'C') {
        event.preventDefault();
        toggleStatsDrawer();
    }
    if (event.key === 'm' || event.key === 'M') {
        event.preventDefault();
        toggleMarketPanel();
    }
    if (event.key === 'j' || event.key === 'J') {
        event.preventDefault();
        toggleMissionsPanel();
    }
    if (event.key === 'f' || event.key === 'F') {
        event.preventDefault();
        toggleCraftPanel();
    }
    if (event.key === 's' || event.key === 'S') {
        event.preventDefault();
        toggleSetCodexPanel();
    }
    if (event.key === 'x' || event.key === 'X') {
        event.preventDefault();
        window.location.href = '/campaign';
    }
    if (['1', '2', '3', '4', '5', '6', '7'].includes(event.key)) {
        event.preventDefault();
        useDockHotbarSlot(Number(event.key));
    }
});

document.addEventListener('click', (event) => {
    const menu = document.querySelector('[data-inventory-context-menu]');
    if (!menu || menu.hidden) return;
    if (event.target instanceof Node && menu.contains(event.target)) return;
    closeContextMenu();
});

function syncChromeInsets() {
    const rail = document.querySelector('.game-hud-rail');
    const dock = document.querySelector('.game-dock');
    const root = document.documentElement;
    if (rail) {
        root.style.setProperty('--game-chrome-top', `${Math.max(48, Math.ceil(rail.getBoundingClientRect().height))}px`);
    }
    if (dock) {
        root.style.setProperty('--game-chrome-bottom', `${Math.max(88, Math.ceil(dock.getBoundingClientRect().height))}px`);
    }
}

function bindChromeInsets() {
    syncChromeInsets();
    window.addEventListener('resize', syncChromeInsets);
    if (typeof ResizeObserver === 'function') {
        const observer = new ResizeObserver(() => syncChromeInsets());
        const rail = document.querySelector('.game-hud-rail');
        const dock = document.querySelector('.game-dock');
        if (rail) observer.observe(rail);
        if (dock) observer.observe(dock);
    }
}

initDrawerControls();
initMarketControls();
initExplorationControls();
syncDrawerUi();
bindChromeInsets();
observeAllItemRarityFx();
bindInventoryHeaderFilterButton();
renderDockHotbar([]);
loadPlayerHudEarly().finally(() => syncChromeInsets());
loadInventory();
window.setTimeout(() => maybeShowLayoutTutorial(), 900);
console.info('[inventory] drag engine', INVENTORY_DRAG_ENGINE);
