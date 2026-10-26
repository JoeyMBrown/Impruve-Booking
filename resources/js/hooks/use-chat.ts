import { useCallback, useRef, useState } from 'react';
import { jsonHeaders } from '@/lib/csrf';
import { formatRange } from '@/lib/datetime';
import { message as messageRoute, start } from '@/routes/chat';
import { confirm, dismiss } from '@/routes/chat/proposals';
import type {
    BookingConflict,
    CardState,
    ChatEntry,
    ProposalCardData,
} from '@/types';

/** Fired after a chat confirm so an open calendar can refetch (FR-CAL-9). */
export const BOOKING_CHANGED_EVENT = 'booking:changed';

let nextId = 0;
const newId = () => `entry-${++nextId}`;

type ServerEvent = { event: string; data: unknown };

/** Split an SSE buffer into complete events and the unfinished remainder. */
function parseEvents(buffer: string): { events: ServerEvent[]; rest: string } {
    const blocks = buffer.split('\n\n');
    const rest = blocks.pop() ?? '';
    const events = blocks.flatMap((block) => {
        let event = 'message';
        let data = '';

        for (const line of block.split('\n')) {
            if (line.startsWith('event: ')) {
                event = line.slice(7);
            } else if (line.startsWith('data: ')) {
                data += line.slice(6);
            }
        }

        try {
            return [{ event, data: JSON.parse(data) as unknown }];
        } catch {
            return [];
        }
    });

    return { events, rest };
}

function describeConflicts(
    conflicts: BookingConflict[],
    timezone: string,
): string {
    return conflicts
        .map(
            (conflict) =>
                `${conflict.equipment_name} is now booked by ${conflict.owner.name}, ${formatRange(conflict.starts_at, conflict.ends_at, timezone)}`,
        )
        .join('; ');
}

/**
 * The chat widget's state: one conversation per page load (it survives
 * in-app navigation), streamed replies, and proposal cards with their
 * confirm / dismiss flows.
 */
export function useChat(timezone: string) {
    const [entries, setEntries] = useState<ChatEntry[]>([]);
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const [streaming, setStreaming] = useState(false);
    const conversationId = useRef<string | null>(null);
    const controller = useRef<AbortController | null>(null);

    const append = (entry: ChatEntry) =>
        setEntries((current) => [...current, entry]);

    const update = (id: string, change: (entry: ChatEntry) => ChatEntry) =>
        setEntries((current) =>
            current.map((entry) => (entry.id === id ? change(entry) : entry)),
        );

    const startConversation = useCallback(async (): Promise<string | null> => {
        if (conversationId.current) {
            return conversationId.current;
        }

        const response = await fetch(start.url(), {
            method: 'POST',
            headers: jsonHeaders(),
        });

        if (!response.ok) {
            return null;
        }

        const data = (await response.json()) as {
            conversation_id: string;
            suggestions: string[];
        };
        conversationId.current = data.conversation_id;
        setSuggestions(data.suggestions);

        return data.conversation_id;
    }, []);

    const unavailable = (fallbackUrl?: string) =>
        append({
            id: newId(),
            kind: 'error',
            text: 'The assistant is unavailable right now.',
            fallbackUrl,
        });

    const send = useCallback(
        async (text: string, options: { system?: boolean } = {}) => {
            const message = text.trim();

            if (!message || controller.current) {
                return;
            }

            append(
                options.system
                    ? {
                          id: newId(),
                          kind: 'notice',
                          text: 'Asking the assistant for alternatives…',
                      }
                    : { id: newId(), kind: 'user', text: message },
            );

            const id = await startConversation();

            if (!id) {
                unavailable();

                return;
            }

            const abort = new AbortController();
            controller.current = abort;
            setStreaming(true);

            // The assistant's text bubble being written; a card closes it so
            // text after the card starts a new bubble.
            let bubble: string | null = null;

            try {
                const response = await fetch(messageRoute.url(id), {
                    method: 'POST',
                    headers: { ...jsonHeaders(), Accept: 'text/event-stream' },
                    body: JSON.stringify({ message }),
                    signal: abort.signal,
                });

                if (response.status === 429) {
                    append({
                        id: newId(),
                        kind: 'notice',
                        text: 'That’s a lot of messages. Please wait a minute and try again.',
                    });

                    return;
                }

                if (!response.ok || !response.body) {
                    unavailable();

                    return;
                }

                const reader = response.body
                    .pipeThrough(new TextDecoderStream())
                    .getReader();
                let buffer = '';

                for (;;) {
                    const { value, done } = await reader.read();

                    if (done) {
                        break;
                    }

                    const parsed = parseEvents(buffer + value);
                    buffer = parsed.rest;

                    for (const { event, data } of parsed.events) {
                        if (event === 'text') {
                            const delta = (data as { delta: string }).delta;

                            if (bubble === null) {
                                const created = newId();
                                bubble = created;
                                append({
                                    id: created,
                                    kind: 'assistant',
                                    text: delta,
                                });
                            } else {
                                update(bubble, (entry) =>
                                    entry.kind === 'assistant'
                                        ? { ...entry, text: entry.text + delta }
                                        : entry,
                                );
                            }
                        } else if (event === 'proposal') {
                            bubble = null;
                            append({
                                id: newId(),
                                kind: 'card',
                                card: data as ProposalCardData,
                                state: { name: 'pending' },
                            });
                        } else if (event === 'error') {
                            unavailable(
                                (data as { fallback_url?: string })
                                    .fallback_url,
                            );
                        }
                    }
                }
            } catch (error) {
                if (abort.signal.aborted) {
                    append({ id: newId(), kind: 'notice', text: 'Stopped.' });
                } else {
                    console.error(error);
                    unavailable();
                }
            } finally {
                controller.current = null;
                setStreaming(false);
            }
        },
        [startConversation],
    );

    const stop = () => controller.current?.abort();

    const setCardState = (entryId: string, state: CardState) =>
        update(entryId, (entry) =>
            entry.kind === 'card' ? { ...entry, state } : entry,
        );

    const confirmCard = async (entryId: string, card: ProposalCardData) => {
        setCardState(entryId, { name: 'confirming' });

        const response = await fetch(confirm.url(card.id), {
            method: 'POST',
            headers: jsonHeaders(),
        });
        const data = (await response.json().catch(() => ({}))) as {
            calendar_url?: string;
            conflicts?: BookingConflict[];
            errors?: { message: string }[];
            message?: string;
        };

        if (response.ok) {
            setCardState(entryId, {
                name: 'booked',
                calendarUrl: data.calendar_url ?? '/calendar',
            });
            window.dispatchEvent(new CustomEvent(BOOKING_CHANGED_EVENT));
        } else if (response.status === 409 && data.conflicts) {
            setCardState(entryId, { name: 'taken', conflicts: data.conflicts });
            // Let the bot follow up with alternatives (FR-CHAT-13).
            void send(
                `[system] Proposal ${card.id} failed: ${describeConflicts(data.conflicts, timezone)}. Suggest alternatives.`,
                { system: true },
            );
        } else if (response.status === 410) {
            setCardState(entryId, { name: 'expired' });
        } else {
            setCardState(entryId, {
                name: 'failed',
                message:
                    data.errors?.[0]?.message ??
                    data.message ??
                    'This can’t be booked any more.',
            });
        }
    };

    const dismissCard = async (entryId: string, card: ProposalCardData) => {
        setCardState(entryId, { name: 'dismissed' });
        await fetch(dismiss.url(card.id), {
            method: 'POST',
            headers: jsonHeaders(),
        });
    };

    /** Start over in a fresh conversation. */
    const reset = () => {
        stop();
        conversationId.current = null;
        setEntries([]);
        void startConversation();
    };

    return {
        entries,
        suggestions,
        streaming,
        startConversation,
        send,
        stop,
        confirmCard,
        dismissCard,
        setCardState,
        reset,
    };
}
