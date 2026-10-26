import { Link, router } from '@inertiajs/react';
import { Pencil, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { formatDateTime, formatRange } from '@/lib/datetime';
import { formatDuration } from '@/lib/local-time';
import { cancel, edit } from '@/routes/bookings';
import type { CalendarBooking } from '@/types';

const PHASE_LABELS = {
    upcoming: 'Upcoming',
    in_progress: 'In progress',
    ended: 'Ended',
} as const;

const STATUS_LABELS = {
    available: 'available',
    out_of_service: 'out of service',
    retired: 'retired',
} as const;

type Props = {
    booking: CalendarBooking;
    timezone: string;
    /** Called after a successful cancel or end (e.g. to close the drawer). */
    onCancelled?: () => void;
};

/**
 * Everything about one booking, shared by the calendar drawer and the
 * /bookings/{id} page. Only the owner sees Edit and Cancel.
 */
export function BookingDetails({ booking, timezone, onCancelled }: Props) {
    const durationMinutes = Math.round(
        (Date.parse(booking.ends_at) - Date.parse(booking.starts_at)) / 60_000,
    );
    const unavailable = booking.items.filter(
        (item) => item.status !== 'available',
    );
    const canChange =
        booking.is_mine && booking.is_active && booking.phase !== 'ended';
    const inProgress = booking.phase === 'in_progress';

    return (
        <div className="flex flex-col gap-4 text-sm">
            <p className="text-muted-foreground">
                {booking.owner.name} · booked via {booking.source}
                {booking.created_at &&
                    ` · ${formatDateTime(booking.created_at, timezone)}`}
            </p>

            <p>
                {formatRange(booking.starts_at, booking.ends_at, timezone)} (
                {formatDuration(durationMinutes)}) ·{' '}
                <span className="font-medium">
                    {booking.is_active
                        ? PHASE_LABELS[booking.phase]
                        : 'Cancelled'}
                </span>
            </p>

            <div className="flex flex-col gap-1">
                <h3 className="font-medium">Items</h3>
                <ul className="flex flex-wrap gap-x-3 gap-y-1">
                    {booking.items.map((item) => (
                        <li key={item.id} className="flex items-center gap-1">
                            {item.name}
                            {item.status !== 'available' && (
                                <TriangleAlert
                                    className="size-4 text-red-700 dark:text-red-400"
                                    aria-label={STATUS_LABELS[item.status]}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            </div>

            {booking.at_risk &&
                unavailable.map((item) => (
                    <p
                        key={item.id}
                        className="flex items-start gap-2 rounded-md border border-red-600/30 bg-red-50 p-2 text-red-800 dark:bg-red-950/30 dark:text-red-300"
                    >
                        <TriangleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            {item.name} is {STATUS_LABELS[item.status]}
                            {item.status_reason &&
                                `: “${item.status_reason}”`}.{' '}
                            {booking.is_mine &&
                                'Edit the booking to swap it, or cancel.'}
                        </span>
                    </p>
                ))}

            {booking.notes && (
                <div className="flex flex-col gap-1">
                    <h3 className="font-medium">Notes</h3>
                    <p className="whitespace-pre-line">{booking.notes}</p>
                </div>
            )}

            {canChange && (
                <div className="flex gap-2">
                    <Button variant="outline" asChild>
                        <Link href={edit(booking.id)}>
                            <Pencil />
                            {inProgress ? 'Shorten' : 'Edit'}
                        </Link>
                    </Button>
                    <CancelButton
                        booking={booking}
                        inProgress={inProgress}
                        onCancelled={onCancelled}
                    />
                </div>
            )}
        </div>
    );
}

/** Cancel (or, for an in-progress booking, End now) after confirming. */
export function CancelButton({
    booking,
    inProgress,
    onCancelled,
    size,
}: {
    booking: CalendarBooking;
    inProgress: boolean;
    onCancelled?: () => void;
    size?: 'sm' | 'default';
}) {
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        router.post(
            cancel.url(booking.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onCancelled?.(),
            },
        );
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive" size={size}>
                    {inProgress ? 'End now' : 'Cancel booking'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {inProgress
                            ? 'End this booking now?'
                            : 'Cancel this booking?'}
                    </DialogTitle>
                    <DialogDescription>
                        {inProgress
                            ? `“${booking.title}” will end at the next 30-minute step and its items will be free from then on.`
                            : `“${booking.title}” will be cancelled and its items freed. This can’t be undone.`}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Keep it</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={confirm}
                    >
                        {inProgress ? 'End now' : 'Cancel booking'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
