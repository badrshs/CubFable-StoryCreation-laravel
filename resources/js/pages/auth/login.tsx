import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import { AlertCircle, LogIn } from 'lucide-react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n';
import type { AuthLayoutProps } from '@/layouts/auth-layout';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

const linkClass =
    'rounded-sm font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

export default function Login({ status, canResetPassword }: Props) {
    const { t, tc } = useI18n();
    const { registrationOpen } = usePage().props;

    setLayoutProps<AuthLayoutProps>({
        eyebrow: t('auth.login'),
        title: t('auth.loginTitle'),
        subtitle: t('auth.loginSubtitle'),
        storyCaption: t('auth.storyCaption'),
        storyHero: t('auth.storyHero'),
        panelTagline: t('auth.panelTaglineLogin'),
    });

    return (
        <>
            <Head title={t('auth.login')} />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="email">{t('auth.email')}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                required
                                autoFocus
                                placeholder={t('auth.emailPlaceholder')}
                                aria-invalid={errors.email ? true : undefined}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <div className="flex items-center justify-between">
                                <Label htmlFor="password">
                                    {t('auth.password')}
                                </Label>
                                {canResetPassword && (
                                    <Link
                                        href={request()}
                                        className={`text-sm ${linkClass}`}
                                    >
                                        {tc(
                                            'auth.forgotPassword',
                                            'Forgot your password?',
                                        )}
                                    </Link>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="current-password"
                                required
                                placeholder={t('auth.passwordPlaceholder')}
                                aria-invalid={
                                    errors.email || errors.password
                                        ? true
                                        : undefined
                                }
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center gap-2.5">
                            <Checkbox id="remember" name="remember" />
                            <Label
                                htmlFor="remember"
                                className="text-sm font-normal text-muted-foreground"
                            >
                                {tc('auth.rememberMe', 'Remember me')}
                            </Label>
                        </div>

                        <div aria-live="polite" className="min-h-[1.25rem]">
                            {errors.email && (
                                <p
                                    className="flex items-start gap-2 text-sm text-destructive"
                                    role="alert"
                                >
                                    <AlertCircle
                                        className="mt-0.5 h-4 w-4 shrink-0"
                                        aria-hidden
                                    />
                                    <span>{t('auth.errorInvalid')}</span>
                                </p>
                            )}
                        </div>

                        <Button
                            type="submit"
                            variant="gold"
                            size="lg"
                            className="rounded-full"
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing ? (
                                t('auth.working')
                            ) : (
                                <>
                                    <LogIn className="h-4 w-4" aria-hidden />
                                    {t('auth.submitLogin')}
                                </>
                            )}
                        </Button>
                    </>
                )}
            </Form>

            {registrationOpen && (
                <div className="mt-7 text-center text-sm text-muted-foreground">
                    {t('auth.noAccount')}{' '}
                    <Link href={register()} className={linkClass}>
                        {t('auth.register')}
                    </Link>
                </div>
            )}
        </>
    );
}
