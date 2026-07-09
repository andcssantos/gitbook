export function enableDragInventory(root, { onMove } = {}) {
    const element = typeof root === 'string' ? document.querySelector(root) : root;
    if (!element) return () => {};

    let dragged = null;

    function dragStart(event) {
        const item = event.target.closest('[data-item-id]');
        if (!item) return;
        dragged = item;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.itemId);
    }

    function dragOver(event) {
        if (event.target.closest('[data-slot-id]')) event.preventDefault();
    }

    function drop(event) {
        const slot = event.target.closest('[data-slot-id]');
        if (!slot || !dragged) return;
        event.preventDefault();
        onMove?.({
            itemId: dragged.dataset.itemId,
            fromSlot: dragged.closest('[data-slot-id]')?.dataset.slotId,
            toSlot: slot.dataset.slotId,
        });
        slot.appendChild(dragged);
        dragged = null;
    }

    element.addEventListener('dragstart', dragStart);
    element.addEventListener('dragover', dragOver);
    element.addEventListener('drop', drop);

    return () => {
        element.removeEventListener('dragstart', dragStart);
        element.removeEventListener('dragover', dragOver);
        element.removeEventListener('drop', drop);
    };
}
