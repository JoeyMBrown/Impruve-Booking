import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { BookingDetails } from '@/components/booking/booking-details';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { show } from '@/routes/bookings';
import type { CalendarBooking } from '@/types';

type Props = {
    booking: CalendarBooking | null;
    timezone: string;
    onClose: () => void;
};

/** The booking detail drawer opened from a calendar block. */
export function BookingDrawer({ booking, timezone, onClose }: Props) {
    return (
        <Sheet
            open={booking !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                {booking && (
                    <>
                        <SheetHeader>
                            <SheetTitle>{booking.title}</SheetTitle>
                            <SheetDescription asChild>
                                <Link
                                    href={show(booking.id)}
                                    className="flex items-center gap-1 hover:underline"
                                >
                                    Open page
                                    <ExternalLink className="size-3" />
                                </Link>
                            </SheetDescription>
                        </SheetHeader>
                        <div className="px-4 pb-4">
                            <BookingDetails
                                booking={booking}
                                timezone={timezone}
                                onCancelled={onClose}
                            />
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
