$ErrorActionPreference = 'Stop'
$photoIds = @('photo-1600596542815-ffad4c1539a9','photo-1600210492486-724fe5c67fb0','photo-1600607687920-4e2a09cf159d','photo-1600047509807-ba8f99d2cdde','photo-1600566753190-17f0baa2a6c3','photo-1600607687939-ce8a6c25118c','photo-1518837695005-2083093ee35b','photo-1580489944761-15a19d654956','photo-1500648767791-00dcc994a43e','photo-1534528741775-53994a69daeb')
New-Item -ItemType Directory -Path public/assets/photos -Force | Out-Null
foreach ($photoId in $photoIds) {
    $destination = Join-Path 'public/assets/photos' ($photoId + '.jpg')
    if (-not (Test-Path -LiteralPath $destination)) {
        Invoke-WebRequest -Uri ('https://images.unsplash.com/' + $photoId + '?auto=format&fit=crop&w=1600&q=85') -OutFile $destination
    }
}
Write-Output 'Illustrative licensed photographs saved locally.'
