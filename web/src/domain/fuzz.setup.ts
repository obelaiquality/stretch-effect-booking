// Shared fast-check settings. Fixed seed by default so CI is reproducible.
// Override with FC_SEED=<int> and FC_RUNS=<int> (runs per property) to explore further.
import fc from 'fast-check';

export const FC_SEED = process.env.FC_SEED ? Number(process.env.FC_SEED) : 20261109;
/** numRuns: FC_RUNS wins, else the per-property default. */
export const runs = (dflt: number): fc.Parameters<unknown> => ({
  seed: FC_SEED,
  numRuns: process.env.FC_RUNS ? Number(process.env.FC_RUNS) : dflt,
  verbose: 1,
});
