<section class="inventory-page" data-inventory-app>
    <header class="inventory-header">
        <div>
            <p class="inventory-kicker">Evolvaxe</p>
            <h1>Inventario</h1>
            <p class="inventory-shortcuts-hint">[E] Equipamento · [I] Inventario · [C] Status · [M] Mercado · [B] Materiais · [F] Criacao (workspace) · [Esc] fechar</p>
        </div>
        <div class="inventory-actions">
            <span class="inventory-summary" data-inventory-summary></span>
            <span class="inventory-status" data-inventory-status>Carregando...</span>
            <button class="inventory-button" type="button" data-craft-open>Criacao [F]</button>
            <button class="inventory-button" type="button" data-materials-open>Materiais [B]</button>
            <button class="inventory-button" type="button" data-market-open>Mercado [M]</button>
            <button class="inventory-button" type="button" data-inventory-refresh>Atualizar</button>
        </div>
    </header>

    <div class="inventory-stage">
        <div class="inventory-drawer-backdrop" data-inventory-backdrop hidden></div>

        <aside class="inventory-drawer inventory-drawer--stats" data-inventory-drawer-stats aria-label="Status do personagem" hidden>
            <div class="inventory-drawer-shell">
                <header class="inventory-drawer-header">
                    <div>
                        <p class="inventory-kicker">Drawer de status</p>
                        <h2>Atributos</h2>
                    </div>
                    <button type="button" class="inventory-drawer-close" data-drawer-close="stats" aria-label="Fechar drawer de status">×</button>
                </header>
                <div class="inventory-drawer-body">
                    <section class="inventory-stats-drawer-panel" data-character-stats-drawer></section>
                </div>
            </div>
        </aside>

        <aside class="inventory-drawer inventory-drawer--left" data-inventory-drawer-left aria-label="Equipamento e expedicao">
            <div class="inventory-drawer-shell">
                <header class="inventory-drawer-header">
                    <div>
                        <p class="inventory-kicker">Drawer esquerdo</p>
                        <h2>Equipamento</h2>
                    </div>
                    <button type="button" class="inventory-drawer-close" data-drawer-close="left" aria-label="Fechar drawer esquerdo">×</button>
                </header>
                <div class="inventory-drawer-body">
                    <section class="inventory-equipment-panel" data-inventory-equipment></section>
                    <section class="inventory-drawer-expedition" data-inventory-expedition></section>
                </div>
            </div>
        </aside>

        <aside class="inventory-drawer inventory-drawer--right" data-inventory-drawer-right aria-label="Inventario principal">
            <div class="inventory-drawer-shell">
                <header class="inventory-drawer-header">
                    <div>
                        <p class="inventory-kicker">Drawer direito</p>
                        <h2>Inventario</h2>
                    </div>
                    <div class="inventory-drawer-header-actions">
                        <button type="button" class="inventory-button inventory-market-toggle" data-market-toggle hidden>Entregas</button>
                        <button type="button" class="inventory-drawer-close" data-drawer-close="right" aria-label="Fechar drawer direito">×</button>
                    </div>
                </header>
                <div class="inventory-drawer-body">
                    <main class="inventory-layout" data-inventory-containers></main>
                </div>
            </div>
        </aside>

        <div class="inventory-hub" data-inventory-hub>
            <p class="inventory-kicker">Tela do jogo</p>
            <h2>Armazenamento</h2>
            <p>Use os atalhos para abrir os drawers laterais.</p>
            <div class="inventory-hub-actions">
                <button type="button" class="inventory-button" data-drawer-open="left">Abrir equipamento [E]</button>
                <button type="button" class="inventory-button" data-drawer-open="right">Abrir inventario [I]</button>
                <button type="button" class="inventory-button" data-drawer-open="stats">Abrir status [C]</button>
                <button type="button" class="inventory-button" data-market-open>Abrir mercado [M]</button>
                <button type="button" class="inventory-button" data-materials-open>Abrir materiais [B]</button>
                <button type="button" class="inventory-button" data-craft-open>Abrir criacao [F]</button>
            </div>
        </div>
    </div>

    <aside class="inventory-craft-panel" data-inventory-craft hidden aria-label="Forja e Alquimia"></aside>

    <aside class="inventory-materials-panel" data-inventory-materials hidden aria-label="Inventario de materiais">
        <div class="inventory-materials-shell">
            <header class="inventory-materials-header">
                <div>
                    <p class="inventory-kicker">Crafting</p>
                    <h2>Materiais</h2>
                </div>
                <div class="inventory-materials-header-actions">
                    <button type="button" class="inventory-button" data-materials-refresh>Atualizar</button>
                    <button type="button" class="inventory-drawer-close" data-materials-close aria-label="Fechar materiais">×</button>
                </div>
            </header>
            <div class="inventory-materials-tabs" data-materials-tabs></div>
            <div class="inventory-materials-grid-host" data-materials-list></div>
        </div>
    </aside>

    <aside class="inventory-market-panel" data-inventory-market hidden aria-label="Mercado P2P">
        <div class="inventory-market-shell">
            <header class="inventory-market-header">
                <div>
                    <p class="inventory-kicker">Economia</p>
                    <h2>Mercado P2P</h2>
                </div>
                <div class="inventory-market-header-actions">
                    <div class="inventory-market-wallets" data-market-wallets></div>
                    <button type="button" class="inventory-button" data-market-refresh>Atualizar</button>
                    <button type="button" class="inventory-drawer-close" data-market-close aria-label="Fechar mercado">×</button>
                </div>
            </header>
            <div class="inventory-market-filters">
                <input type="search" placeholder="Buscar item..." data-market-filter-q>
                <select data-market-filter-quality>
                    <option value="">Todas raridades</option>
                    <option value="common">Comum</option>
                    <option value="uncommon">Incomum</option>
                    <option value="magic">Magico</option>
                    <option value="rare">Raro</option>
                    <option value="epic">Epico</option>
                    <option value="legendary">Lendario</option>
                </select>
                <select data-market-filter-category>
                    <option value="">Todas categorias</option>
                    <option value="weapon">Arma</option>
                    <option value="armor">Armadura</option>
                    <option value="material">Material</option>
                    <option value="consumable">Consumivel</option>
                    <option value="tool">Ferramenta</option>
                </select>
                <input type="number" min="1" placeholder="Min 💎" data-market-filter-min>
                <input type="number" min="1" placeholder="Max 💎" data-market-filter-max>
            </div>
            <div class="inventory-market-list" data-market-listings></div>
        </div>
    </aside>

    <aside class="inventory-compare-dock" data-inventory-compare hidden></aside>
</section>
