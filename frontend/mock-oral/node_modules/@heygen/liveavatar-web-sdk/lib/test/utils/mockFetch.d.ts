type MockCfg = {
    url: string;
    method?: string;
    response?: unknown;
    status?: number;
    headers?: Record<string, string>;
};
export declare function mockFetch(...configs: MockCfg[]): () => void;
export {};
