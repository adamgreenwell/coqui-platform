import { useState } from 'react';

export interface TrendSeries {
  key: string;
  label: string;
  color: string;
  values: (number | null)[];
}

interface TrendChartProps {
  labels: string[];
  series: TrendSeries[];
  height?: number;
  /** Lower is better (rank): smaller values are drawn higher. */
  invert?: boolean;
  min?: number;
  max?: number;
  formatValue?: (value: number) => string;
  ariaLabel: string;
}

const WIDTH = 600;
const PAD = { top: 10, right: 12, bottom: 22, left: 36 };

/** A small dependency-free SVG line chart. Gaps (null) break the line. */
export default function TrendChart({
  labels,
  series,
  height = 160,
  invert = false,
  min,
  max,
  formatValue = (v) => String(v),
  ariaLabel,
}: TrendChartProps) {
  const [hover, setHover] = useState<number | null>(null);

  const all = series.flatMap((s) => s.values.filter((v): v is number => v !== null));
  if (labels.length === 0 || all.length === 0) {
    return (
      <div className="flex items-center justify-center text-[11px] text-slate-500" style={{ height }}>
        Not enough checks yet to chart a trend.
      </div>
    );
  }

  let lo = min ?? Math.min(...all);
  let hi = max ?? Math.max(...all);
  if (lo === hi) {
    lo -= 1;
    hi += 1;
  }

  const plotW = WIDTH - PAD.left - PAD.right;
  const plotH = height - PAD.top - PAD.bottom;
  const x = (i: number) => PAD.left + (labels.length === 1 ? plotW / 2 : (i / (labels.length - 1)) * plotW);
  const y = (v: number) => {
    const t = (v - lo) / (hi - lo);
    return PAD.top + (invert ? t : 1 - t) * plotH;
  };

  const paths = series.map((s) => {
    let d = '';
    let open = false;
    s.values.forEach((v, i) => {
      if (v === null) {
        open = false;
        return;
      }
      d += `${open ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`;
      open = true;
    });
    return d;
  });

  const ticks = [lo, (lo + hi) / 2, hi];
  const labelStep = Math.max(1, Math.ceil(labels.length / 6));

  const onMove = (e: React.MouseEvent<SVGSVGElement>) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const px = ((e.clientX - rect.left) / rect.width) * WIDTH;
    const i = labels.length === 1 ? 0 : Math.round(((px - PAD.left) / plotW) * (labels.length - 1));
    setHover(Math.max(0, Math.min(labels.length - 1, i)));
  };

  return (
    <div className="relative">
      <svg
        viewBox={`0 0 ${WIDTH} ${height}`}
        className="w-full h-auto"
        role="img"
        aria-label={ariaLabel}
        onMouseMove={onMove}
        onMouseLeave={() => setHover(null)}
      >
        {ticks.map((t) => (
          <g key={t}>
            <line x1={PAD.left} x2={WIDTH - PAD.right} y1={y(t)} y2={y(t)} stroke="#1e293b" strokeWidth={1} />
            <text x={PAD.left - 6} y={y(t) + 3} textAnchor="end" fontSize={9} fill="#64748b">
              {formatValue(Math.round(t * 10) / 10)}
            </text>
          </g>
        ))}
        {labels.map((l, i) =>
          i % labelStep === 0 || i === labels.length - 1 ? (
            <text key={l} x={x(i)} y={height - 6} textAnchor="middle" fontSize={9} fill="#64748b">
              {l.slice(5)}
            </text>
          ) : null,
        )}
        {paths.map((d, i) => (
          <path
            key={series[i].key}
            d={d}
            fill="none"
            stroke={series[i].color}
            strokeWidth={2}
            strokeLinejoin="round"
            strokeLinecap="round"
            vectorEffect="non-scaling-stroke"
          />
        ))}
        {hover !== null && (
          <g>
            <line x1={x(hover)} x2={x(hover)} y1={PAD.top} y2={PAD.top + plotH} stroke="#475569" strokeDasharray="3 3" />
            {series.map((s) =>
              s.values[hover] !== null ? (
                <circle key={s.key} cx={x(hover)} cy={y(s.values[hover] as number)} r={3} fill={s.color} />
              ) : null,
            )}
          </g>
        )}
      </svg>
      <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] text-slate-400">
        {hover !== null && <span className="font-mono text-slate-300">{labels[hover]}</span>}
        {series.map((s) => (
          <span key={s.key} className="inline-flex items-center gap-1">
            <span className="inline-block h-1.5 w-3 rounded-full" style={{ backgroundColor: s.color }} />
            {s.label}
            {hover !== null && (
              <span className="font-mono text-slate-200">
                {s.values[hover] !== null ? formatValue(s.values[hover] as number) : '—'}
              </span>
            )}
          </span>
        ))}
      </div>
    </div>
  );
}
