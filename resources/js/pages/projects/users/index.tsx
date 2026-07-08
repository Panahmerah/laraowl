import { Head, Link, usePage, router } from '@inertiajs/react';
import { ArrowUpRight, AlertCircle, Users } from 'lucide-react';
import { useEffect } from 'react';
import { BarChart, Bar, ResponsiveContainer, Tooltip, XAxis } from 'recharts';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { monitoringQuery } from '@/lib/monitoring-query';
import { formatCompactNumber } from '@/lib/utils';
import { show as showUser } from '@/routes/users';

const scopeFilters = [
    { label: 'Authenticated', value: 'authenticated' },
    { label: 'Guest', value: 'guest' },
    { label: 'All', value: 'all' },
];

export default function UsersIndex({
    users,
    timeSeries = [],
    period,
    from,
    to,
    overview,
    scope = 'authenticated',
}: {
    users: any;
    timeSeries: any;
    period: string;
    from?: string | null;
    to?: string | null;
    overview: any;
    scope?: string;
}) {
    const periodLabel = period?.toUpperCase() || '24H';

    const handleScopeChange = (value: string) => {
        const searchParams = new URLSearchParams(window.location.search);
        searchParams.set('scope', value);
        searchParams.delete('page');

        router.visit(window.location.pathname + '?' + searchParams.toString(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const { props }: any = usePage();
    const teamSlug = props.current_team?.slug || props.currentTeam?.slug;
    const currentProject = props.current_project || props.currentProject;
    const projectSlug =
        props.current_project?.slug || props.currentProject?.slug;

    useEffect(() => {
        if (!currentProject?.id || !window.Echo) {
            return;
        }

        const channel = window.Echo.private(
            `project.${currentProject.id}`,
        ).listen('.ProjectDataIngested', () => {
            router.reload({ preserveScroll: true, preserveState: true } as any);
        });

        return () => {
            channel.stopListening('.ProjectDataIngested');
        };
    }, [currentProject?.id]);

    const data = users.data || [];
    const userDetailsHref = (hash: string | number) =>
        showUser.url(
            {
                current_team: teamSlug,
                project: projectSlug,
                hash,
            },
            {
                ...monitoringQuery({ period, from, to }),
            },
        );

    return (
        <>
            <Head title="Users" />

            <div className="mb-8 space-y-4"></div>

            <div className="space-y-8">
                {/* Stats Cards */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <Card className="overflow-hidden border-border bg-card shadow-2xl">
                        <CardContent className="p-6">
                            <div className="mb-6 flex items-start justify-between">
                                <div>
                                    <div className="mb-1 text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                        Authenticated Users ({periodLabel})
                                    </div>
                                    <div className="text-3xl font-bold text-foreground">
                                        {formatCompactNumber(
                                            overview.auth_users,
                                        )}
                                    </div>
                                </div>
                            </div>
                            <div className="h-[120px] w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={timeSeries}>
                                        <XAxis dataKey="minute" hide />
                                        <Tooltip
                                            content={({ active, payload }) => {
                                                if (
                                                    active &&
                                                    payload &&
                                                    payload.length
                                                ) {
                                                    return (
                                                        <div className="rounded-lg border border-border bg-background/95 p-2 shadow-xl backdrop-blur-sm">
                                                            <div className="mb-1.5 border-b border-border/50 pb-1 text-[9px] font-bold tracking-tight text-muted-foreground uppercase">
                                                                {
                                                                    payload[0]
                                                                        .payload
                                                                        .minute
                                                                }
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                <div className="h-2 w-2 rounded-full bg-emerald-500" />
                                                                <span className="text-[10px] font-medium text-muted-foreground uppercase">
                                                                    Active
                                                                    Users:
                                                                </span>
                                                                <span className="text-[10px] font-bold text-foreground">
                                                                    {formatCompactNumber(
                                                                        payload[0]
                                                                            .value,
                                                                    )}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    );
                                                }

                                                return null;
                                            }}
                                        />
                                        <Bar
                                            dataKey="active_users"
                                            name="Active Users"
                                            fill="rgba(34, 197, 94, 0.4)"
                                            radius={[2, 2, 0, 0]}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="overflow-hidden border-border bg-card shadow-2xl">
                        <CardContent className="p-6">
                            <div className="mb-6 flex items-start justify-between">
                                <div>
                                    <div className="mb-1 text-[10px] font-bold tracking-widest text-muted-foreground uppercase">
                                        Requests ({periodLabel})
                                    </div>
                                    <div className="text-3xl font-bold text-foreground">
                                        {formatCompactNumber(
                                            overview.auth_requests +
                                                overview.guest_requests,
                                        )}
                                    </div>
                                </div>
                                <div className="flex gap-4 text-[10px] font-bold tracking-tighter uppercase">
                                    <div className="flex items-center gap-1.5">
                                        <span className="h-2 w-2 rounded-full bg-emerald-500"></span>{' '}
                                        Authenticated{' '}
                                        <span className="ml-1 text-foreground">
                                            {formatCompactNumber(
                                                overview.auth_requests,
                                            )}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <span className="h-2 w-2 rounded-full bg-orange-500"></span>{' '}
                                        Guest{' '}
                                        <span className="ml-1 text-foreground">
                                            {formatCompactNumber(
                                                overview.guest_requests,
                                            )}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div className="relative h-[120px] w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={timeSeries}>
                                        <XAxis dataKey="minute" hide />
                                        <Tooltip
                                            content={({ active, payload }) => {
                                                if (
                                                    active &&
                                                    payload &&
                                                    payload.length
                                                ) {
                                                    return (
                                                        <div className="rounded-lg border border-border bg-background/95 p-2 shadow-xl backdrop-blur-sm">
                                                            <div className="mb-1.5 border-b border-border/50 pb-1 text-[9px] font-bold tracking-tight text-muted-foreground uppercase">
                                                                {
                                                                    payload[0]
                                                                        .payload
                                                                        .minute
                                                                }
                                                            </div>
                                                            <div className="grid gap-1">
                                                                {payload.map(
                                                                    (
                                                                        entry: any,
                                                                        index: number,
                                                                    ) => (
                                                                        <div
                                                                            key={
                                                                                index
                                                                            }
                                                                            className="flex items-center gap-2"
                                                                        >
                                                                            <div
                                                                                className="h-2 w-2 rounded-full"
                                                                                style={{
                                                                                    backgroundColor:
                                                                                        entry.color,
                                                                                }}
                                                                            />
                                                                            <span className="text-[10px] font-medium text-muted-foreground uppercase">
                                                                                {
                                                                                    entry.name
                                                                                }

                                                                                :
                                                                            </span>
                                                                            <span className="text-[10px] font-bold text-foreground">
                                                                                {formatCompactNumber(
                                                                                    entry.value,
                                                                                )}
                                                                            </span>
                                                                        </div>
                                                                    ),
                                                                )}
                                                            </div>
                                                        </div>
                                                    );
                                                }

                                                return null;
                                            }}
                                        />
                                        <Bar
                                            dataKey="ok"
                                            name="1/2/3xx"
                                            fill="rgba(255,255,255,0.4)"
                                            radius={[2, 2, 0, 0]}
                                            stackId="a"
                                        />
                                        <Bar
                                            dataKey="client_error"
                                            name="4xx"
                                            fill="#f97316"
                                            radius={[2, 2, 0, 0]}
                                            stackId="a"
                                        />
                                        <Bar
                                            dataKey="server_error"
                                            name="5xx"
                                            fill="#ef4444"
                                            radius={[2, 2, 0, 0]}
                                            stackId="a"
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Table Section */}
                <div className="space-y-4">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2 font-bold text-foreground">
                            <Users className="h-4 w-4 text-muted-foreground" />
                            <span>
                                {formatCompactNumber(users.total || 0)} Users
                            </span>
                        </div>
                        <div className="flex rounded-md border border-border bg-muted p-1">
                            {scopeFilters.map((filter) => (
                                <Button
                                    key={filter.value}
                                    variant={
                                        scope === filter.value
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    size="sm"
                                    className={`h-7 px-3 text-[10px] font-bold tracking-tight uppercase ${scope === filter.value ? 'bg-primary text-primary-foreground shadow-lg hover:bg-primary/90' : 'text-muted-foreground hover:text-foreground'}`}
                                    onClick={() =>
                                        handleScopeChange(filter.value)
                                    }
                                >
                                    {filter.label}
                                </Button>
                            ))}
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-border bg-card">
                        {data.length > 0 ? (
                            <>
                                <Table>
                                    <TableHeader className="bg-muted/30">
                                        <TableRow className="border-border text-[10px] font-bold text-muted-foreground uppercase hover:bg-transparent">
                                            <TableHead className="pl-6">
                                                User
                                            </TableHead>
                                            <TableHead className="text-center">
                                                1/2/3xx
                                            </TableHead>
                                            <TableHead className="text-center">
                                                4xx
                                            </TableHead>
                                            <TableHead className="text-center">
                                                5xx
                                            </TableHead>
                                            <TableHead className="text-center">
                                                Requests
                                            </TableHead>
                                            <TableHead className="text-center">
                                                Queued Jobs
                                            </TableHead>
                                            <TableHead className="text-center">
                                                Exceptions
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Last Seen
                                            </TableHead>
                                            <TableHead className="w-[50px] pr-6"></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {data.map((u: any, index: number) => (
                                            <TableRow
                                                key={index}
                                                className="group border-border transition-colors hover:bg-muted/30"
                                            >
                                                <TableCell className="pl-6">
                                                    <Link
                                                        href={userDetailsHref(
                                                            u.hash,
                                                        )}
                                                    >
                                                        <div className="flex cursor-pointer items-center gap-3">
                                                            <div className="flex flex-col">
                                                                <span className="text-xs font-bold text-foreground/90 transition-colors group-hover:text-foreground">
                                                                    {
                                                                        u.user_name
                                                                    }
                                                                </span>
                                                                <span className="font-mono text-[10px] text-muted-foreground">
                                                                    {u.user_email ||
                                                                        (u.user_name !==
                                                                            u.user_id &&
                                                                            `ID: ${u.user_id}`)}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="text-center font-mono text-xs text-foreground/60">
                                                    {formatCompactNumber(
                                                        (u.total_requests ||
                                                            0) -
                                                            (u.error_count ||
                                                                0),
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-center font-mono text-xs font-bold text-orange-500">
                                                    {formatCompactNumber(
                                                        u.error_count || 0,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-center font-mono text-xs font-bold text-red-500">
                                                    0
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    <div className="flex items-center justify-center gap-1.5">
                                                        <span className="font-mono text-xs font-bold text-foreground">
                                                            {formatCompactNumber(
                                                                u.total_requests ||
                                                                    0,
                                                            )}
                                                        </span>
                                                        {u.error_count > 0 && (
                                                            <AlertCircle className="h-3 w-3 text-red-500" />
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-center font-mono text-xs text-muted-foreground">
                                                    0
                                                </TableCell>
                                                <TableCell className="text-center font-mono text-xs text-red-400">
                                                    {formatCompactNumber(
                                                        u.exception_count || 0,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-xs whitespace-nowrap text-muted-foreground">
                                                    {new Date(
                                                        u.last_seen,
                                                    ).toLocaleString()}
                                                </TableCell>
                                                <TableCell className="pr-6">
                                                    <Link
                                                        href={userDetailsHref(
                                                            u.hash,
                                                        )}
                                                    >
                                                        <div className="cursor-pointer rounded border border-border bg-muted p-1 text-muted-foreground transition-all group-hover:border-border group-hover:text-foreground">
                                                            <ArrowUpRight className="h-3 w-3" />
                                                        </div>
                                                    </Link>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                <Pagination links={users.links} meta={users} />
                            </>
                        ) : (
                            <div className="p-12">
                                <EmptyState
                                    title="No Users Tracked"
                                    description={
                                        scope === 'guest'
                                            ? "We haven't captured any guest session activity for this project yet."
                                            : "We haven't captured any authenticated user activity for this project yet."
                                    }
                                    icon={Users}
                                />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

UsersIndex.layout = (page: any) => (
    <AppLayout children={page} breadcrumbs={[{ title: 'Users', href: '#' }]} />
);
