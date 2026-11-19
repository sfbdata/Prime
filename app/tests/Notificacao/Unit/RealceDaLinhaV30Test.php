<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Realce da linha do "Guia visual BlueJus" v30 (pedido A2 de 19/11) na lista de notificações e na tabela da
 * plataforma: fundo #2b7ab0 com texto branco, sem pintar de branco os botões, menus e selos que têm cor própria.
 * Que aparece certo na tela é do smoke do dono.
 */
final class RealceDaLinhaV30Test extends TestCase
{
    private function template(string $caminho): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../templates/' . $caminho);
    }

    #[TestDox('Notificações: linha azul no hover, lida ou não lida; etiqueta retangular')]
    public function testNotificacoes(): void
    {
        $twig = $this->template('notificacao/index.html.twig');
        self::assertStringContainsString('.notif-page .notif-row:hover, .notif-page .notif-row.notif-unread:hover { background: #2b7ab0; }', $twig);
        self::assertStringContainsString(':not(.btn):not(.btn *)', $twig, 'botão de marcar como lida mantém a cor');
        self::assertStringNotContainsString('.notif-page .notif-row:hover { background: var(--bs-tertiary-bg); }', $twig);
        self::assertStringContainsString('padding: .22rem .55rem; border-radius: 4px;', $twig);
        self::assertDoesNotMatchRegularExpression('/border-radius:\s*999px/', $twig, 'contador das abas também retangular');
        self::assertStringContainsString('.notif-page .notif-row.notif-unread:hover::before { background: #fff; }', $twig);
    }

    #[TestDox('Plataforma: linha azul no hover sem tocar botões, menus e selos; filtros e selos retangulares')]
    public function testPlataforma(): void
    {
        $twig = $this->template('admin/platform/_base_platform.html.twig');
        self::assertStringContainsString('.platform-table > tbody > tr:hover > * { background: #2b7ab0; color: #fff !important; }', $twig, 'td com text-secondary também fica branco');
        self::assertStringContainsString(':not(.dropdown-menu):not(.dropdown-menu *):not(.badge):not(.badge *) { color: #fff !important; }', $twig);
        self::assertDoesNotMatchRegularExpression('/border-radius:\s*999px/', $twig);
        self::assertStringNotContainsString('rounded-pill', $this->template('admin/platform/oab.html.twig'));
        self::assertStringNotContainsString('rounded-pill', $this->template('_oab_status.html.twig'), 'chip de status da OAB');
    }
}
