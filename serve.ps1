$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add('http://127.0.0.1:8899/')
$listener.Start()
Write-Host "Serving $root on http://127.0.0.1:8899/"
try {
  while ($listener.IsListening) {
    $ctx = $listener.GetContext()
    $path = [Uri]::UnescapeDataString($ctx.Request.Url.AbsolutePath)
    if ($path -eq '/') { $path = '/index.html' }
    $file = Join-Path $root ($path -replace '/', '\')
    try {
      $bytes = [IO.File]::ReadAllBytes($file)
      $ext = [IO.Path]::GetExtension($file).ToLower()
      $mime = switch ($ext) {
        '.html' { 'text/html; charset=utf-8' }
        default { 'application/octet-stream' }
      }
      $ctx.Response.ContentType = $mime
      $ctx.Response.ContentLength64 = $bytes.Length
      $ctx.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    } catch {
      $ctx.Response.StatusCode = 404
      $body = [Text.Encoding]::UTF8.GetBytes('404 Not Found')
      $ctx.Response.ContentLength64 = $body.Length
      $ctx.Response.OutputStream.Write($body, 0, $body.Length)
    }
    $ctx.Response.Close()
  }
} finally {
  $listener.Stop()
}
