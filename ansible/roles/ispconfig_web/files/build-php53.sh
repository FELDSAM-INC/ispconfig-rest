#!/bin/bash
# Builds PHP 5.3 (CLI and FPM; PHP 5.3 builds no CGI next to FPM) for old websites on a current Debian/Ubuntu web server. No distribution or sury
# package exists for it, and it needs OpenSSL 1.0.x: a private OpenSSL and libcurl are linked statically, so the
# binaries never load the system's OpenSSL 3. Sources are verified against pinned SHA-256 sums before use.
#
# Usage: build-php53.sh PREFIX CONFIG_DIR   (e.g. /opt/php-5.3 /etc/php/5.3), with PHP_URL, PHP_SHA256,
# OPENSSL_URL, OPENSSL_SHA256, CURL_URL and CURL_SHA256 set by the ispconfig_web role. The new build replaces PREFIX
# only after its checks pass, so a failed build never removes a working PHP.
set -euo pipefail

PREFIX=${1:?prefix}
CONFIG_DIR=${2:?config directory}
: "${PHP_URL:?}" "${PHP_SHA256:?}" "${OPENSSL_URL:?}" "${OPENSSL_SHA256:?}" "${CURL_URL:?}" "${CURL_SHA256:?}"
WORK=${WORK:-/usr/local/src/ispconfig-php53}
JOBS=${JOBS:-$(nproc)}
DEPS="$WORK/deps"
ROOT="$WORK/root"
STAGE="$ROOT$PREFIX"
MULTIARCH=$(dpkg-architecture -qDEB_HOST_MULTIARCH 2>/dev/null || gcc -dumpmachine)

fetch() { # URL SHA256 -> extracted source directory on stdout
    local file="$WORK/${1##*/}" dir
    if ! echo "$2  $file" | sha256sum --quiet -c - >/dev/null 2>&1; then
        curl -fsSL --retry 3 -o "$file.part" "$1"
        echo "$2  $file.part" | sha256sum --quiet -c - >/dev/null || { echo "Checksum mismatch: $1" >&2; exit 1; }
        mv "$file.part" "$file"
    fi
    dir=$(tar -tf "$file" | head -n 1 | cut -d/ -f1)
    rm -rf "${WORK:?}/$dir"
    tar -C "$WORK" -xf "$file"
    echo "$WORK/$dir"
}

run() { # LOG COMMAND... -> quiet unless it fails
    local log="$WORK/$1.log"
    shift
    "$@" >"$log" 2>&1 || { tail -n 40 "$log" >&2; echo "Failed: $*" >&2; exit 1; }
}

mkdir -p "$WORK"
chmod 0700 "$WORK"
rm -rf "$DEPS" "$ROOT"

# OpenSSL 1.0.2: static, position independent, without the broken protocols. Its parallel build is unreliable.
src=$(fetch "$OPENSSL_URL" "$OPENSSL_SHA256")
cd "$src"
run openssl-config ./config --prefix="$DEPS" --openssldir="$PREFIX/ssl" no-shared no-ssl2 no-ssl3 no-comp \
    no-weak-ssl-ciphers -fPIC
run openssl-depend make depend
run openssl-make make
run openssl-install make install_sw

# libcurl against that OpenSSL, static and limited to what PHP applications use.
src=$(fetch "$CURL_URL" "$CURL_SHA256")
cd "$src"
run curl-configure ./configure --prefix="$DEPS" --disable-shared --enable-static --with-pic \
    --with-openssl="$DEPS" --with-ca-bundle=/etc/ssl/certs/ca-certificates.crt --with-zlib --without-libpsl \
    --without-brotli --without-zstd --without-nghttp2 --without-libidn2 --without-librtmp --without-libssh2 \
    --disable-ldap --disable-ldaps --disable-manual --disable-docs
run curl-make make -j"$JOBS"
run curl-install make install
# PHP's --with-libdir applies to every prefix it searches, the private one included.
ln -sfn . "$DEPS/lib/$MULTIARCH"

src=$(fetch "$PHP_URL" "$PHP_SHA256")
cd "$src"
# zlib's CRC table holds 32-bit entries; the bundled libzip reads it as 64-bit ones (wrong PKWARE decryption).
sed -i 's/^static const uLongf \*crc = NULL;$/static const z_crc_t *crc = NULL;/' ext/zip/lib/zip_source_pkware.c
grep -q '^static const z_crc_t \*crc = NULL;$' ext/zip/lib/zip_source_pkware.c
mcrypt=()
if [ -e /usr/include/mcrypt.h ]; then
    mcrypt=(--with-mcrypt)
fi
# PHP 5.3 predates GCC 10 (-fno-common) and GCC 14 (implicit declarations are errors); gnu89 keeps its inline
# semantics. The static libcurl needs its OpenSSL and zlib on the link line.
export CFLAGS="-O2 -g0 -fPIC -fcommon -std=gnu89 -Wno-error=implicit-function-declaration -Wno-error=int-conversion \
-Wno-error=incompatible-pointer-types -Wno-error=implicit-int"
export CXXFLAGS="-O2 -g0 -fPIC"
export LDFLAGS="-L$DEPS/lib"
export LIBS="-lssl -lcrypto -lz -ldl -lpthread"
run php-configure ./configure --prefix="$PREFIX" --with-libdir="lib/$MULTIARCH" \
    --with-config-file-path="$CONFIG_DIR/cli" --with-config-file-scan-dir="$CONFIG_DIR/cli/conf.d" \
    --enable-fpm --with-fpm-user=www-data --with-fpm-group=www-data --disable-rpath --without-pear \
    --with-openssl="$DEPS" --with-curl="$DEPS" --with-zlib --with-bz2 --enable-zip --enable-mbstring \
    --with-gd --with-jpeg-dir=/usr --with-png-dir=/usr --with-freetype-dir=/usr --enable-gd-native-ttf \
    --with-mysql=mysqlnd --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd --with-pdo-sqlite --with-sqlite3 \
    --enable-soap --enable-sockets --enable-bcmath --enable-calendar --enable-exif --enable-ftp --with-gettext \
    --with-iconv --with-xsl --with-xmlrpc --with-mhash --enable-shmop --enable-sysvsem --enable-sysvshm \
    --enable-sysvmsg --enable-wddx "${mcrypt[@]}"
run php-make make -j"$JOBS"
run php-install make install INSTALL_ROOT="$ROOT"
install -m 0644 php.ini-production "$STAGE/lib/php.ini-production"
cd /

# OpenSSL looks for CA certificates below its openssldir.
mkdir -p "$STAGE/ssl"
ln -sfn /etc/ssl/certs "$STAGE/ssl/certs"
ln -sfn /etc/ssl/certs/ca-certificates.crt "$STAGE/ssl/cert.pem"

for binary in bin/php sbin/php-fpm; do
    "$STAGE/$binary" -n -v >/dev/null
    if ldd "$STAGE/$binary" | grep -Eq 'lib(ssl|crypto)\.so'; then
        echo "$binary loads a shared OpenSSL" >&2
        exit 1
    fi
done
modules=$("$STAGE/bin/php" -n -m)
for module in curl gd mbstring mysql mysqli mysqlnd openssl pdo_mysql soap zip; do
    grep -qx "$module" <<<"$modules" || { echo "PHP 5.3 was built without $module" >&2; exit 1; }
done

rm -rf "$PREFIX.new" "$PREFIX.old"
cp -a "$STAGE" "$PREFIX.new"
if [ -d "$PREFIX" ]; then
    mv "$PREFIX" "$PREFIX.old"
fi
mv "$PREFIX.new" "$PREFIX"
rm -rf "$PREFIX.old" "$DEPS" "$ROOT"
find "$WORK" -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} +
