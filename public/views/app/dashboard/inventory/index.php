<section class="inventory-page is-game-shell" data-inventory-app>
    <header class="game-hud-rail" aria-label="HUD do personagem">
        <section class="inventory-player-hud is-arpg is-toprail" data-player-hud></section>
    </header>

    <div class="inventory-stage">
        <div class="inventory-drawer-backdrop" data-inventory-backdrop hidden></div>

        <aside class="inventory-drawer inventory-drawer--stats inventory-drawer--stats-right inventory-drawer--arpg" data-inventory-drawer-stats aria-label="Status do personagem" hidden>
            <div class="inventory-drawer-shell">
                <header class="inventory-arpg-equip-header">
                    <div>
                        <p class="inventory-kicker">Combate</p>
                        <strong>Bonus equipados</strong>
                    </div>
                    <button type="button" class="inventory-arpg-close" data-drawer-close="stats" aria-label="Fechar status">×</button>
                </header>
                <div class="inventory-drawer-body">
                    <section class="inventory-stats-drawer-panel" data-character-stats-drawer></section>
                </div>
            </div>
        </aside>

        <aside class="inventory-drawer inventory-drawer--left inventory-drawer--arpg" data-inventory-drawer-left aria-label="Equipamento e expedicao">
            <div class="inventory-drawer-shell inventory-drawer-shell--equipment">
                <header class="inventory-arpg-equip-header">
                    <div>
                        <p class="inventory-kicker">Personagem</p>
                        <strong>Equipamento</strong>
                    </div>
                    <button type="button" class="inventory-arpg-close" data-drawer-close="left" aria-label="Fechar equipamento">×</button>
                </header>
                <div class="inventory-drawer-body">
                    <section class="inventory-equipment-panel" data-inventory-equipment></section>
                    <section class="inventory-equipment-loadouts" data-equipment-loadouts aria-label="Loadouts de equipamento"></section>
                    <section class="inventory-exploration-loadout-host" data-exploration-loadout aria-label="Loadout de exploracao"></section>
                </div>
            </div>
            <aside class="inventory-drawer-expedition" data-inventory-expedition hidden aria-label="Expedicao (Mochila)"></aside>
        </aside>

        <aside class="inventory-drawer inventory-drawer--right inventory-drawer--arpg" data-inventory-drawer-right aria-label="Inventario principal">
            <div class="inventory-drawer-shell">
                <header class="inventory-arpg-equip-header">
                    <div>
                        <p class="inventory-kicker">Bagagem</p>
                        <strong>Inventario</strong>
                    </div>
                    <div class="inventory-arpg-header-actions">
                        <button type="button" class="inventory-button inventory-market-toggle" data-market-toggle hidden>Entregas</button>
                        <button type="button" class="inventory-arpg-icon-btn" data-inventory-filter-open title="Filtros" aria-label="Filtros">⚙</button>
                        <button type="button" class="inventory-arpg-close" data-drawer-close="right" aria-label="Fechar inventario">×</button>
                    </div>
                </header>
                <div class="inventory-drawer-body">
                    <main class="inventory-layout" data-inventory-containers></main>
                </div>
            </div>
        </aside>

        <div class="inventory-hub game-hub" data-inventory-hub>
            <section class="game-hub-season" data-hub-season aria-label="Temporada">
                <p class="inventory-kicker">Temporada</p>
                <h2>Espaco da temporada</h2>
                <p>Banner, eventos e destaque sazonal entram aqui.</p>
            </section>

            <section class="game-hub-banners" data-hub-banners aria-label="Destaques">
                <article class="game-hub-card">
                    <p class="inventory-kicker">Destaque</p>
                    <strong>Banner principal</strong>
                    <span>Placeholder para campanha / noticia.</span>
                </article>
                <article class="game-hub-card">
                    <p class="inventory-kicker">Evento</p>
                    <strong>Card secundario</strong>
                    <span>Placeholder para missao ou drop.</span>
                </article>
                <article class="game-hub-card">
                    <p class="inventory-kicker">Mundo</p>
                    <strong>Acesso rapido</strong>
                    <span>Placeholder para bioma em destaque.</span>
                </article>
            </section>

            <p class="game-hub-hint">Atalhos: E I C · J M B F S · X explorar · 1-3 consumiveis</p>
        </div>
    </div>

    <nav class="game-dock is-arpg-dock" aria-label="Barra do jogo">
        <button type="button" class="game-dock-orb is-life" data-drawer-open="stats" title="Status / Vida [C]" aria-label="Status">
            <span class="game-dock-orb-fill" data-dock-hp-fill style="--orb-fill:100%"></span>
            <span class="game-dock-orb-label">HP</span>
            <kbd>C</kbd>
        </button>

        <div class="game-dock-core">
            <div class="game-dock-panels" role="group" aria-label="Paineis">
                <button type="button" class="game-dock-panel" data-drawer-open="left" title="Equipamento [E]"><span>E</span></button>
                <button type="button" class="game-dock-panel" data-drawer-open="right" title="Inventario [I]"><span>I</span></button>
                <button type="button" class="game-dock-panel" data-drawer-open="stats" title="Status [C]"><span>C</span></button>
                <button type="button" class="game-dock-panel" data-missions-open title="Missoes [J]"><span>J</span></button>
                <button type="button" class="game-dock-panel" data-market-open title="Mercado [M]"><span>M</span></button>
                <button type="button" class="game-dock-panel" data-materials-open title="Materiais [B]"><span>B</span></button>
                <button type="button" class="game-dock-panel" data-craft-open title="Criacao [F]"><span>F</span></button>
                <button type="button" class="game-dock-panel" data-set-codex-open title="Set Codex [S]"><span>S</span></button>
            </div>
            <div class="game-dock-xp" title="Experiencia" aria-hidden="true">
                <i data-dock-xp style="width:0%"></i>
            </div>
            <div class="game-dock-hotbar" data-dock-hotbar role="group" aria-label="Atalhos rapidos 1-7"></div>
        </div>

        <a class="game-dock-orb is-explore is-accent" href="/campaign" data-campaign-open title="Campanha [X]" aria-label="Explorar">
            <span class="game-dock-orb-fill is-energy" data-dock-en-fill style="--orb-fill:100%"></span>
            <span class="game-dock-orb-label">X</span>
            <small>Explorar</small>
        </a>
    </nav>

    <aside class="inventory-missions-panel" data-inventory-missions hidden aria-label="Journal de missoes">
        <div class="inventory-missions-shell">
            <header class="inventory-missions-header">
                <div>
                    <p class="inventory-kicker">Progresso</p>
                    <h2>Missoes</h2>
                </div>
                <div class="inventory-missions-header-actions">
                    <button type="button" class="inventory-button" data-missions-refresh>Atualizar</button>
                    <button type="button" class="inventory-drawer-close" data-missions-close aria-label="Fechar missoes">×</button>
                </div>
            </header>
            <div class="inventory-missions-tabs" data-missions-tabs role="tablist">
                <button type="button" class="inventory-missions-tab is-active" data-mission-filter="active" role="tab" aria-selected="true">Ativas</button>
                <button type="button" class="inventory-missions-tab" data-mission-filter="main" role="tab">Principais</button>
                <button type="button" class="inventory-missions-tab" data-mission-filter="side" role="tab">Secundarias</button>
                <button type="button" class="inventory-missions-tab" data-mission-filter="season" role="tab">Temporada</button>
                <button type="button" class="inventory-missions-tab" data-mission-filter="completed" role="tab">Concluidas</button>
            </div>
            <div class="inventory-missions-list" data-missions-list></div>
        </div>
    </aside>

    <aside class="inventory-craft-panel" data-inventory-craft hidden aria-label="Forja e Alquimia"></aside>

    <aside class="inventory-exploration-panel" data-inventory-exploration hidden aria-label="Exploracao do Bosque Inicial"></aside>

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
            <div class="inventory-market-tabs" data-market-tabs role="tablist">
                <button type="button" class="inventory-market-tab is-active" data-market-view="browse" role="tab" aria-selected="true">Anuncios</button>
                <button type="button" class="inventory-market-tab" data-market-view="mine" role="tab">Meus anuncios</button>
                <button type="button" class="inventory-market-tab" data-market-view="history" role="tab">Historico</button>
            </div>
            <div class="inventory-market-filters" data-market-browse-filters>
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
                <input type="number" min="1" placeholder="Min G" data-market-filter-min>
                <input type="number" min="1" placeholder="Max G" data-market-filter-max>
            </div>
            <div class="inventory-market-list" data-market-listings></div>
        </div>
    </aside>

    <aside class="inventory-set-codex-panel" data-inventory-set-codex hidden aria-label="Set Codex">
        <div class="inventory-set-codex-shell">
            <header class="inventory-set-codex-header">
                <div>
                    <p class="inventory-kicker">Colecao</p>
                    <h2>Set Codex</h2>
                </div>
                <div class="inventory-set-codex-header-actions">
                    <button type="button" class="inventory-button" data-set-codex-refresh>Atualizar</button>
                    <button type="button" class="inventory-drawer-close" data-set-codex-close aria-label="Fechar Set Codex">×</button>
                </div>
            </header>
            <div class="inventory-set-codex-list" data-set-codex-list></div>
        </div>
    </aside>

    <aside class="inventory-compare-dock" data-inventory-compare hidden></aside>
</section>
