/* Customer-only controls built from the existing handoff components. */
export function passwordField(name, label, autocomplete, attributes = '') {
    const id = `account-${name}`;
    return `<label class="field">${esc(label)}<div class="password-input account-password-input">
        <input class="input" id="${esc(id)}" name="${esc(name)}" type="password" required autocomplete="${esc(autocomplete)}" ${attributes}>
        <button type="button" data-action="password-visibility" data-target="${esc(id)}" data-label="${esc(label.toLowerCase())}" aria-label="Show ${esc(label.toLowerCase())}" aria-pressed="false" title="Show ${esc(label.toLowerCase())}">${uiIcon('eye')}</button>
    </div></label>`;
}

function addressSummary(address) {
    return `<span class="customer-address-summary"><b>${esc(address.label)} · ${esc(address.name)}</b><span>${esc(address.line)}</span></span>`;
}

export function chooseDeliveryAddress() {
    const addresses = getUser().addresses;
    const selected = checkoutAddress();
    const picker = addresses.length ? `<details class="customer-address-picker">
        <summary aria-label="Delivery address options">${addressSummary(selected)}<span aria-hidden="true">⌄</span></summary>
        <fieldset class="customer-address-options"><legend class="sr-only">Choose a delivery address</legend>
            ${addresses.map(address => `<label class="customer-address-option">
                <input type="radio" name="address" value="${esc(address.id)}" ${address.id === selected.id ? 'checked' : ''} required>
                <span>${addressSummary(address)}<small>${esc(address.phone)}${address.default ? ' · Default' : ''}</small></span>
            </label>`).join('')}
        </fieldset>
    </details>` : '<p>Add an address before choosing your delivery destination.</p>';
    openModal('Choose delivery address', picker, 'Use this address', input => {
        guard(addresses.some(address => address.id === input.address), 'Choose a delivery address.');
        view.addressId = input.address;
    });
}

export function updateAddressChoice(input) {
    const picker = input.closest('.customer-address-picker');
    const address = getUser().addresses.find(address => address.id === input.value);
    if (!picker || !address) return;
    picker.querySelector('summary .customer-address-summary').outerHTML = addressSummary(address);
    picker.open = false;
    picker.querySelector('summary').focus();
}

export function viewInvoice(order) {
    guard(order, 'Order not found.');
    openModal('View invoice', invoiceHTML(order), 'Close', () => {}, true);
    $('#dialog').dataset.readonly = 'true';
}
