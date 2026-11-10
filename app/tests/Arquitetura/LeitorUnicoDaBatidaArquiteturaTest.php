<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Ponto\Repository\RegistroPontoRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E10 (plano-e10-ponto §1 e §7, bloco A): **a batida do Ponto se lê pelo leitor único.**
 *
 * A batida gravada nunca muda; a correção do gestor entra em `ponto_ajuste`. Quem ler `RegistroPonto` direto (um
 * `find`, um `findBy`, uma DQL, um QueryBuilder) vê a batida ORIGINAL: a desconsiderada volta para a conta, a
 * alterada aparece com a hora antiga e a incluída some. Por isso só o {@see RegistroPontoRepository} (que devolve a
 * batida EFETIVA) e o repositório da entidade do ajuste leem as duas entidades.
 *
 * O que conta, fora dos comentários (lidos pelo tokenizer do PHP): o nome da classe da entidade usado como valor
 * (`RegistroPonto::class`, `PontoAjuste::class`: `getRepository`, `find` do EntityManager, `->from()` e `->join()` do
 * QueryBuilder), o nome completo dela dentro de uma string (DQL escrita à mão) e os métodos de leitura genéricos
 * (`find`, `findBy`, `findOneBy`, `findAll`, `count`, `matching`, `createQueryBuilder`) chamados numa variável ou
 * propriedade tipada com um dos dois repositórios.
 *
 * A lista é EXATA, como a de `AcessoDiretoAoDiscoArquiteturaTest`: arquivo novo que lê direto derruba o teste, e
 * arquivo que deixou de ler também, para a lista não guardar entrada morta.
 */
#[CoversNothing]
final class LeitorUnicoDaBatidaArquiteturaTest extends TestCase
{
    /** As duas entidades, pelo último nome (com ou sem o namespace na frente). */
    private const ENTIDADES = '(?:RegistroPonto|PontoAjuste)';

    /** Os dois repositórios, como tipo de parâmetro ou de propriedade. */
    private const REPOSITORIOS = '/\b(?:RegistroPontoRepository|PontoAjusteRepository)\s+\$(\w+)/';

    /** Os métodos de leitura que ignoram o ajuste (os do `ServiceEntityRepository`). */
    private const LEITURA_GENERICA = '(?:find|findBy|findOneBy|findAll|count|matching|createQueryBuilder)';

    /**
     * Quem pode ler as duas entidades direto, e por quê.
     *
     * @var array<string, string>
     */
    private const QUEM_LE_DIRETO = [
        'src/Ponto/Repository/RegistroPontoRepository.php' => 'o leitor único: a batida efetiva, a linhagem dos ajustes e o envio (E13), que é a original de propósito',
        'src/Ponto/Repository/PontoAjusteRepository.php'   => 'o repositório da entidade do ajuste',
        'src/Ponto/Entity/PontoAjuste.php'                 => 'mapeamento do ORM (targetEntity das ligações), não é leitura',
        // Provisória: `pontoEdit` e `pontoDelete` (o `find` do registro pelo id) saem no bloco B da E10, com as rotas
        // de ajuste. Quando saírem, este teste acusa a entrada morta: tire-a daqui.
        'src/Controller/TenantController.php'              => 'pontoEdit e pontoDelete, que o bloco B da E10 tira',
    ];

    #[TestDox('Só os arquivos da lista leem RegistroPonto e PontoAjuste direto (a lista é exata, cada entrada justificada)')]
    public function testSoOLeitorUnicoLeABatida(): void
    {
        $esperados = array_keys(self::QUEM_LE_DIRETO);
        sort($esperados);

        self::assertSame(
            $esperados,
            $this->arquivosQueLeemDireto(),
            'A batida do Ponto se lê pelo RegistroPontoRepository, que devolve a efetiva (com os ajustes do gestor). '
            . 'Leitura direta da entidade ignora o ajuste. Se a entrada saiu porque o arquivo deixou de ler, tire-a da lista.',
        );
    }

    #[TestDox('O detector enxerga cada forma de leitura direta e ignora comentário, constante e escrita')]
    public function testDetectorEnxergaAsFormasDeLeitura(): void
    {
        $leem = [
            '<?php $em->getRepository(RegistroPonto::class)->findAll();',
            '<?php $em->find(\App\Ponto\Entity\PontoAjuste::class, 3);',
            '<?php $qb->select("r")->from(RegistroPonto::class, "r");',
            '<?php $em->createQuery("SELECT r FROM App\Ponto\Entity\RegistroPonto r");',
            '<?php $em->createQuery(\'SELECT a FROM App\\\\Ponto\\\\Entity\\\\PontoAjuste a\');',
            '<?php function f(RegistroPontoRepository $registros) { return $registros->findBy(["tenant" => 1]); }',
            '<?php final class X { public function __construct(private readonly PontoAjusteRepository $ajustes) {} public function f() { return $this->ajustes->find(1); } }',
            '<?php function f(RegistroPontoRepository $repo) { return $repo->createQueryBuilder("r"); }',
        ];
        foreach ($leem as $codigo) {
            self::assertTrue(self::leDireto($codigo), 'deveria acusar: ' . $codigo);
        }

        $naoLeem = [
            '<?php // $em->getRepository(RegistroPonto::class)->findAll();',
            '<?php /** @see RegistroPonto::class */ $x = 1;',
            '<?php $tipo = RegistroPonto::TIPO_ENTRADA; $b = new RegistroPonto();',
            '<?php if ($batida instanceof RegistroPonto) { return $batida->alvo(); }',
            '<?php function f(RegistroPontoRepository $registros) { return $registros->findByUserAndCompetencia($u, $t, 2026, 10); }',
            '<?php function f(RegistroPontoRepository $registros, $outro) { return $outro->find(1); }',
        ];
        foreach ($naoLeem as $codigo) {
            self::assertFalse(self::leDireto($codigo), 'não deveria acusar: ' . $codigo);
        }
    }

    /** @return list<string> */
    private function arquivosQueLeemDireto(): array
    {
        $raiz = \dirname(__DIR__, 2);
        $achados = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $arquivo */
        foreach ($iterador as $arquivo) {
            if ($arquivo->getExtension() === 'php' && self::leDireto((string) file_get_contents($arquivo->getPathname()))) {
                $achados[] = substr($arquivo->getPathname(), \strlen($raiz) + 1);
            }
        }
        sort($achados);

        return $achados;
    }

    private static function leDireto(string $codigo): bool
    {
        [$semComentarios, $strings] = self::separar($codigo);

        if (preg_match('/(?<![\w\\\\])(?:\\\\?[\w\\\\]*\\\\)?' . self::ENTIDADES . '::class\b/', $semComentarios) === 1) {
            return true;
        }
        if (preg_match('/Ponto\\\\+Entity\\\\+' . self::ENTIDADES . '\b/', $strings) === 1) {
            return true;
        }
        preg_match_all(self::REPOSITORIOS, $semComentarios, $variaveis);
        foreach (array_unique($variaveis[1]) as $nome) {
            if (preg_match('/\$(?:this->)?' . preg_quote($nome, '/') . '\s*->\s*' . self::LEITURA_GENERICA . '\s*\(/', $semComentarios) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * O código sem os comentários e, à parte, só o conteúdo das strings (onde mora a DQL escrita à mão).
     *
     * @return array{string, string}
     */
    private static function separar(string $codigo): array
    {
        $limpo = '';
        $strings = '';
        foreach (token_get_all($codigo) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                $strings .= $token[1] . "\n";
            }
            $limpo .= \is_array($token) ? $token[1] : $token;
        }

        return [$limpo, $strings];
    }
}
