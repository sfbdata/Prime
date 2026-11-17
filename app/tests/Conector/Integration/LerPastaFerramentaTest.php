<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\LerPastaFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ler_pasta` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.4 e decisões 4 a 9 do PR 6): cada parte com a
 * sua forma, a aba oculta barrando a parte, a pasta da lixeira, de outro escritório ou trancada como "não encontrado", o
 * segredo de justiça como `em_segredo`, o Registro paginado e a máscara sempre ligada no conector.
 */
#[CoversClass(LerPastaFerramenta::class)]
final class LerPastaFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const CPF = '529.982.247-25';
    private const LEITOR = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;
    private User $colega;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::LEITOR);
        $this->colega = $this->pessoa();
        $this->colega->setFullName('Colega da Equipe');
        $this->vinculo($this->colega, $this->escritorio, permissoes: self::LEITOR);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('sem partes, sai o resumo: número, ação, responsável, clientes e processos em blocos delimitados')]
    public function testResumoPorPadrao(): void
    {
        $pasta = $this->pasta();
        $pasta->setNomeAcao('Revisão contratual')->setResponsavel($this->pessoa);
        $this->em()->flush();

        $resposta = $this->ler(['pasta_id' => $pasta->getId()]);

        self::assertSame(['pasta_id', 'resumo'], array_keys($resposta->dados));
        $resumo = $resposta->dados['resumo'];
        self::assertSame(['numero', 'acao', 'situacao', 'prioridade', 'responsavel', 'aberta_em', 'carteira', 'marcadores', 'clientes', 'processos'], array_keys($resumo));
        self::assertStringContainsString((string) $pasta->getNup(), (string) $resumo['numero']);
        self::assertStringStartsWith('<clientes>', $resumo['clientes']['texto']);
        self::assertStringStartsWith('<processos_vinculados>', $resumo['processos']['texto']);
        self::assertSame(0, $resumo['processos']['total']);
        self::assertSame(['pasta' => [$pasta->getId()]], $resposta->ids);
        self::assertSame(['partes' => 1], $resposta->contagens);
    }

    #[TestDox('todas as partes do PR 6 de uma vez: cada uma com a sua forma')]
    public function testTodasAsPartes(): void
    {
        $pasta = $this->pasta();
        $todas = ['resumo', 'andamento', 'registro', 'equipe', 'cadastro', 'prazos', 'metas', 'detalhes', 'checklist', 'financeiro'];

        $dados = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => $todas])->dados;

        self::assertSame(['pasta_id', ...$todas], array_keys($dados));
        foreach (['metas' => '<metas>', 'detalhes' => '<observacoes>', 'checklist' => '<checklist>', 'financeiro' => '<financeiro>'] as $parte => $tag) {
            self::assertSame(['texto', 'omitidas'], array_keys($dados[$parte]), $parte);
            self::assertStringStartsWith($tag, $dados[$parte]['texto'], $parte);
        }
        self::assertSame(['via', 'atual', 'finalizada', 'estimada', 'base_da_estimativa', 'duracao', 'fases', 'sugestoes'], array_keys($dados['andamento']));
        self::assertSame(['mensagens' => [], 'pagina' => 1, 'por_pagina' => 50], $dados['registro']);
        self::assertSame(['campos', 'reu_completo', 'reus'], array_keys($dados['cadastro']));
        self::assertSame(['prazos' => [], 'omitidos' => 0], $dados['prazos']);
        self::assertLessThan(50_000, \strlen(json_encode($dados, \JSON_THROW_ON_ERROR)));
    }

    #[TestDox('registro: 55 mensagens dão a página 1 com as 50 mais recentes e proxima_pagina 2; a página 2 traz as 5 restantes')]
    public function testRegistroPaginado(): void
    {
        $pasta = $this->pasta();
        $ids = [];
        for ($i = 1; $i <= 55; ++$i) {
            $ids[] = $this->mensagem($pasta, '<p>Mensagem ' . $i . '</p>')->getId();
        }
        $this->em()->flush();

        $primeira = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['registro']]);
        $mensagens = $primeira->dados['registro']['mensagens'];
        self::assertCount(50, $mensagens);
        self::assertSame(max($ids), $mensagens[0]['id'], 'a mais recente primeiro');
        self::assertSame('Mensagem 55', $mensagens[0]['texto']);
        self::assertSame(['id', 'em', 'autor', 'do_sistema', 'resposta_a', 'editada', 'texto', 'cortado'], array_keys($mensagens[0]));
        self::assertSame(2, $primeira->proximaPagina);

        static::getContainer()->get('services_resetter')->reset();
        $segunda = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['registro'], 'pagina' => 2]);
        self::assertCount(5, $segunda->dados['registro']['mensagens']);
        self::assertNull($segunda->proximaPagina);
        self::assertSame(min($ids), $segunda->dados['registro']['mensagens'][4]['id']);
    }

    #[TestDox('registro: com o Financeiro oculto, o cartão de contrato do sistema não sai; mensagem longa é cortada')]
    public function testRegistroSemFinanceiro(): void
    {
        $pasta = $this->pasta();
        $comum = $this->mensagem($pasta, str_repeat('a', 600));
        $contrato = $this->mensagem($pasta, 'Contrato assinado');
        $contrato->setSistema('contrato');
        $this->ocultar($pasta, AbaDaPasta::Financeiro);

        $mensagens = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['registro']])->dados['registro']['mensagens'];

        self::assertSame([$comum->getId()], array_column($mensagens, 'id'));
        self::assertTrue($mensagens[0]['cortado']);
        self::assertSame(501, mb_strlen($mensagens[0]['texto']));
    }

    /** @return iterable<string, array{AbaDaPasta, string}> */
    public static function abaQueBarra(): iterable
    {
        yield 'registro pela aba Dados' => [AbaDaPasta::Dados, 'registro'];
        yield 'financeiro pela aba Financeiro' => [AbaDaPasta::Financeiro, 'financeiro'];
        yield 'detalhes pela aba Detalhes' => [AbaDaPasta::Detalhes, 'detalhes'];
        yield 'checklist pela aba Documentos' => [AbaDaPasta::Documentos, 'checklist'];
        yield 'metas pela aba Tarefas' => [AbaDaPasta::Tarefas, 'metas'];
    }

    #[DataProvider('abaQueBarra')]
    #[TestDox('aba oculta para a pessoa barra a parte como não encontrado: $_dataName')]
    public function testAbaOcultaBarra(AbaDaPasta $aba, string $parte): void
    {
        $pasta = $this->pasta();
        $this->ocultar($pasta, $aba);

        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => [$parte]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        static::getContainer()->get('services_resetter')->reset();
        self::assertArrayHasKey('resumo', $this->ler(['pasta_id' => $pasta->getId()])->dados, 'o resumo continua');
    }

    #[TestDox('não encontrado: a pasta da lixeira, a de outro escritório, a trancada e a que não existe')]
    public function testNaoEncontrado(): void
    {
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $deOutro = $this->pasta($this->escritorio('Outro'));
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();

        foreach ([$naLixeira->getId(), $deOutro->getId(), $trancada->getId(), 999_999_999] as $id) {
            $this->assertMotivo(['pasta_id' => $id], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            static::getContainer()->get('services_resetter')->reset();
        }
    }

    #[TestDox('pasta com processo em segredo de justiça: em_segredo, em qualquer parte')]
    public function testEmSegredo(): void
    {
        $pasta = $this->pasta(comSegredo: true);

        $this->assertMotivo(['pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        static::getContainer()->get('services_resetter')->reset();
        $this->assertMotivo(['pasta_id' => $pasta->getId(), 'partes' => ['registro']], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
    }

    #[TestDox('prazos: só os em aberto, com tipo, título e data')]
    public function testPrazosSoEmAberto(): void
    {
        $pasta = $this->pasta();
        $aberto = $this->prazo($pasta, 'Contestação', '+5 days');
        $cumprido = $this->prazo($pasta, 'Réplica', '+2 days');
        $cumprido->cumprir($this->pessoa);
        $this->em()->flush();

        $prazos = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['prazos']])->dados['prazos'];

        self::assertSame([$aberto->getId()], array_column($prazos['prazos'], 'id'));
        self::assertSame('prazo', $prazos['prazos'][0]['tipo']);
        self::assertSame('Contestação', $prazos['prazos'][0]['titulo']);
        self::assertSame((new \DateTimeImmutable('+5 days'))->format('Y-m-d'), $prazos['prazos'][0]['data']);
    }

    #[TestDox('cadastro: só preenchido ou falta, sem nenhum valor; equipe: só id e nome')]
    public function testCadastroEEquipe(): void
    {
        $pasta = $this->pasta();

        $dados = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['cadastro', 'equipe']])->dados;

        self::assertSame(['falta'], array_values(array_unique($dados['cadastro']['campos'])));
        self::assertFalse($dados['cadastro']['reu_completo']);
        self::assertSame(0, $dados['cadastro']['reus']);
        self::assertNotSame([], $dados['equipe']['pessoas']);
        foreach ($dados['equipe']['pessoas'] as $pessoa) {
            self::assertSame(['id', 'nome'], array_keys($pessoa));
        }
        self::assertContains($this->colega->getId(), array_column($dados['equipe']['pessoas'], 'id'));
    }

    #[TestDox('máscara: com o escritório tendo desligado a máscara, o CPF sai mascarado no conector')]
    public function testMascaraSempreLigada(): void
    {
        $configuracao = new ConfiguracaoDeInteligencia($this->escritorio);
        $configuracao->atualizar(true, 10, 100, false, $this->pessoa);
        $this->em()->persist($configuracao);
        $pasta = $this->pasta();
        $pasta->setNomeAcao('Revisão do CPF ' . self::CPF);
        $this->mensagem($pasta, 'O CPF do cliente é ' . self::CPF);
        $this->em()->flush();

        $dados = $this->ler(['pasta_id' => $pasta->getId(), 'partes' => ['resumo', 'registro']])->dados;

        $json = json_encode($dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString(self::CPF, $json);
        self::assertStringContainsString('[CPF]', $dados['registro']['mensagens'][0]['texto']);
    }

    /** @param array<string, mixed> $argumentos */
    private function ler(array $argumentos): RespostaDaFerramenta
    {
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));

        return static::getContainer()->get(LerPastaFerramenta::class)->executar($argumentos, $chamada);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo): void
    {
        try {
            $this->ler($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function pasta(?Tenant $escritorio = null, bool $comSegredo = false): Pasta
    {
        $escritorio ??= $this->escritorio;
        $pasta = (new Pasta())->setNup('LER-' . uniqid());
        $pasta->setTenant($escritorio);
        $this->em()->persist($pasta);
        if ($comSegredo) {
            $processo = new Processo();
            $processo->setNumeroProcesso('07011345720258070001' . uniqid());
            $processo->setSiglaTribunal('TJDFT');
            $processo->setClasseProcessual('Procedimento Comum');
            $processo->setAssuntoProcessual('Assunto');
            $processo->setTenant($escritorio);
            $processo->setNivelSigilo(1);
            $this->em()->persist($processo);
            $vinculo = new PastaProcesso($pasta, $processo);
            $this->em()->persist($vinculo);
            $pasta->getPastaProcessos()->add($vinculo);
        }
        $this->em()->flush();

        return $pasta;
    }

    private function mensagem(Pasta $pasta, string $conteudo): PastaMensagem
    {
        $mensagem = (new PastaMensagem())->setPasta($pasta)->setTenant($this->escritorio)->setAutor($this->colega)->setConteudo($conteudo);
        $this->em()->persist($mensagem);
        $this->em()->flush();

        return $mensagem;
    }

    private function prazo(Pasta $pasta, string $titulo, string $quando): PastaPrazo
    {
        $prazo = new PastaPrazo($this->escritorio, $pasta, $this->colega);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, $titulo, new \DateTimeImmutable($quando), null, null);
        $this->em()->persist($prazo);
        $this->em()->flush();

        return $prazo;
    }

    private function ocultar(Pasta $pasta, AbaDaPasta $aba): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, $aba, $this->colega, new \DateTimeImmutable()));
        $this->em()->flush();
    }
}
