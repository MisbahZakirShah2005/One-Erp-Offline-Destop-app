!include "LogicLib.nsh"

!macro customInstall
  DetailPrint "Installing/repairing Microsoft Visual C++ Runtime (x64)..."
  File /oname=$PLUGINSDIR\VC_redist.x64.exe "${BUILD_RESOURCES_DIR}\VC_redist.x64.exe"
  ExecWait '"$PLUGINSDIR\VC_redist.x64.exe" /install /quiet /norestart' $0
  DetailPrint "VC++ x64 exit code: $0"

  ${If} $0 == 3010
    SetRebootFlag true
  ${ElseIf} $0 != 0
  ${AndIf} $0 != 1638
    MessageBox MB_ICONSTOP|MB_OK "ONEERP prerequisite installation failed.$\r$\n$\r$\nMicrosoft Visual C++ x64 Runtime exit code: $0$\r$\n$\r$\nONEERP setup will stop so the app is not installed in a broken state."
    Abort
  ${EndIf}

  DetailPrint "Installing/repairing Microsoft Visual C++ Runtime (x86)..."
  File /oname=$PLUGINSDIR\VC_redist.x86.exe "${BUILD_RESOURCES_DIR}\VC_redist.x86.exe"
  ExecWait '"$PLUGINSDIR\VC_redist.x86.exe" /install /quiet /norestart' $1
  DetailPrint "VC++ x86 exit code: $1"

  ${If} $1 == 3010
    SetRebootFlag true
  ${ElseIf} $1 != 0
  ${AndIf} $1 != 1638
    MessageBox MB_ICONSTOP|MB_OK "ONEERP prerequisite installation failed.$\r$\n$\r$\nMicrosoft Visual C++ x86 Runtime exit code: $1$\r$\n$\r$\nONEERP setup will stop so the app is not installed in a broken state."
    Abort
  ${EndIf}
!macroend
