# ServerGuard

ServerGuard is a Debian-focused security toolkit installer and auditing framework for system hardening, compliance checks, and baseline security review.

## Overview

The project packages a set of common Linux security tools for Debian 13+ and automates installation of a security-focused environment. It is designed to help administrators quickly deploy auditing and monitoring tools for system review, network inspection, and vulnerability assessment.

## Features

ServerGuard includes tools for:

- System auditing and hardening
- Package vulnerability checks
- Firewall and network inspection
- Web and TLS security testing
- Rootkit and file integrity monitoring
- Malware detection and compliance scanning
- Proxy and packet-analysis workflows

## Included tools

| Category | Tools |
| --- | --- |
| System auditing | Lynis, debsecan, auditd |
| Network scanning | Nmap, tcpdump, netcat, dnsutils, whois |
| Web security | Nikto |
| TLS auditing | testssl.sh |
| Security compliance | OpenSCAP |
| Rootkit detection | rkhunter, chkrootkit |
| File integrity | AIDE |
| Malware detection | ClamAV |
| Firewall management | UFW, nftables |
| Development and utilities | Python 3, Git, ShellCheck, jq, curl, wget, lsof |
| Network monitoring | Wireshark, TShark, Suricata, Zeek, conntrack |
| Proxy services | mitmproxy, Squid |

## Requirements

- Debian 13 or newer
- Root privileges (`sudo` or direct root access)
- A clean or controlled Debian environment for security testing

## Installation

Run the installer from the project directory:

```bash
chmod +x install-serverguard.sh
sudo ./install-serverguard.sh
```

## Initial security audit

After installation, begin with the following checks:

### System audit

```bash
sudo lynis audit system
```

### Installed package vulnerability assessment

```bash
sudo debsecan
```

### Check listening services

```bash
sudo ss -tulpn
```

### Rootkit checks

```bash
sudo rkhunter --check
```

### Initialize file integrity monitoring

```bash
sudo aideinit
```

> Review the AIDE configuration before establishing the initial baseline.

## Planned architecture

The installer is the first step. The next phase would organize the project into a modular auditing framework:

```text
serverguard/
├── install-serverguard.sh
├── audits/
│   ├── system-audit.sh
│   ├── network-audit.sh
│   ├── web-audit.sh
│   ├── tls-audit.sh
│   ├── docker-audit.sh
│   └── database-audit.sh
├── config/
│   └── audit-policy.yaml
├── reports/
├── serverguard.sh
└── README.md
```

## Future command model

A future command could look like this:

```bash
sudo ./serverguard.sh audit --full
```

This could generate structured reports, including summarized findings such as:

```text
SERVERGUARD SECURITY REPORT

Target: server01
OS: Debian 13

SYSTEM SECURITY       82/100
NETWORK SECURITY      75/100
WEB SECURITY          91/100
TLS SECURITY          88/100
DOCKER SECURITY       79/100
DATABASE SECURITY     95/100

OVERALL SCORE:        85/100

CRITICAL: 0
HIGH:     2
MEDIUM:   5
LOW:      8
```

These scores should be derived from documented checks and weighted findings, not only from raw scanner output.

## Network analysis and proxy module

ServerGuard can be extended with a dedicated network analysis and proxy capability.

### Design goals

- Proxy server: route authorized client traffic through Debian so HTTP and HTTPS requests can be inspected.
- Packet analysis: capture network traffic and review protocols, DNS, TLS handshakes, and connection metadata with Wireshark and related tools.

These capabilities complement each other but serve different purposes.

### Recommended software

| Software | Purpose | Recommendation |
| --- | --- | --- |
| Wireshark | Graphical packet analysis | Essential |
| TShark | CLI packet analysis | Essential |
| tcpdump | Packet capture | Essential |
| mitmproxy | HTTP/HTTPS inspection proxy | Essential |
| Squid | Traditional HTTP proxy server | Optional |
| Zeek | Network traffic analysis and logs | Recommended |
| Suricata | Network intrusion detection | Recommended |
| ngrep | Traffic pattern inspection | Optional |
| termshark | Terminal-based Wireshark interface | Optional |
| conntrack | Connection tracking | Recommended |

### Important distinctions

- Wireshark and TShark analyze captured packets from network interfaces.
- mitmproxy inspects HTTP and HTTPS traffic from explicitly trusted test clients.
- Squid provides a conventional forward proxy with access controls and logging.
- Zeek generates structured network activity logs.
- Suricata detects suspicious activity using IDS rules.

> For HTTPS inspection, use explicitly configured test clients and a dedicated test CA. Do not intercept unrelated or private traffic.

### Proxy software

#### mitmproxy

`mitmproxy` is the first choice for security testing. It can:

- Inspect HTTP and HTTPS requests and responses
- Inspect WebSocket traffic
- Export captured traffic
- Modify requests in controlled environments
- Provide a web interface through `mitmweb`

#### Squid

Squid is useful for:

- Forward proxy services
- Access control
- Proxy authentication
- Traffic logging
- Caching
- Client access management

Use both tools, but keep their configurations and listening interfaces separate.

### Wireshark and packet analysis

Install and use:

- Wireshark for graphical analysis
- TShark for terminal-based analysis
- tcpdump for packet capture
- termshark as an optional terminal interface

If Debian is installed without a desktop environment, TShark and tcpdump are usually enough for capture work. PCAP files can be copied to a workstation and opened in Wireshark.

Also note that traffic captured on a switched interface typically shows only traffic reaching that interface. For broader visibility, use a switch mirror port (SPAN) or a network TAP.

### Additional network monitoring

Additional monitoring tools include:

- Suricata for suspicious network activity detection
- Zeek for detailed network activity logging

Suricata is well-supported in Debian and works well as part of a broader detection workflow.

## Recommendation

The recommended path is to first validate the installer on a clean Debian 13 VM, then expand the project into modular audit scripts and reporting capabilities. The long-term goal is to evolve ServerGuard into a broader Debian-based security and auditing platform.



















