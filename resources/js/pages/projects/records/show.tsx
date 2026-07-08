import { Head, usePage } from '@inertiajs/react';
import {
    Activity,
    Globe,
    Database,
    Mail,
    Bell,
    Layers,
    ChevronDown,
    ChevronRight,
    FileCode,
    AlertTriangle,
} from 'lucide-react';
import { useState } from 'react';
import { Prism as SyntaxHighlighter } from 'react-syntax-highlighter';
import { vscDarkPlus } from 'react-syntax-highlighter/dist/esm/styles/prism';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

export default function RecordShow({
    record,
    relatedRecords = [],
    highlight_record_id,
    linked_exception,
}: {
    record: any;
    relatedRecords?: any[];
    highlight_record_id?: number;
    linked_exception?: any;
}) {
    usePage();
    const payload = record.payload || {};
    const [expandedHeaders, setExpandedHeaders] = useState(false);

    const getStatusColor = (status: number) => {
        if (status >= 500) {
            return 'text-red-500 border-red-500/20 bg-red-500/5';
        }

        if (status >= 400) {
            return 'text-orange-500 border-orange-500/20 bg-orange-500/5';
        }

        return 'text-emerald-500 border-emerald-500/20 bg-emerald-500/5';
    };

    // Trace frames are stored as a JSON-encoded string; each frame's `file`
    // packs "path:line" together and `code` is a map of line number -> source line.
    const parseTraceFrames = (
        raw: unknown,
    ): {
        path: string;
        line?: number;
        source: string;
        code: [number, string][];
    }[] => {
        let frames: any[] = [];

        if (Array.isArray(raw)) {
            frames = raw;
        } else if (typeof raw === 'string' && raw.trim()) {
            try {
                const parsed = JSON.parse(raw);
                frames = Array.isArray(parsed) ? parsed : [];
            } catch {
                frames = [];
            }
        }

        return frames.map((frame) => {
            const rawFile: string = frame.file || '';
            const separatorIndex = rawFile.lastIndexOf(':');
            const path =
                separatorIndex === -1
                    ? rawFile
                    : rawFile.slice(0, separatorIndex);
            const parsedLine =
                separatorIndex === -1
                    ? NaN
                    : Number(rawFile.slice(separatorIndex + 1));

            const code: [number, string][] = frame.code
                ? Object.entries(frame.code)
                      .map(
                          ([lineNumber, text]) =>
                              [Number(lineNumber), text as string] as [
                                  number,
                                  string,
                              ],
                      )
                      .sort((a, b) => a[0] - b[0])
                : [];

            return {
                path: path || 'unknown',
                line: Number.isNaN(parsedLine) ? undefined : parsedLine,
                source: frame.source || '',
                code,
            };
        });
    };

    const queries = relatedRecords.filter((r) => r.type === 'query');
    const logs = relatedRecords.filter((r) => r.type === 'log');

    const events = [
        {
            label: 'QUERIES',
            icon: Database,
            count: queries.length,
            duration:
                queries.reduce((acc, r) => acc + (r.payload.duration || 0), 0) /
                1000,
        },
        {
            label: 'MAIL',
            icon: Mail,
            count: payload.mail_count || 0,
            duration: 0,
        },
        {
            label: 'CACHE',
            icon: Layers,
            count: payload.cache_count || 0,
            duration: 0,
        },
        {
            label: 'OUTGOING REQUESTS',
            icon: Globe,
            count: payload.outgoing_count || 0,
            duration: 0,
        },
        {
            label: 'NOTIFICATIONS',
            icon: Bell,
            count: payload.notification_count || 0,
            duration: 0,
        },
        {
            label: 'QUEUED JOBS',
            icon: Activity,
            count: payload.job_count || 0,
            duration: 0,
        },
    ];

    const isRequest = record.type === 'request';
    const isJob = ['job-attempt', 'queued-job'].includes(record.type);
    const isCommand = ['command', 'scheduled-task'].includes(record.type);
    const isQuery = record.type === 'query';
    const isException = record.type === 'exception';
    const isFailedRequest = isRequest && Number(payload.status_code) >= 400;
    const errorPayload = isException
        ? payload
        : (linked_exception?.payload ?? null);
    const errorIssue = isException
        ? record.issue
        : (linked_exception?.issue ?? null);
    const stackTrace = parseTraceFrames(errorPayload?.trace);

    const title = isException
        ? payload.class || 'Exception'
        : isJob
          ? payload.name || payload.job || 'Job Execution'
          : isCommand
            ? payload.command || 'Command Execution'
            : isQuery
              ? 'Database Query'
              : payload.route_path || record.type.toUpperCase();

    const subTitle = isException
        ? payload.message || ''
        : isRequest
          ? payload.url || `https://${payload.server}${payload.route_path}`
          : isJob
            ? `${payload.connection || 'default'} @ ${payload.queue || 'default'}`
            : isCommand
              ? payload.arguments || 'No arguments'
              : '';

    return (
        <>
            <Head title={`${record.type.toUpperCase()} Details - ${title}`} />

            <div className="mb-8 space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            {title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {subTitle}
                        </p>
                    </div>

                    <Badge
                        variant="outline"
                        className={
                            isException
                                ? 'rounded border-red-500/20 bg-red-500/10 px-3 py-1 text-xs font-bold text-red-500 uppercase'
                                : 'rounded border-border bg-muted px-3 py-1 text-xs font-bold text-foreground uppercase'
                        }
                    >
                        {isException
                            ? 'Exception'
                            : payload.method ||
                              record.type.replace('-', ' ').toUpperCase()}
                    </Badge>
                </div>
            </div>

            <div className="max-w-7xl space-y-6">
                {/* Main Info Card */}
                <Card className="border-border bg-card p-8 shadow-2xl">
                    <div className="space-y-6">
                        {isException ? (
                            <div className="flex items-center gap-3 font-mono text-sm text-red-400">
                                <AlertTriangle className="h-4 w-4" />
                                <span className="break-all">
                                    {payload.file
                                        ? `${payload.file}:${payload.line}`
                                        : 'No file/line captured'}
                                </span>
                            </div>
                        ) : (
                            <div className="flex items-center gap-3 font-mono text-sm text-emerald-400">
                                <Globe className="h-4 w-4" />
                                <span className="break-all">
                                    {payload.url ||
                                        `https://${payload.server}${payload.route_path}`}
                                </span>
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-x-12 gap-y-4 md:grid-cols-2">
                            <div className="flex items-center justify-between border-b border-border py-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                    Date
                                </span>
                                <span className="font-mono text-sm text-foreground/90">
                                    {new Date(
                                        record.created_at,
                                    ).toLocaleString()}{' '}
                                    UTC
                                </span>
                            </div>

                            {isRequest && (
                                <>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Status Code
                                        </span>
                                        <Badge
                                            className={getStatusColor(
                                                payload.status_code,
                                            )}
                                        >
                                            {payload.status_code}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Response Size
                                        </span>
                                        <span className="font-mono text-sm text-foreground/90">
                                            {(
                                                (payload.response_size || 0) /
                                                1024
                                            ).toFixed(2)}{' '}
                                            KB
                                        </span>
                                    </div>
                                </>
                            )}

                            {isJob && (
                                <>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Status
                                        </span>
                                        <Badge
                                            className={
                                                payload.status === 'processed'
                                                    ? 'bg-emerald-500/10 text-emerald-500'
                                                    : 'bg-red-500/10 text-red-500'
                                            }
                                        >
                                            {payload.status || 'STARTED'}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Tries
                                        </span>
                                        <span className="font-mono text-sm text-foreground/90">
                                            {payload.tries || 1}
                                        </span>
                                    </div>
                                </>
                            )}

                            {isCommand && (
                                <div className="flex items-center justify-between border-b border-border py-2">
                                    <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                        Exit Code
                                    </span>
                                    <Badge
                                        className={
                                            payload.exit_code === 0
                                                ? 'bg-emerald-500/10 text-emerald-500'
                                                : 'bg-red-500/10 text-red-500'
                                        }
                                    >
                                        {payload.exit_code ?? 'N/A'}
                                    </Badge>
                                </div>
                            )}

                            {isException && record.issue && (
                                <>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Issue Status
                                        </span>
                                        <Badge
                                            className={
                                                record.issue.status ===
                                                'resolved'
                                                    ? 'bg-emerald-500/10 text-emerald-500'
                                                    : 'bg-red-500/10 text-red-500'
                                            }
                                        >
                                            {record.issue.status}
                                        </Badge>
                                    </div>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Priority
                                        </span>
                                        <span className="font-mono text-sm text-foreground/90">
                                            {record.issue.priority}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Occurrences
                                        </span>
                                        <span className="font-mono text-sm text-foreground/90">
                                            {record.issue.occurrences_count}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between border-b border-border py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Users Affected
                                        </span>
                                        <span className="font-mono text-sm text-foreground/90">
                                            {record.issue.users_count}
                                        </span>
                                    </div>
                                </>
                            )}

                            <div className="flex items-center justify-between border-b border-border py-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                    Server
                                </span>
                                <span className="font-mono text-sm text-foreground/90">
                                    {payload.server || 'Unknown'}
                                </span>
                            </div>

                            <div className="flex items-center justify-between py-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                    Peak Memory
                                </span>
                                <span className="font-mono text-sm text-foreground/90">
                                    {(
                                        (payload.peak_memory_usage || 0) /
                                        1024 /
                                        1024
                                    ).toFixed(2)}{' '}
                                    MB
                                </span>
                            </div>
                        </div>

                        <div className="pt-6">
                            <h3 className="mb-4 text-xs font-bold text-foreground uppercase">
                                User
                            </h3>
                            <div className="flex items-center justify-between border-b border-border py-2">
                                <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                    IP
                                </span>
                                <span className="font-mono text-sm text-foreground/90">
                                    {payload.ip || '127.0.0.1'}
                                </span>
                            </div>
                        </div>

                        <div className="pt-6">
                            <div className="mb-4 flex items-center justify-between">
                                <h3 className="text-xs font-bold text-foreground uppercase">
                                    Events
                                </h3>
                                <div className="flex gap-4 rounded bg-muted px-2 py-1 text-[9px] font-bold uppercase">
                                    <span>
                                        Events{' '}
                                        <span className="ml-1 text-foreground">
                                            {queries.length + logs.length}
                                        </span>
                                    </span>
                                    <span>
                                        Duration{' '}
                                        <span className="ml-1 text-foreground">
                                            {(
                                                (payload.duration || 0) / 1000
                                            ).toFixed(2)}
                                            ms
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-x-12 md:grid-cols-2">
                                {events.map((event, i) => (
                                    <div
                                        key={i}
                                        className="flex items-center justify-between border-b border-border py-2"
                                    >
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            {event.label}
                                        </span>
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono text-sm text-foreground/90">
                                                {event.count} Events
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </Card>

                {/* Error Summary Card (failed requests only — exceptions already show this in the header) */}
                {isFailedRequest && (
                    <Card className="border-red-500/20 bg-red-500/5 p-6 shadow-2xl">
                        <div className="mb-4 flex items-center gap-2">
                            <AlertTriangle className="h-4 w-4 text-red-500" />
                            <h3 className="text-xs font-bold text-red-500 uppercase">
                                Error
                            </h3>
                        </div>

                        {errorPayload ? (
                            <div className="space-y-3">
                                <div className="flex items-center justify-between border-b border-red-500/10 py-2">
                                    <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                        Exception
                                    </span>
                                    <span className="font-mono text-sm text-red-400">
                                        {errorPayload.class || 'Unknown'}
                                    </span>
                                </div>
                                <div className="border-b border-red-500/10 py-2">
                                    <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                        Message
                                    </span>
                                    <p className="mt-1 font-mono text-sm break-all text-foreground/90">
                                        {errorPayload.message ||
                                            'No message captured'}
                                    </p>
                                </div>
                                <div className="flex items-center justify-between border-b border-red-500/10 py-2">
                                    <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                        Location
                                    </span>
                                    <span className="font-mono text-xs text-foreground/90">
                                        {errorPayload.file
                                            ? `${errorPayload.file}:${errorPayload.line}`
                                            : 'Unknown'}
                                    </span>
                                </div>
                                {errorIssue && (
                                    <div className="flex items-center justify-between py-2">
                                        <span className="text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                            Issue
                                        </span>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                className={
                                                    errorIssue.status ===
                                                    'resolved'
                                                        ? 'bg-emerald-500/10 text-emerald-500'
                                                        : 'bg-red-500/10 text-red-500'
                                                }
                                            >
                                                {errorIssue.status}
                                            </Badge>
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {errorIssue.occurrences_count}{' '}
                                                occurrences
                                            </span>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <p className="text-xs text-foreground/90">
                                    {payload.exception_preview ||
                                        'No exception details captured for this request.'}
                                </p>
                                {payload.exceptions ? (
                                    <p className="text-[10px] text-muted-foreground">
                                        {payload.exceptions} exception(s)
                                        recorded during this request.
                                    </p>
                                ) : null}
                            </div>
                        )}
                    </Card>
                )}

                {/* Stack Trace Card */}
                {errorPayload && (
                    <Card className="border-border bg-card p-8 shadow-2xl">
                        <div className="mb-6 flex items-center gap-2">
                            <FileCode className="h-4 w-4 text-red-500" />
                            <h3 className="text-xs font-bold text-foreground uppercase">
                                Stack Trace
                            </h3>
                        </div>

                        {stackTrace.length > 0 ? (
                            <div className="space-y-4">
                                {stackTrace.slice(0, 20).map((frame, i) => (
                                    <Card
                                        key={i}
                                        className="overflow-hidden border-border bg-card/50 shadow-sm"
                                    >
                                        <div className="flex items-center justify-between border-b border-border/50 bg-muted/30 px-4 py-2">
                                            <div className="flex items-center gap-2 truncate">
                                                <span className="text-[10px] font-black text-red-500/60">
                                                    #{i}
                                                </span>
                                                <code className="truncate text-[10px] font-bold text-foreground">
                                                    {frame.path}
                                                    {frame.line !== undefined
                                                        ? `:${frame.line}`
                                                        : ''}
                                                </code>
                                            </div>
                                            {frame.source && (
                                                <span className="truncate text-[9px] font-black text-muted-foreground uppercase">
                                                    {frame.source}
                                                </span>
                                            )}
                                        </div>
                                        {frame.code.length > 0 && (
                                            <SyntaxHighlighter
                                                language="php"
                                                style={vscDarkPlus}
                                                customStyle={{
                                                    margin: 0,
                                                    borderRadius: 0,
                                                    fontSize: '11px',
                                                    padding: '1rem',
                                                }}
                                                showLineNumbers={true}
                                                startingLineNumber={
                                                    frame.code[0][0]
                                                }
                                                wrapLines={true}
                                                lineProps={(lineNum) => {
                                                    const style: any = {
                                                        display: 'block',
                                                    };

                                                    if (
                                                        lineNum === frame.line
                                                    ) {
                                                        style.backgroundColor =
                                                            'rgba(239, 68, 68, 0.1)';
                                                        style.borderLeft =
                                                            '2px solid rgb(239, 68, 68)';
                                                    }

                                                    return { style };
                                                }}
                                            >
                                                {frame.code
                                                    .map(([, text]) => text)
                                                    .join('\n')}
                                            </SyntaxHighlighter>
                                        )}
                                    </Card>
                                ))}
                            </div>
                        ) : (
                            <p className="text-xs text-muted-foreground italic">
                                No stack trace captured for this exception.
                            </p>
                        )}
                    </Card>
                )}

                {/* Headers Card */}
                <Card className="border-border bg-card shadow-2xl">
                    <div
                        className="flex cursor-pointer items-center justify-between p-4 transition-colors hover:bg-muted/30"
                        onClick={() => setExpandedHeaders(!expandedHeaders)}
                    >
                        <h3 className="text-xs font-bold text-foreground uppercase">
                            Headers
                        </h3>
                        <div className="flex h-5 w-5 items-center justify-center rounded bg-muted">
                            {expandedHeaders ? (
                                <ChevronDown className="h-3 w-3" />
                            ) : (
                                <ChevronRight className="h-3 w-3" />
                            )}
                        </div>
                    </div>
                    {expandedHeaders && (
                        <div className="border-t border-border p-6 text-xs">
                            {(() => {
                                let headersObj = payload.headers || {};

                                if (typeof headersObj === 'string') {
                                    try {
                                        headersObj = JSON.parse(headersObj);
                                    } catch {
                                        // ignore
                                    }
                                }

                                return (
                                    <SyntaxHighlighter
                                        language="json"
                                        style={vscDarkPlus}
                                        customStyle={{
                                            margin: 0,
                                            borderRadius: '0.5rem',
                                            border: '1px solid hsl(var(--border))',
                                        }}
                                        wrapLines={true}
                                        wrapLongLines={true}
                                    >
                                        {JSON.stringify(headersObj, null, 4)}
                                    </SyntaxHighlighter>
                                );
                            })()}
                        </div>
                    )}
                </Card>

                {/* Timeline Card */}
                <Card className="border-border bg-card p-8 shadow-2xl">
                    <div className="mb-8 flex items-center justify-between">
                        <h3 className="text-xs font-bold text-foreground uppercase">
                            Timeline
                        </h3>
                        <div className="flex gap-12 text-[10px] font-bold text-muted-foreground uppercase">
                            <span>0ms</span>
                            <span>
                                {((payload.duration || 0) / 1000).toFixed(0)}ms
                            </span>
                        </div>
                    </div>

                    <div className="space-y-4">
                        <div className="relative border-l border-border pl-4">
                            <div className="mb-2 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <ChevronDown className="h-3 w-3 text-muted-foreground" />
                                    <span className="text-[10px] font-bold tracking-widest uppercase">
                                        Request {payload.route_path}
                                    </span>
                                </div>
                                <div className="flex h-5 items-center gap-2 rounded border border-emerald-500/30 bg-emerald-500/20 px-2">
                                    <span className="text-[9px] font-bold text-emerald-400 uppercase">
                                        {record.type.toUpperCase()}
                                    </span>
                                    {isRequest && (
                                        <Badge className="h-3 rounded-sm border-none bg-emerald-500 px-1 text-[9px]">
                                            {payload.status_code}
                                        </Badge>
                                    )}
                                    <span className="font-mono text-[10px] text-foreground">
                                        {(
                                            (payload.duration || 0) / 1000
                                        ).toFixed(2)}
                                        ms
                                    </span>
                                    <span className="font-mono text-[10px] text-muted-foreground">
                                        {title}
                                    </span>
                                </div>
                            </div>

                            {/* Sub-items (Dynamic from relatedRecords) */}
                            <div className="mt-4 ml-4 space-y-3">
                                {isRequest && (
                                    <>
                                        <div className="group flex items-center justify-between">
                                            <span className="text-[10px] font-bold tracking-tighter text-muted-foreground/60 uppercase">
                                                Bootstrap
                                            </span>
                                            <div className="flex h-6 w-[70%] items-center justify-between rounded border border-border bg-muted px-3">
                                                <span className="text-[10px] font-bold uppercase">
                                                    Bootstrap
                                                </span>
                                                <span className="font-mono text-[10px]">
                                                    {(
                                                        (payload.bootstrap ||
                                                            0) / 1000
                                                    ).toFixed(2)}
                                                    ms
                                                </span>
                                            </div>
                                        </div>

                                        {relatedRecords.map((sub, i) => (
                                            <div
                                                key={i}
                                                className={`group flex items-center justify-between ${highlight_record_id === sub.id ? 'rounded ring-1 ring-blue-500 ring-offset-2 ring-offset-[#111111]' : ''}`}
                                            >
                                                <div className="ml-4 flex items-center gap-2">
                                                    <div
                                                        className={`h-1.5 w-1.5 rounded-full border ${sub.type === 'query' ? 'border-blue-500/50' : 'border-red-500/50'}`}
                                                    ></div>
                                                    <span
                                                        className={`text-[10px] font-bold tracking-tighter uppercase ${sub.type === 'query' ? 'text-blue-500' : 'text-red-500'}`}
                                                    >
                                                        {sub.type}
                                                    </span>
                                                    <span className="line-clamp-1 max-w-[400px] font-mono text-[10px] text-muted-foreground">
                                                        {sub.type === 'query'
                                                            ? sub.payload.sql
                                                            : sub.payload
                                                                  .message ||
                                                              'Log event'}
                                                    </span>
                                                </div>
                                                <div
                                                    className={`flex h-6 min-w-[80px] items-center justify-between rounded border px-3 ${sub.type === 'query' ? 'border-blue-500/20 bg-blue-500/10' : 'border-red-500/20 bg-red-500/10'}`}
                                                >
                                                    <span
                                                        className={`text-[10px] font-bold uppercase ${sub.type === 'query' ? 'text-blue-400' : 'text-red-400'}`}
                                                    >
                                                        {sub.type}
                                                    </span>
                                                    <span
                                                        className={`font-mono text-[10px] ${sub.type === 'query' ? 'text-blue-400' : 'text-red-400'}`}
                                                    >
                                                        {sub.type === 'query'
                                                            ? (
                                                                  (sub.payload
                                                                      .duration ||
                                                                      0) / 1000
                                                              ).toFixed(2) +
                                                              'ms'
                                                            : ''}
                                                    </span>
                                                </div>
                                            </div>
                                        ))}

                                        <div className="group flex items-center justify-between">
                                            <span className="text-[10px] font-bold tracking-tighter text-muted-foreground/60 uppercase">
                                                Controller
                                            </span>
                                            <div className="flex h-6 w-[40%] items-center justify-between rounded border border-border bg-muted px-3">
                                                <span className="text-[10px] font-bold uppercase">
                                                    Controller
                                                </span>
                                                <span className="font-mono text-[10px]">
                                                    {(
                                                        (payload.render || 0) /
                                                        1000
                                                    ).toFixed(2)}
                                                    ms
                                                </span>
                                            </div>
                                        </div>
                                    </>
                                )}

                                {!isRequest && (
                                    <div className="flex items-center justify-center p-8 text-xs text-muted-foreground italic">
                                        Detailed timeline not available for this
                                        record type.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </>
    );
}

RecordShow.layout = (page: any) => (
    <AppLayout
        children={page}
        breadcrumbs={[
            { title: 'Records', href: '#' },
            { title: 'Details', href: '#' },
        ]}
    />
);
