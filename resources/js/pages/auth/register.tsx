import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import { MoonStar, Sparkles } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n';
import type { AuthLayoutProps } from '@/layouts/auth-layout';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

const linkClass =
    'rounded-sm font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

export default function Register({ passwordRules }: Props) {
    const { t, tc } = useI18n();
    const { registrationOpen } = usePage().props;

    setLayoutProps<AuthLayoutProps>({
        eyebrow: t('auth.register'),
        title: t('auth.registerTitle'),
        subtitle: t('auth.registerSubtitle'),
        storyCaption: t('auth.storyCaption'),
        storyHero: t('auth.storyHero'),
        panelTagline: t('auth.panelTaglineRegister'),
    });

    return (
        <>
            <Head title={t('auth.register')} />

            {registrationOpen ? (
                <Form
                    {...store.form()}
                    resetOnSuccess={['password', 'password_confirmation']}
                    disableWhileProcessing
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="name">
                                    {tc('auth.name', 'Name')}
                                </Label>
                                <Input
                                    id="name"
                                    type="text"
                                    name="name"
                                    autoComplete="name"
                                    required
                                    autoFocus
                                    placeholder={tc(
                                        'auth.namePlaceholder',
                                        'Your name',
                                    )}
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="email">{t('auth.email')}</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="email"
                                    required
                                    placeholder={t('auth.emailPlaceholder')}
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                />
                                <InputError message={errors.email} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="password">
                                    {t('auth.password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    autoComplete="new-password"
                                    required
                                    placeholder={t('auth.passwordPlaceholder')}
                                    passwordrules={passwordRules}
                                    aria-describedby="password-hint"
                                    aria-invalid={
                                        errors.password ? true : undefined
                                    }
                                />
                                <p
                                    id="password-hint"
                                    className="text-xs text-muted-foreground"
                                >
                                    {t('auth.passwordHint')}
                                </p>
                                <InputError message={errors.password} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="password_confirmation">
                                    {tc(
                                        'auth.confirmPassword',
                                        'Confirm password',
                                    )}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    autoComplete="new-password"
                                    required
                                    placeholder={t('auth.passwordPlaceholder')}
                                    passwordrules={passwordRules}
                                    aria-invalid={
                                        errors.password_confirmation
                                            ? true
                                            : undefined
                                    }
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                variant="gold"
                                size="lg"
                                className="rounded-full"
                                disabled={processing}
                                data-test="register-user-button"
                            >
                                {processing ? (
                                    t('auth.working')
                                ) : (
                                    <>
                                        <Sparkles
                                            className="h-4 w-4"
                                            aria-hidden
                                        />
                                        {t('auth.submitRegister')}
                                    </>
                                )}
                            </Button>
                        </>
                    )}
                </Form>
            ) : (
                <div className="flex flex-col items-center gap-3 rounded-2xl border border-card-border bg-card/70 px-6 py-8 text-center shadow-soft">
                    <span className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/12 text-primary ring-1 ring-primary/20">
                        <MoonStar className="h-6 w-6" aria-hidden />
                    </span>
                    <p className="text-sm text-muted-foreground" role="status">
                        {t('auth.registrationClosed')}
                    </p>
                </div>
            )}

            <div className="mt-7 text-center text-sm text-muted-foreground">
                {t('auth.haveAccount')}{' '}
                <Link href={login()} className={linkClass}>
                    {t('auth.login')}
                </Link>
            </div>
        </>
    );
}
