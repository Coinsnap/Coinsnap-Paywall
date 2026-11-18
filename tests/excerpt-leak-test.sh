#!/bin/bash
# Paid content must not leak through automatic excerpts (REST API, RSS feed, oEmbed).
# Usage: tests/excerpt-leak-test.sh <site-url> <post-id> <marker>
#   <post-id> is a published post whose content is "teaser [paywall_payment id=N] <marker>",
#   <marker> is a word that appears only after the shortcode. Run as a visitor without access.
B=${1:?site url}; POST=${2:?post id}; MARK=${3:?marker}; F=0
check(){ if [ "$2" = 0 ]; then echo "PASS $1"; else echo "FAIL $1 (paid content found)"; F=$((F+1)); fi; }
check "REST API excerpt" "$(curl -sL "$B/wp-json/wp/v2/posts/$POST" | grep -c "$MARK")"
check "RSS feed" "$(curl -sL "$B/?feed=rss2" | grep -c "$MARK")"
check "oEmbed embed page" "$(curl -sL "$B/?p=$POST&embed=true" | grep -c "$MARK")"
check "post page" "$(curl -sL "$B/?p=$POST" | grep -c "$MARK")"
echo "$F failed"; exit $F
