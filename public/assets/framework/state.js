export function createStore(initialState = {}) {
    let state = Object.freeze({ ...initialState });
    const listeners = new Set();

    return {
        get() {
            return state;
        },
        set(patch) {
            state = Object.freeze({ ...state, ...(typeof patch === 'function' ? patch(state) : patch) });
            listeners.forEach((listener) => listener(state));
        },
        subscribe(listener) {
            listeners.add(listener);
            listener(state);
            return () => listeners.delete(listener);
        },
    };
}
