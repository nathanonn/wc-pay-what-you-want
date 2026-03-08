#!/bin/bash
#
# Build script for WC Pay What You Want WordPress Plugin
# Creates a distributable zip file for WordPress installation
#
# Prerequisites: wp-env must be running (npm start)
#

set -e

# Configuration
PLUGIN_SLUG="wc-pay-what-you-want"
PLUGIN_VERSION=$(sed -n 's/^.*Version:[[:space:]]*\([0-9.]*\).*/\1/p' "wc-pay-what-you-want.php" 2>/dev/null | head -1)
PLUGIN_VERSION=${PLUGIN_VERSION:-1.0.0}
BUILD_DIR="build"
DIST_DIR="dist"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Building ${PLUGIN_SLUG} v${PLUGIN_VERSION}${NC}"
echo "======================================"

# Get the script directory (plugin root)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Clean previous builds
echo -e "${YELLOW}Cleaning previous builds...${NC}"
rm -rf "$BUILD_DIR"
rm -rf "$DIST_DIR"

# Create build directories
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG"
mkdir -p "$DIST_DIR"

# Copy plugin files
echo -e "${YELLOW}Copying plugin files...${NC}"

# Copy root-level PHP files (main plugin file + uninstall.php)
for php_file in *.php; do
    [ -f "$php_file" ] && cp "$php_file" "$BUILD_DIR/$PLUGIN_SLUG/"
done

# Copy plugin directories
for dir in inc assets templates languages; do
    if [ -d "$dir" ]; then
        cp -r "$dir" "$BUILD_DIR/$PLUGIN_SLUG/"
    fi
done

# Copy readme.txt if exists (WordPress.org plugin repository format)
if [ -f "readme.txt" ]; then
    cp readme.txt "$BUILD_DIR/$PLUGIN_SLUG/"
fi

# Copy README.md if exists
if [ -f "README.md" ]; then
    cp README.md "$BUILD_DIR/$PLUGIN_SLUG/"
fi

# Install production dependencies
if [ -f "composer.json" ]; then
    echo -e "${YELLOW}Installing production dependencies...${NC}"
    cp composer.json "$BUILD_DIR/$PLUGIN_SLUG/"
    [ -f "composer.lock" ] && cp composer.lock "$BUILD_DIR/$PLUGIN_SLUG/"

    if command -v composer &> /dev/null; then
        # Local composer available — run directly against the build copy
        composer install --no-dev --optimize-autoloader --no-interaction \
            --working-dir="$BUILD_DIR/$PLUGIN_SLUG"
    else
        echo -e "${YELLOW}Local composer not found, using wp-env...${NC}"

        # Ensure wp-env is running (check port 8603)
        if ! curl -s --max-time 3 "http://localhost:8603" > /dev/null 2>&1; then
            echo -e "${YELLOW}wp-env not running, starting it...${NC}"

            # Ensure wp-env package is installed
            if [ ! -f "./node_modules/.bin/wp-env" ]; then
                echo -e "${YELLOW}wp-env not found, running npm install...${NC}"
                npm install
            fi

            npx wp-env start
        fi

        # The plugin root is mounted in the container using the host directory name.
        # Build the equivalent container path for our build copy of the plugin.
        CONTAINER_PLUGIN_DIR="/var/www/html/wp-content/plugins/$(basename "$SCRIPT_DIR")"
        CONTAINER_BUILD_DIR="${CONTAINER_PLUGIN_DIR}/${BUILD_DIR}/${PLUGIN_SLUG}"

        if ! npx wp-env run cli composer install --no-dev --optimize-autoloader --no-interaction -d "$CONTAINER_BUILD_DIR"; then
            echo -e "${RED}Error: Failed to run composer via wp-env.${NC}"
            exit 1
        fi
    fi

    # Remove composer files not needed in distribution
    rm -f "$BUILD_DIR/$PLUGIN_SLUG/composer.json" "$BUILD_DIR/$PLUGIN_SLUG/composer.lock"
fi

# Create the zip file
echo -e "${YELLOW}Creating zip archive...${NC}"
cd "$BUILD_DIR"
zip -rq "../$DIST_DIR/${PLUGIN_SLUG}-${PLUGIN_VERSION}.zip" "$PLUGIN_SLUG"
cd "$SCRIPT_DIR"

# Also create a latest version for convenience
cp "$DIST_DIR/${PLUGIN_SLUG}-${PLUGIN_VERSION}.zip" "$DIST_DIR/${PLUGIN_SLUG}-latest.zip"

# Cleanup build directory
echo -e "${YELLOW}Cleaning up...${NC}"
rm -rf "$BUILD_DIR"

# Output results
echo ""
echo -e "${GREEN}Build complete!${NC}"
echo "======================================"
echo -e "Output files:"
echo -e "  ${GREEN}$DIST_DIR/${PLUGIN_SLUG}-${PLUGIN_VERSION}.zip${NC}"
echo -e "  ${GREEN}$DIST_DIR/${PLUGIN_SLUG}-latest.zip${NC}"
echo ""
echo -e "File size: $(du -h "$DIST_DIR/${PLUGIN_SLUG}-${PLUGIN_VERSION}.zip" | cut -f1)"
