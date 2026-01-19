(() => {
  if (window.vkbmAuthPasswordToggleInitialized) {
    return;
  }
  window.vkbmAuthPasswordToggleInitialized = true;

  document.addEventListener('click', (event) => {
    const button = event.target.closest('.vkbm-auth-form__password-toggle');
    if (!button) {
      return;
    }

    const fieldId = button.getAttribute('aria-controls');
    const field = fieldId ? document.getElementById(fieldId) : null;
    if (!field) {
      return;
    }

    const isVisible = field.type === 'text';
    field.type = isVisible ? 'password' : 'text';
    button.setAttribute('aria-pressed', (!isVisible).toString());

    const label = button.querySelector('.vkbm-auth-form__password-toggle-label');
    if (label) {
      label.textContent = isVisible
        ? button.getAttribute('data-show-label')
        : button.getAttribute('data-hide-label');
    }
  });
})();
