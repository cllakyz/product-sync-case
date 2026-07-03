import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import {
    failedJobs as failedJobsRoute,
    history as syncHistoryRoute,
    retry as retryRoute,
    status as syncStatusRoute,
    trigger as triggerRoute,
} from '@/routes/api/sync';
import { dashboard as syncDashboardRoute } from '@/routes/sync';
import type { RouteDefinition } from '@/wayfinder';

type SyncStats = {
    added: number;
    updated: number;
    deleted: number;
    unchanged: number;
    failed: number;
};

type SyncLog = {
    id: number;
    provider_type: string;
    trigger_type: string;
    status: string;
    started_at: string | null;
    completed_at: string | null;
    duration_ms: number | null;
    stats: SyncStats;
    api_requests: number;
    error_message: string | null;
};

type FailedJob = {
    id: number;
    job_type: string;
    provider_type: string | null;
    status: string;
    retry_count: number;
    exception: string;
    failed_at: string | null;
};

type ActiveRun = {
    provider: string;
    status: string;
    started_at: string | null;
    sync_log_id: number;
};

type StatusResponse = {
    active: ActiveRun[];
    last_completed: Record<string, SyncLog | null>;
};

type ApiSuccess<T> = {
    success: true;
    data: T;
};

type ApiFailure = {
    success: false;
    error: {
        code: string;
        message: string;
    };
};

const REFRESH_MS = 5000;

function readCookie(name: string): string {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

    return match ? decodeURIComponent(match[1]) : '';
}

async function api<T>(
    route: RouteDefinition<'get' | 'post'>,
    options: RequestInit = {},
): Promise<T> {
    const method = (options.method ?? route.method).toUpperCase();

    if (method !== 'GET') {
        // Prime the Sanctum SPA CSRF cookie before a state-changing request.
        await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
    }

    const response = await fetch(route.url, {
        ...options,
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN'),
            ...(options.headers ?? {}),
        },
    });
    const payload = (await response.json()) as ApiSuccess<T> | ApiFailure;

    if (!response.ok || payload.success === false) {
        throw new Error(
            payload.success === false
                ? payload.error.message
                : 'API isteği başarısız oldu.',
        );
    }

    return payload.data;
}

const statusColors: Record<string, string> = {
    completed: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
    partial: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
    running: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    pending: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    skipped: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
};

function StatusBadge({ status }: { status: string }) {
    const cls = statusColors[status] ?? statusColors.pending;

    return <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>{status}</span>;
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

export default function SyncDashboard() {
    const [status, setStatus] = useState<StatusResponse | null>(null);
    const [history, setHistory] = useState<SyncLog[]>([]);
    const [failedJobs, setFailedJobs] = useState<FailedJob[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async () => {
        try {
            const [statusRes, historyRes, failedRes] = await Promise.all([
                api<StatusResponse>(syncStatusRoute()),
                api<SyncLog[]>(syncHistoryRoute({ query: { per_page: 10 } })),
                api<FailedJob[]>(
                    failedJobsRoute({ query: { per_page: 10 } }),
                ),
            ]);
            setStatus(statusRes);
            setHistory(historyRes);
            setFailedJobs(failedRes);
            setError(null);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'Veriler yüklenemedi. Oturumunuz açık mı?',
            );
        }
    }, []);

    useEffect(() => {
        // load() is async — state updates happen after awaited fetches, not
        // synchronously — so this initial call + polling is a safe subscription.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void load();
        const timer = setInterval(() => void load(), REFRESH_MS);

        return () => clearInterval(timer);
    }, [load]);

    const trigger = useCallback(
        async (provider?: string) => {
            setBusy(provider ?? 'all');

            try {
                await api(triggerRoute(), {
                    body: JSON.stringify(provider ? { provider } : {}),
                });
                await load();
            } finally {
                setBusy(null);
            }
        },
        [load],
    );

    const retry = useCallback(
        async (jobId: number) => {
            setBusy(`retry-${jobId}`);

            try {
                await api(retryRoute(jobId));
                await load();
            } finally {
                setBusy(null);
            }
        },
        [load],
    );

    const providers = status ? Object.keys(status.last_completed) : [];

    return (
        <>
            <Head title="Sync Dashboard" />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">Sync Dashboard</h1>
                        <p className="text-sm text-neutral-500">Her {REFRESH_MS / 1000} saniyede otomatik yenilenir.</p>
                    </div>
                    <button
                        onClick={() => void trigger()}
                        disabled={busy !== null}
                        className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-neutral-900"
                    >
                        {busy === 'all' ? 'Kuyruğa alınıyor…' : 'Tümünü Senkronize Et'}
                    </button>
                </header>

                {error && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                        {error}
                    </div>
                )}

                {/* Status cards */}
                <section className="grid gap-4 sm:grid-cols-2">
                    {providers.map((provider) => {
                        const last = status?.last_completed[provider];
                        const active = status?.active.find((a) => a.provider === provider);

                        return (
                            <div
                                key={provider}
                                className="rounded-xl border border-neutral-200 p-4 dark:border-neutral-800"
                            >
                                <div className="mb-2 flex items-center justify-between">
                                    <span className="font-medium">{provider}</span>
                                    {active ? <StatusBadge status={active.status} /> : last && <StatusBadge status={last.status} />}
                                </div>
                                {last ? (
                                    <>
                                        <div className="grid grid-cols-4 gap-2 text-center text-sm">
                                            <Stat label="Eklenen" value={last.stats.added} />
                                            <Stat label="Güncellenen" value={last.stats.updated} />
                                            <Stat label="Silinen" value={last.stats.deleted} />
                                            <Stat label="Değişmeyen" value={last.stats.unchanged} />
                                        </div>
                                        <p className="mt-3 text-xs text-neutral-500">
                                            Son: {formatDate(last.completed_at)} · {last.duration_ms ?? 0} ms · {last.api_requests} istek
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-sm text-neutral-500">Henüz senkronizasyon yok.</p>
                                )}
                                <button
                                    onClick={() => void trigger(provider)}
                                    disabled={busy !== null || active !== undefined}
                                    className="mt-3 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium disabled:opacity-50 dark:border-neutral-700"
                                >
                                    {busy === provider ? 'Kuyruğa alınıyor…' : active ? 'Çalışıyor…' : 'Senkronize Et'}
                                </button>
                            </div>
                        );
                    })}
                </section>

                {/* History */}
                <section>
                    <h2 className="mb-2 text-lg font-semibold">Geçmiş (son 10)</h2>
                    <div className="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-800">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                                <tr>
                                    <th className="px-3 py-2">Provider</th>
                                    <th className="px-3 py-2">Durum</th>
                                    <th className="px-3 py-2">Tetik</th>
                                    <th className="px-3 py-2">+/~/−</th>
                                    <th className="px-3 py-2">Süre</th>
                                    <th className="px-3 py-2">Tarih</th>
                                </tr>
                            </thead>
                            <tbody>
                                {history.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-3 py-4 text-center text-neutral-500">
                                            Kayıt yok.
                                        </td>
                                    </tr>
                                )}
                                {history.map((log) => (
                                    <tr key={log.id} className="border-t border-neutral-100 dark:border-neutral-800">
                                        <td className="px-3 py-2">{log.provider_type}</td>
                                        <td className="px-3 py-2">
                                            <StatusBadge status={log.status} />
                                        </td>
                                        <td className="px-3 py-2">{log.trigger_type}</td>
                                        <td className="px-3 py-2">
                                            {log.stats.added}/{log.stats.updated}/{log.stats.deleted}
                                        </td>
                                        <td className="px-3 py-2">{log.duration_ms ?? 0} ms</td>
                                        <td className="px-3 py-2">{formatDate(log.completed_at ?? log.started_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* Failed jobs */}
                <section>
                    <h2 className="mb-2 text-lg font-semibold">Başarısız Job&apos;lar (DLQ)</h2>
                    <div className="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-800">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                                <tr>
                                    <th className="px-3 py-2">#</th>
                                    <th className="px-3 py-2">Provider</th>
                                    <th className="px-3 py-2">Deneme</th>
                                    <th className="px-3 py-2">Hata</th>
                                    <th className="px-3 py-2">Tarih</th>
                                    <th className="px-3 py-2 text-right">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                {failedJobs.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-3 py-4 text-center text-neutral-500">
                                            Bekleyen başarısız job yok.
                                        </td>
                                    </tr>
                                )}
                                {failedJobs.map((job) => (
                                    <tr key={job.id} className="border-t border-neutral-100 dark:border-neutral-800">
                                        <td className="px-3 py-2">{job.id}</td>
                                        <td className="px-3 py-2">{job.provider_type ?? '—'}</td>
                                        <td className="px-3 py-2">{job.retry_count}</td>
                                        <td className="max-w-[280px] truncate px-3 py-2 text-neutral-500" title={job.exception}>
                                            {job.exception}
                                        </td>
                                        <td className="px-3 py-2">{formatDate(job.failed_at)}</td>
                                        <td className="px-3 py-2 text-right">
                                            <button
                                                onClick={() => void retry(job.id)}
                                                disabled={busy !== null}
                                                className="rounded-lg border border-neutral-300 px-3 py-1 text-xs font-medium disabled:opacity-50 dark:border-neutral-700"
                                            >
                                                {busy === `retry-${job.id}` ? '…' : 'Retry'}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div>
            <div className="text-lg font-semibold">{value}</div>
            <div className="text-xs text-neutral-500">{label}</div>
        </div>
    );
}

SyncDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Sync Dashboard',
            href: syncDashboardRoute(),
        },
    ],
};
