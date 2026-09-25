<?php
/**
 * E-mail de redefinição de senha (TEXTO SIMPLES — sem e()).
 * @var string $link
 * @var int    $minutes
 * @var bool   $admin
 */
?>
Olá!

Recebemos um pedido para redefinir a senha da sua conta<?= $admin ? ' no painel' : '' ?> G-Nesting.
Para criar uma nova senha, abra o link abaixo (válido por <?= $minutes ?> minutos e uma única vez):

<?= $link . "\n" ?>

Se não foi você, ignore este e-mail: sua senha atual continua valendo.

G-Nesting — Objetos que transformam espaços.
