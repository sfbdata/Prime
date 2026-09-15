<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Traduz o valor gravado em `tarefa_mensagem.arquivo_anexo` no caminho em disco do arquivo.
 *
 * Existe como serviço, e não como método privado do `TarefaController`, por um motivo de teste:
 * `ServirFotoControllerTest::testServirFotoComPathTraversalRetorna404` documenta que um GET com
 * `../../` é normalizado pelo ROTEADOR antes de chegar ao controller — um teste funcional de
 * travessia passa verde com ou sem guarda, provando outra barreira. A guarda só pode ser provada
 * chamando-se quem a implementa, com o valor malicioso na mão.
 *
 * O valor de entrada vem do banco, escrito por `TarefaController::uploadFiles()`; não é entrada
 * direta do usuário hoje. Ainda assim era concatenado sem nenhuma verificação, enquanto
 * `ProfileController::servirFoto` e `PecaImagemController::servir` já recusam nome composto.
 */
final class CaminhoDeAnexoDeTarefa
{
    /**
     * Subdiretórios de `public/uploads/tarefas/` que podem ser servidos. `chat` é o único que o
     * código escreve hoje; `admin` existe em disco vindo de um fluxo antigo e fica na lista para
     * não transformar anexo histórico em 404 silencioso. Em produção, os 11 registros com anexo
     * estão todos em `chat`.
     */
    private const SUBDIRETORIOS = ['chat', 'admin'];

    private const RAIZ_RELATIVA = '/public/uploads/tarefas';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Devolve o caminho absoluto já normalizado, ou null quando o valor não é servível.
     *
     * Três camadas, nesta ordem:
     *  1. allowlist — precisa casar `/uploads/tarefas/<sub>/<arquivo>` exatamente, com `<sub>`
     *     entre os conhecidos e `<arquivo>` começando por alfanumérico (o que descarta `.` e `..`)
     *     e sem `/`;
     *  2. normalização — `realpath()` resolve symlink e qualquer `..` residual;
     *  3. confinamento — o resultado precisa estar DENTRO da raiz de anexos de tarefa.
     *
     * `realpath()` devolve false para arquivo inexistente, então este método também devolve null
     * nesse caso — os chamadores já traduziam ausência em 404, o resultado visível não muda.
     */
    public function resolver(string $caminhoPublico): ?string
    {
        if ($caminhoPublico === '') {
            return null;
        }

        if (preg_match($this->padrao(), $caminhoPublico) !== 1) {
            return null;
        }

        $raiz = realpath($this->raizAbsoluta());
        $real = realpath(rtrim($this->projectDir, '/') . '/public' . $caminhoPublico);

        if ($raiz === false || $real === false) {
            return null;
        }

        // O separador no fim é obrigatório: sem ele, `/uploads/tarefas-outro/x` passaria pelo
        // str_starts_with contra `/uploads/tarefas`.
        if (!str_starts_with($real, $raiz . \DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    public function raizAbsoluta(): string
    {
        return rtrim($this->projectDir, '/') . self::RAIZ_RELATIVA;
    }

    private function padrao(): string
    {
        $subs = implode('|', array_map(
            static fn (string $sub): string => preg_quote($sub, '#'),
            self::SUBDIRETORIOS,
        ));

        return '#^/uploads/tarefas/(?:' . $subs . ')/[A-Za-z0-9][A-Za-z0-9._-]*$#';
    }
}
