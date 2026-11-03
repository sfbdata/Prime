<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Armazenamento\ReferenciasDeArquivoDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\PurgarLixeiraUseCase;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use App\Shared\Doctrine\Filter\LixeiraFilter;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * `PurgarLixeiraUseCase::purgarSelecao()`, o laço da purga usado pelo "Excluir de vez" da lixeira
 * (03/11/2026). Com banco: sai NA HORA o que está na lixeira (sem corte de dias), o arquivo só sai
 * DEPOIS do COMMIT, a seção leva a subárvore, arquivo com outra referência fica, banco que recusa
 * não tira nada do disco. E a segunda barreira: item vivo, de outra pasta ou de outro escritório
 * passado por engano é ignorado (a rota já responde 404 antes).
 *
 * O armazenamento é o dublê em memória (registra QUANDO cada chave saiu); o relógio é fixo.
 */
#[CoversClass(PurgarLixeiraUseCase::class)]
final class PurgarSelecaoDaLixeiraTest extends KernelTestCase
{
    private const AGORA = '2026-11-03 12:00:00';

    private EntityManagerInterface $em;
    private ArmazenamentoEmMemoria $memoria;
    private Tenant $tenant;
    private User $autor;
    private Pasta $pasta;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = static::getContainer()->get(EntityManagerInterface::class);
        $this->memoria = new ArmazenamentoEmMemoria();

        $this->tenant = $this->novoEscritorio('Tenant Excluir de vez');

        $this->autor = new User();
        $this->autor->setEmail('excluir_de_vez_' . uniqid() . '@test.com');
        $this->autor->setFullName('Autor');
        $this->autor->setRoles(['ROLE_USER']);
        $this->autor->setIsActive(true);
        $this->autor->setPassword('dummy');
        $this->em->persist($this->autor);

        $this->pasta = $this->novaPasta($this->tenant);
        $this->em->flush();
    }

    #[TestDox('o que acabou de ir para a lixeira sai NA HORA (sem corte de dias), e o arquivo só sai DEPOIS de a linha ter saído do banco')]
    public function testSaiNaHoraEArquivoDepoisDoBanco(): void
    {
        $doc     = $this->documento('agora.pdf', naLixeira: true);
        $vizinho = $this->documento('vizinho.pdf', naLixeira: true);
        $this->em->clear();

        $linhaNoMomento = null;
        $this->memoria->aoExcluir = function () use (&$linhaNoMomento, $doc): void {
            $linhaNoMomento = $this->existeLinha('pasta_documento', (int) $doc->getId());
        };

        $resultado = $this->purga()->purgarSelecao([], [(int) $doc->getId()], (int) $this->pasta->getId(), (int) $this->tenant->getId());

        self::assertFalse($resultado->simulacao);
        self::assertSame(1, $resultado->documentosRemovidos);
        self::assertSame(1, $resultado->arquivosRemovidos);
        self::assertSame(1, $resultado->documentosCandidatos);
        self::assertFalse($linhaNoMomento, 'quando o arquivo saiu, a linha já não estava no banco (INV-6)');
        self::assertFalse($this->existeLinha('pasta_documento', (int) $doc->getId()));
        self::assertFalse($this->memoria->existe($this->chaveDe($doc)));
        self::assertTrue($this->existeLinha('pasta_documento', (int) $vizinho->getId()), 'só sai o que foi selecionado');
        self::assertTrue($this->memoria->existe($this->chaveDe($vizinho)));
        self::assertTrue($this->em->getFilters()->isEnabled(LixeiraFilter::NOME), 'o filtro volta ligado');
    }

    #[TestDox('subpasta selecionada leva a subárvore; filha e documento de dentro também selecionados não contam duas vezes')]
    public function testSubarvore(): void
    {
        $mae     = $this->secao('MAE', naLixeira: true);
        $filha   = $this->secao('FILHA', pai: $mae, naLixeira: true);
        $naMae   = $this->documento('na-mae.pdf', secao: $mae, naLixeira: true);
        $naFilha = $this->documento('na-filha.pdf', secao: $filha, naLixeira: true);
        $this->em->clear();

        $resultado = $this->purga()->purgarSelecao(
            [(int) $filha->getId(), (int) $mae->getId()],
            [(int) $naFilha->getId()],
            (int) $this->pasta->getId(),
            (int) $this->tenant->getId(),
        );

        self::assertSame(2, $resultado->secoesRemovidas);
        self::assertSame(2, $resultado->documentosRemovidos);
        self::assertSame(2, $resultado->arquivosRemovidos);
        foreach ([$mae, $filha] as $secao) {
            self::assertFalse($this->existeLinha('pasta_secao', (int) $secao->getId()));
        }
        foreach ([$naMae, $naFilha] as $doc) {
            self::assertFalse($this->existeLinha('pasta_documento', (int) $doc->getId()));
            self::assertFalse($this->memoria->existe($this->chaveDe($doc)));
        }
    }

    #[TestDox('segunda barreira: item vivo, de outra pasta ou de outro escritório passado por engano é ignorado, sem tocar no arquivo')]
    public function testIgnoraOQueNaoEDaLixeiraDestaPasta(): void
    {
        $vivo        = $this->documento('vivo.pdf', naLixeira: false);
        $irma        = $this->novaPasta($this->tenant);
        $daIrma      = $this->documento('da-irma.pdf', naLixeira: true, pasta: $irma);
        $outro       = $this->novoEscritorio('Outro Tenant Excluir de vez');
        $alheia      = $this->novaPasta($outro);
        $this->em->flush();
        $alheio      = $this->documento('alheio.pdf', naLixeira: true, pasta: $alheia, tenant: $outro);
        $secaoViva   = $this->secao('VIVA', naLixeira: false);
        $this->em->clear();

        $resultado = $this->purga()->purgarSelecao(
            [(int) $secaoViva->getId()],
            [(int) $vivo->getId(), (int) $daIrma->getId(), (int) $alheio->getId()],
            (int) $this->pasta->getId(),
            (int) $this->tenant->getId(),
        );

        self::assertSame(0, $resultado->documentosRemovidos);
        self::assertSame(0, $resultado->secoesRemovidas);
        self::assertSame([], $this->memoria->excluidas);
        self::assertTrue($this->existeLinha('pasta_secao', (int) $secaoViva->getId()));
        foreach ([$vivo, $daIrma, $alheio] as $doc) {
            self::assertTrue($this->existeLinha('pasta_documento', (int) $doc->getId()));
        }
    }

    #[TestDox('arquivo com outra referência viva NÃO sai; a linha selecionada sai')]
    public function testArquivoComOutraReferenciaFica(): void
    {
        $naLixeira = $this->documento('compartilhado.pdf', naLixeira: true);
        $copia     = (new PastaDocumento())->setTenant($this->tenant);
        $copia->setTitulo('copia.pdf')
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($naLixeira->getCaminhoArquivo())
            ->setNomeOriginal('copia.pdf')
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($this->pasta);
        $this->em->persist($copia);
        $this->em->flush();
        $this->em->clear();

        $this->purga()->purgarSelecao([], [(int) $naLixeira->getId()], (int) $this->pasta->getId(), (int) $this->tenant->getId());

        self::assertFalse($this->existeLinha('pasta_documento', (int) $naLixeira->getId()));
        self::assertTrue($this->existeLinha('pasta_documento', (int) $copia->getId()));
        self::assertTrue($this->memoria->existe($this->chaveDe($naLixeira)), 'a outra linha ainda aponta para o arquivo');
        self::assertSame([], $this->memoria->excluidas);
    }

    #[TestDox('banco que recusa: a exceção sobe, a linha fica e nenhum arquivo sai; o filtro volta ligado')]
    public function testBancoQueRecusaNaoApagaArquivo(): void
    {
        $doc = $this->documento('fica.pdf', naLixeira: true);
        $this->em->clear();

        $recusa = new class () {
            public function onFlush(OnFlushEventArgs $args): void
            {
                if ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() !== []) {
                    throw new \LogicException('banco recusou');
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $recusa);

        try {
            $this->purga()->purgarSelecao([], [(int) $doc->getId()], (int) $this->pasta->getId(), (int) $this->tenant->getId());
            self::fail('a recusa do banco devia subir');
        } catch (\LogicException $e) {
            self::assertSame('banco recusou', $e->getMessage());
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $recusa);
        }

        self::assertTrue($this->existeLinha('pasta_documento', (int) $doc->getId()));
        self::assertTrue($this->memoria->existe($this->chaveDe($doc)), 'nada sai do disco antes de o banco confirmar');
        self::assertSame([], $this->memoria->excluidas);
        self::assertTrue($this->em->getFilters()->isEnabled(LixeiraFilter::NOME));
    }

    // ----------------------------------------------------------------- helpers

    private function purga(): PurgarLixeiraUseCase
    {
        $container = static::getContainer();

        return new PurgarLixeiraUseCase(
            $this->em,
            $container->get(PastaDocumentoRepository::class),
            $container->get(PastaSecaoRepository::class),
            $container->get(AcessoALixeira::class),
            new RemocaoAposTransacao($this->memoria, new NullLogger()),
            $container->get(ReferenciasDeArquivoDaPasta::class),
            new MockClock(self::AGORA),
            new NullLogger(),
        );
    }

    private function novoEscritorio(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function novaPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('EXCL-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em->persist($pasta);

        return $pasta;
    }

    /** O arquivo vai para o dublê em memória, pela chave que a leitura usa (`ChavesDePasta::documento`). */
    private function documento(string $nome, bool $naLixeira, ?PastaSecao $secao = null, ?Pasta $pasta = null, ?Tenant $tenant = null): PastaDocumento
    {
        $doc   = (new PastaDocumento())->setTenant($tenant ?? $this->tenant);
        $chave = $this->memoria->gravar(
            ChavesDePasta::novoDocumento($doc, 'pdf'),
            FonteDeConteudo::deTexto('conteudo-' . $nome),
        )->chave;

        $doc->setTitulo($nome)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo($chave->nome)
            ->setNomeOriginal($nome)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10)
            ->setPasta($pasta ?? $this->pasta)
            ->setSecao($secao);
        if ($naLixeira) {
            $doc->marcarExcluido($this->autor, new \DateTimeImmutable('2026-11-03 11:59:00'));
        }
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    private function secao(string $nome, ?PastaSecao $pai = null, bool $naLixeira = false): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        $secao->setPai($pai);
        if ($naLixeira) {
            $secao->marcarExcluido($this->autor, new \DateTimeImmutable('2026-11-03 11:59:00'));
        }
        $this->em->persist($secao);
        $this->em->flush();

        return $secao;
    }

    private function chaveDe(PastaDocumento $doc): ChaveDeArquivo
    {
        return ChavesDePasta::documentoPorNome((int) $doc->getTenant()?->getId(), (string) $doc->getCaminhoArquivo());
    }

    private function existeLinha(string $tabela, int $id): bool
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE id = :id", ['id' => $id]) === 1;
    }
}
