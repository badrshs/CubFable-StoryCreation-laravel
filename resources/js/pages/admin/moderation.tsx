import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronDown,
    ImageOff,
    Pencil,
    RefreshCw,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';

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

type Attempt = {
    id: number;
    attempt: number;
    round: number;
    variant: string;
    provider: string | null;
    model: string | null;
    accepted: boolean;
    error: string | null;
    prompt: string;
    createdAt: string | null;
};

type Item = {
    type: 'cover' | 'page';
    bookId: number;
    childName: string;
    userEmail: string;
    pageId: number | null;
    pageNumber: number | null;
    flaggedAt: string | null;
    imageUrl: string | null;
    sceneAction: string | null;
    sceneDetail: string | null;
    attempts: Attempt[];
};

type ReplicateEngine = { provider: string; model: string; label: string };

type Props = {
    items: Item[];
    engines: { providers: string[]; replicate: ReplicateEngine[] };
};

function timestamp(value: string | null): string {
    return value ? value.replace('T', ' ').slice(0, 19) : '';
}

function AttemptRow({ attempt }: { attempt: Attempt }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="border-b border-card-border/60 last:border-0">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-start text-xs hover:bg-muted/30"
            >
                <Badge
                    variant="outline"
                    className="shrink-0 border-0 bg-muted font-mono text-[10px]"
                >
                    R{attempt.round} #{attempt.attempt}
                </Badge>
                <span className="shrink-0 font-mono text-[10px] text-muted-foreground">
                    {attempt.variant}
                </span>
                <span className="shrink-0 font-mono text-[10px]">
                    {attempt.provider}
                    {attempt.model ? `:${attempt.model}` : ''}
                </span>
                {attempt.accepted ? (
                    <span className="flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                        <CheckCircle2 className="h-3.5 w-3.5" /> accepted
                    </span>
                ) : (
                    <span className="min-w-0 flex-1 truncate text-rose-600 dark:text-rose-400">
                        {attempt.error ?? 'failed'}
                    </span>
                )}
                <span className="ms-auto shrink-0 font-mono text-[10px] text-muted-foreground">
                    {timestamp(attempt.createdAt)}
                </span>
                <ChevronDown
                    className={`h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`}
                />
            </button>
            {open && (
                <div className="space-y-2 bg-muted/20 px-3 pb-3 text-xs">
                    {attempt.error && (
                        <p className="pt-2 font-mono text-rose-600 dark:text-rose-400">
                            {attempt.error}
                        </p>
                    )}
                    <pre className="max-h-64 overflow-auto rounded-md bg-background p-3 whitespace-pre-wrap">
                        {attempt.prompt}
                    </pre>
                </div>
            )}
        </div>
    );
}

function FlaggedCard({
    item,
    engines,
}: {
    item: Item;
    engines: Props['engines'];
}) {
    const [editing, setEditing] = useState(false);
    const [action, setAction] = useState(item.sceneAction ?? '');
    const [detail, setDetail] = useState(item.sceneDetail ?? '');
    const [engine, setEngine] = useState('default');
    const [busy, setBusy] = useState(false);

    const target =
        item.type === 'cover' ? 'cover' : `page-${item.pageNumber ?? 0}`;

    const retry = () => {
        const selected = engines.replicate.find(
            (candidate) =>
                `${candidate.provider}:${candidate.model}` === engine,
        );

        setBusy(true);
        router.post(
            `/admin/books/${item.bookId}/images/regenerate`,
            {
                target,
                provider: selected?.provider ?? null,
                model: selected?.model ?? null,
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    const dismiss = () => {
        const url =
            item.type === 'cover'
                ? `/admin/moderation/books/${item.bookId}/cover/dismiss`
                : `/admin/moderation/pages/${item.pageId}/dismiss`;

        router.post(url, {}, { preserveScroll: true });
    };

    const saveScene = () => {
        router.put(
            `/admin/moderation/pages/${item.pageId}/scene`,
            { action, detail },
            { preserveScroll: true, onSuccess: () => setEditing(false) },
        );
    };

    return (
        <div className="overflow-hidden rounded-xl border border-card-border bg-card">
            <div className="flex flex-wrap items-center gap-3 border-b border-card-border/60 p-4">
                <ShieldAlert className="h-5 w-5 shrink-0 text-amber-500" />
                <div className="min-w-0">
                    <p className="text-sm font-semibold">
                        <Link
                            href={`/admin/books/${item.bookId}`}
                            className="hover:underline"
                        >
                            {item.childName} (book #{item.bookId})
                        </Link>{' '}
                        &middot;{' '}
                        {item.type === 'cover'
                            ? 'Cover'
                            : `Page ${item.pageNumber}`}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {item.userEmail} &middot; flagged{' '}
                        {timestamp(item.flaggedAt)}
                    </p>
                </div>
                <div className="ms-auto flex flex-wrap items-center gap-2">
                    <Select value={engine} onValueChange={setEngine}>
                        <SelectTrigger className="h-8 w-56 text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="default">
                                Book&apos;s engine (default)
                            </SelectItem>
                            {engines.replicate.map((candidate) => (
                                <SelectItem
                                    key={`${candidate.provider}:${candidate.model}`}
                                    value={`${candidate.provider}:${candidate.model}`}
                                >
                                    {candidate.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button size="sm" onClick={retry} disabled={busy}>
                        <RefreshCw
                            className={`h-4 w-4 ${busy ? 'animate-spin' : ''}`}
                        />
                        Retry
                    </Button>
                    <Button size="sm" variant="outline" onClick={dismiss}>
                        Dismiss
                    </Button>
                </div>
            </div>

            <div className="grid gap-4 p-4 lg:grid-cols-[160px_1fr]">
                <div className="flex aspect-[3/4] w-40 items-center justify-center overflow-hidden rounded-lg bg-muted">
                    {item.imageUrl ? (
                        <img
                            src={item.imageUrl}
                            alt=""
                            className="h-full w-full object-contain"
                        />
                    ) : (
                        <ImageOff className="h-8 w-8 text-muted-foreground" />
                    )}
                </div>

                <div className="min-w-0 space-y-3">
                    {item.type === 'page' && (
                        <div className="rounded-lg border border-card-border/60 p-3">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-xs font-semibold text-muted-foreground uppercase">
                                    Scene wording
                                </p>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setEditing((v) => !v)}
                                >
                                    <Pencil className="h-3.5 w-3.5" />
                                    {editing ? 'Cancel' : 'Edit'}
                                </Button>
                            </div>
                            {editing ? (
                                <div className="space-y-2 pt-2">
                                    <Input
                                        value={action}
                                        onChange={(e) =>
                                            setAction(e.target.value)
                                        }
                                        placeholder="What visually happens"
                                    />
                                    <Input
                                        value={detail}
                                        onChange={(e) =>
                                            setDetail(e.target.value)
                                        }
                                        placeholder="Small memorable detail (optional)"
                                    />
                                    <Button size="sm" onClick={saveScene}>
                                        Save wording
                                    </Button>
                                </div>
                            ) : (
                                <p className="pt-1 text-sm">
                                    {item.sceneAction}
                                    {item.sceneDetail && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            &middot; {item.sceneDetail}
                                        </span>
                                    )}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="overflow-hidden rounded-lg border border-card-border/60">
                        <p className="border-b border-card-border/60 bg-muted/30 px-3 py-2 text-xs font-semibold text-muted-foreground uppercase">
                            Attempt history (newest first)
                        </p>
                        {item.attempts.map((attempt) => (
                            <AttemptRow key={attempt.id} attempt={attempt} />
                        ))}
                        {item.attempts.length === 0 && (
                            <p className="p-4 text-center text-xs text-muted-foreground">
                                No journaled attempts.
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function AdminModeration({ items, engines }: Props) {
    return (
        <>
            <Head title="Review queue - Admin" />
            <div className="space-y-4 p-4 sm:p-6">
                <div>
                    <h1 className="text-xl font-bold">Review queue</h1>
                    <p className="text-sm text-muted-foreground">
                        Covers and pages every engine refused on content
                        grounds. Reword the scene, retry on another engine, or
                        dismiss the flag.
                    </p>
                </div>

                {items.length === 0 ? (
                    <div className="rounded-xl border border-card-border bg-card p-12 text-center text-sm text-muted-foreground">
                        Nothing waiting for review.
                    </div>
                ) : (
                    items.map((item) => (
                        <FlaggedCard
                            key={`${item.type}-${item.bookId}-${item.pageId ?? 0}`}
                            item={item}
                            engines={engines}
                        />
                    ))
                )}
            </div>
        </>
    );
}
