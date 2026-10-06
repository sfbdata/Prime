<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArquivoGeradoParaEntrega;
use App\Shared\Http\EntregaDeArquivoGerado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A entrega do arquivo GERADO: download (attachment) com o tipo pedido, em stream — e o arquivo
 * some depois de enviado. É a política oposta da `EntregaDeArquivo` (que nunca apaga), e o tipo do
 * parâmetro é o que garante que um documento persistido nunca passa por aqui.
 */
#[CoversClass(EntregaDeArquivoGerado::class)]
final class EntregaDeArquivoGeradoTest extends TestCase
{
    #[TestDox('responde attachment com o nome e o Content-Type pedidos, envia os bytes e APAGA o arquivo depois do envio')]
    public function testEntregaEApaga(): void
    {
        $area   = AreaTemporariaPrivada::criar('testeentrega');
        $gerado = ArquivoGeradoParaEntrega::retirarDa($area, $area->gravar('PK zip falso', 'zip'), 'testeentrega');
        $area->liberar();

        $resposta = (new EntregaDeArquivoGerado())->resposta($gerado, 'documentos-DOC-1.zip', 'application/zip');

        self::assertSame(200, $resposta->getStatusCode());
        self::assertSame('application/zip', $resposta->headers->get('Content-Type'));
        self::assertSame('attachment; filename=documentos-DOC-1.zip', $resposta->headers->get('Content-Disposition'));

        ob_start();
        try {
            $resposta->sendContent();
        } finally {
            $enviado = (string) ob_get_clean();
        }

        self::assertSame('PK zip falso', $enviado);
        self::assertFileDoesNotExist($gerado->caminho(), 'deleteFileAfterSend: o gerado some depois de enviado');
    }

    #[TestDox('só aceita ArquivoGeradoParaEntrega — não há como passar o caminho de um documento persistido')]
    public function testSoAceitaArquivoGerado(): void
    {
        $parametro = (new \ReflectionMethod(EntregaDeArquivoGerado::class, 'resposta'))->getParameters()[0];

        self::assertSame(ArquivoGeradoParaEntrega::class, (string) $parametro->getType());
    }
}
