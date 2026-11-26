#!/usr/bin/env bash
# Run the Jev vs Akismet benchmark.
#
# Usage: benchmark/run.sh <site-url> [sample] [seed]
#
# Downloads the UCI YouTube Spam Collection once (to benchmark/data/), then runs
# compare-akismet.php through WP-CLI and writes per-comment results to
# benchmark/results/jev-akismet.csv. Set WP to use another WP-CLI command,
# e.g. WP="bash ~/.agents/skills/wp-cli-local/scripts/wp".
set -euo pipefail

if [[ $# -lt 1 ]]; then
	echo "Usage: $0 <site-url> [sample=200] [seed=jct]" >&2
	exit 1
fi

url="$1"
sample="${2:-200}"
seed="${3:-jct}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
data="$here/data"
zip_url="https://archive.ics.uci.edu/static/public/380/youtube+spam+collection.zip"

if ! ls "$data"/Youtube0*.csv >/dev/null 2>&1; then
	mkdir -p "$data"
	echo "Downloading the YouTube Spam Collection..."
	curl -fsSL -o "$data/yt.zip" "$zip_url"
	unzip -oq "$data/yt.zip" -d "$data" -x '__MACOSX/*'
	rm -f "$data/yt.zip"
fi

mkdir -p "$here/results"
# shellcheck disable=SC2086 # WP may hold a command plus arguments.
${WP:-wp} eval-file "$here/compare-akismet.php" \
	"dataset=$data" "sample=$sample" "seed=$seed" "out=$here/results/jev-akismet.csv" \
	--url="$url"
