#!/bin/bash
set -eo pipefail

# cspell:ignore getconf NPROCESSORS ONLN PSQL PGPASSWORD

# Function to set variable only if not already set
set_if_unset() {
    local var_name="$1"
    local var_value="$2"
    if [[ -z "${!var_name:-}" ]]; then
        export "$var_name"="$var_value"
    fi
}

# Function to load env file
load_env_file() {
    local file="$1"
    if [[ ! -f "$file" ]]; then
        return 0
    fi

    while IFS='=' read -r key value || [[ -n "$key" ]]; do
        # Skip comments and empty lines.
        if [[ "$key" =~ ^[[:space:]]*# ]] || [[ -z "$key" ]]; then
            continue
        fi

        key="${key#"${key%%[^[:space:]]*}"}"
        key="${key%"${key##*[^[:space:]]}"}"
        value="${value#"${value%%[^[:space:]]*}"}"
        value="${value%"${value##*[^[:space:]]}"}"

        # Strip matching surrounding quotes.
        if [[ "$value" == \'*\' ]] || [[ "$value" == \"*\" ]]; then
            value="${value:1:${#value}-2}"
        fi

        if [[ -n "$key" ]]; then
            set_if_unset "$key" "$value"
        fi
    done < "$file"
}

# Handle --help.
for arg in "$@"; do
  if [[ "$arg" == "--help" || "$arg" == "-h" ]]; then
    cat <<'EOF'
Usage: composer run phpunit [-- [OPTIONS]]

Runs all tests in the module via Drupal core's run-tests.sh (parallel).
Single .php files are run directly via phpunit.

Examples:
  composer run phpunit
  composer run phpunit -- --types PHPUnit-Unit
  composer run phpunit -- modules/canvas/oauth
  composer run phpunit -- tests/src/Kernel/PropExpressionKernelTest.php
  composer run phpunit -- tests/src/Kernel/PropExpressionKernelTest.php --filter testLabel

Options passed through to run-tests.sh (parallel mode):
  --types <type>    Limit to a test type. Values:
                      PHPUnit-Unit
                      PHPUnit-Kernel
                      PHPUnit-Functional
  <directory>       Run tests in a specific directory (parallel via run-tests.sh)

Options passed through to phpunit directly (single-file mode):
  <file.php>        Run a single test file
  --filter <name>   Run a single test method (requires a .php file argument)

Environment:
  Copy .env.defaults to .env and edit to override environment variables
  such as SIMPLETEST_BASE_URL, SIMPLETEST_DB, DRUPAL_TEST_CONCURRENCY.

With DDEV:
  Outside the ddev environment, simply add `ddev` in front of `composer run phpunit`
EOF
    exit 0
  fi
done

# Find the module root (where this script lives: MODULE_ROOT/tests/scripts/phpunit.sh).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_ROOT="$(realpath "$SCRIPT_DIR/../..")"

# Load defaults, then overrides from the module root.
set -a
load_env_file "$MODULE_ROOT/.env"
load_env_file "$MODULE_ROOT/.env.defaults"
set +a

# Ensure test results can be written.

# Find the Drupal project root (where vendor/ lives) and web root (where core/ lives).
# Case 1: Running from the included .ddev config - module is symlinked but composer root
#         is at /var/www/html, MODULE_ROOT resolves to /var/www/html, and web root is
#         /var/www/html/web.
# Case 2: Running from a 3rd party project - module is at web/modules/contrib/canvas,
#         vendor is 4 levels up, and web root is 3 levels up.
if [[ -d "$MODULE_ROOT/vendor" ]]; then
  # Case 1: Module root IS the composer root (included .ddev config)
  DRUPAL_PROJECT_ROOT="$MODULE_ROOT"
  DRUPAL_WEB_ROOT="$MODULE_ROOT/web"
  # Use the symlinked module path to avoid scanning vendor/
  MODULE_TEST_DIR="$DRUPAL_WEB_ROOT/modules/contrib/canvas"
elif [[ -d "$(realpath "$MODULE_ROOT/../../../..")/vendor" ]]; then
  # Case 2: Module is at web/modules/contrib/canvas, vendor is 4 levels up
  DRUPAL_PROJECT_ROOT=$(realpath "$MODULE_ROOT/../../../..")
  DRUPAL_WEB_ROOT=$(realpath "$MODULE_ROOT/../../..")
else
  echo "Error: Could not find Drupal project root (vendor/ directory not found)"
  echo "  Searched: $MODULE_ROOT/vendor"
  echo "  Searched: $(realpath "$MODULE_ROOT/../../../..")/vendor"
  exit 1
fi
mkdir -p "$MODULE_ROOT/test-results"
if [ -n "$DRUPAL_TEST_WEBSERVER_USER" ]; then
  sudo chown "$DRUPAL_TEST_WEBSERVER_USER" "$MODULE_ROOT/test-results"
fi

# Parse args: .php files run via phpunit directly; directories become --directory
# for run-tests.sh (parallel); everything else passes through.
PHPUNIT_DIRECT_FILE=""
DIRECTORY="${MODULE_TEST_DIR:-$MODULE_ROOT}"
PASSTHROUGH_ARGS=()
INVOCATION_DIR="$(pwd)"
for arg in "$@"; do
  if [[ "$arg" == *.php ]]; then
    [[ "$arg" != /* ]] && arg="$INVOCATION_DIR/$arg"
    PHPUNIT_DIRECT_FILE="$arg"
  elif [[ -d "$arg" ]]; then
    [[ "$arg" != /* ]] && arg="$INVOCATION_DIR/$arg"
    DIRECTORY="$arg"
  else
    PASSTHROUGH_ARGS+=("$arg")
  fi
done

if [ -n "$PHPUNIT_DIRECT_FILE" ]; then
  # phpunit.xml.dist declares bootstrap="../../../core/tests/bootstrap.php",
  # which is relative to the config file. That resolves correctly only when the
  # config sits at web/modules/contrib/canvas (the 3rd-party Case 2 depth). In
  # the included .ddev layout (Case 1) the composer/config root is /var/www/html,
  # three levels too shallow, so the relative path escapes the project. Pass the
  # absolute core bootstrap explicitly; $DRUPAL_WEB_ROOT/core is correct in both
  # cases, and the CLI flag overrides the XML value.
  BOOTSTRAP="$DRUPAL_WEB_ROOT/core/tests/bootstrap.php"
  echo php "$DRUPAL_PROJECT_ROOT/vendor/bin/phpunit" --configuration "$MODULE_ROOT" --bootstrap "$BOOTSTRAP" "$PHPUNIT_DIRECT_FILE" "${PASSTHROUGH_ARGS[@]}"
  exec php "$DRUPAL_PROJECT_ROOT/vendor/bin/phpunit" --configuration "$MODULE_ROOT" --bootstrap "$BOOTSTRAP" "$PHPUNIT_DIRECT_FILE" "${PASSTHROUGH_ARGS[@]}"
fi

DRUPAL_VERSION=$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$DRUPAL_WEB_ROOT/core/lib/Drupal.php")
DRUPAL_MINOR=$(echo "$DRUPAL_VERSION" | cut -d. -f1,2)

RUN_TESTS_ARGS=(
  php "$DRUPAL_WEB_ROOT/core/scripts/run-tests.sh"
  --php "$(command -v php)"
  --url "$SIMPLETEST_BASE_URL"
  --dburl "$SIMPLETEST_DB"
  --sqlite "/tmp/run-tests.sqlite"
  # @see https://stackoverflow.com/a/64571679
  --concurrency "${DRUPAL_TEST_CONCURRENCY:-$(getconf _NPROCESSORS_ONLN)}"
  --color
  --verbose
  --xml "$MODULE_ROOT/test-results"
  --directory "$DIRECTORY"
  "${PASSTHROUGH_ARGS[@]}"
)

# This can be removed when we stop supporting 11.2, --phpunit-configuration
# isn't an option until 11.3.
if [[ "$DRUPAL_MINOR" != "11.2" ]]; then
  RUN_TESTS_ARGS+=(--phpunit-configuration "$MODULE_ROOT")
fi

# If the database is PostgreSQL pre-create the pg_trgm extension in template1
# so that all test databases cloned from it inherit it automatically. Without
# this, each parallel test worker calls CREATE EXTENSION IF NOT EXISTS pg_trgm
# during Drupal's kernel test installer setup. The IF NOT EXISTS guard is not
# atomic in PostgreSQL, so two workers can both pass the existence check before
# either has committed, causing a unique constraint violation on
# pg_extension_name_index and a random test failure unrelated to the code
# under test.
if [[ "$SIMPLETEST_DB" == pgsql://* ]]; then
  DB_USER=$(echo "$SIMPLETEST_DB" | sed -n 's|pgsql://\([^:@]*\).*|\1|p')
  DB_PASS=$(echo "$SIMPLETEST_DB" | sed -n 's|pgsql://[^:@]*:\([^@]*\)@.*|\1|p')
  DB_HOST=$(echo "$SIMPLETEST_DB" | sed -n 's|pgsql://[^@]*@\([^:/]*\).*|\1|p')
  DB_PORT=$(echo "$SIMPLETEST_DB" | sed -n 's|pgsql://[^@]*@[^:]*:\([0-9]*\)/.*|\1|p')
  DB_NAME=$(echo "$SIMPLETEST_DB" | sed -n 's|pgsql://[^@]*@[^/]*/\([^?]*\).*|\1|p')

  PSQL_ARGS=(-U "$DB_USER" -h "${DB_HOST:-localhost}")
  [[ -n "$DB_PORT" ]] && PSQL_ARGS+=(-p "$DB_PORT")

  # Create in template1 so future databases inherit it, and also in the actual
  # test database since Drupal kernel tests share an existing database with table
  # prefixes rather than creating new databases. Without the latter, all parallel
  # workers race to run CREATE EXTENSION IF NOT EXISTS pg_trgm in the same
  # database simultaneously, hitting a PostgreSQL unique constraint violation.
  PGPASSWORD="$DB_PASS" psql "${PSQL_ARGS[@]}" -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;" template1
  PGPASSWORD="$DB_PASS" psql "${PSQL_ARGS[@]}" -d "$DB_NAME" -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
fi

echo "Running with ${DRUPAL_TEST_CONCURRENCY:-$(getconf _NPROCESSORS_ONLN)} parallel workers"

if [ -z "$DRUPAL_TEST_WEBSERVER_USER" ]; then
  echo "${RUN_TESTS_ARGS[@]}"
  "${RUN_TESTS_ARGS[@]}"
else
  sudo -E -u "$DRUPAL_TEST_WEBSERVER_USER" "${RUN_TESTS_ARGS[@]}"
fi
