<#
.SYNOPSIS
    Export Windows DHCP server leases as JSON for Zabbix consumption.

.DESCRIPTION
    Walks every authorized scope on the local DHCP server, collects all active
    leases, and emits a compact JSON array suitable for the
    Switch Port Device (PacketFence) Zabbix widget to ingest.

    Each entry: {"mac": "aa:bb:cc:dd:ee:ff", "ip": "10.x.y.z",
                 "hostname": "PC01", "scope": "10.1.0.0",
                 "expires": "2026-04-20T14:02:11", "state": "Active"}

    Output is a single line of minified JSON on stdout. Empty array [] if
    no leases or errors (never emits Zabbix-unfriendly multi-line text).

.NOTES
    Deploy to: C:\zabbix\scripts\dhcp-leases.ps1
    Requires the DhcpServer PowerShell module (installed by default on
    DHCP Server role).

    Test:
        powershell.exe -NoProfile -ExecutionPolicy Bypass `
            -File C:\zabbix\scripts\dhcp-leases.ps1
#>

$ErrorActionPreference = 'SilentlyContinue'

try {
    Import-Module DhcpServer -ErrorAction Stop

    $leases = @()
    $scopes = Get-DhcpServerv4Scope -ErrorAction Stop

    foreach ($scope in $scopes) {
        $scopeLeases = Get-DhcpServerv4Lease -ScopeId $scope.ScopeId `
                                              -AllLeases `
                                              -ErrorAction SilentlyContinue
        foreach ($lease in $scopeLeases) {
            # Only include leases that have a real client MAC
            if (-not $lease.ClientId) { continue }

            # ClientId comes as hyphen-separated hex, normalize to lowercase
            # colon-separated: "aa-bb-cc-dd-ee-ff" -> "aa:bb:cc:dd:ee:ff"
            $mac = ($lease.ClientId -replace '-', ':').ToLower()

            # Some leases also contain BOOTP/reservations - keep only real client MACs
            if ($mac -notmatch '^([0-9a-f]{2}:){5}[0-9a-f]{2}$') { continue }

            $leases += [pscustomobject]@{
                mac      = $mac
                ip       = [string]$lease.IPAddress
                hostname = [string]$lease.HostName
                scope    = [string]$scope.ScopeId
                expires  = if ($lease.LeaseExpiryTime) {
                    $lease.LeaseExpiryTime.ToString('s')
                } else { $null }
                state    = [string]$lease.AddressState
            }
        }
    }

    # Emit single-line JSON
    ConvertTo-Json -InputObject $leases -Compress -Depth 3
}
catch {
    # Never break Zabbix polling - always emit valid JSON
    Write-Output '[]'
}
