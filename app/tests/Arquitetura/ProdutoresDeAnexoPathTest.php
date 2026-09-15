<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Sustenta a premissa que fecha a janela entre COMMIT, contagem de referências e DELETE físico do
 * anexo de justificativa (ver `SubstituirAnexoDoLoteUseCase` e a spec da E1).
 *
 * O argumento é a **monotonicidade decrescente das referências**: como todo valor gravado em
 * `anexo_path` vem de `ArquivoStorageService::salvar()` — `bin2hex(random_bytes(16))`, nome novo a
 * cada chamada, nunca reaproveitado —, nenhum caminho consegue fazer um registro voltar a apontar
 * para um arquivo que já perdeu a última referência. Uma contagem que dá zero é definitiva.
 *
 * A premissa cai se alguém **copiar uma chave existente** para outro registro. Isso não tem como
 * ser provado estaticamente em geral, então a estratégia aqui é cercar as quatro portas por onde a
 * cópia entraria, cada uma com sua allowlist: quem ESCREVE, quem LÊ, quem clona e quem escreve a
 * coluna por SQL cru. Qualquer porta nova quebra a suíte e força revisão.
 */
final class ProdutoresDeAnexoPathTest extends TestCase
{
    /**
     * Quem grava `anexo_path`. Todos gravam o retorno de `storage->salvar()`:
     * PontoController (upload do colaborador), TenantController (upload do admin) e
     * SubstituirAnexoDoLoteUseCase (a troca da E1).
     */
    private const ESCRITORES = [
        'src/Ponto/Controller/PontoController.php',
        'src/Controller/TenantController.php',
        'src/Ponto/UseCase/SubstituirAnexoDoLoteUseCase.php',
    ];

    /**
     * Quem LÊ `anexo_path` pelo getter — a porta pela qual uma cópia entraria de forma indireta
     * (`$v = $a->getAnexoPath(); $b->setAnexoPath($v);`). Os dois leitores atuais só servem o
     * arquivo (download); nenhum repassa o valor para outro registro.
     *
     * `SubstituirAnexoDoLoteUseCase` NÃO está aqui de propósito: ele lê o anexo por projeção
     * escalar (`anexoNoBancoPorId`), justamente para pegar o valor do banco sob a trava em vez do
     * que estiver no identity map.
     *
     * `ChavesDePonto` (E2.2) lê o getter para montar a `ChaveDeArquivo` da própria justificativa —
     * o valor vai para o storage como endereço e nunca para `setAnexoPath()` de outro registro.
     * Leitor legítimo; a monotonicidade das referências continua de pé.
     */
    private const LEITORES = [
        'src/Controller/TenantController.php',
        'src/Ponto/Armazenamento/ChavesDePonto.php',
        'src/Ponto/Controller/PontoController.php',
    ];

    /**
     * Quem ESCREVE a coluna fora do ORM. Vazia de propósito: hoje ninguém escreve `anexo_path` por
     * SQL cru nem por DQL bulk. `PurgarEscritorioUseCase` só faz `SELECT anexo_path`, então não
     * precisa de isenção — e isentá-lo "por precaução" deixaria um `UPDATE` futuro passar calado.
     *
     * @var string[]
     */
    private const ESCRITA_FORA_DO_ORM = [];

    private const ENTIDADE = 'src/Ponto/Entity/JustificativaPonto.php';

    #[TestDox('Nenhum ESCRITOR novo de anexo_path apareceu sem revisão')]
    public function testEscritoresConhecidos(): void
    {
        self::assertSame(
            $this->ordenado(self::ESCRITORES),
            $this->ordenado($this->arquivosComPadrao('/setAnexoPath\s*\(/', puloEntidade: true)),
            $this->recado('grava'),
        );
    }

    #[TestDox('Nenhum LEITOR novo de anexo_path apareceu — é por aí que a cópia entra')]
    public function testLeitoresConhecidos(): void
    {
        self::assertSame(
            $this->ordenado(self::LEITORES),
            $this->ordenado($this->arquivosComPadrao('/->getAnexoPath\s*\(/', puloEntidade: true)),
            $this->recado('lê'),
        );
    }

    #[TestDox('Ninguém escreve anexo_path fora do ORM (SQL cru ou DQL bulk)')]
    public function testNinguemEscreveForaDoOrm(): void
    {
        $suspeitos = [];

        foreach ($this->arquivosPhp() as $relativo => $conteudo) {
            // (a) SQL cru: coluna em snake_case, num INSERT/UPDATE. Só esses criam referência.
            $sqlCru = preg_match('/\b(UPDATE|INSERT)\b[^;]{0,400}\banexo_path\b/is', $conteudo) === 1;

            // (b) DQL bulk: `->update(...)->set('j.anexoPath', ...)`. Escapa do item (a) porque
            // usa o nome da PROPRIEDADE, não o da coluna — e escapa dos testes de escritor/leitor
            // porque não chama setter nem getter. Este repositório já usa UPDATE em DQL noutro
            // domínio, então o portão é real.
            $dqlBulk = preg_match('/->set\s*\(\s*[\'"][A-Za-z0-9_]*\.?anexoPath[\'"]/i', $conteudo) === 1;

            if ($sqlCru || $dqlBulk) {
                $suspeitos[] = $relativo;
            }
        }

        self::assertSame(
            [],
            array_values(array_diff($suspeitos, self::ESCRITA_FORA_DO_ORM)),
            'Escrita de anexo_path fora do ORM escapa de toda guarda e pode duplicar uma chave '
            . 'existente, derrubando a monotonicidade das referências.',
        );
    }

    #[TestDox('Ninguém clona JustificativaPonto (clone duplica a chave sem chamar setter)')]
    public function testNinguemClonaAJustificativa(): void
    {
        $suspeitos = [];

        foreach ($this->arquivosPhp() as $relativo => $conteudo) {
            if (preg_match('/\bclone\s+\$[A-Za-z0-9_]*[Jj]ustificativa/', $conteudo) === 1) {
                $suspeitos[] = $relativo;
            }
        }

        self::assertSame([], $suspeitos, 'clone copia anexo_path sem passar por setAnexoPath().');
    }

    #[TestDox('A entidade não tem outro caminho de escrita além do setter')]
    public function testEntidadeNaoTemEscritaAlternativa(): void
    {
        $conteudo = (string) file_get_contents(\dirname(__DIR__, 2) . '/' . self::ENTIDADE);

        preg_match_all('/\$this->anexoPath\s*=/', $conteudo, $atribuicoes);

        self::assertCount(
            1,
            $atribuicoes[0],
            'A entidade deveria atribuir $this->anexoPath em UM lugar só (o setter). Um helper '
            . 'como `copiarAnexoDe(self $outro)` escaparia de toda a guarda deste teste.',
        );
    }

    // ------------------------------------------------------------------ helpers

    private function recado(string $verbo): string
    {
        return "A lista de quem {$verbo} `anexo_path` mudou.\n\n"
            . "Antes de atualizar a constante: confirme que o valor gravado vem de `storage->salvar()`\n"
            . "e NÃO é uma chave copiada de outro registro. Se for cópia, a monotonicidade das\n"
            . "referências cai e a remoção física em SubstituirAnexoDoLoteUseCase passa a ter uma\n"
            . "corrida real — reveja o desenho antes de liberar.";
    }

    /** @param string[] $lista @return string[] */
    private function ordenado(array $lista): array
    {
        $copia = array_values($lista);
        sort($copia);

        return $copia;
    }

    /** @return string[] */
    private function arquivosComPadrao(string $padrao, bool $puloEntidade): array
    {
        $achados = [];

        foreach ($this->arquivosPhp() as $relativo => $conteudo) {
            if ($puloEntidade && $relativo === self::ENTIDADE) {
                continue;
            }
            if (preg_match($padrao, $conteudo) === 1) {
                $achados[] = $relativo;
            }
        }

        return $achados;
    }

    /** @return array<string,string> */
    private function arquivosPhp(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $raiz  = \dirname(__DIR__, 2);
        $cache = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $arquivo */
        foreach ($iterador as $arquivo) {
            if ($arquivo instanceof \SplFileInfo && $arquivo->getExtension() === 'php') {
                $cache[str_replace($raiz . '/', '', $arquivo->getPathname())]
                    = (string) file_get_contents($arquivo->getPathname());
            }
        }

        return $cache;
    }
}
