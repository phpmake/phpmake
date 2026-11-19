param(
    [Parameter(Mandatory = $true)]
    [string]$ArgumentsBase64
)

$ErrorActionPreference = 'Stop'

try {
    $source = @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;

public static class PhpMakeJobRunner
{
    private const uint CreateSuspended = 0x00000004;
    private const uint CreateNoWindow = 0x08000000;
    private const uint StartfUseStdHandles = 0x00000100;
    private const uint JobObjectLimitKillOnJobClose = 0x00002000;
    private const uint WaitObject0 = 0;
    private const uint Infinite = 0xFFFFFFFF;

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct StartupInfo
    {
        public int cb;
        public string lpReserved;
        public string lpDesktop;
        public string lpTitle;
        public int dwX;
        public int dwY;
        public int dwXSize;
        public int dwYSize;
        public int dwXCountChars;
        public int dwYCountChars;
        public int dwFillAttribute;
        public uint dwFlags;
        public short wShowWindow;
        public short cbReserved2;
        public IntPtr lpReserved2;
        public IntPtr hStdInput;
        public IntPtr hStdOutput;
        public IntPtr hStdError;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ProcessInformation
    {
        public IntPtr hProcess;
        public IntPtr hThread;
        public uint dwProcessId;
        public uint dwThreadId;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct BasicLimitInformation
    {
        public long PerProcessUserTimeLimit;
        public long PerJobUserTimeLimit;
        public uint LimitFlags;
        public UIntPtr MinimumWorkingSetSize;
        public UIntPtr MaximumWorkingSetSize;
        public uint ActiveProcessLimit;
        public UIntPtr Affinity;
        public uint PriorityClass;
        public uint SchedulingClass;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct IoCounters
    {
        public ulong ReadOperationCount;
        public ulong WriteOperationCount;
        public ulong OtherOperationCount;
        public ulong ReadTransferCount;
        public ulong WriteTransferCount;
        public ulong OtherTransferCount;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ExtendedLimitInformation
    {
        public BasicLimitInformation BasicLimitInformation;
        public IoCounters IoInfo;
        public UIntPtr ProcessMemoryLimit;
        public UIntPtr JobMemoryLimit;
        public UIntPtr PeakProcessMemoryUsed;
        public UIntPtr PeakJobMemoryUsed;
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr CreateJobObject(IntPtr attributes, string name);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool SetInformationJobObject(
        IntPtr job, int informationClass, IntPtr information, uint informationLength);

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool CreateProcess(
        string applicationName, StringBuilder commandLine, IntPtr processAttributes,
        IntPtr threadAttributes, bool inheritHandles, uint creationFlags,
        IntPtr environment, string currentDirectory, ref StartupInfo startupInfo,
        out ProcessInformation processInformation);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern uint ResumeThread(IntPtr thread);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern uint WaitForSingleObject(IntPtr handle, uint milliseconds);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool GetExitCodeProcess(IntPtr process, out uint exitCode);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool TerminateProcess(IntPtr process, uint exitCode);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool CloseHandle(IntPtr handle);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr GetStdHandle(int standardHandle);

    private static Exception LastError(string operation)
    {
        return new Win32Exception(Marshal.GetLastWin32Error(), operation);
    }

    private static string Quote(string value)
    {
        var result = new StringBuilder("\"");
        var backslashes = 0;
        foreach (var character in value)
        {
            if (character == '\\')
            {
                backslashes++;
                continue;
            }

            if (character == '"')
            {
                result.Append('\\', (backslashes * 2) + 1);
                result.Append('"');
                backslashes = 0;
                continue;
            }

            result.Append('\\', backslashes);
            result.Append(character);
            backslashes = 0;
        }

        result.Append('\\', backslashes * 2);
        result.Append('"');
        return result.ToString();
    }

    public static int Run(string[] arguments)
    {
        if (arguments == null || arguments.Length == 0 || String.IsNullOrWhiteSpace(arguments[0]))
        {
            throw new ArgumentException("The configured process argument vector is empty.");
        }

        var job = CreateJobObject(IntPtr.Zero, null);
        if (job == IntPtr.Zero)
        {
            throw LastError("CreateJobObject failed.");
        }

        ProcessInformation process = new ProcessInformation();
        var processCreated = false;
        var processResumed = false;
        try
        {
            var limits = new ExtendedLimitInformation();
            limits.BasicLimitInformation.LimitFlags = JobObjectLimitKillOnJobClose;
            var size = Marshal.SizeOf(typeof(ExtendedLimitInformation));
            var memory = Marshal.AllocHGlobal(size);
            try
            {
                Marshal.StructureToPtr(limits, memory, false);
                if (!SetInformationJobObject(job, 9, memory, (uint)size))
                {
                    throw LastError("SetInformationJobObject failed.");
                }
            }
            finally
            {
                Marshal.FreeHGlobal(memory);
            }

            var quotedArguments = new string[arguments.Length];
            for (var index = 0; index < arguments.Length; index++)
            {
                quotedArguments[index] = Quote(arguments[index]);
            }

            var commandLine = new StringBuilder(String.Join(" ", quotedArguments));
            var startup = new StartupInfo();
            startup.cb = Marshal.SizeOf(typeof(StartupInfo));
            startup.dwFlags = StartfUseStdHandles;
            startup.hStdInput = GetStdHandle(-10);
            startup.hStdOutput = GetStdHandle(-11);
            startup.hStdError = GetStdHandle(-12);

            if (!CreateProcess(
                null, commandLine, IntPtr.Zero, IntPtr.Zero, true,
                CreateSuspended | CreateNoWindow, IntPtr.Zero, Environment.CurrentDirectory,
                ref startup, out process))
            {
                throw LastError("CreateProcess failed.");
            }

            processCreated = true;
            if (!AssignProcessToJobObject(job, process.hProcess))
            {
                throw LastError("AssignProcessToJobObject failed; the suspended process was not started.");
            }

            if (ResumeThread(process.hThread) == 0xFFFFFFFF)
            {
                throw LastError("ResumeThread failed.");
            }

            processResumed = true;
            if (WaitForSingleObject(process.hProcess, Infinite) != WaitObject0)
            {
                throw LastError("Waiting for the configured process failed.");
            }

            uint exitCode;
            if (!GetExitCodeProcess(process.hProcess, out exitCode))
            {
                throw LastError("GetExitCodeProcess failed.");
            }

            return unchecked((int)exitCode);
        }
        finally
        {
            if (processCreated && !processResumed && process.hProcess != IntPtr.Zero)
            {
                TerminateProcess(process.hProcess, 125);
            }

            if (process.hThread != IntPtr.Zero)
            {
                CloseHandle(process.hThread);
            }

            if (process.hProcess != IntPtr.Zero)
            {
                CloseHandle(process.hProcess);
            }

            // KILL_ON_JOB_CLOSE also cleans up background descendants after
            // the configured parent process exits normally.
            CloseHandle(job);
        }
    }
}
'@

    Add-Type -TypeDefinition $source -Language CSharp
    $arguments = [string[]](ConvertFrom-Json ([Text.Encoding]::UTF8.GetString(
        [Convert]::FromBase64String($ArgumentsBase64)
    )))
    exit [PhpMakeJobRunner]::Run($arguments)
}
catch {
    [Console]::Error.WriteLine($_.Exception.ToString())
    exit 125
}
