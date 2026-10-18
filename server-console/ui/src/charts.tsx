import React, { useEffect, useRef, useState } from "react";
import { Activity, ChartNoAxesCombined, Maximize2, X } from "lucide-react";

export type Series = {
  points: [number, number | null][];
  status: string;
  reason_code: string;
  step_seconds?: number;
};
type Tone = "http" | "cpu" | "ram";
type Track = { name: string; tone: Tone; data?: Series };
const format = (n: number | null | undefined, digits = 1) =>
  n == null
    ? "—"
    : n.toLocaleString("ru-RU", { maximumFractionDigits: digits });
const time = (n: number, long = false) =>
  new Date(n * 1000).toLocaleString("ru-RU", {
    timeZone: "Europe/Moscow",
    ...(long
      ? { day: "2-digit", month: "2-digit" }
      : { hour: "2-digit", minute: "2-digit" }),
  });
const missing: Record<string, string> = {
  not_configured: "Источник истории ещё не подключён",
  source_unavailable: "Не удалось получить историю",
  series_missing_or_ambiguous: "Источник метрики не определён",
  no_samples: "За выбранный период нет измерений",
};

type ChartProps = {
  series?: Series;
  label: string;
  unit: string;
  tone?: Tone;
  companion?: Series;
};

export function TimeChart(props: ChartProps) {
  const [open, setOpen] = useState(false);
  const [hidden, setHidden] = useState<string[]>([]);
  const dialog = useRef<HTMLDialogElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (!open || !dialog.current) return;
    const element = dialog.current;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    element.showModal();
    return () => {
      element.close();
      document.body.style.overflow = overflow;
      trigger.current?.focus({ preventScroll: true });
    };
  }, [open]);
  return (
    <>
      <ChartView
        {...props}
        hidden={hidden}
        setHidden={setHidden}
        expandButton={
          <button
            className="chart-expand"
            ref={trigger}
            aria-label={`Увеличить график: ${props.label}`}
            aria-haspopup="dialog"
            aria-expanded={open}
            onClick={() => setOpen(true)}
          >
            <Maximize2 size={18} aria-hidden="true" />
            Увеличить
          </button>
        }
      />
      {open && (
        <dialog
          ref={dialog}
          className="chart-dialog"
          aria-label={props.label}
          onCancel={(event) => {
            event.preventDefault();
            setOpen(false);
          }}
          onClose={() => setOpen(false)}
        >
          <div className="dialog-head">
            <span className="muted">Увеличенный график</span>
            <button aria-label="Закрыть" onClick={() => setOpen(false)}>
              <X size={24} aria-hidden="true" />
            </button>
          </div>
          <ChartView
            {...props}
            hidden={hidden}
            setHidden={setHidden}
            expanded
          />
        </dialog>
      )}
    </>
  );
}

function ChartView({
  series,
  label,
  unit,
  tone = "http",
  companion,
  expanded = false,
  hidden,
  setHidden,
  expandButton,
}: ChartProps & {
  expanded?: boolean;
  hidden: string[];
  setHidden: React.Dispatch<React.SetStateAction<string[]>>;
  expandButton?: React.ReactNode;
}) {
  const container = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(480),
    [cursor, setCursor] = useState<number | null>(null);
  useEffect(() => {
    if (!container.current) return;
    const observer = new ResizeObserver(([entry]) =>
      setWidth(Math.max(1, entry.contentRect.width)),
    );
    observer.observe(container.current);
    return () => observer.disconnect();
  }, []);
  const tracks: Track[] =
    companion !== undefined
      ? [
          { name: "CPU", tone: "cpu", data: series },
          { name: "RAM", tone: "ram", data: companion },
        ]
      : [
          {
            name: tone === "cpu" ? "CPU" : tone === "ram" ? "RAM" : "HTTP",
            tone,
            data: series,
          },
        ];
  const visible = tracks.filter((track) => !hidden.includes(track.name));
  const all = tracks.flatMap((track) => track.data?.points || []);
  const values = visible.flatMap((track) =>
    (track.data?.points || [])
      .filter((point) => point[1] != null)
      .map((point) => point[1]!),
  );
  const hasData = all.some((point) => point[1] != null);
  const start = Math.min(...all.map((point) => point[0])),
    end = Math.max(...all.map((point) => point[0]));
  const duration = Math.max(1, end - start);
  const max =
    unit === "%"
      ? 100
      : Math.max(10, Math.ceil((Math.max(1, ...values) * 1.15) / 50) * 50);
  const left = 68,
    right = 18,
    top = 20,
    bottom = 36,
    height = expanded ? 420 : 250;
  const plotWidth = Math.max(1, width - left - right),
    plotHeight = height - top - bottom;
  const x = (t: number) => left + ((t - start) / duration) * plotWidth;
  const y = (n: number) =>
    top + plotHeight - Math.min(1, Math.max(0, n / max)) * plotHeight;
  const geometry = (track: Track) => {
    const segments: [number, number][][] = [];
    let segment: [number, number][] = [];
    let previous: number | undefined;
    (track.data?.points || []).forEach(([t, n]) => {
      if (
        n == null ||
        (previous !== undefined &&
          t - previous > (track.data?.step_seconds || 60) * 1.5)
      ) {
        if (segment.length) segments.push(segment);
        segment = [];
      }
      if (n != null) segment.push([x(t), y(n)]);
      previous = n == null ? undefined : t;
    });
    if (segment.length) segments.push(segment);
    return segments;
  };
  const pointAt = (track: Track, t: number) => {
    const points = track.data?.points || [];
    if (!points.length) return null;
    const nearest = points.reduce((a, b) =>
      Math.abs(a[0] - t) < Math.abs(b[0] - t) ? a : b,
    );
    return Math.abs(nearest[0] - t) <= (track.data?.step_seconds || 60) * 1.5
      ? nearest[1]
      : null;
  };
  const tickCount = Math.max(2, Math.min(5, Math.floor(plotWidth / 90) + 1));
  return (
    <section
      className={
        "chart panel chart-" + tone + (expanded ? " chart-expanded" : "")
      }
    >
      <div className="section-title">
        <div className="chart-heading">
          <Activity size={18} aria-hidden="true" />
          <h2>{label}</h2>
        </div>
        <div className="chart-actions">
          <span className="unit-label">{unit}</span>
          {expandButton}
        </div>
      </div>
      <div ref={container} className="plot-container">
        {hasData ? (
          <>
            <svg
              viewBox={`0 0 ${width} ${height}`}
              width={width}
              height={height}
              style={{ height }}
              role="img"
              aria-label={`${label}: ${all.filter((point) => point[1] != null).length} измерений`}
              onPointerLeave={(e) => {
                if (e.pointerType === "mouse") setCursor(null);
              }}
              onPointerMove={(e) => {
                const box = e.currentTarget.getBoundingClientRect();
                const fraction = Math.max(
                  0,
                  Math.min(1, (e.clientX - box.left - left) / plotWidth),
                );
                setCursor(start + fraction * duration);
              }}
            >
              {[0, 0.25, 0.5, 0.75, 1].map((fraction) => (
                <g key={fraction}>
                  <line
                    className="grid-line"
                    x1={left}
                    y1={y(max * fraction)}
                    x2={width - right}
                    y2={y(max * fraction)}
                  />
                  <text
                    className="axis-text"
                    x={left - 12}
                    y={y(max * fraction) + 4}
                    textAnchor="end"
                  >
                    {format(max * fraction, 0)}
                  </text>
                </g>
              ))}
              {Array.from({ length: tickCount }, (_, i) => {
                const t = start + (duration * i) / (tickCount - 1);
                return (
                  <g key={i}>
                    <line
                      className="grid-line vertical"
                      x1={x(t)}
                      y1={top}
                      x2={x(t)}
                      y2={height - bottom}
                    />
                    <text
                      className="axis-text"
                      x={x(t)}
                      y={height - 9}
                      textAnchor={
                        i === 0
                          ? "start"
                          : i === tickCount - 1
                            ? "end"
                            : "middle"
                      }
                    >
                      {time(t, duration > 86400)}
                    </text>
                  </g>
                );
              })}
              {visible.map((track) => (
                <g key={track.name} className={"series-" + track.tone}>
                  {geometry(track).map((segment, i) => {
                    const line = segment
                      .map(([a, b], j) => `${j ? "L" : "M"}${a},${b}`)
                      .join(" ");
                    return (
                      <g key={i}>
                        <path
                          className="chart-area"
                          d={`${line} L${segment.at(-1)![0]},${height - bottom} L${segment[0][0]},${height - bottom} Z`}
                        />
                        <path className="chart-line" d={line} />
                        {segment.length === 1 && (
                          <circle
                            className="chart-point"
                            cx={segment[0][0]}
                            cy={segment[0][1]}
                            r={3}
                          />
                        )}
                      </g>
                    );
                  })}
                </g>
              ))}
              {cursor !== null && (
                <g>
                  <line
                    className="crosshair"
                    x1={x(cursor)}
                    y1={top}
                    x2={x(cursor)}
                    y2={height - bottom}
                  />
                  {visible.map((track) => {
                    const n = pointAt(track, cursor);
                    return n == null ? null : (
                      <circle
                        key={track.name}
                        className={"hover-point series-" + track.tone}
                        cx={x(cursor)}
                        cy={y(n)}
                        r={4}
                      />
                    );
                  })}
                </g>
              )}
              <text className="sr-svg" x={left} y={top}>
                Ось X: время по Москве. Ось Y: {unit}.
              </text>
            </svg>
            {cursor !== null && (
              <div className="chart-tooltip" role="tooltip">
                <time>{time(cursor)}</time>
                {visible.map((track) => (
                  <div key={track.name}>
                    <span>
                      <i className={"legend-swatch " + track.tone} />
                      {track.name}
                    </span>
                    <strong>
                      {format(pointAt(track, cursor))} {unit}
                    </strong>
                  </div>
                ))}
              </div>
            )}
          </>
        ) : (
          <div className="chart-no-data">
            <ChartNoAxesCombined size={36} aria-hidden="true" />
            <strong>История пока недоступна</strong>
            <span>
              {missing[series?.reason_code || "not_configured"] ||
                "Нет измерений"}
              . График появится после получения измерений.
            </span>
          </div>
        )}
      </div>
      {hasData && !visible.length && (
        <p className="muted" role="status">
          Линии скрыты. Нажмите на название метрики, чтобы вернуть её на график.
        </p>
      )}
      <div className="chart-legend">
        <div className="legend-header">
          <span>Метрика</span>
          <span>Последнее</span>
          <span>Среднее</span>
          <span>Максимум</span>
        </div>
        {tracks.map((track) => {
          const numbers = (track.data?.points || [])
            .filter((point) => point[1] != null)
            .map((point) => point[1]!);
          const last = track.data?.points.at(-1)?.[1];
          return (
            <div
              className={
                "legend-row " +
                (hidden.includes(track.name) ? "muted-track" : "")
              }
              key={track.name}
            >
              <button
                className="legend-toggle"
                aria-pressed={!hidden.includes(track.name)}
                onClick={() =>
                  setHidden((old) =>
                    old.includes(track.name)
                      ? old.filter((name) => name !== track.name)
                      : [...old, track.name],
                  )
                }
              >
                <i className={"legend-swatch " + track.tone} />
                {track.name}
              </button>
              <span className="legend-value" data-label="Последнее">
                {format(last)}
                {last != null ? " " + unit : ""}
              </span>
              <span className="legend-value" data-label="Среднее">
                {format(
                  numbers.length
                    ? numbers.reduce((a, b) => a + b, 0) / numbers.length
                    : null,
                )}
              </span>
              <span className="legend-value" data-label="Максимум">
                {format(numbers.length ? Math.max(...numbers) : null)}
              </span>
            </div>
          );
        })}
      </div>
      <span className="chart-timezone">Время — Москва</span>
    </section>
  );
}
