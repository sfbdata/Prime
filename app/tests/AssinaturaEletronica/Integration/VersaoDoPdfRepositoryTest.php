<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Integration;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\OrigemDaVersao;
use App\AssinaturaEletronica\Enum\SituacaoDaCadeia;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As versões guardadas do PDF no banco de verdade (contrato da etapa 5, seção 2, itens 1 e 2; DAMA desfaz cada teste):
 * a última, o original e o PDF para assinar de cada documento, a ordem única por documento, o JSON da análise e da
 * cadeia gravados, e o que acontece quando o documento ou o signatário somem.
 */
#[CoversClass(VersaoDoPdfRepository::class)]
#[CoversClass(VersaoDoPdf::class)]
final class VersaoDoPdfRepositoryTest extends KernelTestCase
{
    private const SHA = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private VersaoDoPdfRepository $versoes;
    private DocumentoParaAssinarRepository $documentos;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->versoes = static::getContainer()->get(VersaoDoPdfRepository::class);
        $this->documentos = static::getContainer()->get(DocumentoParaAssinarRepository::class);
    }

    #[TestDox('documento sem versões: nenhuma última nem original; a próxima ordem é 1')]
    public function testSemVersoes(): void
    {
        $documento = $this->documento($this->escritorio());

        self::assertNull($this->versoes->ultima($documento));
        self::assertNull($this->versoes->original($documento));
        self::assertSame([], $this->versoes->doDocumento($documento));
        self::assertSame(1, $this->versoes->proximaOrdem($documento));
    }

    #[TestDox('original, PDF para assinar e devolvida: o original e a última acham a sua, a última é a de maior ordem, mesmo com o documento na Lixeira; a de outro documento não aparece')]
    public function testConsultas(): void
    {
        $tenant = $this->escritorio();
        $documento = $this->documento($tenant);
        $this->versoes->salvar(VersaoDoPdf::original($documento, 1, 'original.pdf', 'Contrato assinado.pdf', self::SHA, 100, ['v' => 1, 'assinaturas' => [['cpf' => '52998224725']]], new \DateTimeImmutable('2026-11-09 10:00:00')));
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 11:00:00'));
        $this->versoes->salvar(VersaoDoPdf::paraAssinar($documento, $this->versoes->proximaOrdem($documento), 'para.pdf', 'Contrato - para assinar.pdf', self::SHA, 200, ['v' => 1], new \DateTimeImmutable('2026-11-09 11:00:00')));
        $govbr = $documento->getSignatarios()[1];
        $this->versoes->salvar(VersaoDoPdf::devolvida($govbr, $this->versoes->proximaOrdem($documento), 'devolvida.pdf', 'assinado.pdf', self::SHA, 300, ['v' => 1], new \DateTimeImmutable('2026-11-09 12:00:00')));
        $alheio = $this->documento($this->escritorio());
        $this->versoes->salvar(VersaoDoPdf::original($alheio, 1, 'alheio.pdf', 'alheio.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));
        $documento->moverParaLixeira(null, new \DateTimeImmutable());
        $this->em()->flush();
        $this->em()->clear();

        self::assertSame(['original.pdf', 'para.pdf', 'devolvida.pdf'], array_map(static fn (VersaoDoPdf $v): string => $v->getArquivoChave(), $this->versoes->doDocumento($documento)));
        self::assertSame([1, 2, 3], array_map(static fn (VersaoDoPdf $v): int => $v->getOrdem(), $this->versoes->doDocumento($documento)));
        $ultima = $this->versoes->ultima($documento) ?? self::fail('sem a última versão');
        self::assertSame(OrigemDaVersao::Devolvida, $ultima->getOrigem());
        self::assertSame($govbr->getId(), $ultima->getSignatario()?->getId());
        self::assertSame('devolvida.pdf', $ultima->getArquivoChave());
        self::assertSame(['v' => 1, 'assinaturas' => [['cpf' => '52998224725']]], $this->versoes->original($documento)?->getAnalise());
        self::assertSame(4, $this->versoes->proximaOrdem($documento));
    }

    #[TestDox('versão atual do PDF assinado (5A): pula o PDF para assinar; sem versão ou só com ele, nula; com devolvida, a devolvida')]
    public function testUltimaQueNaoEParaAssinar(): void
    {
        $tenant = $this->escritorio();
        $semPres = $this->documento($tenant);
        self::assertNull($this->versoes->ultimaQueNaoEParaAssinar($semPres));
        $semPres->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 11:00:00'));
        $this->versoes->salvar(VersaoDoPdf::paraAssinar($semPres, 1, 'para-1.pdf', 'para.pdf', self::SHA, 200, ['v' => 1], new \DateTimeImmutable()));
        self::assertNull($this->versoes->ultimaQueNaoEParaAssinar($semPres), 'só o PDF para assinar não é versão assinada');
        $this->versoes->salvar(VersaoDoPdf::devolvida($semPres->getSignatarios()[1], 2, 'devolvida-1.pdf', 'assinado.pdf', self::SHA, 300, ['v' => 1], new \DateTimeImmutable()));
        self::assertSame('devolvida-1.pdf', $this->versoes->ultimaQueNaoEParaAssinar($semPres)?->getArquivoChave());

        $comPres = $this->documento($tenant);
        $this->versoes->salvar(VersaoDoPdf::original($comPres, 1, 'original-2.pdf', 'contrato.pdf', self::SHA, 100, ['v' => 1], new \DateTimeImmutable()));
        $comPres->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 11:00:00'));
        $this->versoes->salvar(VersaoDoPdf::paraAssinar($comPres, 2, 'para-2.pdf', 'para.pdf', self::SHA, 200, ['v' => 1], new \DateTimeImmutable()));
        $this->em()->clear();

        self::assertSame('para-2.pdf', $this->versoes->ultima($comPres)?->getArquivoChave());
        self::assertSame('original-2.pdf', $this->versoes->ultimaQueNaoEParaAssinar($comPres)?->getArquivoChave(), 'o PDF para assinar depois do original não esconde o original');
    }

    #[TestDox('a cadeia (E6-7) volta do banco com a situação e a hora')]
    public function testCadeiaGravada(): void
    {
        $documento = $this->documento($this->escritorio());
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 11:00:00'));
        $versao = VersaoDoPdf::devolvida($documento->getSignatarios()[1], 1, 'devolvida.pdf', 'assinado.pdf', self::SHA, 300, ['v' => 1], new \DateTimeImmutable());
        $versao->registrarCadeia(SituacaoDaCadeia::Confirmada, ['v' => 1, 'assinaturas' => []], new \DateTimeImmutable('2026-11-10 08:00:00'));
        $this->versoes->salvar($versao);
        $this->em()->clear();

        $relida = $this->versoes->find($versao->getId()) ?? self::fail('versão sumiu');

        self::assertSame(['v' => 1, 'assinaturas' => []], $relida->getCadeia());
        self::assertSame(SituacaoDaCadeia::Confirmada, $relida->getCadeiaSituacao());
        self::assertSame('2026-11-10 08:00:00', $relida->getCadeiaConferidaEm()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('signatário apagado: a versão fica, sem o signatário; documento apagado: as versões vão junto')]
    public function testApagar(): void
    {
        $documento = $this->documento($this->escritorio());
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 11:00:00'));
        $govbr = $documento->getSignatarios()[1];
        $versao = VersaoDoPdf::devolvida($govbr, 1, 'devolvida.pdf', 'assinado.pdf', self::SHA, 300, ['v' => 1], new \DateTimeImmutable());
        $this->versoes->salvar($versao);

        $this->conexao()->executeStatement('DELETE FROM assinatura_eletronica_signatario WHERE id = ?', [$govbr->getId()]);
        self::assertNull($this->conexao()->fetchOne('SELECT signatario_id FROM assinatura_eletronica_versao WHERE id = ?', [$versao->getId()]));

        $this->conexao()->executeStatement('DELETE FROM assinatura_eletronica_documento WHERE id = ?', [$documento->getId()]);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM assinatura_eletronica_versao WHERE documento_id = ?', [$documento->getId()]));
    }

    #[TestDox('duas versões com a mesma ordem no mesmo documento: o índice único recusa')]
    public function testOrdemUnica(): void
    {
        $documento = $this->documento($this->escritorio());
        $this->versoes->salvar(VersaoDoPdf::original($documento, 1, 'a.pdf', 'a.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->versoes->salvar(VersaoDoPdf::original($documento, 1, 'b.pdf', 'b.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));
    }

    #[TestDox('um original por documento (índice único parcial), mesmo com outra ordem')]
    public function testUmOriginalPorDocumento(): void
    {
        $documento = $this->documento($this->escritorio());
        $this->versoes->salvar(VersaoDoPdf::original($documento, 1, 'a.pdf', 'a.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->versoes->salvar(VersaoDoPdf::original($documento, 2, 'b.pdf', 'b.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));
    }

    #[TestDox('um PDF para assinar por documento (índice único parcial); devolvidas, várias')]
    public function testUmParaAssinarPorDocumento(): void
    {
        $documento = $this->documento($this->escritorio());
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 11:00:00'));
        $govbr = $documento->getSignatarios()[1];
        $this->versoes->salvar(VersaoDoPdf::paraAssinar($documento, 1, 'p.pdf', 'p.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));
        $this->versoes->salvar(VersaoDoPdf::devolvida($govbr, 2, 'd1.pdf', 'd1.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));
        $this->versoes->salvar(VersaoDoPdf::devolvida($govbr, 3, 'd2.pdf', 'd2.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));
        self::assertCount(3, $this->versoes->doDocumento($documento));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->versoes->salvar(VersaoDoPdf::paraAssinar($documento, 4, 'p2.pdf', 'p2.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));
    }

    #[TestDox('remover: o original do rascunho sai na hora, e o original novo entra em seguida com a mesma ordem')]
    public function testRemoverOriginalDoRascunho(): void
    {
        $documento = $this->documento($this->escritorio());
        $antigo = VersaoDoPdf::original($documento, 1, 'antigo.pdf', 'antigo.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable());
        $this->versoes->salvar($antigo);
        $id = $antigo->getId();

        $this->versoes->remover($antigo);
        $this->versoes->salvar(VersaoDoPdf::original($documento, $this->versoes->proximaOrdem($documento), 'novo.pdf', 'novo.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()));

        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM assinatura_eletronica_versao WHERE id = ?', [$id]));
        self::assertSame(['novo.pdf'], array_map(static fn (VersaoDoPdf $v): string => $v->getArquivoChave(), $this->versoes->doDocumento($documento)));
        self::assertSame(1, $this->versoes->original($documento)?->getOrdem());
    }

    #[TestDox('remover: versão de documento enviado é prova e fica')]
    public function testRemoverRecusaDepoisDoEnvio(): void
    {
        $documento = $this->documento($this->escritorio());
        $original = VersaoDoPdf::original($documento, 1, 'o.pdf', 'o.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable());
        $this->versoes->salvar($original);
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 11:00:00'));

        try {
            $this->versoes->remover($original);
            self::fail('versão de documento enviado não sai');
        } catch (\LogicException $e) {
            self::assertSame('Só o original de documento em rascunho sai.', $e->getMessage());
        }
        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM assinatura_eletronica_versao WHERE id = ?', [$original->getId()]));
    }

    #[TestDox('documento ainda sem id: as consultas recusam em vez de buscar por nulo')]
    public function testDocumentoSemId(): void
    {
        $documento = new DocumentoParaAssinar($this->escritorio(), null, new \DateTimeImmutable());

        $this->expectExceptionObject(new \LogicException('Documento sem id não tem versões gravadas.'));
        $this->versoes->ultima($documento);
    }

    private function escritorio(): Tenant
    {
        $tenant = (new Tenant())->setName('Escritório Versões ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** Rascunho com Ana (Simples) e Bia (gov.br). */
    private function documento(Tenant $tenant): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar($tenant, null, new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato',
            'signatarios' => [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'],
                ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr'],
            ],
        ]), [], new \DateTimeImmutable('2026-11-09 09:00:00'));
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
