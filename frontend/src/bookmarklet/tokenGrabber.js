/**
 * Bookmarklet: captures the LaLiga Fantasy access_token/refresh_token from
 * the browser's own network traffic while the user is logged in at
 * fantasy.laliga.com, without ever touching their LaLiga password.
 *
 * Why a bookmarklet and not real OAuth: LaLiga's Azure B2C client_ids only
 * accept pre-registered redirect_uris (miliga.laliga.com or the native app's
 * authredirect:// scheme) — confirmed empirically (AADB2C90006 redirect_uri
 * mismatch) against a redirect_uri we control. A browser can't complete that
 * flow. This is the same "capture a token from an already-authenticated
 * session" idea as pasting it from DevTools, just automated: it monkey-
 * patches fetch/XHR to watch for the Authorization header LaLiga's own
 * frontend sends on requests to fantasy-api.llt-services.com, and the
 * refresh_token in the response body of the login.laliga.es token exchange.
 * Everything happens client-side, in the user's own browser tab — nothing
 * is sent anywhere but back into this app's onboarding form.
 *
 * Kept here as the readable source of truth. `bookmarkletHref` below is the
 * same code compacted onto one line, since some browsers mishandle newlines
 * in a saved bookmark's URL.
 */
export const tokenGrabberSource = `
(function () {
  if (window.__fantasyAssistantGrabber) { window.__fantasyAssistantGrabber.show(); return; }

  var state = { access: null, refresh: null };

  var panel = document.createElement('div');
  panel.style.cssText = 'position:fixed;top:16px;right:16px;z-index:2147483647;width:320px;background:#0b0f14;color:#e7edf5;border:1px solid #223044;border-radius:12px;padding:16px;font:13px/1.4 -apple-system,sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.5)';
  panel.innerHTML =
    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">' +
      '<strong>Fantasy Assistant</strong>' +
      '<button id="fa-close" style="background:none;border:none;color:#8ea0b8;cursor:pointer;font-size:16px;line-height:1">\\u00d7</button>' +
    '</div>' +
    '<p style="margin:0 0 10px;color:#8ea0b8">Navega per aquesta pestanya (mercat, plantilla...) fins que aparegui el token.</p>' +
    '<div style="margin-bottom:10px">' +
      '<div style="color:#8ea0b8;margin-bottom:2px">access_token</div>' +
      '<div style="display:flex;gap:6px">' +
        '<input id="fa-access" readonly style="flex:1;min-width:0;background:#121821;border:1px solid #223044;color:#e7edf5;border-radius:6px;padding:6px;font-family:monospace;font-size:11px" placeholder="esperant petici\\u00f3\\u2026">' +
        '<button id="fa-copy-access" style="background:#3ea6ff;border:none;color:#0b0f14;border-radius:6px;padding:0 10px;cursor:pointer;font-weight:600">Copia</button>' +
      '</div>' +
    '</div>' +
    '<div>' +
      '<div style="color:#8ea0b8;margin-bottom:2px">refresh_token</div>' +
      '<div style="display:flex;gap:6px">' +
        '<input id="fa-refresh" readonly style="flex:1;min-width:0;background:#121821;border:1px solid #223044;color:#e7edf5;border-radius:6px;padding:6px;font-family:monospace;font-size:11px" placeholder="esperant login\\u2026">' +
        '<button id="fa-copy-refresh" style="background:#3ea6ff;border:none;color:#0b0f14;border-radius:6px;padding:0 10px;cursor:pointer;font-weight:600">Copia</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(panel);
  panel.querySelector('#fa-close').onclick = function () { panel.remove(); };
  panel.querySelector('#fa-copy-access').onclick = function () { navigator.clipboard.writeText(state.access || ''); };
  panel.querySelector('#fa-copy-refresh').onclick = function () { navigator.clipboard.writeText(state.refresh || ''); };

  function setAccess(token) {
    if (!token || state.access === token) return;
    state.access = token;
    panel.querySelector('#fa-access').value = token;
  }
  function setRefresh(token) {
    if (!token || state.refresh === token) return;
    state.refresh = token;
    panel.querySelector('#fa-refresh').value = token;
  }

  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    try {
      var url = typeof input === 'string' ? input : (input && input.url);
      var headers = (init && init.headers) || (input && input.headers) || {};
      var auth = headers instanceof Headers ? headers.get('Authorization') : (headers['Authorization'] || headers['authorization']);
      if (url && url.indexOf('llt-services.com') !== -1 && auth && auth.indexOf('Bearer ') === 0) {
        setAccess(auth.slice(7));
      }
    } catch (e) {}
    var result = origFetch.apply(this, arguments);
    try {
      var url2 = typeof input === 'string' ? input : (input && input.url);
      if (url2 && url2.indexOf('login.laliga.es') !== -1) {
        result.then(function (res) {
          res.clone().json().then(function (body) {
            if (body && body.refresh_token) setRefresh(body.refresh_token);
          }).catch(function () {});
        });
      }
    } catch (e) {}
    return result;
  };

  var origOpen = XMLHttpRequest.prototype.open;
  var origSetHeader = XMLHttpRequest.prototype.setRequestHeader;
  var origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__fa_url = url;
    return origOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
    if (name && name.toLowerCase() === 'authorization' && this.__fa_url && String(this.__fa_url).indexOf('llt-services.com') !== -1 && value.indexOf('Bearer ') === 0) {
      setAccess(value.slice(7));
    }
    return origSetHeader.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    if (this.__fa_url && String(this.__fa_url).indexOf('login.laliga.es') !== -1) {
      this.addEventListener('load', function () {
        try {
          var body = JSON.parse(this.responseText);
          if (body && body.refresh_token) setRefresh(body.refresh_token);
        } catch (e) {}
      });
    }
    return origSend.apply(this, arguments);
  };

  window.__fantasyAssistantGrabber = { show: function () { panel.style.display = 'block'; } };
})();
`;

export const bookmarkletHref = 'javascript:' + encodeURIComponent(tokenGrabberSource.replace(/\n\s*/g, ' ').trim());
