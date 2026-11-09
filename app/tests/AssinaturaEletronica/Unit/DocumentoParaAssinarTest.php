<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Enum\CorDaLinha;
use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\SituacaoDoSignatario;
use App\AssinaturaEletronica\Enum\TipoDeLogo;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentoParaAssinar::class)]
#[CoversClass(Signatario::class)]
final class DocumentoParaAssinarTest extends TestCase
{
    #[TestDox('documento novo nasce rascunho, sem cor, com a logo da BlueJus e prazo de 7 dias')]
    public function testDocumentoNovo(): void
    {
        $documento = self::documento();

        self::assertSame(SituacaoDoDocumento::Rascunho, $documento->getSituacao());
        self::assertSame(CorDaLinha::Nenhuma, $documento->getCor());
        self::assertSame(TipoDeLogo::Bluejus, $documento->getLogoTipo());
        self::assertSame(7, $documento->getPrazoDias());
        self::assertSame('Documento', $documento->getNome());
        self::assertFalse($documento->estaNaLixeira());
        self::assertFalse($documento->temArquivo());
    }

    #[TestDox('salvar rascunho troca o conteúdo e substitui os signatários, na ordem da lista')]
    public function testAplicarRascunho(): void
    {
        $documento = self::documento();
        $documento->aplicarRascunho(self::dados(['nome' => 'Contrato', 'pasta' => 'Silva', 'signatarios' => [['nome' => 'Ana'], ['nome' => 'Bia']]]), [['k' => 't', 'x' => 'Oi', 'full' => 0]], new \DateTimeImmutable('2026-11-09 10:00:00'));
        $documento->aplicarRascunho(self::dados(['nome' => 'Contrato 2', 'signatarios' => [['nome' => 'Caio', 'nivel' => 'govbr']]]), [], new \DateTimeImmutable('2026-11-09 11:00:00'));

        self::assertSame('Contrato 2', $documento->getNome());
        self::assertNull($documento->getPastaRotulo());
        self::assertSame([], $documento->getBlocos());
        self::assertSame('2026-11-09 11:00:00', $documento->getAlteradoEm()->format('Y-m-d H:i:s'));
        $signatarios = $documento->getSignatarios();
        self::assertCount(1, $signatarios);
        self::assertSame('Caio', $signatarios[0]->getNome());
        self::assertSame(0, $signatarios[0]->getOrdem());
        self::assertSame(NivelDeAssinatura::Govbr, $signatarios[0]->getNivel());
        self::assertSame(SituacaoDoSignatario::Pendente, $signatarios[0]->getSituacao());
    }

    #[TestDox('fora do rascunho nada muda: salvar, trocar arquivo e trocar logo são recusados')]
    public function testSoRascunhoMuda(): void
    {
        $documento = self::documento();
        self::situacao($documento, SituacaoDoDocumento::Aguardando);

        foreach ([
            static fn () => $documento->aplicarRascunho(self::dados([]), [], new \DateTimeImmutable()),
            static fn () => $documento->definirArquivo('a.pdf', 'a.pdf', 1),
            static fn () => $documento->definirLogo(TipoDeLogo::Escritorio, null),
        ] as $acao) {
            try {
                $acao();
                self::fail('deveria recusar');
            } catch (AcaoRecusada $e) {
                self::assertSame('assinatura_eletronica.erro.so_rascunho', $e->getMessage());
            }
        }
    }

    #[TestDox('a chave da logo só fica na logo própria')]
    public function testChaveDaLogoSoNaPropria(): void
    {
        $documento = self::documento();
        $documento->definirLogo(TipoDeLogo::Propria, 'l.png');
        self::assertSame('l.png', $documento->getLogoChave());

        $documento->definirLogo(TipoDeLogo::Escritorio, 'l.png');
        self::assertNull($documento->getLogoChave());
        self::assertSame(TipoDeLogo::Escritorio, $documento->getLogoTipo());
    }

    #[TestDox('Lixeira: o que aguardava assinatura tem o envio cancelado; o rascunho continua rascunho')]
    public function testMoverParaLixeira(): void
    {
        $quem = new User();
        $agora = new \DateTimeImmutable('2026-11-09 12:00:00');

        $aguardando = self::documento();
        self::situacao($aguardando, SituacaoDoDocumento::Aguardando);
        self::assertTrue($aguardando->moverParaLixeira($quem, $agora));
        self::assertSame(SituacaoDoDocumento::Cancelado, $aguardando->getSituacao());
        self::assertSame($agora, $aguardando->getExcluidoEm());
        self::assertSame($quem, $aguardando->getExcluidoPor());

        $rascunho = self::documento();
        self::assertFalse($rascunho->moverParaLixeira($quem, $agora));
        self::assertSame(SituacaoDoDocumento::Rascunho, $rascunho->getSituacao());
        self::assertTrue($rascunho->estaNaLixeira());

        $this->expectException(\LogicException::class);
        $rascunho->moverParaLixeira($quem, $agora);
    }

    #[TestDox('restaurar tira da Lixeira e não reativa o envio cancelado')]
    public function testRestaurar(): void
    {
        $documento = self::documento();
        self::situacao($documento, SituacaoDoDocumento::Aguardando);
        $documento->moverParaLixeira(null, new \DateTimeImmutable());

        $documento->restaurar();

        self::assertFalse($documento->estaNaLixeira());
        self::assertNull($documento->getExcluidoPor());
        self::assertSame(SituacaoDoDocumento::Cancelado, $documento->getSituacao());

        $this->expectException(\LogicException::class);
        $documento->restaurar();
    }

    #[TestDox('só rascunho na Lixeira sai de vez')]
    public function testPodeSairDeVez(): void
    {
        $rascunho = self::documento();
        self::assertFalse($rascunho->podeSairDeVez(), 'fora da Lixeira');
        $rascunho->moverParaLixeira(null, new \DateTimeImmutable());
        self::assertTrue($rascunho->podeSairDeVez());

        $concluido = self::documento();
        self::situacao($concluido, SituacaoDoDocumento::Concluido);
        $concluido->moverParaLixeira(null, new \DateTimeImmutable());
        self::assertFalse($concluido->podeSairDeVez());
    }

    #[TestDox('cópia do Duplicar: rascunho novo com pasta, cor, conteúdo e signatários pendentes; sem arquivo nem Lixeira')]
    public function testCopia(): void
    {
        $origem = self::documento();
        $origem->aplicarRascunho(self::dados(['pasta' => 'Silva', 'mensagem' => 'Assine', 'prazoDias' => 15, 'signatarios' => [['nome' => 'Ana', 'email' => 'ana@exemplo.com.br', 'papel' => 'Cliente']]]), [['k' => 'p', 'x' => 'Texto', 'full' => 1]], new \DateTimeImmutable());
        $origem->definirArquivo('a.pdf', 'contrato.pdf', 3);
        $origem->pintar(CorDaLinha::Verde);
        self::situacao($origem, SituacaoDoDocumento::Concluido);
        (new \ReflectionProperty(Signatario::class, 'situacao'))->setValue($origem->getSignatarios()[0], SituacaoDoSignatario::Assinado);
        $origem->moverParaLixeira(null, new \DateTimeImmutable());

        $copia = DocumentoParaAssinar::copiaDe($origem, 'Contrato (cópia)', null, new \DateTimeImmutable());

        self::assertSame('Contrato (cópia)', $copia->getNome());
        self::assertSame(SituacaoDoDocumento::Rascunho, $copia->getSituacao());
        self::assertSame('Silva', $copia->getPastaRotulo());
        self::assertSame(CorDaLinha::Verde, $copia->getCor());
        self::assertSame('Assine', $copia->getMensagem());
        self::assertSame(15, $copia->getPrazoDias());
        self::assertSame($origem->getBlocos(), $copia->getBlocos());
        self::assertFalse($copia->estaNaLixeira());
        self::assertFalse($copia->temArquivo(), 'o arquivo ganha chave nova no UseCase');
        self::assertCount(1, $copia->getSignatarios());
        self::assertSame('ana@exemplo.com.br', $copia->getSignatarios()[0]->getEmail());
        self::assertSame(SituacaoDoSignatario::Pendente, $copia->getSignatarios()[0]->getSituacao());
        self::assertSame($copia, $copia->getSignatarios()[0]->getDocumento());
        self::assertSame(1, $origem->contarAssinados());
        self::assertSame(0, $copia->contarAssinados());
    }

    private static function documento(): DocumentoParaAssinar
    {
        return new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable('2026-11-09 09:00:00'));
    }

    private static function situacao(DocumentoParaAssinar $documento, SituacaoDoDocumento $situacao): void
    {
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'situacao'))->setValue($documento, $situacao);
    }

    /** @param array<string, mixed> $json */
    private static function dados(array $json): DadosDoRascunho
    {
        return DadosDoRascunho::deJson($json === [] ? ['nome' => ''] : $json);
    }
}
