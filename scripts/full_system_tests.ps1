# DariKruv full regression + corner + mass scenario tests (ASCII-only script)
$ErrorActionPreference = "Continue"
$base = "http://localhost:8080/api/index.php"
$script:pass = 0
$script:fail = 0
$script:fails = New-Object System.Collections.Generic.List[string]
$script:notes = New-Object System.Collections.Generic.List[string]

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Route,
        $Body = $null,
        [string]$Token = $null,
        [hashtable]$Query = @{}
    )
    $uri = "$base`?route=$([uri]::EscapeDataString($Route))"
    foreach ($k in $Query.Keys) {
        $uri += "&$k=$([uri]::EscapeDataString([string]$Query[$k]))"
    }
    $headers = @{ "Content-Type" = "application/json; charset=utf-8" }
    if ($Token) { $headers["Authorization"] = "Bearer $Token" }
    $timeoutSec = 45
    if ($Route -eq 'process_email_queue') { $timeoutSec = 90 }
    $params = @{
        Uri             = $uri
        Method          = $Method
        Headers         = $headers
        UseBasicParsing = $true
        TimeoutSec      = $timeoutSec
    }
    if ($null -ne $Body) {
        $params.Body = [System.Text.Encoding]::UTF8.GetBytes(($Body | ConvertTo-Json -Depth 10 -Compress))
    }
    try {
        $resp = Invoke-WebRequest @params
        $parsed = $null
        try { $parsed = $resp.Content | ConvertFrom-Json } catch {}
        return @{ Code = [int]$resp.StatusCode; Json = $parsed; Raw = $resp.Content }
    }
    catch {
        $code = 0
        $raw = $_.Exception.Message
        $parsed = $null
        if ($_.Exception.Response) {
            $code = [int]$_.Exception.Response.StatusCode
            try {
                $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
                $raw = $sr.ReadToEnd()
                $parsed = $raw | ConvertFrom-Json
            }
            catch {}
        }
        return @{ Code = $code; Json = $parsed; Raw = $raw }
    }
}

function Ok {
    param([string]$Name, [bool]$Cond, [string]$Detail = "")
    if ($Cond) {
        $script:pass++
        Write-Host "PASS  $Name" -ForegroundColor Green
    }
    else {
        $script:fail++
        $msg = if ($Detail) { "$Name | $Detail" } else { $Name }
        [void]$script:fails.Add($msg)
        Write-Host "FAIL  $msg" -ForegroundColor Red
    }
}

function Note([string]$Text) {
    [void]$script:notes.Add($Text)
    Write-Host "NOTE  $Text" -ForegroundColor Yellow
}

function Register-VerifiedUser {
    param(
        [string]$Email,
        [string]$Password = "TestPass1",
        [string]$First = "Test",
        [string]$Last = "User",
        [string]$City = "Sofia",
        [bool]$IsDonor = $false,
        [string]$BloodType = "O+",
        [string]$Phone = "0888000000",
        [bool]$EmailNotifications = $true
    )
    $body = @{
        first_name   = $First
        last_name    = $Last
        email        = $Email
        password     = $Password
        phone        = $Phone
        city         = $City
        is_donor     = $IsDonor
        accept_terms = $true
    }
    if ($IsDonor) {
        $body.blood_type = $BloodType
        $body.email_notifications = $EmailNotifications
    }
    $r = Invoke-Api -Method POST -Route register -Body $body
    if ($r.Code -ne 201) {
        return @{ Ok = $false; Error = "register $($r.Code) $($r.Raw)"; Token = $null }
    }
    $vlink = $r.Json.data.verification_link
    $vtoken = ""
    if ($vlink -match 'token=([^&]+)') { $vtoken = $Matches[1] }
    $vr = Invoke-Api -Method GET -Route verify_email -Query @{ token = $vtoken }
    if ($vr.Code -ne 200) {
        return @{ Ok = $false; Error = "verify $($vr.Code)"; Token = $null }
    }
    $lr = Invoke-Api -Method POST -Route login -Body @{ email = $Email; password = $Password }
    if ($lr.Code -ne 200 -or -not $lr.Json.data.auth_token) {
        return @{ Ok = $false; Error = "login $($lr.Code) $($lr.Raw)"; Token = $null }
    }
    return @{
        Ok       = $true
        Token    = [string]$lr.Json.data.auth_token
        PublicId = [string]$lr.Json.data.public_id
        Role     = [string]$lr.Json.data.role
        Email    = $Email
        Password = $Password
    }
}

function Clear-RateLimits {
    docker compose exec -T mysql mysql -uroot -proot darikruv -e "DELETE FROM rate_limit_attempts;" 2>$null | Out-Null
}

Write-Host "===== PREP =====" -ForegroundColor Cyan
Clear-RateLimits
$ts = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$run = "r$ts"
$deletePhrase = -join ([char]0x0418, [char]0x0417, [char]0x0422, [char]0x0420, [char]0x0418, [char]0x0419)

Write-Host ""
Write-Host "===== A) PAGES AND ASSETS =====" -ForegroundColor Cyan
$pages = @(
    "/html/welcome.html", "/html/login.html", "/html/register.html", "/html/request.html",
    "/html/create-request.html", "/html/profile.html", "/html/faq.html", "/html/campaigns.html",
    "/html/admin.html", "/html/forgot-password.html", "/html/reset-password.html",
    "/html/privacy-policy.html", "/html/email-verified.html", "/html/request-details.html",
    "/html/auth-required.html"
)
foreach ($p in $pages) {
    try {
        $c = (Invoke-WebRequest "http://localhost:8080$p" -UseBasicParsing -TimeoutSec 15 -MaximumRedirection 5).StatusCode
        Ok "PAGE $p" ($c -eq 200) "HTTP $c"
    }
    catch { Ok "PAGE $p" $false $_.Exception.Message }
}
foreach ($a in @(
        "/js/login.js", "/js/register.js", "/js/requests.js", "/js/auth-guard.js",
        "/js/request-details.js", "/js/create-request.js", "/js/profile.js", "/js/admin.js",
        "/css/style_test1.css", "/firebase-messaging-sw.js"
    )) {
    try {
        $c = (Invoke-WebRequest "http://localhost:8080$a" -UseBasicParsing -TimeoutSec 10).StatusCode
        Ok "ASSET $a" ($c -eq 200) "HTTP $c"
    }
    catch { Ok "ASSET $a" $false $_.Exception.Message }
}

Write-Host ""
Write-Host "===== B) AUTH CORNERS =====" -ForegroundColor Cyan
$r = Invoke-Api -Method GET -Route nope
Ok "unknown route 404" ($r.Code -eq 404)

$r = Invoke-Api -Method POST -Route register -Body @{ first_name = "A"; last_name = "B"; email = "bad"; password = "TestPass1"; city = "Sofia"; accept_terms = $true }
Ok "invalid email" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route register -Body @{ first_name = "A"; last_name = "B"; email = "x$run@test.local"; password = "short1A"; city = "Sofia"; accept_terms = $true }
Ok "password short" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route register -Body @{ first_name = "A"; last_name = "B"; email = "x2$run@test.local"; password = "NoDigitsHere"; city = "Sofia"; accept_terms = $true }
Ok "password no digit" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route register -Body @{ first_name = "A"; last_name = "B"; email = "x3$run@test.local"; password = "TestPass1"; city = "Sofia"; accept_terms = $false }
Ok "terms required" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route register -Body @{ first_name = "A"; last_name = "B"; email = "dnr$run@test.local"; password = "TestPass1"; city = "Sofia"; is_donor = $true; blood_type = "Z+"; accept_terms = $true }
Ok "bad blood type" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route register -Body @{ first_name = "A"; last_name = "B"; email = "dnr2$run@test.local"; password = "TestPass1"; city = "Sofia"; is_donor = $true; accept_terms = $true }
Ok "donor no blood type" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route login -Body @{ email = "admin' OR '1'='1"; password = "TestPass1" }
Ok "sql-ish login handled" ($r.Code -in 400, 401) "HTTP $($r.Code)"

$r = Invoke-Api -Method POST -Route login -Body @{ email = "nobody$run@test.local"; password = "TestPass1"; website = "http://bot" }
Ok "honeypot login" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route register -Body @{ first_name = "A"; last_name = "B"; email = "hp$run@test.local"; password = "TestPass1"; city = "Sofia"; accept_terms = $true; website = "spam" }
Ok "honeypot register" ($r.Code -eq 400)

$r = Invoke-Api -Method GET -Route verify_email -Query @{ token = "not-a-real-token" }
Ok "bad verify token" ($r.Code -in 400, 404) "HTTP $($r.Code)"

$r = Invoke-Api -Method POST -Route create_request -Body @{ patient_name = "X" } -Token "not.a.jwt"
Ok "garbage bearer 401" ($r.Code -eq 401)

$r = Invoke-Api -Method POST -Route create_request -Body @{ patient_name = "X" }
Ok "missing bearer 401" ($r.Code -eq 401)

Write-Host ""
Write-Host "===== C) MASS USERS =====" -ForegroundColor Cyan
# Auth corner cases already consumed register_ip budget; reset for mass scenario.
Clear-RateLimits
$donors = @()
$bloodTypes = @("O+", "O-", "A+", "A-", "B+", "AB+")
$cities = @("Sofia", "Plovdiv", "Varna")
for ($i = 0; $i -lt 6; $i++) {
    if (($i % 4) -eq 0) { Clear-RateLimits }
    $u = Register-VerifiedUser -Email "donor$i$run@test.local" -First "Donor$i" -Last "Test" -City $cities[$i % 3] -IsDonor $true -BloodType $bloodTypes[$i] -Phone ("0888111{0:D3}" -f $i)
    Ok "donor $i $($bloodTypes[$i])" $u.Ok $u.Error
    if ($u.Ok) { $donors += $u }
}
Clear-RateLimits
$requesters = @()
for ($i = 0; $i -lt 3; $i++) {
    $u = Register-VerifiedUser -Email "req$i$run@test.local" -First "Req$i" -Last "Test" -City $cities[$i] -IsDonor $false -Phone ("0888222{0:D3}" -f $i)
    Ok "requester $i" $u.Ok $u.Error
    if ($u.Ok) { $requesters += $u }
}
if ($donors.Count -lt 6 -or $requesters.Count -lt 3) {
    Write-Host "FATAL: mass user setup incomplete donors=$($donors.Count) requesters=$($requesters.Count)" -ForegroundColor Red
    Write-Host "===== FINAL PASS=$($script:pass) FAIL=$($script:fail) =====" -ForegroundColor Cyan
    exit 1
}
$r = Invoke-Api -Method POST -Route register -Body @{ first_name = "X"; last_name = "Y"; email = "donor0$run@test.local"; password = "TestPass1"; city = "Sofia"; accept_terms = $true }
Ok "duplicate 409" ($r.Code -eq 409)

Write-Host ""
Write-Host "===== D) REQUESTS =====" -ForegroundColor Cyan
$requests = @()
$r = Invoke-Api -Method POST -Route create_request -Body @{ patient_name = "P"; blood_type = "QQ"; city = "Sofia"; hospital = "H"; contact_name = "C"; contact_phone = "1" } -Token $requesters[0].Token
Ok "invalid blood create" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route create_request -Body @{ patient_name = "P"; blood_type = "O+"; city = "Sofia"; hospital = "H"; contact_name = "C"; contact_phone = "1"; required_units_count = 0 } -Token $requesters[0].Token
Ok "units lt 1" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route create_request -Body @{ patient_name = ""; blood_type = "O+"; city = "Sofia"; hospital = "H"; contact_name = "C"; contact_phone = "1" } -Token $requesters[0].Token
Ok "missing patient" ($r.Code -eq 400)

$specs = @(
    @{ city = "Sofia"; blood = "O+"; units = 2; hospital = "Pirogov"; patient = "Patient Sofia O+" },
    @{ city = "Plovdiv"; blood = "A+"; units = 1; hospital = "UMBAL Plovdiv"; patient = "Patient Plovdiv A+" },
    @{ city = "Varna"; blood = "B+"; units = 3; hospital = "Sv Marina"; patient = "Patient Varna B+" },
    @{ city = "Sofia"; blood = "AB+"; units = 1; hospital = "Alexandrovska"; patient = "Patient Sofia AB+" },
    @{ city = "Sofia"; blood = "O-"; units = 2; hospital = "VMA"; patient = "Patient Sofia O-" }
)
for ($i = 0; $i -lt $specs.Count; $i++) {
    $s = $specs[$i]
    $reqUser = $requesters[$i % $requesters.Count]
    $r = Invoke-Api -Method POST -Route create_request -Body @{
        patient_name         = $s.patient
        blood_type           = $s.blood
        city                 = $s.city
        hospital             = $s.hospital
        contact_name         = "Contact $i"
        contact_phone        = "0888333$i"
        description          = "Need #$i $($s.blood) in $($s.city)"
        required_units_count = $s.units
    } -Token $reqUser.Token
    $ok = ($r.Code -eq 201 -and $r.Json.status -eq "success" -and $r.Json.data.id)
    Ok "create req #$i $($s.city) $($s.blood) x$($s.units)" $ok "HTTP $($r.Code) $($r.Raw)"
    if ($ok) {
        $requests += @{
            Id         = [int]$r.Json.data.id
            Spec       = $s
            OwnerToken = $reqUser.Token
            OwnerEmail = $reqUser.Email
        }
    }
}

$r = Invoke-Api -Method GET -Route requests
$listCount = if ($r.Json.data) { @($r.Json.data).Count } else { 0 }
Ok "list requests" ($r.Code -eq 200 -and $listCount -ge $requests.Count) "count=$listCount"

$r = Invoke-Api -Method GET -Route request_details -Query @{ id = "0" }
Ok "details id0 400" ($r.Code -eq 400)

$r = Invoke-Api -Method GET -Route request_details -Query @{ id = "999999" }
Ok "details missing 404" ($r.Code -eq 404)

if ($requests.Count -gt 0) {
    $rid = $requests[0].Id
    $r = Invoke-Api -Method GET -Route request_details -Query @{ id = "$rid" }
    Ok "details ok" ($r.Code -eq 200 -and [int]$r.Json.data.id -eq $rid)

    $r = Invoke-Api -Method POST -Route create_request_comment -Body @{
        request_id = $rid
        name       = "script-alert-user"
        text       = "Need O+ urgent note"
        is_donor   = $false
    }
    Ok "comment create" ($r.Code -in 200, 201 -and $r.Json.status -eq "success") "HTTP $($r.Code) $($r.Raw)"

    $r = Invoke-Api -Method GET -Route request_comments -Query @{ request_id = "$rid" }
    Ok "list comments" ($r.Code -eq 200)

    $r = Invoke-Api -Method POST -Route update_request -Body @{
        request_id = $rid; patient_name = "HACK"; blood_type = "O+"; city = "Sofia"
        hospital = "X"; contact_name = "X"; contact_phone = "1"; required_units_count = 2
    } -Token $requesters[1].Token
    Ok "non-owner edit 403" ($r.Code -eq 403) "HTTP $($r.Code)"

    $r = Invoke-Api -Method POST -Route update_request -Body @{
        request_id           = $rid
        patient_name         = $requests[0].Spec.patient
        blood_type           = $requests[0].Spec.blood
        city                 = $requests[0].Spec.city
        hospital             = "Pirogov updated"
        contact_name         = "Contact 0"
        contact_phone        = "08883330"
        description          = "Updated"
        required_units_count = 2
    } -Token $requests[0].OwnerToken
    Ok "owner edit active" ($r.Code -eq 200 -and $r.Json.status -eq "success") "HTTP $($r.Code) $($r.Raw)"
}

Write-Host ""
Write-Host "===== E) RESPOND CORNERS =====" -ForegroundColor Cyan
Clear-RateLimits
$self = Register-VerifiedUser -Email "self$run@test.local" -First "Self" -Last "Donor" -IsDonor $true -BloodType "O+" -City "Sofia" -Phone "0888444000"
Ok "self donor" $self.Ok $self.Error
$r = Invoke-Api -Method POST -Route create_request -Body @{
    patient_name = "Own"; blood_type = "O+"; city = "Sofia"; hospital = "H"
    contact_name = "Self"; contact_phone = "0888444000"; required_units_count = 1
} -Token $self.Token
$selfRid = [int]$r.Json.data.id
Ok "self create" ($r.Code -eq 201)
$r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $selfRid; action = "pledge" } -Token $self.Token
Ok "cannot pledge own" ($r.Code -eq 403) "HTTP $($r.Code) $($r.Raw)"

$target = $requests | Where-Object { $_.Spec.blood -eq "A+" } | Select-Object -First 1
if ($target) {
    $r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $target.Id; action = "confirm" } -Token $donors[2].Token
    Ok "confirm without pledge" ($r.Code -eq 400) "HTTP $($r.Code)"
}

$oPlusReq = $requests | Where-Object { $_.Spec.blood -eq "O+" -and $_.Spec.city -eq "Sofia" } | Select-Object -First 1
if ($oPlusReq -and $donors.Count -ge 2) {
    $d0 = $donors[0]
    $d1 = $donors[1]

    $r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $oPlusReq.Id; action = "pledge" } -Token $d0.Token
    Ok "first pledge" ($r.Code -in 200, 201 -and $r.Json.status -eq "success") "HTTP $($r.Code) $($r.Raw)"

    $r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $oPlusReq.Id; action = "pledge" } -Token $d1.Token
    Ok "second blocked waiting" ($r.Code -eq 409) "HTTP $($r.Code) $($r.Raw)"

    $r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $oPlusReq.Id; action = "pledge" } -Token $d0.Token
    Ok "repledge idempotent" ($r.Code -eq 200 -and $r.Json.status -eq "success") "HTTP $($r.Code)"

    $r = Invoke-Api -Method POST -Route update_request -Body @{
        request_id = $oPlusReq.Id; patient_name = $oPlusReq.Spec.patient; blood_type = "O+"; city = "Sofia"
        hospital = "X"; contact_name = "C"; contact_phone = "1"; required_units_count = 2
    } -Token $oPlusReq.OwnerToken
    Ok "cannot edit waiting" ($r.Code -eq 403) "HTTP $($r.Code)"

    $r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $oPlusReq.Id; action = "confirm" } -Token $d0.Token
    Ok "confirm unit1" ($r.Code -eq 200 -and $r.Json.status -eq "success") "HTTP $($r.Code) $($r.Raw)"

    $after = Invoke-Api -Method GET -Route request_details -Query @{ id = "$($oPlusReq.Id)" }
    Ok "after unit1 active fulfilled1" (
        $after.Json.data.status -eq "active" -and [int]$after.Json.data.fulfilled_units_count -eq 1
    ) "status=$($after.Json.data.status) f=$($after.Json.data.fulfilled_units_count)"

    $r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $oPlusReq.Id; action = "pledge" } -Token $d1.Token
    Ok "second pledge unit2" ($r.Code -in 200, 201 -and $r.Json.status -eq "success") "HTTP $($r.Code)"

    $r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $oPlusReq.Id; action = "confirm" } -Token $d1.Token
    Ok "confirm unit2 fulfilled" ($r.Code -eq 200 -and $r.Json.status -eq "success") "HTTP $($r.Code)"

    $after2 = Invoke-Api -Method GET -Route request_details -Query @{ id = "$($oPlusReq.Id)" }
    Ok "fulfilled final" (
        $after2.Json.data.status -eq "fulfilled" -and [int]$after2.Json.data.fulfilled_units_count -eq 2
    ) "status=$($after2.Json.data.status) f=$($after2.Json.data.fulfilled_units_count)"

    $r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $oPlusReq.Id; action = "pledge" } -Token $donors[2].Token
    Ok "cannot pledge fulfilled" ($r.Code -eq 400) "HTTP $($r.Code)"
}

Write-Host ""
Write-Host "===== E2) WAITING EXPIRY =====" -ForegroundColor Cyan
$expReq = Invoke-Api -Method POST -Route create_request -Body @{
    patient_name = "Expiry"; blood_type = "AB+"; city = "Sofia"; hospital = "H"
    contact_name = "C"; contact_phone = "0888555000"; required_units_count = 1
} -Token $requesters[0].Token
$expId = [int]$expReq.Json.data.id
Ok "expiry create" ($expReq.Code -eq 201)

$r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $expId; action = "pledge" } -Token $donors[5].Token
Ok "expiry pledge" ($r.Code -in 200, 201) "HTTP $($r.Code)"

docker compose exec -T mysql mysql -uroot -proot darikruv -e "UPDATE blood_requests SET waiting_until=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=$expId;" 2>$null | Out-Null
$r = Invoke-Api -Method GET -Route request_details -Query @{ id = "$expId" }
Ok "expired becomes active" ($r.Code -eq 200 -and $r.Json.data.status -eq "active") "status=$($r.Json.data.status)"

$r = Invoke-Api -Method POST -Route respond_to_request -Body @{ request_id = $expId; action = "pledge" } -Token $donors[0].Token
Ok "pledge after expiry" ($r.Code -in 200, 201 -and $r.Json.status -eq "success") "HTTP $($r.Code) $($r.Raw)"

Write-Host ""
Write-Host "===== F) PROFILE RESET DELETE =====" -ForegroundColor Cyan
$r = Invoke-Api -Method POST -Route update_profile -Body @{
    name = "Donor0 Updated"; email = $donors[0].Email; phone = "0888111999"; city = "Plovdiv"
    blood_type = "O+"; is_available = $false; email_notifications = $false
} -Token $donors[0].Token
Ok "update profile" ($r.Code -eq 200 -and $r.Json.status -eq "success") "HTTP $($r.Code)"

$r = Invoke-Api -Method POST -Route update_profile -Body @{ name = "X"; email = "not-an-email" } -Token $donors[0].Token
Ok "profile bad email" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route update_last_donation -Body @{ last_donation_date = "2099-01-01" } -Token $donors[0].Token
Ok "last donation rejects future" ($r.Code -eq 400) "HTTP $($r.Code)"

$r = Invoke-Api -Method POST -Route update_last_donation -Body @{ last_donation_date = "not-a-date" } -Token $donors[0].Token
Ok "last donation bad date handled" ($r.Code -eq 400) "HTTP $($r.Code)"

$resetEmail = $donors[3].Email
$r = Invoke-Api -Method POST -Route request_password_reset -Body @{ email = $resetEmail }
Ok "password reset request" ($r.Code -eq 200)

$r = Invoke-Api -Method POST -Route request_password_reset -Body @{ email = "missing$run@test.local" }
Ok "reset unknown email generic" ($r.Code -eq 200)

$resetTok = (docker compose exec -T mysql mysql -uroot -proot -N darikruv -e "SELECT password_reset_token FROM users WHERE email='$resetEmail';" 2>$null).ToString().Trim()
Ok "reset token stored" ($resetTok.Length -gt 10) "len=$($resetTok.Length)"

$r = Invoke-Api -Method POST -Route reset_password -Body @{ token = "badtoken"; password = "NewPass12" }
Ok "bad reset token" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route reset_password -Body @{ token = $resetTok; password = "weak" }
Ok "weak reset pw" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route reset_password -Body @{ token = $resetTok; password = "NewPass12" }
Ok "reset ok" ($r.Code -eq 200) "HTTP $($r.Code)"

$r = Invoke-Api -Method POST -Route login -Body @{ email = $resetEmail; password = "NewPass12" }
Ok "login new pw" ($r.Code -eq 200 -and $r.Json.data.auth_token)
$donors[3].Token = $r.Json.data.auth_token

$r = Invoke-Api -Method POST -Route reset_password -Body @{ token = $resetTok; password = "Another1" }
Ok "reset single use" ($r.Code -eq 400)

$r = Invoke-Api -Method POST -Route request_password_reset -Body @{ email = $donors[4].Email }
$tok4 = (docker compose exec -T mysql mysql -uroot -proot -N darikruv -e "SELECT password_reset_token FROM users WHERE email='$($donors[4].Email)';" 2>$null).ToString().Trim()
docker compose exec -T mysql mysql -uroot -proot darikruv -e "UPDATE users SET password_reset_expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE email='$($donors[4].Email)';" 2>$null | Out-Null
$r = Invoke-Api -Method POST -Route reset_password -Body @{ token = $tok4; password = "NewPass12" }
Ok "expired reset token" ($r.Code -eq 400)

Clear-RateLimits
$delUser = Register-VerifiedUser -Email "delete$run@test.local" -First "Del" -Last "Me" -City "Sofia" -Phone "0888666000"
Ok "delete user create" $delUser.Ok $delUser.Error

$bytes = [System.Text.Encoding]::UTF8.GetBytes('{"password":"TestPass1","confirm_phrase":"WRONG"}')
try {
    Invoke-WebRequest -Uri "$base`?route=delete_account" -Method POST -Headers @{ Authorization = "Bearer $($delUser.Token)"; "Content-Type" = "application/json; charset=utf-8" } -Body $bytes -UseBasicParsing | Out-Null
    Ok "wrong phrase" $false
}
catch { Ok "wrong phrase 400" ([int]$_.Exception.Response.StatusCode -eq 400) }

$bytes = [System.Text.Encoding]::UTF8.GetBytes(('{"password":"TestPass1","confirm_phrase":"' + $deletePhrase + '"}'))
try {
    $resp = Invoke-WebRequest -Uri "$base`?route=delete_account" -Method POST -Headers @{ Authorization = "Bearer $($delUser.Token)"; "Content-Type" = "application/json; charset=utf-8" } -Body $bytes -UseBasicParsing
    Ok "delete ok" ($resp.StatusCode -eq 200) $resp.Content
}
catch {
    $c = [int]$_.Exception.Response.StatusCode
    $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
    Ok "delete ok" $false "HTTP $c $($sr.ReadToEnd())"
}

$r = Invoke-Api -Method POST -Route login -Body @{ email = "delete$run@test.local"; password = "TestPass1" }
Ok "deleted cannot login" ($r.Code -eq 401)

Write-Host ""
Write-Host "===== G) ADMIN =====" -ForegroundColor Cyan
$hash = (docker compose exec -T php php -r "echo password_hash('TestPass1', PASSWORD_DEFAULT);").ToString().Trim()
$adminEmail = "admin$run@test.local"
$uuid = (docker compose exec -T php php -r "require '/var/www/html/api/helpers/auth.php'; echo auth_generate_uuid_v4();").ToString().Trim()
docker compose exec -T mysql mysql -uroot -proot darikruv -e "INSERT INTO users (public_id, first_name, last_name, email, password, city, role, is_verified, verified_at, terms_accepted_at, terms_version) VALUES ('$uuid','Admin','Main','$adminEmail','$hash','Sofia','admin',1,NOW(),NOW(),'2026-05-18-v2');" 2>$null | Out-Null

$r = Invoke-Api -Method POST -Route login -Body @{ email = $adminEmail; password = "TestPass1" }
Ok "admin login" ($r.Code -eq 200 -and $r.Json.data.auth_token) "HTTP $($r.Code)"
$adminToken = $r.Json.data.auth_token

$r = Invoke-Api -Method GET -Route users -Token $donors[0].Token
Ok "donor users 403" ($r.Code -eq 403)

$r = Invoke-Api -Method GET -Route users -Token $adminToken
$userCount = if ($r.Json.data) { @($r.Json.data).Count } else { 0 }
Ok "admin users" ($r.Code -eq 200 -and $userCount -ge 10) "count=$userCount"

$r = Invoke-Api -Method GET -Route donors -Token $adminToken
$donorCount = if ($r.Json.data) { @($r.Json.data).Count } else { 0 }
Ok "admin donors" ($r.Code -eq 200 -and $donorCount -ge 5) "count=$donorCount"

$r = Invoke-Api -Method POST -Route process_email_queue -Body @{ batch_size = 3 } -Token $adminToken
# Without real SMTP each attempt can take a few seconds; small batch keeps this deterministic.
Ok "email queue" ($r.Code -eq 200 -and $r.Json.status -eq "success") "HTTP $($r.Code) $($r.Raw)"

$r = Invoke-Api -Method POST -Route create_campaign -Body @{
    title = "Campaign $run"; city = "Sofia"; date = "2026-09-20"
    link = "https://example.com/c-$run"; description = "Test"
} -Token $adminToken
Ok "campaign" ($r.Code -eq 200 -and $r.Json.status -eq "success") "HTTP $($r.Code) $($r.Raw)"

$r = Invoke-Api -Method POST -Route create_campaign -Body @{
    title = "Bad"; city = "Sofia"; date = "2026-09-20"; link = "not-a-url"
} -Token $adminToken
Ok "campaign bad link" ($r.Code -eq 400)

$bytes = [System.Text.Encoding]::UTF8.GetBytes(('{"password":"TestPass1","confirm_phrase":"' + $deletePhrase + '"}'))
try {
    Invoke-WebRequest -Uri "$base`?route=delete_account" -Method POST -Headers @{ Authorization = "Bearer $adminToken"; "Content-Type" = "application/json; charset=utf-8" } -Body $bytes -UseBasicParsing | Out-Null
    Ok "admin delete blocked" $false
}
catch { Ok "admin delete blocked" ([int]$_.Exception.Response.StatusCode -eq 403) }

Write-Host ""
Write-Host "===== H) LISTS PUSH STRESS =====" -ForegroundColor Cyan
$r = Invoke-Api -Method GET -Route my_requests -Token $requesters[0].Token
Ok "my_requests" ($r.Code -eq 200)

$r = Invoke-Api -Method GET -Route my_responses -Token $donors[0].Token
Ok "my_responses" ($r.Code -eq 200)

$r = Invoke-Api -Method POST -Route save_push_token -Body @{ token = "mass-token-$run"; enabled = $true } -Token $donors[0].Token
Ok "push token" ($r.Code -eq 200)

$r = Invoke-Api -Method POST -Route save_push_token -Body @{ token = "mass-token-$run"; enabled = $true } -Token $donors[0].Token
Ok "push token idempotent" ($r.Code -eq 200)

$r = Invoke-Api -Method GET -Route push_public_config
Ok "push config" ($r.Code -eq 200)

$r = Invoke-Api -Method GET -Route ncth_stores
Ok "ncth_stores" ($r.Code -eq 200 -and $r.Json.status -eq "success")

$okLists = 0
for ($i = 0; $i -lt 20; $i++) {
    $r = Invoke-Api -Method GET -Route requests
    if ($r.Code -eq 200 -and $r.Json.status -eq "success") { $okLists++ }
}
Ok "20x list stable" ($okLists -eq 20) "ok=$okLists/20"

$r = Invoke-Api -Method GET -Route requests -Token $donors[0].Token
Ok "auth list" ($r.Code -eq 200)

Write-Host ""
Write-Host "===== I) DB SNAPSHOT =====" -ForegroundColor Cyan
$snap = docker compose exec -T mysql mysql -uroot -proot -N darikruv -e "SELECT 'users',COUNT(*) FROM users UNION ALL SELECT 'donors',COUNT(*) FROM donors UNION ALL SELECT 'requests',COUNT(*) FROM blood_requests UNION ALL SELECT 'responses',COUNT(*) FROM request_responses UNION ALL SELECT 'fulfilled',COUNT(*) FROM blood_requests WHERE status='fulfilled' UNION ALL SELECT 'waiting',COUNT(*) FROM blood_requests WHERE status='waiting' UNION ALL SELECT 'active',COUNT(*) FROM blood_requests WHERE status='active';" 2>$null
Write-Host $snap
Ok "db snapshot" $true

Write-Host ""
Write-Host "===== FINAL PASS=$($script:pass) FAIL=$($script:fail) =====" -ForegroundColor Cyan
if ($script:fails.Count) {
    Write-Host "FAILURES:"
    $script:fails | ForEach-Object { Write-Host " - $_" }
}
if ($script:notes.Count) {
    Write-Host "NOTES:"
    $script:notes | ForEach-Object { Write-Host " - $_" }
}
if ($script:fail -gt 0) { exit 1 } else { exit 0 }
