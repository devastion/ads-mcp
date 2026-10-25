export type ApiKeyScope = 'search:read' | 'keys:manage';

export type DeveloperKey = {
    id: number;
    name: string;
    prefix: string;
    scopes: ApiKeyScope[];
    created_at: string;
    last_used_at: string | null;
    revoked_at: string | null;
    last_mcp_request_at: string | null;
};

export type DeveloperUsage = {
    limit: number;
    period_start: string;
    resets_at: string;
    per_key: { key_id: number; used: number }[];
};

export type McpConnection = {
    key_id: number;
    prefix: string;
    connected_at: string | null;
};

export type DeveloperMcp = {
    endpoint_url: string;
    client: 'Pi-Bolt';
    config_template: string;
    connection: McpConnection | null;
};

export type DeveloperProps = {
    keys: DeveloperKey[];
    usage: DeveloperUsage;
    mcp: DeveloperMcp;
};

export type CreatedKey = DeveloperKey & { secret: string };

export type CreateKeyResponse = { data: CreatedKey };
