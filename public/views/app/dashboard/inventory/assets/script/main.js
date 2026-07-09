import { ApiError, apiFetch } from '/assets/framework/api.js';
import { installToastStyles, toast } from '/assets/framework/toast.js';

const app = document.querySelector('[data-inventory-app]');
const containerRoot = document.querySelector('[data-inventory-containers]');
const statusNode = document.querySelector('[data-inventory-status]');
const summaryNode = document.querySelector('[data-inventory-summary]');
const refreshButton = document.querySelector('[data-inventory-refresh]');

installToastStyles();

let grids = new Map();
let itemIndex = new Map();
let containerIndex = new Map();
let dragSnapshots = new Map();
let activeDrag = null;
let silent = false;
let loading = false;
let actionInFlight = false;
let contextMenuState = null;

const CELL_SIZE = 44;
const INVENTORY_DRAG_ENGINE = 'v2';

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

function itemTooltip(item) {
    const parts = [
        `<strong>${escapeHtml(itemLabel(item))}</strong>`,
        `Codigo: ${escapeHtml(item.definition?.code || '-')}`,
        `Quantidade: ${Number(item.quantity || 1)}`,
    ];

    if (item.definition?.stackable) parts.push('Ctrl+Clique: dividir metade');
    parts.push('Clique direito: acoes do item');
    if (item.definition?.is_container) parts.push('Item fisico com container interno');
    parts.push('Arraste: preview verde/vermelho mostra celulas ocupadas');
    parts.push('Sobre mochila: azul guarda dentro se houver espaco');
    parts.push('R: rotacionar durante o arraste');
    if (item.quality_bucket) parts.push(`Qualidade: ${escapeHtml(item.quality_bucket)}`);
    if (item.durability?.max) parts.push(`Durabilidade: ${Number(item.durability.current || 0)}/${Number(item.durability.max)}`);

    return parts.join('<br>');
}

function gridElement(container) {
    const host = document.createElement('div');
    host.className = 'inventory-grid-host';
    host.dataset.containerPublicId = container.public_id;

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
    return `${summaryEntry.item_count} item(ns) · ${percent}%`;
}

function renderItem(item) {
    const quantity = Number(item.quantity || 1);
    const code = item.definition?.code || '';
    const name = itemLabel(item);
    const isContainer = Boolean(item.definition?.is_container);
    const durabilityMax = Number(item.durability?.max || 0);
    const durabilityCurrent = Number(item.durability?.current || 0);
    const durabilityPercent = durabilityMax > 0
        ? Math.max(0, Math.min(100, Math.round((durabilityCurrent / durabilityMax) * 100)))
        : null;

    return `
        <div class="inventory-item${isContainer ? ' is-container-item' : ''}" data-item-public-id="${escapeHtml(item.public_id)}">
            ${isContainer ? '<span class="inventory-item-badge">Container</span>' : ''}
            <span class="inventory-item-name">${escapeHtml(name)}</span>
            <span class="inventory-item-meta">
                <span>${escapeHtml(code)}</span>
                <span>${quantity > 1 ? `x${quantity}` : ''}</span>
            </span>
            ${durabilityPercent !== null ? `<span class="inventory-item-durability" style="--durability-percent:${durabilityPercent}%"></span>` : ''}
        </div>
    `;
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

    section.innerHTML = `
        <header class="inventory-container-header">
            <div class="inventory-container-title">
                <div class="inventory-container-title-row">
                    <h2>${escapeHtml(container.name)}</h2>
                    ${badge}
                </div>
                <p>${escapeHtml(container.definition_code)}</p>
                ${isPhysical ? '<p class="inventory-container-link">Vinculado a um item fisico</p>' : ''}
            </div>
            <div class="inventory-container-meta-block">
                <span class="inventory-container-meta">${Number(container.grid.columns)}x${Number(container.grid.rows)}</span>
                <span class="inventory-container-occupancy">${escapeHtml(occupancyLabel(container, summaryEntry))}</span>
            </div>
        </header>
        <div class="inventory-grid-wrap"></div>
    `;

    section.querySelector('.inventory-grid-wrap').appendChild(gridElement(container));
    return section;
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

function clearAllGhostPreviews() {
    for (const ghost of document.querySelectorAll('[data-placement-ghost]')) {
        ghost.hidden = true;
        ghost.replaceChildren();
        ghost.classList.remove('is-valid', 'is-invalid', 'is-merge', 'is-deposit');
    }
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
    ghost.classList.remove('is-valid', 'is-invalid', 'is-merge');
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
    const pointerCoords = activeDrag.pointerX != null && activeDrag.pointerY != null
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
            x: Math.round(Number(dragged?.node?.x || activeDrag.hoverX || 0)),
            y: Math.round(Number(dragged?.node?.y || activeDrag.hoverY || 0)),
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
        'inventory-rotated-preview'
    );
    if (state === 'valid') element?.classList.add('inventory-placement-valid');
    if (state === 'invalid') element?.classList.add('inventory-placement-invalid');
    if (state === 'merge') element?.classList.add('inventory-placement-merge');
    if (state === 'deposit') element?.classList.add('inventory-placement-deposit');
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

    if (syncHoverFromPointer(event.clientX, event.clientY)) {
        updateAllGhostPreviews(event.clientX, event.clientY);
    }

    const dragged = findDraggedWidget();
    if (dragged?.element) {
        updatePlacementHint(dragged.element);
    }
}

function beginDragSession() {
    document.addEventListener('mousemove', onDocumentDragPointer);
}

function endDragSession() {
    document.removeEventListener('mousemove', onDocumentDragPointer);
    clearAllGhostPreviews();
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

function syncHoverFromPointer(clientX, clientY) {
    if (!activeDrag) return false;

    const hover = findGridUnderPointer(clientX, clientY);
    if (!hover) return false;

    const container = containerIndex.get(hover.containerPublicId);
    const current = itemIndex.get(activeDrag.itemPublicId);
    if (!container || !current || !hover.grid?.el) return false;
    if (!isPointerInsideElement(hover.grid.el, clientX, clientY)) return false;

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
        'inventory-rotated-preview'
    );
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
    const pointerCell = activeDrag.pointerX != null && activeDrag.pointerY != null && located.grid.el && container
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
    const x = pointerCell ? pointerCell.x : Math.round(Number(node.x ?? 0));
    const y = pointerCell ? pointerCell.y : Math.round(Number(node.y ?? 0));
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

    const current = itemIndex.get(activeDrag.itemPublicId);
    if (!current) return;

    const base = baseDimensions(current.item);
    if (base.w === base.h) {
        toast('Este item nao pode ser rotacionado.', 'info', 2200);
        return;
    }

    let relative;
    if (activeDrag.pointerX != null && activeDrag.pointerY != null && dragged.element) {
        const rect = dragged.element.getBoundingClientRect();
        relative = {
            left: activeDrag.pointerX - rect.left,
            top: activeDrag.pointerY - rect.top,
        };
    }

    silent = true;
    dragged.grid.rotate(dragged.node.el, relative);
    silent = false;

    syncRotatedStateFromNode(current, dragged.node);
    activeDrag.rotated = Boolean(current.rotated);
    activeDrag.targetSnapshots.clear();

    if (activeDrag.pointerX != null && activeDrag.pointerY != null) {
        syncHoverFromPointer(activeDrag.pointerX, activeDrag.pointerY);
        updateAllGhostPreviews(activeDrag.pointerX, activeDrag.pointerY);
    }

    applyPlacementHintClasses(dragged.element, activeDrag.hoverState || 'valid', Boolean(current.rotated));
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
        };

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
        const current = itemIndex.get(node.id);

        if (coords) {
            syncHoverFromPointer(coords.clientX, coords.clientY);
        }

        syncRotatedStateFromNode(current, node);

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
        await quickSplit(container.public_id, item);
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

    const header = document.createElement('div');
    header.className = 'inventory-context-menu-header';
    header.innerHTML = `
        <strong>${escapeHtml(itemLabel(item))}</strong>
        <span>${escapeHtml(item.definition?.code || '')}</span>
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
            const linkedSection = document.querySelector(`.inventory-container-physical[data-source-item-public-id="${itemPublicId}"]`);
            if (linkedSection) {
                highlightContainer(linkedSection.dataset.containerPublicId);
            }
        });
    }
}

function renderSummary(summary) {
    if (!summaryNode) return;

    const containers = summary?.containers?.length || 0;
    const items = summary?.item_count || 0;
    summaryNode.textContent = `${containers} containers · ${items} itens`;
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
        const confirmed = window.confirm(`Confirmar "${label}" para ${itemLabel(item)}?`);
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
                theme: 'translucent',
                placement: 'top',
            });
        }
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
        const summaryByPublicId = new Map(
            (summaryResponse?.data?.containers || []).map((entry) => [entry.public_id, entry])
        );

        destroyGrids();
        containerRoot.textContent = '';
        renderSummary(summaryResponse?.data || null);

        if (!containers.length) {
            containerRoot.innerHTML = '<div class="inventory-empty">Nenhum container encontrado.</div>';
            setStatus('Vazio');
            return;
        }

        for (const container of containers) {
            containerIndex.set(container.public_id, container);
            const section = renderContainer(container, summaryByPublicId.get(container.public_id) || null);
            containerRoot.appendChild(section);
            const gridNode = section.querySelector('.inventory-grid');
            const grid = initializeGrid(container, gridNode);
            addItems(container, grid);
        }

        bindContainerLinks();
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
