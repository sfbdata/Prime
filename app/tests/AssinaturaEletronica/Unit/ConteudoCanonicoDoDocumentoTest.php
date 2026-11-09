<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Service\ConteudoCanonicoDoDocumento;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O JSON canônico do conteúdo enviado (contrato da etapa 2, seção 4.2) com valor fixo: chaves ordenadas em todos os
 * níveis, sem espaços, UTF-8 sem escape. O `hash_enviado` é o sha256 dele.
 */
#[CoversClass(ConteudoCanonicoDoDocumento::class)]
final class ConteudoCanonicoDoDocumentoTest extends TestCase
{
    private const ESPERADO = '{"arquivo":null,"blocos":[{"i":0,"k":"a","r":"Contratante"},{"full":0,"k":"t","x":"Cláusula 1/2"}],'
        . '"emOrdem":true,"folhaBranca":false,"formato":1,"lateral":null,"layout":"","logo":{"sha256":null,"tipo":"bluejus"},'
        . '"mensagem":"Leia com atenção.","nome":"Contrato de honorários","prazoDias":10,"signatarios":['
        . '{"cpf":"52998224725","email":"ana@exemplo.com","nivel":"simples","nome":"Ana Lima","ordem":0,"papel":"Contratante"},'
        . '{"cpf":null,"email":null,"nivel":"simples","nome":"José Souza","ordem":1,"papel":""}]}';

    /** sha256 do JSON acima, calculado fora do PHP. */
    private const HASH = '0b737f37177370084d00b84beca529997120788b772c886e5f89499be7aa0d73';

    #[TestDox('JSON canônico com valor fixo: chaves ordenadas, sem espaços, acentos sem escape')]
    public function testJsonComValorFixo(): void
    {
        $documento = new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato de honorários',
            'mensagem' => 'Leia com atenção.',
            'emOrdem' => true,
            'prazoDias' => 10,
            'signatarios' => [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'cpf' => '52998224725', 'papel' => 'Contratante'],
                ['nome' => 'José Souza'],
            ],
        ]), [['k' => 'a', 'i' => 0, 'r' => 'Contratante'], ['k' => 't', 'x' => 'Cláusula 1/2', 'full' => 0]], new \DateTimeImmutable('2026-11-09 09:00:00'));

        $json = ConteudoCanonicoDoDocumento::json($documento, null, null);

        self::assertSame(self::ESPERADO, $json);
        self::assertSame(self::HASH, hash('sha256', $json));
        self::assertStringContainsString('"logo":{"sha256":"ab12","tipo":"bluejus"}', ConteudoCanonicoDoDocumento::json($documento, 'ab12', null));
    }
}
