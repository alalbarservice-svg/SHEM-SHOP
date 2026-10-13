import React from "react";
import { createRoot } from "react-dom/client";
import { OrderComplete } from "./components/OrderComplete";
import { App } from "./ShimiApp.jsx";
createRoot(document.getElementById("root")).render(<React.StrictMode>{location.pathname === "/order-complete.html" ? <OrderComplete brand="SHIMI" /> : <App/>}</React.StrictMode>);
