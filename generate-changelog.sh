#!/bin/bash
# usage: generate-changelog.sh previous_tag next_tag
# example: generate-changelog.sh v2.7.2 v2.7.3 > CHANGELOG.new.md
lowerbranch=$(git branch --merged HEAD | grep '[[:digit:]]\.[[:digit:]]' | grep -v '*' | sort -rg | head -n 1)
log=$(git log "$1..HEAD" --no-merges --not $lowerbranch --pretty='format:* [%h](https://github.com/api-platform/core/commit/%H) %s')

diff=$(
printf "# Changelog\n\n"
printf "## %s\n\n" "$2"

fixes=$(echo "$log" | grep 'fix(\|fix:')
if [[ 0 != $(echo "$fixes" | wc -l) ]];
then
    printf "### Bug fixes\n\n"
    printf "$fixes" | sort
    printf "\n\n"
fi

feat=$(echo "$log" | grep 'feat(\|feat:')
if [[ 0 != $(echo "$feat" | wc -l) ]];
then
    printf "### Features\n\n"
    printf "$feat" | sort
fi
)

changelog=$(tail -n+2 CHANGELOG.md)
printf "%s\n%s" "$diff" "$changelog"
