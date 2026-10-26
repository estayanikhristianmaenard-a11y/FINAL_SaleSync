import { api } from './api.js';

// UI handoff: render Home, Shop, Cart, My Orders and Profile inside #app.
// Use sections/modals/hash navigation on this page, following the team's design.
// No styles or replacement design are imposed by the backend.
export { api };

export async function verifyEmailFromLink() {
    const prefix = '#verify-email=';
    if (!location.hash.startsWith(prefix)) return null;
    const token = decodeURIComponent(location.hash.slice(prefix.length));
    const result = await api('/auth/verify-email', { method: 'POST', body: { token } });
    history.replaceState(null, '', location.pathname + location.search + '#profile');
    return result;
}

// Your teammate should call verifyEmailFromLink() during app startup and render
// its success/error state using the agreed design. Payment returns to #orders/{id}.
