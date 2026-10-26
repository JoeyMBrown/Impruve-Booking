import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { calendar } from '@/routes';
import { create as createBooking } from '@/routes/bookings';
import type { CalendarWeekData, EquipmentCategory } from '@/types';

type Filters = CalendarWeekData['filters'];

const CATEGORIES: { value: EquipmentCategory; label: string }[] = [
    { value: 'camera', label: 'Cameras' },
    { value: 'lighting', label: 'Lighting' },
    { value: 'space', label: 'Space' },
];

const ALL = 'all';
const ANY_ITEM = 'any';

/** Query string for a week and filters; the current week needs no ?week. */
export function calendarQuery(week: string | null, filters: Filters) {
    return {
        query: {
            week: week ?? undefined,
            category: filters.category ?? undefined,
            equipment: filters.equipment ?? undefined,
            mine: filters.mine ? 1 : undefined,
        },
    };
}

function formatWeekTitle(start: string, end: string): string {
    const format = (date: string, options: Intl.DateTimeFormatOptions) =>
        new Date(`${date}T12:00:00Z`).toLocaleDateString('en-US', {
            timeZone: 'UTC',
            ...options,
        });

    return `Week of ${format(start, { month: 'short', day: 'numeric' })} – ${format(end, { month: 'short', day: 'numeric', year: 'numeric' })}`;
}

export function WeekToolbar({
    week,
    filters,
    equipment,
}: Pick<CalendarWeekData, 'week' | 'filters' | 'equipment'>) {
    const applyFilters = (next: Partial<Filters>) => {
        router.get(
            calendar.url(
                calendarQuery(week.is_current ? null : week.iso, {
                    ...filters,
                    ...next,
                }),
            ),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <h1 className="mr-2 text-lg font-semibold tracking-tight">
                    {formatWeekTitle(week.start, week.end)}
                </h1>
                <Button variant="outline" size="icon" asChild>
                    <Link
                        href={calendar(calendarQuery(week.prev, filters))}
                        preserveScroll
                        aria-label="Previous week"
                    >
                        <ChevronLeft />
                    </Link>
                </Button>
                <Button variant="outline" asChild>
                    <Link
                        href={calendar(calendarQuery(null, filters))}
                        preserveScroll
                    >
                        Today
                    </Link>
                </Button>
                <Button variant="outline" size="icon" asChild>
                    <Link
                        href={calendar(calendarQuery(week.next, filters))}
                        preserveScroll
                        aria-label="Next week"
                    >
                        <ChevronRight />
                    </Link>
                </Button>
                <Button className="ml-auto" asChild>
                    <Link href={createBooking()}>
                        <Plus />
                        New booking
                    </Link>
                </Button>
            </div>

            <div className="flex flex-wrap items-center gap-4">
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={filters.category ?? ALL}
                    onValueChange={(value) =>
                        value &&
                        applyFilters({
                            category:
                                value === ALL
                                    ? null
                                    : (value as EquipmentCategory),
                        })
                    }
                    aria-label="Filter by category"
                >
                    <ToggleGroupItem value={ALL}>All</ToggleGroupItem>
                    {CATEGORIES.map((category) => (
                        <ToggleGroupItem
                            key={category.value}
                            value={category.value}
                        >
                            {category.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>

                <Select
                    value={
                        filters.equipment ? String(filters.equipment) : ANY_ITEM
                    }
                    onValueChange={(value) =>
                        applyFilters({
                            equipment:
                                value === ANY_ITEM ? null : Number(value),
                        })
                    }
                >
                    <SelectTrigger
                        className="h-8 w-48"
                        aria-label="Filter by item"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ANY_ITEM}>Any item</SelectItem>
                        {equipment.map((item) => (
                            <SelectItem key={item.id} value={String(item.id)}>
                                {item.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <div className="flex items-center gap-2">
                    <Checkbox
                        id="only-mine"
                        checked={filters.mine}
                        onCheckedChange={(checked) =>
                            applyFilters({ mine: checked === true })
                        }
                    />
                    <Label htmlFor="only-mine">Only mine</Label>
                </div>
            </div>
        </div>
    );
}
