export type EquipmentCategory = 'camera' | 'lighting' | 'space';

export type EquipmentStatus = 'available' | 'out_of_service' | 'retired';

export type Equipment = {
    id: number;
    name: string;
    category: EquipmentCategory;
    description: string | null;
    asset_tag: string | null;
    status: EquipmentStatus;
    status_reason: string | null;
    status_changed_at: string | null;
};

export type Option<T extends string = string> = {
    value: T;
    label: string;
};

/** A booking as listed on an equipment page. Times are ISO 8601 (UTC). */
export type EquipmentBooking = {
    id: number;
    title: string;
    starts_at: string;
    ends_at: string;
    owner: { id: number; name: string };
};

export type BookingPhase = 'upcoming' | 'in_progress' | 'ended';

/** A booking that a status change would flag at risk. */
export type ImpactedBooking = EquipmentBooking & { phase: BookingPhase };
