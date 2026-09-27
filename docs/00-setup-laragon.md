# 00 — Ambiente local (Laragon, Windows)

## 1. Instalar

1. **Laragon Full** (https://laragon.org/download): inclui Apache, MySQL, PHP e Composer.
   - PHP **8.2 ou superior** (testado com 8.3.30). Menu Laragon → PHP → versão.
   - Extensões necessárias (menu PHP → Extensions): `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `openssl`, `intl`.
   - MySQL testado: 8.4.3.
2. **Git for Windows** (https://git-scm.com/download/win).

> No terminal do Laragon (botão "Terminal") `php`, `composer` e `mysql` já estão no PATH. Em outro terminal, use os caminhos completos, por exemplo `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`.

## 2. Configurar o projeto

> **Atalho pelo navegador (etapa 13):** depois do `composer install`, crie o banco vazio no HeidiSQL/phpMyAdmin do Laragon,
> rode `composer serve` e abra http://localhost:8000: sem `.env`, a loja leva ao assistente `instalar.php`, o mesmo da
> hospedagem, com a opção de instalar produtos de exemplo. Os passos abaixo fazem o mesmo pelo terminal.

```bash
cd "D:\agnaldo.orrico\Documents\PROJETO GNESTING"
composer install
copy .env.example .env
php bin/generate-key.php
```

No `.env`, o padrão do Laragon é usuário `root` sem senha.

## 3. Banco de dados

```bash
composer migrate -- --create-db --seed
php database/migrate.php --status
```

Isso cria o banco `gnesting`, aplica `database/migrations/*.sql` e os seeds (categorias e produto de referência).

## 4. Primeiro administrador

```bash
php bin/create-admin.php --email=voce@gnesting.com.br --name="Seu Nome" --role=owner
```

A senha é pedida no terminal (mínimo de 12 caracteres). Pressione Enter sem digitar para gerar uma senha forte, que é exibida uma única vez.

## 5. Rodar

**Opção A: servidor embutido (mais simples)**

```bash
composer serve
```

Acesse http://localhost:8000 (loja) e http://localhost:8000/admin (painel).

**Opção B: Apache do Laragon (igual à hospedagem)**

Crie um link da pasta do projeto dentro de `C:\laragon\www` (PowerShell):

```powershell
New-Item -ItemType Junction -Path C:\laragon\www\gnesting -Target "D:\agnaldo.orrico\Documents\PROJETO GNESTING"
```

Menu Laragon → **Reload Apache** e acesse `http://gnesting.test`. O `.htaccess` da raiz redireciona para `/public` e bloqueia as pastas internas, como na hospedagem compartilhada.

## 6. Testes

```bash
composer test
```

Os testes de integração usam o banco `gnesting_test` (variável `DB_TEST_DATABASE`), que é **apagado e recriado** a cada execução. Nunca aponte essa variável para o banco principal: o bootstrap dos testes recusa essa configuração.

## 7. Git (primeiro commit)

```bash
git init
git add .
git commit -m "Etapas 1 e 2: arquitetura e fundação"
```

O `.gitignore` já exclui `.env`, `vendor/`, logs, sessões e uploads.
