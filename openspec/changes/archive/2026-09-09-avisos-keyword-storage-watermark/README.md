# avisos-keyword-storage-watermark

Introduce watermarks per (keyword, storage) to make the scan model idempotent per-keyword, eliminate redundant rescans of historical content, and isolate historical catch-up cost when a client adds new keywords or requests retroactive history.
