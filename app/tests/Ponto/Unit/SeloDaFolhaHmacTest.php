<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\SeloDaFolhaHmac;
use App\Ponto\Service\SeloDoComprovanteHmac;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O selo da folha emitida e do aceite (E16, decisão 14; seção 10 da spec): HMAC-SHA256 com a chave da finalidade
 * `ponto-folha`, derivada pelas chaves da E9. Confere só com a versão gravada; sem o segredo, nada sela nem confere.
 */
#[Group('ponto')]
#[CoversClass(SeloDaFolhaHmac::class)]
final class SeloDaFolhaHmacTest extends TestCase
{
    private const DADOS = '{"formato":1,"codigo":"BJF-ABCD-EFGH-JK23"}';

    #[TestDox('Sela com a chave "ponto-folha" da versão atual (1), diferente da chave do comprovante e da trilha')]
    public function testSelaComAChaveDaFolha(): void
    {
        $selado = $this->selo('segredo-de-teste')->selar(self::DADOS);

        $chave = hash_hkdf('sha256', 'segredo-de-teste', 32, 'ponto-folha');
        self::assertSame(['hmac' => hash_hmac('sha256', self::DADOS, $chave), 'versao' => 1], $selado);
        self::assertSame('ponto-folha', SeloDaFolhaHmac::FINALIDADE);
        $doComprovante = (new SeloDoComprovanteHmac(new ChavesDoHmacDoPonto('segredo-de-teste')))->selar(self::DADOS);
        self::assertNotSame($doComprovante['hmac'], $selado['hmac'], 'a chave do comprovante não sela a folha');
        $daTrilha = hash_hkdf('sha256', 'segredo-de-teste', 32, ChavesDoHmacDoPonto::FINALIDADE_TRILHA);
        self::assertNotSame(hash_hmac('sha256', self::DADOS, $daTrilha), $selado['hmac'], 'nem a da trilha');
    }

    #[TestDox('Confere com a versão gravada; versão desconhecida, outro segredo, dado ou HMAC trocados: não confere')]
    public function testConfereSoComAVersaoGravada(): void
    {
        $selo = $this->selo('segredo-de-teste');
        $selado = $selo->selar(self::DADOS);

        self::assertTrue($selo->conferir(self::DADOS, $selado['hmac'], 1));
        self::assertFalse($selo->conferir(self::DADOS, $selado['hmac'], 2), 'versão que o servidor não conhece');
        self::assertFalse($selo->conferir(self::DADOS, $selado['hmac'], 0));
        self::assertFalse($this->selo('outro-segredo')->conferir(self::DADOS, $selado['hmac'], 1), 'outro segredo');
        self::assertFalse($selo->conferir(str_replace('JK23', 'JK24', self::DADOS), $selado['hmac'], 1), 'dado trocado depois do selo');
        self::assertFalse($selo->conferir(self::DADOS, str_repeat('0', 64), 1), 'HMAC trocado');
    }

    #[TestDox('O selo do comprovante não confere com o da folha, nem o da folha com o do comprovante')]
    public function testNaoConfereComOSeloDoComprovante(): void
    {
        $chaves = new ChavesDoHmacDoPonto('segredo-de-teste');
        $doComprovante = (new SeloDoComprovanteHmac($chaves))->selar(self::DADOS);
        $daFolha = (new SeloDaFolhaHmac($chaves))->selar(self::DADOS);

        self::assertFalse((new SeloDaFolhaHmac($chaves))->conferir(self::DADOS, $doComprovante['hmac'], 1));
        self::assertFalse((new SeloDoComprovanteHmac($chaves))->conferir(self::DADOS, $daFolha['hmac'], 1));
    }

    #[TestDox('Sem o segredo (vazio ou ausente): indisponível, nada confere (nem o que foi selado com ele) e selar recusa')]
    public function testSemSegredo(): void
    {
        $selado = $this->selo('segredo-de-teste')->selar(self::DADOS);

        foreach ([null, ''] as $segredo) {
            $indisponivel = $this->selo($segredo);
            self::assertFalse($indisponivel->disponivel());
            self::assertFalse($indisponivel->conferir(self::DADOS, $selado['hmac'], 1));
        }
        self::assertTrue($this->selo('segredo-de-teste')->disponivel());

        $this->expectException(\LogicException::class);
        $this->selo(null)->selar(self::DADOS);
    }

    private function selo(?string $segredo): SeloDaFolhaHmac
    {
        return new SeloDaFolhaHmac(new ChavesDoHmacDoPonto($segredo));
    }
}
