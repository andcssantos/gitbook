import { ApiError, apiFetch } from '/assets/framework/api.js';
import { openModal, installModalStyles } from '/assets/framework/modal.js';
import { installToastStyles, toast } from '/assets/framework/toast.js';

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
const refreshButton = document.querySelector('[data-inventory-refresh]');
const compareDockRoot = document.querySelector('[data-inventory-compare]');
const marketPanelRoot = document.querySelector('[data-inventory-market]');
const marketListingsRoot = document.querySelector('[data-market-listings]');
const marketWalletsRoot = document.querySelector('[data-market-wallets]');
const materialsPanelRoot = document.querySelector('[data-inventory-materials]');
const materialsTabsRoot = document.querySelector('[data-materials-tabs]');
const materialsListRoot = document.querySelector('[data-materials-list]');
const craftPanelRoot = document.querySelector('[data-inventory-craft]');

installToastStyles();
installModalStyles();
installInventoryModalStyles();

function installInventoryModalStyles() {
    if (document.getElementById('inventory-modal-styles')) return;

    const style = document.createElement('style');
    style.id = 'inventory-modal-styles';
    style.textContent = `
.inventory-modal { display: grid; gap: 14px; }
.inventory-modal h3 { margin: 0; font-size: 1.15rem; }
.inventory-modal-body { display: grid; gap: 8px; color: #334155; line-height: 1.45; }
.inventory-modal-body p { margin: 0; }
.inventory-modal-body ul { margin: 0; padding-left: 18px; }
.inventory-modal-action {
    justify-self: start;
    border: 0;
    border-radius: 8px;
    padding: 10px 16px;
    font-weight: 600;
    cursor: pointer;
}
.inventory-modal--success h3 { color: #166534; }
.inventory-modal--success .inventory-modal-action { background: #22c55e; color: #052e16; }
.inventory-modal--warning h3 { color: #b45309; }
.inventory-modal--warning .inventory-modal-action { background: #f59e0b; color: #451a03; }
.inventory-modal--danger h3 { color: #fecaca; }
.inventory-modal--danger .inventory-modal-confirm { background: #ef4444; color: #fff7ed; }
.inventory-modal--info h3 { color: #93c5fd; }
.inventory-modal--info .inventory-modal-action { background: #3b82f6; color: #eff6ff; }
.inventory-modal--confirm .inventory-modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
}
.inventory-modal-cancel,
.inventory-modal-confirm {
    border: 0;
    border-radius: 8px;
    padding: 10px 16px;
    font-weight: 700;
    cursor: pointer;
}
.inventory-modal-cancel {
    background: rgba(148, 163, 184, .16);
    color: #e2e8f0;
}
.inventory-modal-confirm {
    background: #f59e0b;
    color: #451a03;
}
.inventory-modal--confirm h3 { color: #f8fafc; }
.inventory-modal--confirm .inventory-modal-body { color: #cbd5e1; }
.gb-modal { background: #0f172a; color: #f8fafc; border: 1px solid rgba(148, 163, 184, .28); box-shadow: 0 24px 80px rgba(0,0,0,.55); }
.gb-modal-overlay { background: rgba(2, 6, 23, .72); }
`;
    document.head.appendChild(style);
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

let grids = new Map();
let itemIndex = new Map();
let containerIndex = new Map();
let dragSnapshots = new Map();
let activeDrag = null;
let silent = false;
let loading = false;
let actionInFlight = false;
let contextMenuState = null;
let openContainerPublicIds = new Set(JSON.parse(localStorage.getItem('evolvaxe.inventory.openContainers') || '[]'));
let marketDeliveryOpen = localStorage.getItem('evolvaxe.inventory.marketDeliveryOpen') === '1';
let marketPanelOpen = false;
let materialsPanelOpen = false;
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
let craftPickerMaterialTab = 'metals';
let craftPickerQuery = '';
let craftActiveSlotIndex = null;
let playerWallets = [];
let marketListings = [];
let marketFilters = {
    q: '',
    quality_bucket: '',
    category_code: '',
    min_price: '',
    max_price: '',
};
let marketLoading = false;
let expeditionCarryOpen = true;
let leftDrawerOpen = localStorage.getItem('evolvaxe.inventory.leftDrawer') === '1';
let rightDrawerOpen = localStorage.getItem('evolvaxe.inventory.rightDrawer') !== '0';
let statsDrawerOpen = localStorage.getItem('evolvaxe.inventory.statsDrawer') === '1';
let leftDrawerTab = localStorage.getItem('evolvaxe.inventory.leftDrawerTab') || 'equipment';
let focusedDrawer = localStorage.getItem('evolvaxe.inventory.focusedDrawer') || 'right';
let lastCharacterStats = [];
let equippedBackpackPublicId = null;
let playerPower = null;
let currentEquipment = [];
let currentEquipmentLinks = [];
let currentSetBonuses = [];
let comparePanelState = null;
let splitViewState = JSON.parse(localStorage.getItem('evolvaxe.inventory.splitView') || 'null');
let inventorySummaryByPublicId = new Map();

const CELL_SIZE = 44;
const gridCellSizes = new Map();
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
const EQUIPMENT_SLOT_ICON_FILES = {
    weapon: 'main_weapon.png',
    offhand: 'offhand.png',
    weapon_offhand: 'offhand.png',
    shield: 'offhand.png',
    quiver: 'offhand.png',
    helmet: 'healme.png',
    chest: 'bagpack.png',
    pants: 'pants.png',
    boots: 'boots.png',
    gloves: 'gloves.png',
    ring: 'ring_1.png',
    ring_2: 'ring_2.png',
    amulet: 'pendante.png',
    earring: 'neacles.png',
    belt: 'neacles.png',
    wings: 'wings.png',
    pet: 'pet.png',
    backpack: 'bagpack.png',
};

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

    return `/assets/game/items/${code}.png`;
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

    return `
        <span class="inventory-tooltip-socket is-filled" title="${effectLabel}">
            ${assetUrl
                ? `<img class="inventory-tooltip-socket-art" src="${escapeHtml(assetUrl)}" alt="${escapeHtml(socket.gem.name)}" loading="lazy" onerror="this.remove();">`
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
    if (!item?.definition?.is_container) return '';
    if (item.definition?.equip_slot_code === 'backpack' && item.public_id === equippedBackpackPublicId) {
        return '';
    }

    const storage = containerStorageSummary(item);
    if (!storage) return '<span class="inventory-item-badge">Armazenamento</span>';

    return `<span class="inventory-item-badge">${escapeHtml(storage.label)}</span>`;
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
    const bounds = item?.stat_bounds?.[propertyCode];
    if (bounds) {
        const min = Math.max(1, Number(bounds.min ?? 1));
        const cap = Math.max(min, Number(bounds.cap ?? bounds.max ?? min));
        return `${min}~${cap}`;
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
    return `${min}~${max}`;
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
    if (slotCode === 'potion') return ['potion_1', 'potion_2', 'potion_3', 'potion_4'];
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
    const { tier, filled } = blessStarState(level);
    const stars = Array.from({ length: 5 }, (_, index) => {
        const isFilled = index < filled;
        const tierClass = isFilled && tier ? ` is-tier-${tier}` : '';
        const filledClass = isFilled ? ' is-filled' : '';

        return `<span class="inventory-upgrade-star${filledClass}${tierClass}" aria-hidden="true">★</span>`;
    }).join('');

    const label = level > 0 ? `Nivel de melhoria ${level}` : 'Sem melhoria';

    return `<div class="inventory-upgrade-stars" aria-label="${label}">${stars}</div>`;
}

function baseStatRangeLabelBracketed(item, propertyCode) {
    return `[${baseStatRangeLabel(item, propertyCode).replace('~', ' - ')}]`;
}

function tooltipPropertyValue(item, property, options = {}) {
    const code = String(property.code || '');
    const value = formatItemPropertyValue(property);
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
        return `<strong>${escapeHtml(value)}</strong><small class="inventory-tooltip-range">${escapeHtml(baseStatRangeLabelBracketed(item, code))}</small>${delta}`;
    }

    return `<strong>${escapeHtml(value)}</strong>${delta}`;
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

function tooltipEconomyFooter(item) {
    if (!itemHasEconomy(item)) return '';

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

function formatAffixDisplayName(name) {
    return String(name || '').trim().replace(/^da\s+/i, '');
}

function isStorageContainerItem(item) {
    return Boolean(item?.definition?.is_container && item?.linked_container?.public_id);
}

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

function setGlowLevel(setCode, setBonuses = []) {
    const bonus = (setBonuses || []).find((entry) => entry.set_code === setCode);
    const pieces = Number(bonus?.equipped_pieces || 0);
    if (pieces >= 5) return 3;
    if (pieces >= 3) return 2;
    if (pieces >= 2) return 1;
    return 0;
}

function closeComparePanel() {
    comparePanelState = null;
    if (!compareDockRoot) return;
    compareDockRoot.hidden = true;
    compareDockRoot.classList.remove('is-open');
    compareDockRoot.replaceChildren();
}

function openComparePanel(item) {
    if (!compareDockRoot || !isEquippableItem(item)) return;

    const equipped = comparisonEquippedItem(item);
    if (!equipped) {
        toast('Nenhum item equipado no slot correspondente para comparar.', 'info', 2800);
        return;
    }

    if (comparePanelState?.item?.public_id === item.public_id) {
        closeComparePanel();
        return;
    }

    comparePanelState = { item, equipped };
    renderComparePanel();
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

    compareDockRoot.hidden = false;
    compareDockRoot.classList.add('is-open');
    compareDockRoot.innerHTML = `
        <header class="inventory-compare-header">
            <div>
                <p class="inventory-kicker">Comparacao</p>
                <h3>${escapeHtml(itemLabel(item))}</h3>
            </div>
            <button type="button" class="inventory-compare-close" aria-label="Fechar comparacao">×</button>
        </header>
        <div class="inventory-compare-grid">
            ${renderCompareSideCard('Candidato', item, equipped, candidatePros)}
            ${renderCompareSideCard('Equipado', equipped, item, equippedPros)}
        </div>
        <footer class="inventory-compare-footer">
            <div class="inventory-compare-footer-power">
                <span>Poder do candidato</span>
                <strong>${itemPower}</strong>
                ${formatStatDelta(powerDelta)}
            </div>
            <small>Ctrl+clique no item para abrir ou fechar.</small>
        </footer>
    `;

    compareDockRoot.querySelector('.inventory-compare-close')?.addEventListener('click', closeComparePanel);
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
            <div class="inventory-compare-hero">
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
            <div class="inventory-compare-highlights">
                ${highlights.gains.length ? `<div class="inventory-compare-highlight is-positive"><span>Vantagens</span><ul>${highlights.gains.map((line) => `<li>${line}</li>`).join('')}</ul></div>` : ''}
                ${highlights.losses.length ? `<div class="inventory-compare-highlight is-negative"><span>Desvantagens</span><ul>${highlights.losses.map((line) => `<li>${line}</li>`).join('')}</ul></div>` : ''}
                ${!highlights.gains.length && !highlights.losses.length ? '<p class="inventory-compare-neutral">Sem diferencas relevantes nos atributos principais.</p>' : ''}
            </div>
            ${renderTooltipSetBlock(item)}
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

    return { gains: gains.slice(0, 5), losses: losses.slice(0, 5) };
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
        escapeHtml(typeMeta.label),
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

    const baseList = baseProperties.length
        ? `<ul class="inventory-tooltip-base-stats">${baseProperties.map((property) => renderTooltipStatLine(
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
    const powerBlock = equippable && power > 0
        ? `<div class="inventory-tooltip-power">
            <span>Poder do item</span>
            <strong>${power}${powerDelta}</strong>
        </div>`
        : '';
    const compareHeader = compareWith
        ? `<div class="inventory-tooltip-compare-note">Comparando com <strong>${escapeHtml(itemLabel(compareWith))}</strong>${item.equipped ? ' no inventario' : ' equipado'}</div>`
        : '';

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
        !inline && !jewel && categoryCode !== 'consumable' && categoryCode !== 'currency' && item.definition?.description
            ? `<p class="inventory-tooltip-description">${escapeHtml(item.definition.description)}</p>`
            : '',
        powerBlock,
        jewel ? jewelProperties : baseList,
        jewel ? '' : affixList,
        jewel ? '' : extraPropertyList,
        socketList,
        setBlock,
        storageBlock,
        consumableBlock,
        materialBlock,
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

function syncDrawerUi() {
    app?.classList.toggle('is-left-drawer-open', leftDrawerOpen);
    app?.classList.toggle('is-right-drawer-open', rightDrawerOpen);
    app?.classList.toggle('is-stats-drawer-open', statsDrawerOpen);
    app?.classList.toggle('is-market-open', marketPanelOpen);
    app?.classList.toggle('is-materials-open', materialsPanelOpen);
    app?.classList.toggle('is-craft-open', craftPanelOpen);
    app?.classList.toggle('is-drawer-focus-left', focusedDrawer === 'left');
    app?.classList.toggle('is-drawer-focus-right', focusedDrawer === 'right');
    app?.classList.toggle('is-drawer-focus-stats', focusedDrawer === 'stats');
    if (backdropRoot) backdropRoot.hidden = !(leftDrawerOpen || rightDrawerOpen || statsDrawerOpen || marketPanelOpen || materialsPanelOpen || craftPanelOpen);
    if (hubRoot) hubRoot.hidden = leftDrawerOpen || rightDrawerOpen || statsDrawerOpen || marketPanelOpen || materialsPanelOpen || craftPanelOpen;
    if (statsDrawerRoot) statsDrawerRoot.hidden = !statsDrawerOpen;
    if (marketPanelRoot) {
        marketPanelRoot.hidden = !marketPanelOpen;
        marketPanelRoot.setAttribute('aria-hidden', marketPanelOpen ? 'false' : 'true');
    }
    if (materialsPanelRoot) {
        materialsPanelRoot.hidden = !materialsPanelOpen;
        materialsPanelRoot.setAttribute('aria-hidden', materialsPanelOpen ? 'false' : 'true');
    }
    if (craftPanelRoot) {
        craftPanelRoot.hidden = !craftPanelOpen;
        craftPanelRoot.setAttribute('aria-hidden', craftPanelOpen ? 'false' : 'true');
    }
}

function walletBalance(code) {
    const wallet = playerWallets.find((entry) => entry.currency_code === code || entry.code === code);
    return Number(wallet?.balance || 0);
}

function renderMarketWallets() {
    if (!marketWalletsRoot) return;

    const gold = walletBalance('gold');
    const premium = walletBalance('premium');
    marketWalletsRoot.innerHTML = `
        <span class="inventory-market-wallet is-gold" title="Ouro">${gold.toLocaleString('pt-BR')} G</span>
        <span class="inventory-market-wallet is-premium" title="Eter Cristal">${premium.toLocaleString('pt-BR')} 💎</span>
    `;
}

function marketListingSummaryLines(item) {
    const lines = [];
    const upgradeLevel = upgradeLevelFromItem(item);
    const quantity = Number(item?.quantity || 1);
    const quality = item?.quality_bucket ? String(item.quality_bucket) : null;
    const typeMeta = resolveItemTypeMeta(item);

    if (upgradeLevel > 0) lines.push(`Melhoria +${upgradeLevel}`);
    if (quality) lines.push(`Raridade ${quality}`);
    lines.push(typeMeta.label);
    if (quantity > 1) lines.push(`Quantidade ${quantity}`);

    const affixes = Array.isArray(item?.affixes) ? item.affixes.slice(0, 3) : [];
    affixes.forEach((affix) => {
        const unit = affix.unit ? String(affix.unit) : '';
        lines.push(`${affix.name}: +${affix.value}${unit}`);
    });

    const baseStats = Array.isArray(item?.properties)
        ? item.properties.filter((prop) => ['strength', 'defense', 'vitality', 'agility'].includes(String(prop.code || ''))).slice(0, 3)
        : [];
    baseStats.forEach((prop) => {
        lines.push(`${prop.name}: ${prop.value}`);
    });

    return lines.slice(0, 6);
}

function formatMarketListedAt(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function bindMarketListingTooltips() {
    if (!marketListingsRoot || !window.tippy) return;

    marketListingsRoot.querySelectorAll('[data-market-item-preview]').forEach((node) => {
        if (node._tippy) return;
        const listingId = node.getAttribute('data-market-item-preview');
        const listing = marketListings.find((entry) => entry.listing_public_id === listingId);
        if (!listing?.item) return;

        window.tippy(node, {
            allowHTML: true,
            content: itemTooltip(listing.item),
            theme: 'evolvaxe-item',
            placement: 'right',
            interactive: true,
            appendTo: () => document.body,
            delay: [160, 60],
        });
    });
}

function renderMarketListings() {
    if (!marketListingsRoot) return;

    if (marketLoading) {
        marketListingsRoot.innerHTML = '<p class="inventory-market-empty">Carregando anuncios...</p>';
        return;
    }

    if (!marketListings.length) {
        marketListingsRoot.innerHTML = '<p class="inventory-market-empty">Nenhum anuncio encontrado.</p>';
        return;
    }

    marketListingsRoot.innerHTML = marketListings.map((listing) => {
        const item = listing.item || {};
        const name = itemLabel(item);
        const quality = item.quality_bucket ? String(item.quality_bucket) : 'common';
        const category = item.category_code || item.definition?.category_code || 'material';
        const price = Number(listing.price_premium || 0);
        const canAfford = walletBalance('premium') >= price;
        const isOwn = Boolean(listing.is_own_listing);
        const seller = listing.seller || {};
        const sellerName = seller.name || 'Jogador';
        const sellerLevel = Number(seller.level || 1);
        const assetUrl = itemAssetUrl(item);
        const summaryLines = marketListingSummaryLines(item);
        const upgradeLevel = upgradeLevelFromItem(item);

        return `
            <article class="inventory-market-card rarity-${escapeHtml(quality)}${isOwn ? ' is-own' : ''}">
                <div class="inventory-market-card-preview" data-market-item-preview="${escapeHtml(listing.listing_public_id)}">
                    <div class="inventory-market-card-art${assetUrl ? '' : ' is-placeholder'}">
                        ${assetUrl ? `<img src="${escapeHtml(assetUrl)}" alt="" loading="lazy">` : `<span>${escapeHtml(resolveItemTypeMeta(item).icon)}</span>`}
                    </div>
                    <div class="inventory-market-card-copy">
                        <div class="inventory-market-card-head">
                            <strong>${escapeHtml(name)}${upgradeLevel > 0 ? ` <small>+${upgradeLevel}</small>` : ''}</strong>
                            <span class="inventory-market-card-price">${price.toLocaleString('pt-BR')} 💎</span>
                        </div>
                        <div class="inventory-market-card-meta">
                            <span>${escapeHtml(quality)}</span>
                            <span>${escapeHtml(category)}</span>
                        </div>
                        <ul class="inventory-market-card-stats">
                            ${summaryLines.map((line) => `<li>${escapeHtml(line)}</li>`).join('')}
                        </ul>
                    </div>
                </div>
                <div class="inventory-market-card-seller">
                    <span>Vendedor</span>
                    <strong>${escapeHtml(sellerName)}</strong>
                    <small>Nv. ${sellerLevel} · ${escapeHtml(formatMarketListedAt(listing.listed_at))}</small>
                </div>
                <div class="inventory-market-card-actions">
                    ${isOwn
                        ? `<button type="button" class="inventory-button inventory-button-ghost inventory-market-cancel" data-market-cancel="${escapeHtml(listing.listing_public_id)}">Remover anuncio</button>`
                        : `<button
                            type="button"
                            class="inventory-button inventory-market-buy"
                            data-market-buy="${escapeHtml(listing.listing_public_id)}"
                            ${canAfford ? '' : 'disabled'}
                        >${canAfford ? 'Comprar' : 'Saldo insuficiente'}</button>`}
                </div>
            </article>
        `;
    }).join('');

    bindMarketListingTooltips();
}

async function loadMarketListings() {
    if (!marketPanelOpen || marketLoading) return;

    marketLoading = true;
    renderMarketListings();

    try {
        const params = new URLSearchParams();
        if (marketFilters.q) params.set('q', marketFilters.q);
        if (marketFilters.quality_bucket) params.set('quality_bucket', marketFilters.quality_bucket);
        if (marketFilters.category_code) params.set('category_code', marketFilters.category_code);
        if (marketFilters.min_price) params.set('min_price', marketFilters.min_price);
        if (marketFilters.max_price) params.set('max_price', marketFilters.max_price);
        params.set('limit', '60');

        const response = await apiFetch(`/api/market/listings?${params.toString()}`);
        marketListings = response.data?.listings || [];
    } catch (error) {
        marketListings = [];
        handleError(error, 'Nao foi possivel carregar o mercado.');
    } finally {
        marketLoading = false;
        renderMarketListings();
    }
}

function syncMarketFilterInputs() {
    if (!marketPanelRoot) return;

    const searchInput = marketPanelRoot.querySelector('[data-market-filter-q]');
    const qualitySelect = marketPanelRoot.querySelector('[data-market-filter-quality]');
    const categorySelect = marketPanelRoot.querySelector('[data-market-filter-category]');
    const minInput = marketPanelRoot.querySelector('[data-market-filter-min]');
    const maxInput = marketPanelRoot.querySelector('[data-market-filter-max]');

    if (searchInput) searchInput.value = marketFilters.q;
    if (qualitySelect) qualitySelect.value = marketFilters.quality_bucket;
    if (categorySelect) categorySelect.value = marketFilters.category_code;
    if (minInput) minInput.value = marketFilters.min_price;
    if (maxInput) maxInput.value = marketFilters.max_price;
}

function openMarketPanel() {
    materialsPanelOpen = false;
    craftPanelOpen = false;
    marketPanelOpen = true;
    syncDrawerUi();
    renderMarketWallets();
    syncMarketFilterInputs();
    loadMarketListings();
}

function closeMarketPanel() {
    marketPanelOpen = false;
    syncDrawerUi();
}

function toggleMarketPanel() {
    if (marketPanelOpen) {
        closeMarketPanel();
        return;
    }
    openMarketPanel();
}

async function cancelMarketListing(listingPublicId) {
    if (actionInFlight || loading || !listingPublicId) return;

    const listing = marketListings.find((entry) => entry.listing_public_id === listingPublicId);
    if (!listing) return;

    const itemName = itemLabel(listing.item || {});
    const confirmed = await confirmInventoryAction({
        title: 'Remover anuncio',
        bodyHtml: `<p>Remover <strong>${escapeHtml(itemName)}</strong> do mercado?</p>
            <p>O item voltara para seu inventario principal. A taxa de anuncio nao e reembolsada.</p>`,
        confirmLabel: 'Remover',
        tone: 'danger',
    });
    if (!confirmed) return;

    actionInFlight = true;
    try {
        setStatus('Removendo anuncio...');
        await apiFetch(`/api/market/listings/${encodeURIComponent(listingPublicId)}/cancel`, {
            method: 'POST',
            body: {},
        });
        toast('Anuncio removido. Item devolvido ao inventario.', 'success', 3600);
        setStatus('Sincronizado');
        await loadInventory();
        await loadMarketListings();
    } catch (error) {
        handleError(error, 'Nao foi possivel remover o anuncio.');
    } finally {
        actionInFlight = false;
    }
}

function materialAssetUrl(stack) {
    const url = String(stack?.icon_url || '').trim();
    if (url) return url;

    const familyCode = String(stack?.family_code || '').trim();
    if (!/^[a-z0-9_-]+$/i.test(familyCode)) return null;

    return `/assets/game/materials/${familyCode}.png`;
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
                    ${assetUrl ? `<img src="${escapeHtml(assetUrl)}" alt="" loading="lazy" onerror="this.remove(); this.parentElement.classList.add('is-placeholder');">` : `<span>${escapeHtml(tabIcon)}</span>`}
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
                    ${assetUrl ? `<img class="inventory-item-art" src="${escapeHtml(assetUrl)}" alt="" loading="lazy" onerror="this.closest('.inventory-item')?.classList.add('has-missing-art'); this.remove();">` : `<span class="inventory-item-fallback" aria-hidden="true">${escapeHtml(tabIcon)}</span>`}
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

    const tabs = materialStash.tabs?.length
        ? materialStash.tabs
        : [
            { code: 'metals', name: 'Metais', icon: '⚙' },
            { code: 'gems', name: 'Gemas', icon: '💠' },
            { code: 'essences', name: 'Essencias', icon: '✦' },
            { code: 'fragments', name: 'Fragmentos', icon: '◆' },
        ];

    materialsTabsRoot.innerHTML = tabs.map((tab) => `
        <button type="button" class="inventory-materials-tab${materialsActiveTab === tab.code ? ' is-active' : ''}" data-materials-tab="${escapeHtml(tab.code)}">
            ${escapeHtml(tab.icon || '◆')} ${escapeHtml(tab.name)}
        </button>
    `).join('');

    if (materialsLoading) {
        materialsListRoot.className = 'inventory-materials-grid-host is-loading';
        materialsListRoot.innerHTML = '<p class="inventory-materials-empty">Carregando materiais...</p>';
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
        const response = await apiFetch('/api/inventory/materials');
        materialStash = response.data || { tabs: [], stacks: [] };
    } catch (error) {
        materialStash = { tabs: [], stacks: [] };
        handleError(error, 'Nao foi possivel carregar os materiais.');
    } finally {
        materialsLoading = false;
        renderMaterialsPanel();
    }
}

function openMaterialsPanel() {
    marketPanelOpen = false;
    craftPanelOpen = false;
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
            ${assetUrl ? `<img class="inventory-item-art" src="${escapeHtml(assetUrl)}" alt="" loading="lazy" onerror="this.remove();">` : '<span class="inventory-item-fallback">◆</span>'}
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
                            ${outputsHint ? `<p class="inventory-craft-output-hint">${escapeHtml(outputsHint)}</p>` : ''}
                            ${recipeMatch.guaranteed_success ? '<p class="inventory-craft-guarantee">Forja garantida — esta receita sempre produz um item.</p>' : ''}
                            <div class="inventory-craft-cost-row">
                                <span>Custo</span>
                                <strong class="${canAfford ? '' : 'is-insufficient'}">${goldCost.toLocaleString('pt-BR')} G</strong>
                                <small>Saldo: ${goldBalance.toLocaleString('pt-BR')} G</small>
                            </div>
                        </div>
                        <button type="button" class="inventory-button inventory-craft-execute" data-craft-execute ${canCraft ? '' : 'disabled'}>
                            ${craftWorkspace === 'forge' ? 'Forjar item' : 'Transmutar'}${goldCost > 0 ? ` · ${goldCost.toLocaleString('pt-BR')} G` : ''}
                        </button>
                    </div>
                </section>
                <section class="inventory-craft-lane inventory-craft-lane--library">
                    ${renderCraftPickerLibrary()}
                </section>
            </div>
        </div>
    `;

    window.requestAnimationFrame(() => {
        renderCraftLinks();
        window.requestAnimationFrame(renderCraftLinks);
    });
    bindCraftPanelInteractions();
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

function assignCraftSlot(index, payload, options = {}) {
    if (index < 0 || index >= CRAFT_SLOT_COUNT || !payload) return false;

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
        await loadInventory();
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
    marketPanelOpen = false;
    materialsPanelOpen = false;
    craftPanelOpen = true;
    craftActiveSlotIndex = craftActiveSlotIndex ?? resolveCraftPickTargetIndex();
    if (craftActiveSlotIndex < 0) craftActiveSlotIndex = 0;
    syncDrawerUi();
    Promise.all([loadCraftWorkspaces(), loadCraftPickerStash()]).then(() => {
        renderCraftPanel();
        refreshCraftPreview();
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

function renderInvestigationSparkline(history = []) {
    if (!history.length) return '<span class="inventory-investigate-sparkline is-empty">Sem historico recente</span>';
    const values = history.map((entry) => Number(entry.market_value || 0));
    const min = Math.min(...values);
    const max = Math.max(...values);
    const chars = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
    const bars = values.map((value) => {
        const ratio = max === min ? 0.5 : (value - min) / (max - min);
        return chars[Math.min(chars.length - 1, Math.floor(ratio * (chars.length - 1)))];
    }).join('');

    return `<span class="inventory-investigate-sparkline" title="Ultimos ${history.length} registros">${escapeHtml(bars)}</span>`;
}

function renderInvestigationModal(report, item) {
    const data = report || {};
    const inspectedItem = data.item || item || {};
    const market = data.market || {};
    const dismantle = data.dismantle || {};
    const history = data.history || [];
    const crafting = data.crafting || [];
    const supply = market.supply || {};
    const upgradeLevel = upgradeLevelFromItem(inspectedItem);
    const typeMeta = resolveItemTypeMeta(inspectedItem);
    const assetUrl = itemAssetUrl(inspectedItem);
    const quality = inspectedItem.quality_bucket || 'common';
    const stats = (inspectedItem.properties || []).filter((prop) => !['upgrade_level', 'upgrade_success_rate', 'socket_count'].includes(String(prop.code || ''))).slice(0, 6);
    const affixes = (inspectedItem.affixes || []).slice(0, 6);
    const sockets = inspectedItem.sockets || [];
    const dismantleLines = (dismantle.materials || []).map((entry) => `${entry.label} x${entry.quantity}`).join(' · ') || 'Nenhum material previsto';
    const historyLines = history.length
        ? history.map((entry) => `<li>${escapeHtml(entry.label || '-')}</li>`).join('')
        : '<li>Sem eventos registrados.</li>';

    return `
        <div class="inventory-investigate">
            <header class="inventory-investigate-hero rarity-${escapeHtml(quality)}">
                <div class="inventory-investigate-art${assetUrl ? '' : ' is-placeholder'}">
                    ${assetUrl ? `<img src="${escapeHtml(assetUrl)}" alt="">` : `<span>${escapeHtml(typeMeta.icon)}</span>`}
                </div>
                <div>
                    <h3>${escapeHtml(itemLabel(inspectedItem))}${upgradeLevel > 0 ? ` <small>+${upgradeLevel}</small>` : ''}</h3>
                    <p>${escapeHtml(String(quality))} · Poder ${Number(data.power || 0).toLocaleString('pt-BR')} · ${escapeHtml(typeMeta.label)}</p>
                </div>
            </header>
            ${data.description ? `<section class="inventory-investigate-section"><h4>Descricao</h4><p>${escapeHtml(data.description)}</p></section>` : ''}
            <section class="inventory-investigate-grid">
                <div><h4>Stats</h4><ul>${stats.map((stat) => `<li>${escapeHtml(stat.name)}: <strong>${escapeHtml(String(stat.value ?? '-'))}</strong></li>`).join('') || '<li>Sem stats base.</li>'}</ul></div>
                <div><h4>Affixes</h4><ul>${affixes.map((affix) => `<li>${escapeHtml(affix.name)}: <strong>+${escapeHtml(String(affix.value ?? '-'))}</strong></li>`).join('') || '<li>Sem affixes.</li>'}</ul></div>
                <div><h4>Sockets</h4><ul>${sockets.map((socket) => `<li>${socket.gem ? `● ${escapeHtml(socket.gem.name || 'Gema')}` : '○ Vazio'}</li>`).join('') || '<li>Sem sockets.</li>'}</ul></div>
            </section>
            <section class="inventory-investigate-section">
                <h4>Mercado</h4>
                <p>${Number(market.npc_value || 0).toLocaleString('pt-BR')} G (NPC) / ${Number(market.suggested_premium || 0).toLocaleString('pt-BR')} 💎 (P2P)</p>
                <p>Oferta: ${Number(supply.similar_listings || 0)} similares · Demanda: ${escapeHtml(supply.demand_label || 'Estavel')}</p>
                ${renderInvestigationSparkline(market.price_history || [])}
            </section>
            <section class="inventory-investigate-section">
                <h4>Desmanche</h4>
                <p>${escapeHtml(dismantleLines)}</p>
                ${dismantle.can_dismantle ? `<button type="button" class="inventory-button inventory-investigate-dismantle" data-investigate-dismantle="${escapeHtml(inspectedItem.public_id)}">Desmanchar item</button>` : '<small>Item nao pode ser desmanchado.</small>'}
            </section>
            ${crafting.length ? `<section class="inventory-investigate-section"><h4>Crafting</h4><ul>${crafting.map((entry) => `<li><strong>${escapeHtml(entry.label)}:</strong> ${escapeHtml(entry.description || '')}</li>`).join('')}</ul></section>` : ''}
            <section class="inventory-investigate-section">
                <h4>Historico</h4>
                <ul>${historyLines}</ul>
            </section>
        </div>
    `;
}

async function openInvestigationModal(item) {
    if (!item?.public_id || actionInFlight || loading) return;

    actionInFlight = true;
    try {
        setStatus('Investigando item...');
        const response = await apiFetch(`/api/inventory/items/${encodeURIComponent(item.public_id)}/investigate`);
        const report = response.data || {};
        const content = document.createElement('div');
        content.innerHTML = renderInvestigationModal(report, item);

        const { close, element } = openModal(content.firstElementChild || content, { closeOnBackdrop: true });
        element.querySelector('[data-investigate-dismantle]')?.addEventListener('click', async () => {
            close();
            await executeItemAction(item, {
                code: 'DISMANTLE',
                name: 'Desmanchar',
                requires_confirmation: true,
                is_destructive: true,
            });
        });

        setStatus('Sincronizado');
    } catch (error) {
        handleError(error, 'Nao foi possivel investigar o item.');
    } finally {
        actionInFlight = false;
    }
}

async function buyMarketListing(listingPublicId) {
    if (actionInFlight || loading || !listingPublicId) return;

    const listing = marketListings.find((entry) => entry.listing_public_id === listingPublicId);
    if (!listing) return;

    const itemName = listing.item?.definition_name || listing.item?.definition_code || 'Item';
    const price = Number(listing.price_premium || 0);
    const confirmed = await confirmInventoryAction({
        title: 'Comprar no mercado',
        bodyHtml: `<p>Comprar <strong>${escapeHtml(itemName)}</strong> por <strong>${price.toLocaleString('pt-BR')} 💎</strong>?</p>
            <p>O item sera enviado para o container de entregas do mercado.</p>`,
        confirmLabel: 'Comprar',
        tone: 'warning',
    });
    if (!confirmed) return;

    actionInFlight = true;
    try {
        setStatus('Comprando item...');
        await apiFetch(`/api/market/listings/${encodeURIComponent(listingPublicId)}/buy`, {
            method: 'POST',
            body: {},
        });
        toast('Compra concluida. Verifique as entregas do mercado.', 'success', 3600);
        setStatus('Sincronizado');
        await loadInventory();
        await loadMarketListings();
    } catch (error) {
        handleError(error, 'Nao foi possivel concluir a compra.');
    } finally {
        actionInFlight = false;
    }
}

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
    leftDrawerTab = tab === 'stats' ? 'stats' : 'equipment';
    persistDrawerState();
    equipmentRoot?.querySelectorAll('[data-left-tab]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.leftTab === leftDrawerTab);
    });
    equipmentRoot?.querySelectorAll('[data-left-tab-panel]').forEach((panel) => {
        const active = panel.dataset.leftTabPanel === leftDrawerTab;
        panel.hidden = !active;
        panel.classList.toggle('is-active', active);
    });
    if (leftDrawerTab === 'stats') {
        renderCharacterStats(lastCharacterStats, currentSetBonuses, playerPower, equipmentRoot?.querySelector('[data-character-stats-panel]'));
    }
}

function openLeftDrawer() {
    leftDrawerOpen = true;
    focusedDrawer = 'left';
    persistDrawerState();
    syncDrawerUi();
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
    if (craftPanelOpen) {
        closeCraftPanel();
        return;
    }
    if (materialsPanelOpen) {
        closeMaterialsPanel();
        return;
    }
    if (marketPanelOpen) {
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
        loadInventory();
    });

    document.querySelectorAll('[data-market-open]').forEach((button) => {
        button.addEventListener('click', () => toggleMarketPanel());
    });

    document.querySelectorAll('[data-materials-open]').forEach((button) => {
        button.addEventListener('click', () => toggleMaterialsPanel());
    });

    initCraftControls();

    document.querySelector('[data-market-refresh]')?.addEventListener('click', () => loadMarketListings());
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

        const tabButton = target.closest('[data-materials-tab]');
        if (!tabButton) return;
        materialsActiveTab = tabButton.getAttribute('data-materials-tab') || 'metals';
        renderMaterialsPanel();
    });

    marketPanelRoot?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        if (target.closest('[data-market-close]')) {
            closeMarketPanel();
            return;
        }

        const cancelButton = target.closest('[data-market-cancel]');
        if (cancelButton) {
            cancelMarketListing(cancelButton.getAttribute('data-market-cancel'));
            return;
        }

        if (event.target === marketPanelRoot) {
            closeMarketPanel();
            return;
        }

        const buyButton = target.closest('[data-market-buy]');
        if (!buyButton) return;
        buyMarketListing(buyButton.getAttribute('data-market-buy'));
    });

    marketPanelRoot?.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        if (target.matches('[data-market-filter-q]')) marketFilters.q = target.value.trim();
        if (target.matches('[data-market-filter-quality]')) marketFilters.quality_bucket = target.value;
        if (target.matches('[data-market-filter-category]')) marketFilters.category_code = target.value;
        if (target.matches('[data-market-filter-min]')) marketFilters.min_price = target.value;
        if (target.matches('[data-market-filter-max]')) marketFilters.max_price = target.value;
    });

    marketPanelRoot?.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        if (!target.matches('[data-market-filter-quality], [data-market-filter-category], [data-market-filter-min], [data-market-filter-max]')) {
            return;
        }
        loadMarketListings();
    });

    let marketSearchTimer = null;
    marketPanelRoot?.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement) || !target.matches('[data-market-filter-q]')) return;
        clearTimeout(marketSearchTimer);
        marketSearchTimer = setTimeout(() => loadMarketListings(), 320);
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

    const scope = gridNode?.closest('[data-inventory-expedition]')
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

    const kind = containerKind(container);
    if (kind === 'main') return true;
    if (kind === 'market_delivery') return marketDeliveryOpen;

    return openContainerPublicIds.has(container.public_id);
}

function isContainerVisible(container) {
    if (isEquippedBackpackContainer(container)) {
        return false;
    }

    const kind = containerKind(container);
    if (kind === 'expedition_carry') return false;
    if (kind === 'main') return true;
    if (kind === 'market_delivery') return marketDeliveryOpen;

    return openContainerPublicIds.has(container.public_id);
}

function openContainer(containerPublicId) {
    openContainerPublicIds.add(containerPublicId);
    persistContainerPanels();
}

function toggleContainer(container) {
    const kind = containerKind(container);
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
    loadInventory();
}

function containerDisplayName(container) {
    const kind = containerKind(container);
    if (kind === 'expedition_carry') {
        const backpackSlot = currentEquipment.find((slot) => slot.code === 'backpack');
        if (backpackSlot?.item) {
            return `Expedicao (${itemLabel(backpackSlot.item)})`;
        }

        return 'Bolsos (2x2)';
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
            await loadInventory();
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
            await loadInventory();
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
    const classes = [
        'inventory-item',
        isContainer ? 'is-container-item' : '',
        assetUrl ? 'has-art' : '',
        `rarity-${rarity}`,
        options.ghost ? 'is-equipment-ghost' : '',
        placement.rotated ? 'is-rotated' : '',
        footprintArea <= 1 ? 'is-tiny' : '',
        footprintArea <= 2 ? 'is-compact' : '',
        footprintW > footprintH ? 'is-wide' : '',
        footprintH > footprintW ? 'is-tall' : '',
        footprintArea >= 4 ? 'is-large' : '',
    ].filter(Boolean).join(' ');

    return `
        <div class="${classes}" data-item-public-id="${escapeHtml(item.public_id)}" aria-label="${escapeHtml(itemLabel(item))}">
            ${assetUrl ? `<img class="inventory-item-art" src="${escapeHtml(assetUrl)}" alt="" loading="lazy" onerror="this.closest('.inventory-item')?.classList.add('has-missing-art'); this.remove();">` : ''}
            ${options.hideTypeBadge ? '' : renderItemTypeBadge(item)}
            ${containerItemBadge(item)}
            ${isEquippableItem(item) ? renderUpgradeStars(item) : ''}
            <span class="inventory-item-name">${escapeHtml(name)}</span>
            ${quantity > 1 ? `<span class="inventory-item-quantity">x${quantity}</span>` : ''}
        </div>
    `;
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

function renderContainer(container, summaryEntry = null) {
    const isPhysical = Boolean(container.source_item_public_id);
    const acceptanceTone = container.acceptance_summary?.tone || 'all';
    const section = document.createElement('section');
    section.className = `inventory-container acceptance-${escapeHtml(acceptanceTone)}${isPhysical ? ' inventory-container-physical' : ''}`;
    section.dataset.containerPublicId = container.public_id;
    if (container.source_item_public_id) {
        section.dataset.sourceItemPublicId = container.source_item_public_id;
    }

    const badge = isPhysical
        ? '<span class="inventory-container-badge">Fisico</span>'
        : '';
    const acceptanceBadges = renderAcceptanceBadges(container);
    const breadcrumb = renderContainerBreadcrumb(container);
    const canOrganize = !['expedition_carry', 'market_delivery'].includes(containerKind(container));
    const canRename = Boolean(container.can_rename);
    const titleAttrs = canRename ? ' data-container-rename-title title="Duplo clique para renomear"' : '';
    const canClose = isPhysical || containerKind(container) === 'market_delivery';

    section.innerHTML = `
        <header class="inventory-container-header">
            <div class="inventory-container-title">
                ${breadcrumb}
                <div class="inventory-container-title-row">
                    <h2${titleAttrs}>${escapeHtml(containerDisplayName(container))}</h2>
                    ${badge}
                </div>
                ${acceptanceBadges}
                <p>${escapeHtml(containerDisplayHint(container))}</p>
                ${isPhysical ? '<p class="inventory-container-link">Duplo clique no item para abrir split · duplo clique no titulo para renomear</p>' : ''}
            </div>
            <div class="inventory-container-meta-block">
                <span class="inventory-container-meta">${Number(container.grid.columns)}x${Number(container.grid.rows)}</span>
                <span class="inventory-container-occupancy">${escapeHtml(occupancyLabel(container, summaryEntry))}</span>
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

    if (canRename) {
        section.querySelector('[data-container-rename-title]')?.addEventListener('dblclick', (event) => {
            event.preventDefault();
            event.stopPropagation();
            renameContainerInline(container, event.currentTarget);
        });
    }

    section.querySelector('.inventory-container-close')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (splitViewState?.childPublicId === container.public_id) {
            clearSplitView();
        }
        toggleContainer(container);
    });

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
            if (containerKind(container) !== 'main' && splitViewState) {
                splitViewState = { ...splitViewState, childPublicId: targetPublicId };
                persistSplitView();
            }
            openContainer(targetPublicId);
            loadInventory();
        });
    });

    section.querySelector('.inventory-grid-wrap').appendChild(gridElement(container));
    return section;
}

function renderSplitLayout(parentContainer, childContainer, summaryByPublicId) {
    const host = document.createElement('section');
    host.className = 'inventory-split-layout';
    host.innerHTML = `
        <header class="inventory-split-header">
            <div>
                <p class="inventory-kicker">Armazenamento aninhado</p>
                <h2>${escapeHtml(containerDisplayName(childContainer))}</h2>
            </div>
            <button type="button" class="inventory-button inventory-split-close">Voltar ao inventario</button>
        </header>
        <div class="inventory-split-panels"></div>
    `;

    const panels = host.querySelector('.inventory-split-panels');
    panels.appendChild(renderContainer(parentContainer, summaryByPublicId.get(parentContainer.public_id) || null));
    panels.appendChild(renderContainer(childContainer, summaryByPublicId.get(childContainer.public_id) || null));

    host.querySelector('.inventory-split-close')?.addEventListener('click', () => {
        clearSplitView();
        if (childContainer.public_id) {
            openContainerPublicIds.delete(childContainer.public_id);
            persistContainerPanels();
        }
        loadInventory();
    });

    return host;
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
        await loadInventory();
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
        return;
    }

    try {
        const response = await apiFetch(`/api/items/${encodeURIComponent(slot.item.public_id)}/actions`);
        const useAction = (response.data?.actions || []).find((action) => action.code === 'USE');
        if (!useAction) {
            toast('Este slot nao possui acao de uso rapido.', 'info', 2600);
            return;
        }

        await executeItemAction(slot.item, useAction);
    } catch (error) {
        handleError(error, 'Nao foi possivel usar o consumivel.');
    }
}

function renderEquipment(equipment = [], stats = [], links = [], setBonuses = []) {
    if (!equipmentRoot) return;

    lastCharacterStats = stats;
    equipmentRoot.replaceChildren();
    const equippedCount = equipment.filter((slot) => slot.item).length;
    equipmentRoot.classList.remove('is-collapsed');
    equipmentRoot.innerHTML = `
        <div class="inventory-left-panel">
            <div class="inventory-left-tabs" role="tablist" aria-label="Painel do personagem">
                <button type="button" class="inventory-left-tab${leftDrawerTab === 'equipment' ? ' is-active' : ''}" data-left-tab="equipment" role="tab" aria-selected="${leftDrawerTab === 'equipment'}">Equipamento</button>
                <button type="button" class="inventory-left-tab${leftDrawerTab === 'stats' ? ' is-active' : ''}" data-left-tab="stats" role="tab" aria-selected="${leftDrawerTab === 'stats'}">Status</button>
                <span class="inventory-left-tab-meta">${equippedCount}/${equipment.length} slot(s)</span>
            </div>
            <div class="inventory-left-tab-panels">
                <div class="inventory-left-tab-panel${leftDrawerTab === 'equipment' ? ' is-active' : ''}" data-left-tab-panel="equipment" role="tabpanel"${leftDrawerTab === 'equipment' ? '' : ' hidden'}>
                    <div class="inventory-equipment-drawer-wrap">
                        <div class="inventory-equipment-scaler">
                            <div class="inventory-character-layout">
                                <div class="inventory-paperdoll">
                                    <div class="inventory-character-figure" aria-hidden="true">
                                        <div class="inventory-character-glow"></div>
                                        <div class="inventory-character-head"></div>
                                        <div class="inventory-character-body"></div>
                                        <div class="inventory-character-legs"></div>
                                    </div>
                                    <svg class="inventory-equipment-links" data-equipment-links aria-hidden="true"></svg>
                                    <div class="inventory-equipment-stage" data-equipment-stage></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="inventory-left-tab-panel${leftDrawerTab === 'stats' ? ' is-active' : ''}" data-left-tab-panel="stats" role="tabpanel"${leftDrawerTab === 'stats' ? '' : ' hidden'}>
                    <aside class="inventory-character-stats inventory-character-stats--tab" data-character-stats-panel></aside>
                </div>
            </div>
        </div>
    `;

    equipmentRoot.querySelectorAll('[data-left-tab]').forEach((button) => {
        button.addEventListener('click', () => setLeftDrawerTab(button.dataset.leftTab || 'equipment'));
    });

    renderCharacterStats(stats, setBonuses, playerPower, equipmentRoot.querySelector('[data-character-stats-panel]'));
    if (statsDrawerOpen) {
        renderCharacterStats(stats, setBonuses, playerPower, statsDrawerPanel);
    }
    renderEquipmentSlots(equipment);
    window.requestAnimationFrame(() => {
        renderEquipmentLinks(links, setBonuses);
        window.requestAnimationFrame(() => renderEquipmentLinks(links, setBonuses));
    });
}

function renderExpeditionDrawerSection(container, summaryEntry = null) {
    if (!expeditionRoot) return;

    expeditionRoot.replaceChildren();
    expeditionRoot.appendChild(renderContainer(container, summaryEntry));
    const section = expeditionRoot.querySelector(`[data-container-public-id="${container.public_id}"]`);
    const gridNode = section?.querySelector('.inventory-grid');
    if (!gridNode) return;

    const grid = initializeGrid(container, gridNode);
    addItems(container, grid);
}

function isTwoHandedWeapon(item) {
    const hands = Number(item?.definition?.base_config?.hands || 0);
    return hands >= 2;
}

function renderEquipmentSlots(equipment = []) {
    const stage = equipmentRoot.querySelector('[data-equipment-stage]');
    if (!stage) return;

    const byCode = new Map(equipment.map((slot) => [slot.code, slot]));
    const isDrawerPaperdoll = Boolean(equipmentRoot.querySelector('.inventory-equipment-drawer-wrap'));
    const weaponSlot = byCode.get('weapon');
    const twoHandedWeapon = weaponSlot?.item && isTwoHandedWeapon(weaponSlot.item) ? weaponSlot.item : null;
    const occupiedOffhand = ['weapon_offhand', 'shield', 'quiver']
        .map((code) => byCode.get(code))
        .find((slot) => slot?.item) || null;
    const offhand = occupiedOffhand || { code: 'offhand', name: 'Offhand', item: null };
    const offhandGhost = !occupiedOffhand && twoHandedWeapon ? twoHandedWeapon : null;

    const visualSlots = isDrawerPaperdoll
        ? [
            { slot: byCode.get('pet') },
            { slot: byCode.get('helmet') },
            { slot: byCode.get('wings') },
            { slot: byCode.get('weapon') },
            { slot: byCode.get('chest') },
            { slot: offhand, ghostItem: offhandGhost },
            { slot: byCode.get('gloves') },
            { slot: byCode.get('pants') },
            { slot: byCode.get('boots') },
            { slot: byCode.get('belt') },
            { slot: byCode.get('amulet') },
            { slot: byCode.get('earring') },
            { slot: byCode.get('ring') },
            { slot: byCode.get('ring_2') },
            { slot: byCode.get('backpack') },
            { slot: byCode.get('potion_1') },
            { slot: byCode.get('potion_2') },
            { slot: byCode.get('potion_3') },
            { slot: byCode.get('potion_4') },
        ].filter((entry) => entry.slot)
        : [
            { slot: byCode.get('pet') },
            { slot: byCode.get('helmet') },
            { slot: byCode.get('wings') },
            { slot: byCode.get('weapon') },
            { slot: byCode.get('chest') },
            { slot: offhand, ghostItem: offhandGhost },
            { slot: byCode.get('gloves') },
            { slot: byCode.get('pants') },
            { slot: byCode.get('boots') },
            { slot: byCode.get('amulet') },
            { slot: byCode.get('earring') },
            { slot: byCode.get('ring') },
            { slot: byCode.get('ring_2') },
            { slot: byCode.get('backpack') },
        ].filter((entry) => entry.slot);

    for (const entry of visualSlots) {
        stage.appendChild(equipmentSlotNode(entry.slot, {
            ghostItem: entry.ghostItem || null,
            showLabel: false,
        }));
    }
}

function equipmentSlotNode(slot, options = {}) {
    const ghostItem = options.ghostItem || null;
    const showLabel = Boolean(options.showLabel);
    const titleLabel = options.titleLabel || '';
    const displayItem = slot.item || ghostItem;
    const isGhostOccupied = Boolean(!slot.item && ghostItem);
    const node = document.createElement('article');
    const visualCode = ['weapon_offhand', 'shield', 'quiver'].includes(slot.code) ? 'offhand' : slot.code;
    const rarityClass = displayItem ? ` rarity-${rarityKey(displayItem)}` : '';
    node.className = `inventory-equipment-slot is-${escapeHtml(visualCode)}${displayItem ? ' has-item' : ''}${isGhostOccupied ? ' is-ghost-occupied' : ''}${rarityClass}`;
    node.dataset.equipmentSlot = slot.code;
    node.dataset.visualSlot = visualCode;
    if (titleLabel) {
        node.title = titleLabel;
    }
    const labelHtml = showLabel
        ? `<span class="inventory-equipment-slot-name">${escapeHtml(equipmentSlotLabel(slot))}</span>`
        : '';
    if (isGhostOccupied) {
        node.dataset.ghostOccupied = '1';
        node.title = 'Ocupado por arma de duas maos';
    }

    if (!displayItem) {
        node.innerHTML = `
            ${labelHtml}
            ${renderEquipmentSlotIcon(slot.code)}
            <span class="inventory-equipment-empty" aria-hidden="true"></span>
        `;
        return node;
    }

    node.innerHTML = `
        ${labelHtml}
        ${renderEquipmentSlotIcon(slot.code)}
        <div class="inventory-equipment-item-shell${isGhostOccupied ? ' is-ghost-shell' : ''}">${renderItem(displayItem, { ghost: isGhostOccupied, hideTypeBadge: true })}</div>
        ${!isGhostOccupied && slot.item ? renderUpgradeStars(slot.item) : ''}
    `;

    const itemNode = node.querySelector('.inventory-item');
    if (!isGhostOccupied) {
        itemNode?.addEventListener('contextmenu', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            await openContextMenu(event, slot.item);
        });
        itemNode?.addEventListener('dblclick', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            await openLinkedContainerForItem(slot.item);
        });
    } else {
        itemNode?.addEventListener('contextmenu', (event) => {
            event.preventDefault();
            event.stopPropagation();
        });
    }

    if (itemNode && window.tippy && !isGhostOccupied) {
        window.tippy(itemNode, {
            allowHTML: true,
            appendTo: () => document.body,
            content: itemTooltip(slot.item),
            interactive: true,
            placement: 'auto',
            popperOptions: { strategy: 'fixed' },
            theme: 'evolvaxe-item',
        });
    }

    return node;
}

function visualSlotCode(slotCode) {
    return ['weapon_offhand', 'shield', 'quiver', 'offhand'].includes(slotCode) ? 'offhand' : slotCode;
}

function equipmentSlotElement(slotCode) {
    const escaped = String(slotCode).replace(/"/g, '\\"');
    const visual = visualSlotCode(slotCode).replace(/"/g, '\\"');

    return equipmentRoot.querySelector(`[data-equipment-slot="${escaped}"]`)
        || equipmentRoot.querySelector(`[data-visual-slot="${visual}"]`);
}

function equipmentSlotCenterInPaperdoll(element, paperdoll) {
    if (!element || !paperdoll || !paperdoll.contains(element)) {
        return null;
    }

    const elementRect = element.getBoundingClientRect();
    const paperdollRect = paperdoll.getBoundingClientRect();
    if (!paperdollRect.width || !paperdollRect.height) {
        return null;
    }

    const scaleX = paperdoll.offsetWidth / paperdollRect.width;
    const scaleY = paperdoll.offsetHeight / paperdollRect.height;

    return {
        x: ((elementRect.left - paperdollRect.left) + (elementRect.width / 2)) * scaleX,
        y: ((elementRect.top - paperdollRect.top) + (elementRect.height / 2)) * scaleY,
    };
}

function renderEquipmentLinks(links = [], setBonuses = []) {
    const svg = equipmentRoot.querySelector('[data-equipment-links]');
    const paperdoll = equipmentRoot.querySelector('.inventory-paperdoll');
    if (!svg || !paperdoll) return;

    svg.replaceChildren();
    svg.setAttribute('viewBox', `0 0 ${paperdoll.offsetWidth} ${paperdoll.offsetHeight}`);
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    equipmentRoot.querySelectorAll('.inventory-equipment-slot.is-set-glow-1, .inventory-equipment-slot.is-set-glow-2, .inventory-equipment-slot.is-set-glow-3')
        .forEach((node) => {
            node.classList.remove('is-set-glow-1', 'is-set-glow-2', 'is-set-glow-3');
            node.style.removeProperty('--set-aura-color');
        });

    for (const link of links) {
        const slots = Array.isArray(link.slots) ? link.slots : [];
        if (slots.length < 2) continue;

        const color = /^#[0-9a-f]{6}$/i.test(String(link.aura_color || '')) ? link.aura_color : '#55c58a';
        const glowLevel = setGlowLevel(link.set_code, setBonuses);
        const glowClass = glowLevel > 0 ? `is-set-glow-${glowLevel}` : '';

        for (const slot of slots) {
            const element = equipmentSlotElement(slot.slot_code);
            if (!element || !glowClass) continue;
            element.classList.add(glowClass);
            element.style.setProperty('--set-aura-color', color);
        }

        const points = slots
            .map((slot) => equipmentSlotElement(slot.slot_code))
            .filter(Boolean)
            .map((element) => equipmentSlotCenterInPaperdoll(element, paperdoll))
            .filter(Boolean);

        if (points.length < 2) continue;

        for (let index = 0; index < points.length - 1; index += 1) {
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', String(points[index].x));
            line.setAttribute('y1', String(points[index].y));
            line.setAttribute('x2', String(points[index + 1].x));
            line.setAttribute('y2', String(points[index + 1].y));
            line.setAttribute('stroke', color);
            line.setAttribute('class', `inventory-equipment-link-line${glowClass ? ` ${glowClass}` : ''}`);
            if (glowLevel > 0) {
                line.setAttribute('stroke-width', String(1.5 + glowLevel));
                line.setAttribute('opacity', String(0.55 + (glowLevel * 0.15)));
            }
            svg.appendChild(line);
        }
    }
}

function equipmentSlotLabel(slot) {
    const visualCode = ['weapon_offhand', 'shield', 'quiver', 'offhand'].includes(slot.code) ? 'offhand' : slot.code;
    const labels = {
        weapon: 'Arma',
        offhand: 'Secundaria',
        helmet: 'Elmo',
        chest: 'Armadura',
        pants: 'Calca',
        boots: 'Botas',
        gloves: 'Luvas',
        belt: 'Neckle',
        ring: 'Anel',
        ring_2: 'Anel',
        amulet: 'Colar',
        wings: 'Asa',
        backpack: 'Mochila',
        pet: 'Pet',
        potion_1: 'Pocao',
        potion_2: 'Pocao',
        potion_3: 'Pocao',
        potion_4: 'Pocao',
        earring: 'Brinco',
    };

    return labels[visualCode] || slot.name;
}

function renderCharacterStats(stats = [], setBonuses = [], power = null, root = null) {
    const target = root || equipmentRoot?.querySelector('[data-character-stats-panel]') || equipmentRoot?.querySelector('[data-character-stats]');
    if (!target) return;

    const visibleStats = stats.filter((stat) => Number(stat.value || 0) !== 0);
    const attack = Number(power?.attack || 0);
    const armor = Number(power?.armor || 0);
    const life = Number(power?.life || 0);
    const total = Number(power?.total || 0);
    const hasCorePower = attack > 0 || armor > 0 || life > 0 || total > 0;

    const coreBlock = hasCorePower
        ? `<div class="inventory-character-power-core">
            <div class="inventory-character-power-total">
                <span>Poder total</span>
                <strong>${total > 0 ? total.toLocaleString('pt-BR') : '—'}</strong>
            </div>
            <div class="inventory-character-power-metrics">
                <div><span>Ataque</span><strong>${attack > 0 ? attack.toLocaleString('pt-BR') : '—'}</strong></div>
                <div><span>Armadura</span><strong>${armor > 0 ? armor.toLocaleString('pt-BR') : '—'}</strong></div>
                <div><span>Vida</span><strong>${life > 0 ? life.toLocaleString('pt-BR') : '—'}</strong></div>
            </div>
        </div>`
        : '';

    if (!visibleStats.length && !setBonuses.length && !hasCorePower) {
        target.innerHTML = '<strong>Status</strong><span class="inventory-character-stat-empty">Sem bonus equipados.</span>';
        return;
    }

    const bonusList = setBonuses.length
        ? `<div class="inventory-set-bonuses">${setBonuses.map((set) => `
            <section>
                <strong style="color: ${/^#[0-9a-f]{6}$/i.test(String(set.aura_color || '')) ? escapeHtml(set.aura_color) : '#55c58a'}">${escapeHtml(set.set_name)}</strong>
                <span>${Number(set.equipped_pieces || 0)} peca(s) vinculada(s)</span>
                ${(set.bonuses || []).map((bonus) => `<small>${escapeHtml(bonus.description || `${bonus.name} +${bonus.value}${bonus.unit || ''}`)}</small>`).join('')}
            </section>
        `).join('')}</div>`
        : '';

    target.innerHTML = `
        <strong>Status</strong>
        ${coreBlock}
        ${visibleStats.length ? `<div class="inventory-character-stat-list">${visibleStats.map((stat) => {
            const numeric = Number(stat.value || 0);
            const value = Number.isInteger(numeric) ? String(numeric) : numeric.toFixed(1);
            return `<div class="inventory-character-stat-row"><span>${escapeHtml(stat.name)}</span><b>${escapeHtml(value)}${stat.unit ? escapeHtml(stat.unit) : ''}</b></div>`;
        }).join('')}</div>` : ''}
        ${bonusList}
    `;
}

function toggleCharacterPanel() {
    toggleLeftDrawer();
}

function renderContainerDock() {
    if (marketToggleButton) {
        marketToggleButton.hidden = false;
        marketToggleButton.classList.toggle('is-active', marketDeliveryOpen);
        marketToggleButton.textContent = marketDeliveryOpen ? 'Fechar entregas' : 'Entregas';
    }
}

function findGridUnderPointer(clientX, clientY) {
    for (const [containerPublicId, grid] of grids) {
        if (!grid?.el) continue;
        if (isPointerInsideElement(grid.el, clientX, clientY)) {
            return { containerPublicId, grid };
        }
    }

    return null;
}

function ghostElementForContainer(containerPublicId) {
    return document.querySelector(`[data-placement-ghost="${containerPublicId}"]`);
}

function resolveJewelType(item) {
    const code = String(item?.definition?.code || '');
    if (BLESS_JEWEL_CODES.includes(code)) return 'bless';
    if (SOUL_JEWEL_CODES.includes(code)) return 'soul';
    if (CHAOS_JEWEL_CODES.includes(code)) return 'chaos';
    if (REROLL_JEWEL_CODES.includes(code)) return 'reroll';
    return null;
}

function equipmentSlotVisualCode(slotCode) {
    return ['weapon_offhand', 'shield', 'quiver', 'offhand'].includes(slotCode) ? 'offhand' : slotCode;
}

function equipmentSlotIconUrl(slotCode) {
    const file = EQUIPMENT_SLOT_ICON_FILES[equipmentSlotVisualCode(slotCode)];
    return file ? `/assets/game/icons/${file}` : null;
}

function renderEquipmentSlotIcon(slotCode) {
    const iconUrl = equipmentSlotIconUrl(slotCode);
    if (!iconUrl) {
        return '';
    }

    return `
        <span class="inventory-equipment-slot-icon" aria-hidden="true">
            <img src="${escapeHtml(iconUrl)}" alt="" loading="lazy" onerror="this.closest('.inventory-equipment-slot-icon')?.classList.add('is-missing-icon'); this.remove();">
        </span>
    `;
}

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
    if (overlaps.length !== 1) return null;

    const target = itemIndex.get(overlaps[0].id)?.item;
    if (!target || !canAttemptEnhance(sourceItem, target)) return null;

    return {
        target,
        jewelType: resolveJewelType(sourceItem),
    };
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
    if (overlaps.length !== 1) return null;

    const target = itemIndex.get(overlaps[0].id)?.item;
    if (!target || !canAttemptSocket(sourceItem, target)) return null;

    return { target };
}

function clearAllGhostPreviews() {
    for (const ghost of document.querySelectorAll('[data-placement-ghost]')) {
        ghost.hidden = true;
        ghost.replaceChildren();
        ghost.classList.remove('is-valid', 'is-invalid', 'is-merge', 'is-deposit', 'is-bless', 'is-soul', 'is-chaos', 'is-reroll', 'is-socket');
    }
}

function cleanupDragUi() {
    clearAllGhostPreviews();
    document.querySelectorAll('.grid-stack-placeholder').forEach((placeholder) => placeholder.remove());
    document
        .querySelectorAll('.inventory-placement-valid, .inventory-placement-invalid, .inventory-placement-merge, .inventory-placement-deposit, .inventory-placement-bless, .inventory-placement-soul, .inventory-placement-chaos, .inventory-placement-reroll, .inventory-placement-socket, .inventory-rotated-preview')
        .forEach((element) => clearPlacementHint(element));
    document.querySelectorAll('.inventory-rotation-helper').forEach((element) => clearRotationHelper(element));
}

function findOverlappingPlacements(snapshot, x, y, w, h) {
    return snapshot.filter((placement) => overlaps(
        x,
        y,
        w,
        h,
        placement.x,
        placement.y,
        placement.w,
        placement.h
    ));
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

    const targetContainer = containerIndex.get(containerPublicId);
    if (targetContainer && !canContainerAcceptItem(targetContainer, current.item)) {
        return { state: 'invalid', reason: 'acceptance' };
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

    const overlapPlacement = findOverlapInSnapshot(snapshot, x, y, w, h);
    const overlapItem = overlapPlacement ? itemIndex.get(overlapPlacement.id)?.item : null;

    if (overlapItem?.definition?.is_container) {
        return { state: 'invalid', reason: 'container_full' };
    }

    const valid = isPlacementValidAgainstSnapshot(containerPublicId, snapshot, x, y, w, h);
    return { state: valid ? 'valid' : 'invalid' };
}

function renderGhostPreview(containerPublicId, x, y, w, h, state) {
    const ghost = ghostElementForContainer(containerPublicId);
    if (!ghost) return;

    const cellSize = cellSizeForContainer(containerPublicId);
    ghost.hidden = false;
    ghost.classList.remove('is-valid', 'is-invalid', 'is-merge', 'is-deposit', 'is-bless', 'is-soul', 'is-chaos', 'is-reroll', 'is-socket');
    ghost.classList.add(`is-${state}`);
    ghost.replaceChildren();

    for (let row = y; row < y + h; row += 1) {
        for (let col = x; col < x + w; col += 1) {
            const cell = document.createElement('span');
            cell.className = 'inventory-ghost-cell';
            cell.style.left = `${col * cellSize}px`;
            cell.style.top = `${row * cellSize}px`;
            cell.style.width = `${cellSize}px`;
            cell.style.height = `${cellSize}px`;
            ghost.appendChild(cell);
        }
    }
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

    const size = dimensionsForState(current.item, current.rotated);
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

function onDocumentDragPointer(event) {
    if (!activeDrag) return;

    activeDrag.pointerX = event.clientX;
    activeDrag.pointerY = event.clientY;
    if (activeDrag.rotationAnchor && hasPointerMovedFromRotationAnchor(event.clientX, event.clientY)) {
        activeDrag.rotationAnchor = null;
    }

    if (syncHoverFromPointer(event.clientX, event.clientY)) {
        updateAllGhostPreviews(event.clientX, event.clientY);
    }

    const dragged = findDraggedWidget();
    if (dragged?.element) {
        enforceDraggedFootprint(dragged);
        updatePlacementHint(dragged.element);
    }
}

function beginDragSession() {
    document.addEventListener('mousemove', onDocumentDragPointer);
}

function endDragSession() {
    document.removeEventListener('mousemove', onDocumentDragPointer);
    unlockStaticNodesForDrag();
    cleanupDragUi();
}

function destroyGrids() {
    endDragSession();
    for (const grid of grids.values()) {
        grid.destroy(false);
    }
    grids = new Map();
    gridCellSizes.clear();
    itemIndex = new Map();
    containerIndex = new Map();
    dragSnapshots = new Map();
    activeDrag = null;
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

function gridCellFromPointer(gridEl, clientX, clientY, footprintW, footprintH, columns, rows) {
    const rect = gridEl.getBoundingClientRect();
    const containerPublicId = gridEl?.dataset?.containerPublicId;
    const cellSize = containerPublicId ? cellSizeForContainer(containerPublicId) : resolveCellSize(gridEl);
    const rawX = Math.floor((clientX - rect.left) / cellSize);
    const rawY = Math.floor((clientY - rect.top) / cellSize);
    const maxX = Math.max(0, columns - footprintW);
    const maxY = Math.max(0, rows - footprintH);

    return {
        x: Math.max(0, Math.min(rawX, maxX)),
        y: Math.max(0, Math.min(rawY, maxY)),
    };
}

function clampCell(x, y, width, height, columns, rows) {
    return {
        x: Math.max(0, Math.min(Math.round(Number(x || 0)), Math.max(0, columns - width))),
        y: Math.max(0, Math.min(Math.round(Number(y || 0)), Math.max(0, rows - height))),
    };
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

function targetSnapshotForGrid(containerPublicId, grid, exceptItemPublicId) {
    if (!activeDrag || !grid) return placementSnapshotForGrid(grid, exceptItemPublicId);

    if (!activeDrag.targetSnapshots.has(containerPublicId)) {
        activeDrag.targetSnapshots.set(
            containerPublicId,
            placementSnapshotForGrid(grid, exceptItemPublicId)
        );
    }

    return activeDrag.targetSnapshots.get(containerPublicId) || [];
}

function overlaps(ax, ay, aw, ah, bx, by, bw, bh) {
    return ax < (bx + bw) && (ax + aw) > bx && ay < (by + bh) && (ay + ah) > by;
}

function baseDimensions(item) {
    return {
        w: Number(item.definition?.grid_w || 1),
        h: Number(item.definition?.grid_h || 1),
    };
}

function dimensionsForState(item, rotated) {
    const base = baseDimensions(item);
    if (!rotated) return base;
    return { w: base.h, h: base.w };
}

function snapshotNodes(grid, excludeItemPublicId = null) {
    return grid.engine.nodes
        .filter((node) => node.id && node.id !== excludeItemPublicId)
        .map((node) => ({
            id: node.id,
            x: Number(node.x || 0),
            y: Number(node.y || 0),
            w: Number(node.w || 1),
            h: Number(node.h || 1),
            el: node.el,
        }));
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

function placementSnapshotForGrid(grid, exceptItemPublicId) {
    if (!grid) return [];
    return snapshotNodes(grid, exceptItemPublicId);
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

function findOverlapInSnapshot(snapshot, x, y, w, h) {
    for (const placement of snapshot) {
        if (overlaps(x, y, w, h, placement.x, placement.y, placement.w, placement.h)) {
            return placement;
        }
    }

    return null;
}

function findOverlappingItemFromSnapshot(snapshot, x, y, w, h) {
    const placement = findOverlapInSnapshot(snapshot, x, y, w, h);
    if (!placement) return null;

    return itemIndex.get(placement.id)?.item || { public_id: placement.id };
}

function isInsideBounds(containerPublicId, x, y, w, h) {
    const container = containerIndex.get(containerPublicId);
    if (!container) return false;

    return x >= 0
        && y >= 0
        && (x + w) <= Number(container.grid.columns || 0)
        && (y + h) <= Number(container.grid.rows || 0);
}

function isPlacementValidAgainstSnapshot(containerPublicId, snapshot, x, y, w, h) {
    return isInsideBounds(containerPublicId, x, y, w, h)
        && findOverlapInSnapshot(snapshot, x, y, w, h) === null;
}

function findFirstFreeSlot(containerPublicId, width, height) {
    const container = containerIndex.get(containerPublicId);
    if (!container) return null;

    const columns = Number(container.grid.columns || 0);
    const rows = Number(container.grid.rows || 0);
    const maxY = rows - height;
    const maxX = columns - width;

    if (maxY < 0 || maxX < 0) return null;

    const grid = grids.get(containerPublicId);
    const snapshot = grid ? snapshotNodes(grid) : [];

    for (let y = 0; y <= maxY; y += 1) {
        for (let x = 0; x <= maxX; x += 1) {
            if (isPlacementValidAgainstSnapshot(containerPublicId, snapshot, x, y, width, height)) {
                return { grid_x: x, grid_y: y };
            }
        }
    }

    return null;
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
    if (overlaps.length !== 1) return null;

    const target = itemIndex.get(overlaps[0].id)?.item;
    if (!target || !canAttemptMerge(sourceItem, target)) return null;

    return target;
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
        element.style.setProperty('--inventory-rotation-w', String(width));
        element.style.setProperty('--inventory-rotation-h', String(height));
        element.classList.add('inventory-rotation-helper');
    });
}

function enforceDraggedFootprint(dragged = null) {
    if (!activeDrag?.itemPublicId) return;

    const widget = dragged || findDraggedWidget();
    if (!widget?.node?.el || !widget.grid) return;

    const current = itemIndex.get(activeDrag.itemPublicId);
    if (!current) return;

    const size = dimensionsForState(current.item, Boolean(current.rotated));
    const node = widget.node;
    if (Number(node.w || 1) === size.w && Number(node.h || 1) === size.h) {
        if (node.el.classList.contains('ui-draggable-dragging')) {
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

    if (node.el.classList.contains('ui-draggable-dragging')) {
        applyRotationHelper(activeDrag.itemPublicId, size.w, size.h);
        window.requestAnimationFrame(() => applyRotationHelper(activeDrag.itemPublicId, size.w, size.h));
    }
}

function updatePlacementHint(element) {
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
        loadInventory();
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

    const snapshot = snapshotNodes(dragged.grid, movingItemPublicId);
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
    activeDrag.targetSnapshots.clear();

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

function clearActiveDrag() {
    if (activeDrag) {
        cleanupDragUi();
    }
    activeDrag = null;
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

    const size = dimensionsForState(current.item, current.rotated);
    const sameContainer = targetContainerPublicId === activeDrag.sourceContainerPublicId;
    let x;
    let y;

    if (sameContainer) {
        const dropCell = resolveDropCell(targetContainerPublicId, node, coords);
        x = dropCell.x;
        y = dropCell.y;
        activeDrag.hoverX = x;
        activeDrag.hoverY = y;
        activeDrag.hoverContainerPublicId = targetContainerPublicId;
    } else if (
        activeDrag.hoverContainerPublicId === targetContainerPublicId
        && activeDrag.hoverX != null
        && activeDrag.hoverY != null
    ) {
        x = activeDrag.hoverX;
        y = activeDrag.hoverY;
    } else if (coords) {
        const grid = grids.get(targetContainerPublicId);
        const container = containerIndex.get(targetContainerPublicId);
        if (!grid?.el || !container) return null;

        const cell = gridCellFromPointer(
            grid.el,
            coords.clientX,
            coords.clientY,
            size.w,
            size.h,
            Number(container.grid.columns || 0),
            Number(container.grid.rows || 0)
        );
        x = cell.x;
        y = cell.y;
    } else if (activeDrag.pointerX != null && activeDrag.pointerY != null) {
        const grid = grids.get(targetContainerPublicId);
        const container = containerIndex.get(targetContainerPublicId);
        if (!grid?.el || !container) return null;

        const cell = gridCellFromPointer(
            grid.el,
            activeDrag.pointerX,
            activeDrag.pointerY,
            size.w,
            size.h,
            Number(container.grid.columns || 0),
            Number(container.grid.rows || 0)
        );
        x = cell.x;
        y = cell.y;
    } else {
        return null;
    }

    const rotated = activeDrag.depositRotated != null && activeDrag.hoverState === 'deposit'
        ? Boolean(activeDrag.depositRotated)
        : Boolean(current.rotated);
    const finalSize = dimensionsForState(current.item, rotated);

    return {
        item_public_id: itemPublicId,
        target_container_public_id: targetContainerPublicId,
        grid_x: x,
        grid_y: y,
        grid_w: finalSize.w,
        grid_h: finalSize.h,
        rotated,
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
    const interaction = buildInteraction(targetContainerPublicId, node, coords);
    if (!interaction || !grid) {
        clearActiveDrag();
        return;
    }

    const source = itemIndex.get(interaction.item_public_id);
    const snapshot = dragSnapshots.get(interaction.item_public_id);
    if (!source || !snapshot) {
        clearActiveDrag();
        return;
    }

    restoreOtherNodes(grid, interaction.item_public_id);
    clearPlacementHint(node.el);

    const sourceItem = source.item;
    const depositMove = resolveDepositMove(interaction, sourceItem);
    if (depositMove && hasPlacementChanged(snapshot, depositMove)) {
        activeDrag.handled = true;
        clearActiveDrag();
        await attemptMove(source, depositMove);
        toast('Item guardado na mochila.', 'success', 2600);
        highlightContainer(depositMove.target_container_public_id);
        return;
    }

    const targetSnapshot = targetSnapshotForGrid(targetContainerPublicId, grid, interaction.item_public_id);
    const dropEvaluation = evaluatePlacement(
        targetContainerPublicId,
        grid,
        interaction.item_public_id,
        interaction.grid_x,
        interaction.grid_y,
        interaction.grid_w,
        interaction.grid_h,
        coords
    );

    if (dropEvaluation.state === 'merge' && dropEvaluation.overlapItem) {
        activeDrag.handled = true;
        clearActiveDrag();
        await attemptMerge(sourceItem, dropEvaluation.overlapItem);
        return;
    }

    if ((dropEvaluation.state === 'bless' || dropEvaluation.state === 'soul' || dropEvaluation.state === 'chaos' || dropEvaluation.state === 'reroll') && dropEvaluation.overlapItem) {
        activeDrag.handled = true;
        revertItem(interaction.item_public_id);
        clearActiveDrag();
        await attemptEnhance(sourceItem, dropEvaluation.overlapItem, dropEvaluation.state);
        return;
    }

    if (dropEvaluation.state === 'socket' && dropEvaluation.overlapItem) {
        activeDrag.handled = true;
        revertItem(interaction.item_public_id);
        clearActiveDrag();
        await attemptSocket(sourceItem, dropEvaluation.overlapItem);
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
        return;
    }

    activeDrag.handled = true;
    clearActiveDrag();
    await attemptMove(source, interaction);
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
        float: true,
        animate: false,
        acceptWidgets: false,
        disableOneColumnMode: true,
        draggable: {
            handle: '.grid-stack-item-content',
        },
        disableResize: true,
        removable: false,
        staticGrid: false,
    }, gridNode);

    grid.float(true);
    if (grid.engine) {
        grid.engine.float = true;
    }

    grid.on('dragstart', (_event, element) => {
        const node = element?.gridstackNode;
        if (!node?.id) return;

        const current = itemIndex.get(node.id);
        if (!current) return;

        const size = dimensionsForState(current.item, current.rotated);

        dragSnapshots.set(node.id, {
            container_public_id: container.public_id,
            grid_x: Number(node.x || 0),
            grid_y: Number(node.y || 0),
            grid_w: size.w,
            grid_h: size.h,
            rotated: Boolean(current.rotated),
        });

        activeDrag = {
            itemPublicId: node.id,
            sourceContainerPublicId: container.public_id,
            grid,
            sourceSnapshot: snapshotNodes(grid, node.id),
            targetSnapshots: new Map(),
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
        };

        lockStaticNodesForDrag();
        beginDragSession();
    });

    grid.on('drag', (event, element) => {
        if (silent || loading || actionInFlight || !activeDrag) return;

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
            updateAllGhostPreviews(coords.clientX, coords.clientY);
        }

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
        if (silent || loading || actionInFlight || activeDrag?.handled) return;

        const node = element?.gridstackNode;
        if (!node?.id || !activeDrag || node.id !== activeDrag.itemPublicId) return;

        const coords = dragPointerCoords(event);

        if (coords) {
            syncHoverFromPointer(coords.clientX, coords.clientY);
        }

        enforceDraggedFootprint({ containerPublicId: container.public_id, grid, node, element });

        try {
            await finalizeDrag(event);
        } catch (error) {
            console.error('[inventory-drag]', error);
            revertItem(node.id);
        } finally {
            endDragSession();
            clearActiveDrag();
        }
    });

    grids.set(container.public_id, grid);
    return grid;
}

function bindItemShortcuts(container, item, widget) {
    const content = widget?.querySelector('.grid-stack-item-content');
    if (!content) return;

    content.addEventListener('click', async (event) => {
        if (!event.ctrlKey && !event.metaKey) return;
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

function closeContextMenu() {
    const menu = document.querySelector('[data-inventory-context-menu]');
    if (menu) {
        menu.hidden = true;
        menu.replaceChildren();
    }
    contextMenuState = null;
}

function ensureContextMenuRoot() {
    let menu = document.querySelector('[data-inventory-context-menu]');
    if (!menu) {
        menu = document.createElement('div');
        menu.className = 'inventory-context-menu';
        menu.dataset.inventoryContextMenu = '';
        menu.hidden = true;
        document.body.appendChild(menu);
    }

    return menu;
}

function positionContextMenu(menu, clientX, clientY) {
    menu.hidden = false;
    menu.style.left = '0px';
    menu.style.top = '0px';

    const rect = menu.getBoundingClientRect();
    const maxX = window.innerWidth - rect.width - 8;
    const maxY = window.innerHeight - rect.height - 8;
    const left = Math.max(8, Math.min(clientX, maxX));
    const top = Math.max(8, Math.min(clientY, maxY));

    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
}

function renderContextMenu(menu, item, actions) {
    menu.replaceChildren();
    menu.className = `inventory-context-menu rarity-${rarityKey(item)}`;

    const header = document.createElement('div');
    header.className = 'inventory-context-menu-header';
    header.innerHTML = `
        <strong>${escapeHtml(itemLabel(item))}</strong>
        <span>${escapeHtml(rarityLabel(item))} - ${escapeHtml(item.definition?.code || '')}</span>
    `;
    menu.appendChild(header);

    if (!actions.length) {
        const empty = document.createElement('div');
        empty.className = 'inventory-context-menu-empty';
        empty.textContent = 'Nenhuma acao disponivel.';
        menu.appendChild(empty);
        return;
    }

    const list = document.createElement('div');
    list.className = 'inventory-context-menu-list';

    const renameButton = document.createElement('button');
    renameButton.type = 'button';
    renameButton.className = 'inventory-context-menu-item';
    renameButton.innerHTML = `
        <span class="inventory-context-menu-item-label">Renomear</span>
        <span class="inventory-context-menu-item-description">Definir nome do bau ou bag</span>
    `;
    renameButton.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        closeContextMenu();
        await renameStorageContainerItem(item);
    });
    if (isStorageContainerItem(item)) {
        list.appendChild(renameButton);
    }

    for (const action of actions) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'inventory-context-menu-item';
        if (action.is_destructive) {
            button.classList.add('is-destructive');
        }

        const label = document.createElement('span');
        label.className = 'inventory-context-menu-item-label';
        label.textContent = action.name || action.code;
        button.appendChild(label);

        if (action.description) {
            const description = document.createElement('span');
            description.className = 'inventory-context-menu-item-description';
            description.textContent = action.description;
            button.appendChild(description);
        }

        button.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            closeContextMenu();
            await executeItemAction(item, action);
        });

        list.appendChild(button);
    }

    menu.appendChild(list);
}

async function openContextMenu(event, item) {
    if (actionInFlight || loading || activeDrag) return;

    closeContextMenu();

    const menu = ensureContextMenuRoot();
    menu.innerHTML = '<div class="inventory-context-menu-loading">Carregando acoes...</div>';
    positionContextMenu(menu, event.clientX, event.clientY);
    contextMenuState = { itemPublicId: item.public_id };

    try {
        const response = await apiFetch(`/api/items/${encodeURIComponent(item.public_id)}/actions`);
        const actions = response.data?.actions || [];
        renderContextMenu(menu, item, actions);
        positionContextMenu(menu, event.clientX, event.clientY);
    } catch (error) {
        closeContextMenu();
        handleError(error, 'Nao foi possivel carregar acoes do item.');
    }
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

async function openLinkedContainerForItem(item) {
    if (item?.definition?.equip_slot_code === 'backpack' && item.public_id === equippedBackpackPublicId) {
        expeditionCarryOpen = true;
        persistContainerPanels();
        await loadInventory();
        const expedition = [...containerIndex.values()].find((container) => containerKind(container) === 'expedition_carry');
        if (expedition?.public_id) {
            highlightContainer(expedition.public_id);
        }
        return true;
    }

    const linked = item?.linked_container || itemIndex.get(item?.public_id)?.linked_container || null;
    if (!linked?.public_id) return false;

    if (openContainerPublicIds.has(linked.public_id)) {
        openContainerPublicIds.delete(linked.public_id);
        clearSplitView();
        persistContainerPanels();
        await loadInventory();
        return true;
    }

    const mainContainer = resolveSplitParentContainer(linked.public_id, [...containerIndex.values()]);
    if (mainContainer) {
        splitViewState = {
            parentPublicId: mainContainer.public_id,
            childPublicId: linked.public_id,
        };
        persistSplitView();
    } else {
        clearSplitView();
    }

    openContainer(linked.public_id);
    await loadInventory();
    highlightContainer(linked.public_id);
    return true;
}

function bindContainerLinks() {
    for (const section of document.querySelectorAll('.inventory-container-physical[data-source-item-public-id]')) {
        const sourceItemPublicId = section.dataset.sourceItemPublicId;
        const link = section.querySelector('.inventory-container-link');
        if (!link || !sourceItemPublicId) continue;

        link.addEventListener('click', (event) => {
            event.preventDefault();
            highlightItem(sourceItemPublicId);
        });
    }

    for (const content of document.querySelectorAll('.inventory-item.is-container-item')) {
        const itemPublicId = content.dataset.itemPublicId;
        if (!itemPublicId) continue;

        content.addEventListener('dblclick', (event) => {
            event.preventDefault();
            event.stopPropagation();
            openLinkedContainerForItem(itemIndex.get(itemPublicId)?.item || null);
        });
    }
}

function renderSummary(summary, wallets = []) {
    if (!summaryNode) return;

    const containers = summary?.containers?.length || 0;
    const items = summary?.item_count || 0;
    const equipped = summary?.equipped_item_count || 0;
    const gold = walletBalance('gold') || Number(wallets.find((entry) => (entry.currency_code || entry.code) === 'gold')?.balance || 0);
    const premium = walletBalance('premium') || Number(wallets.find((entry) => (entry.currency_code || entry.code) === 'premium')?.balance || 0);
    summaryNode.textContent = `${containers} containers · ${items} itens · ${equipped} equipado(s) · ${gold.toLocaleString('pt-BR')} G · ${premium.toLocaleString('pt-BR')} 💎`;
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

    if (action.requires_confirmation) {
        const label = action.name || action.code;
        let bodyHtml = `<p>Confirmar esta acao em <strong>${escapeHtml(itemLabel(item))}</strong>?</p>`;

        if (action.code === 'SELL') {
            const npcValue = Number(item?.npc_value || 0);
            const marketValue = Number(item?.market_value || 0);
            bodyHtml = `<p>Vender <strong>${escapeHtml(itemLabel(item))}</strong> ao NPC?</p>
                <p>Valor de mercado: <strong>${marketValue} G</strong><br>Venda NPC: <strong>${npcValue} G</strong></p>`;
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
                await loadInventory();
                if (marketPanelOpen) await loadMarketListings();
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
            await loadInventory();
            if (materialsPanelOpen) await loadMaterialsStash();
            return;
        }

        if (data.action === 'INSPECT') {
            toast(inspectSummary(data), 'info', 5200);
            setStatus('Sincronizado');
            return;
        }

        if (data.action === 'SELL') {
            toast(`Vendido por ${Number(data.gold_received || 0)} G (saldo: ${Number(data.gold_balance || 0)} G).`, 'success', 3600);
            setStatus('Sincronizado');
            await loadInventory();
            return;
        }

        if (data.action === 'LIST_MARKET') {
            toast(`Anunciado por ${Number(data.price_premium || 0)} 💎.`, 'success', 3200);
            setStatus('Sincronizado');
            await loadInventory();
            return;
        }

        if (data.action === 'OPEN') {
            toast(`Container aberto: ${data.container_name || data.container_definition_code}`, 'success', 3200);
            setStatus('Sincronizado');
            openContainer(data.container_public_id);
            await loadInventory();
            highlightContainer(data.container_public_id);
            return;
        }

        toast('Acao concluida.', 'success', 2600);
        setStatus('Sincronizado');
        await loadInventory();
    } catch (error) {
        handleError(error, 'Acao rejeitada pelo servidor.');
        await loadInventory();
    } finally {
        actionInFlight = false;
    }
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
        bindItemShortcuts(container, item, widget);

        const content = widget?.querySelector('.grid-stack-item-content');
        if (content && window.tippy) {
            window.tippy(content, {
                allowHTML: true,
                content: itemTooltip(item),
                theme: 'evolvaxe-item',
                placement: 'auto',
                interactive: true,
                appendTo: () => document.body,
                popperOptions: {
                    strategy: 'fixed',
                },
                delay: [180, 80],
            });
        }
    }
}

async function attemptEnhance(jewelItem, targetItem, jewelType) {
    if (actionInFlight) return false;

    actionInFlight = true;

    try {
        setStatus('Avaliando melhoria...');
        const previewResponse = await apiFetch('/api/inventory/enhance/preview', {
            method: 'POST',
            body: {
                jewel_item_public_id: jewelItem.public_id,
                target_item_public_id: targetItem.public_id,
            },
        });
        const preview = previewResponse.data || {};

        if (!preview.can_apply) {
            toast(preview.reason_message || 'Esta joia nao pode ser aplicada neste item.', 'error', 3600);
            setStatus('Sincronizado');
            return false;
        }

        const rate = Number(preview.success_rate || 0);
        const rateLabel = rate.toFixed(1);
        const jewelLabel = jewelType === 'bless'
            ? 'Joia da Bencao'
            : jewelType === 'soul'
                ? 'Joia da Alma'
                : jewelType === 'chaos'
                    ? 'Joia do Caos'
                    : 'Joia de Rerrolagem';
        const lines = [
            `${jewelLabel} em ${itemLabel(targetItem)}`,
        ];

        if (jewelType === 'chaos') {
            lines.push(`Raridade atual: ${preview.current_quality_bucket || 'common'}`);
            const outcomes = Array.isArray(preview.outcome_chances) ? preview.outcome_chances : [];
            for (const outcome of outcomes) {
                const tier = String(outcome.tier || '');
                const chance = Number(outcome.chance || 0).toFixed(1);
                lines.push(`${tier === 'failure' ? 'Falha instavel' : `Virar ${tier}`}: ${chance}%`);
            }
        } else {
            lines.push(`Chance de sucesso: ${rateLabel}%`);
            const breakdown = preview.success_rate_breakdown;
            if (breakdown) {
                lines.push(`Base da joia: ${Number(breakdown.base_rate || 0).toFixed(1)}%`);
                lines.push(`Apos nivel: ${Number(breakdown.after_decay || 0).toFixed(1)}%`);
                if (Number(breakdown.item_bonus_percent || 0) > 0) {
                    lines.push(`Bonus do item: +${Number(breakdown.item_bonus_percent).toFixed(1)}%`);
                }
            }
        }

        if (preview.current_upgrade_level != null) {
            lines.push(`Nivel atual: +${preview.current_upgrade_level}`);
        }
        if (preview.affix_count != null) {
            lines.push(`Atributos no item: ${preview.affix_count}`);
        }
        lines.push('A joia sera consumida independentemente do resultado.');

        if (jewelType === 'chaos' || jewelType === 'reroll' || rate < 50) {
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
        }

        setStatus('Aplicando joia...');
        const result = await apiFetch('/api/inventory/enhance', {
            method: 'POST',
            body: {
                jewel_item_public_id: jewelItem.public_id,
                target_item_public_id: targetItem.public_id,
                expected_jewel_placement_version: Number(jewelItem.placement?.placement_version || 1),
                expected_target_placement_version: Number(targetItem.placement?.placement_version || 1),
                confirm: true,
            },
        });

        const data = result.data || {};
        setStatus('Sincronizado');
        dragSnapshots.delete(jewelItem.public_id);
        await loadInventory();
        showEnhanceResultModal(jewelType, targetItem, data);
        return true;
    } catch (error) {
        handleError(error, 'Melhoria rejeitada pelo servidor.');
        revertItem(jewelItem.public_id);
        return false;
    } finally {
        actionInFlight = false;
        clearActiveDrag();
    }
}

async function attemptSocket(gemItem, targetItem) {
    if (actionInFlight) return false;

    actionInFlight = true;

    try {
        setStatus('Avaliando engaste...');
        const previewResponse = await apiFetch('/api/inventory/socket/preview', {
            method: 'POST',
            body: {
                gem_item_public_id: gemItem.public_id,
                target_item_public_id: targetItem.public_id,
            },
        });
        const preview = previewResponse.data || {};

        if (!preview.can_apply) {
            toast(preview.reason_message || 'Esta gema nao pode ser encaixada neste item.', 'error', 3600);
            setStatus('Sincronizado');
            return false;
        }

        const effect = preview.gem_effect || {};
        const propertyName = effect.property_name || effect.property || 'Atributo';
        const lines = [
            `Encaixar gema em ${itemLabel(targetItem)}`,
            `Efeito: +${effect.value ?? '?'} ${propertyName}`,
            `Engastes livres: ${preview.empty_socket_count ?? 1}`,
            'A gema sera consumida ao confirmar.',
        ];

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

        setStatus('Encaixando gema...');
        const result = await apiFetch('/api/inventory/socket', {
            method: 'POST',
            body: {
                gem_item_public_id: gemItem.public_id,
                target_item_public_id: targetItem.public_id,
                expected_gem_placement_version: Number(gemItem.placement?.placement_version || 1),
                expected_target_placement_version: Number(targetItem.placement?.placement_version || 1),
                confirm: true,
            },
        });

        const data = result.data || {};
        setStatus('Sincronizado');
        dragSnapshots.delete(gemItem.public_id);
        await loadInventory();
        showSocketResultModal(targetItem, data);
        return true;
    } catch (error) {
        handleError(error, 'Engaste rejeitado pelo servidor.');
        revertItem(gemItem.public_id);
        return false;
    } finally {
        actionInFlight = false;
        clearActiveDrag();
    }
}

async function attemptMerge(sourceItem, targetItem) {
    const quantity = mergeQuantityForItems(sourceItem, targetItem);
    if (quantity <= 0) return false;

    actionInFlight = true;
    silent = true;

    try {
        setStatus('Mesclando...');
        await apiFetch('/api/inventory/stacks/merge', {
            method: 'POST',
            body: {
                source_item_public_id: sourceItem.public_id,
                target_item_public_id: targetItem.public_id,
                quantity,
            },
        });

        toast('Stacks mesclados.', 'success', 2400);
        setStatus('Sincronizado');
        dragSnapshots.delete(sourceItem.public_id);
        await loadInventory();
        return true;
    } catch (error) {
        handleError(error, 'Merge rejeitado pelo servidor.');
        revertItem(sourceItem.public_id);
        return false;
    } finally {
        actionInFlight = false;
        silent = false;
        clearActiveDrag();
    }
}

async function quickSplit(containerPublicId, item) {
    if (actionInFlight || loading) return;

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

    actionInFlight = true;
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
        await loadInventory();
    } catch (error) {
        handleError(error, 'Split rejeitado pelo servidor.');
        await loadInventory();
    } finally {
        actionInFlight = false;
        silent = false;
    }
}

async function attemptMove(source, interaction) {
    actionInFlight = true;

    try {
        setStatus('Salvando...');
        await apiFetch('/api/inventory/move', {
            method: 'POST',
            body: {
                item_public_id: interaction.item_public_id,
                source_container_public_id: dragSnapshots.get(interaction.item_public_id)?.container_public_id || source.container_public_id,
                target_container_public_id: interaction.target_container_public_id,
                grid_x: interaction.grid_x,
                grid_y: interaction.grid_y,
                rotated: interaction.rotated,
                expected_placement_version: source.placement_version,
            },
        });

        setStatus('Sincronizado');
        dragSnapshots.delete(interaction.item_public_id);
        await loadInventory();
        return true;
    } catch (error) {
        handleError(error, 'Movimento rejeitado pelo servidor.');
        revertItem(interaction.item_public_id);
        return false;
    } finally {
        actionInFlight = false;
        clearActiveDrag();
    }
}

async function loadInventory() {
    if (loading || !app) return;

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
        const equipment = response.data?.equipment || [];
        const equipmentLinks = response.data?.equipment_links || [];
        const activeSetBonuses = response.data?.active_set_bonuses || [];
        currentEquipment = equipment;
        currentEquipmentLinks = equipmentLinks;
        currentSetBonuses = activeSetBonuses;
        equippedBackpackPublicId = equipment.find((slot) => slot.code === 'backpack' && slot.item)?.item?.public_id || null;
        if (equippedBackpackPublicId) {
            expeditionCarryOpen = true;
        }
        const characterStats = response.data?.character_stats || [];
        playerPower = response.data?.player_power || null;
        playerWallets = response.data?.wallets || [];
        if (marketPanelOpen) renderMarketWallets();
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
        }

        const expeditionContainer = containers.find((container) => containerKind(container) === 'expedition_carry');
        if (expeditionContainer) {
            expeditionCarryOpen = true;
            renderExpeditionDrawerSection(
                expeditionContainer,
                summaryByPublicId.get(expeditionContainer.public_id) || null
            );
        }

        const visibleContainers = containers.filter(isRightDrawerContainerVisible);

        if (!visibleContainers.length) {
            containerRoot.innerHTML = '<div class="inventory-empty">Nenhum container encontrado.</div>';
            setStatus('Vazio');
            return;
        }

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
        if (comparePanelState?.item?.public_id) {
            const refreshed = itemIndex.get(comparePanelState.item.public_id)?.item;
            if (refreshed) {
                comparePanelState.item = refreshed;
                renderComparePanel();
            } else {
                closeComparePanel();
            }
        }
        setStatus('Sincronizado');
        if (craftPanelOpen) {
            renderCraftPanel();
            refreshCraftPreview();
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
        const renameModal = document.querySelector('[data-inventory-rename-modal]');
        if (renameModal && !renameModal.hidden) {
            closeRenameModal();
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
    if (event.key === 'b' || event.key === 'B') {
        event.preventDefault();
        toggleMaterialsPanel();
    }
    if (event.key === 'f' || event.key === 'F') {
        event.preventDefault();
        toggleCraftPanel();
    }
    if (['1', '2', '3', '4'].includes(event.key)) {
        const slotCode = `potion_${event.key}`;
        event.preventDefault();
        useEquippedPotionHotkey(slotCode);
    }
});

refreshButton?.addEventListener('click', () => loadInventory());

document.addEventListener('click', (event) => {
    const menu = document.querySelector('[data-inventory-context-menu]');
    if (!menu || menu.hidden) return;
    if (event.target instanceof Node && menu.contains(event.target)) return;
    closeContextMenu();
});

initDrawerControls();
syncDrawerUi();
loadInventory();
console.info('[inventory] drag engine', INVENTORY_DRAG_ENGINE);
