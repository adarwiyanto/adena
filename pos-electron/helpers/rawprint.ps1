<#
.SYNOPSIS
  Kirim perintah ESC/POS raw ke printer thermal (cut command).
.PARAMETER PrinterName
  Nama printer Windows persis seperti di Devices and Printers.
#>
param(
  [Parameter(Mandatory = $true)]
  [string]$PrinterName
)

# ESC/POS bytes: Feed 3 baris + Full Cut
$bytes = [byte[]](0x1B, 0x64, 0x03,   # ESC d 3  — feed 3 lines
                  0x1D, 0x56, 0x00)    # GS  V 0  — full cut

# Load Win32 Spooler API
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

public class WinSpool {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
    public struct DOCINFOA {
        [MarshalAs(UnmanagedType.LPStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPStr)] public string pDatatype;
    }

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterA", SetLastError = true)]
    public static extern bool OpenPrinter(string name, out IntPtr handle, IntPtr defaults);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool ClosePrinter(IntPtr handle);

    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterA", SetLastError = true)]
    public static extern int StartDocPrinter(IntPtr handle, int level,
        [In, MarshalAs(UnmanagedType.LPStruct)] DOCINFOA docInfo);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndDocPrinter(IntPtr handle);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool StartPagePrinter(IntPtr handle);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndPagePrinter(IntPtr handle);

    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool WritePrinter(IntPtr handle, byte[] buf, int count, out int written);
}
'@ -ErrorAction Stop

$hPrinter = [IntPtr]::Zero
try {
    if (-not [WinSpool]::OpenPrinter($PrinterName, [ref]$hPrinter, [IntPtr]::Zero)) {
        $err = [System.Runtime.InteropServices.Marshal]::GetLastWin32Error()
        Write-Error "Tidak bisa membuka printer '$PrinterName'. Win32 error: $err"
        exit 1
    }

    $di        = New-Object WinSpool+DOCINFOA
    $di.pDocName  = 'ESC/POS Cut'
    $di.pDatatype = 'RAW'

    if ([WinSpool]::StartDocPrinter($hPrinter, 1, $di) -eq 0) {
        Write-Error 'StartDocPrinter gagal.'
        exit 1
    }

    [WinSpool]::StartPagePrinter($hPrinter) | Out-Null

    $written = 0
    [WinSpool]::WritePrinter($hPrinter, $bytes, $bytes.Length, [ref]$written) | Out-Null

    [WinSpool]::EndPagePrinter($hPrinter) | Out-Null
    [WinSpool]::EndDocPrinter($hPrinter)  | Out-Null

    Write-Output "Cut command OK — $written bytes dikirim ke '$PrinterName'."
}
finally {
    if ($hPrinter -ne [IntPtr]::Zero) {
        [WinSpool]::ClosePrinter($hPrinter) | Out-Null
    }
}
