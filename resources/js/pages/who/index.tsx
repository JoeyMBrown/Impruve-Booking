import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/who';

type ActingUserOption = {
    id: number;
    name: string;
};

type Props = {
    users: ActingUserOption[];
};

export default function Who({ users }: Props) {
    const [selected, setSelected] = useState<ActingUserOption | null>(null);

    return (
        <>
            <Head title="Who's booking?" />

            <div className="grid grid-cols-2 gap-2">
                {users.map((user) => (
                    <Button
                        key={user.id}
                        variant="outline"
                        className="justify-start"
                        onClick={() => setSelected(user)}
                    >
                        {user.name}
                    </Button>
                ))}
            </div>

            <Dialog
                open={selected !== null}
                onOpenChange={(open) => !open && setSelected(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Continue as {selected?.name}?</DialogTitle>
                        <DialogDescription>
                            Bookings you make will be in your name.
                        </DialogDescription>
                    </DialogHeader>

                    <Form {...store.form()}>
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="user_id"
                                    value={selected?.id ?? ''}
                                />
                                <InputError message={errors.user_id} />

                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button type="button" variant="secondary">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        data-test="continue-as-button"
                                    >
                                        {processing && <Spinner />}
                                        Continue
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

Who.layout = {
    title: "Who's booking?",
    description: 'Pick your name to continue.',
};
