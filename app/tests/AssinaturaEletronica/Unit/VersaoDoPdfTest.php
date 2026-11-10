<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Enum\OrigemDaVersao;
use App\AssinaturaEletronica\Enum\SituacaoDaCadeia;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As invariantes da versão guardada do PDF (contrato da etapa 5, seção 2, itens 1 e 2): de onde ela vem e em que
 * situação do documento, quem a devolve, o arquivo (ordem, chave, nome, sha256, tamanho), a análise versionada e a
 * cadeia da etapa 6 (E6-7), gravada uma vez.
 */
#[CoversClass(VersaoDoPdf::class)]
final class VersaoDoPdfTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ANALISE = ['v' => 1, 'situacao' => 'integro'];

    #[TestDox('original: só no rascunho, sem signatário, com o arquivo, a análise e a hora em segundos')]
    public function testOriginal(): void
    {
        $documento = self::rascunho();

        $versao = VersaoDoPdf::original($documento, 1, 'original.pdf', 'Contrato assinado.pdf', self::SHA, 1234, self::ANALISE, new \DateTimeImmutable('2026-11-09 10:00:00.750'));

        self::assertSame($documento, $versao->getDocumento());
        self::assertNull($versao->getSignatario());
        self::assertSame(OrigemDaVersao::Original, $versao->getOrigem());
        self::assertSame(1, $versao->getOrdem());
        self::assertSame('original.pdf', $versao->getArquivoChave());
        self::assertSame('Contrato assinado.pdf', $versao->getNomeOriginal());
        self::assertSame(self::SHA, $versao->getSha256());
        self::assertSame(1234, $versao->getTamanho());
        self::assertSame(self::ANALISE, $versao->getAnalise());
        self::assertSame('2026-11-09 10:00:00.000000', $versao->getCriadoEm()->format('Y-m-d H:i:s.u'));
        self::assertNull($versao->getCadeia());
        self::assertNull($versao->getCadeiaSituacao());
        self::assertFalse($versao->cadeiaConfirmada());

        $enviado = self::enviado();
        self::assertLogica('O original só é guardado no rascunho.', static fn () => VersaoDoPdf::original($enviado, 1, 'o.pdf', 'o.pdf', self::SHA, 1, self::ANALISE, new \DateTimeImmutable()));
    }

    #[TestDox('para assinar: só de documento enviado e aguardando assinatura, sem signatário')]
    public function testParaAssinar(): void
    {
        $versao = VersaoDoPdf::paraAssinar(self::enviado(), 1, 'para.pdf', 'para_assinar_contrato.pdf', self::SHA, 99, self::ANALISE, new \DateTimeImmutable());

        self::assertSame(OrigemDaVersao::ParaAssinar, $versao->getOrigem());
        self::assertNull($versao->getSignatario());

        $rascunho = self::rascunho();
        self::assertLogica('Só documento enviado tem PDF para assinar ou devolvido.', static fn () => VersaoDoPdf::paraAssinar($rascunho, 1, 'p.pdf', 'p.pdf', self::SHA, 1, self::ANALISE, new \DateTimeImmutable()));
        $cancelado = self::enviado();
        $cancelado->moverParaLixeira(null, new \DateTimeImmutable());
        self::assertLogica('O PDF para assinar só é gerado com o documento aguardando assinatura.', static fn () => VersaoDoPdf::paraAssinar($cancelado, 1, 'p.pdf', 'p.pdf', self::SHA, 1, self::ANALISE, new \DateTimeImmutable()));
    }

    #[TestDox('remoção: só o original de documento em rascunho sai; o PDF para assinar, a devolvida e o original depois do envio, não')]
    public function testRemovivel(): void
    {
        $documento = self::rascunho();
        $original = VersaoDoPdf::original($documento, 1, 'o.pdf', 'o.pdf', self::SHA, 1, self::ANALISE, new \DateTimeImmutable());
        $original->exigirRemovivel();

        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 10:00:00'));
        $paraAssinar = VersaoDoPdf::paraAssinar($documento, 2, 'p.pdf', 'p.pdf', self::SHA, 1, self::ANALISE, new \DateTimeImmutable());
        $devolvida = VersaoDoPdf::devolvida($documento->getSignatarios()[1], 3, 'd.pdf', 'd.pdf', self::SHA, 1, self::ANALISE, new \DateTimeImmutable());
        foreach ([$original, $paraAssinar, $devolvida] as $versao) {
            self::assertLogica('Só o original de documento em rascunho sai.', static fn () => $versao->exigirRemovivel());
        }
    }

    #[TestDox('devolvida: do signatário gov.br ou ICP de documento enviado; o documento é o do signatário')]
    public function testDevolvida(): void
    {
        $documento = self::enviado();
        [$simples, $govbr, $icp] = $documento->getSignatarios();

        $versao = VersaoDoPdf::devolvida($govbr, 2, 'dev.pdf', 'assinado.pdf', self::SHA, 10, self::ANALISE, new \DateTimeImmutable());
        self::assertSame(OrigemDaVersao::Devolvida, $versao->getOrigem());
        self::assertSame($govbr, $versao->getSignatario());
        self::assertSame($documento, $versao->getDocumento());
        self::assertSame($icp, VersaoDoPdf::devolvida($icp, 3, 'dev2.pdf', 'assinado.pdf', self::SHA, 10, self::ANALISE, new \DateTimeImmutable())->getSignatario());

        self::assertLogica('Só signatário gov.br ou ICP devolve PDF assinado.', static fn () => VersaoDoPdf::devolvida($simples, 2, 'd.pdf', 'd.pdf', self::SHA, 1, self::ANALISE, new \DateTimeImmutable()));
        $govbrDoRascunho = self::rascunho()->getSignatarios()[1];
        self::assertLogica('Só documento enviado tem PDF para assinar ou devolvido.', static fn () => VersaoDoPdf::devolvida($govbrDoRascunho, 1, 'd.pdf', 'd.pdf', self::SHA, 1, self::ANALISE, new \DateTimeImmutable()));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function arquivosInvalidos(): iterable
    {
        yield 'ordem zero' => [['ordem' => 0], 'Ordem da versão fora de 1 a 32767.'];
        yield 'ordem acima do smallint' => [['ordem' => 32768], 'Ordem da versão fora de 1 a 32767.'];
        yield 'chave vazia' => [['chave' => ''], 'Versão sem chave de arquivo ou com chave longa demais.'];
        yield 'chave longa' => [['chave' => str_repeat('a', 256)], 'Versão sem chave de arquivo ou com chave longa demais.'];
        yield 'nome em branco' => [['nome' => '   '], 'Versão sem nome do arquivo ou com nome longo demais.'];
        yield 'nome longo' => [['nome' => str_repeat('é', 256)], 'Versão sem nome do arquivo ou com nome longo demais.'];
        yield 'sha256 maiúsculo' => [['sha' => strtoupper(self::SHA)], 'sha256 da versão fora do formato (64 hexadecimais minúsculos).'];
        yield 'sha256 curto' => [['sha' => 'abc'], 'sha256 da versão fora do formato (64 hexadecimais minúsculos).'];
        yield 'arquivo vazio' => [['tamanho' => 0], 'Versão com arquivo vazio.'];
        yield 'análise sem versão' => [['analise' => ['situacao' => 'integro']], 'JSON da versão sem o número da versão do formato ("v").'];
        yield 'análise com versão em texto' => [['analise' => ['v' => '1']], 'JSON da versão sem o número da versão do formato ("v").'];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('arquivosInvalidos')]
    #[TestDox('recusa o arquivo fora do formato: $_dataName')]
    public function testArquivoInvalido(array $troca, string $mensagem): void
    {
        $documento = self::rascunho();
        $dados = array_merge(['ordem' => 1, 'chave' => 'o.pdf', 'nome' => 'o.pdf', 'sha' => self::SHA, 'tamanho' => 1, 'analise' => self::ANALISE], $troca);

        self::assertLogica($mensagem, static fn () => VersaoDoPdf::original($documento, $dados['ordem'], $dados['chave'], $dados['nome'], $dados['sha'], $dados['tamanho'], $dados['analise'], new \DateTimeImmutable()));
    }

    #[TestDox('nome com 255 caracteres acentuados e ordem 32767 passam')]
    public function testLimites(): void
    {
        $versao = VersaoDoPdf::original(self::rascunho(), 32767, 'o.pdf', str_repeat('é', 255), self::SHA, 1, self::ANALISE, new \DateTimeImmutable());

        self::assertSame(32767, $versao->getOrdem());
    }

    #[TestDox('cadeia (E6-7): confirmada grava o JSON, a situação e a hora; depois dela, não troca')]
    public function testCadeiaConfirmada(): void
    {
        $versao = self::devolvida();
        $cadeia = ['v' => 1, 'assinaturas' => []];

        $versao->registrarCadeia(SituacaoDaCadeia::Confirmada, $cadeia, new \DateTimeImmutable('2026-11-10 08:00:00.500'));

        self::assertSame($cadeia, $versao->getCadeia());
        self::assertSame(SituacaoDaCadeia::Confirmada, $versao->getCadeiaSituacao());
        self::assertSame('2026-11-10 08:00:00.000000', $versao->getCadeiaConferidaEm()?->format('Y-m-d H:i:s.u'));
        self::assertTrue($versao->cadeiaConfirmada());
        self::assertLogica('A cadeia desta versão já foi conferida.', static fn () => $versao->registrarCadeia(SituacaoDaCadeia::Recusada, $cadeia, new \DateTimeImmutable()));
    }

    #[TestDox('cadeia indisponível nunca é gravada (E6-4); recusada exige o JSON versionado e também não troca')]
    public function testCadeiaIndisponivel(): void
    {
        $versao = self::devolvida();
        self::assertLogica('Cadeia indisponível não é gravada (E6-4).', static fn () => $versao->registrarCadeia(SituacaoDaCadeia::Indisponivel, ['v' => 1], new \DateTimeImmutable()));
        self::assertNull($versao->getCadeiaSituacao());
        self::assertLogica('JSON da versão sem o número da versão do formato ("v").', static fn () => $versao->registrarCadeia(SituacaoDaCadeia::Recusada, ['assinaturas' => []], new \DateTimeImmutable()));
        self::assertNull($versao->getCadeiaSituacao());

        $versao->registrarCadeia(SituacaoDaCadeia::Recusada, ['v' => 1, 'assinaturas' => []], new \DateTimeImmutable());
        self::assertSame(SituacaoDaCadeia::Recusada, $versao->getCadeiaSituacao());
        self::assertFalse($versao->cadeiaConfirmada());
        self::assertLogica('A cadeia desta versão já foi conferida.', static fn () => $versao->registrarCadeia(SituacaoDaCadeia::Confirmada, ['v' => 1, 'assinaturas' => []], new \DateTimeImmutable()));
    }

    private static function devolvida(): VersaoDoPdf
    {
        return VersaoDoPdf::devolvida(self::enviado()->getSignatarios()[1], 2, 'dev.pdf', 'assinado.pdf', self::SHA, 10, self::ANALISE, new \DateTimeImmutable());
    }

    /** Rascunho com um signatário Simples, um gov.br e um ICP. */
    private static function rascunho(): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar(new Tenant(), null, new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato',
            'signatarios' => [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'],
                ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr'],
                ['nome' => 'Caio Reis', 'email' => 'caio@exemplo.com', 'nivel' => 'icp'],
            ],
        ]), [], new \DateTimeImmutable('2026-11-09 09:00:00'));

        return $documento;
    }

    private static function enviado(): DocumentoParaAssinar
    {
        $documento = self::rascunho();
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 10:00:00'));

        return $documento;
    }

    private static function assertLogica(string $mensagem, \Closure $acao): void
    {
        try {
            $acao();
            self::fail('deveria recusar com: ' . $mensagem);
        } catch (\LogicException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
    }
}
