/* FILMOTECA.md — TV web app (VIDAA / Tizen / webOS). Vanilla JS, remote-driven. */
(function () {
  // CustomEvent polyfill for older TV browsers.
  if (typeof window.CustomEvent !== 'function') {
    window.CustomEvent = function (type, params) {
      params = params || {};
      var e = document.createEvent('CustomEvent');
      e.initCustomEvent(type, !!params.bubbles, !!params.cancelable, params.detail);
      return e;
    };
  }

  var CFG = window.FILMOTECA_CONFIG;
  var root = document.getElementById('app');
  var overlay = document.getElementById('player-overlay');
  var frame = document.getElementById('player-frame');

  var stack = [];        // navigation stack: [{name, params}]
  var pollTimer = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function setScreen(html, focusSel) {
    root.innerHTML = html;
    // Defer focus until layout settles.
    setTimeout(function () { Spatial.focusFirst(focusSel); }, 30);
  }

  /* ---------------------------------------------------------------- Pairing */

  function showPairing() {
    setScreen(
      '<div class="screen center">' +
      '  <div class="brand-lg">FILMOTECA<span class="accent">.md</span></div>' +
      '  <div class="pair-title">Conectează televizorul</div>' +
      '  <div id="pair-body" class="pair-body">Se pregătește codul…</div>' +
      '</div>'
    );

    Api.requestDeviceCode().then(function (res) {
      if (!res.ok || !res.data) {
        document.getElementById('pair-body').innerHTML =
          '<div class="error">Nu am putut obține un cod. Verifică conexiunea.</div>' +
          '<div class="focusable btn" id="retry">Încearcă din nou</div>';
        bindClick('retry', showPairing);
        Spatial.focusFirst('#retry');
        return;
      }
      var d = res.data;
      var url = (d.verification_uri || (CFG.WEB_BASE + '/tv')).replace(/^https?:\/\//, '');
      document.getElementById('pair-body').innerHTML =
        '<div class="pair-step">1. Pe telefon sau computer deschide:</div>' +
        '<div class="pair-url">' + esc(url) + '</div>' +
        '<div class="pair-step">2. Introdu codul:</div>' +
        '<div class="pair-code">' + esc(d.user_code) + '</div>' +
        '<div class="pair-note">Lasă acest ecran deschis — televizorul se conectează automat.</div>';
      startPolling(d.device_code, (d.interval || CFG.POLL_INTERVAL) * 1000);
    });
  }

  function startPolling(deviceCode, intervalMs) {
    stopPolling();
    pollTimer = setInterval(function () {
      Api.pollDeviceToken(deviceCode).then(function (res) {
        if (res.ok && res.data && res.data.token) {
          stopPolling();
          Api.setToken(res.data.token);
          replaceWith('home');
        } else if (res.data && (res.data.error === 'expired_token' || res.data.error === 'access_denied')) {
          stopPolling();
          var body = document.getElementById('pair-body');
          if (body) {
            body.innerHTML = '<div class="error">Codul a expirat. Generează unul nou.</div>' +
              '<div class="focusable btn" id="retry">Cod nou</div>';
            bindClick('retry', showPairing);
            Spatial.focusFirst('#retry');
          }
        }
        // otherwise: authorization_pending / slow_down → keep polling
      });
    }, intervalMs);
  }

  function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

  /* ------------------------------------------------------------------- Home */

  function showHome() {
    setScreen(
      '<div class="topbar">' +
      '  <div class="brand">FILMOTECA<span class="accent">.md</span></div>' +
      '  <div class="spacer"></div>' +
      '  <div class="focusable chip" id="nav-logout">Ieși din cont</div>' +
      '</div>' +
      '<div id="home-body" class="screen"><div class="loading">Se încarcă…</div></div>'
    );
    bindClick('nav-logout', function () { Api.clearToken(); replaceWith('pairing'); });

    Api.home().then(function (res) {
      var body = document.getElementById('home-body');
      if (!res.ok || !res.data) { body.innerHTML = '<div class="error">Nu am putut încărca catalogul.</div>'; return; }
      var h = res.data;
      var hero = h.hero || (h.featured && h.featured[0]);
      var rows = buildRows(h);
      var html = '';
      if (hero) html += renderHero(hero);
      html += rows;
      body.innerHTML = html;
      Spatial.focusFirst('.focusable');
    });
  }

  function buildRows(h) {
    var rows = [];
    (h.sections || []).forEach(function (s) {
      if (s.items && s.items.length) rows.push({ title: s.title || 'Recomandate', items: s.items });
    });
    if (h.free_to_watch && h.free_to_watch.length) rows.push({ title: 'Gratis de vizionat', items: h.free_to_watch });
    if (h.latest && h.latest.length) rows.push({ title: 'Adăugate recent', items: h.latest });
    if (h.movies && h.movies.length) rows.push({ title: 'Filme', items: h.movies });
    if (h.series && h.series.length) rows.push({ title: 'Seriale', items: h.series });
    if (h.featured && h.featured.length) rows.push({ title: 'Promovate', items: h.featured });
    // de-dup by title
    var seen = {}, out = '';
    rows.forEach(function (r) {
      if (seen[r.title]) return; seen[r.title] = 1;
      out += renderRow(r.title, r.items);
    });
    return out;
  }

  function renderHero(c) {
    var bg = c.backdrop_url || c.hero_desktop_url || c.poster_url || '';
    return '<div class="hero" style="background-image:url(' + esc(bg) + ')">' +
      '<div class="hero-scrim"></div>' +
      '<div class="hero-content">' +
      '<div class="hero-title">' + esc(c.title || '') + '</div>' +
      '<div class="hero-desc">' + esc(c.short_description || '') + '</div>' +
      '<div class="focusable btn btn-primary hero-btn" data-slug="' + esc(c.slug) + '">▶ Vezi detalii</div>' +
      '</div></div>';
  }

  function renderRow(title, items) {
    var cards = items.map(function (c) {
      var poster = c.poster_url || '';
      return '<div class="card focusable" data-slug="' + esc(c.slug) + '" ' +
        'style="background-image:url(' + esc(poster) + ')">' +
        (poster ? '' : '<span class="card-fallback">' + esc(c.title || '') + '</span>') +
        '</div>';
    }).join('');
    return '<div class="row"><div class="row-title">' + esc(title) + '</div>' +
      '<div class="row-strip">' + cards + '</div></div>';
  }

  /* ----------------------------------------------------------------- Detail */

  function showDetail(slug) {
    setScreen('<div class="screen center"><div class="loading">Se încarcă…</div></div>');
    Api.content(slug).then(function (res) {
      if (!res.ok || !res.data) { setScreen('<div class="screen center"><div class="error">Titlu indisponibil.</div></div>'); return; }
      var c = res.data;
      var bg = c.hero_desktop_url || c.backdrop_url || c.poster_url || '';
      var meta = [c.release_year, c.runtime_minutes ? c.runtime_minutes + ' min' : null, c.age_rating,
        (c.genres || []).slice(0, 3).join(', ')].filter(Boolean).join('  ·  ');

      var episodes = '';
      var firstEp = null;
      if (c.seasons && c.seasons.length) {
        (c.seasons || []).forEach(function (s) {
          var eps = (s.episodes || []).map(function (e) {
            if (!firstEp) firstEp = e.id;
            return '<div class="focusable ep" data-slug="' + esc(c.slug) + '" data-ep="' + esc(e.id) + '">' +
              '<span class="ep-num">' + esc(('0' + (e.episode_number || 1)).slice(-2)) + '</span>' +
              '<span class="ep-title">' + esc(e.title || ('Episodul ' + (e.episode_number || 1))) + '</span></div>';
          }).join('');
          episodes += '<div class="ep-season">' + esc(s.title || ('Sezonul ' + (s.season_number || 1))) + '</div>' + eps;
        });
      }

      var watchLabel = firstEp ? '▶ Vizionează episodul 1' : (c.is_free ? '▶ Vizionează gratuit' : '▶ Vizionează');
      setScreen(
        '<div class="detail" style="background-image:url(' + esc(bg) + ')">' +
        '  <div class="detail-scrim"></div>' +
        '  <div class="detail-content">' +
        '    <div class="focusable chip back-chip" id="d-back">‹ Înapoi</div>' +
        '    <div class="detail-type">' + esc((c.type_label || '').toUpperCase()) + '</div>' +
        '    <div class="detail-title">' + esc(c.title || c.original_title || '') + '</div>' +
        '    <div class="detail-meta">' + esc(meta) + '</div>' +
        '    <div class="detail-desc">' + esc(c.description || c.short_description || '') + '</div>' +
        '    <div class="focusable btn btn-primary" id="d-watch" data-slug="' + esc(c.slug) + '" data-ep="' + esc(firstEp || '') + '">' + watchLabel + '</div>' +
        (episodes ? '<div class="episodes">' + episodes + '</div>' : '') +
        '  </div>' +
        '</div>'
      );
      Spatial.focusFirst('#d-watch');
    });
  }

  /* ----------------------------------------------------------------- Player */

  function playSlug(slug, episodeId) {
    setScreen('<div class="screen center"><div class="loading">Se pregătește redarea…</div></div>');
    Api.playback(slug, episodeId).then(function (res) {
      if (res.status === 401) { replaceWith('pairing'); return; }
      if (res.status === 403) { showBuyOnPhone(slug); return; }
      if (!res.ok || !res.data || !res.data.playback) { showDetail(slug); return; }
      var pb = res.data.playback;
      var src = pb.embed_url || pb.url;
      if (!src) { showBuyOnPhone(slug); return; }
      openPlayer(src);
    });
  }

  function openPlayer(src) {
    frame.setAttribute('src', src);
    overlay.classList.remove('hidden');
  }

  function closePlayer() {
    overlay.classList.add('hidden');
    frame.setAttribute('src', 'about:blank');
  }

  function isPlayerOpen() { return !overlay.classList.contains('hidden'); }

  function showBuyOnPhone(slug) {
    var url = (CFG.WEB_BASE + '/movie/' + slug).replace(/^https?:\/\//, '');
    setScreen(
      '<div class="screen center">' +
      '  <div class="buy-title">Acest titlu se cumpără online</div>' +
      '  <div class="buy-text">Finalizează achiziția pe telefon sau computer, apoi apasă „Verifică din nou".</div>' +
      '  <div class="buy-url">' + esc(url) + '</div>' +
      '  <div class="buy-actions">' +
      '    <div class="focusable btn btn-primary" id="buy-refresh" data-slug="' + esc(slug) + '">Verifică din nou</div>' +
      '    <div class="focusable btn" id="buy-back">Înapoi</div>' +
      '  </div>' +
      '</div>'
    );
    bindClick('buy-refresh', function () { playSlug(slug); });
    bindClick('buy-back', function () { showDetail(slug); });
    Spatial.focusFirst('#buy-refresh');
  }

  /* ------------------------------------------------------------- Navigation */

  function bindClick(id, fn) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', fn);
  }

  // Delegated clicks for dynamically rendered cards/buttons.
  root.addEventListener('click', function (e) {
    var t = e.target;
    while (t && t !== root && !t.classList.contains('focusable')) t = t.parentNode;
    if (!t || t === root) return;
    if (t.id === 'd-back') { back(); return; }
    if (t.id === 'd-watch') { playSlug(t.getAttribute('data-slug'), t.getAttribute('data-ep') || null); return; }
    if (t.classList.contains('ep')) { playSlug(t.getAttribute('data-slug'), t.getAttribute('data-ep')); return; }
    if (t.classList.contains('card') || t.classList.contains('hero-btn')) {
      var slug = t.getAttribute('data-slug');
      if (slug) navigate('detail', { slug: slug });
    }
  });

  function render() {
    var top = stack[stack.length - 1];
    if (!top) return;
    if (top.name === 'pairing') showPairing();
    else if (top.name === 'home') showHome();
    else if (top.name === 'detail') showDetail(top.params.slug);
  }

  function navigate(name, params) { stopPolling(); stack.push({ name: name, params: params || {} }); render(); }
  function replaceWith(name, params) { stopPolling(); stack = [{ name: name, params: params || {} }]; render(); }

  function back() {
    if (isPlayerOpen()) { closePlayer(); return; }
    stopPolling();
    if (stack.length > 1) { stack.pop(); render(); }
    else { try { window.close(); } catch (e) {} /* on home root: stay */ }
  }

  /* ------------------------------------------------------------------ Start */

  function start() {
    Spatial.init();
    window.addEventListener('tv-back', back);
    if (Api.getToken()) replaceWith('home');
    else replaceWith('pairing');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
