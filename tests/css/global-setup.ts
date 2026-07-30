import { build } from 'vite';

/**
 * Die Prüfungen lesen das gebaute Stylesheet aus `public/build`. Ohne eigenen Build entschiede der
 * Zufall, ob dort noch ein Artefakt vom letzten Mal liegt — ein veraltetes meldet die Suite grün.
 */
export default async function setup(): Promise<void> {
    await build({ logLevel: 'warn' });
}
