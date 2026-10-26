import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { DateTimeField } from '@/components/booking/date-time-field';
import { ItemPicker } from '@/components/booking/item-picker';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    addMinutesLocal,
    formatDuration,
    minutesBetweenLocal,
} from '@/lib/local-time';
import { calendar } from '@/routes';
import { availability as availabilityRoute } from '@/routes/api';
import { store } from '@/routes/bookings';
import type {
    BookingFormValues,
    EquipmentCategory,
    ItemAvailability,
    Option,
} from '@/types';

type Props = {
    values: BookingFormValues;
    availability: ItemAvailability[];
    categories: Option<EquipmentCategory>[];
    limits: { slot_minutes: number; max_duration_hours: number };
};

export default function BookingForm({
    values,
    availability: initialAvailability,
    categories,
    limits,
}: Props) {
    const { timezone } = usePage().props;
    const form = useForm<BookingFormValues>(values);
    const { starts_at: startsAt, ends_at: endsAt } = form.data;

    const [availability, setAvailability] = useState(initialAvailability);
    const [checking, setChecking] = useState(false);
    const isFirstRange = useRef(true);

    // Refresh availability whenever the time range changes (debounced).
    useEffect(() => {
        if (isFirstRange.current) {
            isFirstRange.current = false;

            return;
        }

        if (endsAt <= startsAt) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            setChecking(true);

            fetch(
                availabilityRoute.url({
                    query: { starts_at: startsAt, ends_at: endsAt },
                }),
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    return response.json() as Promise<{
                        items: ItemAvailability[];
                    }>;
                })
                .then(({ items }) => setAvailability(items))
                .catch((error: unknown) => {
                    if (!controller.signal.aborted) {
                        console.error(error);
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setChecking(false);
                    }
                });
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [startsAt, endsAt]);

    const setStart = (next: string) => {
        form.setData((data) => ({
            ...data,
            starts_at: next,
            ends_at:
                data.ends_at <= next ? addMinutesLocal(next, 60) : data.ends_at,
        }));
    };

    // An end at or before the start resets to start + 1 h.
    const setEnd = (next: string) => {
        form.setData(
            'ends_at',
            next <= form.data.starts_at
                ? addMinutesLocal(form.data.starts_at, 60)
                : next,
        );
    };

    const toggleItem = (equipmentId: number, checked: boolean) => {
        form.setData(
            'equipment_ids',
            checked
                ? [...form.data.equipment_ids, equipmentId]
                : form.data.equipment_ids.filter((id) => id !== equipmentId),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store.url());
    };

    const minutes = minutesBetweenLocal(startsAt, endsAt);
    const tooLong = minutes > limits.max_duration_hours * 60;
    const itemCount = form.data.equipment_ids.length;
    const errors = form.errors as Partial<Record<string, string>>;

    return (
        <>
            <Head title="New booking" />

            <form
                onSubmit={submit}
                className="flex max-w-3xl flex-col gap-8 p-4"
            >
                <h1 className="text-xl font-semibold tracking-tight">
                    New booking
                </h1>

                {errors.booking && (
                    <Alert variant="destructive">
                        <AlertDescription>{errors.booking}</AlertDescription>
                    </Alert>
                )}

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-medium">① When</h2>
                    <div className="flex flex-wrap gap-6">
                        <DateTimeField
                            id="starts_at"
                            label="Start"
                            value={startsAt}
                            slotMinutes={limits.slot_minutes}
                            onChange={setStart}
                        />
                        <DateTimeField
                            id="ends_at"
                            label="End"
                            value={endsAt}
                            slotMinutes={limits.slot_minutes}
                            onChange={setEnd}
                        />
                    </div>
                    <p
                        className={
                            tooLong
                                ? 'text-sm text-red-600 dark:text-red-400'
                                : 'text-sm text-muted-foreground'
                        }
                    >
                        Duration: {formatDuration(minutes)} (max{' '}
                        {limits.max_duration_hours / 24} days)
                    </p>
                    <InputError message={errors.starts_at} />
                    <InputError message={errors.ends_at} />
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="flex items-center gap-2 text-base font-medium">
                        ② What
                        <span className="text-sm font-normal text-muted-foreground">
                            (availability for the chosen time)
                        </span>
                        {checking && (
                            <Loader2
                                className="size-4 animate-spin text-muted-foreground"
                                aria-label="Checking availability"
                            />
                        )}
                    </h2>
                    <ItemPicker
                        items={availability}
                        categories={categories}
                        selected={form.data.equipment_ids}
                        timezone={timezone}
                        onToggle={toggleItem}
                    />
                    <InputError message={errors.equipment_ids} />
                </section>

                <section className="flex flex-col gap-4">
                    <h2 className="text-base font-medium">③ Details</h2>
                    <div className="grid gap-2">
                        <Label htmlFor="title">Title *</Label>
                        <Input
                            id="title"
                            value={form.data.title}
                            maxLength={120}
                            required
                            placeholder="Client interview shoot"
                            onChange={(event) =>
                                form.setData('title', event.target.value)
                            }
                        />
                        <InputError message={errors.title} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="notes">Notes</Label>
                        <Textarea
                            id="notes"
                            rows={3}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                        <InputError message={errors.notes} />
                    </div>
                </section>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Book {itemCount} {itemCount === 1 ? 'item' : 'items'}
                    </Button>
                    <Button variant="secondary" asChild>
                        <Link href={calendar()}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}
