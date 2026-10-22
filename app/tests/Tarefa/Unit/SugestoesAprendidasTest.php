<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Entity\AprendizadoDaPendencia;
use App\Tarefa\Entity\AprendizadoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Service\SugestoesAprendidas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As contas do aprendizado, conferidas contra a lógica do protótipo (desenho 04B de 22/10, L151-158). Os registros vêm
 * do mais novo para o mais antigo, como o repositório os devolve.
 */
#[CoversClass(SugestoesAprendidas::class)]
#[Group('tarefa')]
final class SugestoesAprendidasTest extends TestCase
{
    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        $this->pessoa = new User();
    }

    #[TestDox('Frases: com 2 ou mais despachos na etapa, só os da etapa; agrupa sem diferença de maiúsculas e devolve as 3 mais usadas')]
    public function testFrasesDaEtapa(): void
    {
        $registros = [
            $this->despacho(EtapaDaPasta::Juridico, 'Junte a procuração.'),
            $this->despacho(EtapaDaPasta::Juridico, 'Analise o contrato.'),
            $this->despacho(EtapaDaPasta::Juridico, 'JUNTE A PROCURAÇÃO.'),
            $this->despacho(EtapaDaPasta::Juridico, 'Fale com o cliente.'),
            $this->despacho(EtapaDaPasta::Juridico, 'Analise o contrato.'),
            $this->despacho(EtapaDaPasta::Juridico, 'Analise o contrato.'),
            $this->despacho(EtapaDaPasta::Juridico, 'Ligue para o fórum.'),
            $this->despacho(EtapaDaPasta::Protocolo, 'Protocole hoje.'),
        ];

        $frases = SugestoesAprendidas::frases($registros, EtapaDaPasta::Juridico, null);

        self::assertSame(['Analise o contrato.', 'Junte a procuração.', 'Fale com o cliente.'], array_column($frases, 'texto'), 'o texto do grupo é o do registro mais novo; no empate, o mais recente primeiro');
        self::assertSame([3, 2, 1], array_column($frases, 'vezes'));
    }

    #[TestDox('Frases: com menos de 2 despachos na etapa, a base é tudo o que a pessoa despachou')]
    public function testFrasesSemBaseNaEtapa(): void
    {
        $registros = [
            $this->despacho(EtapaDaPasta::Juridico, 'Única desta etapa.'),
            $this->despacho(EtapaDaPasta::Protocolo, 'Protocole hoje.'),
        ];

        self::assertSame(['Única desta etapa.', 'Protocole hoje.'], array_column(SugestoesAprendidas::frases($registros, EtapaDaPasta::Juridico, null), 'texto'));
    }

    #[TestDox('Frases: com natureza marcada, só as dessa natureza; a natureza da frase vai junto')]
    public function testFrasesPorNatureza(): void
    {
        $registros = [
            $this->despacho(EtapaDaPasta::Juridico, 'Ciente do acordo.', NaturezaDoDespacho::Ciencia),
            $this->despacho(EtapaDaPasta::Juridico, 'Providencie o RG.', NaturezaDoDespacho::Providencia),
            $this->despacho(EtapaDaPasta::Juridico, 'Sem natureza.'),
        ];

        $frases = SugestoesAprendidas::frases($registros, EtapaDaPasta::Juridico, NaturezaDoDespacho::Providencia);

        self::assertSame([['texto' => 'Providencie o RG.', 'rotulo' => 'Providencie o RG.', 'vezes' => 1, 'natureza' => 'providencia']], $frases);
    }

    #[TestDox('Frases: agrupa pelos 90 primeiros caracteres; o rótulo corta em 108 com reticências acima de 110')]
    public function testChaveERotulo(): void
    {
        $base = str_repeat('a', 90);
        $longo = str_repeat('b', 111);
        $registros = [
            $this->despacho(EtapaDaPasta::Juridico, $base . ' fim um'),
            $this->despacho(EtapaDaPasta::Juridico, $base . ' fim dois'),
            $this->despacho(EtapaDaPasta::Juridico, $longo),
        ];

        $frases = SugestoesAprendidas::frases($registros, EtapaDaPasta::Juridico, null);

        self::assertSame(2, $frases[0]['vezes']);
        self::assertSame(str_repeat('b', 108) . '…', $frases[1]['rotulo']);
        self::assertSame($longo, $frases[1]['texto'], 'o clique põe o texto inteiro');
        self::assertSame(str_repeat('c', 110), SugestoesAprendidas::rotulo(str_repeat('c', 110)));
    }

    #[TestDox('Natureza preferida: a mais usada na etapa; no empate, a do despacho mais recente; nenhuma sem natureza')]
    public function testNaturezaPreferida(): void
    {
        $registros = [
            $this->despacho(EtapaDaPasta::Juridico, 'a', NaturezaDoDespacho::Ciencia),
            $this->despacho(EtapaDaPasta::Juridico, 'b', NaturezaDoDespacho::Providencia),
            $this->despacho(EtapaDaPasta::Juridico, 'c', NaturezaDoDespacho::Providencia),
            $this->despacho(EtapaDaPasta::Juridico, 'd', NaturezaDoDespacho::Ciencia),
            $this->despacho(EtapaDaPasta::Protocolo, 'e', NaturezaDoDespacho::CumpraSe),
            $this->despacho(EtapaDaPasta::Protocolo, 'f', NaturezaDoDespacho::CumpraSe),
            $this->despacho(EtapaDaPasta::Protocolo, 'g', NaturezaDoDespacho::CumpraSe),
        ];

        self::assertSame(NaturezaDoDespacho::Ciencia, SugestoesAprendidas::naturezaPreferida($registros, EtapaDaPasta::Juridico));
        self::assertNull(SugestoesAprendidas::naturezaPreferida([$this->despacho(EtapaDaPasta::Juridico, 'x')], EtapaDaPasta::Juridico));
    }

    #[TestDox('Prazo usual: o do meio dos prazos da etapa (sem os sem prazo), com pelo menos 2; senão, zero')]
    public function testPrazoUsual(): void
    {
        $registros = [
            $this->despacho(EtapaDaPasta::Juridico, 'a', null, 10),
            $this->despacho(EtapaDaPasta::Juridico, 'b', null, 0),
            $this->despacho(EtapaDaPasta::Juridico, 'c', null, 3),
            $this->despacho(EtapaDaPasta::Juridico, 'd', null, 7),
            $this->despacho(EtapaDaPasta::Juridico, 'e', null, 2),
            $this->despacho(EtapaDaPasta::Protocolo, 'f', null, 1),
        ];

        self::assertSame(7, SugestoesAprendidas::prazoUsual($registros, EtapaDaPasta::Juridico), '[2, 3, 7, 10]: o índice 2, como o protótipo');
        self::assertSame(0, SugestoesAprendidas::prazoUsual($registros, EtapaDaPasta::Protocolo), 'uma amostra só');
        self::assertSame(3, SugestoesAprendidas::prazoUsual([$this->despacho(EtapaDaPasta::Juridico, 'a', null, 3), $this->despacho(EtapaDaPasta::Juridico, 'b', null, 1), $this->despacho(EtapaDaPasta::Juridico, 'c', null, 9)], EtapaDaPasta::Juridico));
    }

    #[TestDox('Você costuma pedir: as 5 linhas mais pedidas para a etapa destino, sem diferença de maiúsculas')]
    public function testPedidos(): void
    {
        $linhas = ['RG', 'Procuração', 'rg', 'CPF', 'Comprovante', 'Contrato', 'Certidão', 'procuração', 'RG'];
        $registros = array_map(fn (string $l): AprendizadoDaPendencia => new AprendizadoDaPendencia($this->escritorio, $this->pessoa, EtapaDaPasta::Documentacao, $l), $linhas);
        $registros[] = new AprendizadoDaPendencia($this->escritorio, $this->pessoa, EtapaDaPasta::Juridico, 'Outra etapa');

        self::assertSame(['RG', 'Procuração', 'CPF', 'Comprovante', 'Contrato'], SugestoesAprendidas::pedidos($registros, EtapaDaPasta::Documentacao));
        self::assertSame(['Outra etapa'], SugestoesAprendidas::pedidos($registros, EtapaDaPasta::Juridico));
        self::assertSame([], SugestoesAprendidas::pedidos($registros, EtapaDaPasta::Protocolo));
    }

    private function despacho(EtapaDaPasta $etapa, string $texto, ?NaturezaDoDespacho $natureza = null, int $dias = 0): AprendizadoDoDespacho
    {
        return new AprendizadoDoDespacho($this->escritorio, $this->pessoa, $etapa, $natureza, $texto, $dias);
    }
}
