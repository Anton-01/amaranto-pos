/**
 * Loading state of the dashboard, drawn as the dashboard itself.
 *
 * WHAT — replaces the centered spinner that used to occupy the viewport while
 * the three dashboard endpoints resolved. Instead of a blank frame plus a
 * rotating circle, the page paints its own final geometry in grey: four KPI
 * cards, a wide chart panel and the top-products panel with its donut and its
 * five legend rows.
 *
 * HOW — the layout classes are copied verbatim from DashboardPage, which is
 * the whole point: the boxes land on the exact pixels the real content will
 * occupy, so when the data arrives nothing moves. A spinner gives the eye no
 * anchor and every element jumps into place at once; a skeleton that matches
 * the final structure reads as "already loading this specific screen", which
 * is why perceived latency drops even though the requests take exactly as long
 * as before.
 *
 * The shimmer is Tailwind's `animate-pulse` on the placeholder blocks only.
 * The cards, their rings and their padding are static: animating the container
 * as well makes the whole page throb, which is noticeably worse to look at.
 *
 * `aria-hidden` keeps the decorative blocks out of the accessibility tree; the
 * live region announcing the load lives on the wrapper in DashboardPage.
 */
export default function DashboardSkeleton() {
  return (
    <div aria-hidden="true">
      {/* Header: title, subtitle and the analytics button on the right. */}
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div className="space-y-2">
          <div className="h-7 w-40 animate-pulse rounded-lg bg-slate-200" />
          <div className="h-4 w-full max-w-72 animate-pulse rounded bg-slate-100" />
        </div>
        <div className="h-10 w-full animate-pulse rounded-xl bg-slate-200 sm:w-48" />
      </div>

      {/* KPI strip: same 1 / 2 / 4 column grid as the real cards. */}
      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-100 sm:p-5">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 shrink-0 animate-pulse rounded-xl bg-slate-200" />
              <div className="min-w-0 flex-1 space-y-2">
                <div className="h-3 w-24 animate-pulse rounded bg-slate-100" />
                <div className="h-5 w-28 animate-pulse rounded bg-slate-200" />
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        {/* Hourly trend: the bars stand in for the line chart's plotting area,
            with staggered heights so the block does not read as a flat slab. */}
        <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5 xl:col-span-2">
          <div className="mb-4 h-4 w-56 animate-pulse rounded bg-slate-200" />
          <div className="flex h-[220px] items-end gap-2 sm:h-[280px]">
            {[45, 70, 35, 85, 55, 75, 40, 90, 60, 50, 80, 65].map((height, i) => (
              <div
                key={i}
                className="flex-1 animate-pulse rounded-t bg-slate-100"
                style={{ height: `${height}%` }}
              />
            ))}
          </div>
          <div className="mt-3 flex justify-between">
            {[0, 1, 2, 3, 4].map((i) => (
              <div key={i} className="h-3 w-8 animate-pulse rounded bg-slate-100" />
            ))}
          </div>
        </div>

        {/* Top products: donut placeholder plus the five legend rows. */}
        <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div className="mb-4 h-4 w-40 animate-pulse rounded bg-slate-200" />
          <div className="flex h-[200px] items-center justify-center">
            {/* The white core reproduces the pie's innerRadius so the ring has
                the same thickness the chart will have. */}
            <div className="relative h-[150px] w-[150px] animate-pulse rounded-full bg-slate-200">
              <div className="absolute inset-[30px] rounded-full bg-white" />
            </div>
          </div>
          <div className="mt-2 space-y-1.5">
            {[0, 1, 2, 3, 4].map((i) => (
              <div key={i} className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <div className="h-2.5 w-2.5 animate-pulse rounded-full bg-slate-200" />
                  <div className="h-3 w-28 animate-pulse rounded bg-slate-100" />
                </div>
                <div className="h-3 w-12 animate-pulse rounded bg-slate-100" />
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
