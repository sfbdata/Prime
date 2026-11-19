<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\EstadoDaPropostaDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\DocumentosEPecasFerramenta;
use App\Conector\Ferramenta\DocumentosEPecasNaLixeira;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `documentos_e_pecas`, ação `excluir_de_vez` (spec mcp-remoto-oauth 6.6 e 6.7, PR 14): sempre por proposta, que não
 * apaga nada; a confirmação apaga a linha e, DEPOIS do COMMIT, o arquivo (INV-6), e a proposta sai confirmada mesmo com
 * a purga limpando o EntityManager. Arquivo que outra linha ainda usa fica. Item vivo, de outra pasta, de outro
 * escritório, quem não edita, aba Documentos oculta e contrato com o Financeiro oculto na subárvore: recusa sem gravar.
 * Item restaurado entre a proposta e o sim: a confirmação recusa e nada sai.
 *
 * O armazenamento é o dublê em memória no container (o mesmo da `DocumentosEPecasFerramentaTest`).
 */
#[CoversClass(DocumentosEPecasNaLixeira::class)]
final class ExcluirDeVezPeloConectorTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const EDITOR = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];
    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;
    private ArmazenamentoEmMemoriaNoContainer $disco;
    private EscritorioDoConector $doConector;
    private ConsentimentoDoConector $consentimento;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::EDITOR);
        $this->doConector = new EscritorioDoConector($this->escritorio);
        $this->doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($this->doConector);
        $app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable());
        $this->em()->persist($app);
        $this->consentimento = new ConsentimentoDoConector($this->pessoa, $this->escritorio, $app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($this->consentimento);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('a proposta não apaga nada e o resumo diz que é irreversível, com os nomes; a confirmação apaga a linha e, depois do COMMIT, o arquivo')]
    public function testPropostaEConfirmacao(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'procuracao.pdf', naLixeira: true);
        $vizinho = $this->documento($pasta, 'vizinho.pdf', naLixeira: true);
        $chave = $this->chaveDe($doc);

        $proposta = $this->propor(['acao' => 'excluir_de_vez', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()]]);

        self::assertStringContainsString('Excluir DE VEZ', $proposta['resumo']);
        self::assertStringContainsString('1 documento(s): "procuracao.pdf"', $proposta['resumo']);
        self::assertStringContainsString('Não dá para desfazer', $proposta['resumo']);
        self::assertSame(1, $this->linhas('pasta_documento', (int) $doc->getId()));
        self::assertTrue($this->disco->memoria->existe($chave));

        $nivelDeFora = $this->em()->getConnection()->getTransactionNestingLevel();
        $noMomento = null;
        $this->disco->memoria->aoExcluir = function () use (&$noMomento, $doc): void {
            $noMomento = [$this->em()->getConnection()->getTransactionNestingLevel(), $this->linhas('pasta_documento', (int) $doc->getId())];
        };
        $resposta = $this->confirmar($proposta);

        self::assertSame('confirmada', $resposta->dados['estado']);
        self::assertSame(['documentos' => 1, 'subpastas' => 0], $resposta->dados['resultado']['excluidos_de_vez']);
        self::assertSame([$nivelDeFora, 0], $noMomento, 'o arquivo saiu com a transação da confirmação já confirmada e a linha já fora do banco');
        self::assertSame(0, $this->linhas('pasta_documento', (int) $doc->getId()));
        self::assertFalse($this->disco->memoria->existe($chave));
        self::assertSame(1, $this->linhas('pasta_documento', (int) $vizinho->getId()), 'só sai o que foi pedido');
        self::assertTrue($this->disco->memoria->existe($this->chaveDe($vizinho)));
        self::assertSame(EstadoDaPropostaDoConector::Confirmada->value, $this->em()->getConnection()->fetchOne('SELECT estado FROM conector_ia_proposta WHERE tenant_id = ?', [$this->escritorio->getId()]));
    }

    #[TestDox('subpasta na lixeira leva a subárvore inteira, linhas e arquivos')]
    public function testSubpastaLevaASubarvore(): void
    {
        $pasta = $this->pasta();
        $mae = $this->secao($pasta, 'Mae', naLixeira: true);
        $filha = $this->secao($pasta, 'Filha', naLixeira: true, pai: $mae);
        $dentro = $this->documento($pasta, 'dentro.pdf', naLixeira: true, secao: $filha);
        $chave = $this->chaveDe($dentro);

        $proposta = $this->propor(['acao' => 'excluir_de_vez', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'subpastas' => [$mae->getId()]]);
        self::assertStringContainsString('1 subpasta(s), com tudo o que está dentro delas: "MAE"', $proposta['resumo']);

        $resposta = $this->confirmar($proposta);

        self::assertSame(['documentos' => 1, 'subpastas' => 2], $resposta->dados['resultado']['excluidos_de_vez']);
        self::assertSame(0, $this->linhas('pasta_secao', (int) $mae->getId()));
        self::assertSame(0, $this->linhas('pasta_secao', (int) $filha->getId()));
        self::assertSame(0, $this->linhas('pasta_documento', (int) $dentro->getId()));
        self::assertFalse($this->disco->memoria->existe($chave));
    }

    #[TestDox('arquivo que outra linha ainda referencia fica no armazenamento; a linha pedida sai')]
    public function testArquivoComOutraReferenciaFica(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'compartilhado.pdf', naLixeira: true);
        $copia = $this->documento($pasta, 'copia.pdf', naLixeira: false, caminho: (string) $doc->getCaminhoArquivo());

        $this->confirmar($this->propor(['acao' => 'excluir_de_vez', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()]]));

        self::assertSame(0, $this->linhas('pasta_documento', (int) $doc->getId()));
        self::assertSame(1, $this->linhas('pasta_documento', (int) $copia->getId()));
        self::assertTrue($this->disco->memoria->existe($this->chaveDe($doc)));
        self::assertSame([], $this->disco->memoria->excluidas);
    }

    #[TestDox('item vivo, de outra pasta, de outro escritório, contrato oculto na subárvore e aba Documentos oculta: "não encontrado", sem proposta')]
    public function testRecusas(): void
    {
        $pasta = $this->pasta();
        $vivo = $this->documento($pasta, 'vivo.pdf', naLixeira: false);
        $daOutraPasta = $this->documento($this->pasta(), 'irma.pdf', naLixeira: true);
        $outroEscritorio = $this->escritorio('Outro');
        $alheio = $this->documento($this->pasta($outroEscritorio), 'alheio.pdf', naLixeira: true, escritorio: $outroEscritorio);
        $comContrato = $this->pasta();
        $secao = $this->secao($comContrato, 'Honorarios', naLixeira: true);
        $this->documento($comContrato, 'contrato.pdf', naLixeira: true, secao: $secao, categoria: PastaDocumento::CATEGORIA_CONTRATO);
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $comContrato, AbaDaPasta::Financeiro, $this->pessoa(), new \DateTimeImmutable()));
        $oculta = $this->pasta();
        $naOculta = $this->documento($oculta, 'oculta.pdf', naLixeira: true);
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $oculta, AbaDaPasta::Documentos, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();

        foreach ([
            ['acao' => 'excluir_de_vez', 'pasta_id' => $pasta->getId(), 'documentos' => [$vivo->getId()]],
            ['acao' => 'excluir_de_vez', 'pasta_id' => $pasta->getId(), 'documentos' => [$daOutraPasta->getId()]],
            ['acao' => 'excluir_de_vez', 'pasta_id' => $pasta->getId(), 'documentos' => [$alheio->getId()]],
            ['acao' => 'excluir_de_vez', 'pasta_id' => $comContrato->getId(), 'subpastas' => [$secao->getId()]],
            ['acao' => 'excluir_de_vez', 'pasta_id' => $oculta->getId(), 'documentos' => [$naOculta->getId()]],
        ] as $argumentos) {
            $this->assertMotivo($argumentos, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        $this->assertMotivo(['acao' => 'excluir_de_vez', 'pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta'));
        self::assertSame([], $this->disco->memoria->excluidas);
    }

    #[TestDox('quem só vê a pasta não propõe excluir de vez, e sem mcp:escrita é recusado')]
    public function testSemEditarESemEscopo(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'a.pdf', naLixeira: true);
        $leitor = $this->pessoa();
        $this->vinculo($leitor, $this->escritorio, permissoes: self::LEITOR);
        $this->doConector->ligar($leitor, new \DateTimeImmutable());
        $this->em()->flush();
        $argumentos = ['acao' => 'excluir_de_vez', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()]];

        $this->assertMotivo($argumentos, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $this->chamada($leitor));
        $this->assertMotivo($argumentos, ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->chamada(escopos: ['mcp:leitura']));

        self::assertSame(1, $this->linhas('pasta_documento', (int) $doc->getId()));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta'));
    }

    #[TestDox('reconferir: documento restaurado entre a proposta e o sim faz a confirmação recusar; nada sai e a proposta continua pendente')]
    public function testReconferirItemRestaurado(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'a.pdf', naLixeira: true);
        $proposta = $this->propor(['acao' => 'excluir_de_vez', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()]]);
        $this->em()->getConnection()->executeStatement('UPDATE pasta_documento SET excluido_em = NULL, excluido_por_id = NULL WHERE id = ?', [$doc->getId()]);

        $this->assertConfirmacaoRecusada($proposta, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);

        self::assertSame(1, $this->linhas('pasta_documento', (int) $doc->getId()));
        self::assertTrue($this->disco->memoria->existe($this->chaveDe($doc)));
        self::assertSame(EstadoDaPropostaDoConector::Pendente->value, $this->em()->getConnection()->fetchOne('SELECT estado FROM conector_ia_proposta WHERE tenant_id = ?', [$this->escritorio->getId()]));
    }

    // ── Apoio ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos, ?ChamadaDoConector $chamada = null): RespostaDaFerramenta
    {
        try {
            return static::getContainer()->get(DocumentosEPecasFerramenta::class)->executar($argumentos, $chamada ?? $this->chamada());
        } finally {
            $this->reanexar();
        }
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @return array{proposta_id: string, resumo: string, expira_em: string}
     */
    private function propor(array $argumentos): array
    {
        $resposta = $this->executar($argumentos);
        self::assertSame('aguardando_confirmacao', $resposta->dados['estado']);

        return $resposta->dados['proposta'];
    }

    /** @param array{proposta_id: string, resumo: string} $proposta */
    private function confirmar(array $proposta): RespostaDaFerramenta
    {
        $chamada = $this->chamada();
        try {
            return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar(['proposta_id' => $proposta['proposta_id'], 'resumo' => $proposta['resumo']], $chamada);
        } finally {
            $this->reanexar();
        }
    }

    /** @param array{proposta_id: string, resumo: string} $proposta */
    private function assertConfirmacaoRecusada(array $proposta, string $motivo): void
    {
        try {
            $this->confirmar($proposta);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        }
        self::fail('a confirmação deveria recusar com ' . $motivo);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo, ?ChamadaDoConector $chamada = null): void
    {
        try {
            $this->executar($argumentos, $chamada);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo, $e->getMessage());

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    /** @param list<string> $escopos */
    private function chamada(?User $pessoa = null, array $escopos = ['mcp:leitura', 'mcp:escrita']): ChamadaDoConector
    {
        static::getContainer()->get('services_resetter')->reset();
        $this->reanexar();
        $pessoa ??= $this->pessoa;
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, (int) $this->consentimento->getId(), 'claude.ai', $escopos, new \DateTimeImmutable('+7 days'));
    }

    /** Relê do banco o cenário do setUp: a chamada e a confirmação soltam as entidades do EntityManager. */
    private function reanexar(): void
    {
        foreach (['escritorio', 'pessoa', 'doConector', 'consentimento'] as $campo) {
            $entidade = $this->{$campo};
            $this->{$campo} = $this->em()->find($entidade::class, $entidade->getId()) ?? self::fail('entidade sumiu');
        }
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('LIX-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function secao(Pasta $pasta, string $nome, bool $naLixeira, ?PastaSecao $pai = null): PastaSecao
    {
        $secao = (new PastaSecao())->setPasta($pasta)->setTenant($pasta->getTenant())->setNome($nome);
        $secao->setPai($pai);
        if ($naLixeira) {
            $secao->marcarExcluido($this->pessoa, new \DateTimeImmutable('-1 hour'));
        }
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function documento(Pasta $pasta, string $nome, bool $naLixeira, ?PastaSecao $secao = null, ?Tenant $escritorio = null, string $categoria = PastaDocumento::CATEGORIA_DEMAIS, ?string $caminho = null): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTenant($escritorio ?? $this->escritorio);
        $doc->setPasta($pasta);
        $doc->setSecao($secao);
        $doc->setTitulo($nome);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo($caminho ?? uniqid('lix', true) . '.pdf');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        if ($naLixeira) {
            $doc->marcarExcluido($this->pessoa, new \DateTimeImmutable('-1 hour'));
        }
        $this->em()->persist($doc);
        $this->em()->flush();
        if ($caminho === null) {
            $this->disco->memoria->semear(ChavesDePasta::documento($doc), '%PDF-1.7 ' . $nome);
        }

        return $doc;
    }

    private function chaveDe(PastaDocumento $doc): ChaveDeArquivo
    {
        return ChavesDePasta::documentoPorNome((int) $doc->getTenant()?->getId(), (string) $doc->getCaminhoArquivo());
    }

    private function linhas(string $tabela, int $id): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE id = ?', [$id]);
    }
}
