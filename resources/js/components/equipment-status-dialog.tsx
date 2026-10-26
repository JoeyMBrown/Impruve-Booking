import { Form, usePage } from '@inertiajs/react';
import { RefreshCw, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatRange } from '@/lib/datetime';
import { impact } from '@/routes/api/equipment';
import { status as updateStatus } from '@/routes/equipment';
import type {
    Equipment,
    EquipmentStatus,
    ImpactedBooking,
    Option,
} from '@/types';

type Props = {
    equipment: Equipment;
    statuses: Option<EquipmentStatus>[];
};

type Preview =
    | { state: 'idle' }
    | { state: 'loading' }
    | { state: 'loaded'; bookings: ImpactedBooking[] }
    | { state: 'failed' };

export function EquipmentStatusDialog({ equipment, statuses }: Props) {
    const { timezone } = usePage().props;
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState<EquipmentStatus>(equipment.status);
    const [preview, setPreview] = useState<Preview>({ state: 'idle' });

    // The impact preview is shown before moving an item away from available.
    const needsPreview =
        equipment.status === 'available' && selected !== 'available';

    useEffect(() => {
        if (!open || !needsPreview) {
            setPreview({ state: 'idle' });

            return;
        }

        const controller = new AbortController();
        setPreview({ state: 'loading' });

        fetch(impact.url(equipment.id, { query: { status: selected } }), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json() as Promise<{
                    bookings: ImpactedBooking[];
                }>;
            })
            .then(({ bookings }) => setPreview({ state: 'loaded', bookings }))
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    console.error(error);
                    setPreview({ state: 'failed' });
                }
            });

        return () => controller.abort();
    }, [open, needsPreview, selected, equipment.id]);

    const handleOpenChange = (next: boolean) => {
        setOpen(next);

        if (next) {
            setSelected(equipment.status);
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <RefreshCw />
                    Change status
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Change status: {equipment.name}</DialogTitle>
                    <DialogDescription>
                        Nothing is cancelled. Owners of affected bookings see a
                        warning.
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...updateStatus.form(equipment.id)}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <fieldset className="flex flex-col gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Status
                                </legend>
                                {statuses.map((option) => (
                                    <label
                                        key={option.value}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="radio"
                                            name="status"
                                            value={option.value}
                                            checked={selected === option.value}
                                            onChange={() =>
                                                setSelected(option.value)
                                            }
                                        />
                                        {option.label}
                                        {option.value === equipment.status && (
                                            <span className="text-muted-foreground">
                                                (current)
                                            </span>
                                        )}
                                    </label>
                                ))}
                                <InputError message={errors.status} />
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="reason">Reason</Label>
                                <Textarea
                                    id="reason"
                                    name="reason"
                                    rows={2}
                                    placeholder="Bulb replacement, back ~Nov 3"
                                />
                                <InputError message={errors.reason} />
                            </div>

                            {needsPreview && (
                                <ImpactPreview
                                    preview={preview}
                                    timezone={timezone}
                                />
                            )}

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Save status
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ImpactPreview({
    preview,
    timezone,
}: {
    preview: Preview;
    timezone: string;
}) {
    if (preview.state === 'loading' || preview.state === 'idle') {
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner /> Checking affected bookings…
            </p>
        );
    }

    if (preview.state === 'failed') {
        return (
            <p className="text-sm text-red-600 dark:text-red-400">
                Couldn't load affected bookings. You can still save.
            </p>
        );
    }

    if (preview.bookings.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No upcoming or in-progress bookings use this item.
            </p>
        );
    }

    const count = preview.bookings.length;

    return (
        <div className="flex flex-col gap-2 rounded-md border border-amber-500/40 bg-amber-50 p-3 text-sm dark:bg-amber-950/30">
            <p className="flex items-center gap-2 font-medium">
                <TriangleAlert className="size-4" aria-hidden="true" />
                {count} {count === 1 ? 'booking uses' : 'bookings use'} this
                item and will be flagged:
            </p>
            <ul className="flex flex-col gap-1 pl-6">
                {preview.bookings.map((booking) => (
                    <li key={booking.id}>
                        {formatRange(
                            booking.starts_at,
                            booking.ends_at,
                            timezone,
                        )}{' '}
                        · {booking.title} · {booking.owner.name}
                        {booking.phase === 'in_progress' && (
                            <span className="text-muted-foreground">
                                {' '}
                                (in progress)
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            <p className="text-muted-foreground">
                Nothing will be cancelled. Owners will see a warning.
            </p>
        </div>
    );
}
