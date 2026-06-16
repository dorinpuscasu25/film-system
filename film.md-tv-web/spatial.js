/**
 * Minimal but solid spatial-navigation engine for TV remotes.
 *
 * Any element with class "focusable" participates. Arrow keys move focus to the
 * geometrically nearest focusable in that direction; OK/Enter clicks it; Back
 * fires a "tv-back" event the app handles. Designed to run on the older WebKit/
 * Chromium engines used by VIDAA (Hisense), Tizen (Samsung) and webOS (LG).
 */
var Spatial = (function () {
  var current = null;

  // Remote key codes vary across TV platforms — cover the common ones.
  var KEY = {
    left: [37], up: [38], right: [39], down: [40],
    enter: [13, 32, 29443],            // OK / Enter (29443 = Tizen OK on some sets)
    back: [8, 27, 461, 10009],         // Backspace / Esc / VIDAA / Tizen Return
  };

  function inSet(code, set) { return set.indexOf(code) !== -1; }

  function visible(el) {
    if (el.offsetParent === null && el.getClientRects().length === 0) return false;
    var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  function focusables() {
    var list = document.querySelectorAll('.focusable');
    var out = [];
    for (var i = 0; i < list.length; i++) {
      if (visible(list[i])) out.push(list[i]);
    }
    return out;
  }

  function center(el) {
    var r = el.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2, r: r };
  }

  function setFocus(el) {
    if (!el) return;
    if (current && current !== el) current.classList.remove('focused');
    current = el;
    el.classList.add('focused');
    // Keep the focused item on screen.
    if (el.scrollIntoView) {
      try { el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' }); }
      catch (e) { el.scrollIntoView(); }
    }
  }

  function move(dir) {
    if (!current) { focusFirst(); return; }
    var from = center(current);
    var best = null, bestCost = Infinity;
    var all = focusables();
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (el === current) continue;
      var c = center(el);
      var dx = c.x - from.x, dy = c.y - from.y;
      var primary, cross, ok;
      if (dir === 'left')  { ok = c.r.right <= from.r.left + 8;  primary = -dx; cross = Math.abs(dy); }
      else if (dir === 'right') { ok = c.r.left >= from.r.right - 8; primary = dx;  cross = Math.abs(dy); }
      else if (dir === 'up')   { ok = c.r.bottom <= from.r.top + 8;  primary = -dy; cross = Math.abs(dx); }
      else { ok = c.r.top >= from.r.bottom - 8; primary = dy; cross = Math.abs(dx); }
      if (!ok || primary <= 0) continue;
      // Favor alignment on the cross axis, then proximity on the primary axis.
      var cost = primary + cross * 2.5;
      if (cost < bestCost) { bestCost = cost; best = el; }
    }
    if (best) setFocus(best);
  }

  function onKey(e) {
    var code = e.keyCode || e.which;
    if (inSet(code, KEY.back)) {
      e.preventDefault();
      window.dispatchEvent(new CustomEvent('tv-back'));
      return;
    }
    if (inSet(code, KEY.enter)) {
      e.preventDefault();
      if (current) current.click();
      return;
    }
    if (inSet(code, KEY.left))  { e.preventDefault(); move('left'); }
    else if (inSet(code, KEY.right)) { e.preventDefault(); move('right'); }
    else if (inSet(code, KEY.up))    { e.preventDefault(); move('up'); }
    else if (inSet(code, KEY.down))  { e.preventDefault(); move('down'); }
  }

  function focusFirst(selector) {
    var el = selector ? document.querySelector(selector) : null;
    if (el && visible(el)) { setFocus(el); return; }
    var all = focusables();
    if (all.length) setFocus(all[0]);
  }

  function init() {
    window.addEventListener('keydown', onKey, true);
  }

  return {
    init: init,
    focusFirst: focusFirst,
    setFocus: setFocus,
    refresh: function () { /* focusables are queried live; nothing to cache */ },
    current: function () { return current; },
    clear: function () { if (current) current.classList.remove('focused'); current = null; },
  };
})();
