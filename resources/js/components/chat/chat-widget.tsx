import { Link, usePage } from '@inertiajs/react';
import {
    MessageCircle,
    RotateCcw,
    SendHorizontal,
    Square,
    X,
} from 'lucide-react';
import type { FormEvent, KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { ProposalCard } from '@/components/chat/proposal-card';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useChat } from '@/hooks/use-chat';
import { cn } from '@/lib/utils';
import { create as createBooking } from '@/routes/bookings';

/**
 * The floating booking assistant, on every page that uses the app layout
 * (FR-CHAT-1). The layout persists across Inertia visits, so the chat
 * survives in-app navigation and resets on a full load or user switch.
 */
export function ChatWidget() {
    const { auth, timezone } = usePage().props;
    const chat = useChat(timezone);
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const [now, setNow] = useState(() => Date.now());
    const scrollRef = useRef<HTMLDivElement>(null);

    // Re-check card expiry every 30 seconds.
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 30_000);

        return () => clearInterval(timer);
    }, []);

    useEffect(() => {
        scrollRef.current?.scrollTo({
            top: scrollRef.current.scrollHeight,
            behavior: 'smooth',
        });
    }, [chat.entries]);

    const openWidget = () => {
        setOpen(true);
        void chat.startConversation();
    };

    const submit = (event?: FormEvent) => {
        event?.preventDefault();

        if (chat.streaming || !draft.trim()) {
            return;
        }

        void chat.send(draft);
        setDraft('');
    };

    // Enter sends; Shift+Enter adds a new line.
    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submit();
        }
    };

    if (!open) {
        return (
            <Button
                size="icon"
                className="fixed right-4 bottom-4 z-40 size-14 rounded-full shadow-lg"
                onClick={openWidget}
                aria-label="Open the booking assistant"
            >
                <MessageCircle className="size-6" />
            </Button>
        );
    }

    const firstName = auth.user?.name.split(' ')[0] ?? '';

    return (
        <section
            aria-label="Booking assistant"
            className="fixed inset-0 z-40 flex flex-col border bg-background shadow-xl sm:inset-auto sm:right-4 sm:bottom-4 sm:h-[34rem] sm:max-h-[calc(100vh-6rem)] sm:w-96 sm:rounded-xl"
        >
            <header className="flex items-center gap-2 border-b px-4 py-3">
                <h2 className="flex-1 text-sm font-semibold">
                    Booking assistant
                </h2>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={chat.reset}
                    aria-label="Start a new conversation"
                >
                    <RotateCcw /> New
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => setOpen(false)}
                    aria-label="Close the booking assistant"
                >
                    <X />
                </Button>
            </header>

            <div
                ref={scrollRef}
                className="flex flex-1 flex-col gap-3 overflow-y-auto p-4 text-sm"
                aria-live="polite"
            >
                {chat.entries.length === 0 && (
                    <div className="flex flex-col items-start gap-2">
                        <p>Hi {firstName}! Try:</p>
                        {chat.suggestions.map((suggestion) => (
                            <Button
                                key={suggestion}
                                variant="outline"
                                size="sm"
                                className="h-auto text-left whitespace-normal"
                                onClick={() => void chat.send(suggestion)}
                                disabled={chat.streaming}
                            >
                                {suggestion}
                            </Button>
                        ))}
                    </div>
                )}

                {chat.entries.map((entry) => {
                    switch (entry.kind) {
                        case 'user':
                            return (
                                <p
                                    key={entry.id}
                                    className="ml-8 self-end rounded-lg bg-primary px-3 py-2 whitespace-pre-wrap text-primary-foreground"
                                >
                                    {entry.text}
                                </p>
                            );
                        case 'assistant':
                            return (
                                <p
                                    key={entry.id}
                                    className="mr-8 rounded-lg bg-muted px-3 py-2 whitespace-pre-wrap"
                                >
                                    {entry.text}
                                </p>
                            );
                        case 'notice':
                            return (
                                <p
                                    key={entry.id}
                                    className="text-center text-xs text-muted-foreground"
                                >
                                    {entry.text}
                                </p>
                            );
                        case 'error':
                            return (
                                <p
                                    key={entry.id}
                                    className="rounded-lg border border-red-600/30 bg-red-50 px-3 py-2 text-red-800 dark:bg-red-950/30 dark:text-red-300"
                                    role="alert"
                                >
                                    {entry.text}{' '}
                                    <Link
                                        href={
                                            entry.fallbackUrl ??
                                            createBooking.url()
                                        }
                                        className="font-medium underline"
                                    >
                                        Book manually
                                    </Link>
                                </p>
                            );
                        case 'card':
                            return (
                                <ProposalCard
                                    key={entry.id}
                                    card={entry.card}
                                    state={entry.state}
                                    expired={
                                        now > Date.parse(entry.card.expires_at)
                                    }
                                    onConfirm={() =>
                                        void chat.confirmCard(
                                            entry.id,
                                            entry.card,
                                        )
                                    }
                                    onDismiss={() =>
                                        void chat.dismissCard(
                                            entry.id,
                                            entry.card,
                                        )
                                    }
                                />
                            );
                    }
                })}
            </div>

            <form
                onSubmit={submit}
                className={cn('flex items-end gap-2 border-t p-3')}
            >
                <Textarea
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={onKeyDown}
                    placeholder="Type a request…"
                    rows={1}
                    maxLength={2000}
                    disabled={chat.streaming}
                    aria-label="Message the booking assistant"
                    className="max-h-32 min-h-10 resize-none"
                />
                {chat.streaming ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={chat.stop}
                        aria-label="Stop"
                    >
                        <Square />
                    </Button>
                ) : (
                    <Button
                        type="submit"
                        size="icon"
                        disabled={!draft.trim()}
                        aria-label="Send"
                    >
                        <SendHorizontal />
                    </Button>
                )}
            </form>
        </section>
    );
}
