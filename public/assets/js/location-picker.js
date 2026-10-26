import { api } from './api.js';

const requests = new Map();
const revisions = new WeakMap();
export function fields() {
    return '<label class="field">Province / region<select class="input" name="province" required disabled autocomplete="address-level1"><option value="">Loading provinces / regions…</option></select></label>' +
        '<label class="field">City / municipality<select class="input" name="city" required disabled autocomplete="address-level2"><option value="">Select a province / region first</option></select></label>' +
        '<label class="field">Barangay<select class="input" name="barangay" required disabled><option value="">Select a city / municipality first</option></select></label>' +
        '<p data-location-status class="small muted" role="status" aria-live="polite">Loading Philippine locations…</p>';
}
function load(endpoint, query = {}) {
    const key = endpoint + JSON.stringify(query);
    if (!requests.has(key)) {
        requests.set(key, api(endpoint, { query }).catch(error => { requests.delete(key); throw error; }));
    }
    return requests.get(key);
}

function reset(select, prompt) {
    select.replaceChildren(new Option(prompt, ''));
    select.value = '';
    select.disabled = true;
    revisions.set(select, (revisions.get(select) || 0) + 1);
}

function fill(select, items, prompt) {
    select.replaceChildren(new Option(prompt, ''));
    for (const item of items) select.add(new Option((item.label || item.name) + (item.district ? ` · ${item.district}` : ''), item.code));
    select.disabled = !items.length;
}

function status(form, message, retry = false) {
    const node = form.querySelector('[data-location-status]');
    if (!node) return;
    node.replaceChildren(document.createTextNode(message));
    if (retry) {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'btn ghost sm'; button.textContent = 'Retry';
        button.addEventListener('click', () => { void mount(form); });
        node.append(' ', button);
    }
}

async function cities(form) {
    const province = form.elements.province, city = form.elements.city, barangay = form.elements.barangay;
    reset(city, province.value ? 'Loading cities / municipalities…' : 'Select a province / region first');
    reset(barangay, 'Select a city / municipality first');
    if (!province.value) { status(form, 'Choose your province or region to continue.'); return; }
    const code = province.value, revision = revisions.get(city);
    status(form, 'Loading cities / municipalities…');
    try {
        const rows = await load('/locations/cities', { province_code:code });
        if (!form.isConnected || form.elements.province !== province || form.elements.city !== city || province.value !== code || revisions.get(city) !== revision) return false;
        fill(city, rows, 'Select city / municipality');
        status(form, 'Choose your city or municipality.');
        return true;
    } catch (error) {
        if (form.isConnected && form.elements.city === city && revisions.get(city) === revision) status(form, error.message || 'Unable to load cities.', true);
        return false;
    }
}

async function barangays(form) {
    const city = form.elements.city, barangay = form.elements.barangay;
    reset(barangay, city.value ? 'Loading barangays…' : 'Select a city / municipality first');
    if (!city.value) { status(form, 'Choose your city or municipality.'); return; }
    const code = city.value, revision = revisions.get(barangay);
    status(form, 'Loading barangays…');
    try {
        const rows = await load('/locations/barangays', { city_code:code });
        if (!form.isConnected || form.elements.city !== city || form.elements.barangay !== barangay || city.value !== code || revisions.get(barangay) !== revision) return false;
        fill(barangay, rows, 'Select barangay');
        status(form, 'Choose your barangay.');
        return true;
    } catch (error) {
        if (form.isConnected && form.elements.barangay === barangay && revisions.get(barangay) === revision) status(form, error.message || 'Unable to load barangays.', true);
        return false;
    }
}

export async function mount(form, initial = {}) {
    if (!form?.elements.province) return;
    const province = form.elements.province;
    // Retry a failed child request without discarding completed parent choices.
    if (province.value && form.elements.city.value) { await barangays(form); return; }
    if (province.value) { await cities(form); return; }
    reset(province, 'Loading provinces / regions…');
    reset(form.elements.city, 'Select a province / region first');
    reset(form.elements.barangay, 'Select a city / municipality first');
    status(form, 'Loading Philippine locations…');
    try {
        const { items } = await load('/locations/provinces');
        if (!form.isConnected || form.elements.province !== province) return;
        province.replaceChildren(new Option('Select province / region', ''));
        for (const [kind,label] of [['region','Regions (including Metro Manila)'], ['province','Provinces']]) {
            const group = document.createElement('optgroup'); group.label = label;
            for (const item of items.filter(item => item.kind === kind)) group.append(new Option(item.name, item.code));
            province.append(group);
        }
        province.disabled = false;
        status(form, 'Select province / region, then city / municipality, then barangay.');
        if (initial.province) {
            province.value = initial.province;
            if (!province.value) return;
            const citiesLoaded = await cities(form);
            if (!citiesLoaded || !form.isConnected || form.elements.province !== province || province.value !== initial.province) return;
            form.elements.city.value = initial.city || '';
            if (!form.elements.city.value) return;
            const barangaysLoaded = await barangays(form);
            if (!barangaysLoaded || !form.isConnected || form.elements.province !== province || form.elements.city.value !== initial.city) return;
            form.elements.barangay.value = initial.barangay || '';
            status(form, form.elements.barangay.value ? 'Location selected.' : 'Choose your barangay.');
        }
    } catch (error) {
        if (form.isConnected && form.elements.province === province) status(form, error.message || 'Unable to load Philippine locations.', true);
    }
}

document.addEventListener('change', event => {
    const form = event.target.closest('form');
    if (!form?.elements.province || !form.elements.city || !form.elements.barangay) return;
    if (event.target.name === 'province') void cities(form);
    if (event.target.name === 'city') void barangays(form);
    if (event.target.name === 'barangay') status(form, event.target.value ? 'Location selected.' : 'Choose your barangay.');
});
window.customerLocations = { mount, fields };
