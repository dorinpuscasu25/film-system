/** Thin API client for the FILMOTECA backend. Token kept in localStorage. */
var Api = (function () {
  var BASE = window.FILMOTECA_CONFIG.API_BASE;
  var TOKEN_KEY = 'filmoteca_tv_token';

  function getToken() { return localStorage.getItem(TOKEN_KEY); }
  function setToken(t) { localStorage.setItem(TOKEN_KEY, t); }
  function clearToken() { localStorage.removeItem(TOKEN_KEY); }

  function request(path, opts) {
    opts = opts || {};
    var headers = { 'Accept': 'application/json' };
    if (opts.body) headers['Content-Type'] = 'application/json';
    if (opts.auth) {
      var t = getToken();
      if (t) headers['Authorization'] = 'Bearer ' + t;
    }
    return fetch(BASE + path, {
      method: opts.method || 'GET',
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
        return { ok: res.ok, status: res.status, data: data };
      });
    }).catch(function (err) {
      // Network / CORS failure — return a uniform object so callers don't hang.
      return { ok: false, status: 0, data: null, networkError: true, error: String(err) };
    });
  }

  return {
    getToken: getToken,
    setToken: setToken,
    clearToken: clearToken,

    // --- Device pairing (RFC 8628) ---
    requestDeviceCode: function () {
      return request('/auth/device/code', {
        method: 'POST',
        body: { device_name: 'Hisense VIDAA TV' },
      });
    },
    pollDeviceToken: function (deviceCode) {
      return request('/auth/device/token', {
        method: 'POST',
        body: { device_code: deviceCode },
      });
    },

    // --- Catalogue (public) ---
    home: function () { return request('/public/home'); },
    catalog: function (type, query) {
      var qs = '?page_size=60';
      if (type) qs += '&type=' + encodeURIComponent(type);
      if (query) qs += '&query=' + encodeURIComponent(query);
      return request('/public/catalog' + qs);
    },
    content: function (slug) {
      return request('/public/content/' + encodeURIComponent(slug));
    },

    // --- Playback (auth) ---
    playback: function (slug, episodeId) {
      var qs = episodeId ? '?episode_id=' + encodeURIComponent(episodeId) : '';
      return request('/storefront/content/' + encodeURIComponent(slug) + '/playback' + qs, { auth: true });
    },
  };
})();
