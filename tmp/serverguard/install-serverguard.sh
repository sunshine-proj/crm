#!/usr/bin/env bash

set -Eeuo pipefail

# =====================================================
# ServerGuard - Debian Security Toolkit Installer
# Target: Debian 13+ (Trixie and newer)
# =====================================================

VERSION="1.0.0"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# =====================================================
# Check root privileges
# =====================================================

if [[ "$EUID" -ne 0 ]]; then
    error "Run this script as root or using sudo."
    exit 1
fi

# =====================================================
# Check operating system
# =====================================================

if [[ ! -f /etc/os-release ]]; then
    error "Cannot identify operating system."
    exit 1
fi

source /etc/os-release

if [[ "$ID" != "debian" ]]; then
    error "This installer supports Debian only."
    exit 1
fi

if ! dpkg --compare-versions "$VERSION_ID" ge "13"; then
    error "Debian 13 or newer is required."
    exit 1
fi

log "ServerGuard Installer v${VERSION}"
log "Detected: Debian ${VERSION_ID} (${VERSION_CODENAME})"

# =====================================================
# Update package repositories
# =====================================================

log "Updating package repositories..."

apt-get update

# =====================================================
# Security toolkit packages
# =====================================================

PACKAGES=(
    # System auditing
    lynis
    debsecan
    needrestart
    debian-security-support

    # Network auditing
    nmap
    tcpdump
    netcat-openbsd
    dnsutils
    whois
    iproute2

    # Web security
    nikto
    zaproxy

    # TLS security
    testssl.sh

    # Security compliance
    openscap-scanner
    openscap-utils

    # Rootkit detection
    rkhunter
    chkrootkit

    # File integrity
    aide

    # Malware detection
    clamav
    clamav-freshclam

    # System auditing
    auditd

    # Firewall tools
    ufw
    nftables

    # Automatic security updates
    unattended-upgrades

    # General utilities
    curl
    wget
    jq
    git
    lsof
    procps
    ca-certificates
    gnupg

    # Development utilities
    python3
    python3-pip
    python3-venv
    shellcheck
    yamllint

    # Network packet analysis
    wireshark
    tshark
    tcpdump
    ngrep

    # Proxy servers
    mitmproxy
    squid

    # Network monitoring
    suricata
    suricata-update
    zeek

    # Network connection tracking
    conntrack

    # Container security
    trivy

    # Network troubleshooting
    traceroute
    mtr-tiny
    ethtool

)

# =====================================================
# Check package availability
# =====================================================

AVAILABLE_PACKAGES=()
MISSING_PACKAGES=()

log "Checking package availability..."

for package in "${PACKAGES[@]}"; do

    candidate=$(
        apt-cache policy "$package" |
        awk '/Candidate:/ {print $2; exit}'
    )

    if [[ -n "$candidate" && "$candidate" != "(none)" ]]; then
        AVAILABLE_PACKAGES+=("$package")
    else
        MISSING_PACKAGES+=("$package")
        warn "Package unavailable: $package"
    fi

done

# =====================================================
# Install packages
# =====================================================

log "Installing ${#AVAILABLE_PACKAGES[@]} packages..."

export DEBIAN_FRONTEND=noninteractive

apt-get install -y \
    --no-install-recommends \
    "${AVAILABLE_PACKAGES[@]}"

unset DEBIAN_FRONTEND

# =====================================================
# Create ServerGuard directories
# =====================================================

log "Creating ServerGuard directories..."

mkdir -p /opt/serverguard
mkdir -p /var/log/serverguard
mkdir -p /etc/serverguard

chmod 750 /opt/serverguard
chmod 750 /var/log/serverguard
chmod 750 /etc/serverguard

# =====================================================
# Save installation information
# =====================================================

cat > /etc/serverguard/version.conf <<EOF
SERVERGUARD_VERSION=${VERSION}
INSTALL_DATE=$(date -Iseconds)
DEBIAN_VERSION=${VERSION_ID}
DEBIAN_CODENAME=${VERSION_CODENAME}
EOF

# =====================================================
# Installation summary
# =====================================================

echo
echo "================================================="
echo " SERVERGUARD INSTALLATION COMPLETED"
echo "================================================="

echo
log "Installed packages: ${#AVAILABLE_PACKAGES[@]}"

for package in "${AVAILABLE_PACKAGES[@]}"; do
    echo "  [OK] $package"
done

if [[ ${#MISSING_PACKAGES[@]} -gt 0 ]]; then
    echo
    warn "Unavailable packages:"
    for package in "${MISSING_PACKAGES[@]}"; do
        echo "  [SKIP] $package"
    done
fi

echo
log "Installation information: /etc/serverguard/"
log "Reports directory: /var/log/serverguard/"
log "Toolkit directory: /opt/serverguard/"

echo
echo "================================================="
echo " NEXT STEPS"
echo "================================================="

echo
echo "1. Check system security:"
echo "   lynis audit system"

echo
echo "2. Check installed package vulnerabilities:"
echo "   debsecan"

echo
echo "3. Check listening network services:"
echo "   ss -tulpn"

echo
echo "4. Check TLS configuration:"
echo "   testssl.sh --help"

echo
echo "5. Check system security compliance:"
echo "   oscap --version"

echo
echo "6. Check rootkits:"
echo "   rkhunter --check"

echo
echo "Remember: Only scan systems you own or are"
echo "explicitly authorized to assess."

echo
log "ServerGuard is ready!"



