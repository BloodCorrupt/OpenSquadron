# Require Admin privileges
if (!([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Warning "You do not have Administrator rights to run this script!`nPlease re-run this script as an Administrator."
    Pause
    exit
}

$WorkspaceDir = (Get-Location).Path
$PublicDir = "$WorkspaceDir\public"
$Domain = "opensquadron.local"
$XamppVhostsPath = "C:\xampp\apache\conf\extra\httpd-vhosts.conf"
$HostsFilePath = "$env:windir\System32\drivers\etc\hosts"

Write-Host "============================================="
Write-Host " Running Local Environment Setup for XAMPP"
Write-Host "============================================="
Write-Host ""

# Update XAMPP Virtual Hosts
Write-Host "Adding VirtualHost for $Domain to XAMPP..."
if (Test-Path $XamppVhostsPath) {
    $VhostContent = Get-Content $XamppVhostsPath
    if ($VhostContent -match $Domain) {
        Write-Host "[OK] VirtualHost already exists in $XamppVhostsPath"
    } else {
        $VhostBlock = @"

<VirtualHost *:80>
    DocumentRoot `"$PublicDir`"
    ServerName $Domain
    <Directory `"$PublicDir`">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
"@
        Add-Content -Path $XamppVhostsPath -Value $VhostBlock
        Write-Host "[SUCCESS] VirtualHost added."
    }
} else {
    Write-Warning "Could not find $XamppVhostsPath. Please check if your XAMPP installation is in C:\xampp"
}

Write-Host ""
Write-Host "=========================================================="
Write-Host "Setup is complete!"
Write-Host "Please RESTART Apache and MySQL from your XAMPP Control Panel."
Write-Host "Once MySQL is running, you can create the database by running:"
Write-Host "  C:\xampp\php\php.exe bin/console doctrine:database:create"
Write-Host "=========================================================="
Pause
