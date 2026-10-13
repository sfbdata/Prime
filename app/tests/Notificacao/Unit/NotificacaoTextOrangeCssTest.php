<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * C6-FPN-02: `text-orange` não é utilitário do Bootstrap. A regra do app.css casava só
 * `.notif-icon.text-orange` (dropdown do sino), e a página /notificacoes, cujo <i> mora em
 * `.notif-ico`, ficava sem o laranja do alerta de meta. A cor em si só o smoke confirma; aqui
 * se prova que a regra é geral, tem o par do tema escuro e vem depois do `.notif-icon` (mesma
 * especificidade: quem vem depois vence o azul dele no dropdown).
 */
#[Group('notificacao')]
final class NotificacaoTextOrangeCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/app.css';

    private function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS));
    }

    #[TestDox('.text-orange vale fora do .notif-icon, com o par do tema escuro')]
    public function testRegraGeral(): void
    {
        $css = $this->css();
        self::assertMatchesRegularExpression('/^\.text-orange \{ color: #e8590c; \}$/m', $css);
        self::assertMatchesRegularExpression('/^html\[data-bs-theme="dark"\] \.text-orange \{ color: #ff8c4a; \}$/m', $css);
    }

    #[TestDox('a regra geral vem depois do .notif-icon, para vencer o azul dele no dropdown')]
    public function testDepoisDoNotifIcon(): void
    {
        $css       = $this->css();
        $notifIcon = strpos($css, "\n.notif-icon  {");
        $laranja   = strpos($css, "\n.text-orange {");
        self::assertNotFalse($notifIcon);
        self::assertNotFalse($laranja);
        self::assertGreaterThan($notifIcon, $laranja);
    }
}
