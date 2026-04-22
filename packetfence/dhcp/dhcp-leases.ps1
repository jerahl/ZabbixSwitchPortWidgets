<#
.SYNOPSIS
    Export Windows DHCP server leases as gzip+base64-encoded JSON for Zabbix.

.DESCRIPTION
    Walks every authorized scope on the local DHCP server, collects only the
    active, non-expired leases with real client MACs, then emits a compact
    JSON array compressed with gzip and base64-encoded.

    Each entry: {"mac":"aa:bb:cc:dd:ee:ff","ip":"10.x.y.z",
                 "hostname":"PC01","scope":"10.1.0.0",
                 "expires":"2026-04-20T14:02:11"}

    Output is a single line of base64 text on stdout. The widget receiving
    this via Zabbix decodes it with base64_decode() + gzdecode() to recover
    the JSON array.

    Empty base64-encoded [] on any error, so Zabbix polling never breaks.

.NOTES
    Deploy to: C:\zabbix\scripts\dhcp-leases.ps1
    Requires the DhcpServer PowerShell module (default on DHCP role).

    Test (decode to verify):
        $raw = powershell.exe -NoProfile -ExecutionPolicy Bypass `
            -File C:\zabbix\scripts\dhcp-leases.ps1
        $bytes = [Convert]::FromBase64String($raw)
        $ms = New-Object IO.MemoryStream(,$bytes)
        $gz = New-Object IO.Compression.GZipStream($ms, [IO.Compression.CompressionMode]::Decompress)
        $sr = New-Object IO.StreamReader($gz)
        $sr.ReadToEnd() | ConvertFrom-Json | Measure-Object

.OUTPUTS
    Single line of base64-encoded gzip-compressed JSON on stdout.
#>

$ErrorActionPreference = 'SilentlyContinue'

function Compress-Base64 {
    param([string]$Text)
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Text)
    $ms = New-Object System.IO.MemoryStream
    $gz = New-Object System.IO.Compression.GZipStream($ms,
        [System.IO.Compression.CompressionMode]::Compress)
    $gz.Write($bytes, 0, $bytes.Length)
    $gz.Close()
    [Convert]::ToBase64String($ms.ToArray())
}

try {
    Import-Module DhcpServer -ErrorAction Stop

    $now = Get-Date
    $leases = New-Object System.Collections.ArrayList
    $scopes = Get-DhcpServerv4Scope -ErrorAction Stop

    foreach ($scope in $scopes) {
        # Skip disabled scopes - no point reporting leases that aren't handed out
        if ($scope.State -ne 'Active') { continue }

        $scopeLeases = Get-DhcpServerv4Lease -ScopeId $scope.ScopeId `
                                              -AllLeases `
                                              -ErrorAction SilentlyContinue
        foreach ($lease in $scopeLeases) {
            # Must have a ClientId (MAC). Skips BOOTP/reservation placeholders.
            if (-not $lease.ClientId) { continue }

            # Must be in Active state (drop Declined, Expired, Released, etc.)
            if ($lease.AddressState -notlike 'Active*') { continue }

            # Expiry sanity check: drop leases whose lease time has passed but
            # haven't been purged yet. Reservations get through (expiry is $null).
            if ($lease.LeaseExpiryTime -and $lease.LeaseExpiryTime -lt $now) {
                continue
            }

            # Normalize: hyphen-separated lowercase to colon-separated lowercase
            $mac = ($lease.ClientId -replace '-', ':').ToLower()
            if ($mac -notmatch '^([0-9a-f]{2}:){5}[0-9a-f]{2}$') { continue }

            [void]$leases.Add([pscustomobject]@{
                mac      = $mac
                ip       = [string]$lease.IPAddress
                hostname = [string]$lease.HostName
                scope    = [string]$scope.ScopeId
                expires  = if ($lease.LeaseExpiryTime) {
                    $lease.LeaseExpiryTime.ToString('s')
                } else { $null }
            })
        }
    }

    $json = ConvertTo-Json -InputObject $leases -Compress -Depth 3
    if ([string]::IsNullOrEmpty($json)) { $json = '[]' }

    Compress-Base64 -Text $json
}
catch {
    Compress-Base64 -Text '[]'
}
