<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As setas ‹ › do cabeçalho da pasta: quem é o vizinho de cada pasta no acervo.
 *
 * A ordem é a mesma da lista padrão do Expediente — número da pasta decrescente,
 * `id` desempatando —, então "anterior" é a linha de cima e "próxima" a de baixo.
 */
#[CoversClass(PastaRepository::class)]
#[Group('pasta')]
final class PastaRepositoryVizinhasNoAcervoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PastaRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PastaRepository::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant VIZ ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarPasta(Tenant $tenant, string $nup, string $situacao = Pasta::SITUACAO_ATIVA): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $pasta->setSituacao($situacao);
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }

    /** @return array{?int, ?int} [idAnterior, idProxima] */
    private function vizinhas(Pasta $pasta): array
    {
        $v = $this->repo->vizinhasNoAcervo($pasta);

        return [$v['anterior']['id'] ?? null, $v['proxima']['id'] ?? null];
    }

    #[TestDox('no meio do acervo, ‹ vai para o número maior e › para o menor')]
    public function testVizinhasNoMeioDoAcervo(): void
    {
        $tenant = $this->criarTenant();
        $mil1   = $this->criarPasta($tenant, '1001');
        $mil2   = $this->criarPasta($tenant, '1002');
        $mil3   = $this->criarPasta($tenant, '1003');

        self::assertSame([$mil3->getId(), $mil1->getId()], $this->vizinhas($mil2));
    }

    #[TestDox('nas pontas do acervo a seta correspondente fica sem destino')]
    public function testPontasDoAcervoNaoTemVizinha(): void
    {
        $tenant = $this->criarTenant();
        $mil1   = $this->criarPasta($tenant, '1001');
        $mil2   = $this->criarPasta($tenant, '1002');
        $mil3   = $this->criarPasta($tenant, '1003');

        self::assertSame([null, $mil2->getId()], $this->vizinhas($mil3), 'a de número maior não tem anterior');
        self::assertSame([$mil2->getId(), null], $this->vizinhas($mil1), 'a de número menor não tem próxima');
    }

    #[TestDox('pasta sozinha no escritório não tem vizinha de nenhum lado')]
    public function testPastaSozinha(): void
    {
        $tenant = $this->criarTenant();
        $unica  = $this->criarPasta($tenant, '1001');

        self::assertSame([null, null], $this->vizinhas($unica));
    }

    /**
     * O acervo real tem número repetido (em produção, três pastas com o mesmo NUP). Sem o
     * desempate por `id`, duas pastas de mesmo número apontariam uma para a outra e as setas
     * entrariam em looping — nunca alcançariam o resto do acervo.
     */
    #[TestDox('número repetido desempata por id, sem looping entre as duas')]
    public function testNumeroRepetidoDesempataPorId(): void
    {
        $tenant  = $this->criarTenant();
        $antes   = $this->criarPasta($tenant, '1001');
        $velha   = $this->criarPasta($tenant, '1002');
        $nova    = $this->criarPasta($tenant, '1002');
        $depois  = $this->criarPasta($tenant, '1003');

        // Ordem esperada, de cima para baixo: 1003 · 1002(nova) · 1002(velha) · 1001
        self::assertSame([$depois->getId(), $velha->getId()], $this->vizinhas($nova));
        self::assertSame([$nova->getId(), $antes->getId()], $this->vizinhas($velha));
    }

    #[TestDox('NUP sem prefixo numérico fica no fim da ordem, como na lista')]
    public function testNupNaoNumericoVaiParaOFim(): void
    {
        $tenant  = $this->criarTenant();
        $mil1    = $this->criarPasta($tenant, '1001');
        $mil2    = $this->criarPasta($tenant, '1002');
        $semNum  = $this->criarPasta($tenant, 'PROC-7');

        self::assertSame([$mil1->getId(), null], $this->vizinhas($semNum), 'a sem número é a última');
        self::assertSame([$mil2->getId(), $semNum->getId()], $this->vizinhas($mil1));
    }

    #[TestDox('o prefixo numérico ordena por valor, não por texto: 9 vem depois de 10')]
    public function testOrdemENumericaENaoLexical(): void
    {
        $tenant = $this->criarTenant();
        $nove   = $this->criarPasta($tenant, '9');
        $dez    = $this->criarPasta($tenant, '10');

        // Como texto, '9' > '10' e a ordem se inverteria.
        self::assertSame([$dez->getId(), null], $this->vizinhas($nove));
        self::assertSame([null, $nove->getId()], $this->vizinhas($dez));
    }

    #[TestDox('arquivada e excluída entram no caminho das setas, como entram na lista')]
    public function testArquivadaEExcluidaEntram(): void
    {
        $tenant    = $this->criarTenant();
        $ativa     = $this->criarPasta($tenant, '1001');
        $arquivada = $this->criarPasta($tenant, '1002', Pasta::SITUACAO_ARQUIVADA);
        $riscada   = $this->criarPasta($tenant, '1003');

        $user = new User();
        $user->setEmail('viz_' . uniqid() . '@test.com');
        $user->setFullName('Quem excluiu');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $this->em->persist($user);
        $riscada->marcarExcluida($user, new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame([$riscada->getId(), $ativa->getId()], $this->vizinhas($arquivada));
    }

    /**
     * O vizinho vem com o número, não só com o id: é ele que o cabeçalho mostra no tooltip
     * ("Pasta 1003"), e sem isso a seta não diz para onde leva.
     */
    #[TestDox('o vizinho devolvido traz o número da pasta de destino')]
    public function testVizinhaTrazONumero(): void
    {
        $tenant = $this->criarTenant();
        $this->criarPasta($tenant, '1001');
        $meio = $this->criarPasta($tenant, '1002');
        $this->criarPasta($tenant, '1003');

        $vizinhas = $this->repo->vizinhasNoAcervo($meio);

        self::assertSame('1003', $vizinhas['anterior']['nup']);
        self::assertSame('1001', $vizinhas['proxima']['nup']);
    }

    /**
     * Isolamento: a seta não pode atravessar escritórios. O cenário é desenhado para o
     * vizinho ÓBVIO ser o do outro tenant — a pasta 1002 do vizinho está entre a 1001 e a
     * 1003 deste escritório, e uma consulta sem filtro de tenant a devolveria.
     */
    #[TestDox('vizinha de outro escritório nunca entra no caminho das setas')]
    public function testNaoAtravessaEscritorios(): void
    {
        $meu    = $this->criarTenant();
        $alheio = $this->criarTenant();

        $minha1001 = $this->criarPasta($meu, '1001');
        $minha1003 = $this->criarPasta($meu, '1003');
        $this->criarPasta($alheio, '1002');

        self::assertSame(
            [$minha1003->getId(), null],
            $this->vizinhas($minha1001),
            'a 1002 do outro escritório vazou para a navegação'
        );
        self::assertSame([null, $minha1001->getId()], $this->vizinhas($minha1003));
    }
}
