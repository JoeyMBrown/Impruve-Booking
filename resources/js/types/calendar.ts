import type {
    BookingPhase,
    EquipmentCategory,
    EquipmentStatus,
} from './equipment';

/**
 * A booking as shown on the calendar, drawer and My bookings. Times are ISO
 * 8601 in the company timezone, e.g. "2026-10-28T09:00:00-05:00", so their
 * first 16 characters are the company wall-clock time.
 */
export type CalendarBooking = {
    id: number;
    title: string;
    notes: string | null;
    starts_at: string;
    ends_at: string;
    owner: { id: number; name: string };
    is_mine: boolean;
    is_active: boolean;
    cancelled_at: string | null;
    items: {
        id: number;
        name: string;
        category: EquipmentCategory;
        status: EquipmentStatus;
        status_reason: string | null;
    }[];
    phase: BookingPhase;
    at_risk: boolean;
    source: 'manual' | 'chat';
    created_at: string | null;
};

export type CalendarWeekData = {
    week: {
        iso: string;
        start: string;
        end: string;
        tz: string;
        prev: string;
        next: string;
        is_current: boolean;
    };
    now: string;
    filters: {
        category: EquipmentCategory | null;
        equipment: number | null;
        mine: boolean;
    };
    equipment: { id: number; name: string; category: EquipmentCategory }[];
    bookings: CalendarBooking[];
    out_now: {
        equipment_id: number;
        name: string;
        booking_id: number;
        owner: string;
        until: string;
    }[];
    unavailable: {
        equipment_id: number;
        name: string;
        status: EquipmentStatus;
        reason: string | null;
    }[];
    my_at_risk: CalendarBooking[];
};
