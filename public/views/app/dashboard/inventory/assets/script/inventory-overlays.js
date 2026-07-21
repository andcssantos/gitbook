/**
 * Overlays do inventario (P1): tooltips tippy + context menu + cache de actions.
 */

import { inventoryStore } from './inventory-state-store.js';

let overlayDeps = null;
let contextMenuState = null;

const CONTEXT_MENU_HIDDEN_ACTIONS = new Set([
    'INSPECT',
    'SEAL',
    'UNEQUIP',
]);

const CONTEXT_MENU_ACTION_ORDER = [
    'OPEN',
    'EQUIP',
    'USE',
    'LOCK_ITEM',
    'UNLOCK_ITEM',
    'FAVORITE_ITEM',
    'UNFAVORITE_ITEM',
    'WISHLIST_ITEM',
    'UNWISHLIST_ITEM',
    'SELL',
    'LIST_MARKET',
    'DISMANTLE',
    'DISCARD',
];

const itemActionsCache = inventoryStore.itemActionsCache;

export function configureInventoryOverlays(deps = {}) {
    overlayDeps = deps;
}

function d() {
    return overlayDeps || {};
}

export function getContextMenuState() {
    return contextMenuState;
}

export function invalidateItemActionsCache(itemPublicId) {
    if (!itemPublicId) return;
    itemActionsCache.delete(itemPublicId);
}

export function hideAllItemTooltips(options = {}) {
    const exclude = options.exclude || null;
    try {
        if (window.tippy?.hideAll) {
            window.tippy.hideAll({ duration: 0, exclude });
        }
    } catch {
        // ignore
    }

    // Remove estilos deixados pela implementação antiga. O próprio Tippy
    // gerencia display/visibility; forçar inline fazia tooltips futuros sumirem.
    document.querySelectorAll('[data-tippy-root]').forEach((root) => {
        if (exclude?.popper === root) return;
        root.style.removeProperty('display');
        root.style.removeProperty('visibility');
        root.style.removeProperty('pointer-events');
        root.removeAttribute('data-tippy-hidden-by-drag');
    });
}

export function bindSocketNestedTooltips(root, item) {
    if (!root || !window.tippy || !item) return;
    const htmlFn = d().socketNestedTooltipHtml;
    if (typeof htmlFn !== 'function') return;

    root.querySelectorAll('[data-socket-tooltip]').forEach((node) => {
        if (node._tippy) return;
        const index = Number(node.getAttribute('data-socket-index') || 0);
        const socket = (Array.isArray(item.sockets) ? item.sockets : [])
            .find((entry) => Number(entry.index) === index)
            || (Array.isArray(item.sockets) ? item.sockets : [])[index];

        window.tippy(node, {
            allowHTML: true,
            content: htmlFn(item, socket || { gem: null }),
            theme: 'evolvaxe-item',
            placement: 'right',
            interactive: true,
            appendTo: () => document.body,
            delay: [80, 40],
            popperOptions: { strategy: 'fixed' },
        });
    });
}

export function attachItemTooltip(content, item, options = {}) {
    if (!content || !window.tippy || !item) return null;

    if (content._tippy) {
        try {
            content._tippy.destroy();
        } catch {
            // ignore
        }
    }

    const itemTooltip = d().itemTooltip;
    const instance = window.tippy(content, {
        allowHTML: true,
        content: typeof itemTooltip === 'function' ? itemTooltip(item) : '',
        theme: 'evolvaxe-item',
        placement: options.placement || 'right-start',
        interactive: false,
        appendTo: () => document.body,
        popperOptions: {
            strategy: 'fixed',
            modifiers: [
                {
                    name: 'preventOverflow',
                    options: { boundary: 'viewport', padding: 10 },
                },
                {
                    name: 'flip',
                    options: { fallbackPlacements: ['left-start', 'top', 'bottom'] },
                },
            ],
        },
        delay: options.delay || [220, 60],
        hideOnClick: true,
        touch: false,
        onShow(tip) {
            if (d().isBusy?.() || contextMenuState) {
                return false;
            }
            hideAllItemTooltips({ exclude: tip });
            tip.popper?.style.removeProperty('display');
            tip.popper?.style.removeProperty('visibility');
            tip.popper?.style.removeProperty('pointer-events');
        },
        onShown(tip) {
            bindSocketNestedTooltips(tip.popper, item);
        },
    });

    if (options.prefetch !== false) {
        content.addEventListener('mouseenter', () => {
            void fetchItemActionsCached(item).catch(() => null);
        }, { once: true });
    }

    return instance;
}

export function closeContextMenu() {
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
    menu.style.maxHeight = '';

    const padding = 8;
    const viewportW = window.innerWidth;
    const viewportH = window.innerHeight;
    const maxMenuHeight = Math.max(220, viewportH - (padding * 2));
    menu.style.maxHeight = `${maxMenuHeight}px`;

    const rect = menu.getBoundingClientRect();
    const width = rect.width || 240;
    const height = Math.min(rect.height || 280, maxMenuHeight);

    let left = clientX;
    let top = clientY;

    if (left + width > viewportW - padding) {
        left = Math.max(padding, clientX - width);
    }
    if (top + height > viewportH - padding) {
        top = Math.max(padding, viewportH - height - padding);
    }
    left = Math.max(padding, Math.min(left, viewportW - width - padding));
    top = Math.max(padding, Math.min(top, viewportH - height - padding));

    menu.style.left = `${left}px`;
    menu.style.top = `${top}px`;
}

function filterContextMenuActions(actions) {
    return (Array.isArray(actions) ? actions : [])
        .filter((action) => action?.code && !CONTEXT_MENU_HIDDEN_ACTIONS.has(String(action.code)))
        .sort((a, b) => {
            const aIdx = CONTEXT_MENU_ACTION_ORDER.indexOf(String(a.code));
            const bIdx = CONTEXT_MENU_ACTION_ORDER.indexOf(String(b.code));
            return (aIdx < 0 ? 999 : aIdx) - (bIdx < 0 ? 999 : bIdx);
        });
}

function buildInstantContextActions(item) {
    const deps = d();
    const actions = [];
    if (item?.definition?.is_container) {
        actions.push({
            code: 'OPEN',
            name: 'Abrir',
            description: 'Abrir baú ou bag',
            requires_confirmation: false,
            is_destructive: false,
        });
    }
    if (deps.isEquippableItem?.(item) && !deps.isItemCurrentlyEquipped?.(item)) {
        actions.push({
            code: 'EQUIP',
            name: 'Equipar',
            description: 'Colocar no personagem',
            requires_confirmation: false,
            is_destructive: false,
        });
    }
    if (item?.flags?.locked) {
        actions.push({
            code: 'UNLOCK_ITEM',
            name: 'Destravar',
            description: 'Permitir venda e descarte',
            requires_confirmation: false,
            is_destructive: false,
        });
    } else {
        actions.push({
            code: 'LOCK_ITEM',
            name: 'Travar',
            description: 'Proteger contra venda e descarte',
            requires_confirmation: false,
            is_destructive: false,
        });
    }
    if (item?.flags?.favorite) {
        actions.push({
            code: 'UNFAVORITE_ITEM',
            name: 'Remover favorito',
            description: null,
            requires_confirmation: false,
            is_destructive: false,
        });
    } else {
        actions.push({
            code: 'FAVORITE_ITEM',
            name: 'Favoritar',
            description: null,
            requires_confirmation: false,
            is_destructive: false,
        });
    }
    return actions;
}

function mergeContextMenuActions(instantActions, remoteActions) {
    const byCode = new Map();
    for (const action of [...(instantActions || []), ...(remoteActions || [])]) {
        if (!action?.code || CONTEXT_MENU_HIDDEN_ACTIONS.has(String(action.code))) continue;
        if (!byCode.has(String(action.code))) {
            byCode.set(String(action.code), action);
        }
    }
    return filterContextMenuActions([...byCode.values()]);
}

function renderContextMenu(menu, item, actions) {
    const deps = d();
    menu.replaceChildren();
    menu.className = `inventory-context-menu rarity-${deps.rarityKey?.(item) || 'common'}`;
    const flagLabels = [
        item.flags?.locked ? 'Travado' : null,
        item.flags?.favorite ? 'Favorito' : null,
        item.flags?.wishlist ? 'Wishlist' : null,
    ].filter(Boolean);

    const header = document.createElement('div');
    header.className = 'inventory-context-menu-header';
    header.innerHTML = `
        <strong>${deps.escapeHtml?.(deps.itemLabel?.(item) || '') || ''}</strong>
        <span>${deps.escapeHtml?.(deps.rarityLabel?.(item) || '') || ''} - ${deps.escapeHtml?.(item.definition?.code || '') || ''}</span>
        ${flagLabels.length ? `<small>${flagLabels.map((label) => deps.escapeHtml?.(label) || label).join(' · ')}</small>` : ''}
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
        await deps.renameStorageContainerItem?.(item);
    });
    if (deps.isStorageContainerItem?.(item)) {
        list.appendChild(renameButton);
    }

    if (deps.isEquippableItem?.(item)) {
        const compareButton = document.createElement('button');
        compareButton.type = 'button';
        compareButton.className = 'inventory-context-menu-item';
        const equippedNow = Boolean(deps.isItemCurrentlyEquipped?.(item));
        compareButton.innerHTML = `
            <span class="inventory-context-menu-item-label">Comparar</span>
            <span class="inventory-context-menu-item-description">${equippedNow ? 'Escolher item do inventario' : 'Vs equipado ou outro item'}</span>
        `;
        compareButton.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            closeContextMenu();
            deps.openComparePanel?.(equippedNow ? { ...item, equipped: true } : item);
        });
        list.appendChild(compareButton);
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
            await deps.executeItemAction?.(item, action);
        });

        list.appendChild(button);
    }

    menu.appendChild(list);
}

export async function fetchItemActionsCached(item) {
    const publicId = item?.public_id;
    if (!publicId) return [];

    const cached = itemActionsCache.get(publicId);
    if (cached && (Date.now() - cached.at) < 60_000) {
        return cached.actions;
    }

    const apiFetch = d().apiFetch;
    if (typeof apiFetch !== 'function') return [];

    const response = await apiFetch(`/api/items/${encodeURIComponent(publicId)}/actions`);
    const actions = filterContextMenuActions(response.data?.actions || []);
    itemActionsCache.set(publicId, { at: Date.now(), actions });
    return actions;
}

export function prefetchItemActionsForContainer(container, { concurrency = 3, limit = 24 } = {}) {
    const items = (container?.items || [])
        .filter((item) => item?.public_id)
        .filter((item) => {
            const cached = itemActionsCache.get(item.public_id);
            return !(cached && (Date.now() - cached.at) < 60_000);
        })
        .slice(0, limit);

    if (!items.length) return;

    void (async () => {
        let cursor = 0;
        const workers = Array.from({ length: Math.min(concurrency, items.length) }, async () => {
            while (cursor < items.length) {
                const item = items[cursor];
                cursor += 1;
                try {
                    await fetchItemActionsCached(item);
                } catch {
                    // Prefetch e best-effort.
                }
            }
        });
        await Promise.all(workers);
    })();
}

export async function openContextMenu(event, item) {
    const deps = d();
    if (deps.isBusy?.()) return;

    hideAllItemTooltips();
    closeContextMenu();

    const menu = ensureContextMenuRoot();
    const pointerX = event.clientX;
    const pointerY = event.clientY;
    const instant = buildInstantContextActions(item);
    const cached = itemActionsCache.get(item.public_id);
    const initialActions = cached?.actions?.length
        ? mergeContextMenuActions(instant, cached.actions)
        : instant;

    renderContextMenu(menu, item, initialActions);
    positionContextMenu(menu, pointerX, pointerY);
    contextMenuState = { itemPublicId: item.public_id, pointerX, pointerY };

    if (cached && (Date.now() - cached.at) < 60_000) {
        return;
    }

    try {
        const actions = await fetchItemActionsCached(item);
        if (contextMenuState?.itemPublicId !== item.public_id) return;
        const merged = mergeContextMenuActions(instant, actions);
        const prevCodes = initialActions.map((entry) => entry.code).join('|');
        const nextCodes = merged.map((entry) => entry.code).join('|');
        if (prevCodes === nextCodes) return;
        renderContextMenu(menu, item, merged);
        positionContextMenu(menu, contextMenuState.pointerX, contextMenuState.pointerY);
    } catch (error) {
        if (contextMenuState?.itemPublicId !== item.public_id) return;
        deps.handleError?.(error, 'Nao foi possivel atualizar acoes do item.');
    }
}
