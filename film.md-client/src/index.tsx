import "./index.css";
import "./i18n";
import { createRoot } from "react-dom/client";
import { App } from "./App";
import { stripHashRouteFromUrl } from "./lib/url";

stripHashRouteFromUrl();
createRoot(document.getElementById("root") as HTMLElement).render(<App />);
