import { ApiError, apiFetch } from '/assets/framework/api.js';
import { openModal, installModalStyles } from '/assets/framework/modal.js';
import { installToastStyles, toast } from '/assets/framework/toast.js';

const app = document.querySelector('[data-inventory-app]');
const containerRoot = document.querySelector('[data-inventory-containers]');
const equipmentRoot = document.querySelector('[data-inventory-equipment]');
const dockRoot = document.querySelector('[data-inventory-dock]');
const statusNode = document.querySelector('[data-inventory-status]');
const summaryNode = document.querySelector('[data-inventory-summary]');
const refreshButton = document.querySelector('[data-inventory-refresh]');
const compareDockRoot = document.querySelector('[data-inventory-compare]');

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
        element.querySelector('.inventory-modal-confirm')?.addEventListener('click', () => finish(true));
    });
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
let expeditionCarryOpen = localStorage.getItem('evolvaxe.inventory.expeditionCarryOpen') !== '0';
let characterPanelOpen = localStorage.getItem('evolvaxe.inventory.characterPanelOpen') !== '0';
let equippedBackpackPublicId = null;
let playerPower = null;
let currentEquipment = [];
let currentEquipmentLinks = [];
let currentSetBonuses = [];
let comparePanelState = null;
let splitViewState = JSON.parse(localStorage.getItem('evolvaxe.inventory.splitView') || 'null');
let inventorySummaryByPublicId = new Map();

const CELL_SIZE = 44;
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

function renderUpgradeStars(item) {
    const level = upgradeLevelFromItem(item);
    if (level <= 0) return '';

    const maxStars = 5;
    const stars = Array.from({ length: maxStars }, (_, index) => {
        const filled = index < level ? ' is-filled' : '';
        return `<span class="inventory-equipment-star${filled}" aria-hidden="true">★</span>`;
    }).join('');

    return `<div class="inventory-equipment-stars" aria-label="Nivel de melhoria ${level}">${stars}</div>`;
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

function tooltipSaleBlock() {
    return `
        <div class="inventory-tooltip-prices">
            <div><span>Venda NPC</span><strong>—</strong></div>
            <div><span>Venda jogador</span><strong>—</strong></div>
        </div>
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

function renderItemTypeBadge(item) {
    if (item?.definition?.is_container) return '';
    const typeMeta = resolveItemTypeMeta(item);
    return `<span class="inventory-item-type-badge is-${escapeHtml(typeMeta.tone)}" title="${escapeHtml(typeMeta.label)}">${escapeHtml(typeMeta.icon)}</span>`;
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
            <section class="inventory-compare-card rarity-${rarityKey(item)}">
                <span class="inventory-compare-label">Candidato</span>
                ${itemTooltip(item, { compareWith: equipped, inline: true })}
            </section>
            <section class="inventory-compare-card rarity-${rarityKey(equipped)}">
                <span class="inventory-compare-label">Equipado</span>
                ${itemTooltip(equipped, { compareWith: item, inline: true })}
            </section>
        </div>
        <footer class="inventory-compare-footer">
            <div>
                <span>Poder</span>
                <strong>${itemPower}</strong>
                ${formatStatDelta(powerDelta)}
            </div>
            <small>Ctrl+clique no item para abrir ou fechar.</small>
        </footer>
    `;

    compareDockRoot.querySelector('.inventory-compare-close')?.addEventListener('click', closeComparePanel);
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
                affix.name,
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
            ${mergeable || jewel ? tooltipSaleBlock() : ''}
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
            ${tooltipSaleBlock()}
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

    return `
        <div class="inventory-tooltip rarity-${rarityKey(item)} is-type-${escapeHtml(categoryCode)}${inline ? ' is-inline' : ''}">
            ${hero}
            ${titleBlock}
            <div class="inventory-tooltip-tags">${tags.map((tag) => `<span>${tag}</span>`).join('')}</div>
            ${details}
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

function persistCharacterPanel() {
    localStorage.setItem('evolvaxe.inventory.characterPanelOpen', characterPanelOpen ? '1' : '0');
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

function toggleExpeditionPanel() {
    expeditionCarryOpen = !expeditionCarryOpen;
    persistContainerPanels();
    loadInventory();
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

function isContainerVisible(container) {
    if (isEquippedBackpackContainer(container)) {
        return false;
    }

    const kind = containerKind(container);
    if (kind === 'main') return true;
    if (kind === 'market_delivery') return marketDeliveryOpen;
    if (kind === 'expedition_carry') return expeditionCarryOpen;

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

    if (container.source_item_public_id) {
        const sourceItem = [...itemIndex.values()].find((entry) => entry.item?.public_id === container.source_item_public_id)?.item;
        if (sourceItem) {
            return itemLabel(sourceItem);
        }
    }

    return container.name;
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
            ${renderItemTypeBadge(item)}
            ${containerItemBadge(item)}
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
    const section = document.createElement('section');
    section.className = `inventory-container${isPhysical ? ' inventory-container-physical' : ''}`;
    section.dataset.containerPublicId = container.public_id;
    if (container.source_item_public_id) {
        section.dataset.sourceItemPublicId = container.source_item_public_id;
    }

    const badge = isPhysical
        ? '<span class="inventory-container-badge">Fisico</span>'
        : '';
    const acceptanceBadge = container.acceptance_summary?.label
        ? `<span class="inventory-container-acceptance">${escapeHtml(container.acceptance_summary.label)}</span>`
        : '';
    const breadcrumb = renderContainerBreadcrumb(container);
    const canOrganize = !['expedition_carry', 'market_delivery'].includes(containerKind(container));

    const canClose = isPhysical || containerKind(container) === 'market_delivery' || containerKind(container) === 'expedition_carry';

    section.innerHTML = `
        <header class="inventory-container-header">
            <div class="inventory-container-title">
                ${breadcrumb}
                <div class="inventory-container-title-row">
                    <h2>${escapeHtml(containerDisplayName(container))}</h2>
                    ${badge}
                    ${acceptanceBadge}
                </div>
                <p>${escapeHtml(containerDisplayHint(container))}</p>
                ${isPhysical ? '<p class="inventory-container-link">Duplo clique no item para abrir ou fechar</p>' : ''}
            </div>
            <div class="inventory-container-meta-block">
                <span class="inventory-container-meta">${Number(container.grid.columns)}x${Number(container.grid.rows)}</span>
                <span class="inventory-container-occupancy">${escapeHtml(occupancyLabel(container, summaryEntry))}</span>
                ${canOrganize ? '<button type="button" class="inventory-button inventory-container-organize" data-container-organize>Organizar</button>' : ''}
                ${canClose ? '<button type="button" class="inventory-container-close" aria-label="Fechar container">×</button>' : ''}
            </div>
        </header>
        <div class="inventory-grid-wrap"></div>
    `;

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
        await organizeContainer(container.public_id);
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
            <button type="button" class="inventory-button inventory-split-close">Fechar split</button>
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

async function organizeContainer(containerPublicId) {
    if (actionInFlight || loading) return;

    actionInFlight = true;
    try {
        setStatus('Organizando...');
        const response = await apiFetch(`/api/inventory/containers/${encodeURIComponent(containerPublicId)}/organize`, {
            method: 'POST',
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

async function renameInventoryItem(item) {
    const currentName = itemLabel(item);
    const nextName = window.prompt('Nome personalizado do item (vazio restaura o padrao):', currentName);
    if (nextName === null) return;

    try {
        setStatus('Renomeando item...');
        await apiFetch(`/api/inventory/items/${encodeURIComponent(item.public_id)}/rename`, {
            method: 'PATCH',
            body: { item_name: nextName.trim() },
        });
        toast('Item renomeado.', 'success', 2400);
        setStatus('Sincronizado');
        await loadInventory();
    } catch (error) {
        handleError(error, 'Nao foi possivel renomear o item.');
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

    equipmentRoot.replaceChildren();
    const equippedCount = equipment.filter((slot) => slot.item).length;
    equipmentRoot.classList.toggle('is-collapsed', !characterPanelOpen);
    equipmentRoot.innerHTML = `
        <header class="inventory-equipment-header">
            <div>
                <p class="inventory-kicker">Personagem</p>
                <h2>Equipamentos</h2>
            </div>
            <div class="inventory-equipment-header-actions">
                <span>${equippedCount}/${equipment.length} slot(s)</span>
                <button class="inventory-button inventory-equipment-toggle" type="button" data-character-toggle>${characterPanelOpen ? 'Ocultar' : 'Mostrar'} (I)</button>
            </div>
        </header>
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
            <aside class="inventory-character-stats" data-character-stats></aside>
        </div>
    `;

    equipmentRoot.querySelector('[data-character-toggle]')?.addEventListener('click', () => toggleCharacterPanel());
    renderCharacterStats(stats, setBonuses, playerPower);

    if (!characterPanelOpen) {
        return;
    }

    renderEquipmentSlots(equipment);
    window.requestAnimationFrame(() => renderEquipmentLinks(links, setBonuses));
}

function isTwoHandedWeapon(item) {
    const hands = Number(item?.definition?.base_config?.hands || 0);
    return hands >= 2;
}

function renderEquipmentSlots(equipment = []) {
    const stage = equipmentRoot.querySelector('[data-equipment-stage]');
    if (!stage) return;

    const byCode = new Map(equipment.map((slot) => [slot.code, slot]));
    const weaponSlot = byCode.get('weapon');
    const twoHandedWeapon = weaponSlot?.item && isTwoHandedWeapon(weaponSlot.item) ? weaponSlot.item : null;
    const occupiedOffhand = ['weapon_offhand', 'shield', 'quiver']
        .map((code) => byCode.get(code))
        .find((slot) => slot?.item) || null;
    const offhand = occupiedOffhand || { code: 'offhand', name: 'Offhand', item: null };
    const offhandGhost = !occupiedOffhand && twoHandedWeapon ? twoHandedWeapon : null;

    const visualSlots = [
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
        stage.appendChild(equipmentSlotNode(entry.slot, { ghostItem: entry.ghostItem || null }));
    }
}

function equipmentSlotNode(slot, options = {}) {
    const ghostItem = options.ghostItem || null;
    const displayItem = slot.item || ghostItem;
    const isGhostOccupied = Boolean(!slot.item && ghostItem);
    const node = document.createElement('article');
    const visualCode = ['weapon_offhand', 'shield', 'quiver'].includes(slot.code) ? 'offhand' : slot.code;
    const rarityClass = displayItem ? ` rarity-${rarityKey(displayItem)}` : '';
    node.className = `inventory-equipment-slot is-${escapeHtml(visualCode)}${displayItem ? ' has-item' : ''}${isGhostOccupied ? ' is-ghost-occupied' : ''}${rarityClass}`;
    node.dataset.equipmentSlot = slot.code;
    node.dataset.visualSlot = visualCode;
    if (isGhostOccupied) {
        node.dataset.ghostOccupied = '1';
        node.title = 'Ocupado por arma de duas maos';
    }

    if (!displayItem) {
        node.innerHTML = `
            ${renderEquipmentSlotIcon(slot.code)}
            <span class="inventory-equipment-empty" aria-hidden="true"></span>
        `;
        return node;
    }

    node.innerHTML = `
        ${renderEquipmentSlotIcon(slot.code)}
        <div class="inventory-equipment-item-shell${isGhostOccupied ? ' is-ghost-shell' : ''}">${renderItem(displayItem, { ghost: isGhostOccupied })}</div>
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

function renderEquipmentLinks(links = [], setBonuses = []) {
    const svg = equipmentRoot.querySelector('[data-equipment-links]');
    const paperdoll = equipmentRoot.querySelector('.inventory-paperdoll');
    if (!svg || !paperdoll) return;

    svg.replaceChildren();
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
            .map((element) => {
                const box = element.getBoundingClientRect();
                const root = paperdoll.getBoundingClientRect();
                return {
                    x: box.left - root.left + box.width / 2,
                    y: box.top - root.top + box.height / 2,
                };
            });

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
    };

    return labels[visualCode] || slot.name;
}

function renderCharacterStats(stats = [], setBonuses = [], power = null) {
    const root = equipmentRoot?.querySelector('[data-character-stats]');
    if (!root) return;

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
        root.innerHTML = '<strong>Status</strong><span class="inventory-character-stat-empty">Sem bonus equipados.</span>';
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

    root.innerHTML = `
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
    characterPanelOpen = !characterPanelOpen;
    persistCharacterPanel();
    loadInventory();
}

function renderContainerDock(containers = []) {
    if (!dockRoot) return;

    const secondary = containers.filter(shouldShowContainerInDock);
    if (!secondary.length) {
        dockRoot.hidden = true;
        dockRoot.replaceChildren();
        return;
    }

    dockRoot.hidden = false;
    dockRoot.replaceChildren();
    dockRoot.innerHTML = `
        <div class="inventory-dock-label">
            <strong>Armazenamento</strong>
            <span>Expedicao, entregas e containers abertos. Duplo clique em mochilas/baus para abrir.</span>
        </div>
        <div class="inventory-dock-actions"></div>
    `;

    const actions = dockRoot.querySelector('.inventory-dock-actions');
    for (const container of secondary) {
        const kind = containerKind(container);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `inventory-dock-button is-${kind}${isContainerVisible(container) ? ' is-open' : ''}`;
        button.dataset.containerPublicId = container.public_id;
        button.innerHTML = `
            <span>${escapeHtml(containerDisplayName(container))}</span>
            <small>${escapeHtml(containerDisplayHint(container))} - ${Number(container.grid.columns)}x${Number(container.grid.rows)}</small>
        `;
        button.addEventListener('click', () => toggleContainer(container));
        actions.appendChild(button);
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
    const cellX = Math.floor((clientX - rect.left) / CELL_SIZE);
    const cellY = Math.floor((clientY - rect.top) / CELL_SIZE);
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

    ghost.hidden = false;
    ghost.classList.remove('is-valid', 'is-invalid', 'is-merge', 'is-deposit', 'is-bless', 'is-soul', 'is-chaos', 'is-reroll', 'is-socket');
    ghost.classList.add(`is-${state}`);
    ghost.replaceChildren();

    for (let row = y; row < y + h; row += 1) {
        for (let col = x; col < x + w; col += 1) {
            const cell = document.createElement('span');
            cell.className = 'inventory-ghost-cell';
            cell.style.left = `${col * CELL_SIZE}px`;
            cell.style.top = `${row * CELL_SIZE}px`;
            cell.style.width = `${CELL_SIZE}px`;
            cell.style.height = `${CELL_SIZE}px`;
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
    const rawX = Math.floor((clientX - rect.left) / CELL_SIZE);
    const rawY = Math.floor((clientY - rect.top) / CELL_SIZE);
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

    if (evaluation.state === 'deposit' && evaluation.slot && evaluation.linkedContainer) {
        activeDrag.hoverState = 'deposit';
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

    activeDrag.hoverState = evaluation.state;
    activeDrag.depositContainerPublicId = null;
    activeDrag.depositSlot = null;
    activeDrag.depositRotated = null;
    activeDrag.hoverX = x;
    activeDrag.hoverY = y;
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
        const originX = activeDrag.pointerX != null && dragged.grid?.el
            ? Math.floor((activeDrag.pointerX - dragged.grid.el.getBoundingClientRect().left) / CELL_SIZE)
            : currentX + Math.floor(currentSize.w / 2);
        const originY = activeDrag.pointerY != null && dragged.grid?.el
            ? Math.floor((activeDrag.pointerY - dragged.grid.el.getBoundingClientRect().top) / CELL_SIZE)
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

        let targetContainerPublicId = activeDrag.sourceContainerPublicId;

        if (activeDrag.hoverState === 'deposit' && activeDrag.depositContainerPublicId) {
            await handleDrop(activeDrag.sourceContainerPublicId, node, coords);
            return;
        }

        if (coords?.clientX != null && coords?.clientY != null) {
            const hover = findGridUnderPointer(coords.clientX, coords.clientY);
            if (hover && hover.containerPublicId !== activeDrag.sourceContainerPublicId) {
                targetContainerPublicId = hover.containerPublicId;
                updateAllGhostPreviews(coords.clientX, coords.clientY);
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
    const grid = GridStack.init({
        column: Number(container.grid.columns),
        minRow: Number(container.grid.rows),
        maxRow: Number(container.grid.rows),
        cellHeight: CELL_SIZE,
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
            endDragSession();
            await finalizeDrag(event);
        } catch (error) {
            console.error('[inventory-drag]', error);
            revertItem(node.id);
            clearActiveDrag();
            endDragSession();
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
        <span class="inventory-context-menu-item-description">Definir nome personalizado</span>
    `;
    renameButton.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();
        closeContextMenu();
        await renameInventoryItem(item);
    });
    list.appendChild(renameButton);

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

    const mainContainer = findMainInventoryContainer([...containerIndex.values()]);
    if (isChestContainerItem(item) && mainContainer) {
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

function renderSummary(summary) {
    if (!summaryNode) return;

    const containers = summary?.containers?.length || 0;
    const items = summary?.item_count || 0;
    const equipped = summary?.equipped_item_count || 0;
    summaryNode.textContent = `${containers} containers · ${items} itens · ${equipped} equipado(s)`;
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

    if (action.requires_confirmation) {
        const label = action.name || action.code;
        const confirmed = await confirmInventoryAction({
            title: label,
            bodyHtml: `<p>Confirmar esta acao em <strong>${escapeHtml(itemLabel(item))}</strong>?</p>`,
            confirmLabel: label,
            tone: action.is_destructive ? 'danger' : 'warning',
        });
        if (!confirmed) return;
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

        if (data.action === 'INSPECT') {
            toast(inspectSummary(data), 'info', 5200);
            setStatus('Sincronizado');
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
        inventorySummaryByPublicId = new Map(
            (summaryResponse?.data?.containers || []).map((entry) => [entry.public_id, entry])
        );
        const visibleContainers = containers.filter(isContainerVisible);
        const summaryByPublicId = inventorySummaryByPublicId;

        destroyGrids();
        containerRoot.textContent = '';
        renderEquipment(equipment, characterStats, equipmentLinks, activeSetBonuses);
        renderContainerDock(containers);
        renderSummary(summaryResponse?.data || null);

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

        if (splitParent && splitChild && isContainerVisible(splitChild)) {
            for (const container of containers) {
                containerIndex.set(container.public_id, container);
            }

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
    if (isTyping || event.ctrlKey || event.metaKey || event.altKey) return;
    if (event.key === 'i' || event.key === 'I') {
        event.preventDefault();
        toggleCharacterPanel();
    }
    if (event.key === 'e' || event.key === 'E') {
        event.preventDefault();
        toggleExpeditionPanel();
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

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeContextMenu();
});

loadInventory();
console.info('[inventory] drag engine', INVENTORY_DRAG_ENGINE);
