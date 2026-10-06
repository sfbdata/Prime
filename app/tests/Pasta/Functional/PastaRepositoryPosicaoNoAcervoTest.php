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
 * O "N de M" entre as setas ‹ › do cabeçalho: a linha da pasta na lista do Expediente.
 *
 * Mesma ordem das setas — número decrescente, `id` desempatando — e mesmo conjunto
 * (ativas, arquivadas e lápides do escritório). Os cenários conferem também que a seta ‹
 * leva à pasta da posição N-1: contador e setas não podem discordar.
 */
#[CoversClass(PastaRepository::class)]
#[Group('pasta')]
final class PastaRepositoryPosicaoNoAcervoTest extends KernelTestCase
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
        $tenant->setName('Tenant POS ' . uniqid());
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

    /** @return array{int, int} [posicao, total] */
    private function posicao(Pasta $pasta): array
    {
        $p = $this->repo->posicaoNoAcervo($pasta);
        self::assertNotNull($p);

        return [$p['posicao'], $p['total']];
    }

    #[TestDox('NUPs criados fora de ordem: a posição segue o número decrescente, não a criação')]
    public function testNupsDesordenados(): void
    {
        $tenant = $this->criarTenant();
        $p1002  = $this->criarPasta($tenant, '1002');
        $p9     = $this->criarPasta($tenant, '9');
        $p1010  = $this->criarPasta($tenant, '1010');
        $p10    = $this->criarPasta($tenant, '10');
        $semNum = $this->criarPasta($tenant, 'PROC-7');

        // Ordem da lista, de cima para baixo: 1010 · 1002 · 10 · 9 · PROC-7
        self::assertSame([1, 5], $this->posicao($p1010));
        self::assertSame([2, 5], $this->posicao($p1002));
        self::assertSame([3, 5], $this->posicao($p10), 'o prefixo ordena por valor: 10 acima de 9');
        self::assertSame([4, 5], $this->posicao($p9));
        self::assertSame([5, 5], $this->posicao($semNum), 'NUP sem número fica no fim, como nas setas');

        // Contador e seta concordam: a anterior da 3ª é a 2ª.
        self::assertSame($p1002->getId(), $this->repo->vizinhasNoAcervo($p10)['anterior']['id'] ?? null);
    }

    #[TestDox('número repetido desempata por id: a mais nova fica acima e as duas têm posições distintas')]
    public function testNumeroRepetidoDesempataPorId(): void
    {
        $tenant = $this->criarTenant();
        $antes  = $this->criarPasta($tenant, '1001');
        $velha  = $this->criarPasta($tenant, '1002');
        $nova   = $this->criarPasta($tenant, '1002');
        $depois = $this->criarPasta($tenant, '1003');

        // 1003 · 1002(nova) · 1002(velha) · 1001
        self::assertSame([1, 4], $this->posicao($depois));
        self::assertSame([2, 4], $this->posicao($nova));
        self::assertSame([3, 4], $this->posicao($velha));
        self::assertSame([4, 4], $this->posicao($antes));
        self::assertSame($nova->getId(), $this->repo->vizinhasNoAcervo($velha)['anterior']['id'] ?? null);
    }

    #[TestDox('primeira é 1 de M, última é M de M e pasta sozinha é 1 de 1')]
    public function testPontasEPastaSozinha(): void
    {
        $tenant   = $this->criarTenant();
        $ultima   = $this->criarPasta($tenant, '1001');
        $this->criarPasta($tenant, '1002');
        $primeira = $this->criarPasta($tenant, '1003');

        self::assertSame([1, 3], $this->posicao($primeira));
        self::assertSame([3, 3], $this->posicao($ultima));

        $sozinha = $this->criarPasta($this->criarTenant(), '1001');
        self::assertSame([1, 1], $this->posicao($sozinha));
    }

    #[TestDox('arquivada e excluída contam, como contam nas setas e na lista')]
    public function testArquivadaEExcluidaContam(): void
    {
        $tenant  = $this->criarTenant();
        $ativa   = $this->criarPasta($tenant, '1001');
        $this->criarPasta($tenant, '1002', Pasta::SITUACAO_ARQUIVADA);
        $riscada = $this->criarPasta($tenant, '1003');

        $user = new User();
        $user->setEmail('pos_' . uniqid() . '@test.com');
        $user->setFullName('Quem excluiu');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $this->em->persist($user);
        $riscada->marcarExcluida($user, new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame([3, 3], $this->posicao($ativa));
    }

    /**
     * Isolamento: as pastas do outro escritório são desenhadas para cair ACIMA da minha
     * (números maiores), entre as minhas e com NUP igual a uma delas — uma consulta sem
     * filtro de tenant inflaria tanto a posição quanto o total.
     */
    #[TestDox('pastas de outro escritório não contam na posição nem no total')]
    public function testNaoContaOutroEscritorio(): void
    {
        $meu    = $this->criarTenant();
        $alheio = $this->criarTenant();

        $minha1001 = $this->criarPasta($meu, '1001');
        $minha1003 = $this->criarPasta($meu, '1003');
        $this->criarPasta($alheio, '1002');
        $this->criarPasta($alheio, '9999');
        $this->criarPasta($alheio, '1003');

        self::assertSame([2, 2], $this->posicao($minha1001), 'pasta do outro escritório entrou na contagem');
        self::assertSame([1, 2], $this->posicao($minha1003));
    }

    /**
     * Trava a equivalência entre o contador e as setas num acervo com todos os empates da
     * chave (prefixo, NUP cru, id): mesmo NUP repetido, mesmo prefixo com sufixo diferente,
     * vários NUPs sem número (prefixo -1 empatado) e NUP vazio. As duas consultas leem a
     * mesma expressão; se uma delas mudar sozinha, a caminhada pelas setas sai da ordem do
     * contador e este teste cai.
     */
    #[TestDox('com empates e NUPs sem número, caminhar pelas setas percorre as posições 1..M em ordem')]
    public function testSetasEContadorConcordamComEmpatesENupsSemNumero(): void
    {
        $tenant = $this->criarTenant();
        $velha  = $this->criarPasta($tenant, '1002');
        $nove   = $this->criarPasta($tenant, '9');
        $vazio  = $this->criarPasta($tenant, '');
        $abc    = $this->criarPasta($tenant, 'ABC');
        $dez    = $this->criarPasta($tenant, '10');
        $nova   = $this->criarPasta($tenant, '1002');
        $proc   = $this->criarPasta($tenant, 'PROC-7');
        $dezA   = $this->criarPasta($tenant, '10A');

        // Ruído de outro escritório com as mesmas chaves: não pode entrar em nada.
        $alheio = $this->criarTenant();
        foreach (['1002', '10A', 'ABC', ''] as $nup) {
            $this->criarPasta($alheio, $nup);
        }

        // De cima para baixo: 1002(nova) · 1002(velha) · 10A · 10 · 9 · PROC-7 · ABC · ''
        $esperada = [$nova, $velha, $dezA, $dez, $nove, $proc, $abc, $vazio];
        $total    = \count($esperada);

        foreach ($esperada as $i => $pasta) {
            self::assertSame([$i + 1, $total], $this->posicao($pasta), 'posição da pasta de NUP "' . $pasta->getNup() . '"');

            $vizinhas = $this->repo->vizinhasNoAcervo($pasta);
            self::assertSame(
                $i > 0 ? $esperada[$i - 1]->getId() : null,
                $vizinhas['anterior']['id'] ?? null,
                'seta ‹ da posição ' . ($i + 1),
            );
            self::assertSame(
                $i < $total - 1 ? $esperada[$i + 1]->getId() : null,
                $vizinhas['proxima']['id'] ?? null,
                'seta › da posição ' . ($i + 1),
            );
        }
    }

    #[TestDox('pasta ainda não persistida não tem posição')]
    public function testPastaNaoPersistidaNaoTemPosicao(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('1001');
        $pasta->setTenant($this->criarTenant());

        self::assertNull($this->repo->posicaoNoAcervo($pasta));
    }
}
