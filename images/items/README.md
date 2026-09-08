# Local item icons

This directory includes a small set of 57 original Classic starter/common gear
icons, so newly created characters and common equipment can display without a
third-party image request. The PNGs are original-size exports, not redraws or
AI-generated replacements.

- Artwork: World of Warcraft, © Blizzard Entertainment.
- Texture mirror: [Gethe/wow-ui-textures, classic](https://github.com/Gethe/wow-ui-textures/tree/312a6e61bab69370c65ccc0a08f1075d62196294/ICONS).
- Filenames were matched to the bundled client's `IconFileDataID` through the
  community listfile; see `data/ASSET-SOURCES.md`.

Files are named by **icon FileDataID**, not by item ID or display ID. For example,
`135274.png` is `INV_Sword_04`, used by item 25 (Worn Shortsword).

To add icons extracted from your own client, save them here as
`<FileDataID>.png`, `.jpg`, or `.webp`. PHP checks those extensions in that order.
Local files always take priority, including when `remote_item_icons` is false.
The remaining icons can load by their verified filename from the Wowhead CDN;
if unavailable, the slot outline and item details remain usable.
