<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\PontoAjusteRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A consulta em lote da aba "Minhas Justificativas" (E10): quais justificativas da lista já mexeram na folha (algum
 * ajuste aponta para elas), NESTE escritório. A tela esconde o "Excluir" delas; o servidor recusa pela mesma regra.
 */
#[CoversClass(PontoAjusteRepository::class)]
final class PontoAjusteRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PontoAjusteRepository $repositorio;
    private GravacaoDoAjusteDoPonto $gravacao;
    private User $pessoa;
    private User $gestor;

    protected function setUp(): void
    {
        self::bootKernel();
        $container         = static::getContainer();
        $this->em          = $container->get(EntityManagerInterface::class);
        $this->repositorio = $container->get(PontoAjusteRepository::class);
        $this->gravacao    = $container->get(GravacaoDoAjusteDoPonto::class);
        $this->pessoa      = $this->pessoa('colaborador');
        $this->gestor      = $this->pessoa('gestor');
    }

    #[TestDox('Devolve só as da lista com ajuste ligado (a inclusão do esquecimento e a desconsideração da volta para análise contam); sem ajuste, nada')]
    public function testSoAsComAjuste(): void
    {
        $escritorio     = $this->escritorio();
        $comInclusao    = $this->justificativa($escritorio);
        $desconsiderada = $this->justificativa($escritorio);
        $semAjuste      = $this->justificativa($escritorio);
        $foraDaLista    = $this->justificativa($escritorio);
        $this->incluir($escritorio, $comInclusao, '08:00');
        $this->incluirEDesconsiderar($escritorio, $desconsiderada, '12:00');
        $this->incluir($escritorio, $foraDaLista, '18:00');

        $ids = $this->repositorio->idsDasJustificativasComAjuste($escritorio, self::idsDe([$semAjuste, $desconsiderada, $comInclusao]));

        $esperados = self::idsDe([$comInclusao, $desconsiderada]);
        sort($esperados);
        self::assertSame($esperados, $ids, 'nem a sem ajuste nem a que ficou fora da lista');
    }

    #[TestDox('Escritório escrito na consulta: o id de outro escritório, mesmo com ajuste lá, não volta; lá, volta')]
    public function testOutroEscritorioNaoVolta(): void
    {
        $aqui = $this->escritorio();
        $la   = $this->escritorio();
        $deLa = $this->justificativa($la);
        $this->incluir($la, $deLa, '08:00');

        self::assertSame([], $this->repositorio->idsDasJustificativasComAjuste($aqui, self::idsDe([$deLa])));
        self::assertSame(self::idsDe([$deLa]), $this->repositorio->idsDasJustificativasComAjuste($la, self::idsDe([$deLa])));
    }

    #[TestDox('Lista vazia devolve vazio')]
    public function testListaVazia(): void
    {
        self::assertSame([], $this->repositorio->idsDasJustificativasComAjuste($this->escritorio(), []));
    }

    private function incluir(Tenant $escritorio, JustificativaPonto $justificativa, string $hora): void
    {
        $this->em->wrapInTransaction(fn () => $this->gravacao->incluir(
            $escritorio,
            $this->pessoa,
            'entrada',
            new \DateTimeImmutable('2026-03-10 ' . $hora . ':00'),
            'Esquecimento abonado.',
            $this->gestor,
            $justificativa,
        ));
    }

    /** Como a volta para análise: a inclusão do abono sai da conta, com a desconsideração ligada à justificativa. */
    private function incluirEDesconsiderar(Tenant $escritorio, JustificativaPonto $justificativa, string $hora): void
    {
        $this->em->wrapInTransaction(function () use ($escritorio, $justificativa, $hora): void {
            $inclusao = $this->gravacao->incluir($escritorio, $this->pessoa, 'entrada', new \DateTimeImmutable('2026-03-10 ' . $hora . ':00'), 'Esquecimento abonado.', $this->gestor, $justificativa);
            $alvo     = $this->registros()->alvoDoAjuste($this->pessoa, $escritorio, 'a' . $inclusao->getId());
            self::assertNotNull($alvo);
            $this->gravacao->desconsiderar($alvo, 'Voltou para análise.', $this->gestor, $justificativa, $inclusao);
        });
    }

    private function registros(): RegistroPontoRepository
    {
        return static::getContainer()->get(RegistroPontoRepository::class);
    }

    private function escritorio(): Tenant
    {
        $escritorio = (new Tenant())->setName('Escritório dos ajustes ' . uniqid());
        $this->em->persist($escritorio);
        $this->em->flush();

        return $escritorio;
    }

    private function pessoa(string $papel): User
    {
        $pessoa = new User();
        $pessoa->setEmail('ajuste_repo_' . $papel . '_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa ' . $papel);
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setPassword('x');
        $this->em->persist($pessoa);
        $this->em->flush();

        return $pessoa;
    }

    private function justificativa(Tenant $escritorio): JustificativaPonto
    {
        $justificativa = new JustificativaPonto();
        $justificativa->setTenant($escritorio);
        $justificativa->setUser($this->pessoa);
        $justificativa->setData(new \DateTime('2026-03-10'));
        $justificativa->setStatus('pendente');
        $justificativa->setTipo('esquecimento_registro');
        $justificativa->setTipoRegistroEsquecido('entrada');
        $justificativa->setHoraRegistroEsquecido(new \DateTime('1970-01-01 08:00:00'));
        $justificativa->setBatchId(bin2hex(random_bytes(8)));
        $this->em->persist($justificativa);
        $this->em->flush();

        return $justificativa;
    }

    /**
     * @param list<JustificativaPonto> $justificativas
     *
     * @return list<int>
     */
    private static function idsDe(array $justificativas): array
    {
        return array_map(static fn (JustificativaPonto $j): int => (int) $j->getId(), $justificativas);
    }
}
