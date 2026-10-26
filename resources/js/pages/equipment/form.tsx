import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { index, show, store, update } from '@/routes/equipment';
import type { Equipment, EquipmentCategory, Option } from '@/types';

type Props = {
    equipment: Equipment | null;
    categories: Option<EquipmentCategory>[];
};

export default function EquipmentForm({ equipment, categories }: Props) {
    const title = equipment ? `Edit ${equipment.name}` : 'Add equipment';
    const action = equipment ? update.form(equipment.id) : store.form();

    return (
        <>
            <Head title={title} />

            <div className="flex max-w-xl flex-col gap-6 p-4">
                <h1 className="text-xl font-semibold tracking-tight">
                    {title}
                </h1>

                <Form {...action} className="flex flex-col gap-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name *</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    autoFocus
                                    defaultValue={equipment?.name}
                                    placeholder="Sony FX3 #3"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="category">Category *</Label>
                                <Select
                                    name="category"
                                    required
                                    defaultValue={equipment?.category}
                                >
                                    <SelectTrigger id="category">
                                        <SelectValue placeholder="Choose a category" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {categories.map((category) => (
                                            <SelectItem
                                                key={category.value}
                                                value={category.value}
                                            >
                                                {category.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.category} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="asset_tag">Asset tag</Label>
                                <Input
                                    id="asset_tag"
                                    name="asset_tag"
                                    defaultValue={equipment?.asset_tag ?? ''}
                                    placeholder="IMP-011"
                                />
                                <InputError message={errors.asset_tag} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    name="description"
                                    rows={4}
                                    defaultValue={equipment?.description ?? ''}
                                    aria-describedby="description-hint"
                                />
                                <p
                                    id="description-hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    The assistant reads this. Mention
                                    distinguishing features.
                                </p>
                                <InputError message={errors.description} />
                            </div>

                            <div className="flex gap-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {equipment
                                        ? 'Save changes'
                                        : 'Add equipment'}
                                </Button>
                                <Button variant="secondary" asChild>
                                    <Link
                                        href={
                                            equipment
                                                ? show(equipment.id)
                                                : index()
                                        }
                                    >
                                        Cancel
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
