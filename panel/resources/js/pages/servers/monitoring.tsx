import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import ServerLayout from '@/layouts/server-layout';
import { type BreadcrumbItem, type Server } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Activity, HardDrive, MemoryStick, Server as ServerIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Cell,
    Legend,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface MetricPoint {
    cpu: number;
    ram: number;
    ram_gb: number;
    disk: number;
    disk_gb: number;
    load1: number;
    ts: string;
}

interface LatestMetric {
    cpu_percent: number;
    mem_total: number;
    mem_used: number;
    disk_total: number;
    disk_used: number;
    load1: number;
    uptime_seconds?: number;
    recorded_at: string;
}

function formatBytes(bytes: number): string {
    return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB';
}

function formatUptime(seconds?: number): string {
    if (!seconds) return '—';
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (d > 0) return `${d}d ${h}h ${m}m`;
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m`;
}

function HalfDonutGauge({ pct, label, sublabel, color = '#3b82f6' }: { pct: number; label: string; sublabel?: string; color?: string }) {
    const safeP = Math.max(0, Math.min(100, pct));
    return (
        <div className="flex flex-col items-center">
            <div className="relative">
                <PieChart width={160} height={90}>
                    <Pie
                        data={[{ value: safeP }, { value: 100 - safeP }]}
                        cx={75}
                        cy={85}
                        startAngle={180}
                        endAngle={0}
                        innerRadius={50}
                        outerRadius={70}
                        dataKey="value"
                        strokeWidth={0}
                    >
                        <Cell fill={color} />
                        <Cell fill="#e5e7eb" className="dark:fill-neutral-700" />
                    </Pie>
                </PieChart>
                <div className="absolute bottom-1 left-1/2 -translate-x-1/2 text-center">
                    <span className="text-xl font-bold leading-none">{safeP.toFixed(1)}%</span>
                </div>
            </div>
            <p className="mt-1 text-sm font-medium">{label}</p>
            {sublabel && <p className="text-muted-foreground text-xs">{sublabel}</p>}
        </div>
    );
}

const RANGES = ['1h', '6h', '24h', '7d'] as const;
type Range = (typeof RANGES)[number];

export default function ServersMonitoring({
    server,
    range,
    metrics,
    latestMetric,
}: {
    server: Server;
    range: string;
    metrics: MetricPoint[];
    latestMetric: LatestMetric | null;
}) {
    const [activeRange, setActiveRange] = useState<Range>((range as Range) ?? '1h');

    useEffect(() => {
        setActiveRange((range as Range) ?? '1h');
    }, [range]);

    const handleRangeChange = (r: Range) => {
        setActiveRange(r);
        router.get(route('servers.monitoring', server.id), { range: r }, { preserveState: false });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Monitoring', href: `/servers/${server.id}/monitoring` },
    ];

    const memPct =
        latestMetric && latestMetric.mem_total > 0
            ? (latestMetric.mem_used / latestMetric.mem_total) * 100
            : 0;
    const diskPct =
        latestMetric && latestMetric.disk_total > 0
            ? (latestMetric.disk_used / latestMetric.disk_total) * 100
            : 0;

    const hasMetrics = metrics.length > 0;

    return (
        <ServerLayout
            breadcrumbs={breadcrumbs}
            server={{ id: server.id, name: server.name, public_ip: server.public_ip ?? null, status: server.status }}
        >
            <Head title={`Monitoring — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {/* Page header + range selector */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Monitoring</h1>
                        <p className="text-muted-foreground text-sm">{server.name}</p>
                    </div>

                    <div className="flex rounded-md border p-0.5">
                        {RANGES.map((r) => (
                            <button
                                key={r}
                                onClick={() => handleRangeChange(r)}
                                className={`rounded px-3 py-1 text-sm font-medium transition-colors ${
                                    activeRange === r
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {r}
                            </button>
                        ))}
                    </div>
                </div>

                <Tabs defaultValue="health">
                    <TabsList>
                        <TabsTrigger value="health">
                            <Activity className="mr-1.5 h-4 w-4" />
                            Health
                        </TabsTrigger>
                        <TabsTrigger value="processes">
                            <ServerIcon className="mr-1.5 h-4 w-4" />
                            Top Processes
                        </TabsTrigger>
                        <TabsTrigger value="storage">
                            <HardDrive className="mr-1.5 h-4 w-4" />
                            Storage
                        </TabsTrigger>
                    </TabsList>

                    {/* Health tab */}
                    <TabsContent value="health" className="mt-4 space-y-4">
                        {/* Summary row */}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {/* Load card */}
                            <Card>
                                <CardHeader className="pb-1">
                                    <CardTitle className="text-muted-foreground text-sm font-medium">System Load</CardTitle>
                                    <CardDescription className="text-xs">1-minute average</CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col items-center justify-center py-4">
                                    <span className="text-4xl font-bold tabular-nums">
                                        {latestMetric?.load1 != null ? latestMetric.load1.toFixed(2) : '—'}
                                    </span>
                                    {latestMetric?.uptime_seconds !== undefined && (
                                        <p className="text-muted-foreground mt-2 text-xs">
                                            Uptime: {formatUptime(latestMetric.uptime_seconds)}
                                        </p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Memory donut */}
                            <Card>
                                <CardHeader className="pb-1">
                                    <CardTitle className="text-muted-foreground text-sm font-medium">
                                        <MemoryStick className="mr-1 inline-block h-4 w-4" />
                                        Memory
                                    </CardTitle>
                                    {latestMetric && (
                                        <CardDescription className="text-xs">
                                            {formatBytes(latestMetric.mem_used)} of {formatBytes(latestMetric.mem_total)}
                                        </CardDescription>
                                    )}
                                </CardHeader>
                                <CardContent className="flex items-center justify-center">
                                    {latestMetric ? (
                                        <HalfDonutGauge
                                            pct={memPct}
                                            label="Used"
                                            color="#22c55e"
                                        />
                                    ) : (
                                        <p className="text-muted-foreground py-6 text-sm">No data</p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Disk donut */}
                            <Card>
                                <CardHeader className="pb-1">
                                    <CardTitle className="text-muted-foreground text-sm font-medium">
                                        <HardDrive className="mr-1 inline-block h-4 w-4" />
                                        Disk
                                    </CardTitle>
                                    {latestMetric && (
                                        <CardDescription className="text-xs">
                                            {formatBytes(latestMetric.disk_used)} of {formatBytes(latestMetric.disk_total)}
                                        </CardDescription>
                                    )}
                                </CardHeader>
                                <CardContent className="flex items-center justify-center">
                                    {latestMetric ? (
                                        <HalfDonutGauge
                                            pct={diskPct}
                                            label="Used"
                                            color="#f59e0b"
                                        />
                                    ) : (
                                        <p className="text-muted-foreground py-6 text-sm">No data</p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* CPU chart */}
                        <Card>
                            <CardHeader>
                                <CardTitle>CPU Usage</CardTitle>
                                <CardDescription>Percentage over the last {activeRange}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {hasMetrics ? (
                                    <ResponsiveContainer width="100%" height={200}>
                                        <LineChart data={metrics}>
                                            <XAxis
                                                dataKey="ts"
                                                interval="preserveStartEnd"
                                                tick={{ fontSize: 11 }}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                domain={[0, 100]}
                                                unit="%"
                                                tick={{ fontSize: 11 }}
                                                tickLine={false}
                                                axisLine={false}
                                                width={38}
                                            />
                                            <Tooltip formatter={(v: number) => [`${v}%`, 'CPU']} />
                                            <Line
                                                type="monotone"
                                                dataKey="cpu"
                                                name="CPU"
                                                stroke="#3b82f6"
                                                dot={false}
                                                strokeWidth={2}
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <p className="text-muted-foreground py-8 text-center text-sm">No metric data for this period.</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Load chart */}
                        <Card>
                            <CardHeader>
                                <CardTitle>System Load</CardTitle>
                                <CardDescription>1-minute load average over the last {activeRange}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {hasMetrics ? (
                                    <ResponsiveContainer width="100%" height={200}>
                                        <LineChart data={metrics}>
                                            <XAxis
                                                dataKey="ts"
                                                interval="preserveStartEnd"
                                                tick={{ fontSize: 11 }}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                tick={{ fontSize: 11 }}
                                                tickLine={false}
                                                axisLine={false}
                                                width={38}
                                            />
                                            <Tooltip formatter={(v: number) => [v.toFixed(2), 'Load']} />
                                            <Line
                                                type="monotone"
                                                dataKey="load1"
                                                name="Load"
                                                stroke="#8b5cf6"
                                                dot={false}
                                                strokeWidth={2}
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <p className="text-muted-foreground py-8 text-center text-sm">No metric data for this period.</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* RAM chart */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Memory Usage</CardTitle>
                                <CardDescription>GB used over the last {activeRange}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {hasMetrics ? (
                                    <ResponsiveContainer width="100%" height={200}>
                                        <LineChart data={metrics}>
                                            <XAxis
                                                dataKey="ts"
                                                interval="preserveStartEnd"
                                                tick={{ fontSize: 11 }}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                unit=" GB"
                                                tick={{ fontSize: 11 }}
                                                tickLine={false}
                                                axisLine={false}
                                                width={52}
                                            />
                                            <Tooltip formatter={(v: number) => [`${v} GB`, 'RAM']} />
                                            <Legend />
                                            <Line
                                                type="monotone"
                                                dataKey="ram_gb"
                                                name="RAM (GB)"
                                                stroke="#22c55e"
                                                dot={false}
                                                strokeWidth={2}
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <p className="text-muted-foreground py-8 text-center text-sm">No metric data for this period.</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Disk chart */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Disk Usage</CardTitle>
                                <CardDescription>GB used over the last {activeRange}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {hasMetrics ? (
                                    <ResponsiveContainer width="100%" height={200}>
                                        <LineChart data={metrics}>
                                            <XAxis
                                                dataKey="ts"
                                                interval="preserveStartEnd"
                                                tick={{ fontSize: 11 }}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                unit=" GB"
                                                tick={{ fontSize: 11 }}
                                                tickLine={false}
                                                axisLine={false}
                                                width={52}
                                            />
                                            <Tooltip formatter={(v: number) => [`${v} GB`, 'Disk']} />
                                            <Legend />
                                            <Line
                                                type="monotone"
                                                dataKey="disk_gb"
                                                name="Disk (GB)"
                                                stroke="#f59e0b"
                                                dot={false}
                                                strokeWidth={2}
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <p className="text-muted-foreground py-8 text-center text-sm">No metric data for this period.</p>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Top Processes tab — placeholder */}
                    <TabsContent value="processes" className="mt-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Top Processes</CardTitle>
                                <CardDescription>Real-time process list from the agent.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-col items-center justify-center py-16 text-center">
                                    <ServerIcon className="text-muted-foreground mb-3 h-10 w-10" />
                                    <p className="text-muted-foreground text-sm">Coming soon</p>
                                    <p className="text-muted-foreground mt-1 max-w-xs text-xs">
                                        Live process data will be streamed from the agent in a future update.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Storage tab — placeholder */}
                    <TabsContent value="storage" className="mt-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Storage Details</CardTitle>
                                <CardDescription>Per-partition disk usage.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-col items-center justify-center py-16 text-center">
                                    <HardDrive className="text-muted-foreground mb-3 h-10 w-10" />
                                    <p className="text-muted-foreground text-sm">Coming soon</p>
                                    <p className="text-muted-foreground mt-1 max-w-xs text-xs">
                                        Per-partition breakdown will be available in a future update.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </ServerLayout>
    );
}
