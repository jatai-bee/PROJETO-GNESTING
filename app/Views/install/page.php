<?php
/**
 * Tela do instalador (public/instalar.php). Independente do View/Kernel: roda sem .env.
 *
 * @var string $step requirements | form | done | installed
 * @var list<array{label: string, ok: bool, critical: bool, detail: string}> $requirements
 * @var array<string, string> $data
 * @var array<string, string> $errors
 * @var list<string> $log
 * @var string|null $failure
 * @var string|null $cronUrl
 * @var string $token
 */
use GNesting\Helpers\ZipCode;

$field = static function (string $name, string $label, array $opts = []) use ($data, $errors): string {
    $type = $opts['type'] ?? 'text';
    $id = 'f-' . $name;
    $error = $errors[$name] ?? null;
    $html = '<div class="i-field' . ($error ? ' i-field--error' : '') . '">'
        . '<label for="' . $id . '">' . e($label) . '</label>'
        . '<input id="' . $id . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($data[$name] ?? '') . '"'
        . (($opts['required'] ?? true) ? ' required' : '')
        . (isset($opts['autocomplete']) ? ' autocomplete="' . e($opts['autocomplete']) . '"' : '')
        . (isset($opts['placeholder']) ? ' placeholder="' . e($opts['placeholder']) . '"' : '')
        . (isset($opts['inputmode']) ? ' inputmode="' . e($opts['inputmode']) . '"' : '')
        . ($error ? ' aria-invalid="true" aria-describedby="' . $id . '-erro"' : '') . '>';
    if (isset($opts['hint'])) {
        $html .= '<small class="i-hint">' . e($opts['hint']) . '</small>';
    }
    if ($error) {
        $html .= '<small class="i-error" id="' . $id . '-erro">' . e($error) . '</small>';
    }

    return $html . '</div>';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Instalação | G-Nesting</title>
    <link rel="icon" href="assets/img/logo-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/tokens.css">
    <link rel="stylesheet" href="assets/css/install.css">
</head>
<body class="install">
<main class="i-wrap">
    <header class="i-head">
        <img src="assets/img/logo.svg" alt="G-Nesting" width="190" height="35">
        <p class="i-tag">Instalação da loja</p>
    </header>

<?php if ($step === 'installed'): ?>
    <section class="i-card">
        <h1>A loja já está instalada</h1>
        <p>Por segurança, este assistente não funciona mais. Para entrar no painel, use o endereço <code>/admin</code>.</p>
        <p class="i-muted">Reinstalar apaga a configuração atual: só faça isso de propósito, apagando o arquivo
            <code>storage/installed.lock</code> e as tabelas do banco pelo cPanel.</p>
        <p><a class="i-btn" href="admin">Ir para o painel</a></p>
    </section>

<?php elseif ($step === 'done'): ?>
    <section class="i-card">
        <h1>Pronto! A loja foi instalada</h1>
        <ul class="i-log">
            <?php foreach ($log as $line): ?><li><?= e($line) ?></li><?php endforeach ?>
        </ul>
        <h2>Próximos passos</h2>
        <ol class="i-steps">
            <li><strong>Entre no painel</strong> com o e-mail e a senha que você acabou de cadastrar.</li>
            <li><strong>Sistema</strong>: confira a lista de verificação e faça o primeiro backup.</li>
            <li><strong>Tarefas automáticas</strong> (cancelar pedidos não pagos, backup diário). No cPanel → <em>Cron Jobs</em>, a cada 15 minutos:
                <code class="i-code">php <?= e(dirname(__DIR__, 3)) ?>/bin/cron.php</code>
                Se a hospedagem não tiver <em>Cron Jobs</em>, cadastre este endereço num serviço gratuito como cron-job.org, a cada 15 minutos:
                <code class="i-code"><?= e((string) $cronUrl) ?></code>
                <small class="i-muted">Guarde esse endereço: ele também está no arquivo .env (CRON_TOKEN).</small></li>
            <li><strong>Configurações</strong> e cadastro do catálogo: veja o guia de instalação e uso.</li>
        </ol>
        <p><a class="i-btn" href="admin">Ir para o painel</a></p>
    </section>

<?php else: ?>
    <section class="i-card">
        <h1>1. Conferência da hospedagem</h1>
        <ul class="i-reqs">
            <?php foreach ($requirements as $item): ?>
                <li class="<?= $item['ok'] ? 'ok' : 'fail' ?>">
                    <span class="i-mark" aria-hidden="true"><?= $item['ok'] ? '✓' : '✕' ?></span>
                    <span><?= e($item['label']) ?><?php if (!$item['ok']): ?> <small>— <?= e($item['detail']) ?></small><?php endif ?></span>
                </li>
            <?php endforeach ?>
        </ul>
        <?php if ($step === 'requirements'): ?>
            <p class="i-alert">Corrija os itens marcados com ✕ no cPanel e recarregue esta página.</p>
        <?php endif ?>
    </section>

    <?php if ($step === 'form'): ?>
    <form method="post" action="instalar.php" class="i-card" novalidate>
        <input type="hidden" name="_token" value="<?= e($token) ?>">
        <?php if ($failure !== null): ?>
            <p class="i-alert" role="alert"><?= e($failure) ?></p>
        <?php elseif ($errors !== []): ?>
            <p class="i-alert" role="alert">Confira os campos marcados abaixo.</p>
        <?php endif ?>

        <h1>2. Dados da loja</h1>
        <fieldset>
            <legend>Endereço</legend>
            <?= $field('app_url', 'Endereço da loja', ['type' => 'url', 'hint' => 'Com https:// e sem barra no fim. Já vem preenchido com o endereço desta página.']) ?>
            <div class="i-field<?= isset($errors['shipping_origin_state']) ? ' i-field--error' : '' ?>">
                <label for="f-uf">UF de onde os pedidos saem</label>
                <select id="f-uf" name="shipping_origin_state">
                    <?php foreach (ZipCode::states() as $uf): ?>
                        <option value="<?= e($uf) ?>"<?= $data['shipping_origin_state'] === $uf ? ' selected' : '' ?>><?= e($uf) ?></option>
                    <?php endforeach ?>
                </select>
                <small class="i-hint">Usada no cálculo do frete (a tabela pode ser ajustada depois pelo painel).</small>
            </div>
        </fieldset>

        <fieldset>
            <legend>Banco de dados <small>(cPanel → Bancos de dados MySQL)</small></legend>
            <div class="i-grid">
                <?= $field('db_host', 'Servidor', ['hint' => 'Na hospedagem: localhost']) ?>
                <?= $field('db_port', 'Porta', ['inputmode' => 'numeric']) ?>
            </div>
            <?= $field('db_database', 'Nome do banco', ['placeholder' => 'usuario_gnesting']) ?>
            <div class="i-grid">
                <?= $field('db_username', 'Usuário do banco', ['autocomplete' => 'off']) ?>
                <?= $field('db_password', 'Senha do banco', ['type' => 'password', 'required' => false, 'autocomplete' => 'new-password']) ?>
            </div>
            <small class="i-hint">O usuário precisa ter <strong>todos os privilégios</strong> no banco: é ele que cria e atualiza as tabelas.</small>
        </fieldset>

        <fieldset>
            <legend>Seu acesso ao painel <small>(proprietário)</small></legend>
            <?= $field('admin_name', 'Seu nome', ['autocomplete' => 'name']) ?>
            <?= $field('admin_email', 'Seu e-mail', ['type' => 'email', 'autocomplete' => 'email', 'hint' => 'Também recebe os alertas de erro da loja.']) ?>
            <div class="i-grid">
                <?= $field('admin_password', 'Senha', ['type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Mínimo de ' . \GNesting\Install\Installer::MIN_ADMIN_PASSWORD . ' caracteres.']) ?>
                <?= $field('admin_password_confirmation', 'Repita a senha', ['type' => 'password', 'autocomplete' => 'new-password']) ?>
            </div>
        </fieldset>

        <fieldset>
            <legend>E-mail da loja <small>(opcional agora)</small></legend>
            <p class="i-hint">Conta de e-mail do domínio, criada em cPanel → Contas de e-mail. Em branco, a loja usa o envio padrão da hospedagem.</p>
            <div class="i-grid">
                <?= $field('mail_host', 'Servidor SMTP', ['required' => false, 'placeholder' => 'mail.gnesting.com.br']) ?>
                <?= $field('mail_port', 'Porta', ['required' => false, 'inputmode' => 'numeric', 'hint' => '587 ou 465']) ?>
            </div>
            <div class="i-grid">
                <?= $field('mail_username', 'Usuário (e-mail)', ['type' => 'email', 'required' => false, 'autocomplete' => 'off', 'placeholder' => 'loja@gnesting.com.br']) ?>
                <?= $field('mail_password', 'Senha do e-mail', ['type' => 'password', 'required' => false, 'autocomplete' => 'new-password']) ?>
            </div>
        </fieldset>

        <fieldset>
            <legend>Mercado Pago <small>(opcional agora)</small></legend>
            <p class="i-hint">Suas integrações → Credenciais e Webhooks. Sem isso a loja abre, mas não recebe pagamentos: preencha depois no arquivo .env.</p>
            <?= $field('mp_access_token', 'Access Token', ['type' => 'password', 'required' => false, 'autocomplete' => 'off']) ?>
            <?= $field('mp_webhook_secret', 'Assinatura secreta do webhook', ['type' => 'password', 'required' => false, 'autocomplete' => 'off']) ?>
        </fieldset>

        <?php if (!\GNesting\Install\Installer::isProductionUrl($data['app_url'])): ?>
        <fieldset>
            <legend>Teste no computador</legend>
            <label class="i-check"><input type="checkbox" name="sample_data" value="1"<?= $data['sample_data'] === '1' ? ' checked' : '' ?>>
                Instalar produtos e categorias de exemplo</label>
        </fieldset>
        <?php endif ?>

        <p class="i-muted">Ao instalar: o arquivo .env é gravado com chaves novas, as tabelas são criadas e seu acesso é cadastrado.
            Leva menos de um minuto. Depois disso, este assistente se desliga.</p>
        <button type="submit" class="i-btn">Instalar a loja</button>
    </form>
    <?php endif ?>
<?php endif ?>
</main>
</body>
</html>
