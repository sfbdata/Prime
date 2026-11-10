<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Exception\BatidaEmHorarioFuturoException;
use App\Ponto\Service\BatidaJaRegistrada;
use App\Ponto\UseCase\RegistrarBatidaDoEsquecimentoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Regras do UseCase que grava a batida do esquecimento abonado. Desde a E10 (D18) ela entra como INCLUSÃO em
 * `ponto_ajuste`, ligada à justificativa e com quem abonou como autor; `registro_ponto` fica só com o que a pessoa
 * bateu. Usa o EntityManager real e escritórios gravados, porque a guarda de tenant compara ids.
 */
#[CoversClass(RegistrarBatidaDoEsquecimentoUseCase::class)]
final class RegistrarBatidaDoEsquecimentoUseCaseTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private RegistrarBatidaDoEsquecimentoUseCase $useCase;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
        $this->useCase = static::getContainer()->get(RegistrarBatidaDoEsquecimentoUseCase::class);
    }

    #[TestDox('Esquecimento completo vira inclusão ligada à justificativa, com a data do pedido, a hora esquecida, o tipo pedido e quem abonou como autor; registro_ponto não ganha linha')]
    public function testEsquecimentoViraInclusao(): void
    {
        $tenant  = $this->criarTenant();
        $pessoa  = $this->pessoa('colaborador');
        $gestor  = $this->pessoa('gestor');
        $justificativa = $this->justificativa($tenant, 'esquecimento_registro', 'retorno', '13:15', $pessoa);

        $inclusao = ($this->useCase)($justificativa, $tenant, $gestor);

        self::assertInstanceOf(PontoAjuste::class, $inclusao);
        self::assertSame(TipoDoAjuste::Inclusao, $inclusao->getTipo());
        self::assertSame('2026-03-10 13:15:00', $inclusao->getDataHoraNova()?->format('Y-m-d H:i:s'));
        self::assertSame('retorno', $inclusao->getTipoMarcacaoNova());
        self::assertSame($justificativa, $inclusao->getJustificativa());
        self::assertSame($gestor, $inclusao->getAutor(), 'o autor é quem abonou');
        self::assertSame(RegistrarBatidaDoEsquecimentoUseCase::OBSERVACAO, $inclusao->getMotivo());
        self::assertSame(1, $this->inclusoesDaJustificativa($justificativa), 'uma inclusão ligada à justificativa, já no banco');
        self::assertSame(0, $this->registrosDe($pessoa, $tenant), 'registro_ponto guarda só o que a pessoa bateu');
        self::assertSame(
            [['batida_do_esquecimento', (int) $gestor->getId(), (int) $inclusao->getId(), (int) $justificativa->getId()]],
            $this->eventosDaTrilha($tenant),
            'um evento só, gravado pela própria inclusão',
        );
    }

    #[TestDox('Justificativa que não é esquecimento, ou sem tipo ou hora, não gera inclusão')]
    public function testSemEsquecimentoCompletoNaoGeraBatida(): void
    {
        $tenant = $this->criarTenant();
        $gestor = $this->pessoa('gestor');

        self::assertNull(($this->useCase)($this->justificativa($tenant, 'atestado_medico', null, null), $tenant, $gestor));
        self::assertNull(($this->useCase)($this->justificativa($tenant, 'esquecimento_registro', null, '08:00'), $tenant, $gestor));
        self::assertNull(($this->useCase)($this->justificativa($tenant, 'esquecimento_registro', 'entrada', null), $tenant, $gestor));
        self::assertSame(0, $this->ajustesDoEscritorio($tenant));
    }

    #[TestDox('Justificativa de outro escritório é recusada antes de gravar')]
    public function testOutroEscritorioERecusado(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $justificativa = $this->justificativa($tenantB, 'esquecimento_registro', 'entrada', '08:00', $this->pessoa('colaborador'));

        try {
            ($this->useCase)($justificativa, $tenantA, $this->pessoa('gestor'));
            self::fail('A justificativa de outro escritório deveria ser recusada.');
        } catch (\DomainException) {
        }

        self::assertSame(0, $this->ajustesDoEscritorio($tenantA));
        self::assertSame(0, $this->ajustesDoEscritorio($tenantB));
    }

    #[TestDox('Pedido com data e hora acima de agora no relógio do servidor é recusado e não grava inclusão')]
    public function testHorarioFuturoERecusado(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-03-10 13:00:00', new \DateTimeZone(date_default_timezone_get())));
        $tenant = $this->criarTenant();
        $pessoa = $this->pessoa('colaborador');
        $futura = $this->justificativa($tenant, 'esquecimento_registro', 'saida', '13:01', $pessoa);

        self::assertTrue($this->useCase->pedeHorarioFuturo($futura));
        self::assertFalse($this->useCase->pedeHorarioFuturo($this->justificativa($tenant, 'esquecimento_registro', 'saida', '13:00')));

        try {
            ($this->useCase)($futura, $tenant, $this->pessoa('gestor'));
            self::fail('O horário futuro deveria ser recusado.');
        } catch (BatidaEmHorarioFuturoException) {
        }

        self::assertSame(0, $this->ajustesDoEscritorio($tenant));
    }

    #[TestDox('Guarda do __invoke: com a batida do mesmo tipo já registrada naquele dia, escritório e pessoa, devolve null e não grava nada (spec ponto-esquecimento-nao-trava R1)')]
    public function testBatidaJaRegistradaNaoGrava(): void
    {
        $tenant = $this->criarTenant();
        $pessoa = $this->pessoa('guarda');
        $existente = new RegistroPonto();
        $existente->setUser($pessoa);
        $existente->setTenant($tenant);
        $existente->setTipo('retorno');
        $existente->setDataHora(new \DateTime('2026-03-10 13:00:00'));
        $this->em->persist($existente);
        $this->em->flush();
        $justificativa = $this->justificativa($tenant, 'esquecimento_registro', 'retorno', '13:15', $pessoa);

        self::assertSame(sprintf(BatidaJaRegistrada::MENSAGEM, 'retorno'), $this->useCase->recusa($justificativa, $tenant));
        self::assertNull(($this->useCase)($justificativa, $tenant, $this->pessoa('gestor')));
        self::assertSame(1, $this->registrosDe($pessoa, $tenant), 'só a batida que já existia');
        self::assertSame(0, $this->ajustesDoEscritorio($tenant), 'nenhuma inclusão');
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ESQUECIMENTO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function pessoa(string $papel): User
    {
        $pessoa = new User();
        $pessoa->setEmail('esquecimento_' . $papel . '_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa ' . $papel);
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setPassword('x');
        $this->em->persist($pessoa);
        $this->em->flush();

        return $pessoa;
    }

    /** Com a pessoa, a justificativa é gravada (a inclusão aponta para ela); sem, fica só em memória. */
    private function justificativa(Tenant $tenant, string $tipo, ?string $tipoRegistro, ?string $hora, ?User $pessoa = null): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setTenant($tenant);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus('abonado');
        $justificativa->setTipo($tipo);
        $justificativa->setTipoRegistroEsquecido($tipoRegistro);
        $justificativa->setHoraRegistroEsquecido($hora !== null ? new \DateTime('1970-01-01 ' . $hora . ':00') : null);
        if ($pessoa !== null) {
            $justificativa->setUser($pessoa);
            $this->em->persist($justificativa);
            $this->em->flush();
        }

        return $justificativa;
    }

    private function inclusoesDaJustificativa(JustificativaPonto $justificativa): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM ponto_ajuste WHERE tipo = 'inclusao' AND justificativa_id = ?",
            [$justificativa->getId()],
        );
    }

    private function ajustesDoEscritorio(Tenant $tenant): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_ajuste WHERE tenant_id = ?', [$tenant->getId()]);
    }

    private function registrosDe(User $pessoa, Tenant $tenant): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM registro_ponto WHERE user_id = ? AND tenant_id = ?',
            [$pessoa->getId(), $tenant->getId()],
        );
    }

    /** @return list<array{string, int, int, int}> ação, autor, ajuste_id e justificativa_id de cada evento */
    private function eventosDaTrilha(Tenant $tenant): array
    {
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT acao, autor_id, detalhe FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$tenant->getId()],
        );

        return array_map(static function (array $linha): array {
            $detalhe = json_decode((string) $linha['detalhe'], true);

            return [(string) $linha['acao'], (int) $linha['autor_id'], (int) ($detalhe['ajuste_id'] ?? 0), (int) ($detalhe['justificativa_id'] ?? 0)];
        }, $linhas);
    }
}
