/**
 * Formatação do breakdown de preço do mercado (extraído do main.js).
 */

export function formatMarketBreakdownLines(breakdown = {}) {
    const rows = [
        ['Base', breakdown.base_price, false],
        ['Afixos', breakdown.affix_score, true],
        ['Gemas', breakdown.gem_score, true],
        ['Upgrade', breakdown.upgrade_bonus, true],
        ['Oferta', breakdown.supply_factor, true],
        ['Demanda', breakdown.demand_factor, true],
        ['Taxa NPC', breakdown.npc_rate, true],
    ];

    return rows
        .filter(([, value]) => value !== undefined && value !== null && Number(value) > 0)
        .map(([label, value, isMultiplier]) => {
            const num = Number(value);
            const display = isMultiplier
                ? `×${num.toLocaleString('pt-BR', { maximumFractionDigits: 3 })}`
                : `${Math.round(num).toLocaleString('pt-BR')} G`;
            return { label, display };
        });
}

export function renderMarketBreakdownHtml(breakdown, escapeHtml) {
    const lines = formatMarketBreakdownLines(breakdown || {});
    if (!lines.length) {
        return '';
    }

    const esc = typeof escapeHtml === 'function' ? escapeHtml : (v) => String(v ?? '');
    return `
        <ul class="inventory-market-breakdown">
            ${lines.map((line) => `
                <li><span>${esc(line.label)}</span><strong>${esc(line.display)}</strong></li>
            `).join('')}
        </ul>
    `;
}
