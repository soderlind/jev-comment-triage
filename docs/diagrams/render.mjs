#!/usr/bin/env node

import { existsSync } from "node:fs";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { safeParseGraphDoc } from "@coldtea/pr-lens-schema";
import { flattenViews, render, renderAssetId } from "@coldtea/pr-lens-renderer";

const args = process.argv.slice(2);
const paletteIndex = args.indexOf("--palette");
let palettePath;
if (paletteIndex !== -1) {
  palettePath = args[paletteIndex + 1];
  args.splice(paletteIndex, 2);
}
if (args.length !== 2 || (paletteIndex !== -1 && !palettePath)) {
  console.error("Usage: node render.mjs <graph.json> <output-directory> [--palette github|overrides.json]");
  process.exit(1);
}

// Palette overrides are opt-in and need check-contrast.mjs next to this file.
let recolour = (svg) => svg;
if (palettePath) {
  const { loadOverrides, applyOverrides } = await import("./check-contrast.mjs");
  const overrides = await loadOverrides(palettePath);
  recolour = (svg, theme) => applyOverrides(svg, theme, overrides);
}

const [graphPath, outputDirectory] = args;
const graph = JSON.parse(await readFile(graphPath, "utf8"));
const parsed = safeParseGraphDoc(graph);
if (!parsed.ok) {
  console.error(`PR Lens validation failed (${parsed.error.code}): ${parsed.error.message}`);
  for (const issue of parsed.error.issues) {
    console.error(`  ${issue.path}: ${issue.message}`);
  }
  process.exit(1);
}

const views = flattenViews(parsed.value.views);
const supportedViews = views.filter((view) => view.lens === "architecture" || view.lens === "data-flow");
for (const view of views) {
  if (view.lens !== "architecture" && view.lens !== "data-flow") {
    console.warn(`Skipping view "${view.id}" with unsupported lens "${view.lens}".`);
  }
}
if (supportedViews.length === 0) {
  throw new Error("The graph must declare at least one architecture or data-flow view.");
}

const outputs = [];
const filenames = new Set();
for (const view of supportedViews) {
  for (const theme of ["light", "dark"]) {
    const filename = `${renderAssetId({ lens: view.lens, view: view.id, theme })}.svg`;
    const normalizedName = filename.toLowerCase();
    if (path.basename(filename) !== filename || filenames.has(normalizedName)) {
      throw new Error(`Unsafe or conflicting output filename: ${filename}`);
    }
    filenames.add(normalizedName);
    const { svg, width, height } = render(parsed.value, { lens: view.lens, view: view.id, theme });
    outputs.push({ filename, svg: recolour(svg, theme), width, height });
  }
}

await mkdir(outputDirectory, { recursive: true });
for (const output of outputs) {
  const destination = path.join(outputDirectory, output.filename);
  if (existsSync(destination)) {
    throw new Error(`Refusing to overwrite ${destination}; choose a new output directory.`);
  }
}
for (const output of outputs) {
  const destination = path.join(outputDirectory, output.filename);
  await writeFile(destination, output.svg, { flag: "wx" });
  console.log(`${destination} (${output.width} x ${output.height})`);
}
