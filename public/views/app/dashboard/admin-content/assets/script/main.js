import { apiFetch, ApiError } from '/assets/framework/api.js';

const root = document.querySelector('[data-admin-content]');

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function fillSelect(select, entries, includeEmpty = false, emptyLabel = '(nenhuma)') {
    if (!select) return;
    const options = [];
    if (includeEmpty) options.push(`<option value="">${emptyLabel}</option>`);
    for (const entry of entries) {
        const code = entry.code ?? entry;
        const name = entry.name ?? entry.code ?? entry;
        options.push(`<option value="${escapeHtml(code)}">${escapeHtml(code)}${entry.name ? ` — ${escapeHtml(name)}` : ''}</option>`);
    }
    select.innerHTML = options.join('');
}

function errorMessage(error) {
    if (error instanceof ApiError) return error.payload?.message || error.message;
    return error?.message || 'Erro inesperado';
}

function switchTab(tab) {
    root.querySelectorAll('[data-admin-tab]').forEach((button) => {
        button.classList.toggle('is-active', button.getAttribute('data-admin-tab') === tab);
    });
    root.querySelectorAll('[data-admin-panel]').forEach((panel) => {
        const active = panel.getAttribute('data-admin-panel') === tab;
        panel.hidden = !active;
        panel.classList.toggle('is-active', active);
    });
}

/* -------------------- Items -------------------- */
const items = {
    meta: { categories: [], material_families: [] },
    list: [],
    selected: null,
    timer: null,
    els: {
        list: () => root.querySelector('[data-items-list]'),
        form: () => root.querySelector('[data-items-form]'),
        status: () => root.querySelector('[data-items-status-text]'),
        q: () => root.querySelector('[data-items-q]'),
        statusFilter: () => root.querySelector('[data-items-status]'),
        categoryFilter: () => root.querySelector('[data-items-category]'),
        categoryField: () => root.querySelector('[data-items-category-field]'),
        familyField: () => root.querySelector('[data-items-family-field]'),
        code: () => root.querySelector('[data-items-code]'),
        mode: () => root.querySelector('[data-items-mode]'),
        baseConfig: () => root.querySelector('[data-items-base-config]'),
    },
    setStatus(message) {
        const node = this.els.status();
        if (node) node.textContent = message;
    },
    renderList() {
        const listRoot = this.els.list();
        if (!listRoot) return;
        if (!this.list.length) {
            listRoot.innerHTML = '<p style="padding:1rem;color:#9aabbd">Nenhum item.</p>';
            return;
        }
        listRoot.innerHTML = this.list.map((item) => `
            <button type="button" data-items-pick="${escapeHtml(item.code)}" class="${item.code === this.selected ? 'is-active' : ''}">
                <strong>${escapeHtml(item.name)}</strong>
                <small>${escapeHtml(item.code)} · ${escapeHtml(item.category_code)} · ${escapeHtml(item.status)}</small>
            </button>
        `).join('');
    },
    reset() {
        const form = this.els.form();
        if (!form) return;
        form.reset();
        this.els.mode().value = 'create';
        this.els.code().readOnly = false;
        this.els.baseConfig().value = '{\n\n}';
        this.selected = null;
        this.renderList();
    },
    fill(item) {
        const form = this.els.form();
        this.els.mode().value = 'edit';
        this.els.code().readOnly = true;
        this.selected = item.code;
        form.code.value = item.code;
        form.name.value = item.name || '';
        form.description.value = item.description || '';
        form.category_code.value = item.category_code || '';
        form.material_family_code.value = item.material_family_code || '';
        form.status.value = item.status || 'active';
        form.equip_slot_code.value = item.equip_slot_code || '';
        form.grid_w.value = item.grid_w || 1;
        form.grid_h.value = item.grid_h || 1;
        form.max_stack.value = item.max_stack || 1;
        form.stackable.checked = Boolean(item.stackable);
        form.tradeable.checked = item.tradeable !== false;
        form.is_container.checked = Boolean(item.is_container);
        this.els.baseConfig().value = JSON.stringify(item.base_config || {}, null, 2);
        this.renderList();
    },
    async loadMeta() {
        const response = await apiFetch('/api/admin/items/meta');
        this.meta = response.data || this.meta;
        fillSelect(this.els.categoryFilter(), this.meta.categories || [], true, 'Todas as categorias');
        fillSelect(this.els.categoryField(), this.meta.categories || []);
        fillSelect(this.els.familyField(), this.meta.material_families || [], true);
    },
    async loadList() {
        this.setStatus('Carregando itens...');
        const params = new URLSearchParams({ limit: '120' });
        if (this.els.q()?.value.trim()) params.set('q', this.els.q().value.trim());
        if (this.els.statusFilter()?.value) params.set('status', this.els.statusFilter().value);
        if (this.els.categoryFilter()?.value) params.set('category_code', this.els.categoryFilter().value);
        const response = await apiFetch(`/api/admin/items?${params}`);
        this.list = response.data?.items || [];
        this.renderList();
        this.setStatus(`${response.data?.total ?? this.list.length} item(ns)`);
    },
    async open(code) {
        const response = await apiFetch(`/api/admin/items/${encodeURIComponent(code)}`);
        this.fill(response.data?.item);
        this.setStatus(`Editando ${code}`);
    },
    async save(event) {
        event.preventDefault();
        const form = this.els.form();
        let baseConfig = {};
        try {
            baseConfig = JSON.parse(this.els.baseConfig().value || '{}');
        } catch (_error) {
            throw new Error('base_config precisa ser JSON valido.');
        }
        const payload = {
            code: form.code.value.trim(),
            name: form.name.value.trim(),
            description: form.description.value.trim() || null,
            category_code: form.category_code.value,
            material_family_code: form.material_family_code.value || null,
            status: form.status.value,
            equip_slot_code: form.equip_slot_code.value.trim() || null,
            grid_w: Number(form.grid_w.value || 1),
            grid_h: Number(form.grid_h.value || 1),
            max_stack: Number(form.max_stack.value || 1),
            stackable: Boolean(form.stackable.checked),
            tradeable: Boolean(form.tradeable.checked),
            is_container: Boolean(form.is_container.checked),
            base_config: baseConfig,
        };
        this.setStatus('Salvando item...');
        if (this.els.mode().value === 'create') {
            await apiFetch('/api/admin/items', { method: 'POST', body: payload });
        } else {
            await apiFetch(`/api/admin/items/${encodeURIComponent(payload.code)}`, { method: 'POST', body: payload });
        }
        await this.loadList();
        await this.open(payload.code);
    },
    bind() {
        root.querySelector('[data-items-refresh]')?.addEventListener('click', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        root.querySelector('[data-items-new]')?.addEventListener('click', () => { this.reset(); this.setStatus('Novo item'); });
        this.els.form()?.addEventListener('submit', (event) => this.save(event).catch((e) => { this.setStatus(errorMessage(e)); alert(errorMessage(e)); }));
        this.els.list()?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-items-pick]');
            if (!button) return;
            this.open(button.getAttribute('data-items-pick')).catch((e) => this.setStatus(errorMessage(e)));
        });
        const reload = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.loadList().catch((e) => this.setStatus(errorMessage(e))), 250);
        };
        this.els.q()?.addEventListener('input', reload);
        this.els.statusFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        this.els.categoryFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
    },
};

/* -------------------- Investigables -------------------- */
const investigables = {
    meta: { biomes: [], kinds: [] },
    list: [],
    selected: null,
    timer: null,
    els: {
        list: () => root.querySelector('[data-inv-list]'),
        form: () => root.querySelector('[data-inv-form]'),
        status: () => root.querySelector('[data-inv-status-text]'),
        q: () => root.querySelector('[data-inv-q]'),
        biomeFilter: () => root.querySelector('[data-inv-biome]'),
        biomeField: () => root.querySelector('[data-inv-biome-field]'),
        kindField: () => root.querySelector('[data-inv-kind-field]'),
        code: () => root.querySelector('[data-inv-code]'),
        mode: () => root.querySelector('[data-inv-mode]'),
        config: () => root.querySelector('[data-inv-config]'),
        actions: () => root.querySelector('[data-inv-actions]'),
    },
    setStatus(message) {
        const node = this.els.status();
        if (node) node.textContent = message;
    },
    renderList() {
        const listRoot = this.els.list();
        if (!listRoot) return;
        if (!this.list.length) {
            listRoot.innerHTML = '<p style="padding:1rem;color:#9aabbd">Nenhum investigavel.</p>';
            return;
        }
        listRoot.innerHTML = this.list.map((entry) => `
            <button type="button" data-inv-pick="${escapeHtml(entry.code)}" class="${entry.code === this.selected ? 'is-active' : ''}">
                <strong>${escapeHtml(entry.name)}</strong>
                <small>${escapeHtml(entry.code)} · ${escapeHtml(entry.biome_code)} · ${escapeHtml(entry.kind)}</small>
            </button>
        `).join('');
    },
    reset() {
        const form = this.els.form();
        if (!form) return;
        form.reset();
        this.els.mode().value = 'create';
        this.els.code().readOnly = false;
        form.is_active.checked = true;
        this.els.config().value = JSON.stringify({
            position_label: '',
            map_x: 2,
            map_y: 2,
            flavor_unknown: '',
            analyze_tiers: [],
        }, null, 2);
        this.els.actions().value = '[]';
        this.selected = null;
        this.renderList();
    },
    fill(definition) {
        const form = this.els.form();
        this.els.mode().value = 'edit';
        this.els.code().readOnly = true;
        this.selected = definition.code;
        form.code.value = definition.code;
        form.name.value = definition.name || '';
        form.biome_code.value = definition.biome_code || '';
        form.kind.value = definition.kind || 'other';
        form.icon_key.value = definition.icon_key || '';
        form.sort_order.value = definition.sort_order || 0;
        form.summary.value = definition.summary || '';
        form.is_active.checked = definition.is_active !== false;
        this.els.config().value = JSON.stringify(definition.config || {}, null, 2);
        this.els.actions().value = JSON.stringify(definition.actions || [], null, 2);
        this.renderList();
    },
    async loadMeta() {
        const response = await apiFetch('/api/admin/investigables/meta');
        this.meta = response.data || this.meta;
        fillSelect(this.els.biomeFilter(), this.meta.biomes || [], true, 'Todos os biomas');
        fillSelect(this.els.biomeField(), this.meta.biomes || []);
        fillSelect(this.els.kindField(), (this.meta.kinds || []).map((kind) => ({ code: kind, name: kind })));
    },
    async loadList() {
        this.setStatus('Carregando investigaveis...');
        const params = new URLSearchParams({ limit: '150' });
        if (this.els.q()?.value.trim()) params.set('q', this.els.q().value.trim());
        if (this.els.biomeFilter()?.value) params.set('biome_code', this.els.biomeFilter().value);
        const response = await apiFetch(`/api/admin/investigables?${params}`);
        this.list = response.data?.definitions || [];
        this.renderList();
        this.setStatus(`${response.data?.total ?? this.list.length} investigavel(is)`);
    },
    async open(code) {
        const response = await apiFetch(`/api/admin/investigables/${encodeURIComponent(code)}`);
        this.fill(response.data?.definition);
        this.setStatus(`Editando ${code}`);
    },
    async save(event) {
        event.preventDefault();
        const form = this.els.form();
        let config = {};
        let actions = [];
        try {
            config = JSON.parse(this.els.config().value || '{}');
            actions = JSON.parse(this.els.actions().value || '[]');
        } catch (_error) {
            throw new Error('config/actions precisam ser JSON validos.');
        }
        if (!Array.isArray(actions)) throw new Error('actions deve ser um array JSON.');

        const payload = {
            code: form.code.value.trim(),
            name: form.name.value.trim(),
            biome_code: form.biome_code.value,
            kind: form.kind.value || 'other',
            icon_key: form.icon_key.value.trim() || null,
            sort_order: Number(form.sort_order.value || 0),
            summary: form.summary.value.trim() || null,
            is_active: Boolean(form.is_active.checked),
            config,
            actions,
        };
        this.setStatus('Salvando investigavel...');
        if (this.els.mode().value === 'create') {
            await apiFetch('/api/admin/investigables', { method: 'POST', body: payload });
        } else {
            await apiFetch(`/api/admin/investigables/${encodeURIComponent(payload.code)}`, { method: 'POST', body: payload });
        }
        await this.loadList();
        await this.open(payload.code);
    },
    bind() {
        root.querySelector('[data-inv-refresh]')?.addEventListener('click', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        root.querySelector('[data-inv-new]')?.addEventListener('click', () => { this.reset(); this.setStatus('Novo investigavel'); });
        this.els.form()?.addEventListener('submit', (event) => this.save(event).catch((e) => { this.setStatus(errorMessage(e)); alert(errorMessage(e)); }));
        this.els.list()?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-inv-pick]');
            if (!button) return;
            this.open(button.getAttribute('data-inv-pick')).catch((e) => this.setStatus(errorMessage(e)));
        });
        const reload = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.loadList().catch((e) => this.setStatus(errorMessage(e))), 250);
        };
        this.els.q()?.addEventListener('input', reload);
        this.els.biomeFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
    },
};

/* -------------------- Properties -------------------- */
const properties = {
    meta: { value_types: [], equipment_scopes: [] },
    list: [],
    selected: null,
    timer: null,
    els: {
        list: () => root.querySelector('[data-props-list]'),
        form: () => root.querySelector('[data-props-form]'),
        status: () => root.querySelector('[data-props-status-text]'),
        q: () => root.querySelector('[data-props-q]'),
        statusFilter: () => root.querySelector('[data-props-status]'),
        valueTypeFilter: () => root.querySelector('[data-props-value-type]'),
        scopeFilter: () => root.querySelector('[data-props-scope]'),
        valueTypeField: () => root.querySelector('[data-props-value-type-field]'),
        scopeField: () => root.querySelector('[data-props-scope-field]'),
        code: () => root.querySelector('[data-props-code]'),
        mode: () => root.querySelector('[data-props-mode]'),
    },
    setStatus(message) {
        const node = this.els.status();
        if (node) node.textContent = message;
    },
    renderList() {
        const listRoot = this.els.list();
        if (!listRoot) return;
        if (!this.list.length) {
            listRoot.innerHTML = '<p style="padding:1rem;color:#9aabbd">Nenhuma propriedade.</p>';
            return;
        }
        listRoot.innerHTML = this.list.map((entry) => `
            <button type="button" data-props-pick="${escapeHtml(entry.code)}" class="${entry.code === this.selected ? 'is-active' : ''}">
                <strong>${escapeHtml(entry.name)}</strong>
                <small>${escapeHtml(entry.code)} · ${escapeHtml(entry.value_type)} · ${escapeHtml(entry.equipment_scope)}</small>
            </button>
        `).join('');
    },
    reset() {
        const form = this.els.form();
        if (!form) return;
        form.reset();
        this.els.mode().value = 'create';
        this.els.code().readOnly = false;
        form.value_type.value = this.meta.value_types?.[0] || 'numeric';
        form.equipment_scope.value = this.meta.equipment_scopes?.[0] || 'shared';
        form.status.value = 'active';
        this.selected = null;
        this.renderList();
    },
    fill(definition) {
        const form = this.els.form();
        this.els.mode().value = 'edit';
        this.els.code().readOnly = true;
        this.selected = definition.code;
        form.code.value = definition.code;
        form.name.value = definition.name || '';
        form.value_type.value = definition.value_type || 'numeric';
        form.equipment_scope.value = definition.equipment_scope || 'shared';
        form.unit.value = definition.unit || '';
        form.status.value = definition.status || 'active';
        form.min_value.value = definition.min_value ?? '';
        form.max_value.value = definition.max_value ?? '';
        form.market_filterable.checked = Boolean(definition.market_filterable);
        this.renderList();
    },
    async loadMeta() {
        const response = await apiFetch('/api/admin/properties/meta');
        this.meta = response.data || this.meta;
        fillSelect(this.els.valueTypeFilter(), (this.meta.value_types || []).map((v) => ({ code: v, name: v })), true, 'Todos value_type');
        fillSelect(this.els.scopeFilter(), (this.meta.equipment_scopes || []).map((v) => ({ code: v, name: v })), true, 'Todos scopes');
        fillSelect(this.els.valueTypeField(), (this.meta.value_types || []).map((v) => ({ code: v, name: v })));
        fillSelect(this.els.scopeField(), (this.meta.equipment_scopes || []).map((v) => ({ code: v, name: v })));
    },
    async loadList() {
        this.setStatus('Carregando propriedades...');
        const params = new URLSearchParams({ limit: '150' });
        if (this.els.q()?.value.trim()) params.set('q', this.els.q().value.trim());
        if (this.els.statusFilter()?.value) params.set('status', this.els.statusFilter().value);
        if (this.els.valueTypeFilter()?.value) params.set('value_type', this.els.valueTypeFilter().value);
        if (this.els.scopeFilter()?.value) params.set('equipment_scope', this.els.scopeFilter().value);
        const response = await apiFetch(`/api/admin/properties?${params}`);
        this.list = response.data?.definitions || [];
        this.renderList();
        this.setStatus(`${response.data?.total ?? this.list.length} propriedade(s)`);
    },
    async open(code) {
        const response = await apiFetch(`/api/admin/properties/${encodeURIComponent(code)}`);
        this.fill(response.data?.definition);
        this.setStatus(`Editando ${code}`);
    },
    async save(event) {
        event.preventDefault();
        const form = this.els.form();
        const payload = {
            code: form.code.value.trim(),
            name: form.name.value.trim(),
            value_type: form.value_type.value,
            equipment_scope: form.equipment_scope.value,
            unit: form.unit.value.trim() || null,
            status: form.status.value,
            min_value: form.min_value.value === '' ? null : Number(form.min_value.value),
            max_value: form.max_value.value === '' ? null : Number(form.max_value.value),
            market_filterable: Boolean(form.market_filterable.checked),
        };
        this.setStatus('Salvando propriedade...');
        if (this.els.mode().value === 'create') {
            await apiFetch('/api/admin/properties', { method: 'POST', body: payload });
        } else {
            await apiFetch(`/api/admin/properties/${encodeURIComponent(payload.code)}`, { method: 'POST', body: payload });
        }
        await this.loadList();
        await this.open(payload.code);
        await affixes.loadMeta();
    },
    bind() {
        root.querySelector('[data-props-refresh]')?.addEventListener('click', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        root.querySelector('[data-props-new]')?.addEventListener('click', () => { this.reset(); this.setStatus('Nova propriedade'); });
        this.els.form()?.addEventListener('submit', (event) => this.save(event).catch((e) => { this.setStatus(errorMessage(e)); alert(errorMessage(e)); }));
        this.els.list()?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-props-pick]');
            if (!button) return;
            this.open(button.getAttribute('data-props-pick')).catch((e) => this.setStatus(errorMessage(e)));
        });
        const reload = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.loadList().catch((e) => this.setStatus(errorMessage(e))), 250);
        };
        this.els.q()?.addEventListener('input', reload);
        this.els.statusFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        this.els.valueTypeFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        this.els.scopeFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
    },
};

/* -------------------- Affixes -------------------- */
const affixes = {
    meta: { affix_types: [], properties: [] },
    list: [],
    selected: null,
    timer: null,
    els: {
        list: () => root.querySelector('[data-affix-list]'),
        form: () => root.querySelector('[data-affix-form]'),
        status: () => root.querySelector('[data-affix-status-text]'),
        q: () => root.querySelector('[data-affix-q]'),
        statusFilter: () => root.querySelector('[data-affix-status]'),
        typeFilter: () => root.querySelector('[data-affix-type]'),
        propertyFilter: () => root.querySelector('[data-affix-property]'),
        typeField: () => root.querySelector('[data-affix-type-field]'),
        propertyField: () => root.querySelector('[data-affix-property-field]'),
        code: () => root.querySelector('[data-affix-code]'),
        mode: () => root.querySelector('[data-affix-mode]'),
    },
    setStatus(message) {
        const node = this.els.status();
        if (node) node.textContent = message;
    },
    renderList() {
        const listRoot = this.els.list();
        if (!listRoot) return;
        if (!this.list.length) {
            listRoot.innerHTML = '<p style="padding:1rem;color:#9aabbd">Nenhum afixo.</p>';
            return;
        }
        listRoot.innerHTML = this.list.map((entry) => `
            <button type="button" data-affix-pick="${escapeHtml(entry.code)}" class="${entry.code === this.selected ? 'is-active' : ''}">
                <strong>${escapeHtml(entry.name)}</strong>
                <small>${escapeHtml(entry.code)} · ${escapeHtml(entry.affix_type)} · ${escapeHtml(entry.property_code)}</small>
            </button>
        `).join('');
    },
    reset() {
        const form = this.els.form();
        if (!form) return;
        form.reset();
        this.els.mode().value = 'create';
        this.els.code().readOnly = false;
        form.affix_type.value = this.meta.affix_types?.[0] || 'prefix';
        form.status.value = 'active';
        form.min_value.value = 1;
        form.max_value.value = 5;
        form.rarity_weight.value = 10;
        form.min_item_level.value = 1;
        this.selected = null;
        this.renderList();
    },
    fill(definition) {
        const form = this.els.form();
        this.els.mode().value = 'edit';
        this.els.code().readOnly = true;
        this.selected = definition.code;
        form.code.value = definition.code;
        form.name.value = definition.name || '';
        form.affix_type.value = definition.affix_type || 'prefix';
        form.property_code.value = definition.property_code || '';
        form.status.value = definition.status || 'active';
        form.min_value.value = definition.min_value ?? 0;
        form.max_value.value = definition.max_value ?? 0;
        form.rarity_weight.value = definition.rarity_weight ?? 1;
        form.min_item_level.value = definition.min_item_level ?? 1;
        this.renderList();
    },
    async loadMeta() {
        const response = await apiFetch('/api/admin/affixes/meta');
        this.meta = response.data || this.meta;
        fillSelect(this.els.typeFilter(), (this.meta.affix_types || []).map((v) => ({ code: v, name: v })), true, 'Todos os tipos');
        fillSelect(this.els.propertyFilter(), this.meta.properties || [], true, 'Todas propriedades');
        fillSelect(this.els.typeField(), (this.meta.affix_types || []).map((v) => ({ code: v, name: v })));
        fillSelect(this.els.propertyField(), this.meta.properties || []);
    },
    async loadList() {
        this.setStatus('Carregando afixos...');
        const params = new URLSearchParams({ limit: '150' });
        if (this.els.q()?.value.trim()) params.set('q', this.els.q().value.trim());
        if (this.els.statusFilter()?.value) params.set('status', this.els.statusFilter().value);
        if (this.els.typeFilter()?.value) params.set('affix_type', this.els.typeFilter().value);
        if (this.els.propertyFilter()?.value) params.set('property_code', this.els.propertyFilter().value);
        const response = await apiFetch(`/api/admin/affixes?${params}`);
        this.list = response.data?.definitions || [];
        this.renderList();
        this.setStatus(`${response.data?.total ?? this.list.length} afixo(s)`);
    },
    async open(code) {
        const response = await apiFetch(`/api/admin/affixes/${encodeURIComponent(code)}`);
        this.fill(response.data?.definition);
        this.setStatus(`Editando ${code}`);
    },
    async save(event) {
        event.preventDefault();
        const form = this.els.form();
        const payload = {
            code: form.code.value.trim(),
            name: form.name.value.trim(),
            affix_type: form.affix_type.value,
            property_code: form.property_code.value,
            status: form.status.value,
            min_value: Number(form.min_value.value || 0),
            max_value: Number(form.max_value.value || 0),
            rarity_weight: Number(form.rarity_weight.value || 1),
            min_item_level: Number(form.min_item_level.value || 1),
        };
        this.setStatus('Salvando afixo...');
        if (this.els.mode().value === 'create') {
            await apiFetch('/api/admin/affixes', { method: 'POST', body: payload });
        } else {
            await apiFetch(`/api/admin/affixes/${encodeURIComponent(payload.code)}`, { method: 'POST', body: payload });
        }
        await this.loadList();
        await this.open(payload.code);
    },
    bind() {
        root.querySelector('[data-affix-refresh]')?.addEventListener('click', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        root.querySelector('[data-affix-new]')?.addEventListener('click', () => { this.reset(); this.setStatus('Novo afixo'); });
        this.els.form()?.addEventListener('submit', (event) => this.save(event).catch((e) => { this.setStatus(errorMessage(e)); alert(errorMessage(e)); }));
        this.els.list()?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-affix-pick]');
            if (!button) return;
            this.open(button.getAttribute('data-affix-pick')).catch((e) => this.setStatus(errorMessage(e)));
        });
        const reload = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.loadList().catch((e) => this.setStatus(errorMessage(e))), 250);
        };
        this.els.q()?.addEventListener('input', reload);
        this.els.statusFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        this.els.typeFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        this.els.propertyFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
    },
};

function parseJsonField(raw, label, { allowNull = false, expectArray = false } = {}) {
    const text = String(raw ?? '').trim();
    if (text === '' || text === 'null') {
        if (allowNull) return null;
        return expectArray ? [] : {};
    }
    let value;
    try {
        value = JSON.parse(text);
    } catch (_error) {
        throw new Error(`${label} precisa ser JSON valido.`);
    }
    if (expectArray && !Array.isArray(value)) {
        throw new Error(`${label} deve ser um array JSON.`);
    }
    return value;
}

function stringifyJson(value, fallback = 'null') {
    if (value === undefined || value === null) return fallback;
    return JSON.stringify(value, null, 2);
}

/* -------------------- Biomes -------------------- */
const biomes = {
    meta: { statuses: [], combat_modes: [], monsters: [] },
    list: [],
    selected: null,
    timer: null,
    els: {
        list: () => root.querySelector('[data-biomes-list]'),
        form: () => root.querySelector('[data-biomes-form]'),
        status: () => root.querySelector('[data-biomes-status-text]'),
        q: () => root.querySelector('[data-biomes-q]'),
        statusFilter: () => root.querySelector('[data-biomes-status]'),
        combatModeField: () => root.querySelector('[data-biomes-combat-mode]'),
        code: () => root.querySelector('[data-biomes-code]'),
        mode: () => root.querySelector('[data-biomes-mode]'),
        unlock: () => root.querySelector('[data-biomes-unlock]'),
        entry: () => root.querySelector('[data-biomes-entry]'),
        landmarks: () => root.querySelector('[data-biomes-landmarks]'),
        monsters: () => root.querySelector('[data-biomes-monsters]'),
        settings: () => root.querySelector('[data-biomes-settings]'),
    },
    setStatus(message) {
        const node = this.els.status();
        if (node) node.textContent = message;
    },
    renderList() {
        const listRoot = this.els.list();
        if (!listRoot) return;
        if (!this.list.length) {
            listRoot.innerHTML = '<p style="padding:1rem;color:#9aabbd">Nenhum bioma.</p>';
            return;
        }
        listRoot.innerHTML = this.list.map((entry) => `
            <button type="button" data-biomes-pick="${escapeHtml(entry.code)}" class="${entry.code === this.selected ? 'is-active' : ''}">
                <strong>${escapeHtml(entry.name)}</strong>
                <small>${escapeHtml(entry.code)} · ${escapeHtml(entry.status)} · sort ${escapeHtml(entry.sort_order)}</small>
            </button>
        `).join('');
    },
    reset() {
        const form = this.els.form();
        if (!form) return;
        form.reset();
        this.els.mode().value = 'create';
        this.els.code().readOnly = false;
        form.status.value = 'locked';
        form.sort_order.value = 10;
        form.requires_expedition.checked = true;
        form.season_featured.checked = false;
        form.default_duration_minutes.value = 30;
        form.default_respawn_minutes.value = 15;
        form.discovery_radius.value = 1.5;
        form.map_width.value = 6;
        form.map_height.value = 4;
        form.spawn_x.value = 1;
        form.spawn_y.value = 2;
        form.map_node_x.value = 0;
        form.map_node_y.value = 0;
        form.monster_spawn_count.value = 6;
        form.monster_elite_chance.value = 0.18;
        form.monster_rare_chance.value = 0.04;
        form.move_trap_chance.value = 0.05;
        form.move_trap_damage_min.value = 6;
        form.move_trap_damage_max.value = 12;
        form.engage_radius.value = 2;
        form.kills_to_boss.value = 10;
        form.heal_on_kill_pct.value = 0.03;
        form.combat_mode.value = this.meta.combat_modes?.[0] || 'open';
        form.wave_size.value = '';
        form.wave_pause_kills.value = '';
        this.els.unlock().value = 'null';
        this.els.entry().value = 'null';
        this.els.landmarks().value = '[]';
        this.els.monsters().value = '[]';
        this.els.settings().value = 'null';
        this.selected = null;
        this.renderList();
    },
    fill(biome) {
        const form = this.els.form();
        this.els.mode().value = 'edit';
        this.els.code().readOnly = true;
        this.selected = biome.code;
        form.code.value = biome.code;
        form.name.value = biome.name || '';
        form.status.value = biome.status || 'locked';
        form.sort_order.value = biome.sort_order ?? 0;
        form.summary.value = biome.summary || '';
        form.requires_expedition.checked = biome.requires_expedition !== false;
        form.season_featured.checked = Boolean(biome.season_featured);
        form.default_duration_minutes.value = biome.default_duration_minutes ?? 30;
        form.default_respawn_minutes.value = biome.default_respawn_minutes ?? 15;
        form.discovery_radius.value = biome.discovery_radius ?? 1.5;
        form.map_width.value = biome.map_width ?? 6;
        form.map_height.value = biome.map_height ?? 4;
        form.spawn_x.value = biome.spawn_x ?? 1;
        form.spawn_y.value = biome.spawn_y ?? 2;
        form.map_node_x.value = biome.map_node_x ?? 0;
        form.map_node_y.value = biome.map_node_y ?? 0;
        form.background_url.value = biome.background_url || '';
        form.world_art_url.value = biome.world_art_url || '';
        form.world_pin_url.value = biome.world_pin_url || '';
        form.world_structure_url.value = biome.world_structure_url || '';
        form.monster_spawn_count.value = biome.monster_spawn_count ?? 6;
        form.monster_elite_chance.value = biome.monster_elite_chance ?? 0.18;
        form.monster_rare_chance.value = biome.monster_rare_chance ?? 0.04;
        form.move_trap_chance.value = biome.move_trap_chance ?? 0.05;
        form.move_trap_damage_min.value = biome.move_trap_damage_min ?? 6;
        form.move_trap_damage_max.value = biome.move_trap_damage_max ?? 12;
        form.engage_radius.value = biome.engage_radius ?? 2;
        form.kills_to_boss.value = biome.kills_to_boss ?? 10;
        form.heal_on_kill_pct.value = biome.heal_on_kill_pct ?? 0.03;
        form.combat_mode.value = biome.combat_mode || 'open';
        form.wave_size.value = biome.wave_size ?? '';
        form.wave_pause_kills.value = biome.wave_pause_kills ?? '';
        this.els.unlock().value = stringifyJson(biome.unlock);
        this.els.entry().value = stringifyJson(biome.entry_requirements);
        this.els.landmarks().value = stringifyJson(biome.landmarks ?? [], '[]');
        this.els.monsters().value = stringifyJson(biome.monsters ?? [], '[]');
        this.els.settings().value = stringifyJson(biome.settings);
        this.renderList();
    },
    async loadMeta() {
        const response = await apiFetch('/api/admin/biomes/meta');
        this.meta = response.data || this.meta;
        fillSelect(this.els.combatModeField(), (this.meta.combat_modes || []).map((v) => ({ code: v, name: v })));
    },
    async loadList() {
        this.setStatus('Carregando biomas...');
        const params = new URLSearchParams({ limit: '150' });
        if (this.els.q()?.value.trim()) params.set('q', this.els.q().value.trim());
        if (this.els.statusFilter()?.value) params.set('status', this.els.statusFilter().value);
        const response = await apiFetch(`/api/admin/biomes?${params}`);
        this.list = response.data?.biomes || [];
        this.renderList();
        this.setStatus(`${response.data?.total ?? this.list.length} bioma(s)`);
    },
    async open(code) {
        const response = await apiFetch(`/api/admin/biomes/${encodeURIComponent(code)}`);
        this.fill(response.data?.biome);
        this.setStatus(`Editando ${code}`);
    },
    async save(event) {
        event.preventDefault();
        const form = this.els.form();
        const unlock = parseJsonField(this.els.unlock().value, 'unlock', { allowNull: true });
        const entryRequirements = parseJsonField(this.els.entry().value, 'entry_requirements', { allowNull: true });
        const landmarks = parseJsonField(this.els.landmarks().value, 'landmarks', { expectArray: true });
        const monstersList = parseJsonField(this.els.monsters().value, 'monsters', { expectArray: true });
        const settings = parseJsonField(this.els.settings().value, 'settings', { allowNull: true });

        const payload = {
            code: form.code.value.trim(),
            name: form.name.value.trim(),
            summary: form.summary.value.trim() || null,
            status: form.status.value,
            sort_order: Number(form.sort_order.value || 0),
            requires_expedition: Boolean(form.requires_expedition.checked),
            season_featured: Boolean(form.season_featured.checked),
            default_duration_minutes: Number(form.default_duration_minutes.value || 30),
            default_respawn_minutes: Number(form.default_respawn_minutes.value || 0),
            discovery_radius: Number(form.discovery_radius.value || 1.5),
            map_width: Number(form.map_width.value || 6),
            map_height: Number(form.map_height.value || 4),
            spawn_x: Number(form.spawn_x.value || 0),
            spawn_y: Number(form.spawn_y.value || 0),
            map_node_x: Number(form.map_node_x.value || 0),
            map_node_y: Number(form.map_node_y.value || 0),
            background_url: form.background_url.value.trim() || null,
            world_art_url: form.world_art_url.value.trim() || null,
            world_pin_url: form.world_pin_url.value.trim() || null,
            world_structure_url: form.world_structure_url.value.trim() || null,
            monster_spawn_count: Number(form.monster_spawn_count.value || 0),
            monster_elite_chance: Number(form.monster_elite_chance.value || 0),
            monster_rare_chance: Number(form.monster_rare_chance.value || 0),
            move_trap_chance: Number(form.move_trap_chance.value || 0),
            move_trap_damage_min: Number(form.move_trap_damage_min.value || 0),
            move_trap_damage_max: Number(form.move_trap_damage_max.value || 0),
            engage_radius: Number(form.engage_radius.value || 2),
            kills_to_boss: Number(form.kills_to_boss.value || 10),
            heal_on_kill_pct: Number(form.heal_on_kill_pct.value || 0),
            combat_mode: form.combat_mode.value || 'open',
            wave_size: form.wave_size.value === '' ? null : Number(form.wave_size.value),
            wave_pause_kills: form.wave_pause_kills.value === '' ? null : Number(form.wave_pause_kills.value),
            unlock,
            entry_requirements: entryRequirements,
            landmarks,
            monsters: monstersList,
            settings,
        };
        this.setStatus('Salvando bioma...');
        if (this.els.mode().value === 'create') {
            await apiFetch('/api/admin/biomes', { method: 'POST', body: payload });
        } else {
            await apiFetch(`/api/admin/biomes/${encodeURIComponent(payload.code)}`, { method: 'POST', body: payload });
        }
        await this.loadList();
        await this.open(payload.code);
    },
    bind() {
        root.querySelector('[data-biomes-refresh]')?.addEventListener('click', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        root.querySelector('[data-biomes-new]')?.addEventListener('click', () => { this.reset(); this.setStatus('Novo bioma'); });
        this.els.form()?.addEventListener('submit', (event) => this.save(event).catch((e) => { this.setStatus(errorMessage(e)); alert(errorMessage(e)); }));
        this.els.list()?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-biomes-pick]');
            if (!button) return;
            this.open(button.getAttribute('data-biomes-pick')).catch((e) => this.setStatus(errorMessage(e)));
        });
        const reload = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.loadList().catch((e) => this.setStatus(errorMessage(e))), 250);
        };
        this.els.q()?.addEventListener('input', reload);
        this.els.statusFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
    },
};

/* -------------------- Monsters -------------------- */
const monsters = {
    meta: { statuses: [], sprite_keys: [] },
    list: [],
    selected: null,
    timer: null,
    els: {
        list: () => root.querySelector('[data-monsters-list]'),
        form: () => root.querySelector('[data-monsters-form]'),
        status: () => root.querySelector('[data-monsters-status-text]'),
        q: () => root.querySelector('[data-monsters-q]'),
        statusFilter: () => root.querySelector('[data-monsters-status]'),
        spriteField: () => root.querySelector('[data-monsters-sprite-field]'),
        code: () => root.querySelector('[data-monsters-code]'),
        mode: () => root.querySelector('[data-monsters-mode]'),
        loot: () => root.querySelector('[data-monsters-loot]'),
    },
    setStatus(message) {
        const node = this.els.status();
        if (node) node.textContent = message;
    },
    renderList() {
        const listRoot = this.els.list();
        if (!listRoot) return;
        if (!this.list.length) {
            listRoot.innerHTML = '<p style="padding:1rem;color:#9aabbd">Nenhum monstro.</p>';
            return;
        }
        listRoot.innerHTML = this.list.map((entry) => `
            <button type="button" data-monsters-pick="${escapeHtml(entry.code)}" class="${entry.code === this.selected ? 'is-active' : ''}">
                <strong>${escapeHtml(entry.name)}</strong>
                <small>${escapeHtml(entry.code)} · ${escapeHtml(entry.sprite_key)} · ${escapeHtml(entry.status)}</small>
            </button>
        `).join('');
    },
    reset() {
        const form = this.els.form();
        if (!form) return;
        form.reset();
        this.els.mode().value = 'create';
        this.els.code().readOnly = false;
        form.sprite_key.value = this.meta.sprite_keys?.[0] || 'mob';
        form.element.value = 'neutral';
        form.resistance.value = 'neutral';
        form.status.value = 'active';
        form.base_hp.value = 100;
        form.base_attack.value = 10;
        form.base_defense.value = 5;
        form.dodge_rate.value = 0.1;
        form.attack_rate.value = 0.5;
        form.crit_rate.value = 0.08;
        form.reward_gold_min.value = 3;
        form.reward_gold_max.value = 6;
        form.reward_xp_min.value = 10;
        form.reward_xp_max.value = 16;
        this.els.loot().value = '[]';
        this.selected = null;
        this.renderList();
    },
    fill(definition) {
        const form = this.els.form();
        this.els.mode().value = 'edit';
        this.els.code().readOnly = true;
        this.selected = definition.code;
        form.code.value = definition.code;
        form.name.value = definition.name || '';
        form.sprite_key.value = definition.sprite_key || 'mob';
        form.element.value = definition.element || 'neutral';
        form.resistance.value = definition.resistance || 'neutral';
        form.status.value = definition.status || 'active';
        form.base_hp.value = definition.base_hp ?? 100;
        form.base_attack.value = definition.base_attack ?? 10;
        form.base_defense.value = definition.base_defense ?? 5;
        form.dodge_rate.value = definition.dodge_rate ?? 0.1;
        form.attack_rate.value = definition.attack_rate ?? 0.5;
        form.crit_rate.value = definition.crit_rate ?? 0.08;
        form.reward_gold_min.value = definition.reward_gold_min ?? 3;
        form.reward_gold_max.value = definition.reward_gold_max ?? 6;
        form.reward_xp_min.value = definition.reward_xp_min ?? 10;
        form.reward_xp_max.value = definition.reward_xp_max ?? 16;
        this.els.loot().value = stringifyJson(definition.loot ?? [], '[]');
        this.renderList();
    },
    async loadMeta() {
        const response = await apiFetch('/api/admin/monsters/meta');
        this.meta = response.data || this.meta;
        fillSelect(this.els.spriteField(), (this.meta.sprite_keys || []).map((v) => ({ code: v, name: v })));
    },
    async loadList() {
        this.setStatus('Carregando monstros...');
        const params = new URLSearchParams({ limit: '150' });
        if (this.els.q()?.value.trim()) params.set('q', this.els.q().value.trim());
        if (this.els.statusFilter()?.value) params.set('status', this.els.statusFilter().value);
        const response = await apiFetch(`/api/admin/monsters?${params}`);
        this.list = response.data?.definitions || [];
        this.renderList();
        this.setStatus(`${response.data?.total ?? this.list.length} monstro(s)`);
    },
    async open(code) {
        const response = await apiFetch(`/api/admin/monsters/${encodeURIComponent(code)}`);
        this.fill(response.data?.definition);
        this.setStatus(`Editando ${code}`);
    },
    async save(event) {
        event.preventDefault();
        const form = this.els.form();
        const loot = parseJsonField(this.els.loot().value, 'loot', { expectArray: true });
        const payload = {
            code: form.code.value.trim(),
            name: form.name.value.trim(),
            sprite_key: form.sprite_key.value || 'mob',
            element: form.element.value.trim() || 'neutral',
            resistance: form.resistance.value.trim() || 'neutral',
            status: form.status.value,
            base_hp: Number(form.base_hp.value || 100),
            base_attack: Number(form.base_attack.value || 0),
            base_defense: Number(form.base_defense.value || 0),
            dodge_rate: Number(form.dodge_rate.value || 0),
            attack_rate: Number(form.attack_rate.value || 0),
            crit_rate: Number(form.crit_rate.value || 0),
            reward_gold_min: Number(form.reward_gold_min.value || 0),
            reward_gold_max: Number(form.reward_gold_max.value || 0),
            reward_xp_min: Number(form.reward_xp_min.value || 0),
            reward_xp_max: Number(form.reward_xp_max.value || 0),
            loot,
        };
        this.setStatus('Salvando monstro...');
        if (this.els.mode().value === 'create') {
            await apiFetch('/api/admin/monsters', { method: 'POST', body: payload });
        } else {
            await apiFetch(`/api/admin/monsters/${encodeURIComponent(payload.code)}`, { method: 'POST', body: payload });
        }
        await this.loadList();
        await this.open(payload.code);
        await biomes.loadMeta();
    },
    bind() {
        root.querySelector('[data-monsters-refresh]')?.addEventListener('click', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        root.querySelector('[data-monsters-new]')?.addEventListener('click', () => { this.reset(); this.setStatus('Novo monstro'); });
        this.els.form()?.addEventListener('submit', (event) => this.save(event).catch((e) => { this.setStatus(errorMessage(e)); alert(errorMessage(e)); }));
        this.els.list()?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-monsters-pick]');
            if (!button) return;
            this.open(button.getAttribute('data-monsters-pick')).catch((e) => this.setStatus(errorMessage(e)));
        });
        const reload = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.loadList().catch((e) => this.setStatus(errorMessage(e))), 250);
        };
        this.els.q()?.addEventListener('input', reload);
        this.els.statusFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
    },
};

/* -------------------- Recipes -------------------- */
const recipes = {
    meta: { statuses: [], discoveries: [], workspaces: [] },
    list: [],
    selected: null,
    timer: null,
    els: {
        list: () => root.querySelector('[data-recipes-list]'),
        form: () => root.querySelector('[data-recipes-form]'),
        status: () => root.querySelector('[data-recipes-status-text]'),
        q: () => root.querySelector('[data-recipes-q]'),
        statusFilter: () => root.querySelector('[data-recipes-status]'),
        workspaceFilter: () => root.querySelector('[data-recipes-workspace]'),
        workspaceField: () => root.querySelector('[data-recipes-workspace-field]'),
        code: () => root.querySelector('[data-recipes-code]'),
        mode: () => root.querySelector('[data-recipes-mode]'),
        requirements: () => root.querySelector('[data-recipes-requirements]'),
        outputs: () => root.querySelector('[data-recipes-outputs]'),
    },
    setStatus(message) {
        const node = this.els.status();
        if (node) node.textContent = message;
    },
    renderList() {
        const listRoot = this.els.list();
        if (!listRoot) return;
        if (!this.list.length) {
            listRoot.innerHTML = '<p style="padding:1rem;color:#9aabbd">Nenhuma receita.</p>';
            return;
        }
        listRoot.innerHTML = this.list.map((entry) => `
            <button type="button" data-recipes-pick="${escapeHtml(entry.code)}" class="${entry.code === this.selected ? 'is-active' : ''}">
                <strong>${escapeHtml(entry.name)}</strong>
                <small>${escapeHtml(entry.code)} · ${escapeHtml(entry.workspace)} · ${escapeHtml(entry.status)}</small>
            </button>
        `).join('');
    },
    reset() {
        const form = this.els.form();
        if (!form) return;
        form.reset();
        this.els.mode().value = 'create';
        this.els.code().readOnly = false;
        form.workspace.value = this.meta.workspaces?.[0] || 'forge';
        form.discovery.value = 'public';
        form.status.value = 'active';
        form.sort_order.value = 10;
        form.gold_fee.value = 0;
        this.els.requirements().value = '[]';
        this.els.outputs().value = '[]';
        this.selected = null;
        this.renderList();
    },
    fill(recipe) {
        const form = this.els.form();
        this.els.mode().value = 'edit';
        this.els.code().readOnly = true;
        this.selected = recipe.code;
        form.code.value = recipe.code;
        form.name.value = recipe.name || '';
        form.workspace.value = recipe.workspace || this.meta.workspaces?.[0] || 'forge';
        form.discovery.value = recipe.discovery || 'public';
        form.status.value = recipe.status || 'active';
        form.sort_order.value = recipe.sort_order ?? 0;
        form.gold_fee.value = recipe.gold_fee ?? 0;
        form.description.value = recipe.description || '';
        this.els.requirements().value = stringifyJson(recipe.requirements ?? [], '[]');
        this.els.outputs().value = stringifyJson(recipe.outputs ?? [], '[]');
        this.renderList();
    },
    async loadMeta() {
        const response = await apiFetch('/api/admin/craft-recipes/meta');
        this.meta = response.data || this.meta;
        fillSelect(this.els.workspaceFilter(), (this.meta.workspaces || []).map((v) => ({ code: v, name: v })), true, 'Todos os workspaces');
        fillSelect(this.els.workspaceField(), (this.meta.workspaces || []).map((v) => ({ code: v, name: v })));
    },
    async loadList() {
        this.setStatus('Carregando receitas...');
        const params = new URLSearchParams({ limit: '150' });
        if (this.els.q()?.value.trim()) params.set('q', this.els.q().value.trim());
        if (this.els.statusFilter()?.value) params.set('status', this.els.statusFilter().value);
        if (this.els.workspaceFilter()?.value) params.set('workspace', this.els.workspaceFilter().value);
        const response = await apiFetch(`/api/admin/craft-recipes?${params}`);
        this.list = response.data?.recipes || [];
        this.renderList();
        this.setStatus(`${response.data?.total ?? this.list.length} receita(s)`);
    },
    async open(code) {
        const response = await apiFetch(`/api/admin/craft-recipes/${encodeURIComponent(code)}`);
        this.fill(response.data?.recipe);
        this.setStatus(`Editando ${code}`);
    },
    async save(event) {
        event.preventDefault();
        const form = this.els.form();
        const requirements = parseJsonField(this.els.requirements().value, 'requirements', { expectArray: true });
        const outputs = parseJsonField(this.els.outputs().value, 'outputs', { expectArray: true });
        const payload = {
            code: form.code.value.trim(),
            name: form.name.value.trim(),
            workspace: form.workspace.value,
            discovery: form.discovery.value || 'public',
            status: form.status.value,
            sort_order: Number(form.sort_order.value || 0),
            gold_fee: Number(form.gold_fee.value || 0),
            description: form.description.value.trim() || null,
            requirements,
            outputs,
        };
        this.setStatus('Salvando receita...');
        if (this.els.mode().value === 'create') {
            await apiFetch('/api/admin/craft-recipes', { method: 'POST', body: payload });
        } else {
            await apiFetch(`/api/admin/craft-recipes/${encodeURIComponent(payload.code)}`, { method: 'POST', body: payload });
        }
        await this.loadList();
        await this.open(payload.code);
    },
    bind() {
        root.querySelector('[data-recipes-refresh]')?.addEventListener('click', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        root.querySelector('[data-recipes-new]')?.addEventListener('click', () => { this.reset(); this.setStatus('Nova receita'); });
        this.els.form()?.addEventListener('submit', (event) => this.save(event).catch((e) => { this.setStatus(errorMessage(e)); alert(errorMessage(e)); }));
        this.els.list()?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-recipes-pick]');
            if (!button) return;
            this.open(button.getAttribute('data-recipes-pick')).catch((e) => this.setStatus(errorMessage(e)));
        });
        const reload = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.loadList().catch((e) => this.setStatus(errorMessage(e))), 250);
        };
        this.els.q()?.addEventListener('input', reload);
        this.els.statusFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
        this.els.workspaceFilter()?.addEventListener('change', () => this.loadList().catch((e) => this.setStatus(errorMessage(e))));
    },
};

async function boot() {
    if (!root) return;

    root.querySelectorAll('[data-admin-tab]').forEach((button) => {
        button.addEventListener('click', () => switchTab(button.getAttribute('data-admin-tab')));
    });

    items.bind();
    investigables.bind();
    properties.bind();
    affixes.bind();
    biomes.bind();
    monsters.bind();
    recipes.bind();

    try {
        await Promise.all([
            items.loadMeta(),
            investigables.loadMeta(),
            properties.loadMeta(),
            affixes.loadMeta(),
            biomes.loadMeta(),
            monsters.loadMeta(),
            recipes.loadMeta(),
        ]);
        await Promise.all([
            items.loadList(),
            investigables.loadList(),
            properties.loadList(),
            affixes.loadList(),
            biomes.loadList(),
            monsters.loadList(),
            recipes.loadList(),
        ]);
        items.reset();
        investigables.reset();
        properties.reset();
        affixes.reset();
        biomes.reset();
        monsters.reset();
        recipes.reset();
        switchTab('items');
    } catch (error) {
        const message = errorMessage(error);
        items.setStatus(message);
        investigables.setStatus(message);
        properties.setStatus(message);
        affixes.setStatus(message);
        biomes.setStatus(message);
        monsters.setStatus(message);
        recipes.setStatus(message);
    }
}

boot();
