(function ($, Drupal, drupalSettings) {

  'use strict';

  // Keep the session CSRF token in module closure scope rather than exposing it
  // in global drupalSettings.
  let cachedCsrfToken = null;

  // Helper to decode Base64URL to ArrayBuffer.
  function base64UrlToBuffer(base64url) {
    const padding = '='.repeat((4 - base64url.length % 4) % 4);
    const base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray.buffer;
  }

  // Helper to encode ArrayBuffer to Base64URL.
  function bufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return window.btoa(binary)
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=/g, '');
  }

  // Helper to validate HTTP response and parse JSON.
  function validateResponse(response) {
    return response.json()
      .catch(() => {
        // JSON parsing failed (e.g., HTML error page). Do not expose HTTP
        // status text to avoid leaking internal server details.
        throw new Error('An unexpected server error occurred. Please try again.');
      })
      .then(data => {
        if (!response.ok) {
          let msg = data.message || 'Request failed.';
          if (data.ticket_id) {
            msg += ' (Ticket ID: ' + data.ticket_id + ')';
          }
          const err = new Error(msg);
          err.data = data;
          throw err;
        }
        return data;
      });
  }

  function getRoute(key) {
    const route = drupalSettings.wa && drupalSettings.wa.routes && drupalSettings.wa.routes[key];
    if (route) {
      return route;
    }
    const defaults = {
      csrfToken: Drupal.url('session/token'),
      loginOptions: Drupal.url('wa/login/options'),
      loginVerify: Drupal.url('wa/login/verify'),
      registerReauth: Drupal.url('wa/register/reauth'),
      registerOptions: Drupal.url('wa/register/options'),
      registerVerify: Drupal.url('wa/register/verify'),
      passkeyDelete: Drupal.url('wa/passkey/delete')
    };
    return defaults[key];
  }

  // Fetches the session CSRF token from Drupal's /session/token endpoint for
  // routes protected by _csrf_request_header_token.
  function getSessionCsrfToken(forceRefresh) {
    if (cachedCsrfToken && !forceRefresh) {
      return Promise.resolve(cachedCsrfToken);
    }
    return fetch(getRoute('csrfToken'), {
      method: 'GET',
      credentials: 'same-origin'
    })
      .then(response => {
        if (!response.ok) {
          throw new Error('Unable to obtain CSRF token.');
        }
        return response.text();
      })
      .then(token => {
        const trimmed = token.trim();
        if (!trimmed) {
          throw new Error('Empty CSRF token received.');
        }
        cachedCsrfToken = trimmed;
        return cachedCsrfToken;
      });
  }

  // Appends the login page's ?destination= query parameter (if any) to a URL.
  function appendCurrentDestination(url) {
    const params = new URLSearchParams(window.location.search);
    const destination = params.get('destination');
    if (!destination) {
      return url;
    }
    const separator = url.indexOf('?') === -1 ? '?' : '&';
    return url + separator + 'destination=' + encodeURIComponent(destination);
  }

  // Helper to display a status message in #passkey-status if present.
  function setStatusMessage(text) {
    const statusEl = document.getElementById('passkey-status');
    if (statusEl) {
      statusEl.textContent = text;
    }
  }

  // Re-authenticates using the current password or email code field if entered.
  function reauthIfCredentialsEntered(csrfToken) {
    const passwordInput = document.getElementById('wa-reauth-password');
    if (passwordInput && passwordInput.value) {
      const password = passwordInput.value;
      return fetch(getRoute('registerReauth'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          method: 'password',
          password: password
        })
      })
        .then(validateResponse)
        .then(() => {
          passwordInput.value = '';
          return true;
        });
    }

    const emailCodeInput = document.getElementById('wa-reauth-email-code');
    if (emailCodeInput && emailCodeInput.value.trim()) {
      const code = emailCodeInput.value.trim();
      return fetch(getRoute('registerReauth'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          method: 'email_verify',
          code: code
        })
      })
        .then(validateResponse)
        .then(() => {
          emailCodeInput.value = '';
          return true;
        });
    }

    return Promise.resolve(false);
  }

  Drupal.behaviors.waPasskey = {
    attach: function (context, settings) {
      // Send a one-time email verification code for SSO-only accounts.
      const reauthEmailSendBtn = once('wa-reauth-email-send', '#wa-reauth-email-send', context);
      reauthEmailSendBtn.forEach(element => {
        element.addEventListener('click', function (e) {
          e.preventDefault();
          getSessionCsrfToken()
            .then(csrfToken => {
              return fetch(getRoute('registerReauth'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': csrfToken,
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ method: 'email_send' })
              });
            })
            .then(validateResponse)
            .then(() => {
              setStatusMessage(Drupal.t('A verification code has been sent to your email. Enter it below and click "Add New Passkey".'));
              const codeInput = document.getElementById('wa-reauth-email-code');
              if (codeInput) {
                codeInput.focus();
              }
            })
            .catch(err => {
              console.error('Passkey email re-auth error:', err.message);
              alert(err.message || 'Unable to send verification code.');
            });
        });
      });
      // Re-authenticate with an existing passkey before registering a new one.
      const reauthPasskeyBtn = once('wa-reauth-passkey', '#wa-reauth-passkey', context);
      reauthPasskeyBtn.forEach(element => {
        element.addEventListener('click', function (e) {
          e.preventDefault();
          let csrfToken = null;

          getSessionCsrfToken()
            .then(token => {
              csrfToken = token;
              return fetch(getRoute('registerReauth'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': csrfToken,
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ method: 'passkey_options' })
              });
            })
            .then(validateResponse)
            .then(options => {
              options.challenge = base64UrlToBuffer(options.challenge);
              if (Array.isArray(options.allowCredentials)) {
                options.allowCredentials = options.allowCredentials.map(cred => Object.assign({}, cred, {
                  id: base64UrlToBuffer(cred.id)
                }));
              }
              return navigator.credentials.get({ publicKey: options });
            })
            .then(credential => {
              const assertion = {
                id: credential.id,
                rawId: bufferToBase64Url(credential.rawId),
                type: credential.type,
                response: {
                  authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
                  clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                  signature: bufferToBase64Url(credential.response.signature),
                  userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null
                }
              };
              return fetch(getRoute('registerReauth'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': csrfToken,
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                  method: 'passkey',
                  credential: assertion
                })
              });
            })
            .then(validateResponse)
            .then(() => {
              setStatusMessage(Drupal.t('Identity verified. You can now click "Add New Passkey".'));
            })
            .catch(err => {
              console.error('Passkey re-authentication error:', err.message);
              alert(err.message || 'Verification failed.');
            });
        });
      });

      // Registration.
      const registerBtn = once('wa-register', '#register-passkey', context);
      registerBtn.forEach(element => {
        element.addEventListener('click', function (e) {
          e.preventDefault();
          let csrfToken = null;

          getSessionCsrfToken()
            .then(token => {
              csrfToken = token;
              return reauthIfCredentialsEntered(csrfToken);
            })
            .then(() => {
              return fetch(getRoute('registerOptions'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': csrfToken,
                  'X-Requested-With': 'XMLHttpRequest'
                }
              });
            })
            .then(validateResponse)
            .then(options => {
              if (options.status === 'error') {
                let msg = options.message || 'Error initializing registration.';
                if (options.ticket_id) {
                  msg += ' (Ticket ID: ' + options.ticket_id + ')';
                }
                throw new Error(msg);
              }

              options.challenge = base64UrlToBuffer(options.challenge);
              options.user.id = base64UrlToBuffer(options.user.id);
              if (Array.isArray(options.excludeCredentials)) {
                options.excludeCredentials = options.excludeCredentials.map(cred => Object.assign({}, cred, {
                  id: base64UrlToBuffer(cred.id)
                }));
              }

              return navigator.credentials.create({ publicKey: options });
            })
            .then(credential => {
              const transports = typeof credential.response.getTransports === 'function'
                ? credential.response.getTransports()
                : [];
              const response = {
                id: credential.id,
                rawId: bufferToBase64Url(credential.rawId),
                type: credential.type,
                response: {
                  attestationObject: bufferToBase64Url(credential.response.attestationObject),
                  clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                  transports: transports
                }
              };

              return fetch(getRoute('registerVerify'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': csrfToken,
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(response)
              });
            })
            .then(validateResponse)
            .then(data => {
              if (data.status === 'ok') {
                alert('Passkey registered successfully!');
                location.reload();
              }
              else {
                let msg = 'Registration failed.';
                if (data.ticket_id) {
                  msg += ' (Ticket ID: ' + data.ticket_id + ')';
                }
                else if (data.message) {
                  msg += ' ' + data.message;
                }
                alert(msg);
              }
            })
            .catch(err => {
              if (err.data && err.data.reauth_required) {
                const passwordInput = document.getElementById('wa-reauth-password');
                const emailCodeInput = document.getElementById('wa-reauth-email-code');
                if (passwordInput) {
                  passwordInput.focus();
                }
                else if (emailCodeInput) {
                  emailCodeInput.focus();
                }
              }
              console.error('Passkey registration error:', err.message);
              alert('Error registering passkey: ' + err.message);
            });
        });
      });

      // Delete passkey.
      const deleteButtons = once('wa-delete', '.passkey-delete', context);
      deleteButtons.forEach(button => {
        button.addEventListener('click', function (e) {
          e.preventDefault();
          const credentialId = button.dataset.credentialId;
          if (!credentialId) {
            return;
          }

          const confirmed = window.confirm('Are you sure you want to delete this passkey? This action cannot be undone.');
          if (!confirmed) {
            return;
          }

          getSessionCsrfToken()
            .then(csrfToken => {
              return fetch(getRoute('passkeyDelete'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/json',
                  'Accept': 'application/json',
                  'X-CSRF-Token': csrfToken,
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ id: credentialId })
              });
            })
            .then(validateResponse)
            .then(data => {
              if (data.status === 'ok') {
                location.reload();
              }
              else {
                let msg = data.message || 'Delete failed.';
                if (data.ticket_id) {
                  msg += ' (Ticket ID: ' + data.ticket_id + ')';
                }
                alert(msg);
              }
            })
            .catch(err => {
              console.error('Passkey delete error:', err.message);
              alert('Error deleting passkey: ' + err.message);
            });
        });
      });

      // Login.
      const loginBtn = once('wa-login', '#wa-login-btn', context);
      loginBtn.forEach(element => {
        element.addEventListener('click', function (e) {
          e.preventDefault();

          fetch(getRoute('loginOptions'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
            .then(validateResponse)
            .then(options => {
              if (options.status === 'error') {
                let msg = options.message || 'Error initializing login.';
                if (options.ticket_id) {
                  msg += ' (Ticket ID: ' + options.ticket_id + ')';
                }
                throw new Error(msg);
              }

              options.challenge = base64UrlToBuffer(options.challenge);

              return navigator.credentials.get({ publicKey: options });
            })
            .then(credential => {
              const response = {
                id: credential.id,
                rawId: bufferToBase64Url(credential.rawId),
                type: credential.type,
                response: {
                  authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
                  clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                  signature: bufferToBase64Url(credential.response.signature),
                  userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null
                }
              };

              const verifyUrl = appendCurrentDestination(getRoute('loginVerify'));
              return fetch(verifyUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(response)
              });
            })
            .then(response => {
              if (response.redirected) {
                window.location.assign(response.url);
                return null;
              }
              return response.json()
                .catch(() => {
                  throw new Error('Server error: ' + response.status + ' ' + response.statusText);
                })
                .then(data => {
                  if (!response.ok) {
                    let msg = data.message || 'Login failed.';
                    if (data.ticket_id) {
                      msg += ' (Ticket ID: ' + data.ticket_id + ')';
                    }
                    throw new Error(msg);
                  }
                  return data;
                });
            })
            .then(data => {
              if (!data) {
                return;
              }
              if (data.redirect_url) {
                window.location.assign(data.redirect_url);
                return;
              }
              if (data.status !== 'ok') {
                let msg = 'Login failed: ' + (data.message || 'Unknown error');
                if (data.ticket_id) {
                  msg += ' (Ticket ID: ' + data.ticket_id + ')';
                }
                alert(msg);
              }
            })
            .catch(err => {
              // A TypeError from fetch() usually means a network or CORS
              // failure when a hook_user_login module committed a response
              // directly; reloading reveals the authenticated state.
              if (err instanceof TypeError) {
                window.location.reload();
                return;
              }
              console.error('Passkey login error:', err.message);
              alert(err.message || 'An error occurred during login.');
            });
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
