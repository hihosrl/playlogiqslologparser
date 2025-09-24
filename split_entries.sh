#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<EOF
Usage: $(basename "$0") -i INPUT_FILE [-o OUT_DIR] [-n ENTRIES_PER_FILE]

Options:
  -i  Path to input log (.log or .gz)
  -o  Output directory (default: ./chunks)
  -n  Entries per output file (default: 10000)

Notes:
  - An "entry" begins at any of:
      ^# User@Host:
      ^# Time:
      ^YYYY-MM-DD.* # Time:
  - .gz files are streamed (no full-disk decompress).
  - Files are named: <base>.part_0000.<ext>
EOF
}

OUT_DIR="./chunks"
ENTRIES_PER_FILE=10000
INPUT_FILE=""

while getopts ":i:o:n:h" opt; do
  case "$opt" in
    i) INPUT_FILE="$OPTARG" ;;
    o) OUT_DIR="$OPTARG" ;;
    n) ENTRIES_PER_FILE="$OPTARG" ;;
    h) usage; exit 0 ;;
    \?) echo "Invalid option: -$OPTARG" >&2; usage; exit 2 ;;
    :)  echo "Option -$OPTARG requires an argument." >&2; usage; exit 2 ;;
  esac
done

if [[ -z "$INPUT_FILE" ]]; then
  echo "Error: -i INPUT_FILE is required" >&2
  usage
  exit 2
fi
if [[ ! -f "$INPUT_FILE" ]]; then
  echo "Error: input file not found: $INPUT_FILE" >&2
  exit 1
fi

mkdir -p "$OUT_DIR"

# Determine naming
fname="$(basename -- "$INPUT_FILE")"
base="$fname"
ext=""
if [[ "$fname" == *.gz ]]; then
  base="${fname%.gz}"
fi
if [[ "$base" == *.* ]]; then
  ext="${base##*.}"
  base="${base%.*}"
else
  ext="log"
fi

prefix="${OUT_DIR}/${base}.part_"
suffix=".${ext}"

# Stream command
if [[ "$INPUT_FILE" == *.gz ]]; then
  stream_cmd=(zcat -- "$INPUT_FILE")
else
  stream_cmd=(cat -- "$INPUT_FILE")
fi

# Run awk to split by entry without cutting
# shellcheck disable=SC2016
"${stream_cmd[@]}" | awk -v entries_per_file="$ENTRIES_PER_FILE" -v prefix="$prefix" -v suffix="$suffix" '
function open_next_file(   fn) {
  file_index++
  fn = sprintf("%s%04d%s", prefix, file_index, suffix)
  if (out != "") close(out)
  out = fn
  entries_in_file = 0
}
function flush_entry() {
  if (entry_len == 0) return
  if (out == "" || entries_in_file >= entries_per_file) {
    open_next_file()
  }
  printf "%s", entry > out
  entries_in_file++
  entry = ""
  entry_len = 0
}
function start_new_entry(line) {
  flush_entry()
  entry = line ORS
  entry_len = 1
}
BEGIN {
  out = ""
  entry = ""
  entry_len = 0
  file_index = -1
  entries_in_file = 0
}
{
  if ($0 ~ /^# User@Host:/ || $0 ~ /^# Time:/ || $0 ~ /^[0-9]{4}-[0-9]{2}-[0-9]{2}.* # Time:/) {
    start_new_entry($0)
  } else {
    if (entry_len == 0) {
      # In case the file starts mid-entry, collect until we hit a header
      entry = $0 ORS
      entry_len = 1
    } else {
      entry = entry $0 ORS
      entry_len++
    }
  }
}
END {
  flush_entry()
  # print a brief summary to stderr
  printf "Created %d file(s)\n", (file_index+1) > "/dev/stderr"
}
'

echo "Chunks written to: $OUT_DIR"
ls -1 "${prefix}"*[0-9][0-9][0-9][0-9]"${suffix}" 2>/dev/null || true