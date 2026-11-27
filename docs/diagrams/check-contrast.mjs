#!/usr/bin/env node

// Measures WCAG 2.2 contrast for the colour pairs PR Lens paints, in both
// themes, using the installed renderer palette plus optional overrides.
//
//   node check-contrast.mjs [--palette github|overrides.json] [--svg rendered-dir]
//
// --svg reads the faded card opacities from rendered SVGs instead of the
// defaults. Exits 1 when a required pair fails. Pairs that appear only in change
// (diff) diagrams print "warn" instead, because their colours cannot be
// overridden safely. render.mjs imports loadOverrides and applyOverrides from
// this file for its --palette option.

import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import { pathToFileURL } from "node:url";
import { paletteFor } from "@coldtea/pr-lens-renderer";

const THEMES = ["light", "dark"];
// Keys replaced everywhere: their default colour must be unique in the SVG.
const GLOBAL_KEYS = ["foreground", "muted", "edge"];
// Keys applied by element class, so they never repaint anything else.
// [key, class token (or exact class with "="), attribute]
const CLASS_ROLES = [
  ["title", "ntitle", "fill"],
  ["subtitle", "nsub", "fill"],
  ["label", "=ltext", "fill"],
  ["laneLabel", "lanelabel", "fill"],
  ["icon", "glyph", "fill"],
  ["icon", "glyph-stroke", "stroke"],
  ["lifeline", "lifeline", "stroke"],
];
const ROLE_KEYS = ["title", "subtitle", "label", "laneLabel", "badge", "icon", "lifeline"];
const OVERRIDABLE = [...GLOBAL_KEYS, ...ROLE_KEYS];
// Built-in palettes for --palette <name>. "github" uses the syntax colours of the VS Code
// themes GitHub Light Default and GitHub Dark Default (primer/github-vscode-theme,
// @primer/primitives 7.10.0): function purple titles, constant blue subtitles, string
// labels, keyword red lanes and badges, variable orange icons, link blue edges, comment
// grey lifelines. Light titles and both badges are one step stronger than the theme so
// they pass on faded cards and chips.
export const PRESETS = {
  github: {
    light: {
      title: "#6639ba", subtitle: "#0550ae", label: "#0a3069", laneLabel: "#cf222e",
      badge: "#a40e26", icon: "#953800", edge: "#0969da", lifeline: "#6e7781",
    },
    dark: {
      title: "#d2a8ff", subtitle: "#79c0ff", label: "#a5d6ff", laneLabel: "#ff7b72",
      badge: "#ffa198", icon: "#ffa657", edge: "#58a6ff", lifeline: "#8b949e",
    },
  },
};
// Sequence participants outside the flow ("context") and removed nodes in
// change diagrams ("ghost") are drawn faded, text included.
const DEFAULT_OPACITY = { context: 0.82, ghost: 0.55 };

function validate(raw, source) {
  const overrides = {};
  for (const [theme, values] of Object.entries(raw)) {
    if (!THEMES.includes(theme)) throw new Error(`Unknown theme "${theme}" in ${source}; use light or dark.`);
    for (const [key, colour] of Object.entries(values)) {
      if (!OVERRIDABLE.includes(key)) {
        throw new Error(`Cannot override "${key}"; supported keys: ${OVERRIDABLE.join(", ")}.`);
      }
      if (!/^#[0-9a-f]{6}$/i.test(colour)) throw new Error(`${theme}.${key} must be a #rrggbb colour.`);
    }
    overrides[theme] = { ...values };
  }
  return overrides;
}

// Accepts a preset name or a JSON file; a file may extend a preset with "extends".
export async function loadOverrides(nameOrFile) {
  if (Object.hasOwn(PRESETS, nameOrFile)) return validate(PRESETS[nameOrFile], nameOrFile);
  const { extends: preset, ...raw } = JSON.parse(await readFile(nameOrFile, "utf8"));
  if (preset !== undefined && !Object.hasOwn(PRESETS, preset)) {
    throw new Error(`Unknown preset "${preset}" in ${nameOrFile}; available: ${Object.keys(PRESETS).join(", ")}.`);
  }
  const base = preset ? PRESETS[preset] : {};
  const merged = {};
  for (const theme of new Set([...Object.keys(base), ...Object.keys(raw)])) {
    merged[theme] = { ...base[theme], ...raw[theme] };
  }
  return validate(merged, nameOrFile);
}

const recolourClass = (svg, className, attribute, colour) => {
  const classPattern = className.startsWith("=")
    ? `class="${className.slice(1)}"`
    : `class="(?:[^"]* )?${className}(?: [^"]*)?"`;
  return svg.replace(
    new RegExp(`(<[a-z]+ [^>]*?${classPattern}[^>]*?\\b${attribute}=")#[0-9a-fA-F]{6}(")`, "g"),
    `$1${colour}$2`,
  );
};

export function applyOverrides(svg, theme, overrides) {
  const base = paletteFor(theme);
  const values = overrides?.[theme] ?? {};
  let out = svg;
  // Global keys first, so class roles win even if they reuse a replaced colour.
  for (const key of GLOBAL_KEYS) {
    if (!values[key]) continue;
    const clash = Object.entries(base).find(([other, value]) => other !== key && value === base[key]);
    if (clash) throw new Error(`${theme}.${key} shares ${base[key]} with ${clash[0]}; it cannot be overridden safely.`);
    out = out.replaceAll(`"${base[key]}"`, `"${values[key]}"`);
  }
  for (const [key, className, attribute] of CLASS_ROLES) {
    if (values[key]) out = recolourClass(out, className, attribute, values[key]);
  }
  if (values.badge) {
    out = out.replace(
      /(<g class="bdg bdg-neutral"><rect [^>]*\/><text [^>]*?\bfill=")#[0-9a-fA-F]{6}(")/g,
      `$1${values.badge}$2`,
    );
  }
  return out;
}

const parse = (colour) => {
  if (colour.startsWith("#")) return [1, 3, 5].map((i) => parseInt(colour.slice(i, i + 2), 16)).concat(1);
  const [r, g, b, a = 1] = colour.match(/[\d.]+/g).map(Number);
  return [r, g, b, a];
};
const over = (fg, bg) => {
  const [r, g, b, a] = typeof fg === "string" ? parse(fg) : fg;
  return [r * a + bg[0] * (1 - a), g * a + bg[1] * (1 - a), b * a + bg[2] * (1 - a), 1];
};
const fade = (colour, bg, opacity) => over([colour[0], colour[1], colour[2], opacity], bg);
const luminance = (colour) => {
  const [r, g, b] = colour.slice(0, 3).map((x) => {
    x /= 255;
    return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
export const contrast = (a, b) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

export function contrastPairs(palette, opacity = DEFAULT_OPACITY) {
  const bg = parse(palette.background);
  const card = over(palette.card, bg);
  const chip = over(palette.chip, card);
  const lane = over(palette.lane, bg);
  const pill = over(palette.pill, bg);
  const role = (key, fallback) => over(palette[key] ?? palette[fallback], bg);
  const title = role("title", "foreground");
  const subtitle = role("subtitle", "muted");
  const label = role("label", "muted");
  const laneLabel = role("laneLabel", "muted");
  const badgeFill = over(palette.neutralFill, card);
  const badge = role("badge", "muted");
  const icon = role("icon", "muted");
  const faded = (colour) => fade(colour, bg, opacity.context);
  const ghosted = (colour) => fade(colour, bg, opacity.ghost);
  const c = `(${opacity.context})`;
  const g = `(${opacity.ghost})`;
  return [
    ["text", "node title on card", title, card, 4.5],
    ["text", `node title on faded context card ${c}`, faded(title), faded(card), 4.5],
    ["text", "node subtitle on card", subtitle, card, 4.5],
    ["text", `node subtitle on faded context card ${c}`, faded(subtitle), faded(card), 4.5],
    ["text", "edge label on its pill", label, pill, 4.5],
    ["text", "lane label on lane", laneLabel, lane, 4.5],
    ["text", "lane label on background", laneLabel, bg, 4.5],
    ["text", "badge text on its fill", badge, badgeFill, 4.5],
    ["text", `badge text on faded context card ${c}`, faded(badge), faded(badgeFill), 4.5],
    ["text", "other muted text on background", parse(palette.muted), bg, 4.5],
    ["diff", "added badge text on its fill", parse(palette.addedText), over(palette.addedFill, card), 4.5],
    ["diff", "changed badge text on its fill", parse(palette.modifiedText), over(palette.modifiedFill, card), 4.5],
    ["diff", "removed badge text on its fill", parse(palette.removedText), over(palette.removedFill, card), 4.5],
    ["diff", `node title on removed (ghost) card ${g}`, ghosted(title), ghosted(card), 4.5],
    ["diff", `node subtitle on removed (ghost) card ${g}`, ghosted(subtitle), ghosted(card), 4.5],
    ["graphic", "node icon on chip", icon, chip, 3],
    ["graphic", `node icon on faded context chip ${c}`, faded(icon), faded(chip), 3],
    ["graphic", "edge on background", parse(palette.edge), bg, 3],
    ["graphic", "edge on lane", parse(palette.edge), lane, 3],
    ["graphic", "lifeline on background", over(palette.lifeline, bg), bg, 3],
    ["decorative", "card border on background", over(palette.cardBorder, bg), bg, 0],
  ];
}

async function opacityFrom(directory) {
  const found = { context: [], ghost: [] };
  for (const file of (await readdir(directory)).filter((name) => name.endsWith(".svg"))) {
    const svg = await readFile(path.join(directory, file), "utf8");
    for (const match of svg.matchAll(/<g class="cardsh (context|ghost)"[^>]*\bopacity="([\d.]+)"/g)) {
      found[match[1]].push(Number(match[2]));
    }
  }
  return {
    context: found.context.length ? Math.min(...found.context) : DEFAULT_OPACITY.context,
    ghost: found.ghost.length ? Math.min(...found.ghost) : DEFAULT_OPACITY.ghost,
  };
}

async function main(args) {
  const option = (name) => {
    const index = args.indexOf(name);
    if (index === -1) return undefined;
    if (!args[index + 1]) throw new Error(`${name} needs a value.`);
    return args[index + 1];
  };
  const palettePath = option("--palette");
  const svgDirectory = option("--svg");
  const overrides = palettePath ? await loadOverrides(palettePath) : {};
  const opacity = svgDirectory ? await opacityFrom(svgDirectory) : DEFAULT_OPACITY;

  let failed = false;
  let warned = false;
  for (const theme of THEMES) {
    console.log(`\n${theme}`);
    const palette = { ...paletteFor(theme), ...overrides[theme] };
    for (const [kind, name, a, b, min] of contrastPairs(palette, opacity)) {
      const ratio = contrast(a, b);
      const status = min === 0 ? "info" : ratio >= min ? "ok  " : kind === "diff" ? "warn" : "FAIL";
      if (status === "FAIL") failed = true;
      if (status === "warn") warned = true;
      console.log(`${status} ${ratio.toFixed(2).padStart(5)}:1  ${min ? `(min ${min})` : "         "}  ${kind}: ${name}`);
    }
  }
  if (warned) console.log("\nwarn: change-diagram colours below the minimum; report them as a limitation.");
  return failed ? 1 : 0;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  try {
    process.exitCode = await main(process.argv.slice(2));
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
