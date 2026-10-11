#!/usr/bin/env sh
# shellcheck disable=SC2034
dns_ispconfig_rest_info='ISPConfig REST API (ispconfig-rest)
 DNS-01 challenges in zones hosted in ISPConfig, through ispconfig-rest 1.0.7 or newer.
Site: github.com/FELDSAM-INC/ispconfig-rest
Docs: github.com/FELDSAM-INC/ispconfig-rest/blob/main/acme-dns/README.md
Options:
 ISPC_REST_URL Address of ispconfig-rest, e.g. https://panel.example.com:8090
 ISPC_REST_KEY API key; create one limited to challenges: ispconfig-rest key:create NAME --acme
OptionsAlt:
 ISPC_REST_TIMEOUT Seconds to wait until ISPConfig has applied the record. Default "300".
'

########  Public functions #####################

# Usage: dns_ispconfig_rest_add _acme-challenge.www.example.com "XKrxpRBosdIKFzxW_CT3KLZNf6q0HG9i01zxXp5CPBs"
# Publishes the record and returns once the zone's DNS server has applied it.
dns_ispconfig_rest_add() {
  fulldomain=$1
  txtvalue=$2

  _ispconfig_rest_credentials || return 1
  _info "Publishing the challenge through ispconfig-rest"
  _debug fulldomain "$fulldomain"
  _debug txtvalue "$txtvalue"

  if ! _ispconfig_rest_request POST "/api/v1/dns/acme-challenges" "{\"name\":\"$fulldomain\",\"value\":\"$txtvalue\"}"; then
    return 1
  fi
  if [ "$_code" != "201" ] && [ "$_code" != "200" ]; then
    _err "ispconfig-rest refused the challenge (HTTP $_code): $(_ispconfig_rest_detail)"
    return 1
  fi

  _record="$(echo "$response" | _egrep_o '"id" *: *[0-9]+' | _egrep_o '[0-9]+$')"
  if [ -z "$_record" ]; then
    _err "Unexpected answer from ispconfig-rest: $response"
    return 1
  fi
  _debug "record id" "$_record"

  _timeout="${ISPC_REST_TIMEOUT:-300}"
  _waited=0
  while true; do
    _status="$(echo "$response" | _egrep_o '"status" *: *"[a-z]+"' | cut -d '"' -f 4)"
    _debug "record status" "$_status"
    case "$_status" in
    applied)
      _info "ISPConfig applied the record"
      return 0
      ;;
    failed | stalled)
      _err "ISPConfig did not apply the record (status $_status)"
      return 1
      ;;
    esac
    if [ "$_waited" -ge "$_timeout" ]; then
      _err "ISPConfig did not apply the record within $_timeout seconds"
      return 1
    fi
    _sleep 5
    _waited=$((_waited + 5))
    if ! _ispconfig_rest_request GET "/api/v1/dns/acme-challenges/$_record" || [ "$_code" != "200" ]; then
      _err "Could not read the record status (HTTP $_code)"
      return 1
    fi
  done
}

# Usage: dns_ispconfig_rest_rm _acme-challenge.www.example.com "XKrxpRBosdIKFzxW_CT3KLZNf6q0HG9i01zxXp5CPBs"
dns_ispconfig_rest_rm() {
  fulldomain=$1
  txtvalue=$2

  _ispconfig_rest_credentials || return 1
  _info "Removing the challenge through ispconfig-rest"
  _debug fulldomain "$fulldomain"

  # Names and values are DNS names and base64url: nothing to encode in the query.
  if ! _ispconfig_rest_request DELETE "/api/v1/dns/acme-challenges?name=$fulldomain&value=$txtvalue"; then
    return 1
  fi
  if [ "$_code" != "204" ]; then
    _err "ispconfig-rest could not remove the challenge (HTTP $_code): $(_ispconfig_rest_detail)"
    return 1
  fi
  return 0
}

####################  Private functions below ##################################

_ispconfig_rest_credentials() {
  ISPC_REST_URL="${ISPC_REST_URL:-$(_readaccountconf_mutable ISPC_REST_URL)}"
  ISPC_REST_KEY="${ISPC_REST_KEY:-$(_readaccountconf_mutable ISPC_REST_KEY)}"
  ISPC_REST_TIMEOUT="${ISPC_REST_TIMEOUT:-$(_readaccountconf_mutable ISPC_REST_TIMEOUT)}"

  if [ -z "$ISPC_REST_URL" ] || [ -z "$ISPC_REST_KEY" ]; then
    ISPC_REST_URL=""
    ISPC_REST_KEY=""
    _err "Set ISPC_REST_URL (e.g. https://panel.example.com:8090) and ISPC_REST_KEY (ispconfig-rest key:create NAME --acme)."
    return 1
  fi

  ISPC_REST_URL="$(echo "$ISPC_REST_URL" | sed 's#/*$##')"
  _saveaccountconf_mutable ISPC_REST_URL "$ISPC_REST_URL"
  _saveaccountconf_mutable ISPC_REST_KEY "$ISPC_REST_KEY"
  if [ -n "$ISPC_REST_TIMEOUT" ]; then
    _saveaccountconf_mutable ISPC_REST_TIMEOUT "$ISPC_REST_TIMEOUT"
  fi
  return 0
}

# _ispconfig_rest_request METHOD PATH [BODY] -> $response, $_code
_ispconfig_rest_request() {
  _method="$1"
  _path="$2"
  _body="$3"

  export _H1="X-API-Key: $ISPC_REST_KEY"
  export _H2="Accept: application/json"
  _debug "$_method" "$_path"

  if [ "$_method" = "GET" ]; then
    response="$(_get "$ISPC_REST_URL$_path")"
  else
    response="$(_post "$_body" "$ISPC_REST_URL$_path" "" "$_method" "application/json")"
  fi
  _ret="$?"
  _code="$(grep "^HTTP" "$HTTP_HEADER" | _tail_n 1 | cut -d " " -f 2 | tr -d "\\r\\n")"
  _debug "http response code" "$_code"
  _debug2 response "$response"
  if [ "$_ret" != "0" ] && [ -z "$_code" ]; then
    _err "Could not reach ispconfig-rest at $ISPC_REST_URL"
    return 1
  fi
  return 0
}

# The problem detail of an error response (RFC 9457).
_ispconfig_rest_detail() {
  _detail="$(echo "$response" | _egrep_o '"detail" *: *"[^"]*"' | cut -d '"' -f 4)"
  echo "${_detail:-$response}"
}
