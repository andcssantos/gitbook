<section class="admin-content" data-admin-content>
    <header class="admin-content-header">
        <div>
            <p class="admin-kicker">Gestao de conteudo</p>
            <h1>Painel admin</h1>
            <p>Itens, investigaveis, propriedades, afixos, biomas, monstros e receitas.</p>
        </div>
        <div class="admin-content-actions">
            <a class="admin-button admin-button-ghost" href="/dashboard">Voltar ao jogo</a>
        </div>
    </header>

    <nav class="admin-tabs" aria-label="Modulos">
        <button type="button" class="admin-tab is-active" data-admin-tab="items">Itens</button>
        <button type="button" class="admin-tab" data-admin-tab="investigables">Investigaveis</button>
        <button type="button" class="admin-tab" data-admin-tab="properties">Propriedades</button>
        <button type="button" class="admin-tab" data-admin-tab="affixes">Afixos</button>
        <button type="button" class="admin-tab" data-admin-tab="biomes">Biomas</button>
        <button type="button" class="admin-tab" data-admin-tab="monsters">Monstros</button>
        <button type="button" class="admin-tab" data-admin-tab="recipes">Receitas</button>
    </nav>

    <section class="admin-panel is-active" data-admin-panel="items">
        <div class="admin-toolbar">
            <input type="search" placeholder="Buscar item..." data-items-q>
            <select data-items-status>
                <option value="">Todos os status</option>
                <option value="active">active</option>
                <option value="inactive">inactive</option>
                <option value="draft">draft</option>
            </select>
            <select data-items-category>
                <option value="">Todas as categorias</option>
            </select>
            <button type="button" class="admin-button" data-items-refresh>Atualizar</button>
            <button type="button" class="admin-button" data-items-new>Novo item</button>
            <span class="admin-status" data-items-status-text>Carregando...</span>
        </div>
        <div class="admin-layout">
            <aside class="admin-list" data-items-list></aside>
            <main class="admin-editor">
                <form class="admin-form" data-items-form>
                    <input type="hidden" name="mode" value="create" data-items-mode>
                    <div class="admin-form-grid">
                        <label>Codigo <input name="code" data-items-code required maxlength="100"></label>
                        <label>Nome <input name="name" required maxlength="120"></label>
                        <label>Categoria <select name="category_code" data-items-category-field required></select></label>
                        <label>Familia <select name="material_family_code" data-items-family-field><option value="">(nenhuma)</option></select></label>
                        <label>Status
                            <select name="status">
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                                <option value="draft">draft</option>
                            </select>
                        </label>
                        <label>Slot <input name="equip_slot_code" maxlength="40"></label>
                        <label>Grid W <input name="grid_w" type="number" min="1" value="1"></label>
                        <label>Grid H <input name="grid_h" type="number" min="1" value="1"></label>
                        <label>Max stack <input name="max_stack" type="number" min="1" value="1"></label>
                    </div>
                    <div class="admin-form-flags">
                        <label><input type="checkbox" name="stackable"> Stackable</label>
                        <label><input type="checkbox" name="tradeable" checked> Tradeable</label>
                        <label><input type="checkbox" name="is_container"> Container</label>
                    </div>
                    <label>Descricao <textarea name="description" rows="2"></textarea></label>
                    <label>base_config (JSON) <textarea name="base_config" rows="8" data-items-base-config>{}</textarea></label>
                    <div class="admin-form-actions">
                        <button type="submit" class="admin-button">Salvar item</button>
                    </div>
                </form>
            </main>
        </div>
    </section>

    <section class="admin-panel" data-admin-panel="investigables" hidden>
        <div class="admin-toolbar">
            <input type="search" placeholder="Buscar POI..." data-inv-q>
            <select data-inv-biome>
                <option value="">Todos os biomas</option>
            </select>
            <button type="button" class="admin-button" data-inv-refresh>Atualizar</button>
            <button type="button" class="admin-button" data-inv-new>Novo investigavel</button>
            <span class="admin-status" data-inv-status-text>Carregando...</span>
        </div>
        <div class="admin-layout">
            <aside class="admin-list" data-inv-list></aside>
            <main class="admin-editor">
                <form class="admin-form" data-inv-form>
                    <input type="hidden" name="mode" value="create" data-inv-mode>
                    <div class="admin-form-grid">
                        <label>Codigo <input name="code" data-inv-code required maxlength="80"></label>
                        <label>Nome <input name="name" required maxlength="120"></label>
                        <label>Bioma <select name="biome_code" data-inv-biome-field required></select></label>
                        <label>Kind <select name="kind" data-inv-kind-field></select></label>
                        <label>Icon key <input name="icon_key" maxlength="60"></label>
                        <label>Sort <input name="sort_order" type="number" min="0" value="10"></label>
                    </div>
                    <div class="admin-form-flags">
                        <label><input type="checkbox" name="is_active" checked> Ativo</label>
                    </div>
                    <label>Summary <textarea name="summary" rows="2"></textarea></label>
                    <label>config (JSON: map_x, map_y, analyze_tiers...) <textarea name="config" rows="8" data-inv-config>{}</textarea></label>
                    <label>actions (JSON array) <textarea name="actions" rows="10" data-inv-actions>[]</textarea></label>
                    <div class="admin-form-actions">
                        <button type="submit" class="admin-button">Salvar investigavel</button>
                    </div>
                    <p class="admin-hint">Exemplo de action: {"action_code":"harvest_shears","required_tool_type":"shears","attribute_code":"botany","xp_tool":20,"xp_attribute":12,"min_reveal_tier":1,"config":{"loot":[{"item_definition_code":"herb","quantity_min":1,"quantity_max":2,"weight":100}]}}</p>
                </form>
            </main>
        </div>
    </section>

    <section class="admin-panel" data-admin-panel="properties" hidden>
        <div class="admin-toolbar">
            <input type="search" placeholder="Buscar propriedade..." data-props-q>
            <select data-props-status>
                <option value="">Todos os status</option>
                <option value="active">active</option>
                <option value="inactive">inactive</option>
                <option value="draft">draft</option>
            </select>
            <select data-props-value-type>
                <option value="">Todos value_type</option>
            </select>
            <select data-props-scope>
                <option value="">Todos scopes</option>
            </select>
            <button type="button" class="admin-button" data-props-refresh>Atualizar</button>
            <button type="button" class="admin-button" data-props-new>Nova propriedade</button>
            <span class="admin-status" data-props-status-text>Carregando...</span>
        </div>
        <div class="admin-layout">
            <aside class="admin-list" data-props-list></aside>
            <main class="admin-editor">
                <form class="admin-form" data-props-form>
                    <input type="hidden" name="mode" value="create" data-props-mode>
                    <div class="admin-form-grid">
                        <label>Codigo <input name="code" data-props-code required maxlength="80"></label>
                        <label>Nome <input name="name" required maxlength="120"></label>
                        <label>Value type <select name="value_type" data-props-value-type-field></select></label>
                        <label>Equipment scope <select name="equipment_scope" data-props-scope-field></select></label>
                        <label>Unit <input name="unit" maxlength="40"></label>
                        <label>Status
                            <select name="status">
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                                <option value="draft">draft</option>
                            </select>
                        </label>
                        <label>Min value <input name="min_value" type="number" step="any"></label>
                        <label>Max value <input name="max_value" type="number" step="any"></label>
                    </div>
                    <div class="admin-form-flags">
                        <label><input type="checkbox" name="market_filterable"> Market filterable</label>
                    </div>
                    <div class="admin-form-actions">
                        <button type="submit" class="admin-button">Salvar propriedade</button>
                    </div>
                    <p class="admin-hint">Scopes: shared / offense / defense / exclusive_offense / exclusive_defense. Afixos herdam o escopo da propriedade vinculada.</p>
                </form>
            </main>
        </div>
    </section>

    <section class="admin-panel" data-admin-panel="affixes" hidden>
        <div class="admin-toolbar">
            <input type="search" placeholder="Buscar afixo..." data-affix-q>
            <select data-affix-status>
                <option value="">Todos os status</option>
                <option value="active">active</option>
                <option value="inactive">inactive</option>
                <option value="draft">draft</option>
            </select>
            <select data-affix-type>
                <option value="">Todos os tipos</option>
            </select>
            <select data-affix-property>
                <option value="">Todas propriedades</option>
            </select>
            <button type="button" class="admin-button" data-affix-refresh>Atualizar</button>
            <button type="button" class="admin-button" data-affix-new>Novo afixo</button>
            <span class="admin-status" data-affix-status-text>Carregando...</span>
        </div>
        <div class="admin-layout">
            <aside class="admin-list" data-affix-list></aside>
            <main class="admin-editor">
                <form class="admin-form" data-affix-form>
                    <input type="hidden" name="mode" value="create" data-affix-mode>
                    <div class="admin-form-grid">
                        <label>Codigo <input name="code" data-affix-code required maxlength="80"></label>
                        <label>Nome <input name="name" required maxlength="120"></label>
                        <label>Tipo <select name="affix_type" data-affix-type-field></select></label>
                        <label>Propriedade <select name="property_code" data-affix-property-field required></select></label>
                        <label>Status
                            <select name="status">
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                                <option value="draft">draft</option>
                            </select>
                        </label>
                        <label>Min value <input name="min_value" type="number" step="any" value="1"></label>
                        <label>Max value <input name="max_value" type="number" step="any" value="1"></label>
                        <label>Rarity weight <input name="rarity_weight" type="number" min="1" value="10"></label>
                        <label>Min item level <input name="min_item_level" type="number" min="1" value="1"></label>
                    </div>
                    <div class="admin-form-actions">
                        <button type="submit" class="admin-button">Salvar afixo</button>
                    </div>
                    <p class="admin-hint">Compatibilidade com arma/armadura vem do equipment_scope da propriedade escolhida.</p>
                </form>
            </main>
        </div>
    </section>

    <section class="admin-panel" data-admin-panel="biomes" hidden>
        <div class="admin-toolbar">
            <input type="search" placeholder="Buscar bioma..." data-biomes-q>
            <select data-biomes-status>
                <option value="">Todos os status</option>
                <option value="available">available</option>
                <option value="locked">locked</option>
                <option value="inactive">inactive</option>
                <option value="draft">draft</option>
            </select>
            <button type="button" class="admin-button" data-biomes-refresh>Atualizar</button>
            <button type="button" class="admin-button" data-biomes-new>Novo bioma</button>
            <span class="admin-status" data-biomes-status-text>Carregando...</span>
        </div>
        <div class="admin-layout">
            <aside class="admin-list" data-biomes-list></aside>
            <main class="admin-editor">
                <form class="admin-form" data-biomes-form>
                    <input type="hidden" name="mode" value="create" data-biomes-mode>
                    <div class="admin-form-grid">
                        <label>Codigo <input name="code" data-biomes-code required maxlength="80"></label>
                        <label>Nome <input name="name" required maxlength="120"></label>
                        <label>Status
                            <select name="status">
                                <option value="available">available</option>
                                <option value="locked">locked</option>
                                <option value="inactive">inactive</option>
                                <option value="draft">draft</option>
                            </select>
                        </label>
                        <label>Sort <input name="sort_order" type="number" min="0" value="10"></label>
                    </div>
                    <label>Summary <textarea name="summary" rows="2"></textarea></label>
                    <div class="admin-form-flags">
                        <label><input type="checkbox" name="requires_expedition" checked> Requires expedition</label>
                        <label><input type="checkbox" name="season_featured"> Season featured</label>
                    </div>
                    <div class="admin-form-grid">
                        <label>Duration (min) <input name="default_duration_minutes" type="number" min="1" value="30"></label>
                        <label>Respawn (min) <input name="default_respawn_minutes" type="number" min="0" value="15"></label>
                        <label>Discovery radius <input name="discovery_radius" type="number" step="any" min="0.1" value="1.5"></label>
                        <label>Map W <input name="map_width" type="number" step="any" min="1" value="6"></label>
                        <label>Map H <input name="map_height" type="number" step="any" min="1" value="4"></label>
                        <label>Spawn X <input name="spawn_x" type="number" step="any" value="1"></label>
                        <label>Spawn Y <input name="spawn_y" type="number" step="any" value="2"></label>
                        <label>Map node X <input name="map_node_x" type="number" value="0"></label>
                        <label>Map node Y <input name="map_node_y" type="number" value="0"></label>
                        <label>Background URL <input name="background_url" maxlength="255"></label>
                        <label>World art URL <input name="world_art_url" maxlength="255"></label>
                        <label>World pin URL <input name="world_pin_url" maxlength="255"></label>
                        <label>World structure URL <input name="world_structure_url" maxlength="255"></label>
                        <label>Monster spawn count <input name="monster_spawn_count" type="number" min="0" value="6"></label>
                        <label>Elite chance <input name="monster_elite_chance" type="number" step="any" min="0" max="1" value="0.18"></label>
                        <label>Rare chance <input name="monster_rare_chance" type="number" step="any" min="0" max="1" value="0.04"></label>
                        <label>Trap chance <input name="move_trap_chance" type="number" step="any" min="0" max="1" value="0.05"></label>
                        <label>Trap dmg min <input name="move_trap_damage_min" type="number" min="0" value="6"></label>
                        <label>Trap dmg max <input name="move_trap_damage_max" type="number" min="0" value="12"></label>
                        <label>Engage radius <input name="engage_radius" type="number" step="any" min="0.1" value="2"></label>
                        <label>Kills to boss <input name="kills_to_boss" type="number" min="1" value="10"></label>
                        <label>Heal on kill % <input name="heal_on_kill_pct" type="number" step="any" min="0" max="1" value="0.03"></label>
                        <label>Combat mode <select name="combat_mode" data-biomes-combat-mode></select></label>
                        <label>Wave size <input name="wave_size" type="number" min="1"></label>
                        <label>Wave pause kills <input name="wave_pause_kills" type="number" min="0"></label>
                    </div>
                    <label>unlock (JSON) <textarea name="unlock" rows="4" data-biomes-unlock>null</textarea></label>
                    <label>entry_requirements (JSON) <textarea name="entry_requirements" rows="4" data-biomes-entry>null</textarea></label>
                    <label>landmarks (JSON) <textarea name="landmarks" rows="6" data-biomes-landmarks>[]</textarea></label>
                    <label>monsters (JSON) <textarea name="monsters" rows="8" data-biomes-monsters>[]</textarea></label>
                    <label>settings (JSON, opcional) <textarea name="settings" rows="4" data-biomes-settings>null</textarea></label>
                    <div class="admin-form-actions">
                        <button type="submit" class="admin-button">Salvar bioma</button>
                    </div>
                    <p class="admin-hint">monsters: array de {"monster_code","spawn_weight","is_boss_candidate","enabled","sort_order"}. unlock / entry_requirements: objetos JSON (ex. requisitos de missao/nivel) ou null.</p>
                </form>
            </main>
        </div>
    </section>

    <section class="admin-panel" data-admin-panel="monsters" hidden>
        <div class="admin-toolbar">
            <input type="search" placeholder="Buscar monstro..." data-monsters-q>
            <select data-monsters-status>
                <option value="">Todos os status</option>
                <option value="active">active</option>
                <option value="inactive">inactive</option>
                <option value="draft">draft</option>
            </select>
            <button type="button" class="admin-button" data-monsters-refresh>Atualizar</button>
            <button type="button" class="admin-button" data-monsters-new>Novo monstro</button>
            <span class="admin-status" data-monsters-status-text>Carregando...</span>
        </div>
        <div class="admin-layout">
            <aside class="admin-list" data-monsters-list></aside>
            <main class="admin-editor">
                <form class="admin-form" data-monsters-form>
                    <input type="hidden" name="mode" value="create" data-monsters-mode>
                    <div class="admin-form-grid">
                        <label>Codigo <input name="code" data-monsters-code required maxlength="80"></label>
                        <label>Nome <input name="name" required maxlength="120"></label>
                        <label>Sprite key <select name="sprite_key" data-monsters-sprite-field></select></label>
                        <label>Element <input name="element" maxlength="40" value="neutral"></label>
                        <label>Resistance <input name="resistance" maxlength="40" value="neutral"></label>
                        <label>Status
                            <select name="status">
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                                <option value="draft">draft</option>
                            </select>
                        </label>
                        <label>Base HP <input name="base_hp" type="number" min="1" value="100"></label>
                        <label>Base attack <input name="base_attack" type="number" min="0" value="10"></label>
                        <label>Base defense <input name="base_defense" type="number" min="0" value="5"></label>
                        <label>Dodge rate <input name="dodge_rate" type="number" step="any" min="0" max="1" value="0.1"></label>
                        <label>Attack rate <input name="attack_rate" type="number" step="any" min="0" max="1" value="0.5"></label>
                        <label>Crit rate <input name="crit_rate" type="number" step="any" min="0" max="1" value="0.08"></label>
                        <label>Gold min <input name="reward_gold_min" type="number" min="0" value="3"></label>
                        <label>Gold max <input name="reward_gold_max" type="number" min="0" value="6"></label>
                        <label>XP min <input name="reward_xp_min" type="number" min="0" value="10"></label>
                        <label>XP max <input name="reward_xp_max" type="number" min="0" value="16"></label>
                    </div>
                    <label>loot (JSON array) <textarea name="loot" rows="8" data-monsters-loot>[]</textarea></label>
                    <div class="admin-form-actions">
                        <button type="submit" class="admin-button">Salvar monstro</button>
                    </div>
                    <p class="admin-hint">Exemplo de loot: [{"item_definition_code":"herb","quantity_min":1,"quantity_max":2,"weight":100}]</p>
                </form>
            </main>
        </div>
    </section>

    <section class="admin-panel" data-admin-panel="recipes" hidden>
        <div class="admin-toolbar">
            <input type="search" placeholder="Buscar receita..." data-recipes-q>
            <select data-recipes-status>
                <option value="">Todos os status</option>
                <option value="active">active</option>
                <option value="inactive">inactive</option>
                <option value="draft">draft</option>
            </select>
            <select data-recipes-workspace>
                <option value="">Todos os workspaces</option>
            </select>
            <button type="button" class="admin-button" data-recipes-refresh>Atualizar</button>
            <button type="button" class="admin-button" data-recipes-new>Nova receita</button>
            <span class="admin-status" data-recipes-status-text>Carregando...</span>
        </div>
        <div class="admin-layout">
            <aside class="admin-list" data-recipes-list></aside>
            <main class="admin-editor">
                <form class="admin-form" data-recipes-form>
                    <input type="hidden" name="mode" value="create" data-recipes-mode>
                    <div class="admin-form-grid">
                        <label>Codigo <input name="code" data-recipes-code required maxlength="80"></label>
                        <label>Nome <input name="name" required maxlength="120"></label>
                        <label>Workspace <select name="workspace" data-recipes-workspace-field required></select></label>
                        <label>Discovery
                            <select name="discovery">
                                <option value="public">public</option>
                                <option value="hidden">hidden</option>
                            </select>
                        </label>
                        <label>Status
                            <select name="status">
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                                <option value="draft">draft</option>
                            </select>
                        </label>
                        <label>Sort <input name="sort_order" type="number" min="0" value="10"></label>
                        <label>Gold fee <input name="gold_fee" type="number" min="0" value="0"></label>
                    </div>
                    <label>Descricao <textarea name="description" rows="2"></textarea></label>
                    <label>requirements (JSON array) <textarea name="requirements" rows="8" data-recipes-requirements>[]</textarea></label>
                    <label>outputs (JSON array) <textarea name="outputs" rows="8" data-recipes-outputs>[]</textarea></label>
                    <div class="admin-form-actions">
                        <button type="submit" class="admin-button">Salvar receita</button>
                    </div>
                    <p class="admin-hint">Exemplo de requirement: {"kind":"material_family","family_code":"wood","min":1,"label":"Madeira","weight":1}</p>
                    <p class="admin-hint">Exemplo de output: {"definition_code":"iron_sword","name":"Iron Sword","quality_bucket":"common","weight":1}</p>
                </form>
            </main>
        </div>
    </section>
</section>
