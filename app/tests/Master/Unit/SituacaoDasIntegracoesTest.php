<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\SituacaoDasIntegracoes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SituacaoDasIntegracoes::class)]
final class SituacaoDasIntegracoesTest extends TestCase
{
    /** @return array<string, bool> */
    private function conectadas(string $mailer, string $googleLogin, string $googleAgenda, string $facebook, string $djen, string $ia): array
    {
        $lista = (new SituacaoDasIntegracoes($mailer, $googleLogin, $googleAgenda, $facebook, $djen, $ia))->situacao();

        return array_column($lista, 'conectada', 'chave');
    }

    #[TestDox('sem nenhuma credencial: só os tribunais (API pública do DJEN) aparecem conectados, e o e-mail null:// não conta')]
    public function testSemCredenciais(): void
    {
        $c = $this->conectadas('null://null', '', '', '', 'https://comunicaapi.pje.jus.br', '');

        self::assertSame(['email' => false, 'banco_pix' => false, 'google' => false, 'facebook' => false, 'tribunais' => true, 'ia' => false], $c);
    }

    #[TestDox('credencial presente liga a integração; o Google vale com a chave do login OU da agenda')]
    public function testComCredenciais(): void
    {
        $c = $this->conectadas('smtp://u:p@smtp.exemplo.com:587', '', 'client-agenda', 'app-id', 'https://x', 'sk-ant-1');

        self::assertSame(['email' => true, 'banco_pix' => false, 'google' => true, 'facebook' => true, 'tribunais' => true, 'ia' => true], $c);
    }

    #[TestDox('a ordem é a do cartão do desenho')]
    public function testOrdem(): void
    {
        $chaves = array_column((new SituacaoDasIntegracoes('', '', '', '', '', ''))->situacao(), 'chave');

        self::assertSame(['email', 'banco_pix', 'google', 'facebook', 'tribunais', 'ia'], $chaves);
    }
}
