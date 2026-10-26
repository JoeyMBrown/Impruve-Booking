import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { joinLocal, splitLocal, timeOptions } from '@/lib/local-time';

type Props = {
    id: string;
    label: string;
    value: string;
    slotMinutes: number;
    disabled?: boolean;
    onChange: (value: string) => void;
};

/** A native date input plus a time select in slot steps. */
export function DateTimeField({
    id,
    label,
    value,
    slotMinutes,
    disabled = false,
    onChange,
}: Props) {
    const { date, time } = splitLocal(value);

    return (
        <fieldset className="grid gap-2" disabled={disabled}>
            <legend className="mb-2 text-sm font-medium">{label}</legend>
            <div className="flex gap-2">
                <Label htmlFor={`${id}-date`} className="sr-only">
                    {label} date
                </Label>
                <Input
                    id={`${id}-date`}
                    type="date"
                    className="w-44"
                    value={date}
                    required
                    onChange={(event) =>
                        event.target.value &&
                        onChange(joinLocal(event.target.value, time))
                    }
                />
                <Select
                    value={time}
                    disabled={disabled}
                    onValueChange={(next) => onChange(joinLocal(date, next))}
                >
                    <SelectTrigger
                        id={`${id}-time`}
                        className="w-32"
                        aria-label={`${label} time`}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent className="max-h-72">
                        {timeOptions(slotMinutes).map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </fieldset>
    );
}
