import type { BookingConflict } from './booking';

type CardValues = {
    title: string;
    notes: string | null;
    items: { id: number; name: string }[];
    starts_at: string;
    ends_at: string;
    display: { when: string; duration: string };
};

/** A proposal card from the `proposal` SSE event (D§5.3). */
export type ProposalCardData = CardValues & {
    id: string;
    action: 'create' | 'update' | 'cancel';
    status: string;
    booking_id: number | null;
    before: CardValues | null;
    check: { ok: boolean };
    expires_at: string;
};

export type CardState =
    | { name: 'pending' }
    | { name: 'confirming' }
    | { name: 'booked'; calendarUrl: string }
    | { name: 'taken'; conflicts: BookingConflict[] }
    | { name: 'expired' }
    | { name: 'failed'; message: string }
    | { name: 'dismissed' };

export type ChatEntry =
    | { id: string; kind: 'user'; text: string }
    | { id: string; kind: 'assistant'; text: string }
    | { id: string; kind: 'notice'; text: string }
    | { id: string; kind: 'error'; text: string; fallbackUrl?: string }
    | { id: string; kind: 'card'; card: ProposalCardData; state: CardState };
