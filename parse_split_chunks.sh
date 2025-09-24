#!/usr/bin/env bash
set -euo pipefail

# Small wrapper to iterate chunks in a directory and run the PHP parser per file.
# Usage:
#   ./parse_split_chunks.sh [DIR]
# Env overrides:
#   PARSER=/home/bytoz/.aws/logParser3
#   PHP_BIN=php
#   LOG=/home/bytoz/.aws/parse_chunks.log
#   GLOB=betmaker_slow.part_*.log   # pattern of chunk files to process
#   RESUME=true                      # skip files that already have .processed marker (default true)
#   BULK=1                           # enable bulk TSV + LOAD DATA mode in parser

DIR=${1:-/pp/split}
PARSER=${PARSER:-/home/bytoz/.aws/logParser3}
PHP_BIN=${PHP_BIN:-php}
LOG=${LOG:-/home/bytoz/.aws/parse_chunks.log}
GLOB=${GLOB:-betmaker_slow.part_*.log}
RESUME=${RESUME:-true}
BULK=${BULK:-1}

if [[ ! -d "$DIR" ]]; then
  echo "Directory not found: $DIR" >&2
  exit 1
fi
if [[ ! -f "$PARSER" ]]; then
  echo "Parser not found: $PARSER" >&2
  exit 1
fi

shopt -s nullglob
mapfile -t files < <(ls -1v -- "$DIR"/$GLOB 2>/dev/null || true)
if (( ${#files[@]} == 0 )); then
  echo "No files matching pattern in $DIR: $GLOB"
  exit 0
fi

echo "Processing ${#files[@]} files in $DIR (pattern: $GLOB)"
for f in "${files[@]}"; do
  base=$(basename -- "$f")
  stamp="$f.processed"
  if [[ "$RESUME" == "true" && -f "$stamp" ]]; then
    echo "Skip $base (already processed)"
    continue
  fi

  start_ts=$(date '+%F %T')
  echo "[$start_ts] START $base" | tee -a "$LOG"
  if BULK="$BULK" "$PHP_BIN" "$PARSER" "$f" >>"$LOG" 2>&1; then
    touch "$stamp"
    end_ts=$(date '+%F %T')
    echo "[$end_ts] OK    $base" | tee -a "$LOG"
  else
    end_ts=$(date '+%F %T')
    echo "[$end_ts] FAIL  $base (see $LOG)" | tee -a "$LOG"
    exit 1
  fi

done

echo "All done. Log: $LOG"
