// Explicitly synthetic visual/test data. Never imported by production code.
export function graphFixture(
  metric: string,
  period = "1h",
  now = Math.floor(Date.now() / 1000),
) {
  const seconds =
    (
      { "1h": 3600, "24h": 86400, "7d": 604800, "15d": 1296000 } as Record<
        string,
        number
      >
    )[period] || 3600;
  const step = seconds / 120;
  const points = Array.from({ length: 121 }, (_, i) => {
    let n =
      metric === "cpu"
        ? 20 +
          9 * Math.sin(i * 0.17) +
          4 * Math.cos(i * 0.71) +
          (i > 48 && i < 63 ? 26 * Math.sin(((i - 48) / 15) * Math.PI) : 0)
        : metric === "ram"
          ? 41 + 2.5 * Math.sin(i * 0.04) + i * 0.015
          : 155 +
            21 * Math.sin(i * 0.16) +
            10 * Math.cos(i * 0.73) +
            (i > 80 && i < 87 ? 170 * Math.sin(((i - 80) / 7) * Math.PI) : 0);
    if (i === 120) n = metric === "cpu" ? 12.4 : metric === "ram" ? 42.5 : 127;
    return [
      now - seconds + i * step,
      i === 37 || i === 38 ? null : Math.round(n * 10) / 10,
    ] as [number, number | null];
  });
  return { status: "ok", reason_code: "measured", step_seconds: step, points };
}
