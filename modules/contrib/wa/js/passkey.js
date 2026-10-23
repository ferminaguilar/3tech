(function ($, Drupal, drupalSettings) {

  'use strict';

  // Helper to decode Base64URL to ArrayBuffer
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

  // Helper to encode ArrayBuffer to Base64URL
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

  // Helper to validate HTTP response and parse JSON
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
          throw new Error(msg);
        }
        return data;
      });
  }

  function getCsrfToken() {
    const settingsToken = drupalSettings.wa && drupalSettings.wa.passkeyDeleteToken;

    if (settingsToken) {
      return Promise.resolve(settingsToken);
    }

    return Promise.reject(new Error('Missing passkey CSRF token'));
  }

  function getOptionsToken(key) {
    const settingsToken = drupalSettings.wa && drupalSettings.wa[key];
    if (settingsToken) {
      return Promise.resolve(settingsToken);
    }
    return Promise.reject(new Error('Missing passkey options CSRF token'));
  }

  function getRoute(key) {
    const route = drupalSettings.wa && drupalSettings.wa.routes && drupalSettings.wa.routes[key];
    if (route) {
      return route;
    }
    // Fallback for pages where routes might not be populated (though they should be if the library is loaded)
    // or if the user hasn't updated the module code yet.
    console.warn('Missing route definition for ' + key + ', falling back to default.');
    const defaults = {
      'loginOptions': '/wa/login/options',
      'loginVerify': '/wa/login/verify',
      'registerOptions': '/wa/register/options',
      'registerVerify': '/wa/register/verify',
      'passkeyDelete': '/wa/passkey/delete'
    };
    return defaults[key];
  }

  Drupal.behaviors.waPasskey = {
    attach: function (context, settings) {
      // Registration
      const registerBtn = once('wa-register', '#register-passkey', context);
      registerBtn.forEach(element => {
        element.addEventListener('click', function (e) {
          e.preventDefault();

          getOptionsToken('registerOptionsToken')
            .then(csrfToken => {
              return fetch(getRoute('registerOptions'), {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': csrfToken,
                  'X-Requested-With': 'XMLHttpRequest'
                },
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
                  transports: transports,
                },
              };

              return getOptionsToken('registerVerifyToken')
                .then(csrfToken => fetch(getRoute('registerVerify'), {
                  method: 'POST',
                  headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                  },
                  body: JSON.stringify(response)
                }));
            })
            .then(validateResponse)
            .then(data => {
              if (data.status === 'ok') {
                alert('Passkey registered successfully!');
                location.reload();
              } else {
                let msg = 'Registration failed.';
                if (data.ticket_id) {
                  msg += ' (Ticket ID: ' + data.ticket_id + ')';
                } else if (data.message) {
                  msg += ' ' + data.message;
                }
                alert(msg);
              }
            })
            .catch(err => {
              console.error('Passkey registration error:', err.message);
              alert('Error registering passkey: ' + err.message);
            });
        });
      });

      // Delete passkey
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

          getCsrfToken()
            .then(csrfToken => {
              const deleteUrl = getRoute('passkeyDelete') || '/wa/passkey/delete';

              return fetch(deleteUrl, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'Accept': 'application/json',
                  'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ id: credentialId }),
                credentials: 'include'
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

      // Login
      const loginBtn = once('wa-login', '#wa-login-btn', context);
      loginBtn.forEach(element => {
        element.addEventListener('click', function (e) {
          e.preventDefault();

          fetch(getRoute('loginOptions'), {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
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
                  userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null,
                },
              };

              return fetch(getRoute('loginVerify'), {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(response)
              });
            })
            .then(response => {
              if (response.redirected) {
                window.location.href = response.url;
                return null;
              }
              return response.json()
                .catch(() => {
                  // JSON parsing failed (e.g., HTML error page)
                  throw new Error('Server error: ' + response.status + ' ' + response.statusText);
                })
                .then(data => {
                  if (!response.ok) {
                    // Server returned an error response with JSON body
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
              if (!data) return;
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
              // failure. This happens when a module (e.g. login_destination)
              // calls $response->send() with an external URL inside
              // hook_user_login(), which triggers fastcgi_finish_request()
              // and commits the redirect before we can intercept it. The
              // session cookie IS included in that committed response, so the
              // user is already authenticated — reloading reveals that state.
              if (err instanceof TypeError) {
                window.location.reload();
                return;
              }
              // Log only the message, not the full Error object, to avoid
              // exposing the JavaScript call stack in DevTools.
              console.error('Passkey login error:', err.message);
              alert(err.message || 'An error occurred during login.');
            });
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
