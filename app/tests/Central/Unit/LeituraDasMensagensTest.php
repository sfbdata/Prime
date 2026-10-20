<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\DTO\ConteudoLido;
use App\Central\DTO\DespachoOutput;
use App\Central\DTO\MensagemOutput;
use App\Central\DTO\RespostaOutput;
use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Service\LeituraDasMensagens;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Service\PermissionChecker;
use App\Tests\Central\Support\MontaLeituraDasMensagensTrait;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O ponto único de leitura do despacho (regra 13 da Central): a pasta é conferida na hora da leitura, para cada leitor.
 * Quem não vê (inclusive porque a pasta foi apagada, excluída ou é de outro escritório) recebe "Item de pasta sem
 * acesso" e nenhuma referência; a citação de um despacho segue a mesma regra.
 */
#[CoversClass(LeituraDasMensagens::class)]
#[CoversClass(ConteudoLido::class)]
#[CoversClass(DespachoOutput::class)]
#[CoversClass(MensagemOutput::class)]
#[CoversClass(RespostaOutput::class)]
#[CoversClass(Mensagem::class)]
final class LeituraDasMensagensTest extends TestCase
{
    use MontaLeituraDasMensagensTrait;

    private const TEXTO = "Despacho: Registro de Ana · 20/10/2026 10:00\nSEGREDO do teor\nPasta NUP-77";

    private Tenant $tenant;
    private Pasta $pasta;
    private User $ana;
    private User $bruno;
    private Conversa $grupo;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 7);
        $this->pasta = (new Pasta())->setTenant($this->tenant)->setNup('NUP-77');
        DefineId::em($this->pasta, 77);
        $this->ana = $this->pessoa(1, 'Ana');
        $this->bruno = $this->pessoa(2, 'Bruno');
        $this->grupo = Conversa::grupo($this->tenant, $this->ana, 'Equipe', new \DateTimeImmutable());
        DefineId::em($this->grupo, 9);
    }

    #[TestDox('Mensagem comum: o texto gravado, sem despacho e sem ocultar (a pasta nem é consultada)')]
    public function testMensagemComum(): void
    {
        $repositorio = $this->createMock(PastaRepository::class);
        $repositorio->expects(self::never())->method('findOneBy');
        $leitura = new LeituraDasMensagens($repositorio, new AcessoAPasta((new \ReflectionClass(\App\Pasta\Service\NivelNoCadeado::class))->newInstanceWithoutConstructor(), $this->createStub(PermissionChecker::class)), $this->createStub(VisibilidadeDoFinanceiroDaPasta::class));

        $saida = $leitura->umaParaLeitor($this->mensagem('oi, tudo bem?'), $this->bruno);

        self::assertSame('oi, tudo bem?', $saida->conteudo);
        self::assertNull($saida->despacho);
        self::assertFalse($saida->ocultoPorPermissao);
    }

    #[TestDox('Despacho que o leitor vê: o texto e a referência {pastaId, nup}')]
    public function testDespachoQueOLeitorVe(): void
    {
        $saida = $this->leituraDasMensagens([$this->pasta], [1, 2])->umaParaLeitor($this->despacho(), $this->bruno);

        self::assertSame(self::TEXTO, $saida->conteudo);
        self::assertEquals(new DespachoOutput(77, 'NUP-77'), $saida->despacho);
        self::assertFalse($saida->ocultoPorPermissao);
    }

    #[TestDox('Despacho que o leitor não vê: "Item de pasta sem acesso", sem referência, e quem vê continua vendo')]
    public function testDespachoQueOLeitorNaoVe(): void
    {
        $leitura = $this->leituraDasMensagens([$this->pasta], [1]);
        $despacho = $this->despacho();

        $doBruno = $leitura->umaParaLeitor($despacho, $this->bruno);
        self::assertSame(LeituraDasMensagens::SEM_ACESSO, $doBruno->conteudo);
        self::assertSame('Item de pasta sem acesso', $doBruno->conteudo);
        self::assertNull($doBruno->despacho);
        self::assertTrue($doBruno->ocultoPorPermissao);
        self::assertStringNotContainsString('NUP-77', (string) json_encode($doBruno));
        self::assertStringNotContainsString('SEGREDO', (string) json_encode($doBruno));

        $daAna = $leitura->umaParaLeitor($despacho, $this->ana);
        self::assertSame(self::TEXTO, $daAna->conteudo);
        self::assertFalse($daAna->ocultoPorPermissao);
    }

    #[TestDox('Pasta apagada (referência nula), excluída (lápide) ou de outro escritório: ninguém vê, nem quem tem acesso a tudo')]
    public function testPastaQueNaoExisteMais(): void
    {
        $apagada = $this->despacho();
        (new \ReflectionProperty(Mensagem::class, 'despachoPasta'))->setValue($apagada, null);   // o ON DELETE SET NULL
        self::assertTrue($apagada->isDespacho(), 'o tipo fica: segue sendo despacho');

        $todosVeem = $this->leituraDasMensagens([$this->pasta], [1, 2]);
        self::assertTrue($todosVeem->umaParaLeitor($apagada, $this->ana)->ocultoPorPermissao);

        $excluida = $this->despacho();
        $this->pasta->marcarExcluida($this->ana, new \DateTimeImmutable());
        self::assertTrue($todosVeem->umaParaLeitor($excluida, $this->ana)->ocultoPorPermissao);

        // A busca leva o escritório da mensagem: a pasta com o mesmo id em outro escritório não é encontrada.
        $outroEscritorio = new Tenant();
        DefineId::em($outroEscritorio, 8);
        $pastaDeFora = (new Pasta())->setTenant($outroEscritorio)->setNup('DE-FORA');
        DefineId::em($pastaDeFora, 77);
        $saida = $this->leituraDasMensagens([$pastaDeFora], [1, 2])->umaParaLeitor($this->despacho(new Pasta()), $this->ana);
        self::assertTrue($saida->ocultoPorPermissao);
        self::assertNull($saida->despacho);
    }

    #[TestDox('Registro do Financeiro: além da pasta, o leitor precisa ver o financeiro dela')]
    public function testRegistroDoFinanceiro(): void
    {
        $leitura = $this->leituraDasMensagens([$this->pasta], [1, 2], quemVeOFinanceiro: [1]);
        $financeiro = $this->despacho(tipo: TipoDeItemEncaminhado::RegistroFinanceiro);

        self::assertTrue($leitura->umaParaLeitor($financeiro, $this->bruno)->ocultoPorPermissao, 'vê a pasta, não vê o financeiro');
        self::assertFalse($leitura->umaParaLeitor($financeiro, $this->ana)->ocultoPorPermissao);
        self::assertFalse($leitura->umaParaLeitor($this->despacho(tipo: TipoDeItemEncaminhado::RegistroDados), $this->bruno)->ocultoPorPermissao, 'os outros tipos não pedem o financeiro');
    }

    #[TestDox('Resposta que cita um despacho: o trecho citado também sai como "Item de pasta sem acesso"; a resposta segue legível')]
    public function testCitacaoDeDespacho(): void
    {
        $despacho = $this->despacho();
        $resposta = new Mensagem($this->tenant, $this->grupo, $this->ana, 'pode deixar', new \DateTimeImmutable(), $despacho);
        DefineId::em($resposta, 2);

        $doBruno = $this->leituraDasMensagens([$this->pasta], [1])->umaParaLeitor($resposta, $this->bruno);
        self::assertSame('pode deixar', $doBruno->conteudo);
        self::assertFalse($doBruno->ocultoPorPermissao);
        self::assertNotNull($doBruno->respostaA);
        self::assertSame(LeituraDasMensagens::SEM_ACESSO, $doBruno->respostaA->trecho);
        self::assertTrue($doBruno->respostaA->ocultoPorPermissao);

        $daAna = $this->leituraDasMensagens([$this->pasta], [1])->umaParaLeitor($resposta, $this->ana);
        self::assertStringStartsWith('Despacho: Registro de Ana', (string) $daAna->respostaA?->trecho);
        self::assertFalse((bool) $daAna->respostaA?->ocultoPorPermissao);
    }

    #[TestDox('Memória por leitura: vinte despachos da mesma pasta, uma busca e uma pergunta de permissão; outra leitura pergunta de novo')]
    public function testMemoriaPorLeitura(): void
    {
        $repositorio = $this->createMock(PastaRepository::class);
        $repositorio->expects(self::exactly(2))->method('findOneBy')->willReturn($this->pasta);
        $checker = $this->createMock(PermissionChecker::class);
        $checker->expects(self::exactly(2))->method('canAccessResource')->willReturn(true);
        $leitura = new LeituraDasMensagens($repositorio, new AcessoAPasta((new \ReflectionClass(\App\Pasta\Service\NivelNoCadeado::class))->newInstanceWithoutConstructor(), $checker), $this->createStub(VisibilidadeDoFinanceiroDaPasta::class));
        $mensagens = array_map(fn (int $i): Mensagem => $this->despacho(id: $i), range(1, 20));

        self::assertCount(20, $leitura->paraLeitor($mensagens, $this->bruno));
        $leitura->paraLeitor($mensagens, $this->bruno);   // uma revogação entre as duas leituras valeria
    }

    #[TestDox('Pasta sem NUP: a referência vai com nup nulo (a tela mostra o id)')]
    public function testPastaSemNup(): void
    {
        $this->pasta->setNup('  ');

        self::assertEquals(new DespachoOutput(77, null), $this->leituraDasMensagens([$this->pasta], [2])->umaParaLeitor($this->despacho(), $this->bruno)->despacho);
    }

    #[TestDox('Referência: só uma vez, nunca em mensagem de sistema, e só com pasta do mesmo escritório')]
    public function testInvariantesDaReferencia(): void
    {
        $mensagem = $this->mensagem('x');
        self::assertFalse($mensagem->isDespacho());
        self::assertNull($mensagem->getDespachoTipo());
        self::assertNull($mensagem->getDespachoPastaId());

        $outro = new Tenant();
        DefineId::em($outro, 8);
        $deFora = (new Pasta())->setTenant($outro);
        $recusas = [
            fn () => $this->mensagem('x')->referenciarDespacho($deFora, TipoDeItemEncaminhado::Pasta),
            fn () => Mensagem::deSistema($this->tenant, $this->grupo, $this->ana, 'Ana adicionou Bruno', new \DateTimeImmutable())->referenciarDespacho($this->pasta, TipoDeItemEncaminhado::Pasta),
            fn () => $this->despacho()->referenciarDespacho($this->pasta, TipoDeItemEncaminhado::Meta),
        ];
        foreach ($recusas as $i => $recusa) {
            try {
                $recusa();
                self::fail('devia recusar o caso ' . $i);
            } catch (\DomainException) {
            }
        }
    }

    private function pessoa(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        DefineId::em($u, $id);

        return $u;
    }

    private function mensagem(string $texto, int $id = 1): Mensagem
    {
        $mensagem = new Mensagem($this->tenant, $this->grupo, $this->ana, $texto, new \DateTimeImmutable());
        DefineId::em($mensagem, $id);

        return $mensagem;
    }

    /** O despacho da Ana; `$pasta` permite apontar para um id sem a pasta real (outro escritório). */
    private function despacho(?Pasta $pasta = null, TipoDeItemEncaminhado $tipo = TipoDeItemEncaminhado::RegistroDados, int $id = 1): Mensagem
    {
        if ($pasta !== null) {
            $pasta->setTenant($this->tenant);
            DefineId::em($pasta, 77);
        }
        $mensagem = $this->mensagem(self::TEXTO, $id);
        $mensagem->referenciarDespacho($pasta ?? $this->pasta, $tipo);

        return $mensagem;
    }
}
