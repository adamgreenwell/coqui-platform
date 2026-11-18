# Publishes the Coqui SEO Platform backlog to GitHub: labels, milestones, project fields,
# issues, project items, field values and epic/sub-issue links.
# Idempotent: existing labels, fields, milestones, issues and project items are reused.

$ErrorActionPreference = 'Stop'
$repo = 'adamgreenwell/coqui-platform'
$owner = 'adamgreenwell'
$projectNumber = 17
$projectId = 'PVT_kwHOAF72is4BsOqN'
$tmp = Join-Path $PSScriptRoot '.tmp'
New-Item -ItemType Directory -Force $tmp | Out-Null

function Invoke-GhJson([string[]]$GhArgs) {
  $out = & gh @GhArgs 2>$null
  if ($LASTEXITCODE -ne 0) {
    $err = & gh @GhArgs 2>&1 | Out-String
    throw "gh $($GhArgs -join ' ') failed: $err"
  }
  $text = ($out | Out-String)
  $iObj = $text.IndexOf('{'); $iArr = $text.IndexOf('[')
  $i = if ($iObj -lt 0) { $iArr } elseif ($iArr -lt 0) { $iObj } else { [Math]::Min($iObj, $iArr) }
  if ($i -gt 0) { $text = $text.Substring($i) }
  return $text
}

function Invoke-GraphQL([string]$query, [hashtable]$variables) {
  $payload = @{ query = $query; variables = $variables } | ConvertTo-Json -Depth 20 -Compress
  $file = Join-Path $tmp ("gql-" + [guid]::NewGuid().ToString('n') + ".json")
  [System.IO.File]::WriteAllText($file, $payload, (New-Object System.Text.UTF8Encoding($false)))
  $raw = & gh api graphql --input $file 2>$null | Out-String
  Remove-Item $file -Force -ErrorAction SilentlyContinue
  if ($raw -notmatch '^\s*\{') { throw "GraphQL returned non-JSON response: $($raw.Substring(0,[Math]::Min(200,$raw.Length)))" }
  $json = $raw | ConvertFrom-Json
  if ($json.errors) {
    throw ("GraphQL errors: " + (($json.errors | ForEach-Object { $_.message }) -join '; '))
  }
  return $json
}

$data = Get-Content (Join-Path $PSScriptRoot '../plan/.generated/issues.json') -Raw | ConvertFrom-Json
$all = @($data.epics) + @($data.features)
$phaseNames = $data.phaseNames
Write-Output "backlog: $($data.epics.Count) epics, $($data.features.Count) features"

# ---------- labels ----------
$labelDefs = @(
  @{ n = 'type/epic'; c = '6f42c1'; d = 'Platform epic (one per subsystem)' },
  @{ n = 'type/feature'; c = '1d76db'; d = 'Platform feature issue' }
)
$epicSlugs = @('foundation','dataforseo-core','serp-intelligence','hybrid-crawler','backlinks','hosting-moat')
foreach ($s in $epicSlugs) { $labelDefs += @{ n = "epic/$s"; c = '8250df'; d = "Epic: $s" } }
$areas = @('platform','dataforseo','serp','crawler','backlinks','integrations','coolify')
foreach ($a in $areas) { $labelDefs += @{ n = "area/$a"; c = '0e8a16'; d = "Area: $a" } }
foreach ($p in 1..5) { $labelDefs += @{ n = "phase/$p"; c = 'fbca04'; d = "Delivery phase $p" } }
foreach ($s in @('S','M','L','XL')) { $labelDefs += @{ n = "size/$s"; c = 'c2e0c6'; d = "Size: $s" } }
foreach ($p in @('P0','P1','P2')) { $labelDefs += @{ n = "priority/$p"; c = 'd93f0b'; d = "Priority: $p" } }
foreach ($l in $labelDefs) {
  & gh label create $l.n --repo $repo --color $l.c --description $l.d --force 2>&1 | Out-Null
  if ($LASTEXITCODE -ne 0) { Write-Warning "label $($l.n) failed" }
}
Write-Output "labels: $($labelDefs.Count) ensured"

# ---------- project fields ----------
$epicOptionByKey = @{
  E01 = 'E01 Foundation'
  E02 = 'E02 DataForSEO Core'
  E03 = 'E03 SERP Intelligence'
  E04 = 'E04 Hybrid Crawler'
  E05 = 'E05 Backlinks'
  E06 = 'E06 Hosting Moat'
}
$epicOptions = @($data.epics | ForEach-Object { $epicOptionByKey[$_.key] })
$areaOptions = @('Platform','DataForSEO','SERP','Crawler','Backlinks','Integrations','Coolify')
$fieldDefs = @(
  @{ name = 'Epic'; options = $epicOptions },
  @{ name = 'Phase'; options = @('Phase 1','Phase 2','Phase 3','Phase 4','Phase 5') },
  @{ name = 'Priority'; options = @('P0','P1','P2') },
  @{ name = 'Size'; options = @('S','M','L','XL') },
  @{ name = 'Area'; options = $areaOptions }
)
$existingFields = (Invoke-GhJson @('project','field-list',"$projectNumber",'--owner',$owner,'--format','json') | ConvertFrom-Json).fields
foreach ($fd in $fieldDefs) {
  if ($existingFields.name -notcontains $fd.name) {
    $optArg = ($fd.options | ForEach-Object { if ($_ -match ',') { '"' + $_ + '"' } else { $_ } }) -join ','
    & gh project field-create $projectNumber --owner $owner --name $fd.name --data-type SINGLE_SELECT --single-select-options $optArg 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "field $($fd.name) failed" }
  }
}
$fields = (Invoke-GhJson @('project','field-list',"$projectNumber",'--owner',$owner,'--format','json') | ConvertFrom-Json).fields
$fieldMap = @{}
foreach ($f in $fields) { $fieldMap[$f.name] = $f }
$statusField = $fieldMap['Status']
$todoOption = ($statusField.options | Where-Object { $_.name -eq 'Todo' }).id

# ---------- milestones ----------
$existingMs = (Invoke-GhJson @('api', "repos/$repo/milestones?state=all&per_page=100") | ConvertFrom-Json)
$msMap = @{}
foreach ($m in $existingMs) { $msMap[$m.title] = $m.number }
foreach ($pn in ($phaseNames.PSObject.Properties | ForEach-Object { $_.Value })) {
  if (-not $msMap.ContainsKey($pn)) {
    $created = (Invoke-GhJson @('api','-X','POST',"repos/$repo/milestones",'-f',"title=$pn",'-f','state=open') | ConvertFrom-Json)
    $msMap[$pn] = $created.number
  }
}
Write-Output "milestones: $($msMap.Count)"

# ---------- repository + labels ----------
$repoQuery = 'query { repository(owner:"adamgreenwell", name:"coqui-platform") { id labels(first:100) { nodes { id name } } } }'
$repoInfo = Invoke-GraphQL $repoQuery @{}
$repositoryId = $repoInfo.data.repository.id
$labelMap = @{}
foreach ($n in $repoInfo.data.repository.labels.nodes) { $labelMap[$n.name] = $n.id }

# ---------- existing issues ----------
$existing = (Invoke-GhJson @('issue','list','--repo',$repo,'--state','all','--limit','500','--json','number,title,id,url') | ConvertFrom-Json)
$issueByTitle = @{}
foreach ($i in $existing) { $issueByTitle[$i.title] = $i }
Write-Output "existing issues: $($existing.Count)"

# ---------- milestone node ids ----------
$msNode = @{}
foreach ($k in $msMap.Keys) {
  $m = (Invoke-GhJson @('api',"repos/$repo/milestones/$($msMap[$k])") | ConvertFrom-Json)
  $msNode[$k] = $m.node_id
}

# ---------- create missing issues ----------
$san = { param($k) $k -replace '[^A-Za-z0-9]','' }
$remaining = @($all | Where-Object { -not $issueByTitle.ContainsKey($_.title) })
Write-Output "creating $($remaining.Count) issues (reusing $($all.Count - $remaining.Count))"
foreach ($it in $remaining) {
  $ok = $false
  for ($attempt = 1; $attempt -le 3 -and -not $ok; $attempt++) {
    try {
      $q = "mutation(`$i: CreateIssueInput!) { c: createIssue(input: `$i) { issue { id number url } } }"
      $vars = @{ i = @{
        repositoryId = $repositoryId
        title = $it.title
        body = $it.body
        labelIds = @($it.labels | ForEach-Object { $labelMap[$_] } | Where-Object { $_ })
        milestoneId = $msNode[$it.milestone]
      } }
      Invoke-GraphQL $q $vars | Out-Null
      $ok = $true
    } catch {
      Write-Warning "create $($it.key) attempt $attempt failed: $($_.Exception.Message)"
      Start-Sleep -Seconds (2 * $attempt)
    }
  }
  if (-not $ok) { throw "Could not create $($it.key)" }
  Write-Output "  created $($it.key)"
}

# ---------- authoritative issue ids ----------
$existing = (Invoke-GhJson @('issue','list','--repo',$repo,'--state','all','--limit','500','--json','number,title,id,url') | ConvertFrom-Json)
$issueIds = @{}
foreach ($i in $existing) {
  $m = $all | Where-Object { $_.title -eq $i.title } | Select-Object -First 1
  if ($m) { $issueIds[$m.key] = @{ id = $i.id; number = $i.number; url = $i.url } }
}
Write-Output "issue ids known: $($issueIds.Count) of $($all.Count)"
if ($issueIds.Count -ne $all.Count) { throw "Not all issues resolved: $($issueIds.Count)/$($all.Count)" }

# ---------- project items ----------
$existingItems = (Invoke-GhJson @('project','item-list',"$projectNumber",'--owner',$owner,'--format','json','--limit','500') | ConvertFrom-Json).items
$itemByNumber = @{}
foreach ($i in $existingItems) { if ($i.content.number) { $itemByNumber[[int]$i.content.number] = $i.id } }
$toAdd = @($issueIds.Keys | Where-Object { -not $itemByNumber.ContainsKey([int]$issueIds[$_].number) })
Write-Output "adding $($toAdd.Count) project items"
for ($i = 0; $i -lt $toAdd.Count; $i += 10) {
  $batch = $toAdd[$i..([Math]::Min($i + 9, $toAdd.Count - 1))]
  $addQuery = 'mutation(' + (($batch | ForEach-Object { '$a' + (& $san $_) + ': AddProjectV2ItemByIdInput!' }) -join ', ') + ') { ' + (($batch | ForEach-Object { 'p' + (& $san $_) + ': addProjectV2ItemById(input: $a' + (& $san $_) + ') { item { id } }' }) -join ' ') + ' }'
  $addVars = @{}
  foreach ($k in $batch) { $addVars["a" + (& $san $k)] = @{ projectId = $projectId; contentId = $issueIds[$k].id } }
  $addResult = Invoke-GraphQL $addQuery $addVars
  foreach ($k in $batch) {
    $itemByNumber[[int]$issueIds[$k].number] = $addResult.data.("p" + (& $san $k)).item.id
  }
  Write-Output "  added through $($batch[-1])"
}
Write-Output "project items: $($itemByNumber.Count)"

# ---------- field values ----------
function FieldOptionId($fieldName, $optionName) {
  $f = $fieldMap[$fieldName]
  if (-not $f) { return $null }
  $o = $f.options | Where-Object { $_.name -eq $optionName }
  if (-not $o) { return $null }
  return $o.id
}
$areaTitle = @{ platform='Platform'; dataforseo='DataForSEO'; serp='SERP'; crawler='Crawler'; backlinks='Backlinks'; integrations='Integrations'; coolify='Coolify' }
$epicArea = @{ E01='Platform'; E02='DataForSEO'; E03='SERP'; E04='Crawler'; E05='Backlinks'; E06='Integrations' }

$mutations = @()
foreach ($it in $all) {
  $itemId = $itemByNumber[[int]$issueIds[$it.key].number]
  $sets = @(
    @{ f = 'Epic'; o = $epicOptionByKey[$it.epic] },
    @{ f = 'Phase'; o = "Phase $($it.phase)" },
    @{ f = 'Priority'; o = $it.priority },
    @{ f = 'Size'; o = $it.size },
    @{ f = 'Area'; o = $(if ($it.labels -like 'area/*') { $areaTitle[(($it.labels | Where-Object { $_ -like 'area/*' } | Select-Object -First 1)).Substring(5)] } else { $epicArea[$it.epic] }) },
    @{ f = 'Status'; o = 'Todo' }
  )
  foreach ($s in $sets) {
    $fieldId = $fieldMap[$s.f].id
    $optionId = if ($s.f -eq 'Status') { $todoOption } else { FieldOptionId $s.f $s.o }
    if (-not $optionId) { Write-Warning "missing option $($s.f)=$($s.o)"; continue }
    $mutations += @{ item = $itemId; field = $fieldId; option = $optionId }
  }
}
Write-Output "field values to set: $($mutations.Count)"
$batchSize = 10
for ($i = 0; $i -lt $mutations.Count; $i += $batchSize) {
  $batch = $mutations[$i..([Math]::Min($i + $batchSize - 1, $mutations.Count - 1))]
  $q = 'mutation('
  $parts = @()
  $vars = @{}
  $n = 0
  foreach ($m in $batch) {
    $q += '$u' + $n + ': UpdateProjectV2ItemFieldValueInput!'
    if ($n -lt $batch.Count - 1) { $q += ', ' }
    $parts += "m${n}: updateProjectV2ItemFieldValue(input: `$u$n) { projectV2Item { id } }"
    $vars["u$n"] = @{ projectId = $projectId; itemId = $m.item; fieldId = $m.field; value = @{ singleSelectOptionId = $m.option } }
    $n++
  }
  $q += ') { ' + ($parts -join ' ') + ' }'
  Invoke-GraphQL $q $vars | Out-Null
}
Write-Output "field values done"

# ---------- sub-issue links ----------
$links = @()
foreach ($f in $data.features) { $links += @{ parent = $f.epic; child = $f.key } }
$linkBatch = 10
for ($i = 0; $i -lt $links.Count; $i += $linkBatch) {
  $batch = $links[$i..([Math]::Min($i + $linkBatch - 1, $links.Count - 1))]
  $q = 'mutation('
  $parts = @()
  $vars = @{}
  $n = 0
  foreach ($l in $batch) {
    $q += '$s' + $n + ': AddSubIssueInput!'
    if ($n -lt $batch.Count - 1) { $q += ', ' }
    $parts += "l${n}: addSubIssue(input: `$s$n) { issue { id } }"
    $vars["s$n"] = @{ issueId = $issueIds[$l.parent].id; subIssueId = $issueIds[$l.child].id; replaceParent = $false }
    $n++
  }
  $q += ') { ' + ($parts -join ' ') + ' }'
  try { Invoke-GraphQL $q $vars | Out-Null } catch { Write-Warning "sub-issue batch $i skipped: $($_.Exception.Message)" }
}
Write-Output "sub-issue links attempted for $($links.Count) features"

# ---------- verification ----------
$finalItems = (Invoke-GhJson @('project','item-list',"$projectNumber",'--owner',$owner,'--format','json','--limit','500') | ConvertFrom-Json).items
$issueCount = (Invoke-GhJson @('issue','list','--repo',$repo,'--state','all','--limit','500','--json','number') | ConvertFrom-Json).Count
Write-Output "VERIFY issues=$issueCount project_items=$($finalItems.Count) epics=$($data.epics.Count) features=$($data.features.Count)"
