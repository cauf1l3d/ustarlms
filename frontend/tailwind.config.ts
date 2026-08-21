import type { Config } from "tailwindcss";

const config: Config = {
  content: ["./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        brand: "var(--c-primary)",
        accent: "var(--c-accent)",
        accentSoft: "var(--c-accent-soft)",
        surface: "var(--c-surface)",
        page: "var(--c-bg)",
        ink: "var(--c-text)",
        mut: "var(--c-muted)",
        ok: "var(--c-success)",
        warn: "var(--c-warning)",
      },
      borderRadius: {
        card: "var(--radius-card)",
      },
    },
  },
  plugins: [],
};
export default config;
