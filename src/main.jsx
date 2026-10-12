import React from "react";
import { createRoot } from "react-dom/client";
import { OrderComplete } from "./components/OrderComplete";
import { App } from "./App.jsx";
import "./styles.css";

createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    {location.pathname === "/order-complete.html" ? <OrderComplete brand="SHEM" /> : <App />}
  </React.StrictMode>,
);
