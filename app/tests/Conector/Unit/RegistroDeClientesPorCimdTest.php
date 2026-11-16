<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Repository\ClienteDoConectorRepository;
use App\Conector\Service\RegistroDeClientesPorCimd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O esqueleto do commit 0: só o caminho do cache válido. A busca do documento e as listas do servidor são do bloco C.
 */
#[CoversClass(RegistroDeClientesPorCimd::class)]
final class RegistroDeClientesPorCimdTest extends TestCase
{
    use CenarioDoConector;

    private const URL = 'https://claude.ai/oauth/claude-code-client-metadata';
    private const RETORNO = 'http://127.0.0.1/callback';

    private function sut(?ClienteDoConector $gravado): RegistroDeClientesPorCimd
    {
        $clientes = $this->createStub(ClienteDoConectorRepository::class);
        $clientes->method('buscarPorIdentificador')->willReturn($gravado);

        return new RegistroDeClientesPorCimd($clientes, new MockClock(self::em('2026-11-16 12:00:00')));
    }

    private static function gravado(string $validoAte = '2026-11-16 13:00:00'): ClienteDoConector
    {
        $cliente = self::clienteCimd(self::URL);
        $cliente->renovarMetadados('Claude Code', [self::RETORNO], self::em('2026-11-16 11:00:00'), self::em($validoAte));

        return $cliente;
    }

    private function recusa(RegistroDeClientesPorCimd $sut, string $redirect, string $motivo): void
    {
        try {
            $sut->resolver(self::URL, $redirect);
            self::fail('aceitou: ' . $motivo);
        } catch (ClienteRecusado $e) {
            self::assertSame($motivo, $e->motivo);
            self::assertSame('claude.ai', $e->host);
        }
    }

    #[TestDox('Cliente gravado, ativo, com metadados no prazo e o redirect no documento: devolve o cliente')]
    public function testCacheValido(): void
    {
        $cliente = self::gravado();

        self::assertSame($cliente, $this->sut($cliente)->resolver(self::URL, self::RETORNO));
    }

    #[TestDox('Sem cliente gravado ou com metadados vencidos (inclusive no instante exato): recusa, por enquanto sem buscar')]
    public function testSemCacheRecusa(): void
    {
        $this->recusa($this->sut(null), self::RETORNO, ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL);
        $this->recusa($this->sut(self::gravado('2026-11-16 12:00:00')), self::RETORNO, ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL);
        $this->recusa($this->sut(self::clienteCimd(self::URL)), self::RETORNO, ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL);
    }

    #[TestDox('Cliente desativado e redirect fora do documento (mesmo parecido) são recusados')]
    public function testDesativadoERedirect(): void
    {
        $desativado = self::gravado();
        $desativado->desativar();
        $this->recusa($this->sut($desativado), self::RETORNO, ClienteRecusado::MOTIVO_CLIENTE_INATIVO);

        $this->recusa($this->sut(self::gravado()), self::RETORNO . '/', ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO);
        $this->recusa($this->sut(self::gravado()), 'https://evil.example/callback', ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO);
    }
}
