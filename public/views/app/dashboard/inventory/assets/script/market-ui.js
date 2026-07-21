/**
 * UI do painel de mercado P2P (extraído do main.js — Sprint I).
 * Entregas do mercado (marketDeliveryOpen) permanecem no main.js.
 */

let uiDeps = null;

let marketPanelOpen = false;
let marketListings = [];
let marketMyListings = [];
let marketHistory = [];
let marketView = 'browse';
let marketFilters = {
    q: '',
    quality_bucket: '',
    category_code: '',
    min_price: '',
    max_price: '',
};
let marketLoading = false;
let marketControlsInitialized = false;
let marketSearchTimer = null;

export function configureMarketUi(deps) {
    uiDeps = deps || {};
}

function d() {
    return uiDeps || {};
}

function apiFetch(...args) {
    return d().apiFetch(...args);
}

function escapeHtml(value) {
    return (d().escapeHtml || ((v) => String(v ?? '')))(value);
}

function toast(...args) {
    return d().toast?.(...args);
}

function handleError(...args) {
    return d().handleError?.(...args);
}

function setStatus(...args) {
    return d().setStatus?.(...args);
}

function syncDrawerUi() {
    return d().syncDrawerUi?.();
}

function closeMissionsPanel() {
    return d().closeMissionsPanel?.();
}

function closeExplorationPanel() {
    return d().closeExplorationPanel?.();
}

function isExplorationPanelOpen() {
    return Boolean(d().isExplorationPanelOpen?.());
}

function reloadContainerPanelsOnly() {
    return d().reloadContainerPanelsOnly?.();
}

function invalidateContainerCache() {
    return d().invalidateContainerCache?.();
}

function confirmInventoryAction(...args) {
    return d().confirmInventoryAction?.(...args);
}

function itemLabel(item) {
    return d().itemLabel?.(item) ?? String(item?.definition_name || item?.definition_code || 'Item');
}

function itemAssetUrl(item) {
    return d().itemAssetUrl?.(item) ?? null;
}

function itemTooltip(item, options = {}) {
    return d().itemTooltip?.(item, options) ?? '';
}

function upgradeLevelFromItem(item) {
    return Number(d().upgradeLevelFromItem?.(item) || 0);
}

function resolveItemTypeMeta(item) {
    return d().resolveItemTypeMeta?.(item) || { label: 'Item', icon: '◆' };
}

function walletBalance(code) {
    return Number(d().walletBalance?.(code) || 0);
}

function closeSiblingPanels() {
    return d().closeSiblingPanels?.();
}

function isBusy() {
    return Boolean(d().isBusy?.());
}

function setActionInFlight(value) {
    return d().setActionInFlight?.(value);
}

function marketPanelRootEl() {
    return d().marketPanelRoot || null;
}

function marketListingsRootEl() {
    return d().marketListingsRoot || null;
}

function marketWalletsRootEl() {
    return d().marketWalletsRoot || null;
}

export function isMarketPanelOpen() {
    return marketPanelOpen;
}

export function setMarketPanelOpen(open) {
    marketPanelOpen = Boolean(open);
}

export function renderMarketWallets() {
    const root = marketWalletsRootEl();
    if (!root) return;

    const gold = walletBalance('gold');
    const premium = walletBalance('premium');
    root.innerHTML = `
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
    const root = marketListingsRootEl();
    if (!root || !window.tippy) return;

    root.querySelectorAll('[data-market-item-preview]').forEach((node) => {
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

function syncMarketViewTabs() {
    const root = marketPanelRootEl();
    root?.querySelectorAll('[data-market-view]').forEach((button) => {
        const active = button.getAttribute('data-market-view') === marketView;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    const filters = root?.querySelector('[data-market-browse-filters]');
    if (filters) filters.hidden = marketView !== 'browse';
}

function renderMarketListings() {
    const root = marketListingsRootEl();
    if (!root) return;
    syncMarketViewTabs();

    if (marketLoading) {
        root.innerHTML = '<p class="inventory-market-empty">Carregando...</p>';
        return;
    }

    if (marketView === 'mine') {
        if (!marketMyListings.length) {
            root.innerHTML = '<p class="inventory-market-empty">Voce nao tem anuncios recentes.</p>';
            return;
        }
        root.innerHTML = marketMyListings.map((listing) => `
            <article class="inventory-market-card rarity-${escapeHtml(listing.quality_bucket || 'common')}${listing.status === 'active' ? ' is-own' : ''}">
                <div class="inventory-market-card-copy">
                    <div class="inventory-market-card-head">
                        <strong>${escapeHtml(listing.item_name || listing.definition_code || 'Item')}</strong>
                        <span class="inventory-market-card-price">${Number(listing.price_premium || 0).toLocaleString('pt-BR')} 💎</span>
                    </div>
                    <div class="inventory-market-card-meta">
                        <span>${escapeHtml(listing.status || 'active')}</span>
                        <span>${escapeHtml(listing.listed_at || '')}</span>
                    </div>
                </div>
                ${listing.status === 'active' ? `<div class="inventory-market-card-actions"><button type="button" class="inventory-button inventory-button-ghost" data-market-cancel="${escapeHtml(listing.public_id)}">Remover anuncio</button></div>` : ''}
            </article>
        `).join('');
        return;
    }

    if (marketView === 'history') {
        if (!marketHistory.length) {
            root.innerHTML = '<p class="inventory-market-empty">Sem historico de transacoes.</p>';
            return;
        }
        root.innerHTML = marketHistory.map((tx) => `
            <article class="inventory-market-card rarity-${escapeHtml(tx.quality_bucket || 'common')}">
                <div class="inventory-market-card-copy">
                    <div class="inventory-market-card-head">
                        <strong>${escapeHtml(tx.item_name || tx.definition_code || 'Item')}</strong>
                        <span class="inventory-market-card-price">${Number(tx.price_premium || 0).toLocaleString('pt-BR')} 💎</span>
                    </div>
                    <div class="inventory-market-card-meta">
                        <span>${escapeHtml(tx.created_at || '')}</span>
                        <span>Liquido vendedor: ${Number(tx.seller_net_premium || 0).toLocaleString('pt-BR')} 💎</span>
                    </div>
                </div>
            </article>
        `).join('');
        return;
    }

    if (!marketListings.length) {
        root.innerHTML = '<p class="inventory-market-empty">Nenhum anuncio encontrado.</p>';
        return;
    }

    root.innerHTML = marketListings.map((listing) => {
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

export async function loadMarketListings() {
    if (!marketPanelOpen || marketLoading) return;

    marketLoading = true;
    renderMarketListings();

    try {
        if (marketView === 'mine') {
            const response = await apiFetch('/api/market/me/listings');
            marketMyListings = response.data?.listings || [];
        } else if (marketView === 'history') {
            const response = await apiFetch('/api/market/me/history');
            marketHistory = response.data?.transactions || [];
        } else {
            const params = new URLSearchParams();
            if (marketFilters.q) params.set('q', marketFilters.q);
            if (marketFilters.quality_bucket) params.set('quality_bucket', marketFilters.quality_bucket);
            if (marketFilters.category_code) params.set('category_code', marketFilters.category_code);
            if (marketFilters.min_price) params.set('min_price', marketFilters.min_price);
            if (marketFilters.max_price) params.set('max_price', marketFilters.max_price);
            params.set('limit', '60');

            const response = await apiFetch(`/api/market/listings?${params.toString()}`);
            marketListings = response.data?.listings || [];
        }
    } catch (error) {
        marketListings = [];
        marketMyListings = [];
        marketHistory = [];
        handleError(error, 'Nao foi possivel carregar o mercado.');
    } finally {
        marketLoading = false;
        renderMarketListings();
    }
}

function syncMarketFilterInputs() {
    const root = marketPanelRootEl();
    if (!root) return;

    const searchInput = root.querySelector('[data-market-filter-q]');
    const qualitySelect = root.querySelector('[data-market-filter-quality]');
    const categorySelect = root.querySelector('[data-market-filter-category]');
    const minInput = root.querySelector('[data-market-filter-min]');
    const maxInput = root.querySelector('[data-market-filter-max]');

    if (searchInput) searchInput.value = marketFilters.q;
    if (qualitySelect) qualitySelect.value = marketFilters.quality_bucket;
    if (categorySelect) categorySelect.value = marketFilters.category_code;
    if (minInput) minInput.value = marketFilters.min_price;
    if (maxInput) maxInput.value = marketFilters.max_price;
}

export function openMarketPanel() {
    closeSiblingPanels();
    if (isExplorationPanelOpen()) closeExplorationPanel();
    closeMissionsPanel();
    marketPanelOpen = true;
    syncDrawerUi();
    renderMarketWallets();
    syncMarketFilterInputs();
    loadMarketListings();
}

export function closeMarketPanel() {
    marketPanelOpen = false;
    syncDrawerUi();
}

export function toggleMarketPanel() {
    if (marketPanelOpen) {
        closeMarketPanel();
        return;
    }
    openMarketPanel();
}

async function cancelMarketListing(listingPublicId) {
    if (isBusy() || !listingPublicId) return;

    const listing = marketListings.find((entry) => entry.listing_public_id === listingPublicId)
        || marketMyListings.find((entry) => entry.public_id === listingPublicId || entry.listing_public_id === listingPublicId);
    const itemName = listing ? itemLabel(listing.item || { definition_name: listing.item_name, definition_code: listing.definition_code }) : 'Item';
    const confirmed = await confirmInventoryAction({
        title: 'Remover anuncio',
        bodyHtml: `<p>Remover <strong>${escapeHtml(itemName)}</strong> do mercado?</p>
            <p>O item voltara para seu inventario principal. A taxa de anuncio nao e reembolsada.</p>`,
        confirmLabel: 'Remover',
        tone: 'danger',
    });
    if (!confirmed) return;

    setActionInFlight(true);
    try {
        setStatus('Removendo anuncio...');
        await apiFetch(`/api/market/listings/${encodeURIComponent(listingPublicId)}/cancel`, {
            method: 'POST',
            body: {},
        });
        toast('Anuncio removido. Item devolvido ao inventario.', 'success', 3600);
        setStatus('Sincronizado');
        invalidateContainerCache();
        await reloadContainerPanelsOnly();
        await loadMarketListings();
    } catch (error) {
        handleError(error, 'Nao foi possivel remover o anuncio.');
    } finally {
        setActionInFlight(false);
    }
}

async function buyMarketListing(listingPublicId) {
    if (isBusy() || !listingPublicId) return;

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

    setActionInFlight(true);
    try {
        setStatus('Comprando item...');
        await apiFetch(`/api/market/listings/${encodeURIComponent(listingPublicId)}/buy`, {
            method: 'POST',
            body: {},
        });
        toast('Compra concluida. Verifique as entregas do mercado.', 'success', 3600);
        setStatus('Sincronizado');
        invalidateContainerCache();
        await reloadContainerPanelsOnly();
        await loadMarketListings();
    } catch (error) {
        handleError(error, 'Nao foi possivel concluir a compra.');
    } finally {
        setActionInFlight(false);
    }
}

export function initMarketControls() {
    if (marketControlsInitialized) return;
    marketControlsInitialized = true;

    const root = marketPanelRootEl();

    document.querySelectorAll('[data-market-open]').forEach((button) => {
        button.addEventListener('click', () => toggleMarketPanel());
    });

    document.querySelector('[data-market-refresh]')?.addEventListener('click', () => loadMarketListings());

    root?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        if (target.closest('[data-market-close]')) {
            closeMarketPanel();
            return;
        }

        const viewButton = target.closest('[data-market-view]');
        if (viewButton) {
            marketView = viewButton.getAttribute('data-market-view') || 'browse';
            void loadMarketListings();
            return;
        }

        const cancelButton = target.closest('[data-market-cancel]');
        if (cancelButton) {
            cancelMarketListing(cancelButton.getAttribute('data-market-cancel'));
            return;
        }

        if (event.target === root) {
            closeMarketPanel();
            return;
        }

        const buyButton = target.closest('[data-market-buy]');
        if (!buyButton) return;
        buyMarketListing(buyButton.getAttribute('data-market-buy'));
    });

    root?.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        if (target.matches('[data-market-filter-q]')) marketFilters.q = target.value.trim();
        if (target.matches('[data-market-filter-quality]')) marketFilters.quality_bucket = target.value;
        if (target.matches('[data-market-filter-category]')) marketFilters.category_code = target.value;
        if (target.matches('[data-market-filter-min]')) marketFilters.min_price = target.value;
        if (target.matches('[data-market-filter-max]')) marketFilters.max_price = target.value;
    });

    root?.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        if (!target.matches('[data-market-filter-quality], [data-market-filter-category], [data-market-filter-min], [data-market-filter-max]')) {
            return;
        }
        loadMarketListings();
    });

    root?.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement) || !target.matches('[data-market-filter-q]')) return;
        clearTimeout(marketSearchTimer);
        marketSearchTimer = setTimeout(() => loadMarketListings(), 320);
    });
}
