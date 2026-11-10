<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Integration;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\DTO\ResumoDoDocumento;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As consultas da tela Assinatura no banco de verdade (DAMA desfaz cada teste). Etapa 3: o código de validação (a porta
 * da validação pública e a colisão do envio) e o PDF final entre os arquivos em uso.
 */
#[CoversClass(DocumentoParaAssinarRepository::class)]
#[CoversClass(ResumoDoDocumento::class)]
final class DocumentoParaAssinarRepositoryTest extends KernelTestCase
{
    private DocumentoParaAssinarRepository $documentos;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->documentos = static::getContainer()->get(DocumentoParaAssinarRepository::class);
    }

    #[TestDox('travar e reler: o estado gravado (Lixeira incluída) passa por cima do que estava em memória')]
    public function testTravarERecarregarReleOEstado(): void
    {
        $tenant = $this->escritorio();
        $documento = $this->documento($tenant, 'Contrato');
        $this->conexao()->executeStatement("UPDATE assinatura_eletronica_documento SET excluido_em = NOW(), situacao = 'aguardando' WHERE id = ?", [$documento->getId()]);
        self::assertFalse($documento->estaNaLixeira(), 'em memória, ainda a leitura antiga');

        $achou = $this->em()->wrapInTransaction(fn (): bool => $this->documentos->travarERecarregar($documento, $tenant));

        self::assertTrue($achou);
        self::assertTrue($documento->estaNaLixeira());
        self::assertFalse($documento->ehRascunho());
    }

    #[TestDox('travar e reler: documento de outro escritório ou que sumiu devolve falso')]
    public function testTravarERecarregarForaDoEscritorio(): void
    {
        $tenant = $this->escritorio();
        $documento = $this->documento($tenant, 'Contrato');

        self::assertFalse($this->em()->wrapInTransaction(fn (): bool => $this->documentos->travarERecarregar($documento, $this->escritorio())));

        $this->conexao()->executeStatement('DELETE FROM assinatura_eletronica_documento WHERE id = ?', [$documento->getId()]);
        self::assertFalse($this->em()->wrapInTransaction(fn (): bool => $this->documentos->travarERecarregar($documento, $tenant)));
    }

    #[TestDox('lista: só os campos do Resumo, sem carregar a entidade (nem os blocos), só do escritório, Lixeira incluída')]
    public function testResumosSemCarregarAEntidade(): void
    {
        $tenant = $this->escritorio();
        $a = $this->documento($tenant, 'Contrato', [['nome' => 'Ana'], ['nome' => 'Bia']]);
        $b = $this->documento($tenant, 'Na Lixeira', [['nome' => 'Caio']]);
        $b->moverParaLixeira(null, new \DateTimeImmutable());
        $this->documento($this->escritorio(), 'De outro escritório');
        $this->em()->flush();
        $esperados = array_map(ResumoDoDocumento::deDocumento(...), [$b, $a]);
        $this->em()->clear();

        $resumos = $this->documentos->resumosDoEscritorio($tenant);

        self::assertEquals($esperados, $resumos);
        self::assertSame([], $this->em()->getUnitOfWork()->getIdentityMap()[DocumentoParaAssinar::class] ?? [], 'nenhum documento hidratado');
    }

    #[TestDox('arquivos em uso: PDF ou logo de qualquer documento do escritório, Lixeira incluída; de outro escritório não conta')]
    public function testArquivosEmUso(): void
    {
        $tenant = $this->escritorio();
        $comPdf = $this->documento($tenant, 'Com PDF', [], [['k' => 'pg', 'n' => 1]]);
        $comPdf->definirArquivo('pdf-1.pdf', 'contrato.pdf', 1);
        $comLogo = $this->documento($tenant, 'Com logo');
        $comLogo->definirLogo(TipoDeLogo::Propria, 'logo-1.png');
        $comLogo->moverParaLixeira(null, new \DateTimeImmutable());
        $alheio = $this->documento($this->escritorio(), 'Alheio');
        $alheio->definirLogo(TipoDeLogo::Propria, 'logo-2.png');
        $this->em()->flush();

        self::assertSame(['pdf-1.pdf', 'logo-1.png'], $this->documentos->arquivosEmUso($tenant, ['pdf-1.pdf', 'logo-1.png', 'logo-2.png', 'solto.png']));
        self::assertSame([], $this->documentos->arquivosEmUso($tenant, []));
    }

    #[TestDox('etapa 3: o PDF final com selo também é arquivo em uso; o de outro escritório não conta')]
    public function testArquivosEmUsoComPdfFinal(): void
    {
        $tenant = $this->escritorio();
        $concluido = $this->documento($tenant, 'Concluído');
        $alheio = $this->documento($this->escritorio(), 'Alheio');
        $this->conexao()->executeStatement('UPDATE assinatura_eletronica_documento SET arquivo_final_chave = ? WHERE id = ?', ['final-1.pdf', $concluido->getId()]);
        $this->conexao()->executeStatement('UPDATE assinatura_eletronica_documento SET arquivo_final_chave = ? WHERE id = ?', ['final-2.pdf', $alheio->getId()]);

        self::assertSame(['final-1.pdf'], $this->documentos->arquivosEmUso($tenant, ['final-1.pdf', 'final-2.pdf']));
    }

    #[TestDox('etapa 3: pelo código, acha o documento de qualquer escritório, mesmo na Lixeira; código desconhecido, nada; a lista leva o código')]
    public function testPorCodigo(): void
    {
        $tenant = $this->escritorio();
        $documento = $this->documento($tenant, 'Contrato');
        $this->conexao()->executeStatement(
            "UPDATE assinatura_eletronica_documento SET codigo = 'BJA-AAAA-BBBB-CCCC', excluido_em = NOW(), situacao = 'aguardando' WHERE id = ?",
            [$documento->getId()],
        );
        $this->em()->clear();

        self::assertSame($documento->getId(), $this->documentos->porCodigo('BJA-AAAA-BBBB-CCCC')?->getId());
        self::assertNull($this->documentos->porCodigo('BJA-ZZZZ-ZZZZ-ZZZZ'));
        self::assertNull($this->documentos->porCodigo('bja-aaaa-bbbb-cccc'), 'a comparação é exata; quem chama normaliza');
        self::assertSame('BJA-AAAA-BBBB-CCCC', $this->documentos->resumosDoEscritorio($tenant)[0]->codigo);
    }

    #[TestDox('etapa 3: código em uso vale para todos os escritórios e a Lixeira, mesmo com o filtro de escritório ligado em outro')]
    public function testCodigoEmUso(): void
    {
        $documento = $this->documento($this->escritorio(), 'Contrato');
        $this->conexao()->executeStatement(
            "UPDATE assinatura_eletronica_documento SET codigo = 'BJA-DDDD-EEEE-FFFF', excluido_em = NOW() WHERE id = ?",
            [$documento->getId()],
        );
        $outro = $this->escritorio();
        $this->em()->getFilters()->enable('tenant')->setParameter('tenant', $outro->getId(), Types::INTEGER);

        try {
            self::assertTrue($this->documentos->codigoEmUso('BJA-DDDD-EEEE-FFFF'));
            self::assertFalse($this->documentos->codigoEmUso('BJA-GGGG-HHHH-JJJJ'));
        } finally {
            $this->em()->getFilters()->disable('tenant');
        }
    }

    private function escritorio(): Tenant
    {
        $tenant = (new Tenant())->setName('Escritório Assinatura ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /**
     * @param list<array<string, mixed>> $signatarios
     * @param list<array<string, mixed>> $blocos
     */
    private function documento(Tenant $tenant, string $nome, array $signatarios = [], array $blocos = []): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar($tenant, null, new \DateTimeImmutable('2026-11-09 10:00:00'));
        $documento->aplicarRascunho(DadosDoRascunho::deJson(['nome' => $nome, 'signatarios' => $signatarios]), $blocos, new \DateTimeImmutable('2026-11-09 10:00:00'));
        $this->documentos->salvar($documento);

        return $documento;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function conexao(): Connection
    {
        return $this->em()->getConnection();
    }
}
