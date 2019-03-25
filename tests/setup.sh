#!/usr/bin/env bash
# php-ast installation and configuration script, taken from https://github.com/phan/phan
# NOTE: You may want to replace all of this with `pecl install ast` or `pecl install ast-0.1.7`
# in your own scripts to ensure stable versions are installed instead of development versions.
set -xeu

if [[ "x$TRAVIS" == "x" ]]; then
    echo "This should only be run in travis"
    exit 1
fi

# Ensure the build directory exist
PHP_VERSION_ID=$(php -r "echo PHP_VERSION_ID;")
PHAN_BUILD_DIR="$HOME/.cache/phan-ast-build"
EXPECTED_AST_FILE="$PHAN_BUILD_DIR/build/php-ast-$PHP_VERSION_ID.so"

[[ -d "$PHAN_BUILD_DIR" ]] || mkdir -p "$PHAN_BUILD_DIR"

cd "$PHAN_BUILD_DIR"

if [[ ! -e "$EXPECTED_AST_FILE" ]]; then
  echo "No cached extension found. Building..."
  rm -rf php-ast build
  mkdir build

  git clone --depth 1 https://github.com/nikic/php-ast.git php-ast

  export CFLAGS="-O3"
  pushd php-ast
  # Install the ast extension
  phpize
  ./configure
  make

  cp modules/ast.so "$EXPECTED_AST_FILE"
  popd
else
  echo "Using cached extension."
fi

echo "extension=$EXPECTED_AST_FILE" >> ~/.phpenv/versions/$(phpenv version-name)/etc/php.ini

php -r 'function_exists("ast\parse_code") || (print("Failed to enable php-ast\n") && exit(1));'

# Disable xdebug, since we aren't currently gathering code coverage data and
# having xdebug slows down Composer a bit.
# TODO(optional): Once xdebug is enabled for PHP 7.3 on Travis, get rid of the '|| true'
phpenv config-rm xdebug.ini || true
