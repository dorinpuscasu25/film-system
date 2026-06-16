// Runtime config — edit for your deployment.
(function () {
  var host = (location && location.hostname) || '';
  // On a veezify.com host the API allows the request directly (CORS whitelisted).
  // Anywhere else (local test server, other host) we go through a same-origin
  // proxy at /api/v1 to avoid CORS — see serve.py.
  var onVeezify = /veezify\.com$/i.test(host);

  window.FILMOTECA_CONFIG = {
    API_BASE: onVeezify ? 'https://filmmd-api.veezify.com/api/v1' : '/api/v1',
    WEB_BASE: 'https://film.veezify.com',
    POLL_INTERVAL: 5,
  };
})();
