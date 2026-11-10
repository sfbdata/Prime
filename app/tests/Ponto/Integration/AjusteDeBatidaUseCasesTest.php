<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\DTO\AjustarBatidaInput;
use App\Ponto\DTO\DesconsiderarBatidaInput;
use App\Ponto\DTO\IncluirBatidaInput;
use App\Ponto\DTO\RestaurarBatidaInput;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Enum\TipoJustificativa;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\PontoAjusteRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\HorarioFuturoDaBatida;
use App\Ponto\Service\TrilhaDoPonto;
use App\Ponto\UseCase\AjustarBatidaUseCase;
use App\Ponto\UseCase\DesconsiderarBatidaUseCase;
use App\Ponto\UseCase\IncluirBatidaUseCase;
use App\Ponto\UseCase\RestaurarBatidaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Os quatro UseCases do ajuste de batida (E10, plano-e10-ponto §6), no banco: a trilha e a gravação são classes
 * finais e o que importa é o que fica gravado (ou não). Cada recusa é conferida ANTES de escrever: nenhuma linha em
 * `ponto_ajuste` nem na trilha. Cobre a cabeça esperada (`mudou`), o alvo de outra folha (`naoEncontrada`), o P43 nas
 * quatro ações (a própria batida, só o primário ou o Master), a decisão A (a desconsideração da análise não se
 * restaura pela tela) e o caminho `pelaAnalise`, que não confere a cabeça (contrato da E12).
 *
 * O relógio fica parado em 22/10/2026 12:00.
 */
#[Group('ponto')]
#[CoversClass(IncluirBatidaUseCase::class)]
#[CoversClass(AjustarBatidaUseCase::class)]
#[CoversClass(DesconsiderarBatidaUseCase::class)]
#[CoversClass(RestaurarBatidaUseCase::class)]
#[CoversClass(DesconsiderarBatidaInput::class)]
final class AjusteDeBatidaUseCasesTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private RegistroPontoRepository $registros;
    private TrilhaDoPonto $trilha;
    private GravacaoDoAjusteDoPonto $gravacao;
    private AnaliseDaPropriaJustificativa $analiseDaPropria;
    private Tenant $escritorio;
    private User $pessoa;
    private User $gestora;
    /** @var array{int, int} ajustes e eventos gravados antes do teste (o banco de teste pode não estar vazio) */
    private array $noInicio;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-10-22 12:00:00', new \DateTimeZone(date_default_timezone_get())));
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->registros = $c->get(RegistroPontoRepository::class);
        $this->trilha = $c->get(TrilhaDoPonto::class);
        $this->gravacao = new GravacaoDoAjusteDoPonto($this->em, $this->trilha, $c->get(HorarioFuturoDaBatida::class), $c->get(ClockInterface::class));
        $this->analiseDaPropria = $c->get(AnaliseDaPropriaJustificativa::class);

        $this->escritorio = $this->novoEscritorio();
        $this->pessoa = $this->usuario('Ana Colaboradora');
        $this->gestora = $this->usuario('Gestora do Ajuste');
        $this->vincular($this->pessoa, $this->escritorio, primario: false);
        $this->vincular($this->gestora, $this->escritorio, primario: true);
        $this->noInicio = [$this->quantosAjustes(), $this->quantosEventos()];
    }

    // ------------------------------------------------------------------------------------------------- Incluir

    #[TestDox('Incluir: a batida entra como inclusão (alvo a{id}), com o motivo e o evento batida_incluida; nada entra em registro_ponto')]
    public function testIncluir(): void
    {
        $inclusao = $this->incluir()->executar(new IncluirBatidaInput(
            $this->escritorio,
            $this->pessoa,
            'saida',
            new \DateTimeImmutable('2026-10-21 18:00:00'),
            'Esqueceu de bater a saída.',
            $this->gestora,
        ));

        self::assertSame(TipoDoAjuste::Inclusao, $inclusao->getTipo());
        self::assertSame('a' . $inclusao->getId(), $inclusao->alvo());
        self::assertSame(['saida', '2026-10-21 18:00:00'], [$inclusao->getTipoMarcacaoNova(), $inclusao->getDataHoraNova()?->format('Y-m-d H:i:s')]);
        self::assertSame('batida_incluida', $this->ultimaAcao());
        self::assertSame(0, $this->quantosRegistros(), 'a inclusão não grava em registro_ponto');
    }

    #[TestDox('Incluir a própria batida, sem ser primário nem Master, é recusado (P43, decisão B) e nada é gravado')]
    public function testIncluirAPropriaSemSerPrimario(): void
    {
        $motivo = $this->motivoDaRecusa(fn () => $this->incluir()->executar(new IncluirBatidaInput(
            $this->escritorio,
            $this->pessoa,
            'saida',
            new \DateTimeImmutable('2026-10-21 18:00:00'),
            'Corrigindo a minha.',
            $this->pessoa,
        )));

        self::assertSame(AjusteDeBatidaRecusadoException::DA_PROPRIA_BATIDA, $motivo);
        $this->assertNadaGravado();
    }

    #[TestDox('O primário inclui a própria batida (P43)')]
    public function testPrimarioIncluiAPropria(): void
    {
        $inclusao = $this->incluir()->executar(new IncluirBatidaInput(
            $this->escritorio,
            $this->gestora,
            'entrada',
            new \DateTimeImmutable('2026-10-21 08:00:00'),
            'Esqueci de bater.',
            $this->gestora,
        ));

        self::assertSame($this->gestora, $inclusao->getUser());
    }

    #[TestDox('Incluir sem motivo é recusado e nada é gravado')]
    public function testIncluirSemMotivo(): void
    {
        $motivo = $this->motivoDaRecusa(fn () => $this->incluir()->executar(new IncluirBatidaInput(
            $this->escritorio,
            $this->pessoa,
            'saida',
            new \DateTimeImmutable('2026-10-21 18:00:00'),
            '   ',
            $this->gestora,
        )));

        self::assertSame(AjusteDeBatidaRecusadoException::SEM_MOTIVO, $motivo);
        $this->assertNadaGravado();
    }

    // ------------------------------------------------------------------------------------------------- Ajustar

    #[TestDox('Ajustar a batida nunca ajustada (cabeça esperada nula): a alteração entra presa ao registro, que não muda')]
    public function testAjustar(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');

        $alteracao = $this->ajustar('b' . $batida->getId(), null, 'saida', '2026-10-21 18:30:00');

        self::assertSame(TipoDoAjuste::Alteracao, $alteracao->getTipo());
        self::assertSame($batida, $alteracao->getRegistroPonto());
        self::assertSame('batida_ajustada', $this->ultimaAcao());
        self::assertSame('2026-10-21 18:00:00', $this->dataHoraGravada($batida), 'o registro original não muda');
    }

    #[TestDox('Ajustar de novo com a cabeça que a tela mostrou (o ajuste anterior) passa')]
    public function testAjustarDeNovoComACabecaCerta(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $primeira = $this->ajustar('b' . $batida->getId(), null, 'saida', '2026-10-21 18:30:00');

        $segunda = $this->ajustar('b' . $batida->getId(), $primeira->getId(), 'saida', '2026-10-21 18:45:00');

        self::assertSame($primeira, $segunda->getAnterior());
    }

    #[TestDox('Ajustar com a cabeça velha (outro ajuste entrou no meio) é recusado como "mudou" e nada é gravado')]
    public function testAjustarComACabecaVelha(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $this->ajustar('b' . $batida->getId(), null, 'saida', '2026-10-21 18:30:00');
        [$ajustes, $eventos] = [$this->quantosAjustes(), $this->quantosEventos()];

        $motivo = $this->motivoDaRecusa(fn () => $this->ajustar('b' . $batida->getId(), null, 'saida', '2026-10-21 19:00:00'));

        self::assertSame(AjusteDeBatidaRecusadoException::MUDOU, $motivo);
        self::assertSame([$ajustes, $eventos], [$this->quantosAjustes(), $this->quantosEventos()]);
    }

    #[TestDox('Ajustar a batida de outro escritório da mesma pessoa é "não encontrada" (multi-tenant) e nada é gravado')]
    public function testAjustarBatidaDeOutroEscritorio(): void
    {
        $outro = $this->novoEscritorio();
        $this->vincular($this->pessoa, $outro, primario: false);
        $deFora = $this->batida('saida', '2026-10-21 18:00:00', $outro);

        $motivo = $this->motivoDaRecusa(fn () => $this->ajustar('b' . $deFora->getId(), null, 'saida', '2026-10-21 18:30:00'));

        self::assertSame(AjusteDeBatidaRecusadoException::NAO_ENCONTRADA, $motivo);
        $this->assertNadaGravado();
    }

    #[TestDox('Ajustar a própria batida, sem ser primário nem Master, é recusado (P43) antes de ler a batida')]
    public function testAjustarAPropria(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');

        $motivo = $this->motivoDaRecusa(fn () => $this->ajustarUseCase()->executar(new AjustarBatidaInput(
            $this->escritorio,
            $this->pessoa,
            'b' . $batida->getId(),
            null,
            'saida',
            new \DateTimeImmutable('2026-10-21 18:30:00'),
            'Corrigindo a minha.',
            $this->pessoa,
        )));

        self::assertSame(AjusteDeBatidaRecusadoException::DA_PROPRIA_BATIDA, $motivo);
        $this->assertNadaGravado();
    }

    // ------------------------------------------------------------------------------------------- Desconsiderar

    #[TestDox('Desconsiderar pela tela com a cabeça certa: a desconsideração entra, sem justificativa, com o evento batida_desconsiderada')]
    public function testDesconsiderarPelaTela(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');

        $desconsideracao = $this->desconsiderar()->executar(DesconsiderarBatidaInput::daTela(
            $this->escritorio,
            $this->pessoa,
            'b' . $batida->getId(),
            null,
            'Batida em dobro.',
            $this->gestora,
        ));

        self::assertSame(TipoDoAjuste::Desconsideracao, $desconsideracao->getTipo());
        self::assertNull($desconsideracao->getJustificativa());
        self::assertTrue($this->alvo('b' . $batida->getId())->desconsiderada());
        self::assertSame('batida_desconsiderada', $this->ultimaAcao());
        self::assertSame('2026-10-21 18:00:00', $this->dataHoraGravada($batida), 'o registro original fica guardado');
    }

    #[TestDox('Desconsiderar pela tela com a cabeça velha é recusado como "mudou" e nada é gravado')]
    public function testDesconsiderarPelaTelaComACabecaVelha(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $this->ajustar('b' . $batida->getId(), null, 'saida', '2026-10-21 18:30:00');
        [$ajustes, $eventos] = [$this->quantosAjustes(), $this->quantosEventos()];

        $motivo = $this->motivoDaRecusa(fn () => $this->desconsiderar()->executar(DesconsiderarBatidaInput::daTela(
            $this->escritorio,
            $this->pessoa,
            'b' . $batida->getId(),
            null,
            'Batida em dobro.',
            $this->gestora,
        )));

        self::assertSame(AjusteDeBatidaRecusadoException::MUDOU, $motivo);
        self::assertSame([$ajustes, $eventos], [$this->quantosAjustes(), $this->quantosEventos()]);
    }

    #[TestDox('Desconsiderar pela análise não confere a cabeça (contrato da E12) e liga a desconsideração à justificativa')]
    public function testDesconsiderarPelaAnalise(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $this->ajustar('b' . $batida->getId(), null, 'saida', '2026-10-21 18:30:00');
        $justificativa = $this->justificativa();

        $desconsideracao = $this->desconsiderar()->executar(DesconsiderarBatidaInput::pelaAnalise(
            $this->escritorio,
            $this->pessoa,
            'b' . $batida->getId(),
            'Trabalho externo recusado.',
            $this->gestora,
            $justificativa,
        ));

        self::assertSame($justificativa, $desconsideracao->getJustificativa());
        self::assertTrue($this->alvo('b' . $batida->getId())->desconsiderada());
    }

    #[TestDox('Desconsiderar pela análise uma batida que não existe nesta folha é "não encontrada" (para a E12: nada a fazer)')]
    public function testDesconsiderarPelaAnaliseSemBatida(): void
    {
        $justificativa = $this->justificativa();

        $motivo = $this->motivoDaRecusa(fn () => $this->desconsiderar()->executar(DesconsiderarBatidaInput::pelaAnalise(
            $this->escritorio,
            $this->pessoa,
            'b999999999',
            'Trabalho externo recusado.',
            $this->gestora,
            $justificativa,
        )));

        self::assertSame(AjusteDeBatidaRecusadoException::NAO_ENCONTRADA, $motivo);
        $this->assertNadaGravado();
    }

    #[TestDox('Desconsiderar a própria batida, sem ser primário nem Master, é recusado (P43) e nada é gravado')]
    public function testDesconsiderarAPropria(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');

        $motivo = $this->motivoDaRecusa(fn () => $this->desconsiderar()->executar(DesconsiderarBatidaInput::daTela(
            $this->escritorio,
            $this->pessoa,
            'b' . $batida->getId(),
            null,
            'Batida em dobro.',
            $this->pessoa,
        )));

        self::assertSame(AjusteDeBatidaRecusadoException::DA_PROPRIA_BATIDA, $motivo);
        $this->assertNadaGravado();
    }

    // ----------------------------------------------------------------------------------------------- Restaurar

    #[TestDox('Restaurar a desconsideração da tela: a batida volta como estava, com o evento ajuste_desfeito')]
    public function testRestaurar(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $desconsideracao = $this->desconsiderarPelaTela($batida);

        $restauracao = $this->restaurar()->executar(new RestaurarBatidaInput($this->escritorio, $this->pessoa, (int) $desconsideracao->getId(), 'Era válida.', $this->gestora));

        self::assertSame(TipoDoAjuste::Restauracao, $restauracao->getTipo());
        self::assertSame($desconsideracao, $restauracao->getDesfaz());
        $alvo = $this->alvo('b' . $batida->getId());
        self::assertSame(['saida', '2026-10-21 18:00:00'], [$alvo->tipoAtual, $alvo->dataHoraAtual?->format('Y-m-d H:i:s')]);
        self::assertSame('ajuste_desfeito', $this->ultimaAcao());
    }

    #[TestDox('Decisão A: a desconsideração feita pela análise da justificativa não se restaura pela tela, e nada é gravado')]
    public function testRestaurarADaAnaliseERecusado(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $desconsideracao = $this->desconsiderar()->executar(DesconsiderarBatidaInput::pelaAnalise(
            $this->escritorio,
            $this->pessoa,
            'b' . $batida->getId(),
            'Trabalho externo recusado.',
            $this->gestora,
            $this->justificativa(),
        ));
        [$ajustes, $eventos] = [$this->quantosAjustes(), $this->quantosEventos()];

        $motivo = $this->motivoDaRecusa(fn () => $this->restaurar()->executar(new RestaurarBatidaInput($this->escritorio, $this->pessoa, (int) $desconsideracao->getId(), 'Volta.', $this->gestora)));

        self::assertSame(AjusteDeBatidaRecusadoException::LIGADA_A_JUSTIFICATIVA, $motivo);
        self::assertSame([$ajustes, $eventos], [$this->quantosAjustes(), $this->quantosEventos()]);
    }

    #[TestDox('Restaurar a desconsideração que já não é a cabeça (já restaurada) é recusado como "mudou"')]
    public function testRestaurarDuasVezes(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $desconsideracao = $this->desconsiderarPelaTela($batida);
        $input = new RestaurarBatidaInput($this->escritorio, $this->pessoa, (int) $desconsideracao->getId(), 'Era válida.', $this->gestora);
        $this->restaurar()->executar($input);
        [$ajustes, $eventos] = [$this->quantosAjustes(), $this->quantosEventos()];

        $motivo = $this->motivoDaRecusa(fn () => $this->restaurar()->executar($input));

        self::assertSame(AjusteDeBatidaRecusadoException::MUDOU, $motivo);
        self::assertSame([$ajustes, $eventos], [$this->quantosAjustes(), $this->quantosEventos()]);
    }

    #[TestDox('Restaurar pela folha de outro escritório, ou um ajuste que não é desconsideração, é "não encontrada"')]
    public function testRestaurarForaDaFolha(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $desconsideracao = $this->desconsiderarPelaTela($batida);
        $outro = $this->novoEscritorio();
        $this->vincular($this->pessoa, $outro, primario: false);
        $alteracao = $this->ajustar('b' . $this->batida('entrada', '2026-10-21 08:00:00')->getId(), null, 'entrada', '2026-10-21 08:05:00');
        [$ajustes, $eventos] = [$this->quantosAjustes(), $this->quantosEventos()];

        $deOutroEscritorio = $this->motivoDaRecusa(fn () => $this->restaurar()->executar(new RestaurarBatidaInput($outro, $this->pessoa, (int) $desconsideracao->getId(), 'Volta.', $this->gestora)));
        $deOutraPessoa = $this->motivoDaRecusa(fn () => $this->restaurar()->executar(new RestaurarBatidaInput($this->escritorio, $this->gestora, (int) $desconsideracao->getId(), 'Volta.', $this->gestora)));
        $naoEDesconsideracao = $this->motivoDaRecusa(fn () => $this->restaurar()->executar(new RestaurarBatidaInput($this->escritorio, $this->pessoa, (int) $alteracao->getId(), 'Volta.', $this->gestora)));

        self::assertSame(array_fill(0, 3, AjusteDeBatidaRecusadoException::NAO_ENCONTRADA), [$deOutroEscritorio, $deOutraPessoa, $naoEDesconsideracao]);
        self::assertSame([$ajustes, $eventos], [$this->quantosAjustes(), $this->quantosEventos()]);
    }

    #[TestDox('Restaurar a própria batida, sem ser primário nem Master, é recusado (P43) e nada é gravado')]
    public function testRestaurarAPropria(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $desconsideracao = $this->desconsiderarPelaTela($batida);
        [$ajustes, $eventos] = [$this->quantosAjustes(), $this->quantosEventos()];

        $motivo = $this->motivoDaRecusa(fn () => $this->restaurar()->executar(new RestaurarBatidaInput($this->escritorio, $this->pessoa, (int) $desconsideracao->getId(), 'Era minha.', $this->pessoa)));

        self::assertSame(AjusteDeBatidaRecusadoException::DA_PROPRIA_BATIDA, $motivo);
        self::assertSame([$ajustes, $eventos], [$this->quantosAjustes(), $this->quantosEventos()], 'nada gravado em ponto_ajuste nem na trilha');
        self::assertTrue($this->alvo('b' . $batida->getId())->desconsiderada(), 'a batida continua fora da conta');
    }

    // ------------------------------------------------------------------------ justificativa em análise (decisão C5)

    #[TestDox('C5: a batida de trabalho externo com a justificativa em análise não se ajusta nem se desconsidera pela tela, e nada é gravado')]
    public function testEmAnaliseRecusaAjustarEDesconsiderar(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $this->justificativaLigada($batida, 'pendente');
        $alvo = 'b' . $batida->getId();

        $ajustar = $this->motivoDaRecusa(fn () => $this->ajustar($alvo, null, 'saida', '2026-10-21 18:30:00'));
        $desconsiderar = $this->motivoDaRecusa(fn () => $this->desconsiderar()->executar(DesconsiderarBatidaInput::daTela($this->escritorio, $this->pessoa, $alvo, null, 'Batida em dobro.', $this->gestora)));

        self::assertSame([AjusteDeBatidaRecusadoException::EM_ANALISE, AjusteDeBatidaRecusadoException::EM_ANALISE], [$ajustar, $desconsiderar]);
        $this->assertNadaGravado();
        self::assertFalse($this->alvo($alvo)->desconsiderada(), 'a batida segue na conta');
    }

    #[TestDox('C5: a análise segue desconsiderando a batida da justificativa em análise (a recusa do trabalho externo), e o Restaurar pela tela recusa essa desconsideração')]
    public function testEmAnaliseQuemDecideEAAnalise(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $justificativa = $this->justificativaLigada($batida, 'pendente');

        $desconsideracao = $this->desconsiderar()->executar(DesconsiderarBatidaInput::pelaAnalise(
            $this->escritorio,
            $this->pessoa,
            'b' . $batida->getId(),
            'Trabalho externo recusado.',
            $this->gestora,
            $justificativa,
        ));

        self::assertTrue($this->alvo('b' . $batida->getId())->desconsiderada());
        $motivo = $this->motivoDaRecusa(fn () => $this->restaurar()->executar(new RestaurarBatidaInput($this->escritorio, $this->pessoa, (int) $desconsideracao->getId(), 'Volta.', $this->gestora)));
        self::assertSame(AjusteDeBatidaRecusadoException::LIGADA_A_JUSTIFICATIVA, $motivo);
    }

    /** @return iterable<string, array{string}> */
    public static function decididas(): iterable
    {
        yield 'abonada' => ['abonado'];
        yield 'recusada' => ['rejeitado'];
    }

    #[DataProvider('decididas')]
    #[TestDox('C5: decidida a justificativa ($_dataName), a ficha ajusta e desconsidera a batida como qualquer outra (P65)')]
    public function testDecididaAjustaEDesconsidera(string $status): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $this->justificativaLigada($batida, $status);
        $alvo = 'b' . $batida->getId();

        $alteracao = $this->ajustar($alvo, null, 'saida', '2026-10-21 18:30:00');
        $this->desconsiderar()->executar(DesconsiderarBatidaInput::daTela($this->escritorio, $this->pessoa, $alvo, $alteracao->getId(), 'Batida em dobro.', $this->gestora));

        self::assertTrue($this->alvo($alvo)->desconsiderada());
    }

    #[TestDox('C5: a inclusão ligada a um esquecimento em análise não se ajusta nem se desconsidera pela tela')]
    public function testInclusaoComJustificativaEmAnalise(): void
    {
        $inclusao = $this->inclusaoLigada('pendente');
        $alvo = 'a' . $inclusao->getId();
        $antes = [$this->quantosAjustes(), $this->quantosEventos()];

        $ajustar = $this->motivoDaRecusa(fn () => $this->ajustar($alvo, $inclusao->getId(), 'saida', '2026-10-21 18:30:00'));
        $desconsiderar = $this->motivoDaRecusa(fn () => $this->desconsiderar()->executar(DesconsiderarBatidaInput::daTela($this->escritorio, $this->pessoa, $alvo, $inclusao->getId(), 'Batida em dobro.', $this->gestora)));

        self::assertSame([AjusteDeBatidaRecusadoException::EM_ANALISE, AjusteDeBatidaRecusadoException::EM_ANALISE], [$ajustar, $desconsiderar]);
        self::assertSame($antes, [$this->quantosAjustes(), $this->quantosEventos()]);
    }

    #[TestDox('C5, limite 1: a inclusão do esquecimento abonado se ajusta e se desconsidera pela tela')]
    public function testInclusaoDoEsquecimentoAbonadoSeCorrige(): void
    {
        $inclusao = $this->inclusaoLigada('abonado');
        $alvo = 'a' . $inclusao->getId();

        $alteracao = $this->ajustar($alvo, $inclusao->getId(), 'saida', '2026-10-21 18:30:00');
        $this->desconsiderar()->executar(DesconsiderarBatidaInput::daTela($this->escritorio, $this->pessoa, $alvo, $alteracao->getId(), 'Batida em dobro.', $this->gestora));

        self::assertTrue($this->alvo($alvo)->desconsiderada());
    }

    #[TestDox('C5: a batida que o gestor desconsiderou não se restaura enquanto o trabalho externo dela está em análise, e nada é gravado')]
    public function testRestaurarComTrabalhoExternoEmAnalise(): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $desconsideracao = $this->desconsiderarPelaTela($batida);
        $this->justificativaLigada($batida, 'pendente');
        $antes = [$this->quantosAjustes(), $this->quantosEventos()];

        $motivo = $this->motivoDaRecusa(fn () => $this->restaurarPelaTela($desconsideracao));

        self::assertSame(AjusteDeBatidaRecusadoException::EM_ANALISE, $motivo);
        self::assertSame($antes, [$this->quantosAjustes(), $this->quantosEventos()]);
        self::assertTrue($this->alvo('b' . $batida->getId())->desconsiderada(), 'a batida segue fora da conta');
    }

    #[TestDox('C5: a inclusão que o gestor desconsiderou não se restaura enquanto o esquecimento dela voltou para análise, e nada é gravado')]
    public function testRestaurarInclusaoComEsquecimentoEmAnalise(): void
    {
        $inclusao = $this->inclusaoLigada('abonado');
        $alvo = 'a' . $inclusao->getId();
        $desconsideracao = $this->desconsiderar()->executar(DesconsiderarBatidaInput::daTela($this->escritorio, $this->pessoa, $alvo, $inclusao->getId(), 'Batida em dobro.', $this->gestora));
        $inclusao->getJustificativa()?->setStatus('pendente');
        $this->em->flush();
        $antes = [$this->quantosAjustes(), $this->quantosEventos()];

        $motivo = $this->motivoDaRecusa(fn () => $this->restaurarPelaTela($desconsideracao));

        self::assertSame(AjusteDeBatidaRecusadoException::EM_ANALISE, $motivo);
        self::assertSame($antes, [$this->quantosAjustes(), $this->quantosEventos()]);
        self::assertTrue($this->alvo($alvo)->desconsiderada(), 'a inclusão segue fora da conta');
    }

    #[DataProvider('decididas')]
    #[TestDox('C5: decidida a justificativa ($_dataName), o Restaurar devolve à conta a batida que o gestor desconsiderou')]
    public function testDecididaRestaura(string $status): void
    {
        $batida = $this->batida('saida', '2026-10-21 18:00:00');
        $desconsideracao = $this->desconsiderarPelaTela($batida);
        $this->justificativaLigada($batida, $status);

        $this->restaurarPelaTela($desconsideracao);

        self::assertFalse($this->alvo('b' . $batida->getId())->desconsiderada());
    }

    // ------------------------------------------------------------------------------------------------- apoio

    private function incluir(): IncluirBatidaUseCase
    {
        return new IncluirBatidaUseCase($this->trilha, $this->gravacao, $this->analiseDaPropria);
    }

    private function ajustarUseCase(): AjustarBatidaUseCase
    {
        return new AjustarBatidaUseCase($this->trilha, $this->gravacao, $this->registros, $this->analiseDaPropria, static::getContainer()->get(JustificativaPontoRepository::class));
    }

    private function desconsiderar(): DesconsiderarBatidaUseCase
    {
        return new DesconsiderarBatidaUseCase($this->trilha, $this->gravacao, $this->registros, $this->analiseDaPropria, static::getContainer()->get(JustificativaPontoRepository::class));
    }

    private function restaurar(): RestaurarBatidaUseCase
    {
        return new RestaurarBatidaUseCase($this->trilha, $this->gravacao, $this->registros, static::getContainer()->get(PontoAjusteRepository::class), $this->analiseDaPropria, static::getContainer()->get(JustificativaPontoRepository::class));
    }

    private function ajustar(string $alvo, ?int $cabeca, string $tipo, string $quando): PontoAjuste
    {
        return $this->ajustarUseCase()->executar(new AjustarBatidaInput($this->escritorio, $this->pessoa, $alvo, $cabeca, $tipo, new \DateTimeImmutable($quando), 'Correção.', $this->gestora));
    }

    private function desconsiderarPelaTela(RegistroPonto $batida): PontoAjuste
    {
        return $this->desconsiderar()->executar(DesconsiderarBatidaInput::daTela($this->escritorio, $this->pessoa, 'b' . $batida->getId(), null, 'Batida em dobro.', $this->gestora));
    }

    private function restaurarPelaTela(PontoAjuste $desconsideracao): PontoAjuste
    {
        return $this->restaurar()->executar(new RestaurarBatidaInput($this->escritorio, $this->pessoa, (int) $desconsideracao->getId(), 'Era válida.', $this->gestora));
    }

    private function alvo(string $alvo): AlvoDoAjuste
    {
        return $this->registros->alvoDoAjuste($this->pessoa, $this->escritorio, $alvo) ?? throw new \LogicException('Alvo não achado: ' . $alvo);
    }

    private function motivoDaRecusa(\Closure $operacao): string
    {
        try {
            $operacao();
        } catch (AjusteDeBatidaRecusadoException $recusa) {
            return $recusa->motivo;
        }
        self::fail('A operação não foi recusada.');
    }

    /** Nenhum ajuste nem evento novo desde o começo do teste, em escritório nenhum. */
    private function assertNadaGravado(): void
    {
        self::assertSame($this->noInicio, [$this->quantosAjustes(), $this->quantosEventos()], 'nada gravado em ponto_ajuste nem na trilha');
    }

    private function ultimaAcao(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT acao FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia DESC LIMIT 1', [$this->escritorio->getId()]);
    }

    private function quantosAjustes(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_ajuste');
    }

    private function quantosEventos(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_auditoria');
    }

    private function quantosRegistros(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM registro_ponto WHERE user_id = ?', [$this->pessoa->getId()]);
    }

    private function dataHoraGravada(RegistroPonto $batida): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT data_hora FROM registro_ponto WHERE id = ?', [$batida->getId()]);
    }

    private function batida(string $tipo, string $quando, ?Tenant $escritorio = null): RegistroPonto
    {
        $batida = (new RegistroPonto())->setTenant($escritorio ?? $this->escritorio)->setUser($this->pessoa)->setTipo($tipo)->setDataHora(new \DateTime($quando));
        $this->em->persist($batida);
        $this->em->flush();

        return $batida;
    }

    private function justificativa(): JustificativaPonto
    {
        $justificativa = (new JustificativaPonto())
            ->setTenant($this->escritorio)
            ->setUser($this->pessoa)
            ->setData(new \DateTime('2026-10-21'))
            ->setStatus('recusado')
            ->setTipo('trabalho_externo');
        $this->em->persist($justificativa);
        $this->em->flush();

        return $justificativa;
    }

    /** Justificativa da pessoa no dia 21/10, ligada à batida (trabalho externo, E12) ou solta (o esquecimento). */
    private function justificativaLigada(?RegistroPonto $batida, string $status, string $tipo = 'trabalho_externo'): JustificativaPonto
    {
        $justificativa = (new JustificativaPonto())
            ->setTenant($this->escritorio)
            ->setUser($this->pessoa)
            ->setData(new \DateTime('2026-10-21'))
            ->setStatus($status)
            ->setTipo($tipo)
            ->setRegistroPonto($batida);
        $this->em->persist($justificativa);
        $this->em->flush();

        return $justificativa;
    }

    /** A inclusão da saída de 21/10 às 18h ligada a um esquecimento com o status dado. */
    private function inclusaoLigada(string $status): PontoAjuste
    {
        $justificativa = $this->justificativaLigada(null, $status, TipoJustificativa::EsquecimentoRegistro->value);

        return $this->trilha->emTransacao(fn (): PontoAjuste => $this->gravacao->incluir(
            $this->escritorio,
            $this->pessoa,
            'saida',
            new \DateTimeImmutable('2026-10-21 18:00:00'),
            'Esquecimento abonado.',
            $this->gestora,
            $justificativa,
        ));
    }

    private function novoEscritorio(): Tenant
    {
        $escritorio = (new Tenant())->setName('Escritório do ajuste ' . uniqid());
        $this->em->persist($escritorio);
        $this->em->flush();

        return $escritorio;
    }

    private function usuario(string $nome): User
    {
        $usuario = (new User())->setEmail('ajuste_uc_' . uniqid() . '@test.com')->setFullName($nome);
        $usuario->setRoles(['ROLE_USER']);
        $usuario->setIsActive(true);
        $usuario->setPassword('x');
        $this->em->persist($usuario);
        $this->em->flush();

        return $usuario;
    }

    /** Com `primario`, o vínculo leva o perfil de sistema (o primário do escritório, RegraDosPrimarios). */
    private function vincular(User $usuario, Tenant $escritorio, bool $primario): void
    {
        $vinculo = new UserTenant($usuario, $escritorio);
        if ($primario) {
            $perfil = (new TenantRole())->setTenant($escritorio)->setName('Primário ' . uniqid())->setIsSystem(true);
            $this->em->persist($perfil);
            $vinculo->setTenantRole($perfil);
        }
        $this->em->persist($vinculo);
        $this->em->flush();
    }
}
