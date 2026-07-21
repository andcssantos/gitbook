/**
 * Reconciliacao pontual do GridStack (P0).
 * Diff por public_id: add / update / remove — sem destroy total do grid.
 */

function itemKey(item) {
    return String(item?.public_id || '');
}

export function fingerprintInventoryItem(item, size) {
    return fingerprintItem(item, size);
}

function fingerprintItem(item, size) {
    if (!item) return '';
    const placement = item.placement || {};
    const id = itemKey(item);
    const x = Number(placement.grid_x || 0);
    const y = Number(placement.grid_y || 0);
    const w = Number(size?.w || 1);
    const h = Number(size?.h || 1);
    const rot = placement.rotated ? 1 : 0;
    const locked = placement.locked ? 1 : 0;
    const qty = Number(item.quantity || 1);
    const version = Number(placement.placement_version || 1);
    const icon = String(item.definition?.icon || item.icon || '');
    const name = String(item.definition?.name || item.name || '');
    const quality = String(item.quality_bucket || item.quality_value || '');
    return `${id}|${x},${y},${w}x${h}|r${rot}|L${locked}|q${qty}|v${version}|${icon}|${name}|${quality}`;
}

function buildIndexEntry(container, item, size, rotated) {
    const placement = item.placement || {};
    return {
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
    };
}

function runSilent(setSilent, fn) {
    if (typeof setSilent === 'function') {
        setSilent(true);
        try {
            return fn();
        } finally {
            setSilent(false);
        }
    }
    return fn();
}

function removeNode(grid, node, setSilent) {
    if (!node?.el) return;
    runSilent(setSilent, () => {
        try {
            grid.removeWidget(node.el, true);
        } catch {
            try {
                node.el.remove?.();
            } catch {
                /* ignore */
            }
        }
    });
}

function addItemToGrid(grid, container, item, {
    dimensionsForState,
    renderItem,
    bindItemWidget,
    itemIndex,
} = {}) {
    const id = itemKey(item);
    if (!id || !grid) return false;

    const placement = item.placement || {};
    const rotated = Boolean(placement.rotated);
    const size = typeof dimensionsForState === 'function'
        ? dimensionsForState(item, rotated)
        : { w: 1, h: 1 };

    if (itemIndex instanceof Map) {
        itemIndex.set(id, buildIndexEntry(container, item, size, rotated));
    }

    try {
        grid.addWidget({
            id,
            x: Number(placement.grid_x || 0),
            y: Number(placement.grid_y || 0),
            w: size.w,
            h: size.h,
            noResize: true,
            noMove: Boolean(placement.locked),
            locked: Boolean(placement.locked),
            content: typeof renderItem === 'function' ? renderItem(item) : '',
        });
    } catch {
        return false;
    }

    const widget = grid.engine?.nodes?.find((node) => node.id === id)?.el;
    if (widget) {
        widget.dataset.inventoryFp = fingerprintItem(item, size);
        if (typeof bindItemWidget === 'function') {
            try {
                bindItemWidget(container, item, widget);
            } catch {
                /* ignore */
            }
        }
    }

    return true;
}

/**
 * Aplica payload do container no grid montado, preservando widgets estaveis.
 *
 * @returns {{ skipped?: boolean, added: number, updated: number, removed: number, rebuilt?: boolean }}
 */
export function reconcileMountedGrid({
    containerPublicId,
    container,
    grids,
    itemIndex,
    setSilent,
    dimensionsForState,
    renderItem,
    bindItemWidget,
    updateContainerOccupancyBadge,
    applyInventoryFilters,
    isBusy,
    forceRebuild = false,
} = {}) {
    const result = { added: 0, updated: 0, removed: 0 };

    if (!containerPublicId || !container) {
        return { ...result, skipped: true };
    }

    if (typeof isBusy === 'function' && isBusy()) {
        return { ...result, skipped: true };
    }

    if (typeof document !== 'undefined'
        && document.documentElement.classList.contains('is-inventory-dragging')) {
        return { ...result, skipped: true };
    }

    const grid = grids?.get?.(containerPublicId);
    if (!grid?.engine?.nodes) {
        return { ...result, skipped: true };
    }

    const items = Array.isArray(container.items) ? container.items : [];
    const desired = new Map();
    for (const item of items) {
        const id = itemKey(item);
        if (!id) continue;
        desired.set(id, item);
    }

    if (itemIndex instanceof Map) {
        for (const [id, entry] of [...itemIndex.entries()]) {
            if (entry?.container_public_id === containerPublicId) {
                itemIndex.delete(id);
            }
        }
    }

    const existingNodes = [...(grid.engine.nodes || [])];

    if (forceRebuild) {
        for (const node of existingNodes) {
            removeNode(grid, node, setSilent);
            result.removed += 1;
        }
        grid.el?.querySelectorAll?.('.grid-stack-item')?.forEach((element) => {
            try {
                element.remove();
            } catch {
                /* ignore */
            }
        });
        result.rebuilt = true;
        for (const item of desired.values()) {
            if (addItemToGrid(grid, container, item, {
                dimensionsForState,
                renderItem,
                bindItemWidget,
                itemIndex,
            })) {
                result.added += 1;
            }
        }
        updateContainerOccupancyBadge?.(containerPublicId);
        applyInventoryFilters?.();
        return result;
    }

    const presentIds = new Set();

    for (const node of existingNodes) {
        const id = String(node.id || node.el?.getAttribute?.('gs-id') || '');
        if (!id) {
            removeNode(grid, node, setSilent);
            result.removed += 1;
            continue;
        }

        const next = desired.get(id);
        if (!next) {
            removeNode(grid, node, setSilent);
            result.removed += 1;
            continue;
        }

        presentIds.add(id);

        const placement = next.placement || {};
        const rotated = Boolean(placement.rotated);
        const size = typeof dimensionsForState === 'function'
            ? dimensionsForState(next, rotated)
            : { w: Number(node.w || 1), h: Number(node.h || 1) };

        if (itemIndex instanceof Map) {
            itemIndex.set(id, buildIndexEntry(container, next, size, rotated));
        }

        const el = node.el;
        const nextFp = fingerprintItem(next, size);
        const prevFp = el?.dataset?.inventoryFp || '';

        const x = Number(placement.grid_x || 0);
        const y = Number(placement.grid_y || 0);
        const w = Number(size.w || 1);
        const h = Number(size.h || 1);
        const locked = Boolean(placement.locked);

        const posChanged =
            Number(node.x) !== x
            || Number(node.y) !== y
            || Number(node.w) !== w
            || Number(node.h) !== h;

        if (posChanged || Boolean(node.locked) !== locked) {
            runSilent(setSilent, () => {
                try {
                    grid.update(el, {
                        x,
                        y,
                        w,
                        h,
                        noMove: locked,
                        locked,
                    });
                } catch {
                    /* ignore */
                }
            });
            result.updated += 1;
        }

        if (prevFp !== nextFp) {
            const content = el?.querySelector?.('.grid-stack-item-content');
            if (content && typeof renderItem === 'function') {
                try {
                    content.innerHTML = renderItem(next);
                } catch {
                    /* ignore */
                }
            }
            if (el) {
                el.dataset.inventoryFp = nextFp;
            }
            if (typeof bindItemWidget === 'function') {
                try {
                    bindItemWidget(container, next, el);
                } catch {
                    /* ignore */
                }
            }
            if (!posChanged) {
                result.updated += 1;
            }
        } else if (el && !el.dataset.inventoryFp) {
            el.dataset.inventoryFp = nextFp;
        }
    }

    for (const [id, item] of desired.entries()) {
        if (presentIds.has(id)) continue;
        if (addItemToGrid(grid, container, item, {
            dimensionsForState,
            renderItem,
            bindItemWidget,
            itemIndex,
        })) {
            result.added += 1;
        }
    }

    updateContainerOccupancyBadge?.(containerPublicId);
    applyInventoryFilters?.();
    return result;
}
