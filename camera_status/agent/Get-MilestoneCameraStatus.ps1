# Get-MilestoneCameraStatus.ps1
# Called by Zabbix agent UserParameter on the Milestone Management Server.
# Queries XProtect Professional+ Configuration API + Recording Server for
# camera state, recording state, last frame timestamp, and retention.
#
# Usage (from Zabbix UserParameter):
#   powershell.exe -NoProfile -ExecutionPolicy Bypass -File Get-MilestoneCameraStatus.ps1 -Metric online -CameraGuid $1
#   powershell.exe -NoProfile -ExecutionPolicy Bypass -File Get-MilestoneCameraStatus.ps1 -Metric discover
#
# Metrics:
#   discover         -> LLD JSON of all cameras
#   online <guid>    -> 1/0
#   recording <guid> -> 1/0
#   last_frame <guid>-> unix timestamp of most recent recorded frame
#   retention <guid> -> days of recordings available
#   storage_pct      -> overall recording server storage % used

param(
    [Parameter(Mandatory=$true)]
    [ValidateSet('discover','online','recording','last_frame','retention','storage_pct')]
    [string]$Metric,

    [string]$CameraGuid
)

$ErrorActionPreference = 'Stop'

# Load the MIP SDK (installed with XProtect Management Client SDK)
# Adjust path if your install location differs
$mipPath = "${env:ProgramFiles}\Milestone\MIPSDK\Bin"
if (-not (Test-Path $mipPath)) {
    # Fallback for Pro+ which may install SDK elsewhere
    $mipPath = "${env:ProgramFiles}\Milestone\XProtect Management Client\MIPSDK\Bin"
}

Add-Type -Path (Join-Path $mipPath 'VideoOS.Platform.SDK.dll') -ErrorAction SilentlyContinue
Add-Type -Path (Join-Path $mipPath 'VideoOS.ConfigurationApi.ClientService.dll') -ErrorAction SilentlyContinue

# Connection settings — read from a protected config file rather than hardcoding
$configFile = "C:\ProgramData\zabbix\milestone.config.json"
if (-not (Test-Path $configFile)) {
    Write-Error "Missing $configFile — create with {ServerUri, Username, Password}"
    exit 1
}
$cfg = Get-Content $configFile -Raw | ConvertFrom-Json

# Login
[VideoOS.Platform.SDK.Environment]::Initialize()
$uri = [Uri]$cfg.ServerUri
$creds = New-Object System.Net.NetworkCredential($cfg.Username, $cfg.Password)
[VideoOS.Platform.SDK.Environment]::AddServer($uri, $creds)
[VideoOS.Platform.SDK.Environment]::Login($uri) | Out-Null

function Get-AllCameras {
    $sys = [VideoOS.Platform.Configuration]::Instance
    $cams = @()
    foreach ($recorder in $sys.GetItemsByKind([VideoOS.Platform.Kind]::Server)) {
        foreach ($cam in $recorder.GetChildren() | Where-Object { $_.FQID.Kind -eq [VideoOS.Platform.Kind]::Camera }) {
            $cams += $cam
        }
    }
    return $cams
}

function Get-CameraByGuid($guid) {
    Get-AllCameras | Where-Object { $_.FQID.ObjectId.ToString() -eq $guid } | Select-Object -First 1
}

switch ($Metric) {

    'discover' {
        $lld = @{ data = @() }
        foreach ($cam in Get-AllCameras) {
            $lld.data += @{
                '{#CAMERA_GUID}' = $cam.FQID.ObjectId.ToString()
                '{#CAMERA_NAME}' = $cam.Name
                '{#RECORDER}'    = $cam.FQID.ServerId.Name
            }
        }
        $lld | ConvertTo-Json -Compress -Depth 5
    }

    'online' {
        $cam = Get-CameraByGuid $CameraGuid
        if (-not $cam) { "0"; break }
        $state = $cam.EnabledState -eq 'Enabled' -and $cam.ConnectionState -eq 'Connected'
        if ($state) { "1" } else { "0" }
    }

    'recording' {
        $cam = Get-CameraByGuid $CameraGuid
        if (-not $cam) { "0"; break }
        if ($cam.RecordingEnabled) { "1" } else { "0" }
    }

    'last_frame' {
        $cam = Get-CameraByGuid $CameraGuid
        if (-not $cam) { "0"; break }
        # Query the recording database for the most recent sequence
        $db = $cam.GetDatabase()
        $latest = $db.GetMostRecentSequence()
        if ($latest) {
            [int64]([datetimeoffset]$latest.EndTime).ToUnixTimeSeconds()
        } else { "0" }
    }

    'retention' {
        $cam = Get-CameraByGuid $CameraGuid
        if (-not $cam) { "0"; break }
        $db = $cam.GetDatabase()
        $oldest = $db.GetOldestSequence()
        $newest = $db.GetMostRecentSequence()
        if ($oldest -and $newest) {
            $span = ($newest.EndTime - $oldest.StartTime).TotalDays
            [math]::Round($span, 2)
        } else { "0" }
    }

    'storage_pct' {
        # Aggregate across recording server storages
        $total = 0.0; $used = 0.0
        foreach ($rec in [VideoOS.Platform.Configuration]::Instance.GetItemsByKind([VideoOS.Platform.Kind]::Server)) {
            foreach ($storage in $rec.GetChildren() | Where-Object { $_.FQID.Kind -eq [VideoOS.Platform.Kind]::Storage }) {
                $total += [double]$storage.MaxSize
                $used  += [double]$storage.UsedSpace
            }
        }
        if ($total -gt 0) {
            [math]::Round(($used / $total) * 100, 1)
        } else { "0" }
    }
}

[VideoOS.Platform.SDK.Environment]::Logout($uri)
