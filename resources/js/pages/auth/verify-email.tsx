// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { AuthLayoutProps } from '@/layouts/auth-layout';
import { logout } from '@/routes';
import { index as booksIndex } from '@/routes/books';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <>
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    A new verification link has been sent to the email address
                    you provided during registration.
                </div>
            )}

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button
                            disabled={processing}
                            variant="gold"
                            size="lg"
                            className="rounded-full"
                        >
                            {processing && <Spinner />}
                            Resend verification email
                        </Button>

                        <div className="space-y-2">
                            <TextLink
                                href={booksIndex()}
                                className="mx-auto block text-sm"
                            >
                                Skip for now
                            </TextLink>
                            <p className="text-xs text-muted-foreground">
                                You can verify later, but some features may not
                                be available until your email is verified.
                            </p>
                        </div>

                        <TextLink
                            href={logout()}
                            className="mx-auto block text-sm"
                        >
                            Log out
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    eyebrow: 'One last step',
    title: 'Verify your email',
    subtitle:
        'We just emailed you a verification link. Click it to unlock everything, or skip for now and verify later.',
} satisfies AuthLayoutProps;
