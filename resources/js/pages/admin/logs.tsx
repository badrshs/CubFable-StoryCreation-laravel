import { Head, router } from '@inertiajs/react';
import {
    ChevronDown,
    Download,
    Eraser,
    RefreshCw,
    ScrollText,
    Search,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type LogFile = { name: string; size: string; modified: string };

type Entry = {
    time: string;
    env: string;
    level: string;
    message: string;
    context: string;
};

type Props = {
    files: LogFile[];
    selected: string;
    entries: Entry[];
    counts: Record<string, number>;
    truncated: boolean;
    filters: { level: string; search: string };
};

const LEVEL_TONE: Record<string, string> = {
    emergency: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    alert: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    critical: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    error: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    warning: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    notice: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
    info: 'bg-primary/15 text-primary',
    debug: 'bg-muted text-muted-foreground',
    raw: 'bg-muted text-muted-foreground',
};

function EntryRow({ entry }: { entry: Entry }) {
    const [open, setOpen] = useState(false);
    const expandable = entry.context !== '';

    return (
        <div className="border-b border-card-border/60 last:border-0">
            <button
                type="button"
                onClick={() => expandable && setOpen((value) => !value)}
                className={`flex w-full items-start gap-3 p-3 text-start text-sm ${expandable ? 'cursor-pointer hover:bg-muted/30' : 'cursor-default'}`}
            >
                {entry.level !== 'raw' && (
                    <Badge
                        variant="outline"
                        className={`mt-0.5 shrink-0 border-0 font-mono text-[10px] uppercase ${LEVEL_TONE[entry.level] ?? 'bg-muted'}`}
                    >
                        {entry.level}
                    </Badge>
                )}
                <span className="min-w-0 flex-1 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap">
                    {entry.message}
                </span>
                {entry.time && (
                    <span className="shrink-0 font-mono text-[10px] text-muted-foreground">
                        {entry.time.replace('T', ' ').slice(0, 19)}
                    </span>
                )}
                {expandable && (
                    <ChevronDown
                        className={`mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`}
                    />
                )}
            </button>
            {open && (
                <pre className="max-h-80 overflow-auto bg-muted/30 p-3 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
                    {entry.context}
                </pre>
            )}
        </div>
    );
}

export default function AdminLogs({
    files,
    selected,
    entries,
    counts,
    truncated,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    const visit = (params: Record<string, string>) => {
        router.get(
            '/admin/logs',
            {
                file: selected,
                level: filters.level,
                search,
                ...params,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const totalShown = entries.length;

    return (
        <>
            <Head title="Logs - Admin" />
            <div className="space-y-4 p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 font-serif text-2xl font-semibold">
                            <ScrollText className="h-5 w-5" /> Logs
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Newest entries first
                            {truncated &&
                                ' - large file: showing the last 2 MB only'}
                            .
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={selected}
                            onValueChange={(value) =>
                                visit({ file: value, level: '', search: '' })
                            }
                        >
                            <SelectTrigger className="w-72">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {files.map((file) => (
                                    <SelectItem
                                        key={file.name}
                                        value={file.name}
                                    >
                                        {file.name} - {file.size} -{' '}
                                        {file.modified}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => visit({})}
                            title="Refresh"
                        >
                            <RefreshCw className="h-4 w-4" />
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <a
                                href={`/admin/logs/download?file=${encodeURIComponent(selected)}`}
                            >
                                <Download className="h-4 w-4" /> Download
                            </a>
                        </Button>
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button variant="outline" size="sm">
                                    <Eraser className="h-4 w-4" /> Clear
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>
                                        Delete {selected}?
                                    </AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Deletes this log file for good. This
                                        cannot be undone - download it first if
                                        you may need it.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>
                                        Cancel
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={() =>
                                            router.delete(
                                                `/admin/logs?file=${encodeURIComponent(selected)}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Delete file
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button variant="destructive" size="sm">
                                    <Trash2 className="h-4 w-4" /> Clear all
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>
                                        Delete all logs?
                                    </AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Deletes every log file, including the
                                        per-book logs. This cannot be undone -
                                        download anything you need first.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>
                                        Cancel
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={() =>
                                            router.delete('/admin/logs/all', {
                                                preserveScroll: true,
                                            })
                                        }
                                    >
                                        Clear all
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            visit({});
                        }}
                        className="relative"
                    >
                        <Search className="absolute start-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search entries..."
                            className="w-72 ps-8"
                        />
                    </form>
                    <button
                        type="button"
                        onClick={() => visit({ level: '' })}
                        className={`rounded-full px-3 py-1 text-xs font-semibold transition-colors ${
                            filters.level === ''
                                ? 'bg-foreground text-background'
                                : 'bg-muted text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        all
                    </button>
                    {Object.entries(counts).map(([level, count]) => (
                        <button
                            key={level}
                            type="button"
                            onClick={() =>
                                visit({
                                    level:
                                        filters.level === level ? '' : level,
                                })
                            }
                            className={`rounded-full px-3 py-1 text-xs font-semibold transition-all ${LEVEL_TONE[level] ?? 'bg-muted'} ${
                                filters.level === level
                                    ? 'ring-2 ring-current'
                                    : 'opacity-80 hover:opacity-100'
                            }`}
                        >
                            {level}: {count}
                        </button>
                    ))}
                </div>

                <div className="overflow-hidden rounded-xl border border-card-border bg-card">
                    {entries.map((entry, index) => (
                        <EntryRow key={index} entry={entry} />
                    ))}
                    {totalShown === 0 && (
                        <p className="p-10 text-center text-sm text-muted-foreground">
                            No entries match.
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}
