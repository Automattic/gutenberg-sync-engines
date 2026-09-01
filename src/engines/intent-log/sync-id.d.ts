/**
 * Type declarations for sync-id.js (block identity minting). See
 * engine-types.d.ts for the lockstep discipline.
 */

export function mintSyncId( random?: () => number ): string;
