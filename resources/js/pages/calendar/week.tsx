import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { create as createBooking } from '@/routes/bookings';

// Placeholder until the weekly calendar (M6), which brings its own
// "+ New booking" button in the calendar header.
export default function CalendarWeek() {
    return (
        <>
            <Head title="Calendar" />
            <div className="flex flex-col items-start gap-4 p-4">
                <Button asChild>
                    <Link href={createBooking()}>
                        <Plus />
                        New booking
                    </Link>
                </Button>
                <p className="text-sm text-muted-foreground">
                    The weekly calendar is coming soon.
                </p>
            </div>
        </>
    );
}
