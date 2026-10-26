import { Link, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { destroy as switchUser } from '@/routes/who';

export function ActingUser() {
    const { auth } = usePage().props;

    if (!auth.user) {
        return null;
    }

    return (
        <div className="flex items-center gap-1 text-sm" data-test="acting-user">
            <span className="text-muted-foreground">
                <span className="hidden sm:inline">Acting as </span>
                <strong className="font-semibold text-foreground">
                    {auth.user.name}
                </strong>
            </span>
            <span aria-hidden="true" className="text-muted-foreground">
                ·
            </span>
            <Button variant="ghost" size="sm" asChild>
                <Link
                    href={switchUser()}
                    as="button"
                    onClick={() => router.flushAll()}
                    data-test="switch-user-button"
                >
                    Switch
                </Link>
            </Button>
        </div>
    );
}
