# Fresh Debian/Ubuntu server with systemd for the Ansible ISPConfig roles (tests/Integration/ansible/run.sh). Docker
# images forbid packages to start services (policy-rc.d); a server does not.
ARG BASE=ubuntu:24.04
FROM ${BASE}
ENV container=docker DEBIAN_FRONTEND=noninteractive
RUN apt-get update -qq \
 && apt-get install -y -qq --no-install-recommends systemd systemd-sysv dbus python3 sudo ca-certificates curl \
    iproute2 procps >/dev/null \
 && apt-get clean && rm -rf /var/lib/apt/lists/* \
 && rm -f /usr/sbin/policy-rc.d \
 && (systemctl mask getty.target console-getty.service systemd-logind.service systemd-udevd.service \
    systemd-resolved.service >/dev/null 2>&1 || true)
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
