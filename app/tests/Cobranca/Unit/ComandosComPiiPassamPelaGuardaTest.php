<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Command\LidaComDadoPessoal;
use App\Cobranca\Command\NaoLidaComDadoPessoal;
use App\Cobranca\Service\Espelho\GuardaDeLogComPii;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 🔴 INV-Q10 — **todo comando de cobrança declara se lida com dado pessoal, e quem não declarar
 * quebra a suíte.**
 *
 * ## Duas versões anteriores desta trava falharam, e vale saber como
 *
 * 1. **Lista de caminhos escrita à mão.** Comando novo que esquecesse a guarda passava, porque
 *    ninguém lembraria de acrescentá-lo — o modo de falha que a trava existe para combater.
 * 2. **Heurística por marcas de PII no código.** A terceira revisão provou que era **circular**:
 *    apagando a guarda dos quatro comandos do espelho e da reconciliação, sobravam **zero** marcas,
 *    porque a única marca que eles tinham era a string da própria opção `--aceito-log-com-pii`.
 *    O teste ficava **verde justamente quando a proteção era removida**. Cobria 4 de 9.
 *
 * ## A régua atual não adivinha
 *
 * Todo `*Command.php` de `src/Cobranca/Command/` implementa {@see LidaComDadoPessoal} **ou**
 * {@see NaoLidaComDadoPessoal}. Três checagens, nenhuma circular:
 *
 * | # | regra | pega |
 * |---|---|---|
 * | 1 | declara **exatamente uma** das duas | o comando novo que não decidiu |
 * | 2 | declarou `LidaComDadoPessoal` ⟹ chama a guarda | quem declara e não protege |
 * | 3 | declarou `NaoLidaComDadoPessoal` ⟹ não menciona campo de PII | quem se dispensou errado |
 *
 * A checagem 1 é por `ReflectionClass::implementsInterface()`, não por texto — então
 * `implements Outra, LidaComDadoPessoal` (interface em último lugar) casa igual, que era outro furo
 * apontado na revisão.
 *
 * ⚠️ A checagem 2 lê o fonte **sem comentários** (`token_get_all`). A versão anterior buscava no
 * fonte cru e passava com a chamada da guarda **comentada** — a mesma família de teste vazio que já
 * apareceu três vezes neste módulo.
 */
#[CoversClass(GuardaDeLogComPii::class)]
final class ComandosComPiiPassamPelaGuardaTest extends TestCase
{
    private const NAMESPACE_DOS_COMANDOS = 'App\\Cobranca\\Command\\';

    /**
     * Campos que caracterizam dado pessoal de terceiro.
     *
     * 🔑 **Só são usadas na checagem 3** — sobre quem declarou NÃO lidar com PII. Nessa direção a
     * lista não é circular: ela procura marcas em código que, por declaração, não deveria tê-las.
     * Usá-la na direção contrária foi o defeito da versão anterior.
     */
    private const MARCAS_DE_PII = [
        'sacado', 'Sacado', 'Condomino', 'Condômino',
        'documento', 'Documento', 'cpf', 'Cpf', 'CNPJ', 'cnpj',
        'telefone', 'Telefone', 'endereco', 'Endereco',
        // `email` voltou: saiu sem justificativa na rodada anterior, e e-mail é um dos três dados
        // que a própria guarda cita. `Pessoa` continua FORA, e por motivo registrado — casa dentro
        // de `NaoLidaComDadoPessoal`, o que tornaria a checagem 3 sempre positiva.
        'email', 'Email', 'eMail',
    ];

    /** @return iterable<string, array{0: string}> */
    public static function comandosDeCobranca(): iterable
    {
        $arquivos = glob(\dirname(__DIR__, 3) . '/src/Cobranca/Command/*Command.php') ?: [];

        sort($arquivos);

        foreach ($arquivos as $arquivo) {
            yield basename($arquivo) => [$arquivo];
        }
    }

    /**
     * A varredura tem de enxergar o diretório inteiro — senão a suíte fica verde sobre nada.
     *
     * ⚠️ A contagem é **exata**, não `>=`. Com `>=` (o que a revisão apontou), remover um comando não
     * acusava nada: o teste seguia verde com um a menos. Mexer no número aqui é ato consciente.
     */
    #[TestDox('A varredura enxerga TODOS os comandos de cobrança')]
    public function testAVarreduraCobreODiretorioInteiro(): void
    {
        $encontrados = iterator_count(self::comandosDeCobranca());
        $noDisco = count(glob(\dirname(__DIR__, 3) . '/src/Cobranca/Command/*Command.php') ?: []);

        self::assertSame($noDisco, $encontrados);
        // 11 desde 19/08: entrou `ReconciliarHonorarioDeParcelaCommand`, declarado
        // `LidaComDadoPessoal` — ele imprime a UNIDADE junto do NN, e essa dupla identifica o devedor.
        self::assertSame(
            11,
            $encontrados,
            'o número de comandos de cobrança mudou — atualize este teste CONSCIENTEMENTE, '
            . 'depois de decidir a qual das duas interfaces o comando novo pertence',
        );
    }

    /**
     * **Checagem 1 — a que acaba com a categoria inteira.**
     *
     * Comando novo não consegue nascer fora da régua porque não consegue nascer sem decidir.
     *
     * **Reintrodução provada:** removendo `implements LidaComDadoPessoal` de qualquer um dos nove,
     * este teste fica vermelho para ele — mesmo que o comando não mencione PII nenhuma, que era
     * exatamente o buraco da versão heurística.
     */
    #[DataProvider('comandosDeCobranca')]
    #[TestDox('Todo comando declara SE lida com dado pessoal')]
    public function testTodoComandoDeclara(string $arquivo): void
    {
        $classe = $this->classeDe($arquivo);

        $lida = $classe->implementsInterface(LidaComDadoPessoal::class);
        $naoLida = $classe->implementsInterface(NaoLidaComDadoPessoal::class);

        self::assertNotSame(
            $lida,
            $naoLida,
            sprintf(
                "%s tem de implementar EXATAMENTE UMA de LidaComDadoPessoal / NaoLidaComDadoPessoal.\n"
                . 'Nenhuma das duas: o comando nasceu fora da régua. As duas: a declaração se contradiz. '
                . 'Com APP_DEBUG=1 o log do Doctrine imprime os parâmetros das consultas — nome, CPF, '
                . 'e-mail e telefone por extenso — numa saída que é colada em chat.',
                basename($arquivo),
            ),
        );
    }

    /**
     * **Checagem 2 — declarar não basta, tem de proteger.**
     *
     * **Reintrodução provada:** comentando a chamada `guardaDeLog->bloqueia()` em qualquer um dos
     * nove, este teste fica vermelho — comentário não conta como código.
     */
    #[DataProvider('comandosDeCobranca')]
    #[TestDox('Quem declara lidar com dado pessoal consulta a guarda')]
    public function testQuemDeclaraProtege(string $arquivo): void
    {
        if (!$this->classeDe($arquivo)->implementsInterface(LidaComDadoPessoal::class)) {
            self::assertTrue(true, 'declarou não lidar com PII — a checagem 3 confere se é verdade');

            return;
        }

        // ⚠️ Não basta a chamada existir: ela tem de DECIDIR. Buscar só `bloqueia(` passava com a
        // chamada dentro de `if (false)`, depois de um `return`, ou dentro de uma string literal
        // (`token_get_all` remove comentário, não string). A forma exigida é a que interrompe.
        $codigo = preg_replace('/\s+/', '', $this->codigoSemComentarios($arquivo)) ?? '';

        self::assertStringContainsString(
            'if($this->guardaDeLog->bloqueia(',
            $codigo,
            sprintf(
                '%s declara LidaComDadoPessoal mas não consulta a GuardaDeLogComPii numa condição que '
                . 'interrompa a execução. A forma esperada é `if ($this->guardaDeLog->bloqueia(...)) '
                . '{ return GuardaDeLogComPii::LOG_COM_PII; }`.',
                basename($arquivo),
            ),
        );

        self::assertStringContainsString(
            'returnGuardaDeLogComPii::LOG_COM_PII;',
            $codigo,
            sprintf('%s chama a guarda mas não sai com o código 69 quando ela bloqueia.', basename($arquivo)),
        );
    }

    /**
     * **Checagem 3 — quem se dispensou tem de estar certo.**
     *
     * Esta é a única que usa as marcas de PII, e nesta direção elas **não** são circulares: procuram
     * campo de dado pessoal em código que, por declaração, não deveria tê-lo.
     *
     * **Reintrodução provada:** trocando a interface de `ImportarCadastroCondominosCommand` para
     * `NaoLidaComDadoPessoal`, este teste fica vermelho apontando `Condomino, telefone`.
     */
    #[DataProvider('comandosDeCobranca')]
    #[TestDox('Quem declara NÃO lidar com dado pessoal realmente não toca campo de PII')]
    public function testQuemSeDispensaEstaCerto(string $arquivo): void
    {
        if (!$this->classeDe($arquivo)->implementsInterface(NaoLidaComDadoPessoal::class)) {
            self::assertTrue(true, 'declarou lidar com PII — a checagem 2 confere a proteção');

            return;
        }

        $codigo = $this->codigoSemComentarios($arquivo);

        $encontradas = array_values(array_filter(
            self::MARCAS_DE_PII,
            static fn (string $marca): bool => str_contains($codigo, $marca),
        ));

        self::assertSame(
            [],
            $encontradas,
            sprintf(
                "%s declara NaoLidaComDadoPessoal, mas o código menciona: %s.\n"
                . 'Ou ele lida com dado pessoal e a interface está errada, ou o identificador é '
                . 'infeliz e vale renomear. A régua falha fechado de propósito.',
                basename($arquivo),
                implode(', ', $encontradas),
            ),
        );
    }

    /** @return \ReflectionClass<object> */
    private function classeDe(string $arquivo): \ReflectionClass
    {
        $nome = self::NAMESPACE_DOS_COMANDOS . basename($arquivo, '.php');

        self::assertTrue(class_exists($nome), sprintf('classe %s não encontrada', $nome));

        return new \ReflectionClass($nome);
    }

    /**
     * O fonte sem comentários nem docblocks — é o que impede a checagem 2 de passar com a chamada da
     * guarda comentada.
     */
    private function codigoSemComentarios(string $arquivo): string
    {
        $fonte = file_get_contents($arquivo);
        self::assertIsString($fonte, sprintf('não consegui ler %s', $arquivo));

        $limpo = '';

        foreach (token_get_all($fonte) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $limpo .= $token[1];

                continue;
            }

            $limpo .= $token;
        }

        return $limpo;
    }
}
