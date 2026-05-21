@echo off
echo ==========================================
echo   PUBLISH VERS XAMPP EN COURS...
echo ==========================================

SET SOURCE=%~dp0
SET DEST=C:\xampp\htdocs\gestion-stock

echo Copie des fichiers depuis %SOURCE%
echo Vers %DEST%
echo.

xcopy "%SOURCE%gestion_stock_atelier.html" "%DEST%" /Y /I
xcopy "%SOURCE%app.js" "%DEST%" /Y /I
xcopy "%SOURCE%styles.css" "%DEST%" /Y /I

if exist "%SOURCE%api" (
    echo Copie du dossier API...
    xcopy "%SOURCE%api" "%DEST%\api" /E /Y /I
)

if exist "%SOURCE%assets" (
    echo Copie du dossier Assets...
    xcopy "%SOURCE%assets" "%DEST%\assets" /E /Y /I
)

echo.
echo ==========================================
echo   PUBLISH TERMINE AVEC SUCCES !
echo ==========================================
echo Tu peux maintenant rafraichir ta page :
echo http://127.0.0.1/gestion-stock/gestion_stock_atelier.html
echo ==========================================
pause
