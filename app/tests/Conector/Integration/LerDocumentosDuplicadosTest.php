<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\LerDocumentosDuplicados;
use App\Conector\Ferramenta\LerDocumentosEModelosFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ler_documentos_e_modelos`, acao `duplicados` (M9 de 26/11/2026, item 3), com o banco de verdade: o relatório dos
 * repetidos do escritório e de uma pasta, os dois critérios separados (hash igual e, sem hash, mesmo nome e tamanho),
 * o que fica de fora (lixeira, pasta com lápide, arquivo vazio, outra pasta, outro escritório) e o acesso (segredo de
 * justiça, cadeado, aba Documentos e o contrato com a aba Financeiro oculta). Nada é gravado.
 */
#[CoversClass(LerDocumentosDuplicados::class)]
#[CoversClass(LerDocumentosEModelosFerramenta::class)]
final class LerDocumentosDuplicadosTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const EDITOR = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];
    private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::EDITOR);
        $doConector = new EscritorioDoConector($this->escritorio);
        $doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($doConector);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('escritório: conta por pasta os grupos e as cópias a mais, por hash e, sem hash, por nome e tamanho, e só isso')]
    public function testEscritorio(): void
    {
        $muitas = $this->pasta();
        // A remessa repetida: o mesmo arquivo três vezes (duas cópias a mais) e outro em dobro, todos com hash.
        $this->documento($muitas, 'rg.pdf', 10, self::HASH_A, '2026-05-30');
        $this->documento($muitas, 'rg.pdf', 10, self::HASH_A, '2026-07-11');
        $this->documento($muitas, 'rg (1).pdf', 10, self::HASH_A, '2026-07-13');
        $this->documento($muitas, 'cnh.pdf', 20, self::HASH_B, '2026-05-30');
        $this->documento($muitas, 'cnh.pdf', 20, self::HASH_B, '2026-07-11');
        // Sem hash: mesmo nome e tamanho é provável; nome igual com tamanho diferente não é.
        $this->documento($muitas, 'procuracao.pdf', 30, null, '2026-05-30');
        $this->documento($muitas, 'procuracao.pdf', 30, null, '2026-07-11');
        $this->documento($muitas, 'contrato.pdf', 40, null, '2026-05-30');
        $this->documento($muitas, 'contrato.pdf', 41, null, '2026-07-11');

        $poucas = $this->pasta();
        $this->documento($poucas, 'a.pdf', 10, self::HASH_A);
        $this->documento($poucas, 'b.pdf', 10, self::HASH_A);

        // Fora: o mesmo hash em outra pasta (não é repetido DENTRO dela), um da dupla na lixeira, arquivo vazio, pasta
        // com lápide e outro escritório.
        $sozinha = $this->pasta();
        $this->documento($sozinha, 'rg.pdf', 10, self::HASH_A);
        $comLixeira = $this->pasta();
        $this->documento($comLixeira, 'x.pdf', 10, self::HASH_B);
        $this->documento($comLixeira, 'x.pdf', 10, self::HASH_B)->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $vazios = $this->pasta();
        $this->documento($vazios, 'vazio.pdf', 0, null);
        $this->documento($vazios, 'vazio.pdf', 0, null);
        $excluida = $this->pasta();
        $this->documento($excluida, 'y.pdf', 10, self::HASH_A);
        $this->documento($excluida, 'y.pdf', 10, self::HASH_A);
        $excluida->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $outro = $this->escritorio('Outro');
        $alheia = $this->pasta($outro);
        $this->documento($alheia, 'z.pdf', 10, self::HASH_A, tenant: $outro);
        $this->documento($alheia, 'z.pdf', 10, self::HASH_A, tenant: $outro);
        $this->em()->flush();

        $resposta = $this->executar([]);

        self::assertSame('escritorio', $resposta->dados['recorte']);
        self::assertSame(['grupos' => 3, 'copias_a_mais' => 4, 'pastas' => 2], $resposta->dados['totais']['conteudo']);
        self::assertSame(['grupos' => 1, 'copias_a_mais' => 1, 'pastas' => 1], $resposta->dados['totais']['provavel']);
        self::assertSame(2, $resposta->dados['totais']['pastas_com_repetidos']);
        // Sem hash e vivos: os 4 da pasta cheia e os 2 vazios.
        self::assertSame(6, $resposta->dados['totais']['documentos_sem_hash']);
        self::assertSame(sprintf(LerDocumentosDuplicados::AVISO_SEM_HASH, 6), $resposta->dados['aviso']);
        self::assertSame([(int) $muitas->getId(), (int) $poucas->getId()], array_column($resposta->dados['pastas'], 'pasta_id'));
        self::assertSame([
            'pasta_id' => (int) $muitas->getId(),
            'numero' => $muitas->getNup(),
            'conteudo' => ['grupos' => 2, 'copias_a_mais' => 3],
            'provavel' => ['grupos' => 1, 'copias_a_mais' => 1],
        ], $resposta->dados['pastas'][0]);
        self::assertNull($resposta->proximaPagina);
        self::assertStringNotContainsString(self::HASH_A, json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('pasta: os grupos com os documentos de cada um, do mais antigo ao mais novo, com o critério')]
    public function testPasta(): void
    {
        $pasta = $this->pasta();
        $primeiro = $this->documento($pasta, 'rg.pdf', 10, self::HASH_A, '2026-05-30');
        $segundo = $this->documento($pasta, 'rg.pdf', 10, self::HASH_A, '2026-07-11');
        $provavelA = $this->documento($pasta, 'procuracao.pdf', 30, null, '2026-05-30');
        $provavelB = $this->documento($pasta, 'procuracao.pdf', 30, null, '2026-07-13');
        $this->documento($pasta, 'unico.pdf', 50, self::HASH_B);

        $resposta = $this->executar(['pasta_id' => $pasta->getId()]);

        self::assertSame('pasta', $resposta->dados['recorte']);
        self::assertSame(['conteudo' => ['grupos' => 1, 'copias_a_mais' => 1], 'provavel' => ['grupos' => 1, 'copias_a_mais' => 1], 'documentos_sem_hash' => 2], $resposta->dados['totais']);
        self::assertCount(2, $resposta->dados['grupos']);
        [$porHash, $porNome] = $resposta->dados['grupos'];
        self::assertSame('conteudo', $porHash['criterio']);
        self::assertSame([(int) $primeiro->getId(), (int) $segundo->getId()], array_column($porHash['documentos'], 'id'));
        self::assertSame(['id', 'nome', 'categoria', 'subpasta_id', 'enviado_em', 'tamanho_bytes'], array_keys($porHash['documentos'][0]));
        self::assertSame(['2026-05-30', '2026-07-11'], array_column($porHash['documentos'], 'enviado_em'));
        self::assertSame('provavel', $porNome['criterio']);
        self::assertSame([(int) $provavelA->getId(), (int) $provavelB->getId()], array_column($porNome['documentos'], 'id'));
        self::assertSame([(int) $pasta->getId()], $resposta->ids['pasta']);
    }

    #[TestDox('acesso: segredo de justiça e cadeado saem do escritório e são contados; a pasta deles responde como as outras ações')]
    public function testAcesso(): void
    {
        $emSegredo = $this->pastaEmSegredo();
        $this->documento($emSegredo, 's.pdf', 10, self::HASH_A);
        $this->documento($emSegredo, 's.pdf', 10, self::HASH_A);
        $trancada = $this->pasta();
        $this->documento($trancada, 't.pdf', 10, self::HASH_A);
        $this->documento($trancada, 't.pdf', 10, self::HASH_A);
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $semDocumentos = $this->pasta();
        $this->documento($semDocumentos, 'd.pdf', 10, self::HASH_A);
        $this->documento($semDocumentos, 'd.pdf', 10, self::HASH_A);
        $this->ocultar($semDocumentos, AbaDaPasta::Documentos);

        $resposta = $this->executar([]);

        self::assertSame([], $resposta->dados['pastas']);
        self::assertSame(1, $resposta->dados['totais']['pastas_omitidas_em_segredo']);
        self::assertSame(2, $resposta->dados['totais']['pastas_omitidas_sem_acesso']);
        self::assertSame(0, $resposta->dados['totais']['conteudo']['grupos']);
        $this->assertMotivo(['pasta_id' => $emSegredo->getId()], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        $this->assertMotivo(['pasta_id' => $trancada->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['pasta_id' => $semDocumentos->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('com a aba Financeiro oculta, o contrato de honorários não entra no grupo (e o grupo que sobra com um só some)')]
    public function testContratoComFinanceiroOculto(): void
    {
        $pasta = $this->pasta();
        $this->documento($pasta, 'contrato.pdf', 10, self::HASH_A, categoria: PastaDocumento::CATEGORIA_CONTRATO);
        $this->documento($pasta, 'contrato.pdf', 10, self::HASH_A);
        $this->ocultar($pasta, AbaDaPasta::Financeiro);

        self::assertSame([], $this->executar([])->dados['pastas']);
        self::assertSame([], $this->executar(['pasta_id' => $pasta->getId()])->dados['grupos']);
    }

    #[TestDox('pasta_id só é opcional em duplicados; inválido é recusado; campo de outra ação é recusado')]
    public function testEntrada(): void
    {
        $this->assertMotivo(['pasta_id' => 0], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['documento_id' => 1], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['pagina' => 0], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        try {
            $this->chamar(['acao' => 'listar']);
            self::fail('listar sem pasta_id deveria ser recusado');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $e->motivo);
        }
    }

    /** @param array<string, mixed> $extras */
    private function executar(array $extras): RespostaDaFerramenta
    {
        return $this->chamar(['acao' => LerDocumentosDuplicados::ACAO] + $extras);
    }

    /** @param array<string, mixed> $argumentos */
    private function chamar(array $argumentos): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));

        return static::getContainer()->get(LerDocumentosEModelosFerramenta::class)->executar($argumentos, $chamada);
    }

    /** @param array<string, mixed> $extras */
    private function assertMotivo(array $extras, string $motivo): void
    {
        try {
            $this->executar($extras);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('DUP-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function pastaEmSegredo(): Pasta
    {
        $pasta = $this->pasta();
        $processo = new Processo();
        $processo->setNumeroProcesso('07011345720258070001' . uniqid());
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->escritorio);
        $processo->setNivelSigilo(1);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();

        return $pasta;
    }

    private function documento(Pasta $pasta, string $nome, int $tamanho, ?string $sha256, ?string $enviadoEm = null, ?Tenant $tenant = null, string $categoria = PastaDocumento::CATEGORIA_DEMAIS): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTenant($tenant ?? $this->escritorio);
        $doc->setPasta($pasta);
        $doc->setTitulo($nome);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo(uniqid('dup', true) . '.bin');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes($tamanho);
        $doc->setSha256($sha256);
        $this->em()->persist($doc);
        $this->em()->flush();
        if ($enviadoEm !== null) {
            $this->em()->getConnection()->executeStatement('UPDATE pasta_documento SET uploaded_at = :d WHERE id = :id', ['d' => $enviadoEm . ' 10:00:00', 'id' => $doc->getId()]);
            $this->em()->refresh($doc);
        }

        return $doc;
    }

    private function ocultar(Pasta $pasta, AbaDaPasta $aba): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, $aba, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();
    }
}
