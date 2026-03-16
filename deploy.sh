#!/bin/bash

# =============================================================================
# 1Platform Content AI — WordPress.org SVN Deploy Script
# =============================================================================
#
# Deploys the plugin to the WordPress.org SVN repository.
#
# Usage:
#   ./deploy.sh              # Deploy current version (reads from plugin header)
#   ./deploy.sh 2.3.3        # Deploy specific version
#   ./deploy.sh --assets     # Update only SVN assets (banners, icons, screenshots)
#   ./deploy.sh --dry-run    # Show what would be done without committing
#
# Requirements:
#   - svn command-line client installed
#   - Valid WordPress.org SVN credentials
#
# =============================================================================

set -euo pipefail

# --- Configuration -----------------------------------------------------------

PLUGIN_SLUG="1platform-content-ai"
PLUGIN_FILE="1platform-content-ai.php"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
SVN_DIR="/tmp/${PLUGIN_SLUG}-svn"
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
ASSETS_DIR="${PLUGIN_DIR}/wp-assets"

# SVN credentials
SVN_USERNAME="1platform"

# --- Colors -------------------------------------------------------------------

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# --- Helper Functions ---------------------------------------------------------

info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()   { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# --- Parse Arguments ----------------------------------------------------------

DRY_RUN=false
ASSETS_ONLY=false
VERSION=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)  DRY_RUN=true; shift ;;
        --assets)   ASSETS_ONLY=true; shift ;;
        *)          VERSION="$1"; shift ;;
    esac
done

# --- Extract Version from Plugin Header --------------------------------------

if [[ -z "$VERSION" ]]; then
    VERSION=$(grep -i "Version:" "${PLUGIN_DIR}/${PLUGIN_FILE}" | head -1 | sed 's/.*Version:\s*//i' | tr -d '[:space:]')
fi

if [[ -z "$VERSION" ]]; then
    error "Could not determine plugin version. Pass it as argument: ./deploy.sh 2.3.3"
fi

# --- Validate Version Matches ------------------------------------------------

HEADER_VERSION=$(grep -i "Version:" "${PLUGIN_DIR}/${PLUGIN_FILE}" | head -1 | sed 's/.*Version:\s*//i' | tr -d '[:space:]')
README_VERSION=$(grep -i "Stable tag:" "${PLUGIN_DIR}/readme.txt" | head -1 | sed 's/.*Stable tag:\s*//i' | tr -d '[:space:]')

if [[ "$HEADER_VERSION" != "$README_VERSION" ]]; then
    error "Version mismatch! Plugin header says ${HEADER_VERSION}, readme.txt Stable tag says ${README_VERSION}. Fix before deploying."
fi

if [[ "$VERSION" != "$HEADER_VERSION" ]]; then
    error "Requested version ${VERSION} doesn't match plugin header version ${HEADER_VERSION}."
fi

info "Deploying ${PLUGIN_SLUG} v${VERSION}"

# --- Build Exclude List from .distignore --------------------------------------

build_exclude_args() {
    local excludes=""
    if [[ -f "${PLUGIN_DIR}/.distignore" ]]; then
        while IFS= read -r line; do
            # Skip empty lines and comments
            [[ -z "$line" || "$line" =~ ^# ]] && continue
            excludes+="--exclude=${line} "
        done < "${PLUGIN_DIR}/.distignore"
    fi
    # Always exclude these
    excludes+="--exclude=.git "
    excludes+="--exclude=.svn "
    excludes+="--exclude=deploy.sh "
    excludes+="--exclude=wp-assets "
    excludes+="--exclude=.phpunit.result.cache "
    excludes+="--exclude=composer.phar "
    excludes+="--exclude=skills-lock.json "
    excludes+="--exclude=CHANGELOG.md "
    echo "$excludes"
}

# --- SVN Checkout / Update ----------------------------------------------------

if [[ -d "$SVN_DIR" ]]; then
    info "Updating existing SVN checkout..."
    svn up "$SVN_DIR" --quiet
else
    info "Checking out SVN repository (this may take a moment)..."
    svn co "$SVN_URL" "$SVN_DIR" --quiet
fi

success "SVN working copy ready at ${SVN_DIR}"

# --- Assets Only Mode ---------------------------------------------------------

if $ASSETS_ONLY; then
    info "Updating SVN assets only..."

    if [[ ! -d "$ASSETS_DIR" ]]; then
        error "Assets directory not found: ${ASSETS_DIR}"
    fi

    # Ensure assets directory exists in SVN
    mkdir -p "${SVN_DIR}/assets"

    # Sync assets
    rsync -rc "${ASSETS_DIR}/" "${SVN_DIR}/assets/" --delete

    # Add new files and remove deleted ones
    cd "$SVN_DIR"
    svn add assets/* --force 2>/dev/null || true
    svn status assets | grep '^\!' | awk '{print $2}' | xargs -I{} svn rm {} 2>/dev/null || true

    if $DRY_RUN; then
        info "[DRY RUN] Would commit these asset changes:"
        svn status assets
        exit 0
    fi

    svn ci assets -m "Update assets for ${PLUGIN_SLUG}" --username "$SVN_USERNAME"
    success "Assets updated!"
    exit 0
fi

# --- Check if Tag Already Exists ----------------------------------------------

if [[ -d "${SVN_DIR}/tags/${VERSION}" ]]; then
    error "Tag ${VERSION} already exists in SVN. Bump the version before deploying."
fi

# --- Sync Plugin Files to trunk/ ---------------------------------------------

info "Syncing plugin files to trunk/..."

EXCLUDE_ARGS=$(build_exclude_args)

# shellcheck disable=SC2086
rsync -rc --delete ${EXCLUDE_ARGS} "${PLUGIN_DIR}/" "${SVN_DIR}/trunk/"

success "Files synced to trunk/"

# --- Sync Assets to assets/ --------------------------------------------------

if [[ -d "$ASSETS_DIR" ]]; then
    info "Syncing SVN assets (banners, icons, screenshots)..."
    mkdir -p "${SVN_DIR}/assets"
    rsync -rc "${ASSETS_DIR}/" "${SVN_DIR}/assets/" --delete
    success "Assets synced"
else
    warn "No wp-assets/ directory found. Skipping assets sync."
    warn "Create wp-assets/ with banner-772x250.png, icon-256x256.png, etc."
fi

# --- SVN Add/Remove -----------------------------------------------------------

info "Registering file changes with SVN..."

cd "$SVN_DIR"

# Add new files (force to handle already-versioned files gracefully)
svn add trunk/* --force 2>/dev/null || true
svn add assets/* --force 2>/dev/null || true

# Remove deleted files
svn status | grep '^\!' | awk '{print $2}' | xargs -I{} svn rm {} 2>/dev/null || true

# Show status
echo ""
info "SVN status:"
svn status
echo ""

# --- Dry Run Stop -------------------------------------------------------------

if $DRY_RUN; then
    info "[DRY RUN] Would commit trunk and create tag ${VERSION}"
    info "[DRY RUN] No changes were made to the SVN repository."
    exit 0
fi

# --- Confirm ------------------------------------------------------------------

echo -e "${YELLOW}Ready to deploy v${VERSION} to WordPress.org.${NC}"
echo -e "This will:"
echo -e "  1. Commit trunk/"
echo -e "  2. Create tag ${VERSION}/"
echo -e "  3. Commit the tag"
echo ""
read -rp "Continue? (y/N) " confirm

if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
    info "Deployment cancelled."
    exit 0
fi

# --- Commit trunk/ ------------------------------------------------------------

info "Committing trunk/..."
svn ci trunk -m "Update trunk to v${VERSION}" --username "$SVN_USERNAME"
success "trunk/ committed"

# --- Create Tag ---------------------------------------------------------------

info "Creating tag ${VERSION}..."
svn cp trunk "tags/${VERSION}"
svn ci "tags/${VERSION}" -m "Tagging version ${VERSION}" --username "$SVN_USERNAME"
success "Tag ${VERSION} created and committed"

# --- Commit Assets (if any) ---------------------------------------------------

if [[ -d "${SVN_DIR}/assets" ]]; then
    ASSET_CHANGES=$(svn status assets 2>/dev/null | head -1)
    if [[ -n "$ASSET_CHANGES" ]]; then
        info "Committing assets/..."
        svn ci assets -m "Update assets for v${VERSION}" --username "$SVN_USERNAME"
        success "Assets committed"
    fi
fi

# --- Done! --------------------------------------------------------------------

echo ""
echo -e "${GREEN}=============================================${NC}"
echo -e "${GREEN}  ${PLUGIN_SLUG} v${VERSION} deployed!  ${NC}"
echo -e "${GREEN}=============================================${NC}"
echo ""
echo -e "  Plugin page: https://wordpress.org/plugins/${PLUGIN_SLUG}/"
echo -e "  SVN repo:    ${SVN_URL}"
echo ""
echo -e "${YELLOW}Note: It may take a few minutes for the update to appear on WordPress.org.${NC}"
