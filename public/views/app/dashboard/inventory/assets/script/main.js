import { ApiError, apiFetch } from '/assets/framework/api.js';
import { installToastStyles, toast } from '/assets/framework/toast.js';

const app = document.querySelector('[data-inventory-app]');
const containerRoot = document.querySelector('[data-inventory-containers]');
const statusNode = document.querySelector('[data-inventory-status]');
const refreshButton = document.querySelector('[data-inventory-refresh]');

installToastStyles();

let grids = new Map();
let itemIndex = new Map();
let silent = false;
let loading = false;

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

    if (item.quality_bucket) parts.push(`Qualidade: ${escapeHtml(item.quality_bucket)}`);
    if (item.durability?.max) parts.push(`Durabilidade: ${Number(item.durability.current || 0)}/${Number(item.durability.max)}`);

    return parts.join('<br>');
}

function gridElement(container) {
    const grid = document.createElement('div');
    grid.className = 'grid-stack inventory-grid';
    grid.dataset.containerPublicId = container.public_id;
    grid.style.setProperty('--inventory-columns', String(container.grid.columns));
    grid.style.setProperty('--inventory-rows', String(container.grid.rows));
    return grid;
}

function renderItem(item) {
    const quantity = Number(item.quantity || 1);
    const code = item.definition?.code || '';
    const name = itemLabel(item);

    return `
        <div class="inventory-item" data-item-public-id="${escapeHtml(item.public_id)}">
            <span class="inventory-item-name">${escapeHtml(name)}</span>
            <span class="inventory-item-meta">
                <span>${escapeHtml(code)}</span>
                <span>${quantity > 1 ? `x${quantity}` : ''}</span>
            </span>
        </div>
    `;
}

function renderContainer(container) {
    const section = document.createElement('section');
    section.className = 'inventory-container';
    section.innerHTML = `
        <header class="inventory-container-header">
            <div class="inventory-container-title">
                <h2>${escapeHtml(container.name)}</h2>
                <p>${escapeHtml(container.definition_code)}</p>
            </div>
            <span class="inventory-container-meta">${Number(container.grid.columns)}x${Number(container.grid.rows)}</span>
        </header>
        <div class="inventory-grid-wrap"></div>
    `;

    section.querySelector('.inventory-grid-wrap').appendChild(gridElement(container));
    return section;
}

function destroyGrids() {
    for (const grid of grids.values()) {
        grid.destroy(false);
    }
    grids = new Map();
    itemIndex = new Map();
}

function initializeGrid(container, gridNode) {
    const grid = GridStack.init({
        column: Number(container.grid.columns),
        minRow: Number(container.grid.rows),
        maxRow: Number(container.grid.rows),
        cellHeight: 44,
        margin: 0,
        float: true,
        animate: true,
        acceptWidgets: true,
        draggable: {
            handle: '.grid-stack-item-content',
        },
        disableResize: true,
        removable: false,
        staticGrid: false,
    }, gridNode);

    grid.on('change', (_event, items) => {
        if (silent || loading) return;
        for (const changed of items || []) {
            queueMove(container.public_id, changed);
        }
    });

    grid.on('dropped', (_event, previousWidget, newWidget) => {
        if (silent || loading || !previousWidget || !newWidget) return;
        queueMove(container.public_id, newWidget);
    });

    grids.set(container.public_id, grid);
    return grid;
}

function addItems(container, grid) {
    for (const item of container.items || []) {
        const placement = item.placement || {};
        itemIndex.set(item.public_id, {
            container_public_id: container.public_id,
            placement_version: Number(placement.placement_version || 1),
        });

        grid.addWidget({
            id: item.public_id,
            x: Number(placement.grid_x || 0),
            y: Number(placement.grid_y || 0),
            w: Number(placement.grid_w || item.definition?.grid_w || 1),
            h: Number(placement.grid_h || item.definition?.grid_h || 1),
            noResize: true,
            locked: Boolean(placement.locked),
            content: renderItem(item),
        });

        const widget = grid.engine.nodes.find((node) => node.id === item.public_id)?.el;
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

let moveTimer = null;
const pendingMoves = new Map();

function queueMove(targetContainerPublicId, node) {
    const itemPublicId = node.id || node.el?.getAttribute('gs-id');
    if (!itemPublicId) return;

    pendingMoves.set(itemPublicId, {
        item_public_id: itemPublicId,
        target_container_public_id: targetContainerPublicId,
        grid_x: Number(node.x || 0),
        grid_y: Number(node.y || 0),
    });

    clearTimeout(moveTimer);
    moveTimer = setTimeout(flushMoves, 220);
}

async function flushMoves() {
    if (!pendingMoves.size) return;

    const moves = Array.from(pendingMoves.values());
    pendingMoves.clear();

    for (const move of moves) {
        const current = itemIndex.get(move.item_public_id);
        if (!current) continue;

        try {
            setStatus('Salvando...');
            const response = await apiFetch('/api/inventory/move', {
                method: 'POST',
                body: {
                    item_public_id: move.item_public_id,
                    source_container_public_id: current.container_public_id,
                    target_container_public_id: move.target_container_public_id,
                    grid_x: move.grid_x,
                    grid_y: move.grid_y,
                    expected_placement_version: current.placement_version,
                },
            });

            const result = response.data || {};
            itemIndex.set(move.item_public_id, {
                container_public_id: result.target_container_public_id || move.target_container_public_id,
                placement_version: Number(result.placement_version || current.placement_version + 1),
            });
            setStatus('Sincronizado');
        } catch (error) {
            handleError(error, 'Movimento rejeitado pelo servidor.');
            await loadInventory();
            return;
        }
    }
}

async function loadInventory() {
    if (loading || !app) return;

    loading = true;
    silent = true;
    setStatus('Carregando...');

    try {
        const response = await apiFetch('/api/inventory');
        const containers = response.data?.containers || [];

        destroyGrids();
        containerRoot.textContent = '';

        if (!containers.length) {
            containerRoot.innerHTML = '<div class="inventory-empty">Nenhum container encontrado.</div>';
            setStatus('Vazio');
            return;
        }

        for (const container of containers) {
            const section = renderContainer(container);
            containerRoot.appendChild(section);
            const gridNode = section.querySelector('.inventory-grid');
            const grid = initializeGrid(container, gridNode);
            addItems(container, grid);
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

refreshButton?.addEventListener('click', () => loadInventory());

loadInventory();
