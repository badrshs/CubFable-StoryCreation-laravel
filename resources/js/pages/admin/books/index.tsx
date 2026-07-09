import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
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

type BookRow = {
    id: number;
    childName: string;
    userEmail: string;
    artStyle: string;
    language: string;
    status: string;
    pagesTotal: number;
    pagesDone: number;
    aiCost: number;
    paid: boolean;
    createdAt: string;
};

type Props = {
    books: {
        data: BookRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { search: string; status: string };
    statuses: string[];
};

const STATUS_TONE: Record<string, string> = {
    complete: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    generating: 'bg-primary/15 text-primary',
    pending: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    failed: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    draft: 'bg-muted text-muted-foreground',
};

export default function AdminBooks({ books, filters, statuses }: Props) {
    const [search, setSearch] = useState(filters.search);

    const applyFilters = (overrides: Partial<Props['filters']> = {}) => {
        router.get(
            '/admin/books',
            { search, status: filters.status, ...overrides },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Books - Admin" />
            <div className="space-y-4 p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-serif text-2xl font-semibold">
                            Books
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {books.total} books across all accounts.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                applyFilters();
                            }}
                            className="relative"
                        >
                            <Search className="absolute start-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="id, child, or user email"
                                className="w-64 ps-8"
                            />
                        </form>
                        <Select
                            value={filters.status || 'all'}
                            onValueChange={(value) =>
                                applyFilters({
                                    status: value === 'all' ? '' : value,
                                })
                            }
                        >
                            <SelectTrigger className="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {status}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-card-border bg-card">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-card-border text-start text-xs text-muted-foreground uppercase">
                                <th className="p-3 text-start">#</th>
                                <th className="p-3 text-start">Child</th>
                                <th className="p-3 text-start">User</th>
                                <th className="p-3 text-start">Style</th>
                                <th className="p-3 text-start">Status</th>
                                <th className="p-3 text-start">Pages</th>
                                <th className="p-3 text-start">AI cost</th>
                                <th className="p-3 text-start">Paid</th>
                                <th className="p-3 text-start">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            {books.data.map((book) => (
                                <tr
                                    key={book.id}
                                    className="cursor-pointer border-b border-card-border/60 transition-colors last:border-0 hover:bg-muted/40"
                                    onClick={() =>
                                        router.visit(`/admin/books/${book.id}`)
                                    }
                                >
                                    <td className="p-3 font-mono text-xs">
                                        {book.id}
                                    </td>
                                    <td className="p-3 font-medium">
                                        {book.childName}
                                    </td>
                                    <td className="p-3 text-muted-foreground">
                                        {book.userEmail}
                                    </td>
                                    <td className="p-3">{book.artStyle}</td>
                                    <td className="p-3">
                                        <Badge
                                            variant="outline"
                                            className={`border-0 ${STATUS_TONE[book.status] ?? ''}`}
                                        >
                                            {book.status}
                                        </Badge>
                                    </td>
                                    <td className="p-3">
                                        {book.pagesDone}/{book.pagesTotal}
                                    </td>
                                    <td className="p-3">
                                        ${book.aiCost.toFixed(2)}
                                    </td>
                                    <td className="p-3">
                                        {book.paid ? 'yes' : '-'}
                                    </td>
                                    <td className="p-3 text-xs text-muted-foreground">
                                        {book.createdAt}
                                    </td>
                                </tr>
                            ))}
                            {books.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={9}
                                        className="p-8 text-center text-muted-foreground"
                                    >
                                        No books match.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap gap-1">
                    {books.links.map((link, index) =>
                        link.url ? (
                            <Link key={index} href={link.url} preserveScroll>
                                <Button
                                    size="sm"
                                    variant={link.active ? 'default' : 'ghost'}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            </Link>
                        ) : null,
                    )}
                </div>
            </div>
        </>
    );
}
