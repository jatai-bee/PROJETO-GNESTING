@echo off
rem ------------------------------------------------------------------
rem G-Nesting - gera o pacote .zip para enviar ao cPanel (docs/17 secao 2).
rem Dois cliques neste arquivo. O pacote sai do ULTIMO COMMIT do Git e e
rem copiado para a pasta PACOTE-CPANEL, ao lado da pasta do projeto.
rem Precisa de: PHP 8.2+ (Laragon ou tools\php83), Composer e Git.
rem Opcional: gerar-pacote.cmd /silencioso  (sem abrir o Explorer nem pausar no fim)
rem ------------------------------------------------------------------
setlocal EnableExtensions EnableDelayedExpansion
cd /d "%~dp0"

echo.
echo  ==================================================
echo   G-Nesting - gerar o pacote para o cPanel
echo  ==================================================
echo.

rem --- PHP: PATH, depois Laragon, depois %USERPROFILE%\tools\php83
set "PHP="
for %%P in (php.exe) do if not "%%~$PATH:P"=="" set "PHP=%%~$PATH:P"
if not defined PHP for /d %%D in ("C:\laragon\bin\php\php-8*") do if exist "%%~D\php.exe" set "PHP=%%~D\php.exe"
if not defined PHP if exist "%USERPROFILE%\tools\php83\php.exe" set "PHP=%USERPROFILE%\tools\php83\php.exe"
if not defined PHP (
    echo  [ERRO] PHP nao encontrado. Instale o Laragon: https://laragon.org/download
    goto fim
)
echo  PHP:      %PHP%

rem --- Composer: PATH, depois Laragon, depois %USERPROFILE%\tools\composer
set "COMPOSER_PHAR="
where composer >nul 2>nul
if errorlevel 1 (
    if exist "C:\laragon\bin\composer\composer.phar" set "COMPOSER_PHAR=C:\laragon\bin\composer\composer.phar"
    if exist "%USERPROFILE%\tools\composer\composer.phar" set "COMPOSER_PHAR=%USERPROFILE%\tools\composer\composer.phar"
)
if defined COMPOSER_PHAR (echo  Composer: !COMPOSER_PHAR!) else (echo  Composer: comando composer do PATH)

rem --- Git (o pacote sai do ultimo commit)
where git >nul 2>nul
if errorlevel 1 (
    echo  [ERRO] Git nao encontrado. Instale: https://git-scm.com/download/win
    goto fim
)
echo.
echo  Atencao: o pacote leva o ULTIMO COMMIT. Alteracoes nao commitadas ficam de fora.
echo.

"%PHP%" bin\build-release.php
if errorlevel 1 (
    echo.
    echo  [ERRO] O pacote nao foi gerado. Leia a mensagem acima.
    goto fim
)

rem --- Copia o pacote mais novo (e o LEIA-ME) para ..\PACOTE-CPANEL
set "DEST=%~dp0..\PACOTE-CPANEL"
if not exist "%DEST%" mkdir "%DEST%"
set "ZIP="
for /f "delims=" %%Z in ('dir /b /o-d "build\gnesting-*.zip"') do if not defined ZIP set "ZIP=%%Z"
copy /y "build\!ZIP!" "%DEST%\" >nul
"%PHP%" -r "$z = new ZipArchive(); $z->open($argv[1]); file_put_contents($argv[2], (string) $z->getFromName('LEIA-ME-INSTALACAO.txt'));" "build\!ZIP!" "%DEST%\LEIA-ME.txt"

echo.
echo  Pronto: %DEST%\!ZIP!
echo  Envie esse .zip pelo Gerenciador de Arquivos do cPanel e siga o guia de instalacao.
if /i not "%~1"=="/silencioso" explorer "%DEST%"

:fim
echo.
if /i not "%~1"=="/silencioso" pause
