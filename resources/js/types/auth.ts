export type User = {
    id: number;
    name: string;
    email: string | null;
    avatar?: string;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};
