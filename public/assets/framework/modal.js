export function openModal(content, options = {}) {
    const overlay = document.createElement('div');
    overlay.className = 'gb-modal-overlay';
    overlay.innerHTML = `<div class="gb-modal" role="dialog" aria-modal="true"></div>`;
    const modal = overlay.firstElementChild;
    if (content instanceof HTMLElement) modal.appendChild(content);
    else modal.innerHTML = String(content);
    document.body.appendChild(overlay);

    const close = () => overlay.remove();
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay && options.closeOnBackdrop !== false) close();
    });
    document.addEventListener('keydown', function esc(event) {
        if (event.key === 'Escape') {
            close();
            document.removeEventListener('keydown', esc);
        }
    });

    return { close, element: modal };
}

export function installModalStyles() {
    if (document.getElementById('gb-modal-styles')) return;
    const style = document.createElement('style');
    style.id = 'gb-modal-styles';
    style.textContent = `
.gb-modal-overlay{position:fixed;inset:0;z-index:9998;display:grid;place-items:center;background:rgba(15,23,42,.55);padding:16px}
.gb-modal{width:min(560px,100%);max-height:calc(100vh - 32px);overflow:auto;background:#fff;color:#111827;border-radius:8px;padding:16px;box-shadow:0 24px 80px rgba(0,0,0,.28)}`;
    document.head.appendChild(style);
}
