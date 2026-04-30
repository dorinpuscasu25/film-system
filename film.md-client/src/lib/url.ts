export function stripHashRouteFromUrl() {
  const { pathname, search, hash } = window.location;

  if (!hash || hash === "#") {
    return;
  }

  if (hash === "#/" || hash.startsWith("#/")) {
    const hashTarget = hash.slice(1);
    const nextUrl = hashTarget === "/" ? `${pathname}${search}` : hashTarget;
    window.history.replaceState(null, "", nextUrl);
    return;
  }

  window.history.replaceState(null, "", `${pathname}${search}`);
}
