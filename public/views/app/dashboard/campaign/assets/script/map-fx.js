/**
 * Camada cinematografica do mapa de campanha.
 * GSAP + tsParticles + Howler (globals do vendor).
 */

const AUDIO = {
    ambient: '/assets/game/audio/bosque-ambient.ogg',
    select: [
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Map_Marker/WAV_Map_Marker_01.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Map_Marker/WAV_Map_Marker_02.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Map_Marker/WAV_Map_Marker_03.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Map_Marker/WAV_Map_Marker_04.WAV',
    ],
    hover: [
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Mouseover_Locations_On_The_Map/WAV_Mouseover_Locations_On_The_Map_01.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Mouseover_Locations_On_The_Map/WAV_Mouseover_Locations_On_The_Map_02.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Mouseover_Locations_On_The_Map/WAV_Mouseover_Locations_On_The_Map_03.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Mouseover_Locations_On_The_Map/WAV_Mouseover_Locations_On_The_Map_04.WAV',
    ],
    open: [
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Open_Or_Close_Map_Menu/WAV_Open_Or_Close_Map_Menu_01.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Open_Or_Close_Map_Menu/WAV_Open_Or_Close_Map_Menu_02.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Open_Or_Close_Map_Menu/WAV_Open_Or_Close_Map_Menu_03.WAV',
    ],
    whoosh: [
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Fast_Travel_On_The_Map/WAV_Fast_Travel_On_The_Map_01.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Fast_Travel_On_The_Map/WAV_Fast_Travel_On_The_Map_02.WAV',
        '/assets/game/sounds/RPG_Interface_Map_Principal_Menu/WAV/Map_Menu/Fast_Travel_On_The_Map/WAV_Fast_Travel_On_The_Map_03.WAV',
    ],
    rarity: [
        '/assets/game/sounds/RPG_Interface_Essentials_Inventory_Dialog/WAV/Inventory/Identify_Object/WAV_Identify_Object_01.WAV',
        '/assets/game/sounds/RPG_Interface_Essentials_Inventory_Dialog/WAV/Inventory/Identify_Object/WAV_Identify_Object_02.WAV',
        '/assets/game/sounds/RPG_Interface_Essentials_Inventory_Dialog/WAV/Inventory/Identify_Object/WAV_Identify_Object_03.WAV',
    ],
};

const WEATHER_CYCLE_MS = 40_000;
const WEATHER_ORDER = ['clear', 'fog', 'rain', 'storm', 'night', 'fireflies', 'clear'];

function gsapLib() {
    return window.gsap || null;
}

function HowlCtor() {
    return window.Howl || null;
}

function tsParticlesApi() {
    return window.tsParticles
        || window.__tsParticlesInternals?.engine?.tsParticles
        || window.__tsParticlesInternals?.bundles?.slim?.tsParticles
        || null;
}

function confettiApi() {
    return window.confetti
        || window.__tsParticlesInternals?.bundles?.confetti?.confetti
        || null;
}

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
}

function wavDataUri(samples, sampleRate = 22050) {
    const n = samples.length;
    const buffer = new ArrayBuffer(44 + n * 2);
    const view = new DataView(buffer);
    const writeStr = (offset, str) => {
        for (let i = 0; i < str.length; i += 1) view.setUint8(offset + i, str.charCodeAt(i));
    };
    writeStr(0, 'RIFF');
    view.setUint32(4, 36 + n * 2, true);
    writeStr(8, 'WAVE');
    writeStr(12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * 2, true);
    view.setUint16(32, 2, true);
    view.setUint16(34, 16, true);
    writeStr(36, 'data');
    view.setUint32(40, n * 2, true);
    for (let i = 0; i < n; i += 1) {
        const s = Math.max(-1, Math.min(1, samples[i]));
        view.setInt16(44 + i * 2, s < 0 ? s * 0x8000 : s * 0x7fff, true);
    }
    const bytes = new Uint8Array(buffer);
    let bin = '';
    for (let i = 0; i < bytes.length; i += 1) bin += String.fromCharCode(bytes[i]);
    return `data:audio/wav;base64,${btoa(bin)}`;
}

function toneSamples({ freq = 440, duration = 0.12, type = 'sine', volume = 0.22, sampleRate = 22050 } = {}) {
    const len = Math.floor(sampleRate * duration);
    const out = new Float32Array(len);
    for (let i = 0; i < len; i += 1) {
        const t = i / sampleRate;
        const env = Math.min(1, i / (sampleRate * 0.01)) * Math.max(0, 1 - i / len);
        let wave = Math.sin(2 * Math.PI * freq * t);
        if (type === 'tri') wave = 2 * Math.abs(2 * ((t * freq) % 1) - 1) - 1;
        if (type === 'noise') wave = Math.random() * 2 - 1;
        out[i] = wave * volume * env;
    }
    return out;
}

export function createCampaignMapFx({ page, onToggleMap3D, isMap3DEnabled } = {}) {
    const reduced = prefersReducedMotion();
    let muted = localStorage.getItem('campaign_map_muted') === '1';
    let unlocked = false;
    let particleInstance = null;
    let weatherInstance = null;
    let sparkEl = null;
    let sparkTween = null;
    let ambient = null;
    let sfx = {};
    let mapSig = '';
    let weather = 'clear';
    let weatherTimer = null;
    let weatherRoot = null;
    let confettiCanvas = null;

    function makePoolHowl(urls, volume = 0.5) {
        const H = HowlCtor();
        if (!H) return null;
        return urls.map((src) => new H({
            src: [src],
            volume,
            html5: true,
            preload: true,
        }));
    }

    function playPool(pool, vol = 1) {
        if (!pool?.length) return;
        const sound = pool[Math.floor(Math.random() * pool.length)];
        try {
            sound.volume(Math.max(0, Math.min(1, vol)));
            sound.play();
        } catch {
            // ignore
        }
    }

    function ensureAudio() {
        const H = HowlCtor();
        if (!H || ambient) return;
        try {
            ambient = new H({
                src: [AUDIO.ambient],
                loop: true,
                volume: 0,
                html5: true,
                preload: true,
            });
            sfx = {
                hover: makePoolHowl(AUDIO.hover, 0.28),
                select: makePoolHowl(AUDIO.select, 0.5),
                open: makePoolHowl(AUDIO.open, 0.45),
                whoosh: makePoolHowl(AUDIO.whoosh, 0.4),
                rarity: makePoolHowl(AUDIO.rarity, 0.48),
            };
        } catch {
            ambient = null;
            sfx = {};
        }
    }

    function playSfx(name, vol = 1) {
        if (muted || !unlocked) return;
        ensureAudio();
        const sound = sfx[name];
        if (!sound) return;
        if (Array.isArray(sound)) {
            playPool(sound, vol);
            return;
        }
        try {
            sound.volume(Math.max(0, Math.min(1, vol)));
            sound.play();
        } catch {
            // ignore
        }
    }

    function startAmbient() {
        if (muted || !unlocked) return;
        ensureAudio();
        if (!ambient) return;
        try {
            if (!ambient.playing()) ambient.play();
            const g = gsapLib();
            if (g) g.to(ambient, { volume: 0.42, duration: 1.8, ease: 'sine.out', overwrite: true });
            else ambient.volume(0.42);
        } catch {
            // ignore
        }
    }

    function stopAmbient(fade = true) {
        if (!ambient) return;
        try {
            const g = gsapLib();
            if (fade && g) {
                g.to(ambient, {
                    volume: 0,
                    duration: 0.7,
                    ease: 'sine.in',
                    onComplete: () => ambient.stop(),
                });
            } else {
                ambient.stop();
            }
        } catch {
            // ignore
        }
    }

    function setMuted(next) {
        muted = Boolean(next);
        localStorage.setItem('campaign_map_muted', muted ? '1' : '0');
        syncMuteUi();
        if (muted) stopAmbient(true);
        else if (unlocked) startAmbient();
    }

    function syncMuteUi() {
        const btn = page?.querySelector('[data-campaign-audio-toggle]');
        if (!btn) return;
        btn.setAttribute('aria-pressed', muted ? 'true' : 'false');
        btn.textContent = muted ? 'Som off' : 'Som on';
    }

    function unlockAudioOnce() {
        if (unlocked) return;
        unlocked = true;
        ensureAudio();
        startAmbient();
    }

    function pollenOptions() {
        return {
            fullScreen: false,
            fpsLimit: 48,
            detectRetina: true,
            background: { color: { value: 'transparent' } },
            particles: {
                number: { value: weather === 'rain' ? 8 : 26, density: { enable: true, width: 900, height: 700 } },
                color: { value: ['#bbf7d0', '#fde68a', '#86efac', '#a7f3d0'] },
                shape: { type: ['circle', 'triangle'] },
                opacity: { value: { min: 0.1, max: 0.4 } },
                size: { value: { min: 1.1, max: 3.4 } },
                move: {
                    enable: true,
                    speed: { min: 0.15, max: 0.75 },
                    direction: 'bottom',
                    drift: 0.4,
                    outModes: { default: 'out' },
                },
            },
            interactivity: {
                events: { onHover: { enable: true, mode: 'bubble' }, resize: { enable: true } },
                modes: { bubble: { distance: 90, size: 5, duration: 1.1, opacity: 0.7 } },
            },
        };
    }

    function rainOptions(storm = false) {
        return {
            fullScreen: false,
            fpsLimit: 55,
            detectRetina: true,
            background: { color: { value: 'transparent' } },
            particles: {
                number: { value: storm ? 110 : 70, density: { enable: true, width: 900, height: 700 } },
                color: { value: storm ? ['#93c5fd', '#e2e8f0', '#f8fafc'] : ['#93c5fd', '#bfdbfe', '#e2e8f0'] },
                shape: { type: 'line' },
                opacity: { value: { min: 0.15, max: storm ? 0.6 : 0.45 } },
                size: { value: { min: 0.4, max: storm ? 2 : 1.4 } },
                move: {
                    enable: true,
                    speed: { min: storm ? 14 : 8, max: storm ? 24 : 16 },
                    direction: 'bottom',
                    straight: true,
                    outModes: { default: 'out' },
                },
                rotate: { value: storm ? 28 : 20 },
            },
        };
    }

    function firefliesOptions() {
        return {
            fullScreen: false,
            fpsLimit: 48,
            detectRetina: true,
            background: { color: { value: 'transparent' } },
            particles: {
                number: { value: 24 },
                color: { value: ['#fde68a', '#fef08a', '#bbf7d0'] },
                shape: { type: 'circle' },
                opacity: {
                    value: { min: 0.12, max: 0.9 },
                    animation: { enable: true, speed: 1.4, sync: false },
                },
                size: { value: { min: 1, max: 2.8 } },
                move: {
                    enable: true,
                    speed: { min: 0.2, max: 0.75 },
                    direction: 'none',
                    outModes: { default: 'bounce' },
                },
            },
        };
    }

    async function mountParticles(host, mode = 'pollen') {
        const engine = tsParticlesApi();
        if (!engine || !host || reduced) return;
        try {
            if (particleInstance) {
                await particleInstance.destroy?.();
                particleInstance = null;
            }
            let options = pollenOptions();
            if (mode === 'rain') options = rainOptions(false);
            if (mode === 'storm') options = rainOptions(true);
            if (mode === 'fireflies') options = firefliesOptions();
            particleInstance = await engine.load({
                id: 'campaign-forest-particles',
                element: host,
                options,
            });
        } catch {
            particleInstance = null;
        }
    }

    function particleModeForWeather() {
        if (weather === 'rain') return 'rain';
        if (weather === 'storm') return 'storm';
        if (weather === 'fireflies' || weather === 'night') return 'fireflies';
        return 'pollen';
    }

    function ensureWeatherRoot(mapRoot) {
        let root = mapRoot.querySelector('[data-campaign-weather]');
        if (!root) {
            root = document.createElement('div');
            root.className = 'campaign-weather';
            root.setAttribute('data-campaign-weather', '');
            root.innerHTML = `
                <div class="campaign-weather-tint" data-weather-tint></div>
                <div class="campaign-weather-fog" data-weather-fog></div>
                <div class="campaign-weather-clouds" data-weather-clouds>
                    <span></span><span></span><span></span><span></span>
                </div>
                <div class="campaign-weather-lightning" data-weather-lightning></div>
                <div class="campaign-map-highlights" data-campaign-highlights></div>
            `;
            mapRoot.appendChild(root);
        }
        weatherRoot = root;
        return root;
    }

    function applyWeatherClass(mapRoot) {
        mapRoot.classList.remove(
            'is-weather-clear', 'is-weather-fog', 'is-weather-rain',
            'is-weather-storm', 'is-weather-night', 'is-weather-fireflies'
        );
        mapRoot.classList.add(`is-weather-${weather}`);
        if (weather === 'storm' && !reduced) {
            flashLightning(mapRoot);
        }
    }

    function flashLightning(mapRoot) {
        const bolt = mapRoot.querySelector('[data-weather-lightning]');
        if (!bolt) return;
        bolt.classList.add('is-flash');
        window.setTimeout(() => bolt.classList.remove('is-flash'), 180);
        window.setTimeout(() => {
            bolt.classList.add('is-flash');
            window.setTimeout(() => bolt.classList.remove('is-flash'), 120);
        }, 280);
        cameraShake(0.45);
    }

    async function setWeather(next, mapRoot) {
        weather = next;
        if (!mapRoot) mapRoot = page?.querySelector('.campaign-map');
        if (!mapRoot) return;
        ensureWeatherRoot(mapRoot);
        applyWeatherClass(mapRoot);
        const host = mapRoot.querySelector('[data-campaign-particles]');
        if (host) await mountParticles(host, particleModeForWeather());
    }

    function scheduleWeather(mapRoot) {
        if (reduced || weatherTimer) return;
        weatherTimer = window.setInterval(() => {
            const idx = WEATHER_ORDER.indexOf(weather);
            const next = WEATHER_ORDER[(Math.max(0, idx) + 1) % WEATHER_ORDER.length];
            const root = page?.querySelector('.campaign-map');
            if (root) setWeather(next, root);
        }, WEATHER_CYCLE_MS);
        paintHighlights(mapRoot);
    }

    function paintHighlights(mapRoot) {
        const host = mapRoot.querySelector('[data-campaign-highlights]');
        if (!host || host.childElementCount) return;
        const spots = [
            { x: 28, y: 62, s: 18 },
            { x: 48, y: 40, s: 16 },
            { x: 72, y: 34, s: 14 },
            { x: 84, y: 22, s: 12 },
        ];
        host.innerHTML = spots.map((p) => (
            `<i style="left:${p.x}%;top:${p.y}%;width:${p.s}%;padding-top:${p.s}%;"></i>`
        )).join('');
    }

    function killSpark() {
        sparkTween?.kill?.();
        sparkTween = null;
        sparkEl?.remove?.();
        sparkEl = null;
    }

    function animateTrails(mapRoot) {
        const g = gsapLib();
        const paths = mapRoot.querySelectorAll('.campaign-map-trails path.is-glow');
        if (!paths.length) return;

        paths.forEach((path, index) => {
            try {
                const len = path.getTotalLength();
                path.style.strokeDasharray = `${len}`;
                path.style.strokeDashoffset = `${len}`;
                if (g && !reduced) {
                    g.to(path, {
                        strokeDashoffset: 0,
                        duration: 1.25,
                        delay: 0.08 + index * 0.11,
                        ease: 'power2.out',
                    });
                } else {
                    path.style.strokeDashoffset = '0';
                }
            } catch {
                // ignore
            }
        });

        const active = mapRoot.querySelector('.campaign-map-trails path.is-glow.is-active, .campaign-map-trails path.is-glow.is-cleared');
        if (!active || reduced || !g) return;

        killSpark();
        sparkEl = document.createElement('div');
        sparkEl.className = 'campaign-trail-spark';
        mapRoot.appendChild(sparkEl);

        const proxy = { t: 0 };
        sparkTween = g.to(proxy, {
            t: 1,
            duration: 5.2,
            repeat: -1,
            ease: 'none',
            onUpdate: () => {
                try {
                    const len = active.getTotalLength();
                    const pt = active.getPointAtLength(proxy.t * len);
                    sparkEl.style.left = `${pt.x}%`;
                    sparkEl.style.top = `${pt.y}%`;
                } catch {
                    // ignore
                }
            },
        });
    }

    function animatePins(mapRoot, { entrance = true } = {}) {
        const g = gsapLib();
        const pins = mapRoot.querySelectorAll('.campaign-pin');
        if (!g || !pins.length) return;

        if (entrance && !reduced) {
            g.fromTo(
                pins,
                { opacity: 0, y: 18, scale: 0.82 },
                {
                    opacity: 1,
                    y: 0,
                    scale: 1,
                    duration: 0.55,
                    stagger: 0.06,
                    ease: 'back.out(1.6)',
                    clearProps: 'transform',
                }
            );
        }

        if (reduced) return;

        pins.forEach((pin) => {
            const img = pin.querySelector('img');
            if (!img) return;
            pin.addEventListener('mouseenter', () => {
                playSfx('hover', 0.4);
                g.to(img, { y: -6, scale: 1.12, duration: 0.25, ease: 'power2.out', overwrite: 'auto' });
            });
            pin.addEventListener('mouseleave', () => {
                g.to(img, { y: 0, scale: 1, duration: 0.3, ease: 'power2.out', overwrite: 'auto' });
            });
        });
    }

    function popIn(el, { y = 14, scale = 0.96, duration = 0.35 } = {}) {
        const g = gsapLib();
        if (!el || !g || reduced) {
            if (el) el.style.opacity = '1';
            return Promise.resolve();
        }
        return new Promise((resolve) => {
            g.fromTo(
                el,
                { autoAlpha: 0, y, scale },
                {
                    autoAlpha: 1,
                    y: 0,
                    scale: 1,
                    duration,
                    ease: 'power3.out',
                    overwrite: true,
                    onComplete: resolve,
                }
            );
        });
    }

    function popOut(el, { y = 10, scale = 0.97, duration = 0.22 } = {}) {
        const g = gsapLib();
        if (!el) return Promise.resolve();
        if (!g || reduced) {
            el.style.opacity = '0';
            return Promise.resolve();
        }
        return new Promise((resolve) => {
            g.to(el, {
                autoAlpha: 0,
                y,
                scale,
                duration,
                ease: 'power2.in',
                overwrite: true,
                onComplete: resolve,
            });
        });
    }

    function ensureConfettiCanvas() {
        if (confettiCanvas && document.body.contains(confettiCanvas)) return confettiCanvas;
        confettiCanvas = document.createElement('canvas');
        confettiCanvas.id = 'campaign-confetti-canvas';
        confettiCanvas.setAttribute('aria-hidden', 'true');
        confettiCanvas.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:10000;';
        document.body.appendChild(confettiCanvas);
        return confettiCanvas;
    }

    function gsapBurstFallback(kind = 'soft') {
        const g = gsapLib();
        if (!g || reduced) return;
        const host = document.createElement('div');
        host.className = 'campaign-burst-fallback';
        document.body.appendChild(host);
        const count = kind === 'hard' ? 36 : 18;
        const colors = ['#fbbf24', '#86efac', '#93c5fd', '#c4b5fd', '#fde68a'];
        for (let i = 0; i < count; i += 1) {
            const bit = document.createElement('i');
            bit.style.background = colors[i % colors.length];
            host.appendChild(bit);
            const angle = (Math.PI * 2 * i) / count;
            const dist = 80 + Math.random() * 160;
            g.fromTo(
                bit,
                { x: 0, y: 0, opacity: 1, scale: 1 },
                {
                    x: Math.cos(angle) * dist,
                    y: Math.sin(angle) * dist + 40,
                    opacity: 0,
                    scale: 0.4,
                    duration: 0.9 + Math.random() * 0.4,
                    ease: 'power2.out',
                }
            );
        }
        window.setTimeout(() => host.remove(), 1500);
    }

    async function celebrate(kind = 'soft') {
        playSfx('whoosh', 0.55);
        if (reduced) return;

        const fn = confettiApi();
        const canvas = ensureConfettiCanvas();
        const opts = kind === 'hard'
            ? {
                particleCount: 110,
                spread: 78,
                startVelocity: 42,
                scalar: 1.05,
                origin: { y: 0.58 },
                colors: ['#fbbf24', '#86efac', '#93c5fd', '#c4b5fd', '#fde68a'],
            }
            : {
                particleCount: 55,
                spread: 62,
                startVelocity: 28,
                origin: { y: 0.62 },
                colors: ['#bbf7d0', '#fde68a', '#86efac'],
            };

        try {
            if (typeof fn === 'function') {
                if (typeof fn.create === 'function') {
                    const fire = await fn.create(canvas, {});
                    await fire(opts);
                    return;
                }
                await fn(opts);
                return;
            }
        } catch {
            // fallback abaixo
        }
        gsapBurstFallback(kind);
    }

    function sparkleRarity(root) {
        const g = gsapLib();
        if (!root || reduced) return;
        root.querySelectorAll('.campaign-dossier-card.rarity-rare, .campaign-dossier-card.rarity-epic, .campaign-dossier-card.rarity-legendary, .campaign-album-card.rarity-rare, .campaign-album-card.rarity-epic, .campaign-album-card.rarity-legendary, .campaign-lobby-mini.rarity-rare, .campaign-lobby-mini.rarity-epic').forEach((card) => {
            if (card.querySelector('.campaign-rarity-spark')) return;
            const spark = document.createElement('span');
            spark.className = 'campaign-rarity-spark';
            card.appendChild(spark);
            if (g) {
                g.to(spark, {
                    opacity: 0.15,
                    duration: 1.1,
                    yoyo: true,
                    repeat: -1,
                    ease: 'sine.inOut',
                    delay: Math.random(),
                });
            }
        });
        decorateEssence(root);
    }

    function decorateEssence(root = page) {
        if (!root || reduced) return;
        const targets = root.querySelectorAll(
            '.campaign-loot-item.is-rare > .grid-stack-item-content, .campaign-loot-item.is-epic > .grid-stack-item-content, .campaign-loot-item.is-legendary > .grid-stack-item-content, .campaign-dossier-card.rarity-epic, .campaign-dossier-card.rarity-legendary, .campaign-album-card.rarity-legendary'
        );
        targets.forEach((el) => {
            if (el.querySelector(':scope > .campaign-essence')) return;
            const essence = document.createElement('span');
            essence.className = 'campaign-essence';
            essence.setAttribute('aria-hidden', 'true');
            const motes = document.createElement('span');
            motes.className = 'campaign-essence-motes';
            const corners = [
                [8, 10], [92, 12], [10, 88], [90, 86], [50, 6], [50, 94],
            ];
            motes.innerHTML = corners.map(([x, y], i) => (
                `<i style="left:${x}%;top:${y}%;animation-delay:${(i * 0.35).toFixed(2)}s"></i>`
            )).join('');
            el.appendChild(essence);
            el.appendChild(motes);
        });
    }

    async function afterMapRender(mapRoot, world) {
        if (!mapRoot) return;
        const sig = `${world?.code || ''}:${(world?.nodes || []).map((n) => `${n.code}:${n.status}`).join('|')}`;
        const worldChanged = sig !== mapSig;
        mapSig = sig;

        let host = mapRoot.querySelector('[data-campaign-particles]');
        if (!host) {
            host = document.createElement('div');
            host.className = 'campaign-map-particles';
            host.setAttribute('data-campaign-particles', '');
            mapRoot.appendChild(host);
        }

        ensureWeatherRoot(mapRoot);
        applyWeatherClass(mapRoot);
        await mountParticles(host, particleModeForWeather());
        if (particleInstance) mapRoot.querySelector('.campaign-map-fx')?.remove();

        decoratePins(mapRoot);
        animateTrails(mapRoot);
        animatePins(mapRoot, { entrance: worldChanged });
        scheduleWeather(mapRoot);
        paintHighlights(mapRoot);
    }

    function decoratePins(mapRoot) {
        mapRoot.querySelectorAll('.campaign-pin').forEach((pin) => {
            if (!pin.querySelector('.campaign-pin-aura') && (pin.classList.contains('is-available') || pin.classList.contains('is-village'))) {
                const aura = document.createElement('span');
                aura.className = `campaign-pin-aura${pin.classList.contains('is-village') ? ' is-village' : ''}`;
                pin.prepend(aura);
            }
            if (!pin.querySelector('.campaign-pin-fog') && (pin.classList.contains('is-locked') || pin.classList.contains('is-teaser'))) {
                const fog = document.createElement('span');
                fog.className = 'campaign-pin-fog';
                pin.prepend(fog);
            }
            if (pin.classList.contains('is-cleared') && !pin.querySelector('.campaign-pin-cleared-glow')) {
                const glow = document.createElement('span');
                glow.className = 'campaign-pin-cleared-glow';
                pin.prepend(glow);
            }
        });
    }

    function cameraShake(intensity = 0.55) {
        const g = gsapLib();
        const target = page || document.body;
        if (!g || reduced || !target) return;
        g.fromTo(
            target,
            { x: 0, y: 0 },
            {
                keyframes: [
                    { x: -6 * intensity, y: 3 * intensity, duration: 0.05 },
                    { x: 7 * intensity, y: -4 * intensity, duration: 0.06 },
                    { x: -4 * intensity, y: 2 * intensity, duration: 0.05 },
                    { x: 0, y: 0, duration: 0.08 },
                ],
                ease: 'power1.out',
            }
        );
    }

    function screenFlash(color = 'rgba(253, 224, 71, 0.45)', duration = 0.45) {
        const g = gsapLib();
        const flash = document.createElement('div');
        flash.className = 'campaign-screen-flash';
        flash.style.background = color;
        document.body.appendChild(flash);
        if (!g || reduced) {
            window.setTimeout(() => flash.remove(), duration * 1000);
            return;
        }
        g.fromTo(
            flash,
            { autoAlpha: 0.85 },
            { autoAlpha: 0, duration, ease: 'power2.out', onComplete: () => flash.remove() }
        );
    }

    function rarityBurst(rarity = 'rare') {
        const colors = {
            uncommon: ['#86efac', '#bbf7d0'],
            rare: ['#93c5fd', '#60a5fa'],
            epic: ['#c4b5fd', '#a78bfa'],
            legendary: ['#fbbf24', '#f59e0b', '#fde68a'],
        };
        celebrate('soft');
        gsapBurstFallback('soft');
        const palette = colors[rarity] || colors.rare;
        screenFlash(`${palette[0]}88`, 0.35);
        playSfx('rarity', 0.7);
    }

    function combatHit(type = 'player_hit') {
        if (reduced) return;
        const g = gsapLib();
        const arena = page?.querySelector('.campaign-battle-arena') || page?.querySelector('[data-campaign-battle]');
        if (!arena) return;

        if (type.includes('crit')) {
            screenFlash('rgba(254, 202, 202, 0.35)', 0.25);
            cameraShake(0.7);
        } else if (type.startsWith('monster')) {
            cameraShake(0.35);
        }

        if (!g) return;
        const spark = document.createElement('div');
        spark.className = `campaign-combat-spark${type.includes('crit') ? ' is-crit' : ''}`;
        arena.appendChild(spark);
        g.fromTo(
            spark,
            { scale: 0.4, autoAlpha: 1 },
            { scale: 1.6, autoAlpha: 0, duration: 0.45, ease: 'power2.out', onComplete: () => spark.remove() }
        );
    }

    function discoveryFlash() {
        screenFlash('rgba(253, 224, 71, 0.5)', 0.55);
        celebrate('hard');
        cameraShake(0.4);
    }

    function onPinSelect() {
        playSfx('select', 0.7);
        playSfx('whoosh', 0.35);
    }

    function onTooltipShow(el) {
        playSfx('hover', 0.25);
        popIn(el, { y: 10, scale: 0.97, duration: 0.28 });
    }

    function onLobbyShow(el) {
        playSfx('open', 0.4);
        popIn(el, { y: 18, scale: 0.95, duration: 0.42 });
    }

    function onAlbumOpen(el) {
        playSfx('open', 0.5);
        popIn(el?.querySelector('.campaign-album-panel'), { y: 24, scale: 0.94, duration: 0.4 });
        sparkleRarity(el);
    }

    function onDossierShow(el) {
        popIn(el?.querySelector('.campaign-dossier-panel'), { y: 20, scale: 0.96, duration: 0.36 });
        sparkleRarity(el);
    }

    function openFxLab() {
        const lab = page?.querySelector('[data-campaign-fx-lab]');
        if (!lab) return;
        lab.hidden = false;
        const grid = lab.querySelector('[data-campaign-fx-actions]');
        if (grid && !grid.childElementCount) {
            const actions = [
                { id: 'w-clear', label: 'Clima: Clear', run: () => setWeather('clear') },
                { id: 'w-fog', label: 'Clima: Fog', run: () => setWeather('fog') },
                { id: 'w-rain', label: 'Clima: Rain', run: () => setWeather('rain') },
                { id: 'w-storm', label: 'Clima: Storm', run: () => setWeather('storm') },
                { id: 'w-night', label: 'Clima: Night', run: () => setWeather('night') },
                { id: 'w-flies', label: 'Clima: Fireflies', run: () => setWeather('fireflies') },
                { id: 'c-soft', label: 'Confetti soft', run: () => celebrate('soft') },
                { id: 'c-hard', label: 'Confetti hard', run: () => celebrate('hard') },
                { id: 'flash', label: 'Screen flash', run: () => screenFlash() },
                { id: 'shake', label: 'Camera shake', run: () => cameraShake(0.8) },
                { id: 'discover', label: 'Discovery flash', run: () => discoveryFlash() },
                { id: 'r-rare', label: 'Rarity rare', run: () => rarityBurst('rare') },
                { id: 'r-epic', label: 'Rarity epic', run: () => rarityBurst('epic') },
                { id: 'r-leg', label: 'Rarity legendary', run: () => rarityBurst('legendary') },
                { id: 'hit', label: 'Combat hit', run: () => combatHit('player_hit') },
                { id: 'crit', label: 'Combat crit', run: () => combatHit('player_crit') },
                { id: 'mdmg', label: 'Combat damage', run: () => combatHit('monster_hit') },
                {
                    id: 'bolt',
                    label: 'Lightning',
                    run: () => {
                        const map = page?.querySelector('.campaign-map');
                        if (map) flashLightning(map);
                    },
                },
                {
                    id: '3d',
                    label: isMap3DEnabled?.() ? 'Atmosfera 3D: OFF' : 'Atmosfera 3D: ON',
                    run: async () => {
                        await onToggleMap3D?.();
                        const btn = grid.querySelector('[data-fx-action="3d"]');
                        if (btn) btn.textContent = isMap3DEnabled?.() ? 'Atmosfera 3D: OFF' : 'Atmosfera 3D: ON';
                    },
                },
                {
                    id: 'e-rare',
                    label: 'Essence rare',
                    run: () => {
                        decorateEssence(page);
                        rarityBurst('rare');
                    },
                },
                { id: 'e-epic', label: 'Essence epic', run: () => { decorateEssence(page); rarityBurst('epic'); } },
                { id: 'e-leg', label: 'Essence legendary', run: () => { decorateEssence(page); rarityBurst('legendary'); } },
            ];
            const byId = Object.fromEntries(actions.map((a) => [a.id, a]));
            grid.innerHTML = actions.map((a) => (
                `<button type="button" class="campaign-button is-ghost" data-fx-action="${a.id}">${a.label}</button>`
            )).join('');
            grid.querySelectorAll('[data-fx-action]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    unlockAudioOnce();
                    byId[btn.getAttribute('data-fx-action')]?.run?.();
                });
            });
        }
        popIn(lab, { y: 16, scale: 0.97, duration: 0.3 });
    }

    function closeFxLab() {
        const lab = page?.querySelector('[data-campaign-fx-lab]');
        if (!lab || lab.hidden) return;
        popOut(lab).then(() => { lab.hidden = true; });
    }

    function destroy() {
        killSpark();
        stopAmbient(false);
        if (weatherTimer) window.clearInterval(weatherTimer);
        weatherTimer = null;
        particleInstance?.destroy?.();
        weatherInstance?.destroy?.();
        particleInstance = null;
        weatherInstance = null;
        confettiCanvas?.remove?.();
        confettiCanvas = null;
        gsapLib()?.killTweensOf?.('*');
    }

    function boot() {
        syncMuteUi();
        page?.querySelector('[data-campaign-audio-toggle]')?.addEventListener('click', () => {
            unlockAudioOnce();
            setMuted(!muted);
        });
        page?.querySelector('[data-campaign-fx-open]')?.addEventListener('click', () => openFxLab());
        page?.querySelector('[data-campaign-fx-close]')?.addEventListener('click', () => closeFxLab());

        const unlock = () => unlockAudioOnce();
        ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => {
            window.addEventListener(ev, unlock, { once: true, passive: true });
        });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stopAmbient(true);
            else if (!muted && unlocked) startAmbient();
        });
    }

    return {
        boot,
        afterMapRender,
        onPinSelect,
        onTooltipShow,
        onLobbyShow,
        onAlbumOpen,
        onDossierShow,
        celebrate,
        popIn,
        popOut,
        sparkleRarity,
        decorateEssence,
        setWeather,
        setMuted,
        cameraShake,
        screenFlash,
        rarityBurst,
        combatHit,
        discoveryFlash,
        openFxLab,
        closeFxLab,
        destroy,
        get muted() { return muted; },
        get weather() { return weather; },
    };
}
