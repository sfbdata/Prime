<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use App\Shared\Doctrine\Filter\LixeiraFilter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O mecanismo da lixeira (D7): com o `LixeiraFilter` ligado — e ele nasce ligado —, o item com
 * `excluido_em` some de TODA leitura comum: `find()`, DQL (inclusive a seção joinada), as
 * coleções preguiçosas (`pasta.documentos`, `secao.documentos`, `secao.filhas`) e a contagem
 * delas; e a `AcessoALixeira` é a única porta, que religa o filtro mesmo quando o trabalho lança.
 *
 * Cada caso limpa o identity map antes de ler: o filtro é SQL, e uma consulta Doctrine não relê o
 * que já está carregado — sem o `clear()` o teste provaria a memória, não o filtro.
 */
#[CoversClass(LixeiraFilter::class)]
#[CoversClass(AcessoALixeira::class)]
final class LixeiraFilterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Tenant Lixeira ' . uniqid());
        $this->em->persist($this->tenant);

        $this->autor = new User();
        $this->autor->setEmail('lixeira_' . uniqid() . '@test.com');
        $this->autor->setFullName('Autor da Lixeira');
        $this->autor->setRoles(['ROLE_USER']);
        $this->autor->setIsActive(true);
        $this->autor->setPassword('dummy');
        $this->em->persist($this->autor);
        $this->em->flush();
    }

    #[TestDox('o filtro nasce ligado no container (web, console e testes)')]
    public function testFiltroLigadoPorPadrao(): void
    {
        self::assertTrue($this->em->getFilters()->isEnabled(LixeiraFilter::NOME));
        self::assertInstanceOf(LixeiraFilter::class, $this->em->getFilters()->getFilter(LixeiraFilter::NOME));
    }

    #[TestDox('find() não acha documento nem seção na lixeira; com a AcessoALixeira acha')]
    public function testFindNaoAchaOQueEstaNaLixeira(): void
    {
        $pasta   = $this->pasta();
        $secao   = $this->secao($pasta, 'NA LIXEIRA');
        $doc     = $this->documento($pasta, null, 'doc.pdf');
        $this->mandarParaALixeira([$secao, $doc]);
        $secaoId = (int) $secao->getId();
        $docId   = (int) $doc->getId();
        $this->em->clear();

        self::assertNull($this->em->find(PastaDocumento::class, $docId), 'documento na lixeira respondeu ao find()');
        self::assertNull($this->em->find(PastaSecao::class, $secaoId), 'seção na lixeira respondeu ao find()');

        $achados = static::getContainer()->get(AcessoALixeira::class)->comLixeiraVisivel(fn (): array => [
            $this->em->find(PastaDocumento::class, $docId),
            $this->em->find(PastaSecao::class, $secaoId),
        ]);
        self::assertInstanceOf(PastaDocumento::class, $achados[0]);
        self::assertInstanceOf(PastaSecao::class, $achados[1]);
        self::assertTrue($achados[0]->estaNaLixeira());
        self::assertSame($this->autor->getId(), $achados[0]->getExcluidoPor()?->getId());
        self::assertTrue($this->em->getFilters()->isEnabled(LixeiraFilter::NOME), 'o filtro volta depois do escopo');
    }

    #[TestDox('coleções preguiçosas: pasta.documentos, secao.documentos e secao.filhas não trazem (nem contam) a lixeira')]
    public function testColecoesPreguicosasEscondemALixeira(): void
    {
        $pasta  = $this->pasta();
        $mae    = $this->secao($pasta, 'MAE');
        $viva   = $this->secao($pasta, 'VIVA', $mae);
        $morta  = $this->secao($pasta, 'MORTA', $mae);
        $naRaiz = $this->documento($pasta, null, 'raiz.pdf');
        $naMae  = $this->documento($pasta, $mae, 'na-mae.pdf');
        $foraRaiz = $this->documento($pasta, null, 'fora-raiz.pdf');
        $foraMae  = $this->documento($pasta, $mae, 'fora-mae.pdf');
        $this->mandarParaALixeira([$morta, $foraRaiz, $foraMae]);
        $pastaId = (int) $pasta->getId();
        $maeId   = (int) $mae->getId();
        $this->em->clear();

        $pastaLida = $this->em->find(Pasta::class, $pastaId);
        self::assertNotNull($pastaLida);
        self::assertSame(2, $pastaLida->getDocumentos()->count(), 'a contagem da coleção preguiçosa também passa pelo filtro');
        self::assertSame(
            ['na-mae.pdf', 'raiz.pdf'],
            $this->nomes($pastaLida->getDocumentos()->toArray()),
        );

        $maeLida = $this->em->find(PastaSecao::class, $maeId);
        self::assertNotNull($maeLida);
        self::assertSame(['na-mae.pdf'], $this->nomes($maeLida->getDocumentos()->toArray()));
        self::assertSame(['VIVA'], array_map(static fn (PastaSecao $s): string => $s->getNome(), $maeLida->getFilhas()->toArray()));
        self::assertSame(1, $maeLida->getFilhas()->count());

        // Os ids continuam vivos no banco (lixeira é lápide, não remoção).
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = ?', [$pastaId]));
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_secao WHERE pasta_id = ?', [$pastaId]));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = ? AND excluido_em IS NOT NULL', [$pastaId]));
    }

    #[TestDox('DQL: o explorador (findByPastaComSecao), findByPasta das seções e os duplicados (comOMesmoConteudo) não veem a lixeira')]
    public function testDqlEscondeALixeira(): void
    {
        $sha   = str_repeat('a', 64);
        $pasta = $this->pasta();
        $viva  = $this->secao($pasta, 'VIVA');
        $morta = $this->secao($pasta, 'MORTA');
        $fica  = $this->documento($pasta, $viva, 'fica.pdf', $sha);
        $some  = $this->documento($pasta, $viva, 'some.pdf', $sha);
        $this->mandarParaALixeira([$morta, $some]);
        $this->em->clear();

        $documentos = static::getContainer()->get(PastaDocumentoRepository::class);
        $secoes     = static::getContainer()->get(PastaSecaoRepository::class);
        $pastaLida  = $this->em->find(Pasta::class, (int) $pasta->getId());
        $tenant     = $this->em->find(Tenant::class, (int) $this->tenant->getId());
        self::assertNotNull($pastaLida);
        self::assertNotNull($tenant);

        self::assertSame(['fica.pdf'], $this->nomes($documentos->findByPastaComSecao($pastaLida, $tenant)));
        self::assertSame(['VIVA'], array_map(static fn (PastaSecao $s): string => $s->getNome(), $secoes->findByPasta($pastaLida, $tenant)));
        self::assertSame(['fica.pdf'], $this->nomes($documentos->comOMesmoConteudo($tenant, $sha)), 'o duplicado na lixeira não é "em uso"');
        self::assertSame([], $documentos->findTodosDaPasta([(int) $some->getId()], $pastaLida, $tenant), 'a prova de posse do lote não aceita item da lixeira');
        self::assertCount(1, $documentos->findTodosDaPasta([(int) $fica->getId()], $pastaLida, $tenant));
    }

    #[TestDox('AcessoALixeira religa o filtro mesmo quando o trabalho lança')]
    public function testAcessoReligaOFiltroComExcecao(): void
    {
        $acesso = static::getContainer()->get(AcessoALixeira::class);

        try {
            $acesso->comLixeiraVisivel(function (): void {
                self::assertFalse($this->em->getFilters()->isEnabled(LixeiraFilter::NOME), 'dentro do escopo o filtro está desligado');

                throw new \RuntimeException('falhou no meio');
            });
            self::fail('a exceção do trabalho tem de subir');
        } catch (\RuntimeException $e) {
            self::assertSame('falhou no meio', $e->getMessage());
        }

        self::assertTrue($this->em->getFilters()->isEnabled(LixeiraFilter::NOME));
    }

    // ----------------------------------------------------------------- helpers

    private function pasta(): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('LIX-' . uniqid());
        $pasta->setTenant($this->tenant);
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }

    private function secao(Pasta $pasta, string $nome, ?PastaSecao $pai = null): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($this->tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        $secao->setPai($pai);
        $this->em->persist($secao);
        $this->em->flush();

        return $secao;
    }

    private function documento(Pasta $pasta, ?PastaSecao $secao, string $nome, ?string $sha256 = null): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('fake-' . bin2hex(random_bytes(6)) . '.pdf');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $doc->setPasta($pasta);
        $doc->setTenant($this->tenant);
        $doc->setSecao($secao);
        $doc->setSha256($sha256);
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    /** @param list<PastaDocumento|PastaSecao> $itens */
    private function mandarParaALixeira(array $itens): void
    {
        $em = new \DateTimeImmutable('2026-10-07 10:00:00');
        foreach ($itens as $item) {
            $item->marcarExcluido($this->autor, $em);
        }
        $this->em->flush();
    }

    /**
     * @param list<PastaDocumento> $documentos
     *
     * @return list<string>
     */
    private function nomes(array $documentos): array
    {
        $nomes = array_map(static fn (PastaDocumento $d): string => $d->getNomeOriginal(), $documentos);
        sort($nomes);

        return $nomes;
    }
}
