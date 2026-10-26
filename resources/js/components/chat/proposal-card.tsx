import { Link } from '@inertiajs/react';
import { CircleCheck, TriangleAlert, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    create as createBooking,
    edit as editBooking,
} from '@/routes/bookings';
import type { CardState, ProposalCardData } from '@/types';

const HEADINGS = {
    create: 'Proposed booking',
    update: 'Proposed change',
    cancel: 'Cancel booking',
} as const;

type Props = {
    card: ProposalCardData;
    state: CardState;
    expired: boolean;
    onConfirm: () => void;
    onDismiss: () => void;
};

/**
 * A proposal the user must confirm (FR-CHAT-11/12). Nothing is saved until
 * Confirm; dates are absolute so mistakes are visible first.
 */
export function ProposalCard({
    card,
    state,
    expired,
    onConfirm,
    onDismiss,
}: Props) {
    const pending = state.name === 'pending' && !expired;
    const editHref =
        card.action === 'create'
            ? createBooking({ query: { proposal: card.id } })
            : card.action === 'update' && card.booking_id
              ? editBooking(card.booking_id, { query: { proposal: card.id } })
              : null;

    return (
        <div
            className="flex flex-col gap-2 rounded-lg border bg-background p-3 text-sm shadow-sm"
            data-test="proposal-card"
        >
            <div className="flex items-start justify-between gap-2">
                <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {HEADINGS[card.action]}
                </span>
                {pending && (
                    <button
                        type="button"
                        onClick={onDismiss}
                        className="text-muted-foreground hover:text-foreground"
                        aria-label="Dismiss proposal"
                    >
                        <X className="size-4" />
                    </button>
                )}
            </div>

            <p className="font-medium">“{card.title}”</p>
            <p>{card.items.map((item) => item.name).join(', ')}</p>
            <p>
                {card.display.when}{' '}
                <span className="text-muted-foreground">
                    ({card.display.duration})
                </span>
            </p>

            {card.before && (
                <div className="rounded-md bg-muted/60 p-2 text-xs text-muted-foreground">
                    <p className="font-medium">Was:</p>
                    <p>
                        “{card.before.title}” ·{' '}
                        {card.before.items.map((item) => item.name).join(', ')}
                    </p>
                    <p>{card.before.display.when}</p>
                </div>
            )}

            {pending && card.check.ok && card.action !== 'cancel' && (
                <p className="flex items-center gap-1 text-emerald-700 dark:text-emerald-400">
                    <CircleCheck className="size-4" aria-hidden="true" />
                    All items available
                </p>
            )}

            <CardFooter
                state={state}
                expired={expired}
                pending={pending}
                editHref={editHref}
                confirmLabel={
                    card.action === 'cancel' ? 'Confirm cancel' : 'Confirm'
                }
                onConfirm={onConfirm}
            />
        </div>
    );
}

function CardFooter({
    state,
    expired,
    pending,
    editHref,
    confirmLabel,
    onConfirm,
}: {
    state: CardState;
    expired: boolean;
    pending: boolean;
    editHref: ReturnType<typeof createBooking> | null;
    confirmLabel: string;
    onConfirm: () => void;
}) {
    if (pending) {
        return (
            <div className="flex flex-wrap gap-2">
                <Button size="sm" onClick={onConfirm}>
                    {confirmLabel}
                </Button>
                {editHref && (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={editHref}>Edit in form</Link>
                    </Button>
                )}
            </div>
        );
    }

    switch (state.name) {
        case 'confirming':
            return (
                <p className="flex items-center gap-2 text-muted-foreground">
                    <Spinner /> Confirming…
                </p>
            );
        case 'booked':
            return (
                <p className="flex items-center gap-1 text-emerald-700 dark:text-emerald-400">
                    <CircleCheck className="size-4" aria-hidden="true" />
                    Done ·{' '}
                    <Link href={state.calendarUrl} className="underline">
                        View on calendar
                    </Link>
                </p>
            );
        case 'taken':
            return (
                <p className="flex items-start gap-1 text-red-700 dark:text-red-400">
                    <TriangleAlert
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    Taken:{' '}
                    {state.conflicts
                        .map(
                            (conflict) =>
                                `${conflict.equipment_name} is now booked by ${conflict.owner.name}`,
                        )
                        .join('; ')}
                </p>
            );
        case 'failed':
            return (
                <p className="text-red-700 dark:text-red-400">
                    {state.message}
                </p>
            );
        case 'dismissed':
            return <p className="text-muted-foreground">Dismissed</p>;
        default:
            return expired ? (
                <p className="text-muted-foreground">Expired: ask again</p>
            ) : null;
    }
}
