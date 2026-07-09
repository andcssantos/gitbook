export function createServerTimer({ serverNow, endsAt, onTick, onEnd }) {
    const drift = Date.now() - Number(serverNow);
    let active = true;

    function tick() {
        if (!active) return;
        const remaining = Math.max(0, Number(endsAt) - (Date.now() - drift));
        onTick?.(remaining);
        if (remaining <= 0) {
            active = false;
            onEnd?.();
            return;
        }
        requestAnimationFrame(tick);
    }

    tick();
    return { stop: () => { active = false; } };
}
