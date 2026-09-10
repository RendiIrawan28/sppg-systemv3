param([string[]]$Tasks = @('assembleDebug', 'testDebugUnitTest', 'lintDebug'))

$ErrorActionPreference = 'Stop'
# Keep Java socket files out of the Windows short-name user temp path.
# Environment overrides are limited to this build and restored afterwards.
$buildTemp = Join-Path $PSScriptRoot 'build/tmp-java'
New-Item -ItemType Directory -Force -Path $buildTemp | Out-Null
$previousTemp = $env:TEMP
$previousTmp = $env:TMP
$previousJavaOptions = $env:JAVA_TOOL_OPTIONS
try {
    $env:TEMP = $buildTemp
    $env:TMP = $buildTemp
    $env:JAVA_TOOL_OPTIONS = ($previousJavaOptions + ' "-Djava.io.tmpdir=' + $buildTemp + '"').Trim()
    & (Join-Path $PSScriptRoot 'gradlew.bat') -p $PSScriptRoot @Tasks
    $buildExitCode = $LASTEXITCODE
} finally {
    $env:TEMP = $previousTemp
    $env:TMP = $previousTmp
    $env:JAVA_TOOL_OPTIONS = $previousJavaOptions
}
exit $buildExitCode
