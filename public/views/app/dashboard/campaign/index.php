<section class="campaign-page" data-campaign-page data-world-code="mundo_1_bosque">
    <header class="campaign-chrome">
        <a class="campaign-back" href="/dashboard" data-campaign-back>Voltar</a>
        <div class="campaign-chrome-title">
            <strong data-campaign-world-name>Mundo 1</strong>
            <span data-campaign-world-hint>Clique em um pin</span>
        </div>
        <button type="button" class="campaign-chrome-album" data-campaign-album-open hidden>
            Album <em data-campaign-album-count>0/0</em>
        </button>
        <button type="button" class="campaign-chrome-audio" data-campaign-audio-toggle aria-pressed="false" title="Liga/desliga som do mapa">
            Som on
        </button>
        <button type="button" class="campaign-chrome-fx" data-campaign-fx-open title="Painel de efeitos (showcase)">
            FX Lab
        </button>
    </header>

    <div class="campaign-stage" data-campaign-stage>
        <p class="campaign-loading">Carregando mapa...</p>
    </div>

    <aside class="campaign-fx-lab" data-campaign-fx-lab hidden aria-label="Laboratorio de efeitos">
        <header>
            <strong>FX Lab</strong>
            <button type="button" class="campaign-lobby-close" data-campaign-fx-close>Fechar</button>
        </header>
        <p class="campaign-fx-lab-hint">Atmosfera 3D ligada por padrao. Trilha ARPG (passos/glow). Essencia nos itens. SFX do acervo Map/Inventory.</p>
        <div class="campaign-fx-lab-grid" data-campaign-fx-actions></div>
    </aside>

    <aside class="campaign-lobby" data-campaign-lobby hidden aria-label="Detalhe do pin"></aside>
    <div class="campaign-pin-tooltip" data-campaign-pin-tooltip hidden aria-hidden="true"></div>
    <section class="campaign-album" data-campaign-album hidden aria-label="Album de artefatos"></section>
    <section class="campaign-dossier" data-campaign-dossier hidden aria-label="Dossier da fase"></section>

    <section class="campaign-battle" data-campaign-battle hidden aria-label="Batalha idle"></section>
    <section class="campaign-loot" data-campaign-loot hidden aria-label="Triagem de loot"></section>
    <section class="campaign-score" data-campaign-score hidden aria-label="Placar da fase"></section>
</section>
