/**
 * Atmosfera 3D leve SOBRE o mapa 2D.
 * Sem terreno: só névoa de borda, nuvens e luz ambiente — a arte pintada continua no CSS.
 */

import * as THREE from '/assets/vendor/three/build/three.module.min.js';

export function createCampaignMap3D({ page, getWeather } = {}) {
    let enabled = false;
    let mapRoot = null;
    let renderer = null;
    let scene = null;
    let camera = null;
    let clock = null;
    let animId = 0;
    let canvas = null;
    let clouds = [];
    let fogPlanes = [];
    let vignette = null;
    let hemi = null;
    let resizeObs = null;
    let weather = 'clear';
    let parallaxX = 0;
    let parallaxY = 0;
    let targetParallaxX = 0;
    let targetParallaxY = 0;

    function teardown() {
        if (animId) cancelAnimationFrame(animId);
        animId = 0;
        resizeObs?.disconnect?.();
        resizeObs = null;
        mapRoot?.removeEventListener?.('pointermove', onPointerMove);
        if (canvas) {
            canvas.remove();
            canvas = null;
        }
        scene?.traverse?.((child) => {
            child.geometry?.dispose?.();
            if (child.material) {
                const mats = Array.isArray(child.material) ? child.material : [child.material];
                mats.forEach((m) => m.dispose?.());
            }
        });
        renderer?.dispose?.();
        renderer = null;
        scene = null;
        camera = null;
        clock = null;
        clouds = [];
        fogPlanes = [];
        vignette = null;
        hemi = null;
        mapRoot?.classList.remove('is-atm-3d');
    }

    function makeSoftCloudMaterial(opacity = 0.22) {
        return new THREE.MeshBasicMaterial({
            color: '#e8eef8',
            transparent: true,
            opacity,
            depthWrite: false,
            side: THREE.DoubleSide,
        });
    }

    function makeFogMaterial(color, opacity) {
        return new THREE.MeshBasicMaterial({
            color,
            transparent: true,
            opacity,
            depthWrite: false,
            side: THREE.DoubleSide,
        });
    }

    function buildClouds() {
        const group = new THREE.Group();
        const geo = new THREE.PlaneGeometry(1, 1);
        for (let i = 0; i < 8; i += 1) {
            const mat = makeSoftCloudMaterial(0.14 + (i % 3) * 0.04);
            const mesh = new THREE.Mesh(geo, mat);
            const w = 28 + (i % 4) * 10;
            const h = 10 + (i % 3) * 4;
            mesh.scale.set(w, h, 1);
            mesh.position.set(
                -70 + i * 22,
                18 + (i % 3) * 6,
                -8 - (i % 4) * 4
            );
            mesh.userData.speed = 3.5 + (i % 5) * 1.2;
            mesh.userData.bob = i * 0.7;
            mesh.userData.baseY = mesh.position.y;
            group.add(mesh);
            clouds.push(mesh);
        }
        return group;
    }

    function buildEdgeFog() {
        const group = new THREE.Group();
        const geo = new THREE.PlaneGeometry(1, 1);
        const edges = [
            { x: 0, y: -32, z: 2, sx: 140, sy: 36, rot: 0, opacity: 0.42, color: '#020617' },
            { x: 0, y: 38, z: 2, sx: 140, sy: 28, rot: 0, opacity: 0.32, color: '#020617' },
            { x: -58, y: 0, z: 3, sx: 40, sy: 90, rot: 0, opacity: 0.38, color: '#0b1220' },
            { x: 58, y: 0, z: 3, sx: 40, sy: 90, rot: 0, opacity: 0.38, color: '#0b1220' },
        ];
        edges.forEach((e) => {
            const mesh = new THREE.Mesh(geo, makeFogMaterial(e.color, e.opacity));
            mesh.scale.set(e.sx, e.sy, 1);
            mesh.position.set(e.x, e.y, e.z);
            mesh.userData.baseOpacity = e.opacity;
            group.add(mesh);
            fogPlanes.push(mesh);
        });
        return group;
    }

    function buildVignetteDisk() {
        const geo = new THREE.RingGeometry(42, 90, 64);
        const mat = new THREE.MeshBasicMaterial({
            color: '#020617',
            transparent: true,
            opacity: 0.55,
            depthWrite: false,
            side: THREE.DoubleSide,
        });
        const mesh = new THREE.Mesh(geo, mat);
        mesh.position.z = 1;
        vignette = mesh;
        return mesh;
    }

    function applyWeather(name) {
        weather = name || weather || 'clear';
        const foggy = weather === 'fog' || weather === 'rain' || weather === 'storm';
        const night = weather === 'night' || weather === 'fireflies';
        const storm = weather === 'storm';

        clouds.forEach((c, i) => {
            const base = foggy ? 0.28 : night ? 0.12 : 0.1;
            c.material.opacity = base + (i % 3) * 0.03;
            c.material.color.set(night ? '#94a3b8' : storm ? '#cbd5e1' : '#e8eef8');
            c.userData.speed = foggy ? 2.2 + (i % 4) : 4 + (i % 5) * 1.4;
        });

        fogPlanes.forEach((p) => {
            const boost = foggy ? 1.35 : night ? 1.5 : 1;
            p.material.opacity = Math.min(0.72, p.userData.baseOpacity * boost);
            p.material.color.set(night ? '#020617' : storm ? '#0f172a' : '#020617');
        });

        if (vignette) {
            vignette.material.opacity = night ? 0.72 : foggy ? 0.6 : 0.48;
        }
        if (hemi) {
            hemi.intensity = night ? 0.15 : foggy ? 0.22 : 0.3;
        }
    }

    function onPointerMove(e) {
        if (!mapRoot) return;
        const rect = mapRoot.getBoundingClientRect();
        const nx = ((e.clientX - rect.left) / rect.width) * 2 - 1;
        const ny = ((e.clientY - rect.top) / rect.height) * 2 - 1;
        targetParallaxX = nx * 4;
        targetParallaxY = -ny * 3;
    }

    function resize() {
        if (!renderer || !camera || !mapRoot) return;
        const w = mapRoot.clientWidth || 1;
        const h = mapRoot.clientHeight || 1;
        renderer.setSize(w, h, false);
        camera.left = -w / 12;
        camera.right = w / 12;
        camera.top = h / 12;
        camera.bottom = -h / 12;
        camera.updateProjectionMatrix();
    }

    function tick() {
        animId = requestAnimationFrame(tick);
        if (!renderer || !scene || !camera || !clock) return;
        const t = clock.getElapsedTime();
        const dt = Math.min(0.05, clock.getDelta());

        parallaxX += (targetParallaxX - parallaxX) * Math.min(1, dt * 3);
        parallaxY += (targetParallaxY - parallaxY) * Math.min(1, dt * 3);

        clouds.forEach((c, i) => {
            c.position.x += c.userData.speed * dt;
            if (c.position.x > 90) c.position.x = -90;
            c.position.y = c.userData.baseY + Math.sin(t * 0.5 + c.userData.bob) * 1.2;
            c.position.x += parallaxX * (0.15 + (i % 3) * 0.05);
        });

        fogPlanes.forEach((p, i) => {
            p.position.x = (i < 2 ? 0 : (i === 2 ? -58 : 58)) + parallaxX * 0.35;
            p.position.y = (i === 0 ? -32 : i === 1 ? 38 : 0) + parallaxY * 0.25;
            p.material.opacity = p.userData.baseOpacity
                * (0.85 + Math.sin(t * 0.35 + i) * 0.12)
                * (weather === 'fog' || weather === 'rain' ? 1.3 : weather === 'night' ? 1.4 : 1);
        });

        if (vignette) {
            vignette.position.x = parallaxX * 0.2;
            vignette.position.y = parallaxY * 0.15;
        }

        const next = typeof getWeather === 'function' ? getWeather() : weather;
        if (next !== weather) applyWeather(next);

        renderer.render(scene, camera);
    }

    function mount(root) {
        teardown();
        mapRoot = root;
        if (!mapRoot || !enabled) return;

        mapRoot.classList.add('is-atm-3d');
        canvas = document.createElement('canvas');
        canvas.className = 'campaign-map-atm3d';
        canvas.setAttribute('aria-hidden', 'true');
        mapRoot.appendChild(canvas);

        renderer = new THREE.WebGLRenderer({
            canvas,
            antialias: true,
            alpha: true,
            powerPreference: 'high-performance',
        });
        renderer.setClearColor(0x000000, 0);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));

        scene = new THREE.Scene();
        camera = new THREE.OrthographicCamera(-50, 50, 40, -40, 0.1, 200);
        camera.position.set(0, 0, 60);
        camera.lookAt(0, 0, 0);
        clock = new THREE.Clock();

        hemi = new THREE.AmbientLight('#94a3b8', 0.25);
        scene.add(hemi);
        scene.add(buildEdgeFog());
        scene.add(buildVignetteDisk());
        scene.add(buildClouds());

        mapRoot.addEventListener('pointermove', onPointerMove, { passive: true });
        resizeObs = new ResizeObserver(() => resize());
        resizeObs.observe(mapRoot);
        resize();
        applyWeather(typeof getWeather === 'function' ? getWeather() : 'clear');
        tick();
    }

    async function afterMapRender(root) {
        mapRoot = root;
        if (enabled && root) mount(root);
    }

    function enable() {
        enabled = true;
        page?.classList.add('has-map-atm3d');
        if (mapRoot) mount(mapRoot);
    }

    function disable() {
        enabled = false;
        page?.classList.remove('has-map-atm3d');
        teardown();
    }

    function toggle() {
        if (enabled) disable();
        else enable();
        return enabled;
    }

    function setWeather(name) {
        applyWeather(name);
    }

    function destroy() {
        disable();
    }

    return {
        afterMapRender,
        enable,
        disable,
        toggle,
        setWeather,
        destroy,
        get enabled() { return enabled; },
    };
}
