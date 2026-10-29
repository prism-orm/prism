#!/usr/bin/env bash
# ADR 0016 : publie le site construit (website/.vitepress/dist) sur la branche gh-pages de
# prism-orm/prism, que GitHub Pages sert à https://prism-orm.github.io/prism/. Le job pages de
# ci.yml l'appelle après les tests ; make docs-publish l'appelle depuis ce poste, sans GitHub
# Actions, avec les identifiants Git de l'utilisateur. À blanc par défaut : rien n'est poussé
# sans PUSH=1.
#
#     make docs-publish [PUSH=1] [REMOTE=git@github.com:prism-orm/prism.git]
#
# - gh-pages ne garde que le site du moment : un seul commit, remplacé à chaque publication
#   (le site se reconstruit à l'identique depuis main, son historique n'apporte rien) ;
# - le site doit venir de main : une autre branche est refusée, sauf ANY_BRANCH=1.
set -euo pipefail

cd "$(dirname "$0")/../.."

PUSH="${PUSH:-}"
REMOTE="${REMOTE:-https://github.com/prism-orm/prism.git}"
ANY_BRANCH="${ANY_BRANCH:-}"
SITE="website/.vitepress/dist"
BRANCH="gh-pages"

fail() {
    echo "pages: $*" >&2
    exit 1
}

[[ -f "$SITE/index.html" ]] || fail "no built site in $SITE: run make docs-build first."
source_ref="${GITHUB_REF_NAME:-$(git rev-parse --abbrev-ref HEAD)}"
[[ "$source_ref" == "main" || "$ANY_BRANCH" == "1" ]] || fail "the site is published from main, not $source_ref (ANY_BRANCH=1 to force)."
source_sha="$(git rev-parse --short HEAD)"

# un dépôt jetable : le site seul, en un commit, sans toucher au dépôt de travail
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
cp -R "$SITE/." "$work/"
# sans Jekyll : GitHub Pages servirait mal les fichiers et dossiers qui commencent par « _ »
touch "$work/.nojekyll"

name="$(git config user.name || echo 'Prism')"
email="$(git config user.email || echo 'prism@users.noreply.github.com')"
git -C "$work" init --quiet --initial-branch="$BRANCH"
git -C "$work" add --all
git -C "$work" -c user.name="$name" -c user.email="$email" commit --quiet -m "docs: publish the site from $source_ref ($source_sha)"

files="$(git -C "$work" ls-files | wc -l | tr -d ' ')"
if [[ "$PUSH" == "1" ]]; then
    echo "Publishing $files files from $source_ref ($source_sha) to $BRANCH of ${REMOTE//x-access-token:*@/***@}"
    git -C "$work" push --force --quiet "$REMOTE" "$BRANCH:$BRANCH" || fail "the push failed; the site online is unchanged."
    echo "Done: https://prism-orm.github.io/prism/ (GitHub Pages takes a minute to update)."
else
    echo "Dry run, nothing is pushed (PUSH=1 to push): $files files from $source_ref ($source_sha) to $BRANCH of $REMOTE"
fi
