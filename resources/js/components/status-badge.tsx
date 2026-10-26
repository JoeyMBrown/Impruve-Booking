import { Archive, CircleCheck, OctagonX } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { EquipmentStatus } from '@/types';

// Status is never shown by colour alone: every badge has an icon and text.
const statusDisplay = {
    available: {
        label: 'Available',
        icon: CircleCheck,
        className:
            'border-emerald-600/30 text-emerald-700 dark:text-emerald-400',
    },
    out_of_service: {
        label: 'Out of service',
        icon: OctagonX,
        className: 'border-red-600/30 text-red-700 dark:text-red-400',
    },
    retired: {
        label: 'Retired',
        icon: Archive,
        className: 'border-neutral-400/50 text-muted-foreground',
    },
} as const;

export function StatusBadge({
    status,
    className,
}: {
    status: EquipmentStatus;
    className?: string;
}) {
    const {
        label,
        icon: Icon,
        className: statusClassName,
    } = statusDisplay[status];

    return (
        <Badge variant="outline" className={cn(statusClassName, className)}>
            <Icon aria-hidden="true" />
            {label}
        </Badge>
    );
}
