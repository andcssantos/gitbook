/**
 * Janelas flutuantes de bags/baús (multi-open, arrastáveis).
 * Reaproveita grids/itemIndex da engine principal via deps.
 */

let deps = null;
/** @type {Map<string, { el: HTMLElement, x: number, y: number, z: number, sourceItemPublicId: string|null, cellSize: number }>} */
const floatingWindows = new Map();
let nextZ = 1300;
const STORAGE_KEY = 'evolvaxe.inventory.floatingBags';

export function configureFloatingBags(nextDeps) {
    deps = nextDeps || {};
}

function d() {
    return deps || {};
}

function escapeHtml(value) {
    return d().escapeHtml?.(value) ?? String(value ?? '');
}

export function getOpenFloatingContainerIds() {
    return Array.from(floatingWindows.keys());
}

export function isFloatingContainerOpen(containerPublicId) {
    return floatingWindows.has(containerPublicId);
}

export function ensureFloatingBagsRoot() {
    let root = document.querySelector('[data-floating-bags-root]');
    if (root) return root;
    root = document.createElement('div');
    root.className = 'inventory-floating-bags-root';
    root.dataset.floatingBagsRoot = '1';
    document.body.appendChild(root);
    return root;
}

function resolveFloatingCellSize(container) {
    const cols = Math.max(1, Number(container?.grid?.columns || 4));
    const rows = Math.max(1, Number(container?.grid?.rows || 4));
    const maxWidth = Math.min(460, Math.floor(window.innerWidth * 0.42));
    const maxHeight = Math.min(360, Math.floor(window.innerHeight * 0.42));
    const byWidth = Math.floor((maxWidth - 28) / cols);
    const byHeight = Math.floor((maxHeight - 56) / rows);
    return Math.max(22, Math.min(36, byWidth, byHeight));
}

function defaultWindowPosition(index = 0) {
    const baseX = Math.max(24, window.innerWidth - 520);
    const baseY = Math.max(90, Number(getComputedStyle(document.documentElement)
        .getPropertyValue('--game-chrome-top')
        .replace('px', '')) || 72) + 12;
    return {
        x: baseX - (index % 4) * 28,
        y: baseY + (index % 5) * 36,
    };
}

function clampWindowPosition(el, x, y) {
    const rect = el.getBoundingClientRect();
    const maxX = Math.max(8, window.innerWidth - Math.min(rect.width || 280, window.innerWidth - 16));
    const maxY = Math.max(8, window.innerHeight - 64);
    return {
        x: Math.max(8, Math.min(x, maxX)),
        y: Math.max(8, Math.min(y, maxY)),
    };
}

function persistFloatingBags() {
    try {
        const payload = Array.from(floatingWindows.entries()).map(([publicId, win]) => ({
            public_id: publicId,
            x: win.x,
            y: win.y,
            source_item_public_id: win.sourceItemPublicId,
        }));
        localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    } catch {
        // ignore
    }
}

export function loadFloatingBagsState() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function focusFloatingWindow(containerPublicId) {
    const win = floatingWindows.get(containerPublicId);
    if (!win?.el) return;
    nextZ += 1;
    win.z = nextZ;
    win.el.style.zIndex = String(nextZ);
    win.el.classList.add('is-focused');
    floatingWindows.forEach((other, id) => {
        if (id !== containerPublicId) other.el.classList.remove('is-focused');
    });
}

function bindWindowDrag(header, win, containerPublicId) {
    let dragging = false;
    let originX = 0;
    let originY = 0;
    let startX = 0;
    let startY = 0;

    const onMove = (event) => {
        if (!dragging) return;
        const next = clampWindowPosition(
            win.el,
            startX + (event.clientX - originX),
            startY + (event.clientY - originY)
        );
        win.x = next.x;
        win.y = next.y;
        win.el.style.left = `${next.x}px`;
        win.el.style.top = `${next.y}px`;
    };

    const onUp = () => {
        if (!dragging) return;
        dragging = false;
        document.removeEventListener('pointermove', onMove);
        document.removeEventListener('pointerup', onUp);
        persistFloatingBags();
    };

    header.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;
        if (event.target.closest('button')) return;
        dragging = true;
        focusFloatingWindow(containerPublicId);
        originX = event.clientX;
        originY = event.clientY;
        startX = win.x;
        startY = win.y;
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
        event.preventDefault();
    });
}

export function closeFloatingBagWindow(containerPublicId) {
    const win = floatingWindows.get(containerPublicId);
    if (!win) return;

    d().snapshotContainerDetailToCache?.(containerPublicId);

    const grids = d().grids;
    const gridCellSizes = d().gridCellSizes;
    const itemIndex = d().itemIndex;
    const grid = grids?.get(containerPublicId);

    if (grid) {
        d().setSilent?.(true);
        try {
            grid.destroy(false);
        } finally {
            d().setSilent?.(false);
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

    win.el.remove();
    floatingWindows.delete(containerPublicId);
    persistFloatingBags();
}

export function closeAllFloatingBagWindows() {
    for (const publicId of [...floatingWindows.keys()]) {
        closeFloatingBagWindow(publicId);
    }
}

async function paintFloatingWindowContent(win, container, summaryEntry) {
    const body = win.el.querySelector('[data-floating-bag-body]');
    const title = win.el.querySelector('[data-floating-bag-title]');
    if (!body) return;

    const cellSize = resolveFloatingCellSize(container);
    win.cellSize = cellSize;
    win.el.style.setProperty('--inventory-cell', `${cellSize}px`);
    win.el.dataset.floatingCols = String(container.grid?.columns || 1);
    win.el.dataset.floatingRows = String(container.grid?.rows || 1);

    if (title) {
        const cols = Number(container.grid?.columns || 0);
        const rows = Number(container.grid?.rows || 0);
        const name = d().containerDisplayName?.(container) || container.name || 'Bag';
        title.textContent = cols && rows ? `${name} · ${cols}x${rows}` : name;
    }

    d().containerIndex?.set(container.public_id, container);

    // Libera grid anterior (mesmo container) antes de remontar.
    const existingGrid = d().grids?.get(container.public_id);
    if (existingGrid) {
        d().setSilent?.(true);
        try {
            existingGrid.destroy(false);
        } catch {
            // ignore
        } finally {
            d().setSilent?.(false);
        }
        d().grids.delete(container.public_id);
        d().gridCellSizes?.delete(container.public_id);
    } else {
        d().unmountContainerPanel?.(container.public_id);
        d().openContainerPublicIds?.delete(container.public_id);
    }

    // Remove entradas antigas deste container no itemIndex antes de addItems.
    if (d().itemIndex) {
        for (const [publicId, indexed] of [...d().itemIndex.entries()]) {
            if (indexed.container_public_id === container.public_id) {
                d().itemIndex.delete(publicId);
            }
        }
    }

    body.replaceChildren();
    const section = d().renderContainer?.(container, summaryEntry, {
        floating: true,
        compact: true,
        onClose: () => closeFloatingBagWindow(container.public_id),
    });
    if (!section) return;
    body.appendChild(section);

    const gridNode = section.querySelector('.inventory-grid');
    if (!gridNode) return;

    d().gridCellSizes?.set(container.public_id, cellSize);
    gridNode.style.setProperty('--inventory-cell', `${cellSize}px`);
    const grid = d().initializeGrid?.(container, gridNode);
    if (grid) {
        d().addItems?.(container, grid);
        d().prefetchItemActions?.(container);
    }
    d().bindContainerLinks?.();
    d().applyInventoryFilters?.();
}

export async function openFloatingBagWindow(containerPublicId, options = {}) {
    if (!containerPublicId) return false;

    if (floatingWindows.has(containerPublicId)) {
        focusFloatingWindow(containerPublicId);
        d().highlightContainer?.(containerPublicId);
        // Se o grid morreu (ex.: reload parcial), remonta.
        if (!d().grids?.has(containerPublicId)) {
            try {
                const detail = await d().fetchContainerDetail?.(containerPublicId);
                if (detail?.container) {
                    const win = floatingWindows.get(containerPublicId);
                    if (win) {
                        await paintFloatingWindowContent(win, detail.container, detail.summaryEntry || null);
                    }
                }
            } catch {
                closeFloatingBagWindow(containerPublicId);
                return openFloatingBagWindow(containerPublicId, options);
            }
        }
        return true;
    }

    const root = ensureFloatingBagsRoot();
    const position = options.position || defaultWindowPosition(floatingWindows.size);
    nextZ += 1;

    const el = document.createElement('section');
    el.className = 'inventory-floating-bag is-focused';
    el.dataset.floatingBagWindow = containerPublicId;
    el.style.left = `${position.x}px`;
    el.style.top = `${position.y}px`;
    el.style.zIndex = String(nextZ);
    el.innerHTML = `
        <header class="inventory-floating-bag-header" data-floating-bag-drag>
            <div class="inventory-floating-bag-copy">
                <p class="inventory-kicker">Armazenamento</p>
                <strong data-floating-bag-title>Abrindo...</strong>
            </div>
            <button type="button" class="inventory-floating-bag-close" data-floating-bag-close aria-label="Fechar">×</button>
        </header>
        <div class="inventory-floating-bag-body" data-floating-bag-body>
            <p class="inventory-floating-bag-loading">Carregando...</p>
        </div>
    `;

    const win = {
        el,
        x: position.x,
        y: position.y,
        z: nextZ,
        sourceItemPublicId: options.sourceItemPublicId || null,
        cellSize: 32,
    };
    floatingWindows.set(containerPublicId, win);
    root.appendChild(el);

    floatingWindows.forEach((other, id) => {
        if (id !== containerPublicId) other.el.classList.remove('is-focused');
    });

    const header = el.querySelector('[data-floating-bag-drag]');
    if (header) bindWindowDrag(header, win, containerPublicId);
    el.addEventListener('pointerdown', () => focusFloatingWindow(containerPublicId));
    el.querySelector('[data-floating-bag-close]')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        closeFloatingBagWindow(containerPublicId);
    });

    try {
        d().setStatus?.('Abrindo...');
        // Garante fetch fresco ao reabrir (evita snapshot vazio pos-close).
        d().containerDetailCache?.invalidate?.(containerPublicId);
        const detail = await d().fetchContainerDetail?.(containerPublicId);
        const container = detail?.container;
        if (!container?.public_id) {
            throw new Error('Container nao encontrado.');
        }
        d().openContainerPublicIds?.delete(containerPublicId);
        d().persistContainerPanels?.();
        await paintFloatingWindowContent(win, container, detail.summaryEntry || null);
        const clamped = clampWindowPosition(el, win.x, win.y);
        win.x = clamped.x;
        win.y = clamped.y;
        el.style.left = `${clamped.x}px`;
        el.style.top = `${clamped.y}px`;
        persistFloatingBags();
        d().setStatus?.('Sincronizado');
        d().highlightContainer?.(containerPublicId);
        return true;
    } catch (error) {
        closeFloatingBagWindow(containerPublicId);
        d().handleError?.(error, 'Nao foi possivel abrir o bag/bau.');
        return false;
    }
}

export async function remountFloatingBagWindows() {
    const saved = Array.from(floatingWindows.entries()).map(([publicId, win]) => ({
        public_id: publicId,
        x: win.x,
        y: win.y,
        source_item_public_id: win.sourceItemPublicId,
    }));
    if (!saved.length) return;

    for (const entry of saved) {
        closeFloatingBagWindow(entry.public_id);
    }

    for (const entry of saved) {
        await openFloatingBagWindow(entry.public_id, {
            position: { x: entry.x, y: entry.y },
            sourceItemPublicId: entry.source_item_public_id || null,
        });
    }
}

export async function restoreFloatingBagWindows() {
    const saved = loadFloatingBagsState();
    if (!saved.length) return;
    for (const entry of saved) {
        if (!entry?.public_id) continue;
        await openFloatingBagWindow(entry.public_id, {
            position: { x: Number(entry.x) || defaultWindowPosition(0).x, y: Number(entry.y) || defaultWindowPosition(0).y },
            sourceItemPublicId: entry.source_item_public_id || null,
        });
    }
}

export async function softRefreshFloatingBagWindow(containerPublicId) {
    const win = floatingWindows.get(containerPublicId);
    if (!win) return false;
    try {
        const detail = await d().fetchContainerDetail?.(containerPublicId);
        if (!detail?.container) return false;
        await paintFloatingWindowContent(win, detail.container, detail.summaryEntry || null);
        return true;
    } catch {
        return false;
    }
}
