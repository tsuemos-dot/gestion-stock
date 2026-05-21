@echo off
echo ==========================================
echo   PUBLICATION VERS XAMPP (GESTION STOCK)
echo ==========================================
echo.

REM Définir le dossier de destination XAMPP
set "DEST=C:\xampp\htdocs\gestion-stock"

REM Vérifier si le dossier de destination existe
if not exist "%DEST%" (
    echo [ERREUR] Le dossier %DEST% n'existe pas !
    echo Créez-le ou vérifiez le chemin.
    pause
    exit /b 1
)

echo [INFO] Copie des fichiers vers %DEST%...
echo.

REM Copier les fichiers principaux avec affichage détaillé
echo -> Copie de gestion_stock_atelier.html
copy /Y "gestion_stock_atelier.html" "%DEST%\gestion_stock_atelier.html" > nul
if %errorlevel% neq 0 echo [ECHEC] gestion_stock_atelier.html

echo -> Copie de app.js
copy /Y "app.js" "%DEST%\app.js" > nul
if %errorlevel% neq 0 echo [ECHEC] app.js

echo -> Copie de styles.css
copy /Y "styles.css" "%DEST%\styles.css" > nul
if %errorlevel% neq 0 echo [ECHEC] styles.css

echo -> Copie du dossier api/
xcopy /E /I /Y "api" "%DEST%\api" > nul
if %errorlevel% neq 0 echo [ECHEC] dossier api

echo -> Copie du dossier assets/
xcopy /E /I /Y "assets" "%DEST%\assets" > nul
if %errorlevel% neq 0 echo [ECHEC] dossier assets

echo.
echo ==========================================
echo   PUBLICATION TERMINEE AVEC SUCCES !
echo ==========================================
echo.
echo Vos modifications sont maintenant actives sur :
echo http://127.0.0.1/gestion-stock/gestion_stock_atelier.html
echo.
echo N'oubliez pas d'actualiser votre navigateur (F5 ou Ctrl+F5)
echo.
pause
