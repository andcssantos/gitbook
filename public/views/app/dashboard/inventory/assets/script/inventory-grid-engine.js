/**
 * Adapter leve de grid do inventario (P1/P2).
 * Placement math, snapshots, ghost preview e bloqueio bag-em-si.
 */

let gridDeps = null;

export function configureInventoryGridEngine(deps = {}) {
    gridDeps = deps;
}

function d() {
    return gridDeps || {};
}

function grids() {
    return d().grids;
}

function itemIndex() {
    return d().itemIndex;
}

function containerIndex() {
    return d().containerIndex;
}

export function overlaps(ax, ay, aw, ah, bx, by, bw, bh) {
    return ax < (bx + bw) && (ax + aw) > bx && ay < (by + bh) && (ay + ah) > by;
}

export function baseDimensions(item) {
    return {
        w: Number(item?.definition?.grid_w || 1),
        h: Number(item?.definition?.grid_h || 1),
    };
}

export function dimensionsForState(item, rotated) {
    const base = baseDimensions(item);
    if (!rotated) return base;
    return { w: base.h, h: base.w };
}

export function snapshotNodes(grid, excludeItemPublicId = null) {
    if (!grid?.engine?.nodes) return [];
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

export function snapshotContainerItems(container) {
    return (container?.items || [])
        .map((item) => {
            const placement = item.placement || {};
            const size = dimensionsForState(item, Boolean(placement.rotated));
            return {
                id: item.public_id,
                x: Number(placement.grid_x || 0),
                y: Number(placement.grid_y || 0),
                w: Number(placement.grid_w || size.w),
                h: Number(placement.grid_h || size.h),
            };
        })
        .filter((placement) => placement.id);
}

export function findOverlapInSnapshot(snapshot, x, y, w, h) {
    for (const placement of snapshot || []) {
        if (overlaps(x, y, w, h, placement.x, placement.y, placement.w, placement.h)) {
            return placement;
        }
    }
    return null;
}

export function findOverlappingPlacements(snapshot, x, y, w, h) {
    return (snapshot || []).filter((placement) => overlaps(
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

export function findOverlappingItemFromSnapshot(snapshot, x, y, w, h) {
    const placement = findOverlapInSnapshot(snapshot, x, y, w, h);
    if (!placement) return null;
    return itemIndex()?.get(placement.id)?.item || { public_id: placement.id };
}

export function isInsideBounds(containerPublicId, x, y, w, h) {
    const container = containerIndex()?.get(containerPublicId);
    if (!container) return false;

    return x >= 0
        && y >= 0
        && (x + w) <= Number(container.grid?.columns || 0)
        && (y + h) <= Number(container.grid?.rows || 0);
}

export function isPlacementValidAgainstSnapshot(containerPublicId, snapshot, x, y, w, h) {
    return isInsideBounds(containerPublicId, x, y, w, h)
        && findOverlapInSnapshot(snapshot, x, y, w, h) === null;
}

export function placementSnapshotForContainer(containerPublicId, exceptItemPublicId = null) {
    const deps = d();
    const byId = new Map();
    const index = itemIndex();
    const containers = containerIndex();
    const gridMap = grids();

    const pushItem = (item) => {
        if (!item?.public_id || item.public_id === exceptItemPublicId) return;
        if (byId.has(item.public_id)) return;

        const placement = item.placement || {};
        const rotated = Boolean(placement.rotated);
        const size = dimensionsForState(item, rotated);
        byId.set(item.public_id, {
            id: item.public_id,
            x: Number(placement.grid_x ?? 0),
            y: Number(placement.grid_y ?? 0),
            w: Math.max(1, Number(size.w || 1)),
            h: Math.max(1, Number(size.h || 1)),
        });
    };

    if (index) {
        for (const [publicId, entry] of index.entries()) {
            if (entry.container_public_id !== containerPublicId) continue;
            if (publicId === exceptItemPublicId) continue;

            const placement = entry.item?.placement || {};
            const rotated = Boolean(entry.rotated ?? placement.rotated);
            const size = entry.item
                ? dimensionsForState(entry.item, rotated)
                : {
                    w: Number(entry.grid_w ?? placement.grid_w ?? 1),
                    h: Number(entry.grid_h ?? placement.grid_h ?? 1),
                };

            byId.set(publicId, {
                id: publicId,
                x: Number(placement.grid_x ?? 0),
                y: Number(placement.grid_y ?? 0),
                w: Math.max(1, Number(size.w || 1)),
                h: Math.max(1, Number(size.h || 1)),
            });
        }
    }

    const gridMounted = Boolean(gridMap?.has?.(containerPublicId));
    if (!gridMounted) {
        const cached = deps.containerDetailCache?.get?.(containerPublicId)?.container;
        if (cached?.items) {
            for (const item of cached.items) pushItem(item);
        }
        const indexedContainer = containers?.get?.(containerPublicId);
        if (indexedContainer?.items) {
            for (const item of indexedContainer.items) pushItem(item);
        }
    }

    const activeDrag = deps.getActiveDrag?.();
    if (
        activeDrag?.sourceSnapshot
        && activeDrag.sourceContainerPublicId === containerPublicId
        && exceptItemPublicId === activeDrag.itemPublicId
    ) {
        for (const snap of activeDrag.sourceSnapshot) {
            if (!snap?.id || snap.id === exceptItemPublicId) continue;
            const existing = byId.get(snap.id);
            if (!existing) continue;
            byId.set(snap.id, {
                ...existing,
                x: Number(snap.x ?? existing.x),
                y: Number(snap.y ?? existing.y),
                w: Math.max(1, Number(snap.w ?? existing.w)),
                h: Math.max(1, Number(snap.h ?? existing.h)),
            });
        }
    }

    const grid = gridMap?.get?.(containerPublicId);
    if (!grid) {
        return [...byId.values()];
    }

    const staleNodes = [];
    for (const node of grid.engine?.nodes || []) {
        if (!node?.id || node.id === exceptItemPublicId) continue;

        const entry = index?.get?.(node.id);
        if (entry && entry.container_public_id !== containerPublicId) {
            staleNodes.push(node);
            continue;
        }
        if (!entry && !byId.has(node.id)) {
            staleNodes.push(node);
        }
    }

    if (staleNodes.length) {
        window.setTimeout(() => {
            for (const node of staleNodes) {
                if (!node?.id || !node?.el) continue;
                const live = index?.get?.(node.id);
                if (live && live.container_public_id === containerPublicId) continue;
                deps.setSilent?.(true);
                try {
                    grid.removeWidget(node.el, true);
                } catch {
                    node.el.remove?.();
                } finally {
                    deps.setSilent?.(false);
                }
            }
        }, 0);
    }

    return [...byId.values()];
}

export function placementSnapshotForGrid(grid, exceptItemPublicId) {
    if (!grid) return [];

    const containerPublicId = grid.el?.dataset?.containerPublicId || '';
    if (containerPublicId) {
        const indexedSnapshot = placementSnapshotForContainer(containerPublicId, exceptItemPublicId);
        if (indexedSnapshot.length > 0) {
            return indexedSnapshot;
        }
    }

    return snapshotNodes(grid, exceptItemPublicId);
}

export function targetSnapshotForGrid(containerPublicId, grid, exceptItemPublicId) {
    const indexedSnapshot = placementSnapshotForContainer(containerPublicId, exceptItemPublicId);
    if (indexedSnapshot.length > 0 || !grid) {
        return indexedSnapshot;
    }
    return placementSnapshotForGrid(grid, exceptItemPublicId);
}

export function findFirstFreeSlot(containerPublicId, width, height) {
    const container = containerIndex()?.get(containerPublicId);
    if (!container) return null;

    const columns = Number(container.grid?.columns || 0);
    const rows = Number(container.grid?.rows || 0);
    const maxY = rows - height;
    const maxX = columns - width;

    if (maxY < 0 || maxX < 0) return null;

    const grid = grids()?.get(containerPublicId);
    let snapshot = placementSnapshotForContainer(containerPublicId);
    if (!snapshot.length) {
        snapshot = grid ? snapshotNodes(grid) : snapshotContainerItems(container);
    }

    for (let y = 0; y <= maxY; y += 1) {
        for (let x = 0; x <= maxX; x += 1) {
            if (isPlacementValidAgainstSnapshot(containerPublicId, snapshot, x, y, width, height)) {
                return { grid_x: x, grid_y: y };
            }
        }
    }

    return null;
}

export function gridCellFromPointer(gridEl, clientX, clientY, footprintW, footprintH, columns, rows) {
    const deps = d();
    const rect = gridEl.getBoundingClientRect();
    const containerPublicId = gridEl?.dataset?.containerPublicId;
    const cellSize = containerPublicId
        ? (deps.cellSizeForContainer?.(containerPublicId) || 44)
        : (deps.resolveCellSize?.(gridEl) || 44);
    const rawX = Math.floor((clientX - rect.left) / cellSize);
    const rawY = Math.floor((clientY - rect.top) / cellSize);
    const maxX = Math.max(0, columns - footprintW);
    const maxY = Math.max(0, rows - footprintH);

    return {
        x: Math.max(0, Math.min(rawX, maxX)),
        y: Math.max(0, Math.min(rawY, maxY)),
    };
}

export function clampCell(x, y, width, height, columns, rows) {
    return {
        x: Math.max(0, Math.min(Math.round(Number(x || 0)), Math.max(0, columns - width))),
        y: Math.max(0, Math.min(Math.round(Number(y || 0)), Math.max(0, rows - height))),
    };
}

export function ghostElementForContainer(containerPublicId) {
    return document.querySelector(`[data-placement-ghost="${containerPublicId}"]`);
}

export function clearAllGhostPreviews() {
    for (const ghost of document.querySelectorAll('[data-placement-ghost]')) {
        ghost.hidden = true;
        ghost.replaceChildren();
        ghost.classList.remove(
            'is-valid',
            'is-invalid',
            'is-merge',
            'is-deposit',
            'is-bless',
            'is-soul',
            'is-chaos',
            'is-reroll',
            'is-socket'
        );
    }
}

export function renderGhostPreview(containerPublicId, x, y, w, h, state) {
    const deps = d();
    const ghost = ghostElementForContainer(containerPublicId);
    if (!ghost) return;

    const cellSize = deps.cellSizeForContainer?.(containerPublicId) || 44;
    ghost.hidden = false;
    ghost.classList.remove(
        'is-valid',
        'is-invalid',
        'is-merge',
        'is-deposit',
        'is-bless',
        'is-soul',
        'is-chaos',
        'is-reroll',
        'is-socket'
    );
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

/**
 * Bloqueia bag/bau dentro do proprio container vinculado.
 */
export function isItemMovingIntoOwnContainer(item, targetContainerPublicId) {
    const deps = d();
    if (!item?.public_id || !targetContainerPublicId) return false;

    const linkedId = item.linked_container?.public_id
        || itemIndex()?.get(item.public_id)?.item?.linked_container?.public_id
        || itemIndex()?.get(item.public_id)?.linked_container?.public_id
        || null;
    if (linkedId && linkedId === targetContainerPublicId) {
        return true;
    }

    const target = containerIndex()?.get(targetContainerPublicId)
        || deps.getAllContainersCache?.()?.find?.((entry) => entry.public_id === targetContainerPublicId)
        || deps.containerDetailCache?.get?.(targetContainerPublicId)?.container
        || null;
    if (target?.source_item_public_id && target.source_item_public_id === item.public_id) {
        return true;
    }

    const escape = deps.cssEscape || ((value) => String(value ?? ''));
    const section = document.querySelector(`[data-container-public-id="${escape(targetContainerPublicId)}"]`);
    const sourceFromDom = section?.dataset?.sourceItemPublicId || null;
    return Boolean(sourceFromDom && sourceFromDom === item.public_id);
}

export function reconcileContainerGrid(containerPublicId) {
    const deps = d();
    const grid = grids()?.get(containerPublicId);
    if (!grid) return;

    deps.setSilent?.(true);
    try {
        for (const node of [...(grid.engine?.nodes || [])]) {
            if (!node?.id) continue;

            const entry = itemIndex()?.get(node.id);
            if (!entry || entry.container_public_id !== containerPublicId) {
                if (node.el) {
                    try {
                        grid.removeWidget(node.el, true);
                    } catch {
                        node.el.remove();
                    }
                }
            }
        }
    } finally {
        deps.setSilent?.(false);
    }
}

export function purgeItemWidgetFromAllGrids(itemPublicId, keepContainerPublicId = null) {
    const deps = d();
    const safeId = String(itemPublicId || '').replace(/"/g, '\\"');
    if (!safeId) return;
    const gridMap = grids();
    if (!gridMap) return;

    for (const [containerPublicId, grid] of gridMap) {
        if (keepContainerPublicId && containerPublicId === keepContainerPublicId) continue;

        const node = grid.engine?.nodes?.find((candidate) => candidate.id === itemPublicId);
        if (!node?.el) continue;

        deps.setSilent?.(true);
        try {
            grid.removeWidget(node.el, true);
        } catch {
            node.el.remove();
        } finally {
            deps.setSilent?.(false);
        }
    }

    document.querySelectorAll(`.grid-stack-item[gs-id="${safeId}"]`).forEach((element) => {
        const hostGrid = element.closest('.grid-stack.inventory-grid');
        const hostContainerId = hostGrid?.dataset?.containerPublicId || null;
        if (keepContainerPublicId && hostContainerId === keepContainerPublicId) return;

        const grid = hostContainerId ? gridMap.get(hostContainerId) : null;
        deps.setSilent?.(true);
        try {
            if (grid && element.gridstackNode) {
                grid.removeWidget(element, true);
            } else {
                element.remove();
            }
        } catch {
            element.remove();
        } finally {
            deps.setSilent?.(false);
        }
    });
}

export function scrubOrphanGridWidgets(containerPublicId = null) {
    const deps = d();
    const gridMap = grids();
    if (!gridMap) return;

    const targets = containerPublicId
        ? [[containerPublicId, gridMap.get(containerPublicId)]].filter((entry) => entry[1])
        : [...gridMap.entries()];

    for (const [publicId, grid] of targets) {
        if (!grid) continue;
        reconcileContainerGrid(publicId);

        grid.el?.querySelectorAll('.grid-stack-item[gs-id]')?.forEach((element) => {
            const id = element.getAttribute('gs-id');
            if (!id) return;
            const entry = itemIndex()?.get(id);
            const inEngine = (grid.engine?.nodes || []).some((node) => node.id === id);
            if (entry && entry.container_public_id === publicId && inEngine) return;

            deps.setSilent?.(true);
            try {
                if (inEngine && element.gridstackNode) {
                    grid.removeWidget(element, true);
                } else {
                    element.remove();
                }
            } catch {
                element.remove();
            } finally {
                deps.setSilent?.(false);
            }
        });
    }
}

export function dedupeItemWidgets(itemPublicId) {
    const deps = d();
    const safeId = String(itemPublicId || '').replace(/"/g, '\\"');
    if (!safeId) return;

    const entry = itemIndex()?.get(itemPublicId);
    const keepContainerId = entry?.container_public_id || null;
    const nodes = [...document.querySelectorAll(`.grid-stack-item[gs-id="${safeId}"]`)];
    if (nodes.length <= 1) return;

    let kept = false;
    for (const element of nodes) {
        const hostId = element.closest('.grid-stack.inventory-grid')?.dataset?.containerPublicId || null;
        const shouldKeep = !kept
            && keepContainerId
            && hostId === keepContainerId
            && element.isConnected
            && !element.classList.contains('ui-draggable-dragging');

        if (shouldKeep) {
            kept = true;
            continue;
        }

        const grid = hostId ? grids()?.get(hostId) : null;
        deps.setSilent?.(true);
        try {
            if (grid && element.gridstackNode) {
                grid.removeWidget(element, true);
            } else {
                element.remove();
            }
        } catch {
            element.remove();
        } finally {
            deps.setSilent?.(false);
        }
    }
}
