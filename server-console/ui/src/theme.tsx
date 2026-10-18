import React, { useEffect, useState } from "react";

type Theme = "system" | "light" | "dark";
const storageKey = "ustar-console-theme";
const isTheme = (value: string | null): value is Theme =>
  value === "system" || value === "light" || value === "dark";

function readTheme(): Theme {
  try {
    const value = localStorage.getItem(storageKey);
    return isTheme(value) ? value : "system";
  } catch {
    return "system";
  }
}

let preference = readTheme();
function applyTheme(theme: Theme) {
  document.documentElement.dataset.theme =
    theme === "system"
      ? window.matchMedia("(prefers-color-scheme: dark)").matches
        ? "dark"
        : "light"
      : theme;
}

// Apply before React mounts, including the connection and login screens.
applyTheme(preference);

export function ThemeToggle() {
  const [theme, setTheme] = useState<Theme>(preference);
  useEffect(() => {
    const media = window.matchMedia("(prefers-color-scheme: dark)");
    const onSystemChange = () => {
      if (preference === "system") applyTheme(preference);
    };
    const onStorage = (event: StorageEvent) => {
      if (event.key !== storageKey && event.key !== null) return;
      preference = isTheme(event.newValue) ? event.newValue : "system";
      setTheme(preference);
      applyTheme(preference);
    };
    media.addEventListener("change", onSystemChange);
    window.addEventListener("storage", onStorage);
    applyTheme(preference);
    return () => {
      media.removeEventListener("change", onSystemChange);
      window.removeEventListener("storage", onStorage);
    };
  }, []);

  return (
    <label className="theme-toggle">
      <span>Тема</span>
      <select
        aria-label="Тема оформления"
        value={theme}
        onChange={(event) => {
          preference = event.currentTarget.value as Theme;
          setTheme(preference);
          applyTheme(preference);
          try {
            localStorage.setItem(storageKey, preference);
          } catch {
            // Switching still works when browser storage is unavailable.
          }
        }}
      >
        <option value="system">Как в системе</option>
        <option value="light">Светлая</option>
        <option value="dark">Тёмная</option>
      </select>
    </label>
  );
}
