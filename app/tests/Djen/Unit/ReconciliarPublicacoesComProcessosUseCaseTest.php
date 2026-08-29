<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\UseCase\ReconciliarPublicacoesComProcessosUseCase;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Religa à FK do Processo as publicações que ficaram avulsas porque o processo entrou no cadastro
 * DEPOIS da captura. Em produção eram 8, todas com essa mesma história.
 *
 * O que a aba da pasta mostra não depende disto (lá o casamento é por número); o que depende é a
 * tela do módulo, que hoje carimba "Avulsa" nessas 8 — um rótulo que não é verdade.
 */
#[CoversClass(ReconciliarPublicacoesComProcessosUseCase::class)]
#[Group('djen')]
final class ReconciliarPublicacoesComProcessosUseCaseTest extends TestCase
{
    #[TestDox('Liga a publicação avulsa ao processo cujo número casa e grava uma vez só')]
    public function testLigaEGrava(): void
    {
        $tenant   = new Tenant();
        $processo = new Processo();
        $processo->setNumeroProcesso('07011111111111111111');
        $avulsa = $this->publicacao('07011111111111111111');

        $publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $publicacoes->method('listarAvulsasDoTenant')->willReturn([$avulsa]);
        $publicacoes->expects(self::once())->method('flush');

        $processos = $this->createMock(ProcessoRepository::class);
        $processos->method('findPorNumerosDoTenant')->willReturn(['07011111111111111111' => $processo]);

        $religadas = (new ReconciliarPublicacoesComProcessosUseCase($publicacoes, $processos))->executar($tenant);

        self::assertSame(1, $religadas);
        self::assertSame($processo, $avulsa->getProcesso());
    }

    #[TestDox('Simulação conta o que faria e NÃO grava nem toca a entidade')]
    public function testSimulacaoNaoGrava(): void
    {
        $tenant   = new Tenant();
        $processo = new Processo();
        $processo->setNumeroProcesso('07011111111111111111');
        $avulsa = $this->publicacao('07011111111111111111');

        $publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $publicacoes->method('listarAvulsasDoTenant')->willReturn([$avulsa]);
        $publicacoes->expects(self::never())->method('flush');

        $processos = $this->createMock(ProcessoRepository::class);
        $processos->method('findPorNumerosDoTenant')->willReturn(['07011111111111111111' => $processo]);

        $religadas = (new ReconciliarPublicacoesComProcessosUseCase($publicacoes, $processos))->executar($tenant, simular: true);

        self::assertSame(1, $religadas);
        self::assertNull($avulsa->getProcesso(), 'simulação não pode deixar a entidade suja para o próximo flush');
    }

    #[TestDox('Publicação sem processo correspondente continua avulsa — a maioria dos 161 casos de produção')]
    public function testSemProcessoCorrespondenteFicaComoEsta(): void
    {
        $avulsa = $this->publicacao('07099999999999999999');

        $publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $publicacoes->method('listarAvulsasDoTenant')->willReturn([$avulsa]);
        $publicacoes->expects(self::never())->method('flush');

        $processos = $this->createMock(ProcessoRepository::class);
        $processos->method('findPorNumerosDoTenant')->willReturn([]);

        $religadas = (new ReconciliarPublicacoesComProcessosUseCase($publicacoes, $processos))->executar(new Tenant());

        self::assertSame(0, $religadas);
        self::assertNull($avulsa->getProcesso());
    }

    #[TestDox('Sem avulsa nenhuma não consulta processo nem grava — rodar de novo é barato e inócuo')]
    public function testIdempotenteQuandoNaoHaAvulsas(): void
    {
        $publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $publicacoes->method('listarAvulsasDoTenant')->willReturn([]);
        $publicacoes->expects(self::never())->method('flush');

        $processos = $this->createMock(ProcessoRepository::class);
        $processos->expects(self::never())->method('findPorNumerosDoTenant');

        $religadas = (new ReconciliarPublicacoesComProcessosUseCase($publicacoes, $processos))->executar(new Tenant());

        self::assertSame(0, $religadas);
    }

    #[TestDox('Publicação sem número de processo é ignorada, sem virar chave vazia na busca')]
    public function testIgnoraPublicacaoSemNumero(): void
    {
        $semNumero = $this->publicacao('');

        $publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $publicacoes->method('listarAvulsasDoTenant')->willReturn([$semNumero]);
        $publicacoes->expects(self::never())->method('flush');

        $processos = $this->createMock(ProcessoRepository::class);
        $processos->expects(self::never())->method('findPorNumerosDoTenant');

        self::assertSame(0, (new ReconciliarPublicacoesComProcessosUseCase($publicacoes, $processos))->executar(new Tenant()));
    }

    private function publicacao(string $numero): PublicacaoDjen
    {
        $pub = new PublicacaoDjen();
        $pub->setNumeroProcesso($numero);

        return $pub;
    }
}
