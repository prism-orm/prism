#!/usr/bin/env bash
# ADR 0004 : publie chaque packages/<nom> vers github.com/prism-orm/<nom>, comme
# .github/workflows/split.yml, mais depuis ce poste : sans GitHub Actions, avec les identifiants
# Git de l'utilisateur. À blanc par défaut : rien n'est poussé sans PUSH=1.
#
#     make split [TAG="v0.8.0 v0.9.0"] [PUSH=1] [FORCE=1] [REMOTE=git@github.com:prism-orm/%s.git]
#
# - la branche main de chaque paquet vient de la branche main locale ; avec TAG (un ou
#   plusieurs, séparés par des espaces), chaque tag du paquet vient du tag local du même nom ;
# - les paquets sont ceux de la matrice de split.yml : une seule liste à maintenir ;
# - pas de --force : le split est déterministe, une divergence doit échouer bruyamment. FORCE=1
#   remplace l'historique publié, seulement après une réécriture voulue de celui du monorepo
#   (messages corrigés) : les tags déjà publiés changent de commit, et Packagist les suit.
set -euo pipefail

cd "$(dirname "$0")/../.."

TAG="${TAG:-}"
PUSH="${PUSH:-}"
FORCE="${FORCE:-}"
REMOTE="${REMOTE:-https://github.com/prism-orm/%s.git}"
BRANCH="main"

# la version et l'empreinte de split.yml
LITE_VERSION="v1.0.1"
LITE_SHA256="2539301ce5e21d0ca44b689d0dd2c1b20d9f9e996c1fe6c462afb8af4e7141cc"
LITE="var/bin/splitsh-lite"

fail() {
    echo "split: $*" >&2
    exit 1
}

# le SHA du split d'un paquet à une référence ; les statistiques de splitsh-lite seulement en cas d'erreur
split_sha() {
    local log
    log="$(mktemp)"
    if ! "$LITE" --prefix="packages/$1" --origin="$2" 2> "$log"; then
        cat "$log" >&2
        rm -f "$log"
        fail "splitsh-lite failed on packages/$1 at $2."
    fi
    rm -f "$log"
}

# un SHA vide (paquet absent à cette référence) ferait du refspec « :ref » une suppression
add_ref() {
    local package="$1" sha="$2" ref="$3" label="$4"
    if [[ -z "$sha" ]]; then
        printf '  %-18s %-12s  %s: packages/%s does not exist there, skipped\n' "$package" "-" "$label" "$package"
        return
    fi
    [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || fail "unexpected split of packages/$package at $label: $sha"
    # « + » : la référence distante est remplacée même si l'historique a divergé
    refspecs+=("${FORCE:+${FORCE/1/+}}$sha:$ref")
    printf '  %-18s %s  %s → %s\n' "$package" "${sha:0:12}" "$label" "$remote"
}

# splitsh-lite, téléchargé une fois et vérifié
if [[ ! -x "$LITE" ]]; then
    [[ "$(uname -s)-$(uname -m)" == "Linux-x86_64" ]] || fail "splitsh-lite $LITE_VERSION is only fetched for Linux x86-64; install it as $LITE."
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    echo "Fetching splitsh-lite $LITE_VERSION…"
    curl -sSfL -o "$tmp/lite.tgz" "https://github.com/splitsh/lite/releases/download/$LITE_VERSION/lite_linux_amd64.tar.gz"
    echo "$LITE_SHA256  $tmp/lite.tgz" | sha256sum -c --quiet - || fail "the checksum of splitsh-lite does not match: not installed."
    tar -xzf "$tmp/lite.tgz" -C "$tmp" ./splitsh-lite
    mkdir -p "$(dirname "$LITE")"
    mv "$tmp/splitsh-lite" "$LITE"
    chmod +x "$LITE"
fi

# la liste des paquets, lue dans la matrice du workflow
packages="$(sed -n 's/^ *package: *\[\(.*\)\] *$/\1/p' .github/workflows/split.yml | tr -d ' ' | tr ',' ' ')"
[[ -n "$packages" ]] || fail "no package list found in .github/workflows/split.yml (matrix.package)."

git rev-parse --verify --quiet "refs/heads/$BRANCH" > /dev/null || fail "no local branch $BRANCH."
[[ -z "$FORCE" || "$FORCE" == "1" ]] || fail "FORCE is 1 or empty."
for tag in $TAG; do
    git rev-parse --verify --quiet "refs/tags/$tag" > /dev/null || fail "no local tag $tag."
done
for package in $packages; do
    [[ -d "packages/$package" ]] || fail "packages/$package does not exist."
done

if [[ "$PUSH" == "1" ]]; then
    echo "Pushing $BRANCH${TAG:+ and $TAG} of: $packages"
else
    echo "Dry run, nothing is pushed (PUSH=1 to push): $BRANCH${TAG:+ and $TAG} of: $packages"
fi
if [[ "$FORCE" == "1" ]]; then
    echo "FORCE=1: the published branch and tags are replaced, even where their history differs."
fi

for package in $packages; do
    remote="$(printf "$REMOTE" "$package")"
    refspecs=()
    add_ref "$package" "$(split_sha "$package" "refs/heads/$BRANCH")" "refs/heads/$BRANCH" "$BRANCH"
    for tag in $TAG; do
        add_ref "$package" "$(split_sha "$package" "refs/tags/$tag")" "refs/tags/$tag" "$tag"
    done
    if [[ "$PUSH" == "1" && ${#refspecs[@]} -gt 0 ]]; then
        git push "$remote" "${refspecs[@]}" || fail "the push of $package failed; the packages before it are published, run again once fixed."
    fi
done

[[ "$PUSH" == "1" ]] && echo "Done." || echo "Nothing pushed. Run again with PUSH=1 to publish."
