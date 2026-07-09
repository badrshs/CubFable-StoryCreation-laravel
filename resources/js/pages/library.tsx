import { Link, router, useForm, usePage } from '@inertiajs/react';
import { motion, useReducedMotion } from 'framer-motion';
import {
    BookOpen,
    Loader2,
    Pencil,
    Plus,
    Sparkles,
    Trash2,
    Upload,
    Users,
} from 'lucide-react';
import type { ChangeEvent, FormEvent } from 'react';
import { useState } from 'react';
import { PhotoCropDialog } from '@/components/cubfable/photo-crop-dialog';
import Starfield from '@/components/cubfable/starfield';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/i18n';
import { easeOutSoft, fadeUp, staggerContainer } from '@/lib/motion';
import characterRoutes from '@/routes/characters';
import templateRoutes from '@/routes/templates';
import type { Character, CharacterAgeGroup } from '@/types';

type LibraryProps = {
    characters: Character[];
};

// Pull the first character of the (trimmed) name for the avatar fallback.
function initialOf(name: string): string {
    return name.trim().charAt(0).toUpperCase() || '?';
}

type EditorState = { mode: 'create' } | { mode: 'edit'; character: Character };

type CharacterFormData = {
    name: string;
    role: string;
    ageGroup: CharacterAgeGroup;
    description: string;
    photoUrl: string | null;
};

// The add / edit form, presented in a dialog. Owns its own draft state so
// opening it for a different character always starts from that character.
function CharacterEditor({
    state,
    onClose,
}: {
    state: EditorState | null;
    onClose: () => void;
}) {
    const t = useT();
    const {
        data,
        setData,
        errors,
        clearErrors,
        processing,
        transform,
        post,
        patch,
    } = useForm<CharacterFormData>({
        name: '',
        role: '',
        ageGroup: 'adult',
        description: '',
        photoUrl: null,
    });

    // Whether this editing session replaced or removed the photo. The stored
    // photo arrives as a /storage/... URL, which the backend must never
    // receive back as if it were a fresh data-URL upload.
    const [photoChanged, setPhotoChanged] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const nameId = 'character-name';

    // Reset the draft whenever the editor is (re)opened, using the guarded
    // render-time adjustment pattern (no cascading effect renders). The stale
    // draft stays in place while the dialog animates closed.
    const [prevState, setPrevState] = useState<EditorState | null>(null);

    if (state !== prevState) {
        setPrevState(state);

        if (state) {
            setData(
                state.mode === 'edit'
                    ? {
                          name: state.character.name,
                          role: state.character.role ?? '',
                          ageGroup: state.character.ageGroup ?? 'adult',
                          description: state.character.description ?? '',
                          photoUrl: state.character.photoUrl ?? null,
                      }
                    : {
                          name: '',
                          role: '',
                          ageGroup: 'adult',
                          description: '',
                          photoUrl: null,
                      },
            );
            clearErrors();
            setError(null);
            setPhotoChanged(false);
        }
    }

    const isEdit = state?.mode === 'edit';
    const saving = processing;

    const { photoUploadQuality } = usePage().props as unknown as {
        photoUploadQuality: string;
    };

    // Every chosen photo goes through the crop dialog first, so the person
    // fills the reference frame.
    const [cropFile, setCropFile] = useState<File | null>(null);

    const handlePhoto = (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = '';

        if (file) {
            setCropFile(file);
        }
    };

    const applyCrop = (dataUrl: string) => {
        setData('photoUrl', dataUrl);
        setPhotoChanged(true);
        setCropFile(null);
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();

        if (!data.name.trim() || saving) {
            return;
        }

        setError(null);

        // PATCH semantics: photoUrl is only sent when this session actually
        // changed it, so the stored /storage/... URL is never echoed back to
        // an endpoint that expects a data URL (or null to clear the photo).
        const includePhoto = !isEdit || photoChanged;

        transform((current) => ({
            name: current.name.trim(),
            role: current.role.trim() || null,
            ageGroup: current.ageGroup,
            description: current.description.trim() || null,
            ...(includePhoto ? { photoUrl: current.photoUrl ?? null } : {}),
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };

        if (state?.mode === 'edit') {
            patch(characterRoutes.update.url(state.character.id), options);
        } else {
            post(characterRoutes.store.url(), options);
        }
    };

    return (
        <Dialog
            open={!!state}
            onOpenChange={(open) => (!open ? onClose() : undefined)}
        >
            <PhotoCropDialog
                file={cropFile}
                quality={photoUploadQuality}
                onCropped={applyCrop}
                onCancel={() => setCropFile(null)}
            />
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle className="font-serif text-2xl">
                        {isEdit
                            ? t('library.editTitle')
                            : t('library.addTitle')}
                    </DialogTitle>
                    <DialogDescription>
                        {isEdit
                            ? t('library.editSubtitle')
                            : t('library.addSubtitle')}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-5">
                    {/* Photo */}
                    <div className="flex items-center gap-4">
                        <div className="relative h-24 w-24 shrink-0 overflow-hidden rounded-2xl border-2 border-dashed border-primary/40 bg-primary/5 transition-colors focus-within:ring-2 focus-within:ring-ring hover:bg-primary/10">
                            <input
                                type="file"
                                accept="image/*"
                                onChange={handlePhoto}
                                aria-label={t('library.photoLabel')}
                                className="absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0"
                            />
                            {data.photoUrl ? (
                                <img
                                    src={data.photoUrl}
                                    alt={t('library.photoPreviewAlt', {
                                        name:
                                            data.name ||
                                            t('library.thisCharacter'),
                                    })}
                                    className="h-full w-full object-cover"
                                />
                            ) : (
                                <div className="flex h-full w-full flex-col items-center justify-center text-primary/70">
                                    <Upload
                                        className="mb-1 h-6 w-6"
                                        aria-hidden
                                    />
                                    <span className="text-[11px] font-medium">
                                        {t('library.photo')}
                                    </span>
                                </div>
                            )}
                        </div>
                        <div className="min-w-0">
                            <p className="font-display text-sm font-semibold text-foreground">
                                {t('library.photoHeading')}
                            </p>
                            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                {t('library.photoHint')}
                            </p>
                            {data.photoUrl && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setData('photoUrl', null);
                                        setPhotoChanged(true);
                                    }}
                                    className="mt-2 inline-flex items-center gap-1 rounded-sm text-xs font-medium text-rose hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <Trash2 className="h-3 w-3" aria-hidden />
                                    {t('library.removePhoto')}
                                </button>
                            )}
                        </div>
                    </div>

                    {errors.photoUrl && (
                        <p
                            role="alert"
                            className="text-sm font-medium text-destructive"
                        >
                            {errors.photoUrl}
                        </p>
                    )}

                    {/* Name (required) */}
                    <div className="space-y-1.5">
                        <Label htmlFor={nameId}>
                            {t('library.nameLabel')}{' '}
                            <span className="text-rose">*</span>
                        </Label>
                        <Input
                            id={nameId}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder={t('library.namePlaceholder')}
                            required
                            autoFocus
                            className="h-11 rounded-xl"
                        />
                        {errors.name && (
                            <p
                                role="alert"
                                className="text-sm font-medium text-destructive"
                            >
                                {errors.name}
                            </p>
                        )}
                    </div>

                    {/* Role */}
                    <div className="space-y-1.5">
                        <Label htmlFor="character-role">
                            {t('library.roleLabel')}
                        </Label>
                        <Input
                            id="character-role"
                            value={data.role}
                            onChange={(e) => setData('role', e.target.value)}
                            placeholder={t('library.rolePlaceholder')}
                            className="h-11 rounded-xl"
                        />
                        {errors.role && (
                            <p
                                role="alert"
                                className="text-sm font-medium text-destructive"
                            >
                                {errors.role}
                            </p>
                        )}
                    </div>

                    {/* Age group: an unmarked companion (mom, dad, grandpa)
                        risks being drawn kid-sized in the illustrations. */}
                    <div className="space-y-1.5">
                        <Label htmlFor="character-age-group">
                            {t('library.ageGroupLabel')}
                        </Label>
                        <Select
                            value={data.ageGroup}
                            onValueChange={(v) =>
                                setData('ageGroup', v as CharacterAgeGroup)
                            }
                        >
                            <SelectTrigger
                                id="character-age-group"
                                className="h-11 rounded-xl"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="adult">
                                    {t('library.ageGroupAdult')}
                                </SelectItem>
                                <SelectItem value="child">
                                    {t('library.ageGroupChild')}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.ageGroup && (
                            <p
                                role="alert"
                                className="text-sm font-medium text-destructive"
                            >
                                {errors.ageGroup}
                            </p>
                        )}
                    </div>

                    {/* Description */}
                    <div className="space-y-1.5">
                        <Label htmlFor="character-description">
                            {t('library.descriptionLabel')}
                        </Label>
                        <Textarea
                            id="character-description"
                            value={data.description}
                            onChange={(e) =>
                                setData('description', e.target.value)
                            }
                            placeholder={t('library.descriptionPlaceholder')}
                            rows={3}
                            className="rounded-xl"
                        />
                        {errors.description && (
                            <p
                                role="alert"
                                className="text-sm font-medium text-destructive"
                            >
                                {errors.description}
                            </p>
                        )}
                    </div>

                    {error && (
                        <p
                            role="alert"
                            className="text-sm font-medium text-destructive"
                        >
                            {error}
                        </p>
                    )}

                    <DialogFooter className="gap-2 sm:gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onClose}
                            disabled={saving}
                        >
                            {t('library.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            variant="gold"
                            disabled={saving || !data.name.trim()}
                        >
                            {saving ? (
                                <>
                                    <Loader2
                                        className="h-4 w-4 animate-spin"
                                        aria-hidden
                                    />
                                    {t('library.saving')}
                                </>
                            ) : isEdit ? (
                                t('library.saveChanges')
                            ) : (
                                t('library.addCharacter')
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// A single saved character shown as a keepsake card.
function CharacterCard({
    character,
    onEdit,
    onDelete,
}: {
    character: Character;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const t = useT();

    return (
        <motion.article
            variants={fadeUp}
            className="group flex h-full flex-col overflow-hidden rounded-3xl border border-card-border bg-card shadow-soft transition-all duration-500 ease-out hover:-translate-y-1.5 hover:shadow-lift"
        >
            {/* Portrait stage */}
            <div className="relative aspect-[4/3] overflow-hidden bg-gradient-to-b from-secondary to-muted">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-8 top-4 h-24 rounded-full bg-primary/15 blur-3xl transition-opacity duration-500 group-hover:bg-gold/20"
                />
                {character.photoUrl ? (
                    <img
                        src={character.photoUrl}
                        alt={t('library.portraitAlt', {
                            name: character.name,
                        })}
                        className="relative z-10 h-full w-full object-cover"
                    />
                ) : (
                    <div className="relative z-10 flex h-full w-full items-center justify-center">
                        <span
                            aria-hidden
                            className="flex h-20 w-20 items-center justify-center rounded-full bg-primary/15 font-display text-3xl font-bold text-primary ring-1 ring-primary/25"
                        >
                            {initialOf(character.name)}
                        </span>
                    </div>
                )}
                {character.isMain && (
                    <span className="absolute end-3 top-3 z-20 inline-flex items-center gap-1 rounded-full bg-gold/90 px-2.5 py-1 font-display text-[0.68rem] font-semibold text-gold-foreground shadow-soft">
                        <Sparkles className="h-3 w-3" aria-hidden />
                        {t('library.heroBadge')}
                    </span>
                )}
            </div>

            {/* Details */}
            <div className="flex flex-1 flex-col p-5">
                <h3 className="font-serif text-xl leading-tight font-bold text-foreground">
                    {character.name}
                </h3>
                {character.role ? (
                    <p className="mt-1 font-display text-xs font-semibold tracking-[0.12em] text-primary uppercase">
                        {character.role}
                    </p>
                ) : null}
                {character.description ? (
                    <p className="mt-3 line-clamp-3 flex-1 text-sm leading-relaxed text-muted-foreground">
                        {character.description}
                    </p>
                ) : (
                    <p className="mt-3 flex-1 text-sm leading-relaxed text-muted-foreground/70 italic">
                        {t('library.noDescription')}
                    </p>
                )}

                {/* Actions */}
                <div className="mt-5 flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="flex-1 rounded-full"
                        onClick={onEdit}
                    >
                        <Pencil className="h-4 w-4" aria-hidden />
                        {t('library.edit')}
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="rounded-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={onDelete}
                        aria-label={t('library.deleteAria', {
                            name: character.name,
                        })}
                    >
                        <Trash2 className="h-4 w-4" aria-hidden />
                    </Button>
                </div>
            </div>
        </motion.article>
    );
}

export default function Library({ characters }: LibraryProps) {
    const t = useT();
    const reduce = useReducedMotion();

    const [editor, setEditor] = useState<EditorState | null>(null);
    const [pendingDelete, setPendingDelete] = useState<Character | null>(null);
    const [deleting, setDeleting] = useState(false);
    // Keep the name available for the confirm copy while the dialog animates out.
    const [lastDeletedName, setLastDeletedName] = useState('');

    const handleDelete = () => {
        if (!pendingDelete || deleting) {
            return;
        }

        setLastDeletedName(pendingDelete.name);

        // Left open on failure so the user can retry; the button returns to idle.
        router.delete(characterRoutes.destroy.url(pendingDelete.id), {
            preserveScroll: true,
            onStart: () => setDeleting(true),
            onSuccess: () => setPendingDelete(null),
            onFinish: () => setDeleting(false),
        });
    };

    const hasCharacters = characters.length > 0;

    return (
        <div className="relative min-h-[100dvh] bg-background">
            {/* Enchanted header */}
            <section className="relative overflow-hidden">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-primary/12 via-background to-background"
                />
                <Starfield
                    count={28}
                    aurora
                    className="opacity-70 dark:opacity-100"
                />

                <div className="relative z-10 container mx-auto px-4 pt-20 pb-10 md:pt-24">
                    <motion.div
                        initial={reduce ? false : { opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.6, ease: easeOutSoft }}
                        className="flex flex-col gap-6 md:flex-row md:items-end md:justify-between"
                    >
                        <div className="max-w-2xl">
                            <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-gold/30 bg-gold/10 px-3.5 py-1.5 font-display text-sm font-semibold text-gold">
                                <Users className="h-4 w-4" aria-hidden />
                                <span>{t('library.eyebrow')}</span>
                            </div>

                            <h1 className="font-serif text-4xl leading-[1.08] font-bold text-foreground md:text-6xl">
                                {t('library.heading')}{' '}
                                <span className="text-lamplight italic">
                                    {t('library.headingAccent')}
                                </span>
                            </h1>

                            <p className="mt-5 max-w-xl text-lg leading-relaxed text-muted-foreground">
                                {t('library.subheading')}
                            </p>

                            {hasCharacters && (
                                <p className="mt-6 inline-flex items-center gap-2 font-display text-sm font-medium text-muted-foreground">
                                    <BookOpen
                                        className="h-4 w-4 text-primary"
                                        aria-hidden
                                    />
                                    {t('library.count', {
                                        count: characters.length,
                                    })}
                                </p>
                            )}
                        </div>

                        <div className="shrink-0">
                            <Button
                                variant="gold"
                                size="lg"
                                className="rounded-full shadow-soft"
                                onClick={() => setEditor({ mode: 'create' })}
                            >
                                <Plus className="h-4 w-4" aria-hidden />
                                {t('library.addCharacter')}
                            </Button>
                        </div>
                    </motion.div>
                </div>
            </section>

            {/* Roster */}
            <section className="container mx-auto px-4 pb-24">
                {!hasCharacters ? (
                    <motion.div
                        initial={reduce ? false : { opacity: 0, scale: 0.96 }}
                        animate={{ opacity: 1, scale: 1 }}
                        transition={{ duration: 0.6, ease: easeOutSoft }}
                        className="mx-auto max-w-xl rounded-3xl border border-card-border bg-card px-8 py-16 text-center shadow-soft"
                    >
                        <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-secondary text-primary">
                            <Users className="h-7 w-7" aria-hidden />
                        </div>
                        <h2 className="font-serif text-3xl font-bold text-foreground">
                            {t('library.emptyTitle')}
                        </h2>
                        <p className="mx-auto mt-3 max-w-md text-muted-foreground">
                            {t('library.emptyDescription')}
                        </p>
                        <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                            <Button
                                variant="gold"
                                size="lg"
                                className="rounded-full"
                                onClick={() => setEditor({ mode: 'create' })}
                            >
                                <Plus className="h-4 w-4" aria-hidden />
                                {t('library.addFirstCharacter')}
                            </Button>
                            <Link href={templateRoutes.index()}>
                                <Button
                                    variant="outline"
                                    size="lg"
                                    className="rounded-full"
                                >
                                    <Sparkles className="h-4 w-4" aria-hidden />
                                    {t('library.startAStory')}
                                </Button>
                            </Link>
                        </div>
                    </motion.div>
                ) : (
                    <motion.div
                        variants={staggerContainer(0.07)}
                        initial={reduce ? false : 'hidden'}
                        animate="show"
                        className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        {characters.map((character) => (
                            <CharacterCard
                                key={character.id}
                                character={character}
                                onEdit={() =>
                                    setEditor({ mode: 'edit', character })
                                }
                                onDelete={() => setPendingDelete(character)}
                            />
                        ))}
                    </motion.div>
                )}
            </section>

            {/* Add / edit dialog */}
            <CharacterEditor state={editor} onClose={() => setEditor(null)} />

            {/* Delete confirmation */}
            <AlertDialog
                open={!!pendingDelete}
                onOpenChange={(open) =>
                    !open ? setPendingDelete(null) : undefined
                }
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle className="font-serif text-2xl">
                            {t('library.deleteTitle')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t('library.deleteDescription', {
                                name: pendingDelete?.name ?? lastDeletedName,
                            })}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={deleting}>
                            {t('library.cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={(e) => {
                                e.preventDefault();
                                handleDelete();
                            }}
                            disabled={deleting}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            {deleting ? (
                                <>
                                    <Loader2
                                        className="h-4 w-4 animate-spin"
                                        aria-hidden
                                    />
                                    {t('library.deleting')}
                                </>
                            ) : (
                                t('library.confirmDelete')
                            )}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
