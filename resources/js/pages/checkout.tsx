import { Link, router } from '@inertiajs/react';
import { CheckoutEventNames, initializePaddle } from '@paddle/paddle-js';
import type { Paddle } from '@paddle/paddle-js';
import {
    Elements,
    PaymentElement,
    useElements,
    useStripe,
} from '@stripe/react-stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import { motion, useReducedMotion } from 'framer-motion';
import {
    AlertCircle,
    ArrowLeft,
    Baby,
    BookOpen,
    Check,
    FileDown,
    Info,
    Lock,
    Palette,
    Pencil,
    ShieldCheck,
    Sparkles,
    Wand2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import BookCover from '@/components/cubfable/book-cover';
import Starfield from '@/components/cubfable/starfield';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useT } from '@/i18n';
import { easeOutSoft, fadeUp, staggerContainer } from '@/lib/motion';
import bookRoutes, { index, show } from '@/routes/books';
import type { Book } from '@/types';

// Slugs like "under_the_sea" are stored raw; render them as gentle Title Case.
function humanize(value?: string | null): string {
    if (!value) {
        return '';
    }

    return value
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

// A small, on-brand detail chip for the order summary (name, theme, ages...).
function DetailChip({
    icon,
    label,
    value,
}: {
    icon: ReactNode;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-center gap-3 rounded-xl border border-card-border bg-background/50 px-3.5 py-2.5">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                {icon}
            </span>
            <div className="min-w-0">
                <p className="font-display text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
                <p className="truncate font-serif text-base leading-tight font-semibold text-foreground">
                    {value}
                </p>
            </div>
        </div>
    );
}

// One line in the "what you get" list of inclusions. Callers pass a themed
// icon per line, but every row renders the uniform gold check dot.
// eslint-disable-next-line @typescript-eslint/no-unused-vars
function Included({ icon, text }: { icon: ReactNode; text: string }) {
    return (
        <li className="flex items-start gap-2.5">
            <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gold/15 text-gold">
                <Check className="h-3 w-3" />
            </span>
            <span className="text-sm leading-relaxed text-muted-foreground">
                {text}
            </span>
        </li>
    );
}

// The formatted headline price, using font-display for the numerals per brand.
function PriceTag({
    currency,
    price,
    className = '',
}: {
    currency: string;
    price: string;
    className?: string;
}) {
    return (
        <span
            className={`inline-flex items-baseline gap-1.5 font-display ${className}`}
        >
            <span className="text-sm font-semibold text-muted-foreground">
                {currency}
            </span>
            <span className="font-bold tracking-tight text-foreground">
                {price}
            </span>
        </span>
    );
}

// The two consent checkboxes every payment provider shares: the pay action
// stays locked until both are ticked.
function ConsentChecks({
    acceptTerms,
    acceptGeneration,
    onAcceptTerms,
    onAcceptGeneration,
}: {
    acceptTerms: boolean;
    acceptGeneration: boolean;
    onAcceptTerms: (value: boolean) => void;
    onAcceptGeneration: (value: boolean) => void;
}) {
    const t = useT();

    return (
        <fieldset className="mt-6 space-y-4">
            <legend className="sr-only">{t('checkout.consentLegend')}</legend>
            <div className="flex items-start gap-3">
                <Checkbox
                    id="accept-terms"
                    checked={acceptTerms}
                    onCheckedChange={(v) => onAcceptTerms(v === true)}
                    className="mt-0.5"
                />
                <Label
                    htmlFor="accept-terms"
                    className="cursor-pointer text-sm leading-relaxed font-normal text-muted-foreground"
                >
                    {t('checkout.consentTerms')}
                </Label>
            </div>
            <div className="flex items-start gap-3">
                <Checkbox
                    id="accept-generation"
                    checked={acceptGeneration}
                    onCheckedChange={(v) => onAcceptGeneration(v === true)}
                    className="mt-0.5"
                />
                <Label
                    htmlFor="accept-generation"
                    className="cursor-pointer text-sm leading-relaxed font-normal text-muted-foreground"
                >
                    {t('checkout.consentGeneration')}
                </Label>
            </div>
        </fieldset>
    );
}

// The real payment form. Rendered inside <Elements> so useStripe/useElements have the
// PaymentIntent client secret. Collects consent, mounts the Stripe Payment Element, and
// confirms the payment. On success it routes to the reader; the webhook flips the book to
// pending and starts generation (the client return is cosmetic).
function PaymentForm({
    bookId,
    currency,
    price,
}: {
    bookId: number;
    currency: string;
    price: string;
}) {
    const t = useT();
    const stripe = useStripe();
    const elements = useElements();
    const [acceptTerms, setAcceptTerms] = useState(false);
    const [acceptGeneration, setAcceptGeneration] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const consentGiven = acceptTerms && acceptGeneration;

    async function onSubmit(e: FormEvent) {
        e.preventDefault();

        if (!stripe || !elements || !consentGiven || submitting) {
            return;
        }

        setSubmitting(true);
        setError(null);
        const { error: payErr } = await stripe.confirmPayment({
            elements,
            redirect: 'if_required',
        });

        if (payErr) {
            setError(payErr.message ?? t('checkout.payError'));
            setSubmitting(false);

            return;
        }

        router.visit(show.url(bookId));
    }

    return (
        <form onSubmit={onSubmit} className="relative">
            <div className="mt-6 mb-2">
                <p className="font-display text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    {t('checkout.paymentMethod')}
                </p>
            </div>
            <div className="rounded-2xl border border-card-border bg-background/60 p-4">
                <PaymentElement options={{ layout: 'tabs' }} />
            </div>

            <ConsentChecks
                acceptTerms={acceptTerms}
                acceptGeneration={acceptGeneration}
                onAcceptTerms={setAcceptTerms}
                onAcceptGeneration={setAcceptGeneration}
            />

            <div className="mt-6">
                <Button
                    type="submit"
                    variant="gold"
                    size="xl"
                    disabled={!stripe || submitting || !consentGiven}
                    className={`w-full gap-2 rounded-full ${consentGiven ? 'glow-gold' : ''}`}
                    data-testid="pay-button"
                >
                    <Lock className="h-4 w-4" />
                    {submitting
                        ? t('checkout.paying')
                        : t('checkout.payButton', { currency, price })}
                </Button>
                {error ? (
                    <p
                        className="mt-2.5 flex items-center justify-center gap-1.5 text-center text-xs font-medium text-destructive"
                        role="alert"
                    >
                        <AlertCircle className="h-3.5 w-3.5" />
                        {error}
                    </p>
                ) : (
                    !consentGiven && (
                        <p className="mt-2.5 flex items-center justify-center gap-1.5 text-center text-xs font-medium text-muted-foreground">
                            <Info className="h-3.5 w-3.5" />
                            {t('checkout.payNeedsConsent')}
                        </p>
                    )
                )}
            </div>
        </form>
    );
}

// Mounts Stripe Elements around the payment form. Behavior is unchanged from
// when checkout was Stripe-only; this wrapper just keeps loadStripe out of the
// Paddle path.
function StripePaymentPanel({
    bookId,
    clientSecret,
    publishableKey,
    currency,
    price,
}: {
    bookId: number;
    clientSecret: string;
    publishableKey: string;
    currency: string;
    price: string;
}) {
    const stripePromise = useMemo(
        () => loadStripe(publishableKey),
        [publishableKey],
    );

    return (
        <Elements
            stripe={stripePromise}
            options={{
                clientSecret,
                appearance: {
                    theme: 'stripe',
                    variables: { borderRadius: '12px' },
                },
            }}
        >
            <PaymentForm bookId={bookId} currency={currency} price={price} />
        </Elements>
    );
}

// Paddle's inline checkout renders its own payment UI (including the pay
// button) inside an iframe, so consent gates the frame itself: nothing is
// mounted until both boxes are ticked, and unticking closes the checkout.
// On success it routes to the reader; the webhook (or the reader's
// reconcile-on-load) flips the book to pending server-side, so the client
// return is cosmetic, exactly like the Stripe path.
function PaddlePaymentPanel({
    bookId,
    transactionId,
    clientToken,
    environment,
}: {
    bookId: number;
    transactionId: string;
    clientToken: string;
    environment: 'sandbox' | 'production';
}) {
    const t = useT();
    const [acceptTerms, setAcceptTerms] = useState(false);
    const [acceptGeneration, setAcceptGeneration] = useState(false);
    const [paddle, setPaddle] = useState<Paddle | null>(null);
    const [failed, setFailed] = useState(false);
    const openedRef = useRef(false);
    const consentGiven = acceptTerms && acceptGeneration;

    useEffect(() => {
        let cancelled = false;

        initializePaddle({
            environment,
            token: clientToken,
            eventCallback: (event) => {
                if (event.name === CheckoutEventNames.CHECKOUT_COMPLETED) {
                    router.visit(show.url(bookId));
                } else if (
                    event.name === CheckoutEventNames.CHECKOUT_ERROR ||
                    event.name === CheckoutEventNames.CHECKOUT_PAYMENT_FAILED
                ) {
                    setFailed(true);
                }
            },
        }).then((instance) => {
            if (!cancelled && instance) {
                setPaddle(instance);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [environment, clientToken, bookId]);

    useEffect(() => {
        if (!paddle) {
            return;
        }

        if (consentGiven) {
            openedRef.current = true;
            paddle.Checkout.open({
                transactionId,
                settings: {
                    displayMode: 'inline',
                    frameTarget: 'paddle-checkout-frame',
                    frameInitialHeight: 450,
                    frameStyle:
                        'width: 100%; min-width: 286px; background-color: transparent; border: none;',
                },
            });
        } else if (openedRef.current) {
            openedRef.current = false;
            paddle.Checkout.close();
        }
    }, [paddle, consentGiven, transactionId]);

    return (
        <div className="relative">
            <ConsentChecks
                acceptTerms={acceptTerms}
                acceptGeneration={acceptGeneration}
                onAcceptTerms={(value) => {
                    setAcceptTerms(value);
                    setFailed(false);
                }}
                onAcceptGeneration={(value) => {
                    setAcceptGeneration(value);
                    setFailed(false);
                }}
            />

            <div className="mt-6 mb-2">
                <p className="font-display text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    {t('checkout.paymentMethod')}
                </p>
            </div>
            <div className="rounded-2xl border border-card-border bg-background/60 p-4">
                {consentGiven ? (
                    <div className="paddle-checkout-frame" />
                ) : (
                    <p className="flex items-center justify-center gap-1.5 py-8 text-center text-xs font-medium text-muted-foreground">
                        <Info className="h-3.5 w-3.5" />
                        {t('checkout.payNeedsConsent')}
                    </p>
                )}
            </div>
            {failed && (
                <p
                    className="mt-2.5 flex items-center justify-center gap-1.5 text-center text-xs font-medium text-destructive"
                    role="alert"
                >
                    <AlertCircle className="h-3.5 w-3.5" />
                    {t('checkout.payError')}
                </p>
            )}
        </div>
    );
}

type CheckoutProps = {
    book: Book;
    amount: string;
    currency: string;
} & (
    | { provider: 'stripe'; clientSecret: string; publishableKey: string }
    | {
          provider: 'paddle';
          transactionId: string;
          clientToken: string;
          environment: 'sandbox' | 'production';
      }
);

export default function Checkout(props: CheckoutProps) {
    const { book, amount, currency } = props;
    const t = useT();
    const reduceMotion = useReducedMotion();

    return (
        <div className="bg-grain relative min-h-[100dvh] overflow-hidden bg-background">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-x-0 top-0 h-[36rem] bg-gradient-to-b from-primary/12 via-background/0 to-transparent dark:from-primary/25"
            />
            <Starfield count={reduceMotion ? 16 : 34} className="opacity-70" />

            <div className="relative container mx-auto max-w-5xl px-4 py-14 sm:py-16">
                <motion.header
                    initial={reduceMotion ? false : { opacity: 0, y: 18 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.6, ease: easeOutSoft }}
                    className="mb-10"
                >
                    <Link
                        href={index.url()}
                        className="mb-6 inline-flex items-center gap-1.5 rounded-full text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                    >
                        <ArrowLeft className="h-4 w-4 rtl:-scale-x-100" />
                        {t('checkout.backToLibrary')}
                    </Link>
                    <span className="mb-4 inline-flex items-center gap-2 rounded-full border border-gold/30 bg-gold/10 px-3 py-1.5 font-display text-sm font-semibold text-gold-foreground dark:text-gold">
                        <Sparkles className="h-4 w-4 text-gold" />
                        {t('checkout.eyebrow')}
                    </span>
                    <h1 className="font-serif text-4xl leading-tight font-bold text-foreground md:text-5xl">
                        {t('checkout.heading')}
                    </h1>
                    <p className="mt-2 max-w-2xl text-lg text-muted-foreground">
                        {t('checkout.subheading')}
                    </p>
                </motion.header>

                <motion.div
                    variants={staggerContainer(0.1)}
                    initial={reduceMotion ? false : 'hidden'}
                    animate="show"
                    className="grid gap-8 lg:grid-cols-[1.05fr_0.95fr]"
                >
                    {/* ---------- Order summary panel ---------- */}
                    <motion.section
                        variants={fadeUp}
                        aria-labelledby="order-summary-title"
                        className="relative overflow-hidden rounded-3xl border border-card-border bg-card/80 p-6 shadow-lift backdrop-blur-sm sm:p-8"
                    >
                        <div
                            aria-hidden
                            className="pointer-events-none absolute -top-16 -right-16 h-48 w-48 rounded-full bg-primary/10 blur-3xl"
                        />
                        <h2
                            id="order-summary-title"
                            className="relative mb-6 font-display text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase"
                        >
                            {t('checkout.orderSummary')}
                        </h2>

                        <div className="relative flex gap-5">
                            <div className="w-28 shrink-0 sm:w-32">
                                <BookCover
                                    coverImageUrl={book.coverImageUrl}
                                    interactive={false}
                                />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="font-display text-[11px] font-semibold tracking-wide text-gold uppercase">
                                    {t('checkout.personalizedFor')}
                                </p>
                                <p className="mt-0.5 font-serif text-2xl leading-tight font-bold text-foreground">
                                    {book.childName}
                                </p>
                                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                                    {t('checkout.heroLine', {
                                        name: book.childName,
                                    })}
                                </p>
                                <Link
                                    href={bookRoutes.edit(book.id)}
                                    className="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <Pencil className="h-3.5 w-3.5" />
                                    {t('checkout.editDetails')}
                                </Link>
                            </div>
                        </div>

                        <div className="relative mt-6 grid gap-2.5 sm:grid-cols-2">
                            <DetailChip
                                icon={<Baby className="h-4 w-4" />}
                                label={t('checkout.agesLabel')}
                                value={book.ageRange}
                            />
                            <DetailChip
                                icon={<Wand2 className="h-4 w-4" />}
                                label={t('checkout.themeLabel')}
                                value={humanize(book.theme)}
                            />
                            <DetailChip
                                icon={<Palette className="h-4 w-4" />}
                                label={t('checkout.artStyleLabel')}
                                value={humanize(book.artStyle)}
                            />
                            <DetailChip
                                icon={<Sparkles className="h-4 w-4" />}
                                label={t('checkout.oneTimeLabel')}
                                value={t('checkout.oneTimeValue')}
                            />
                        </div>

                        <Separator className="my-6 bg-card-border" />

                        <div className="relative">
                            <p className="mb-3 font-serif text-lg font-semibold text-foreground">
                                {t('checkout.whatYouGet')}
                            </p>
                            <ul className="space-y-2.5">
                                <Included
                                    icon={<BookOpen className="h-3 w-3" />}
                                    text={t('checkout.includeIllustrated')}
                                />
                                <Included
                                    icon={<Sparkles className="h-3 w-3" />}
                                    text={t('checkout.includePersonalized')}
                                />
                                <Included
                                    icon={<FileDown className="h-3 w-3" />}
                                    text={t('checkout.includePdf')}
                                />
                                <Included
                                    icon={<BookOpen className="h-3 w-3" />}
                                    text={t('checkout.includeKeepsake')}
                                />
                            </ul>
                        </div>
                    </motion.section>

                    {/* ---------- Payment panel ---------- */}
                    <motion.section
                        variants={fadeUp}
                        aria-labelledby="payment-title"
                        className="relative flex flex-col overflow-hidden rounded-3xl border border-card-border bg-card/80 p-6 shadow-lift backdrop-blur-sm sm:p-8"
                    >
                        <div
                            aria-hidden
                            className="pointer-events-none absolute -bottom-20 -left-16 h-52 w-52 rounded-full bg-gold/10 blur-3xl"
                        />
                        <h2
                            id="payment-title"
                            className="relative mb-6 font-display text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase"
                        >
                            {t('checkout.payment')}
                        </h2>

                        {/* Line item + total */}
                        <div className="relative space-y-3">
                            <div className="flex items-center justify-between gap-4">
                                <div className="min-w-0">
                                    <p className="truncate font-serif text-base font-semibold text-foreground">
                                        {t('checkout.lineItem')}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('checkout.lineItemNote')}
                                    </p>
                                </div>
                                <PriceTag
                                    currency={currency}
                                    price={amount}
                                    className="text-lg"
                                />
                            </div>
                            <Separator className="bg-card-border" />
                            <div className="flex items-center justify-between gap-4">
                                <span className="font-serif text-lg font-semibold text-foreground">
                                    {t('checkout.total')}
                                </span>
                                <PriceTag
                                    currency={currency}
                                    price={amount}
                                    className="text-3xl"
                                />
                            </div>
                        </div>

                        {/* Real payment, via the active provider */}
                        <div className="relative">
                            {props.provider === 'paddle' ? (
                                <PaddlePaymentPanel
                                    bookId={book.id}
                                    transactionId={props.transactionId}
                                    clientToken={props.clientToken}
                                    environment={props.environment}
                                />
                            ) : (
                                <StripePaymentPanel
                                    bookId={book.id}
                                    clientSecret={props.clientSecret}
                                    publishableKey={props.publishableKey}
                                    currency={currency}
                                    price={amount}
                                />
                            )}
                        </div>

                        {/* Trust cues */}
                        <div className="relative mt-6 rounded-2xl border border-card-border bg-background/50 p-4">
                            <div className="flex items-center gap-2.5 text-sm font-semibold text-foreground">
                                <ShieldCheck className="h-4 w-4 text-gold" />
                                {t('checkout.trustTitle')}
                            </div>
                            <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                                {t('checkout.trustBody')}
                            </p>
                        </div>
                    </motion.section>
                </motion.div>
            </div>
        </div>
    );
}
