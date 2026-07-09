let container;

function ensureContainer() {
    if (!container) {
        container = document.createElement('div');
        container.className = 'gb-toast-stack';
        document.body.appendChild(container);
    }
    return container;
}

export function toast(message, type = 'info', timeout = 3200) {
    const item = document.createElement('div');
    item.className = `gb-toast gb-toast-${type}`;
    item.textContent = message;
    ensureContainer().appendChild(item);
    requestAnimationFrame(() => item.classList.add('is-visible'));
    setTimeout(() => {
        item.classList.remove('is-visible');
        setTimeout(() => item.remove(), 180);
    }, timeout);
}

export function installToastStyles() {
    if (document.getElementById('gb-toast-styles')) return;
    const style = document.createElement('style');
    style.id = 'gb-toast-styles';
    style.textContent = `
.gb-toast-stack{position:fixed;right:16px;bottom:16px;z-index:9999;display:grid;gap:8px}
.gb-toast{opacity:0;transform:translateY(8px);transition:.18s ease;padding:10px 12px;border-radius:6px;background:#1f2937;color:#fff;font:14px system-ui;box-shadow:0 8px 24px rgba(0,0,0,.18)}
.gb-toast.is-visible{opacity:1;transform:translateY(0)}
.gb-toast-success{background:#047857}.gb-toast-error{background:#b91c1c}.gb-toast-warning{background:#b45309}`;
    document.head.appendChild(style);
}
