import "./index.css";
import "./i18n";
import React from "react";
import { render } from "react-dom";
import { App } from "./App";
import { stripHashRouteFromUrl } from "./lib/url";

stripHashRouteFromUrl();
render(<App />, document.getElementById("root"));
