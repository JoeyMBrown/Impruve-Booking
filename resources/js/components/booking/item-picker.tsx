import { CircleCheck, CircleX, OctagonX } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import { formatRange } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { EquipmentCategory, ItemAvailability, Option } from '@/types';

type Props = {
    items: ItemAvailability[];
    categories: Option<EquipmentCategory>[];
    selected: number[];
    timezone: string;
    disabled?: boolean;
    onToggle: (equipmentId: number, checked: boolean) => void;
};

/**
 * Items for the chosen time, grouped by category. Busy and unbookable items
 * are disabled; one that becomes unavailable after being ticked stays ticked
 * and turns red so the user sees why it can't be saved.
 */
export function ItemPicker({
    items,
    categories,
    selected,
    timezone,
    disabled = false,
    onToggle,
}: Props) {
    return (
        <div className="flex flex-col gap-4">
            {categories.map((category) => {
                const inCategory = items.filter(
                    (item) => item.category === category.value,
                );

                if (inCategory.length === 0) {
                    return null;
                }

                return (
                    <div key={category.value} className="flex flex-col gap-1">
                        <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {category.label}
                        </h3>
                        <ul className="flex flex-col divide-y rounded-lg border">
                            {inCategory.map((item) => (
                                <ItemRow
                                    key={item.equipment_id}
                                    item={item}
                                    checked={selected.includes(
                                        item.equipment_id,
                                    )}
                                    timezone={timezone}
                                    disabled={disabled}
                                    onToggle={onToggle}
                                />
                            ))}
                        </ul>
                    </div>
                );
            })}
        </div>
    );
}

function ItemRow({
    item,
    checked,
    timezone,
    disabled,
    onToggle,
}: {
    item: ItemAvailability;
    checked: boolean;
    timezone: string;
    disabled: boolean;
    onToggle: (equipmentId: number, checked: boolean) => void;
}) {
    const free = item.state === 'free';
    const blocked = checked && !free;
    const id = `item-${item.equipment_id}`;

    return (
        <li
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm',
                blocked && 'bg-red-50 dark:bg-red-950/30',
            )}
        >
            <Checkbox
                id={id}
                checked={checked}
                // A ticked item can always be unticked, even once unavailable.
                disabled={disabled || (!free && !checked)}
                onCheckedChange={(value) =>
                    onToggle(item.equipment_id, value === true)
                }
            />
            <label
                htmlFor={id}
                className={cn(
                    'font-medium',
                    !free && !checked && 'text-muted-foreground',
                )}
            >
                {item.name}
            </label>
            <span
                className={cn(
                    'ml-auto flex items-center gap-1',
                    blocked
                        ? 'text-red-700 dark:text-red-400'
                        : 'text-muted-foreground',
                )}
            >
                <StateText item={item} timezone={timezone} />
            </span>
            {blocked && (
                <p className="w-full text-xs text-red-700 dark:text-red-400">
                    Can’t be saved: this item isn’t available for the chosen
                    time. Untick it or change the time.
                </p>
            )}
        </li>
    );
}

function StateText({
    item,
    timezone,
}: {
    item: ItemAvailability;
    timezone: string;
}) {
    if (item.state === 'free') {
        return (
            <>
                <CircleCheck className="size-4" aria-hidden="true" />
                free
            </>
        );
    }

    if (item.state === 'unbookable') {
        return (
            <>
                <OctagonX className="size-4" aria-hidden="true" />
                {item.status === 'out_of_service'
                    ? 'Out of service'
                    : item.status}
                {item.reason && ` — ${item.reason}`}
            </>
        );
    }

    const [first, ...others] = item.conflicts;

    return (
        <>
            <CircleX className="size-4" aria-hidden="true" />
            {first &&
                `${first.owner.name} · “${first.title}” ${formatRange(first.starts_at, first.ends_at, timezone)}`}
            {others.length > 0 && ` (+${others.length} more)`}
        </>
    );
}
