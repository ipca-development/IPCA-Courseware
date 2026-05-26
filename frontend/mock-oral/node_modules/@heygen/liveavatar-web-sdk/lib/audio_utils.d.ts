/**
 * Splits a PCM 24KHz audio string (raw 16-bit signed PCM) into chunks.
 *
 * The first chunk is 400ms to minimize time-to-first-audio; subsequent chunks
 * are 1s to reduce message overhead once playback is underway.
 *
 * At 24,000Hz, 16-bit mono: 1 sample = 2 bytes.
 *   400ms => 24000 * 0.4 * 2 = 19200 bytes
 *   1000ms => 24000 * 1.0 * 2 = 48000 bytes
 */
export declare function splitPcm24kStringToChunks(pcmString: string): string[];
