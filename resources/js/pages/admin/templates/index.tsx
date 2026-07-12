import { Head, Link, router } from '@inertiajs/react';
import { Loader2, Plus, Search, Sparkles } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type TemplateRow = {
    id: number;
    title: string;
    theme: string;
    ageMin: number;
    ageMax: number;
    pageCount: number;
    booksCount: number;
    coverImageUrl: string | null;
    needsCover: boolean;
    hasImagePrompt: boolean;
};

type Props = {
    templates: {
        data: TemplateRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { search: string };
};

export default function AdminTemplates({ templates, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    // One cover generates at a time: the call is synchronous and paid.
    const [generatingId, setGeneratingId] = useState<number | null>(null);

    const generateCover = (template: TemplateRow) => {
        router.post(
            `/admin/templates/${template.id}/generate-cover`,
            {},
            {
                preserveScroll: true,
                onStart: () => setGeneratingId(template.id),
                onFinish: () => setGeneratingId(null),
            },
        );
    };

    return (
        <>
            <Head title="Templates - Admin" />
            <div className="space-y-4 p-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-serif text-2xl font-semibold">
                            Templates
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {templates.total} story templates.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get(
                                    '/admin/templates',
                                    { search },
                                    { preserveState: true },
                                );
                            }}
                            className="relative"
                        >
                            <Search className="absolute start-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="title or theme"
                                className="w-64 ps-8"
                            />
                        </form>
                        <Button asChild>
                            <Link href="/admin/templates/create">
                                <Plus className="h-4 w-4" /> New template
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl border border-card-border bg-card">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-card-border text-xs text-muted-foreground uppercase">
                                <th className="p-3 text-start">Cover</th>
                                <th className="p-3 text-start">Title</th>
                                <th className="p-3 text-start">Theme</th>
                                <th className="p-3 text-start">Ages</th>
                                <th className="p-3 text-start">Pages</th>
                                <th className="p-3 text-start">Books</th>
                                <th className="p-3 text-start">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {templates.data.map((template) => (
                                <tr
                                    key={template.id}
                                    className="cursor-pointer border-b border-card-border/60 transition-colors last:border-0 hover:bg-muted/40"
                                    onClick={() =>
                                        router.visit(
                                            `/admin/templates/${template.id}/edit`,
                                        )
                                    }
                                >
                                    <td className="p-2">
                                        <div className="h-12 w-9 overflow-hidden rounded-sm bg-muted">
                                            {template.coverImageUrl && (
                                                <img
                                                    src={template.coverImageUrl}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            )}
                                        </div>
                                    </td>
                                    <td className="p-3 font-medium">
                                        {template.title}
                                    </td>
                                    <td className="p-3 text-muted-foreground">
                                        {template.theme}
                                    </td>
                                    <td className="p-3">
                                        {template.ageMin}-{template.ageMax}
                                    </td>
                                    <td className="p-3">
                                        {template.pageCount}
                                    </td>
                                    <td className="p-3">
                                        {template.booksCount}
                                    </td>
                                    <td className="p-2">
                                        {template.needsCover &&
                                            template.hasImagePrompt && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={
                                                        generatingId !== null
                                                    }
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        generateCover(
                                                            template,
                                                        );
                                                    }}
                                                >
                                                    {generatingId ===
                                                    template.id ? (
                                                        <Loader2 className="h-4 w-4 animate-spin" />
                                                    ) : (
                                                        <Sparkles className="h-4 w-4" />
                                                    )}
                                                    Generate cover
                                                </Button>
                                            )}
                                    </td>
                                </tr>
                            ))}
                            {templates.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="p-8 text-center text-muted-foreground"
                                    >
                                        No templates match.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap gap-1">
                    {templates.links.map((link, index) =>
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
