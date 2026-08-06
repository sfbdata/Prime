<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\RomperAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Exception\AcordoComParcelasRenegociadasException;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\RestauradorObrigacoesOriginais;
use App\Cobranca\UseCase\RomperAcordoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RomperAcordoUseCase::class)]
final class RomperAcordoUseCaseTest extends TestCase
{
    private AcordoRepository&MockObject $acordoRepository;
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private RomperAcordoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->acordoRepository = $this->createMock(AcordoRepository::class);
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        // O serviço é final: usa-se o REAL com o repositório de eventos mockado (flush único).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        // Idem: o restaurador é final e não tem dependência externa além do repositório já mockado.
        $restaurador = new RestauradorObrigacoesOriginais($this->obrigacaoRepository);
        $this->sut = new RomperAcordoUseCase($this->acordoRepository, $this->obrigacaoRepository, $restaurador, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    #[TestDox('§D5: romper solta e DESCONGELA a original — ela volta ao saldo com os juros correndo')]
    public function soltaEDescongelaAsObrigacoesOriginais(): void
    {
        // Mesmo defeito que o dono viu no cancelamento: a original volta ao exigível por derivação, mas
        // CONGELADA ela é pulada por `EncargosVivos::hidratar` e os juros ficam parados.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $original = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso)->setValorOriginal(17000);
        $original->setAcordoSubstituto($acordo);
        $original->congelarEncargos(new \DateTimeImmutable('2026-07-21 21:04:26'));

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->obrigacaoRepository->method('parcelasRenegociadasPorAcordoVigente')->willReturn([]);
        $this->obrigacaoRepository->method('substituidasPorAcordo')->willReturn([$original]);

        $input = new RomperAcordoInput();
        $input->acordoId = 85;
        $input->motivo = 'Devedor descumpriu';

        $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertFalse($original->encargosCongelados(), 'A original tem de voltar a crescer ao vivo.');
        // O vínculo PERMANECE: a original já volta ao saldo por derivação (`doCasoExigiveis` inclui a
        // substituída por acordo não vigente), e é ele que mantém o histórico do rompimento legível —
        // "este acordo substituiu N obrigações". Apagá-lo não traria benefício nenhum.
        self::assertSame($acordo, $original->getAcordoSubstituto(), 'O vínculo com o acordo rompido permanece.');
    }

    #[Test]
    #[TestDox('Romper NÃO apaga nada: o acordo e as parcelas continuam, como histórico')]
    public function romperNaoApagaAcordoNemParcelas(): void
    {
        // Nenhum dos dois apaga do banco. A diferença é de VISIBILIDADE: o rompido continua acessível
        // em "Acordos encerrados" (aconteceu de verdade e foi descumprido); o cancelado some da tela e
        // a rota dele dá 404, sobrando só a linha do histórico.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $parcela = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso)->setValorOriginal(2366);
        $parcela->setAcordoOrigem($acordo);
        $acordo->getParcelas()->add($parcela);

        $this->obrigacaoRepository->method('substituidasPorAcordo')->willReturn([]);
        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->obrigacaoRepository->method('parcelasRenegociadasPorAcordoVigente')->willReturn([]);

        $this->acordoRepository->expects($this->never())->method('remover');
        $this->obrigacaoRepository->expects($this->never())->method('remover');
        $this->acordoRepository->expects($this->once())->method('salvar')->with($acordo);

        $input = new RomperAcordoInput();
        $input->acordoId = 85;
        $input->motivo = 'Devedor descumpriu';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(StatusAcordo::Rompido, $resultado->getStatus());
        self::assertCount(1, $acordo->getParcelas(), 'A parcela continua existindo (invariável 14).');
        self::assertSame($acordo, $parcela->getAcordoOrigem());
    }

    #[Test]
    #[TestDox('Ajuste 9: romper acordo cujas parcelas outro acordo vigente renegociou é recusado')]
    public function recusaRomperAcordoComParcelasRenegociadas(): void
    {
        // Acordo sobre acordo — o estado que o importador CRIA desde 08/2026, com a prova da coluna F
        // da planilha (spec `cobranca-acordo-assume-parcelas-do-anterior.md`). Romper A aqui faria a
        // dívida entrar DUAS vezes no saldo: as originais que A substituiu voltam ao exigível E as parcelas
        // de B continuam nele (§2.1). Este guard é o alarme.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordoA = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $acordoB = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $parcelaRenegociada = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $parcelaRenegociada->setAcordoOrigem($acordoA)->setAcordoSubstituto($acordoB);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordoA);
        $this->obrigacaoRepository
            ->method('parcelasRenegociadasPorAcordoVigente')
            ->with($acordoA)
            ->willReturn([$parcelaRenegociada]);
        // Recusa ANTES de qualquer efeito: o acordo não muda de status e nada é persistido.
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new RomperAcordoInput();
        $input->acordoId = 80;
        $input->motivo = 'Devedor descumpriu';

        try {
            $this->sut->executar($input, $this->tenant, $this->usuario);
            self::fail('Deveria ter recusado o rompimento.');
        } catch (AcordoComParcelasRenegociadasException) {
            self::assertSame(StatusAcordo::Ativo, $acordoA->getStatus(), 'O acordo não pode mudar de status.');
        }
    }

    #[Test]
    #[TestDox('Acordo sem parcelas renegociadas rompe normalmente (o caminho de todo dado novo)')]
    public function rompeNormalmenteQuandoNenhumaParcelaFoiRenegociada(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->obrigacaoRepository->method('parcelasRenegociadasPorAcordoVigente')->willReturn([]);

        $input = new RomperAcordoInput();
        $input->acordoId = 80;
        $input->motivo = 'Devedor descumpriu';

        self::assertSame(StatusAcordo::Rompido, $this->sut->executar($input, $this->tenant, $this->usuario)->getStatus());
    }

    #[Test]
    public function rompeAcordoAtivoRegistrandoMotivoEEvento(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);

        $this->acordoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(80, $this->tenant)
            ->willReturn($acordo);

        // Acordo persistido sem flush; o evento fecha a transação com flush: true.
        $this->acordoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(Acordo::class));
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new RomperAcordoInput();
        $input->acordoId = 80;
        $input->motivo = '  Devedor descumpriu as parcelas  ';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($acordo, $resultado);
        self::assertSame(StatusAcordo::Rompido, $resultado->getStatus());
        self::assertFalse($resultado->estaAtivo());
        // Motivo normalizado (trim) e registrado no acordo (só muda status + motivo, sem reverter saldo).
        self::assertSame('Devedor descumpriu as parcelas', $resultado->getMotivoRompimento());
    }

    #[Test]
    public function rejeitaAcordoNaoEncontrado(): void
    {
        // Acordo inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoEncontradoException::class);

        $input = new RomperAcordoInput();
        $input->acordoId = 999;
        $input->motivo = 'Qualquer motivo';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaAcordoNaoAtivo(): void
    {
        // Acordo já cumprido não transiciona (só um acordo ativo rompe).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $acordo->marcarCumprido();

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoAtivoException::class);

        $input = new RomperAcordoInput();
        $input->acordoId = 80;
        $input->motivo = 'Tentando romper um cumprido';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
