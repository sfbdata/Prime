<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\Service\NormalizadorDePastaJudicial;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorClienteDoResponsavel;
use App\Cliente\Repository\ClientePFRepository;
use App\Cobranca\UseCase\CorrigirPastaJudicialDuplicadaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Pasta\UseCase\GerarNumeroDePasta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorrigirPastaJudicialDuplicadaUseCase::class)]
final class CorrigirPastaJudicialDuplicadaUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private PastaRepository&MockObject $pastaRepository;
    private EntityManagerInterface&MockObject $em;
    private CorrigirPastaJudicialDuplicadaUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $this->pastaRepository = $this->createMock(PastaRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        // wrapInTransaction real (não mockado): executa o callback direto, como o real faz.
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $cb) => $cb());

        $comporNome = new ComporNomeDaPastaJudicial();
        $this->sut = new CorrigirPastaJudicialDuplicadaUseCase(
            $this->casoRepository,
            $this->eventoRepository,
            $this->pastaRepository,
            new CriarPastaUseCase($this->em, new GerarNumeroDePasta($this->createMock(NumeracaoDePastaInterface::class))),
            new NormalizadorDePastaJudicial($comporNome, new ResolvedorClienteDoResponsavel($this->createMock(ClientePFRepository::class))),
            new RegistrarEventoHistorico($this->eventoRepository),
            $this->em,
        );
        $this->tenant = new Tenant();
    }

    private function caso(int $id, string $unidade, string $pessoaNome): CasoCobranca
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        (new \ReflectionProperty($caso, 'id'))->setValue($caso, $id);

        $objeto = new ObjetoCobranca();
        $objeto->setIdentificacao($unidade);
        $ref = new \ReflectionProperty($caso, 'objeto');
        $ref->setAccessible(true);
        $ref->setValue($caso, $objeto);

        $pessoa = new Pessoa();
        $pessoa->setNome($pessoaNome);
        $caso->setPessoaCobradaAtual($pessoa);

        return $caso;
    }

    private function eventoVinculo(CasoCobranca $caso, \DateTimeImmutable $quando): EventoHistorico
    {
        $evento = new EventoHistorico();
        $evento->setTenant($this->tenant);
        $evento->setCaso($caso);
        $evento->setTipo(TipoEventoHistorico::VinculoPasta);
        $evento->setDescricao('Vínculo com a pasta X.');
        $evento->setOcorridoEm($quando);

        return $evento;
    }

    #[Test]
    public function preverNaoEscreveNadaEIdentificaOVencedorPeloVinculoMaisAntigo(): void
    {
        $pastaCompartilhada = (new Pasta())->setTenant($this->tenant);
        (new \ReflectionProperty($pastaCompartilhada, 'id'))->setValue($pastaCompartilhada, 1221);

        // Caso 20 vinculou-se PRIMEIRO (01/09), caso 19 vinculou-se DEPOIS (02/09) — vence o 20,
        // mesmo que o array venha em outra ordem (o UseCase não pode confiar na ordem de entrada).
        $caso19 = $this->caso(19, '03-08', 'ABINADABE ALMEIDA DE SOUSA');
        $caso19->setPastaJudicial($pastaCompartilhada);
        $caso20 = $this->caso(20, '03-09', 'ABINADABE ALMEIDA DE SOUSA');
        $caso20->setPastaJudicial($pastaCompartilhada);

        $this->casoRepository->method('pastasJudiciaisDuplicadas')->willReturn([[$caso19, $caso20]]);
        $this->eventoRepository->method('doCaso')->willReturnMap([
            [$caso19, [$this->eventoVinculo($caso19, new \DateTimeImmutable('2026-09-02 11:22:08'))]],
            [$caso20, [$this->eventoVinculo($caso20, new \DateTimeImmutable('2026-09-01 11:05:41'))]],
        ]);
        $this->pastaRepository->method('buscarParaVinculo')->willReturn([]);
        $this->casoRepository->method('pastaIdsJudicializadosDoTenant')->willReturn([1221]);

        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');
        $this->em->expects($this->never())->method('flush');

        $resultado = $this->sut->prever($this->tenant);

        self::assertFalse($resultado->aplicou);
        self::assertCount(1, $resultado->itens);
        $item = $resultado->itens[0];
        self::assertSame(20, $item['casoVencedorId'], 'vence quem se vinculou primeiro (evento), não quem tem o maior id');
        self::assertSame(19, $item['casoCorrigidoId']);
        self::assertSame('03-08', $item['casoCorrigidoUnidade']);
        self::assertFalse($item['reaproveitouOrfa']);
    }

    #[Test]
    public function confirmarCorrigeOPerdedorComPastaNovaComUnidadeNoNome(): void
    {
        $pastaCompartilhada = (new Pasta())->setTenant($this->tenant);
        (new \ReflectionProperty($pastaCompartilhada, 'id'))->setValue($pastaCompartilhada, 1221);

        $caso19 = $this->caso(19, '03-08', 'ABINADABE ALMEIDA DE SOUSA');
        $caso19->setPastaJudicial($pastaCompartilhada);
        $caso20 = $this->caso(20, '03-09', 'ABINADABE ALMEIDA DE SOUSA');
        $caso20->setPastaJudicial($pastaCompartilhada);

        $this->casoRepository->method('pastasJudiciaisDuplicadas')->willReturn([[$caso19, $caso20]]);
        $this->eventoRepository->method('doCaso')->willReturnMap([
            [$caso19, [$this->eventoVinculo($caso19, new \DateTimeImmutable('2026-09-02 11:22:08'))]],
            [$caso20, [$this->eventoVinculo($caso20, new \DateTimeImmutable('2026-09-01 11:05:41'))]],
        ]);
        // Sem pasta órfã disponível — o retrato real dos 2 casos medidos em produção em 08/09.
        $this->pastaRepository->method('buscarParaVinculo')->willReturn([]);
        $this->casoRepository->method('pastaIdsJudicializadosDoTenant')->willReturn([1221]);

        $this->casoRepository->expects($this->once())->method('salvar')->with($caso19);
        $this->eventoRepository->expects($this->once())->method('salvar');
        // 2x: uma dentro de CriarPastaUseCase::executar() (persiste a pasta nova) e uma no fecho do
        // processar() — as duas cabem na mesma transação (wrapInTransaction não commita a cada flush).
        $this->em->expects($this->exactly(2))->method('flush');

        $usuario = new User();
        $resultado = $this->sut->confirmar($this->tenant, $usuario);

        self::assertTrue($resultado->aplicou);
        $pastaNova = $caso19->getPastaJudicial();
        self::assertNotSame($pastaCompartilhada, $pastaNova, 'o perdedor ganha uma pasta DIFERENTE da compartilhada');
        self::assertSame($pastaCompartilhada, $caso20->getPastaJudicial(), 'o vencedor não é tocado');
    }
}
