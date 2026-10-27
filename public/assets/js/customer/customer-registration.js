/* Registration presentation stays inside the customer's existing authentication card. */
function mountRegistration(form) {
    form.noValidate = true;
    form.closest('.auth-screen').classList.add('auth-registration');
    form.innerHTML = `
        <ol class="registration-progress" aria-label="Registration progress">
            <li data-registration-indicator="1" aria-current="step"><span>1</span><b>Account details</b></li>
            <li data-registration-indicator="2"><span>2</span><b>Delivery address</b></li>
        </ol>
        <p id="auth-error" role="alert" tabindex="-1"></p>
        <fieldset class="registration-step" data-registration-step="1">
            <legend class="sr-only">Account details</legend>
            <div class="registration-grid">
                <div class="registration-wide">${field('name','Full name','text','','required autocomplete="name" maxlength="70"')}</div>
                <div class="registration-wide">${field('email','Email address','email','','required autocomplete="email" placeholder="you@example.com"')}</div>
                <label class="field">Password<div class="password-input"><input class="input" id="sign-in-password" name="password" type="password" minlength="10" required autocomplete="new-password"><button type="button" data-action="password-visibility" data-target="sign-in-password" aria-label="Show password">${uiIcon('eye')}</button></div></label>
                ${field('confirmPassword','Confirm password','password','','required minlength="10" autocomplete="new-password"')}
                ${field('phone','Phone number','tel','','required autocomplete="tel" inputmode="numeric" minlength="11" maxlength="11" pattern="[0-9]{11}" title="Enter exactly 11 digits, e.g. 09171234567" placeholder="09171234567"')}
                ${field('birth_date','Birthday','date','',`required autocomplete="bday" max="${esc(S.registrationDate || '')}" aria-describedby="registration-age-hint"`)}
            </div>
            <p class="registration-hint">Use at least 10 characters for your password.</p>
            <p id="registration-age-hint" class="registration-hint" aria-live="polite">You must be at least ${S.minimumCustomerAge} years old to register.</p>
        </fieldset>
        <fieldset class="registration-step" data-registration-step="2" hidden>
            <legend class="sr-only">Delivery address</legend>
            <p class="registration-intro">Where should we deliver your finds?</p>
            <div class="registration-grid registration-address">
                ${field('house_number','House / unit number','text','','required autocomplete="address-line1" maxlength="100" placeholder="123 / Unit 4B"')}
                ${field('street','Street','text','','required autocomplete="address-line2" maxlength="150"')}
                ${window.customerLocations.fields()}
                ${field('postal_code','Postal code','text','','required autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="e.g. 1611"')}
            </div>
            <p class="registration-hint registration-saved">${uiIcon('pin')} Saved as your default Home address. You can edit it later.</p>
        </fieldset>
        <div class="registration-actions">
            <button type="button" class="btn ghost" data-action="registration-back" hidden>Back</button>
            <button class="btn primary" type="submit">Continue to address →</button>
        </div>
        <p class="registration-caption">Step <span data-registration-number>1</span> of 2 · Just the essentials to get started.</p>`;
    const subtitle = form.closest('.auth-form-card').querySelector(':scope > .muted');
    if (subtitle) subtitle.textContent = 'A little about you. A place for your next find.';
    const footnote = form.closest('.auth-form-card').querySelector('.auth-footnote');
    if (footnote) footnote.hidden = true;
    form.dataset.registrationStep = '1';
    void window.customerLocations.mount(form);
}

function setRegistrationStep(form, step, focus = true) {
    form.dataset.registrationStep = String(step);
    form.querySelectorAll('[data-registration-step]').forEach(section => {
        section.hidden = Number(section.dataset.registrationStep) !== step;
    });
    form.querySelectorAll('[data-registration-indicator]').forEach(indicator => {
        const number = Number(indicator.dataset.registrationIndicator);
        indicator.classList.toggle('is-complete', number < step);
        if (number === step) indicator.setAttribute('aria-current', 'step');
        else indicator.removeAttribute('aria-current');
    });
    form.querySelector('[data-action="registration-back"]').hidden = step === 1;
    form.querySelector('[type="submit"]').textContent = step === 1 ? 'Continue to address →' : 'Create account';
    form.querySelector('[data-registration-number]').textContent = step;
    form.querySelector('#auth-error').textContent = '';
    if (focus) {
        const first = form.querySelector(`:scope > fieldset[data-registration-step="${step}"] input, :scope > fieldset[data-registration-step="${step}"] select`);
        first?.focus({ preventScroll:true });
        if (form.getBoundingClientRect().top < 0) form.scrollIntoView({ behavior:'smooth', block:'start' });
    }
}

function validateRegistrationFields(form, step) {
    const controls = form.querySelectorAll(`:scope > fieldset[data-registration-step="${step}"] input, :scope > fieldset[data-registration-step="${step}"] select`);
    for (const control of controls) {
        if (!control.checkValidity()) {
            setRegistrationStep(form, step, false);
            control.reportValidity();
            return false;
        }
    }
    return true;
}
