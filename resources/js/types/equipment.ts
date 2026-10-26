export type EquipmentCategory = 'camera' | 'lighting' | 'space';

export type EquipmentStatus = 'available' | 'out_of_service' | 'retired';

export type Equipment = {
    id: number;
    name: string;
    category: EquipmentCategory;
    description: string | null;
    asset_tag: string | null;
    status: EquipmentStatus;
    status_reason: string | null;
    status_changed_at: string | null;
};

export type Option<T extends string = string> = {
    value: T;
    label: string;
};
