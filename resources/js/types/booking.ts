import type { EquipmentCategory, EquipmentStatus } from './equipment';

export type AvailabilityState = 'free' | 'busy' | 'unbookable';

/** An active booking that holds an item. Times are ISO 8601. */
export type BookingConflict = {
    equipment_id: number;
    equipment_name: string;
    booking_id: number;
    title: string;
    owner: { id: number; name: string };
    starts_at: string;
    ends_at: string;
};

/** One item's availability for a time range (GET /api/availability). */
export type ItemAvailability = {
    equipment_id: number;
    name: string;
    category: EquipmentCategory;
    state: AvailabilityState;
    status: EquipmentStatus;
    reason: string | null;
    conflicts: BookingConflict[];
};

/**
 * Booking form values. Times are company-local wall-clock strings
 * ("2026-10-29T09:00"); the server reads them in the company timezone.
 */
export type BookingFormValues = {
    starts_at: string;
    ends_at: string;
    equipment_ids: number[];
    title: string;
    notes: string;
};
