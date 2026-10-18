#!/bin/bash
# usage: run-all.sh <tag> <shem_base> <shimi_base> [extra shoot.py args]   -> $V/<tag>-<site>/
V=${V:-/tmp/vdiff}; T=$(dirname "$0"); tag=$1; shem=$2; shimi=$3; shift 3
for vp in desktop mobile; do
  python3 $T/shoot.py shem  $shem  $V/$tag-shem  --vp=$vp "$@" & 
  python3 $T/shoot.py shimi $shimi $V/$tag-shimi --vp=$vp "$@" &
done
wait
