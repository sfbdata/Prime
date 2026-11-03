<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As duas agregações da lixeira do escritório, com banco e SEM sessão (nenhum TenantFilter ligado):
 * o recorte de escritório é o `tenant = :tenant` da própria consulta, e só o que está na lixeira
 * conta. Tirar qualquer um dos dois deixa este teste vermelho.
 */
#[CoversClass(PastaDocumentoRepository::class)]
#[CoversClass(PastaSecaoRepository::class)]
final class ResumoDaLixeiraDoEscritorioRepositoryTest extends KernelTestCase
{
    #[TestDox('documentos: só do escritório pedido e só os da lixeira, por pasta, categoria e autor')]
    public function testDocumentosSoDoEscritorio(): void
    {
        $meu    = $this->tenant();
        $alheio = $this->tenant();
        $minha  = $this->pasta($meu);
        $dele   = $this->pasta($alheio);
        $this->marcar('pasta_documento', $this->documento($minha, $meu)->getId(), '2026-10-01 09:00:00');
        $this->marcar('pasta_documento', $this->documento($minha, $meu)->getId(), '2026-10-03 09:00:00');
        $this->documento($minha, $meu); // vivo: não conta
        $this->marcar('pasta_documento', $this->documento($dele, $alheio)->getId(), '2026-10-05 09:00:00');

        $linhas = $this->comLixeira(fn (): array => $this->repo(PastaDocumentoRepository::class)->resumoDaLixeiraDoEscritorio($meu));

        self::assertSame([(int) $minha->getId()], array_values(array_unique(array_column($linhas, 'pastaId'))), 'nada do outro escritório');
        self::assertCount(1, $linhas);
        self::assertSame(2, $linhas[0]['total']);
        self::assertSame(PastaDocumento::CATEGORIA_DEMAIS, $linhas[0]['categoria']);
        self::assertSame('2026-10-01 09:00:00', substr($linhas[0]['maisAntiga'], 0, 19));
        self::assertSame('2026-10-03 09:00:00', substr($linhas[0]['maisRecente'], 0, 19));
    }

    #[TestDox('seções: só do escritório pedido e só as da lixeira')]
    public function testSecoesSoDoEscritorio(): void
    {
        $meu    = $this->tenant();
        $alheio = $this->tenant();
        $minha  = $this->pasta($meu);
        $dele   = $this->pasta($alheio);
        $this->marcar('pasta_secao', $this->secao($minha, $meu)->getId(), '2026-10-01 09:00:00');
        $this->secao($minha, $meu); // viva: não conta
        $this->marcar('pasta_secao', $this->secao($dele, $alheio)->getId(), '2026-10-05 09:00:00');

        $linhas = $this->comLixeira(fn (): array => $this->repo(PastaSecaoRepository::class)->resumoDaLixeiraDoEscritorio($meu));

        self::assertSame([[(int) $minha->getId(), 1]], array_map(static fn (array $l): array => [$l['pastaId'], $l['total']], $linhas));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function repo(string $classe): object
    {
        return static::getContainer()->get($classe);
    }

    /** @param callable(): array<mixed> $trabalho */
    private function comLixeira(callable $trabalho): array
    {
        $this->em()->clear();

        return static::getContainer()->get(AcessoALixeira::class)->comLixeiraVisivel($trabalho);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Resumo da lixeira ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function pasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('RES-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function secao(Pasta $pasta, Tenant $tenant): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome('S ' . uniqid());
        $secao->setOrdem(1);
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function documento(Pasta $pasta, Tenant $tenant): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo('doc');
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('fake-' . bin2hex(random_bytes(6)) . '.pdf');
        $doc->setNomeOriginal('doc.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    private function marcar(string $tabela, ?int $id, string $em): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE {$tabela} SET excluido_em = :em WHERE id = :id", ['em' => $em, 'id' => $id]);
    }
}
