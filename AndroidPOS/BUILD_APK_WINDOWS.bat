@echo off
setlocal
if "%ANDROID_HOME%"=="" (
  echo ANDROID_HOME belum diatur. Buka proyek melalui Android Studio atau atur ANDROID_HOME terlebih dahulu.
  pause
  exit /b 1
)
call gradlew.bat clean assembleDebug
if errorlevel 1 (
  echo BUILD GAGAL.
  pause
  exit /b 1
)
echo APK tersedia di app\build\outputs\apk\debug\app-debug.apk
pause
