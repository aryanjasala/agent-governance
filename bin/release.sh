#!/usr/bin/env bash
# Version and changelog helper for releases. Used by the prepare-release and
# release workflows, and works the same locally.
#
#   bin/release.sh current            version in the plugin header
#   bin/release.sh check              fail unless header, VERSION and Stable tag agree
#   bin/release.sh next               next version from commits since the last release, or nothing
#   bin/release.sh notes              readme.txt changelog lines for those commits
#   bin/release.sh apply <version> <notes-file>
#                                     write the version into all three places and add the changelog entry
#
# Commits follow Conventional Commits (squash-merged PR titles):
#   feat: -> minor, fix: or perf: -> patch, feat!: or a "BREAKING CHANGE" footer -> major.
#   docs:, ci:, chore:, test:, refactor:, build:, style: do not trigger a release.
set -euo pipefail
cd "$(dirname "$0")/.."

slug="agent-governance"
main_file="$slug.php"

header_version() {
	sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$main_file" | head -1
}

constant_version() {
	sed -nE "s/^const VERSION[[:space:]]*=[[:space:]]*'([^']+)';/\1/p" "$main_file" | head -1
}

stable_tag() {
	sed -nE 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' readme.txt | head -1
}

# Commits since the tag of the current version, as "subject<US>body<RS>".
commits_since_release() {
	local tag="v$(header_version)"
	if ! git rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
		echo "$tag is not tagged yet; the release workflow publishes it on the next push to main." >&2
		return 1
	fi
	git log --no-merges --format='%s%x1f%b%x1e' "$tag..HEAD"
}

case "${1:-}" in
	current)
		header_version
		;;

	check)
		header="$(header_version)"
		constant="$(constant_version)"
		stable="$(stable_tag)"
		if [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
			echo "Version mismatch: header $header, VERSION $constant, readme Stable tag $stable." >&2
			exit 1
		fi
		if ! grep -qxF "= $header =" readme.txt; then
			echo "readme.txt has no changelog entry for $header." >&2
			exit 1
		fi
		echo "$header"
		;;

	next)
		log="$(commits_since_release)" || exit 0
		bump="$(printf '%s' "$log" | awk 'BEGIN { RS = "\036"; FS = "\037"; level = 0 }
			{
				sub(/^\n+/, "")
				if ($1 == "") next
				if ($1 ~ /^[a-z]+(\([^)]*\))?!:/ || $2 ~ /BREAKING[ -]CHANGE/) { if (level < 3) level = 3 }
				else if ($1 ~ /^feat(\([^)]*\))?:/) { if (level < 2) level = 2 }
				else if ($1 ~ /^(fix|perf)(\([^)]*\))?:/) { if (level < 1) level = 1 }
			}
			END { split("none patch minor major", names, " "); print names[level + 1] }')"
		[ "$bump" = none ] && exit 0
		IFS=. read -r major minor patch <<<"$(header_version)"
		case "$bump" in
			major) echo "$((major + 1)).0.0 major" ;;
			minor) echo "$major.$((minor + 1)).0 minor" ;;
			patch) echo "$major.$minor.$((patch + 1)) patch" ;;
		esac
		;;

	notes)
		log="$(commits_since_release)" || exit 0
		printf '%s' "$log" | awk 'BEGIN { RS = "\036"; FS = "\037" }
			{
				sub(/^\n+/, "")
				subject = $1
				if (!match(subject, /^[a-z]+(\([^)]*\))?!?: /)) next
				text = substr(subject, RLENGTH + 1)
				sub(/ \(#[0-9]+\)$/, "", text)
				type = subject
				sub(/[(!:].*/, "", type)
				breaking = (subject ~ /^[a-z]+(\([^)]*\))?!:/ || $2 ~ /BREAKING[ -]CHANGE/)
				if (type != "feat" && type != "fix" && type != "perf" && !breaking) next
				label = "Changed"
				if (type == "feat") label = "New"
				if (type == "fix") label = "Fix"
				if (type == "perf") label = "Faster"
				if (breaking) label = label " (breaking change)"
				print "* " label ": " text
			}'
		;;

	apply)
		version="${2:?usage: bin/release.sh apply <version> <notes-file>}"
		notes="${3:?usage: bin/release.sh apply <version> <notes-file>}"
		[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Not a version: $version" >&2; exit 1; }
		[ -s "$notes" ] || { echo "Changelog notes are empty: $notes" >&2; exit 1; }

		perl -pi -e "s/^(\s*\*\s*Version:\s*)\S+/\${1}$version/" "$main_file"
		perl -pi -e "s/^(const VERSION\s*=\s*')[^']+(';)/\${1}$version\${2}/" "$main_file"
		perl -pi -e "s/^(Stable tag:\s*)\S+/\${1}$version/" readme.txt
		# New entry goes first under "== Changelog ==", one blank line either side.
		awk -v version="$version" -v notes="$notes" '
			/^== Changelog ==$/ {
				print
				print ""
				print "= " version " ="
				while ((getline line < notes) > 0) print line
				print ""
				if ((getline following) > 0 && following != "") print following
				next
			}
			{ print }' readme.txt > readme.txt.tmp && mv readme.txt.tmp readme.txt

		"$0" check >/dev/null
		echo "Updated $main_file and readme.txt to $version"
		;;

	*)
		sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
		exit 1
		;;
esac
