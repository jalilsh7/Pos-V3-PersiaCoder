import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { RouterProvider } from "@tanstack/react-router";
import { getRouter } from "./router";
import { AppearanceProvider } from "./lib/appearance";
import "./styles.css";

const rootElement = document.getElementById("root");
if (!rootElement) throw new Error("Missing #root mount element");

const router = getRouter();

createRoot(rootElement).render(
  <StrictMode>
    <AppearanceProvider><RouterProvider router={router} /></AppearanceProvider>
  </StrictMode>,
);
