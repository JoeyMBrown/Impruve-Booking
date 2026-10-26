import { Head, Link, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { EquipmentStatusDialog } from '@/components/equipment-status-dialog';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { dayKey, formatDateTime, formatDay, formatRange } from '@/lib/datetime';
import { edit, index } from '@/routes/equipment';
import type {
    Equipment,
    EquipmentBooking,
    EquipmentStatus,
    Option,
} from '@/types';

type Props = {
    equipment: Equipment;
    bookings: EquipmentBooking[];
    window: { starts_at: string; ends_at: string };
    statuses: Option<EquipmentStatus>[];
};

type DayGroup = { key: string; label: string; bookings: EquipmentBooking[] };

/**
 * Groups bookings under the day they start. A booking that started before
 * the window (e.g. last Sunday) is listed under the window's first day.
 */
function groupByDay(
    bookings: EquipmentBooking[],
    windowStart: string,
    timeZone: string,
): DayGroup[] {
    const groups: DayGroup[] = [];

    for (const booking of bookings) {
        // Compare as timestamps: the ISO strings can carry different offsets.
        const anchor =
            Date.parse(booking.starts_at) < Date.parse(windowStart)
                ? windowStart
                : booking.starts_at;
        const key = dayKey(anchor, timeZone);
        let group = groups.find((candidate) => candidate.key === key);

        if (!group) {
            group = { key, label: formatDay(anchor, timeZone), bookings: [] };
            groups.push(group);
        }

        group.bookings.push(booking);
    }

    return groups;
}

export default function EquipmentShow({
    equipment,
    bookings,
    window,
    statuses,
}: Props) {
    const { timezone } = usePage().props;
    const days = groupByDay(bookings, window.starts_at, timezone);

    return (
        <>
            <Head title={equipment.name} />

            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Link
                    href={index()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    ← All equipment
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {equipment.name}
                        </h1>
                        <StatusBadge status={equipment.status} />
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={edit(equipment.id)}>
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <EquipmentStatusDialog
                            equipment={equipment}
                            statuses={statuses}
                        />
                    </div>
                </div>

                <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">Category</dt>
                    <dd className="capitalize">{equipment.category}</dd>
                    <dt className="text-muted-foreground">Asset tag</dt>
                    <dd>{equipment.asset_tag ?? '—'}</dd>
                    <dt className="text-muted-foreground">Description</dt>
                    <dd className="whitespace-pre-line">
                        {equipment.description ?? '—'}
                    </dd>
                    {equipment.status_reason && (
                        <>
                            <dt className="text-muted-foreground">
                                Status reason
                            </dt>
                            <dd>{equipment.status_reason}</dd>
                        </>
                    )}
                    {equipment.status_changed_at && (
                        <>
                            <dt className="text-muted-foreground">
                                Status changed
                            </dt>
                            <dd>
                                {formatDateTime(
                                    equipment.status_changed_at,
                                    timezone,
                                )}
                            </dd>
                        </>
                    )}
                </dl>

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-medium">
                        Bookings this week and next
                    </h2>

                    {days.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No bookings in the next two weeks.
                        </p>
                    )}

                    {days.map((day) => (
                        <div key={day.key} className="flex flex-col gap-1">
                            <h3 className="text-sm font-medium text-muted-foreground">
                                {day.label}
                            </h3>
                            <ul className="flex flex-col divide-y rounded-lg border">
                                {day.bookings.map((booking) => (
                                    <li
                                        key={booking.id}
                                        className="flex flex-wrap justify-between gap-2 px-4 py-2 text-sm"
                                    >
                                        <span className="font-medium">
                                            {booking.title}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {formatRange(
                                                booking.starts_at,
                                                booking.ends_at,
                                                timezone,
                                            )}{' '}
                                            · {booking.owner.name}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}
