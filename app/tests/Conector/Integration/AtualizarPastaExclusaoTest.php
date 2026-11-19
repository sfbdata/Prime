<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\AtualizarPastaExclusao;
use App\Conector\Ferramenta\AtualizarPastaFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Repository\PastaReuRepository;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `atualizar_pasta` com `acao` excluir (spec mcp-remoto-oauth 6.6 e decisão 31): os três alvos (pasta, marcador, réu)
 * sempre por proposta, que nada grava até a `confirmar_acao`; a reconferência que recusa quando o alvo mudou; e a
 * paridade com a tela, alvo por alvo: pasta pede o recurso `pasta` de `delete`, marcador pede o módulo `expediente`, réu
 * pede o recurso `pasta` de editar.
 */
#[CoversClass(AtualizarPastaExclusao::class)]
#[CoversClass(AtualizarPastaFerramenta::class)]
final class AtualizarPastaExclusaoTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const TUDO = ['modules.pastas.view', 'modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit', 'resources.pasta.delete'];

    private Tenant $escritorio;
    private User $pessoa;
    private ConsentimentoDoConector $consentimento;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::TUDO);
        $doConector = new EscritorioDoConector($this->escritorio);
        $doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($doConector);
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

    // ------------------------------------------------------------------ pasta

    #[TestDox('excluir pasta: proposta com o número no resumo, nada muda ao propor; confirmar manda para a lixeira')]
    public function testExcluirPasta(): void
    {
        $pasta = $this->pasta();

        $proposta = $this->executar(['acao' => 'excluir', 'alvo' => 'pasta', 'pasta_id' => $pasta->getId()]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString((string) $pasta->getNup(), $proposta->dados['proposta']['resumo']);
        self::assertStringContainsString('lixeira', $proposta->dados['proposta']['resumo']);
        self::assertFalse($this->pastaAtual($pasta)->estaExcluida(), 'propor não grava');

        $confirmada = $this->confirmar($proposta);

        self::assertSame('excluida', $confirmada->dados['resultado']['estado']);
        self::assertSame(AtualizarPastaExclusao::AVISO_LIXEIRA, $confirmada->dados['resultado']['aviso']);
        self::assertTrue($this->pastaAtual($pasta)->estaExcluida());
    }

    #[TestDox('excluir pasta: o número mudou depois da proposta, a confirmação recusa e a pasta fica')]
    public function testExcluirPastaQueMudou(): void
    {
        $pasta = $this->pasta();
        $proposta = $this->executar(['acao' => 'excluir', 'alvo' => 'pasta', 'pasta_id' => $pasta->getId()]);
        $this->pastaAtual($pasta)->setNup('OUTRO-' . uniqid());
        $this->em()->flush();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(AtualizarPastaFerramenta::MUDOU, $erro->getMessage());
        self::assertFalse($this->pastaAtual($pasta)->estaExcluida());
    }

    #[TestDox('paridade, alvo pasta: quem vê e edita mas não tem o recurso pasta de delete recebe "sem permissão", sem proposta')]
    public function testExcluirPastaSemDelete(): void
    {
        $pasta = $this->pasta();
        $this->trocarPessoa(['modules.pastas.view', 'modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit']);

        $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'pasta', 'pasta_id' => $pasta->getId()], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('excluir pasta: a permissão de excluir saiu depois da proposta, a confirmação recusa')]
    public function testExcluirPastaPermissaoSaiu(): void
    {
        $pasta = $this->pasta();
        $proposta = $this->executar(['acao' => 'excluir', 'alvo' => 'pasta', 'pasta_id' => $pasta->getId()]);
        $this->em()->getConnection()->executeStatement(
            'DELETE FROM tenant_role_permission WHERE permission_id IN (SELECT id FROM permission WHERE code = ?) AND tenant_role_id IN (SELECT tenant_role_id FROM user_tenant WHERE user_id = ?)',
            ['resources.pasta.delete', $this->pessoa->getId()],
        );

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $erro->motivo);
        self::assertFalse($this->pastaAtual($pasta)->estaExcluida());
    }

    // ------------------------------------------------------------------ marcador

    #[TestDox('excluir marcador: proposta que cita as pastas e os submarcadores; confirmar exclui o marcador do escritório')]
    public function testExcluirMarcador(): void
    {
        $marcador = $this->marcador('Urgente cliente');
        $this->marcador('Sub de urgente', $marcador);
        $pasta = $this->gerenciado($this->pasta());
        $pasta->addMarcador($this->gerenciado($marcador));
        $this->em()->flush();
        $this->em()->clear();

        $proposta = $this->executar(['acao' => 'excluir', 'alvo' => 'marcador', 'marcador_id' => $marcador->getId()]);

        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('"Urgente cliente"', $resumo);
        self::assertStringContainsString('(1 delas você vê)', $resumo);
        self::assertStringContainsString('"Sub de urgente"', $resumo);
        self::assertNotNull($this->em()->find(Marcador::class, $marcador->getId()), 'propor não grava');

        $confirmada = $this->confirmar($proposta);

        self::assertSame('excluido', $confirmada->dados['resultado']['estado']);
        $this->em()->clear();
        self::assertNull($this->em()->find(Marcador::class, $marcador->getId()));
    }

    #[TestDox('excluir marcador: renomeado depois da proposta, a confirmação recusa e ele fica')]
    public function testExcluirMarcadorQueMudou(): void
    {
        $marcador = $this->marcador('Antigo');
        $proposta = $this->executar(['acao' => 'excluir', 'alvo' => 'marcador', 'marcador_id' => $marcador->getId()]);
        $this->gerenciado($marcador)->setNome('Renomeado');
        $this->em()->flush();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(AtualizarPastaFerramenta::MUDOU, $erro->getMessage());
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Marcador::class, $marcador->getId()));
    }

    #[TestDox('paridade, alvo marcador: sem o módulo Expediente, "sem permissão", sem proposta')]
    public function testExcluirMarcadorSemExpediente(): void
    {
        $marcador = $this->marcador('Do escritório');
        $this->trocarPessoa(['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit', 'resources.pasta.delete']);

        $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'marcador', 'marcador_id' => $marcador->getId()], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('excluir marcador de outro escritório ou inexistente: não encontrado')]
    public function testExcluirMarcadorDeOutro(): void
    {
        $outro = $this->escritorio('Outro');
        $alheio = new Marcador('Alheio', $outro, $this->pessoa());
        $this->em()->persist($alheio);
        $this->em()->flush();

        foreach ([$alheio->getId(), 999_999_999] as $id) {
            $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'marcador', 'marcador_id' => (int) $id], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
    }

    // ------------------------------------------------------------------ réu

    #[TestDox('excluir réu: proposta que avisa quando é o principal; confirmar remove e o próximo vira o principal')]
    public function testExcluirReu(): void
    {
        $pasta = $this->pasta();
        $principal = $this->reu($pasta, 'BANCO PRINCIPAL', 0);
        $segundo = $this->reu($pasta, 'BANCO SEGUNDO', 1);

        $proposta = $this->executar(['acao' => 'excluir', 'alvo' => 'reu', 'pasta_id' => $pasta->getId(), 'reu_id' => $principal->getId()]);

        self::assertStringContainsString('"BANCO PRINCIPAL"', $proposta->dados['proposta']['resumo']);
        self::assertStringContainsString('réu principal', $proposta->dados['proposta']['resumo']);
        self::assertCount(2, $this->reus($pasta), 'propor não grava');

        $confirmada = $this->confirmar($proposta);

        self::assertSame('removido', $confirmada->dados['resultado']['estado']);
        self::assertSame(1, $confirmada->dados['resultado']['reus_que_ficam']);
        self::assertSame([$segundo->getId()], array_map(static fn (PastaReu $r): ?int => $r->getId(), $this->reus($pasta)));
    }

    #[TestDox('paridade, alvo réu: só vê a pasta, sem o recurso de editar: não encontrado, sem proposta')]
    public function testExcluirReuSemEditar(): void
    {
        $pasta = $this->pasta();
        $reu = $this->reu($pasta, 'BANCO X', 0);
        $this->trocarPessoa(['modules.pastas.view', 'modules.expediente.view', 'resources.pasta.view', 'resources.pasta.delete']);

        $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'reu', 'pasta_id' => $pasta->getId(), 'reu_id' => $reu->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('excluir réu de outra pasta: não encontrado; pasta na lixeira depois da proposta: a confirmação recusa')]
    public function testExcluirReuDeOutraPasta(): void
    {
        $pasta = $this->pasta();
        $reuDeOutra = $this->reu($this->pasta(), 'BANCO DE OUTRA', 0);
        $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'reu', 'pasta_id' => $pasta->getId(), 'reu_id' => $reuDeOutra->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);

        $reu = $this->reu($pasta, 'BANCO DAQUI', 0);
        $proposta = $this->executar(['acao' => 'excluir', 'alvo' => 'reu', 'pasta_id' => $pasta->getId(), 'reu_id' => $reu->getId()]);
        $this->gerenciado($pasta)->marcarExcluida($this->gerenciado($this->pessoa), new \DateTimeImmutable());
        $this->em()->flush();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
        self::assertCount(1, $this->reus($pasta));
    }

    #[TestDox('alvo sem o campo dele, ou com campo de outro alvo: pedido inválido')]
    public function testCamposDoAlvo(): void
    {
        $id = (int) $this->pasta()->getId();

        $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'pasta'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'marcador', 'pasta_id' => $id, 'marcador_id' => 1], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'reu', 'pasta_id' => $id], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'excluir', 'alvo' => 'processo', 'pasta_id' => $id], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    // ------------------------------------------------------------------ apoio

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();

        return static::getContainer()->get(AtualizarPastaFerramenta::class)->executar($argumentos, $this->chamada());
    }

    /** Confirma pela `confirmar_acao` de verdade, com o resumo exato da proposta. */
    private function confirmar(RespostaDaFerramenta $proposta): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();
        $argumentos = ['proposta_id' => $proposta->dados['proposta']['proposta_id'], 'resumo' => $proposta->dados['proposta']['resumo']];

        return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar($argumentos, $this->chamada());
    }

    private function recusaDaConfirmacao(RespostaDaFerramenta $proposta): ErroDaFerramenta
    {
        try {
            $this->confirmar($proposta);
        } catch (ErroDaFerramenta $e) {
            return $e;
        }
        self::fail('a confirmação deveria recusar');
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo): void
    {
        try {
            $this->executar($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function chamada(): ChamadaDoConector
    {
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, (int) $this->consentimento->getId(), 'claude.ai', ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('+7 days'));
    }

    /** @param list<string> $permissoes */
    private function trocarPessoa(array $permissoes): void
    {
        $outra = $this->pessoa();
        $this->vinculo($outra, $this->gerenciado($this->escritorio), permissoes: $permissoes);
        $consentimento = new ConsentimentoDoConector($outra, $this->gerenciado($this->escritorio), $this->gerenciado($this->consentimento)->getCliente(), ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($consentimento);
        $this->em()->flush();
        $this->pessoa = $outra;
        $this->consentimento = $consentimento;
    }

    private function contarPropostas(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta');
    }

    private function pasta(): Pasta
    {
        $pasta = (new Pasta())->setNup('EXC-' . uniqid());
        $pasta->setTenant($this->gerenciado($this->escritorio));
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function pastaAtual(Pasta $pasta): Pasta
    {
        $this->em()->clear();

        return $this->em()->find(Pasta::class, $pasta->getId()) ?? throw new \LogicException('pasta sumiu');
    }

    private function marcador(string $nome, ?Marcador $pai = null): Marcador
    {
        $pai = $pai === null ? null : $this->gerenciado($pai);
        $marcador = new Marcador($nome, $this->gerenciado($this->escritorio), $this->gerenciado($this->pessoa), $pai);
        $pai?->getFilhos()->add($marcador);
        $this->em()->persist($marcador);
        $this->em()->flush();

        return $marcador;
    }

    private function reu(Pasta $pasta, string $nome, int $ordem): PastaReu
    {
        $reu = new PastaReu($this->gerenciado($this->escritorio), $this->gerenciado($pasta), $nome, null, $ordem);
        $this->em()->persist($reu);
        $this->em()->flush();

        return $reu;
    }

    /** @return list<PastaReu> */
    private function reus(Pasta $pasta): array
    {
        $this->em()->clear();

        return static::getContainer()->get(PastaReuRepository::class)->daPasta($this->gerenciado($pasta), $this->gerenciado($this->escritorio));
    }

    /**
     * A entidade presa ao EntityManager de agora (o `pastaAtual()` limpa, e o `services_resetter` pode trocá-lo).
     *
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function gerenciado(object $entidade): object
    {
        if ($this->em()->contains($entidade)) {
            return $entidade;
        }

        return $this->em()->find($entidade::class, $entidade->getId()) ?? throw new \LogicException('entidade sumiu');
    }
}
