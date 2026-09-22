#!/usr/bin/env bash
#
# Sets up the WordPress core PHPUnit test suite for running Certiva's tests.
# Standard WP-CLI plugin scaffold script.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress/}

download() {
	curl -s "$1" > "$2"
}

install_wp() {
	mkdir -p "$WP_CORE_DIR"

	if [ "$WP_VERSION" == "latest" ]; then
		local ARCHIVE_NAME="latest"
	else
		local ARCHIVE_NAME="wordpress-$WP_VERSION"
	fi

	download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "/tmp/wordpress.tar.gz"
	tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
}

install_test_suite() {
	local ABSPATH_SLASH
	ABSPATH_SLASH=$(echo "$WP_CORE_DIR" | sed 's:/*$:/:')

	mkdir -p "$WP_TESTS_DIR"

	if [ "$WP_VERSION" == "latest" ] || [[ "$WP_VERSION" =~ [0-9]+\.[0-9]+(\.[0-9]+)? ]]; then
		local SVN_TAG="trunk"
	fi

	svn export --quiet https://develop.svn.wordpress.org/${SVN_TAG:-trunk}/tests/phpunit/includes/ "$WP_TESTS_DIR"/includes
	svn export --quiet https://develop.svn.wordpress.org/${SVN_TAG:-trunk}/tests/phpunit/data/ "$WP_TESTS_DIR"/data

	download "https://develop.svn.wordpress.org/${SVN_TAG:-trunk}/wp-tests-config-sample.php" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s:dirname( __FILE__ ) . '/src/':'$ABSPATH_SLASH':" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
}

install_db() {
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true
}

install_wp
install_test_suite
install_db

echo "Done. Export WP_TESTS_DIR=$WP_TESTS_DIR before running phpunit."
