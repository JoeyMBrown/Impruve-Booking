import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { create, index, show } from '@/routes/equipment';
import type {
    Equipment,
    EquipmentCategory,
    EquipmentStatus,
    Option,
} from '@/types';

type StatusFilter = 'active' | EquipmentStatus;

type Props = {
    equipment: Equipment[];
    filters: {
        category: EquipmentCategory | null;
        status: StatusFilter;
    };
    categories: Option<EquipmentCategory>[];
    statuses: Option<EquipmentStatus>[];
};

const ALL_CATEGORIES = 'all';

export default function EquipmentIndex({
    equipment,
    filters,
    categories,
    statuses,
}: Props) {
    const categoryLabel = (value: EquipmentCategory) =>
        categories.find((category) => category.value === value)?.label ?? value;

    const applyFilters = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, ...next };

        router.get(
            index.url({
                query: {
                    category: merged.category ?? undefined,
                    status:
                        merged.status === 'active' ? undefined : merged.status,
                },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Equipment" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Equipment"
                        description="Everything that can be booked."
                    />
                    <Button asChild>
                        <Link href={create()}>
                            <Plus />
                            Add equipment
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-center gap-4">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={filters.category ?? ALL_CATEGORIES}
                        onValueChange={(value) =>
                            value &&
                            applyFilters({
                                category:
                                    value === ALL_CATEGORIES
                                        ? null
                                        : (value as EquipmentCategory),
                            })
                        }
                        aria-label="Filter by category"
                    >
                        <ToggleGroupItem value={ALL_CATEGORIES}>
                            All
                        </ToggleGroupItem>
                        {categories.map((category) => (
                            <ToggleGroupItem
                                key={category.value}
                                value={category.value}
                            >
                                {category.label}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>

                    <Select
                        value={filters.status}
                        onValueChange={(value) =>
                            applyFilters({ status: value as StatusFilter })
                        }
                    >
                        <SelectTrigger
                            className="w-48"
                            aria-label="Filter by status"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">
                                Active (not retired)
                            </SelectItem>
                            {statuses.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="px-4 py-2 font-medium">Name</th>
                                <th className="px-4 py-2 font-medium">
                                    Category
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Asset tag
                                </th>
                                <th className="px-4 py-2 font-medium">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {equipment.map((item) => (
                                <tr
                                    key={item.id}
                                    className="border-b last:border-0"
                                >
                                    <td className="px-4 py-3 font-medium">
                                        <Link
                                            href={show(item.id)}
                                            className="hover:underline"
                                        >
                                            {item.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">
                                        {categoryLabel(item.category)}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {item.asset_tag ?? '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-col items-start gap-1">
                                            <StatusBadge status={item.status} />
                                            {item.status_reason && (
                                                <span className="text-xs text-muted-foreground">
                                                    “{item.status_reason}”
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {equipment.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No equipment matches these filters.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
