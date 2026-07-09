import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    Coins,
    PiggyBank,
    Wallet,
} from 'lucide-react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type Props = {
    stats: {
        revenue: number;
        currency: string;
        aiSpend: number;
        booksTotal: number;
        avgCostPerBook: number;
        failedJobs: number;
    };
    byStatus: Record<string, number>;
    trend: { day: string; books: number; spend: number }[];
    spendByModel: { model: string; spend: number; calls: number }[];
    spendByKind: Record<string, number>;
    topStyles: Record<string, number>;
    recentFailures: { id: number; childName: string; failedAt: string }[];
};

// Status colors are semantic and reserved (dataviz rule): each chip carries a
// label, never color alone. Chart marks use the single primary hue - every
// chart below is single-series magnitude, so no categorical palette is needed.
const STATUS_TONE: Record<string, string> = {
    complete: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    generating: 'bg-primary/15 text-primary',
    pending: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    failed: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    draft: 'bg-muted text-muted-foreground',
};

const BAR_FILL = 'hsl(249 72% 60%)';
const GRID_STROKE = 'hsl(220 15% 55% / 0.18)';
const AXIS_TICK = { fill: 'hsl(220 10% 55%)', fontSize: 11 };

function StatTile({
    icon: Icon,
    label,
    value,
    hint,
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-5">
                <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <Icon className="h-5 w-5" />
                </div>
                <div>
                    <p className="text-xs text-muted-foreground uppercase">
                        {label}
                    </p>
                    <p className="text-2xl font-semibold tabular-nums">
                        {value}
                    </p>
                    {hint && (
                        <p className="text-xs text-muted-foreground">{hint}</p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

const tooltipStyle = {
    background: 'var(--card, #fff)',
    border: '1px solid hsl(220 15% 55% / 0.25)',
    borderRadius: 8,
    fontSize: 12,
};

export default function AdminDashboard({
    stats,
    byStatus,
    trend,
    spendByModel,
    spendByKind,
    topStyles,
    recentFailures,
}: Props) {
    const shortDay = (day: string) => day.slice(5);
    const maxModelSpend = Math.max(
        ...spendByModel.map((row) => row.spend),
        0.01,
    );

    return (
        <>
            <Head title="Admin" />
            <div className="space-y-6 p-6">
                <h1 className="font-serif text-2xl font-semibold">
                    Dashboard
                </h1>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile
                        icon={PiggyBank}
                        label="Revenue"
                        value={`${stats.revenue.toFixed(2)} ${stats.currency}`}
                        hint="paid orders"
                    />
                    <StatTile
                        icon={Wallet}
                        label="AI spend"
                        value={`$${stats.aiSpend.toFixed(2)}`}
                        hint={`~$${stats.avgCostPerBook.toFixed(2)} per finished book`}
                    />
                    <StatTile
                        icon={BookOpen}
                        label="Books"
                        value={String(stats.booksTotal)}
                    />
                    <StatTile
                        icon={AlertTriangle}
                        label="Failed jobs"
                        value={String(stats.failedJobs)}
                        hint="queue failures on record"
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    {Object.entries(byStatus).map(([status, total]) => (
                        <span
                            key={status}
                            className={`rounded-full px-3 py-1 text-xs font-semibold ${STATUS_TONE[status] ?? 'bg-muted'}`}
                        >
                            {status}: {total}
                        </span>
                    ))}
                </div>

                {/* Books and spend share a timeline but not a scale - two
                    charts, never a dual axis. */}
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Books created - last 14 days</CardTitle>
                        </CardHeader>
                        <CardContent className="h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={trend}>
                                    <CartesianGrid
                                        vertical={false}
                                        stroke={GRID_STROKE}
                                    />
                                    <XAxis
                                        dataKey="day"
                                        tickFormatter={shortDay}
                                        tick={AXIS_TICK}
                                        axisLine={false}
                                        tickLine={false}
                                    />
                                    <YAxis
                                        allowDecimals={false}
                                        tick={AXIS_TICK}
                                        axisLine={false}
                                        tickLine={false}
                                        width={28}
                                    />
                                    <Tooltip
                                        cursor={{
                                            fill: 'hsl(220 15% 55% / 0.08)',
                                        }}
                                        contentStyle={tooltipStyle}
                                    />
                                    <Bar
                                        dataKey="books"
                                        fill={BAR_FILL}
                                        radius={[4, 4, 0, 0]}
                                        maxBarSize={18}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>AI spend (USD) - last 14 days</CardTitle>
                        </CardHeader>
                        <CardContent className="h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={trend}>
                                    <CartesianGrid
                                        vertical={false}
                                        stroke={GRID_STROKE}
                                    />
                                    <XAxis
                                        dataKey="day"
                                        tickFormatter={shortDay}
                                        tick={AXIS_TICK}
                                        axisLine={false}
                                        tickLine={false}
                                    />
                                    <YAxis
                                        tick={AXIS_TICK}
                                        axisLine={false}
                                        tickLine={false}
                                        width={40}
                                    />
                                    <Tooltip
                                        cursor={{
                                            fill: 'hsl(220 15% 55% / 0.08)',
                                        }}
                                        contentStyle={tooltipStyle}
                                    />
                                    <Bar
                                        dataKey="spend"
                                        fill="hsl(40 85% 55%)"
                                        radius={[4, 4, 0, 0]}
                                        maxBarSize={18}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Spend by model</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2.5">
                            {spendByModel.map((row) => (
                                <div key={row.model} className="text-sm">
                                    <div className="mb-1 flex items-baseline justify-between gap-3">
                                        <span className="truncate font-mono text-xs">
                                            {row.model}
                                        </span>
                                        <span className="tabular-nums">
                                            ${row.spend.toFixed(2)}
                                            <span className="ms-2 text-xs text-muted-foreground">
                                                {row.calls} calls
                                            </span>
                                        </span>
                                    </div>
                                    <div className="h-2 rounded-full bg-muted">
                                        <div
                                            className="h-2 rounded-full bg-primary"
                                            style={{
                                                width: `${Math.max(2, (row.spend / maxModelSpend) * 100)}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                            {spendByModel.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No AI usage recorded yet.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Spend by kind</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1.5 text-sm">
                                {Object.entries(spendByKind).map(
                                    ([kind, total]) => (
                                        <p
                                            key={kind}
                                            className="flex justify-between"
                                        >
                                            <span className="text-muted-foreground">
                                                {kind}
                                            </span>
                                            <span className="tabular-nums">
                                                ${total.toFixed(2)}
                                            </span>
                                        </p>
                                    ),
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Top styles</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1.5 text-sm">
                                {Object.entries(topStyles).map(
                                    ([style, total]) => (
                                        <p
                                            key={style}
                                            className="flex justify-between"
                                        >
                                            <span className="text-muted-foreground">
                                                {style}
                                            </span>
                                            <span className="tabular-nums">
                                                {total}
                                            </span>
                                        </p>
                                    ),
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {recentFailures.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <AlertTriangle className="h-4 w-4 text-rose-500" />
                                Recent failures
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1.5 text-sm">
                            {recentFailures.map((failure) => (
                                <p
                                    key={failure.id}
                                    className="flex justify-between"
                                >
                                    <Link
                                        href={`/admin/books/${failure.id}`}
                                        className="text-primary hover:underline"
                                    >
                                        #{failure.id} - {failure.childName}
                                    </Link>
                                    <span className="text-xs text-muted-foreground">
                                        {failure.failedAt}
                                    </span>
                                </p>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Coins className="h-3.5 w-3.5" />
                    AI spend uses provider-reported costs where available and
                    estimates elsewhere.
                </p>
            </div>
        </>
    );
}
