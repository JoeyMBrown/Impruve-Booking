import { Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { edit, index } from '@/routes/equipment';
import type { Equipment } from '@/types';

type Props = {
    equipment: Equipment;
};

export default function EquipmentShow({ equipment }: Props) {
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
                    <Button variant="outline" asChild>
                        <Link href={edit(equipment.id)}>
                            <Pencil />
                            Edit
                        </Link>
                    </Button>
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
                </dl>
            </div>
        </>
    );
}
