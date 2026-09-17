<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;

/**
 * O backend de disco — o único que existe na E2, e o que continua servindo os mesmos arquivos,
 * nos mesmos caminhos (INV-1).
 *
 * Conviveu de propósito com `ArquivoStorageService` — o shim de D2 — enquanto os 33 consumidores do
 * início da E2 migravam fatia a fatia: os dois escrevem no mesmo disco, pelo mesmo layout. **Desde a
 * E2.6C o shim não atende mais ninguém em produção** (o último era o envio ao Drive) e sai na E2.8.
 * Esta classe
 * **não** implementa a interface antiga — duas implementações da mesma interface quebrariam o
 * autowiring por interface e derrubariam todos de uma vez.
 *
 * ## O que ele faz melhor que o antigo, sem mudar nenhum caminho
 *
 *  - **escrita atômica**: grava num temporário no MESMO diretório e faz `rename()` para o lugar.
 *    O antigo usa `file_put_contents` direto e sem checar retorno (`ArquivoStorageService.php:28`) —
 *    disco cheio no meio deixava arquivo parcial, e em silêncio;
 *  - **erro não some**: toda falha de I/O vira `FalhaDeArmazenamento`;
 *  - **metadados no retorno**: tamanho e MIME medidos DEPOIS da escrita, o que remove o motivo de
 *    alguém tocar na origem depois de gravar (a causa do 500 do Kanban).
 *
 * ## Também é o materializador do disco (E2.3, E2.6A)
 *
 * Aqui `paraLeitura()` é cópia zero: o arquivo já está em disco, então o caminho emprestado é o
 * dele. `copiaGravavel()` copia para o {@see DiretorioTemporarioPrivado} (D29) — fora do volume, só
 * para o dono. Moram nesta classe, e não numa vizinha, para que o `ResolvedorDeCaminhoLocal` continue
 * sendo detalhe privado do backend (D11) — ninguém fora daqui converte chave em caminho.
 *
 * ## E a operação por prefixo (E2.5)
 *
 * `listar()`/`excluirPrefixo()` só aceitam {@see CategoriaComIsolamentoFisico} (D7) e, antes de
 * tocar em qualquer arquivo, provam que o diretório é do escritório e inventariam a árvore sem
 * seguir link. É por isso que esta classe é a única entrada da allowlist de
 * `LimpezaDeArquivosArquiteturaTest`.
 */
final readonly class ArmazenamentoLocal implements ArmazenamentoDeArquivos, MaterializadorDeArquivo, ArmazenamentoComPrefixo
{
    /** Tentativas de cunhagem antes de desistir. Com 128 bits, colidir uma vez já é anedota. */
    private const TENTATIVAS_DE_CUNHAGEM = 5;

    /** Tipos de `st_mode` (bits S_IFMT) que a varredura do prefixo distingue. */
    private const S_IFMT  = 0o170000;
    private const S_IFDIR = 0o040000;
    private const S_IFREG = 0o100000;
    private const S_IFLNK = 0o120000;

    private DiretorioTemporarioPrivado $temporarios;

    /**
     * @param DiretorioTemporarioPrivado|null $temporarios onde nascem as cópias graváveis; o padrão é
     *                                                     o diretório privado do processo — informar
     *                                                     só em teste
     */
    public function __construct(
        private ResolvedorDeCaminhoLocal $resolvedor,
        ?DiretorioTemporarioPrivado $temporarios = null,
    ) {
        $this->temporarios = $temporarios ?? DiretorioTemporarioPrivado::doProcesso('copia');
    }

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        $chave = $destino instanceof NovoArquivo
            ? $this->cunharChaveLivre($destino)
            : $destino;

        $caminho = $this->resolvedor->caminhoDe($chave);

        $this->escreverAtomicamente($caminho, $fonte);

        clearstatcache(true, $caminho);
        $tamanho = @filesize($caminho);
        if ($tamanho === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Gravou mas não conseguiu medir o arquivo %s.', $chave->comoTexto()),
            );
        }

        return new ArquivoArmazenado($chave, $tamanho, $this->mimeDe($caminho));
    }

    public function abrir(ChaveDeArquivo $chave): mixed
    {
        $caminho = $this->exigirArquivoPresente($chave);

        $recurso = @fopen($caminho, 'rb');
        if ($recurso === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Arquivo existe mas não pôde ser aberto: %s', $chave->comoTexto()),
            );
        }

        return $recurso;
    }

    public function ler(ChaveDeArquivo $chave): string
    {
        $caminho = $this->exigirArquivoPresente($chave);

        $conteudo = @file_get_contents($caminho);
        if ($conteudo === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Arquivo existe mas não pôde ser lido: %s', $chave->comoTexto()),
            );
        }

        return $conteudo;
    }

    /**
     * Ausência devolve false; **impossibilidade de saber lança**.
     *
     * A diferença importa: um diretório sem permissão faria `file_exists()` devolver false, e uma
     * rotina de limpeza leria isso como "o arquivo sumiu". Diretório que simplesmente não existe
     * ainda é ausência normal — nada foi gravado ali.
     *
     * Duas sutilezas que custaram defeito reproduzido em revisão:
     *
     *  - usa `is_file()`, não `file_exists()`. Uma chave endereça ARQUIVO; um diretório naquele
     *    caminho é ausência de arquivo. Com `file_exists()`, `existe()` dizia true enquanto
     *    `metadados()` dizia null e `excluir()` não removia nada — três métodos discordando, e o
     *    padrão `if (existe()) { metadados()->tamanhoBytes; }` virava TypeError fatal;
     *  - a checagem de legibilidade percorre **toda a cadeia** de ancestrais, não só o pai. Se o
     *    avô perde `+x`, o próprio `is_dir()` do pai já falha, a guarda do pai nunca dispara e o
     *    método voltava false em silêncio. Não é hipótese: `public/uploads/*` nascendo com o uid
     *    errado é incidente recorrente registrado no CLAUDE.md, e `public/uploads/cobrancas` é o
     *    avô das duas categorias com isolamento físico.
     */
    public function existe(ChaveDeArquivo $chave): bool
    {
        $caminho = $this->resolvedor->caminhoDe($chave);
        clearstatcache(true, $caminho);

        if (is_file($caminho)) {
            return true;
        }

        $this->exigirCadeiaLegivel($caminho, $chave->comoTexto());

        return false;
    }

    /**
     * Idempotente só quando a ausência está PROVADA (E2.5).
     *
     * Com um diretório ilegível no caminho, `is_file()` devolve false e a versão anterior
     * voltava em silêncio: quem chamava achava que o arquivo tinha saído, nada era registrado e o
     * órfão ficava invisível. Agora a cadeia é provada como em `existe()`, e a impossibilidade de
     * olhar vira `FalhaDeArmazenamento` — que a remoção pós-COMMIT transforma em log.
     */
    public function excluir(ChaveDeArquivo $chave): void
    {
        $caminho = $this->resolvedor->caminhoDe($chave);
        clearstatcache(true, $caminho);

        if (!is_file($caminho)) {
            $this->exigirCadeiaLegivel($caminho, $chave->comoTexto());

            return; // idempotente: apagar o que comprovadamente não existe não é erro
        }

        if (@unlink($caminho)) {
            return;
        }

        clearstatcache(true, $caminho);
        if (is_file($caminho)) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível remover %s.', $chave->comoTexto()),
            );
        }
    }

    /**
     * Null é ausência PROVADA, como em `existe()` (D10).
     *
     * Sem a prova, um diretório ilegível acima do arquivo fazia `is_file()` devolver false e a
     * medição respondia "não existe" a uma pane — e quem mede primeiro (a compressão da E2.6)
     * transformava isso em `ArquivoNaoEncontrado`, ou seja, em 404 numa rota.
     */
    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
    {
        $caminho = $this->resolvedor->caminhoDe($chave);
        clearstatcache(true, $caminho);

        if (!is_file($caminho)) {
            $this->exigirCadeiaLegivel($caminho, $chave->comoTexto());

            return null;
        }

        $tamanho = @filesize($caminho);
        $mtime   = @filemtime($caminho);

        if ($tamanho === false || $mtime === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível ler os metadados de %s.', $chave->comoTexto()),
            );
        }

        return new MetadadosDeArquivo(
            tamanhoBytes: $tamanho,
            mimeType: $this->mimeDe($caminho),
            atualizadoEm: (new \DateTimeImmutable())->setTimestamp($mtime),
            checksum: null, // D6: cálculo fica para a E3
        );
    }

    /**
     * Cópia zero: devolve, emprestado, o caminho do próprio arquivo persistido.
     *
     * A ordem das checagens é o D10. Antes de afirmar "não existe", prova que dava para olhar —
     * com o diretório ilegível o `is_file()` também devolve false, e sem a prova isso viraria 404
     * na rota. E um arquivo presente mas ilegível é pane, não ausência.
     */
    public function paraLeitura(ChaveDeArquivo $chave): ArquivoEmprestado
    {
        $caminho = $this->exigirArquivoPresente($chave);

        if (!is_readable($caminho)) {
            throw new FalhaDeArmazenamento(
                sprintf('Arquivo existe mas não é legível: %s', $chave->comoTexto()),
            );
        }

        return new ArquivoEmprestado($caminho);
    }

    /**
     * Cópia completa do persistido num temporário privado (D29).
     *
     * A ordem é a do D10 — a origem é aberta por {@see abrir()}, que distingue ausência de pane — e
     * a cópia só é devolvida com TODOS os bytes; qualquer parcial é apagada. A origem só é lida.
     *
     * Quem falhou importa (D26): falta de espaço ou diretório temporário inválido viram
     * {@see FalhaNoTemporario} (o persistido está intacto, dá para seguir sem comprimir); leitura
     * que não entrega o arquivo inteiro vira {@see FalhaDeArmazenamento} (pane, tem de subir).
     */
    public function copiaGravavel(ChaveDeArquivo $chave): ArquivoTemporarioPossuido
    {
        $origem = $this->abrir($chave);

        try {
            $copia = $this->temporarios->novoArquivo('copia-');

            try {
                $this->copiarInteiro($origem, $copia->caminho(), $chave);
            } catch (\Throwable $e) {
                $copia->liberar();

                throw $e;
            }

            return $copia;
        } finally {
            fclose($origem);
        }
    }

    /**
     * Copia tudo, e diz de QUEM foi a culpa quando não copia (D26).
     *
     * O `stream_copy_to_stream` devolve `false` tanto para escrita recusada (o `/tmp` encheu) quanto
     * para erro de leitura — medido na E2.6A. Quem separa os dois é a posição: numa falha de
     * escrita, o destino ficou atrás da origem; num erro de leitura, os dois pararam juntos. A
     * diferença decide se a compressão segue sem comprimir (temporário) ou se a pane sobe (leitura).
     *
     * @param resource $origem
     */
    private function copiarInteiro(mixed $origem, string $destino, ChaveDeArquivo $chave): void
    {
        $esperado = fstat($origem)['size'] ?? null;
        if ($esperado === null) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível medir a origem de %s para copiar.', $chave->comoTexto()),
            );
        }

        $saida = @fopen($destino, 'wb');
        if ($saida === false) {
            throw new FalhaNoTemporario(
                sprintf('Não foi possível abrir a cópia gravável de %s.', $chave->comoTexto()),
            );
        }

        try {
            $copiados = @stream_copy_to_stream($origem, $saida);

            if ($copiados === false) {
                throw $this->culpaDaCopia($origem, $saida, $chave);
            }

            if ($copiados !== $esperado) {
                throw new FalhaDeArmazenamento(sprintf(
                    'A leitura de %s entregou %d de %d bytes.',
                    $chave->comoTexto(),
                    $copiados,
                    $esperado,
                ));
            }
        } finally {
            fclose($saida);
        }
    }

    /**
     * De quem foi a falha quando `stream_copy_to_stream` devolve `false`.
     *
     * Destino atrás da origem = os bytes foram lidos e não couberam: é o temporário. Empatados (ou
     * posição indisponível) = na dúvida, pane de leitura, que SOBE — errar para o lado de subir
     * mostra o problema; errar para o outro lado o esconde num log.
     *
     * @param resource $origem
     * @param resource $saida
     */
    private function culpaDaCopia(mixed $origem, mixed $saida, ChaveDeArquivo $chave): FalhaDeArmazenamento
    {
        $escritos = ftell($saida);
        $lidos    = ftell($origem);

        if ($escritos !== false && $lidos !== false && $escritos < $lidos) {
            return new FalhaNoTemporario(sprintf(
                'Não foi possível escrever a cópia gravável de %s (%d de %d bytes).',
                $chave->comoTexto(),
                $escritos,
                $lidos,
            ));
        }

        return new FalhaDeArmazenamento(
            sprintf('A leitura de %s foi interrompida no meio da cópia.', $chave->comoTexto()),
        );
    }

    /**
     * Os arquivos endereçáveis logo abaixo do prefixo do escritório (D7).
     *
     * Só o primeiro nível — subpastas não são chaves e não são percorridas. Devolve arquivo regular
     * cujo nome é uma chave válida; fica de fora o que não é chave de arquivo persistido: arquivo
     * oculto (o temporário `.compress_*` do compressor) e resíduo de escrita `*.parcial-<hex>`
     * (DT-6). Link simbólico ou arquivo especial nesse nível faz a listagem inteira falhar — o
     * prefixo deixou de ser provadamente do escritório.
     *
     * @return list<ChaveDeArquivo>
     */
    public function listar(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): iterable
    {
        $comprovado = $this->prefixoComprovado($escopo, $categoria);
        if ($comprovado === null) {
            return [];
        }

        $prefixo = $comprovado['caminho'];
        $chaves  = [];

        foreach ($this->entradasDe($prefixo) as $nome) {
            $tipo = $this->lstatOuFalha($prefixo . '/' . $nome)['mode'] & self::S_IFMT;

            if ($tipo === self::S_IFLNK) {
                throw $this->escape($prefixo . '/' . $nome, 'é um link simbólico');
            }
            if ($tipo !== self::S_IFREG && $tipo !== self::S_IFDIR) {
                throw $this->escape($prefixo . '/' . $nome, 'não é arquivo regular nem diretório');
            }

            if ($tipo === self::S_IFDIR
                || str_starts_with($nome, '.')
                || preg_match('/\.parcial-[0-9a-f]{16}$/', $nome) === 1
            ) {
                continue;
            }

            try {
                $chaves[] = new ChaveDeArquivo($escopo, $categoria->paraCategoria(), $nome);
            } catch (Exception\ChaveDeArquivoInvalida) {
                continue; // nome que nenhuma linha do banco poderia endereçar
            }
        }

        return $chaves; // na ordem do scandir(), que é alfabética
    }

    /**
     * Apaga TUDO sob o prefixo do escritório — inclusive ocultos e subpastas — e o próprio prefixo.
     *
     * ## Duas fases, e a primeira não apaga nada
     *
     * 1. **Prova e inventário.** O prefixo tem de ser provadamente do escritório
     *    ({@see prefixoComprovado()}); depois a árvore inteira é percorrida por `lstat`, sem seguir
     *    link. Link simbólico, arquivo especial, outro sistema de arquivos montado dentro, ou
     *    diretório que não dá para ler: a operação LANÇA **antes** de remover o primeiro arquivo.
     *    Isto é falhar fechado — o prefixo fica inteiro, e quem chama registra. Terminado o
     *    inventário, a identidade do prefixo (dispositivo e inode) é conferida de novo: se ele
     *    foi trocado por outra coisa no meio, nada é removido.
     * 2. **Remoção.** Arquivos primeiro, depois os diretórios do mais fundo para o mais raso, e
     *    por fim o prefixo. Antes de cada `unlink` o arquivo é conferido de novo (mesmo
     *    dispositivo e inode, ainda arquivo regular) e o diretório pai tem de continuar sendo o
     *    caminho real inventariado, com o cache de `realpath()` limpo. Uma falha de I/O num item
     *    não interrompe os demais e NÃO lança: volta em `naoRemovidas`, ao lado da contagem.
     *
     * **Limites conhecidos** (DT-12): o PHP não tem `unlinkat`, então entre a conferência e o
     * `unlink` há uma janela; e um bind mount do MESMO sistema de arquivos dentro do prefixo tem
     * o mesmo dispositivo e não é detectado (criá-lo exige root). Explorar qualquer um dos dois
     * exige escrever no volume de uploads — e quem escreve ali já não precisa deste método para
     * apagar arquivo alheio.
     *
     * @throws FalhaDeArmazenamento se o pertencimento não puder ser provado, ou se houver escape
     */
    public function excluirPrefixo(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): ResultadoDaRemocao
    {
        $comprovado = $this->prefixoComprovado($escopo, $categoria);
        if ($comprovado === null) {
            return new ResultadoDaRemocao(0, []);
        }

        $prefixo    = $comprovado['caminho'];
        $arquivos   = [];
        $diretorios = [];
        $this->inventariar($prefixo, $comprovado['dev'], $arquivos, $diretorios);
        $this->exigirMesmaIdentidade($prefixo, $comprovado['dev'], $comprovado['ino']);

        $rotulo    = $escopo->comoTexto() . '/' . $categoria->value;
        $relativo  = static fn (string $caminho): string => $rotulo . substr($caminho, \strlen($prefixo));
        $removidos = 0;
        $sobras    = [];

        foreach ($arquivos as $caminho => ['dev' => $dev, 'ino' => $inode]) {
            if (!$this->continuaComoInventariado($caminho, $dev, $inode)) {
                $sobras[] = $relativo($caminho) . ' (mudou desde o inventário)';
                continue;
            }

            if (@unlink($caminho)) {
                $removidos++;
                continue;
            }

            clearstatcache(true, $caminho);
            if (@lstat($caminho) !== false) {
                $sobras[] = $relativo($caminho);
            }
        }

        foreach (array_reverse($diretorios) as $diretorio) {
            if (!@rmdir($diretorio) && is_dir($diretorio)) {
                $sobras[] = $relativo($diretorio) . '/';
            }
        }

        clearstatcache(true, $prefixo);
        if (!@rmdir($prefixo) && is_dir($prefixo)) {
            $sobras[] = $rotulo . '/';
        }

        return new ResultadoDaRemocao($removidos, $sobras);
    }

    /**
     * O caminho REAL do prefixo e sua identidade (dispositivo, inode), depois de provar que ele
     * pertence só ao escopo; null quando ele comprovadamente não existe (nada a listar ou apagar).
     *
     * O que precisa ser verdade, e por quê:
     *
     *  - escopo de tenant: global não tem subpasta própria — sem esta guarda o prefixo seria a raiz;
     *  - o último componente é exatamente o id (`^[1-9][0-9]*$`) e o pai é a raiz da categoria;
     *  - a raiz não é um link quebrado (isso não é "ainda não existe": é volume que sumiu);
     *  - o prefixo não é link simbólico — `pastas/5 -> ..` faria "apagar o escritório 5" apagar
     *    todas as peças de todos os escritórios;
     *  - `realpath(prefixo)` é `realpath(raiz)/id`: nenhum componente desvia para outro lugar;
     *  - o prefixo não coincide com nenhuma das sete raízes configuradas nem contém alguma delas —
     *    o erro de configuração em que `cobrancas/5` fosse o diretório de outra categoria.
     *
     * @return array{caminho: string, dev: int, ino: int}|null
     */
    private function prefixoComprovado(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): ?array
    {
        if ($escopo->ehGlobal()) {
            throw new FalhaDeArmazenamento(sprintf(
                'Operação por prefixo exige escopo de escritório; %s recebeu escopo global.',
                $categoria->value,
            ));
        }

        $tenantId = (string) $escopo->tenantIdObrigatorio();
        ['raiz' => $raiz, 'prefixo' => $prefixo] = $this->resolvedor->prefixoDe($escopo, $categoria);

        if (preg_match('/^[1-9][0-9]*$/', $tenantId) !== 1
            || basename($prefixo) !== $tenantId
            || \dirname($prefixo) !== $raiz
        ) {
            throw $this->escape($prefixo, 'não é <raiz>/<id do escritório>');
        }

        // Limpeza GLOBAL, não só do prefixo: o cache de `realpath()` do PHP responde com dado
        // velho quando a permissão de um ancestral muda (medido na investigação da E2.5), e numa
        // CLI longa como a purga isso é o caso normal.
        clearstatcache(true);

        $infoRaiz = @lstat($raiz);
        if ($infoRaiz !== false && ($infoRaiz['mode'] & self::S_IFMT) === self::S_IFLNK && realpath($raiz) === false) {
            throw $this->escape($raiz, 'é um link simbólico quebrado — o volume não está onde deveria');
        }

        $info = @lstat($prefixo);

        if ($info === false) {
            // Ausente — mas só se dava para olhar. Diretório ilegível no caminho não é ausência.
            $this->exigirCadeiaLegivel($prefixo . '/.', sprintf('o prefixo %s', $prefixo));

            return null;
        }

        $tipo = $info['mode'] & self::S_IFMT;
        if ($tipo === self::S_IFLNK) {
            throw $this->escape($prefixo, 'é um link simbólico');
        }
        if ($tipo !== self::S_IFDIR) {
            throw $this->escape($prefixo, 'existe mas não é diretório');
        }

        $raizReal    = realpath($raiz);
        $prefixoReal = realpath($prefixo);

        if ($raizReal === false || $prefixoReal === false || $prefixoReal !== $raizReal . '/' . $tenantId) {
            throw $this->escape($prefixo, 'não resolve para <raiz real>/<id>');
        }

        foreach ($this->resolvedor->raizesConfiguradas() as $outraRaiz) {
            $outraReal = realpath($outraRaiz);
            if ($outraReal === false) {
                continue; // raiz que não existe não pode estar dentro do prefixo
            }

            if ($outraReal === $prefixoReal || str_starts_with($outraReal . '/', $prefixoReal . '/')) {
                throw $this->escape($prefixo, sprintf('coincide com a raiz configurada %s ou a contém', $outraRaiz));
            }
        }

        return ['caminho' => $prefixoReal, 'dev' => $info['dev'], 'ino' => $info['ino']];
    }

    /**
     * Fase 1 de {@see excluirPrefixo()}: registra o que existe, sem apagar nada.
     *
     * @param array<string, array{dev: int, ino: int}> $arquivos   caminho → identidade
     * @param list<string>                             $diretorios em pré-ordem (o mais raso primeiro)
     */
    private function inventariar(string $diretorio, int $dispositivo, array &$arquivos, array &$diretorios): void
    {
        foreach ($this->entradasDe($diretorio) as $nome) {
            $caminho = $diretorio . '/' . $nome;
            $info    = $this->lstatOuFalha($caminho);
            $tipo    = $info['mode'] & self::S_IFMT;

            if ($tipo === self::S_IFLNK) {
                throw $this->escape($caminho, 'é um link simbólico');
            }

            if ($info['dev'] !== $dispositivo) {
                throw $this->escape($caminho, 'está em outro sistema de arquivos montado dentro do prefixo');
            }

            if ($tipo === self::S_IFDIR) {
                $diretorios[] = $caminho;
                $this->inventariar($caminho, $dispositivo, $arquivos, $diretorios);
                continue;
            }

            if ($tipo !== self::S_IFREG) {
                throw $this->escape($caminho, 'não é arquivo regular nem diretório');
            }

            $arquivos[$caminho] = ['dev' => $info['dev'], 'ino' => $info['ino']];
        }
    }

    /**
     * O prefixo ainda é o diretório provado: mesmo dispositivo, mesmo inode, e não virou link.
     * Fecha a troca do prefixo inteiro entre a prova e a remoção.
     */
    private function exigirMesmaIdentidade(string $prefixo, int $dispositivo, int $inode): void
    {
        clearstatcache(true);
        $info = $this->lstatOuFalha($prefixo);

        if (($info['mode'] & self::S_IFMT) !== self::S_IFDIR
            || $info['dev'] !== $dispositivo
            || $info['ino'] !== $inode
        ) {
            throw $this->escape($prefixo, 'mudou entre a prova de pertencimento e a remoção');
        }
    }

    /**
     * O arquivo ainda é o inventariado e o pai ainda é o caminho real de antes.
     *
     * O cache de `realpath()` é limpo INTEIRO: limpar só o caminho deixa os ancestrais em cache, e
     * o `realpath` do pai responderia com o que era verdade antes de uma troca por link (medido
     * na revisão da E2.5).
     */
    private function continuaComoInventariado(string $caminho, int $dispositivo, int $inode): bool
    {
        clearstatcache(true);
        $info = @lstat($caminho);

        return $info !== false
            && ($info['mode'] & self::S_IFMT) === self::S_IFREG
            && $info['dev'] === $dispositivo
            && $info['ino'] === $inode
            && realpath(\dirname($caminho)) === \dirname($caminho);
    }

    /**
     * Nomes do diretório, incluindo ocultos. Diretório ilegível falha — "não consegui listar"
     * não pode virar "está vazio".
     *
     * @return list<string>
     */
    private function entradasDe(string $diretorio): array
    {
        $entradas = @scandir($diretorio);
        if ($entradas === false) {
            throw new FalhaDeArmazenamento(sprintf('Não foi possível listar o diretório %s.', $diretorio));
        }

        return array_values(array_filter(
            $entradas,
            static fn (string $nome): bool => $nome !== '.' && $nome !== '..',
        ));
    }

    /** @return array{dev: int, ino: int, mode: int} */
    private function lstatOuFalha(string $caminho): array
    {
        clearstatcache(true, $caminho);
        $info = @lstat($caminho);
        if ($info === false) {
            throw new FalhaDeArmazenamento(sprintf('Não foi possível inspecionar %s.', $caminho));
        }

        return ['dev' => $info['dev'], 'ino' => $info['ino'], 'mode' => $info['mode']];
    }

    private function escape(string $caminho, string $motivo): FalhaDeArmazenamento
    {
        return new FalhaDeArmazenamento(sprintf(
            'Pertencimento do prefixo não comprovado: %s %s. Nada foi removido.',
            $caminho,
            $motivo,
        ));
    }

    /**
     * O caminho do arquivo, ou a exceção CERTA quando ele não está lá (D10, D13).
     *
     * Com um diretório ilegível no caminho, `is_file()` também devolve false. Sem provar antes que
     * dava para olhar, a leitura responderia "não encontrado" a uma pane — e quem chama transforma
     * isso em 404 (o export de peça) ou em "a peça não referencia nada" (a pergunta que uma
     * rotina de limpeza faz antes de apagar imagem). Os três leitores passam por aqui.
     */
    private function exigirArquivoPresente(ChaveDeArquivo $chave): string
    {
        $caminho = $this->resolvedor->caminhoDe($chave);
        clearstatcache(true, $caminho);

        if (!is_file($caminho)) {
            $this->exigirCadeiaLegivel($caminho, $chave->comoTexto());

            throw ArquivoNaoEncontrado::para($chave);
        }

        return $caminho;
    }

    private function cunharChaveLivre(NovoArquivo $novo): ChaveDeArquivo
    {
        for ($tentativa = 0; $tentativa < self::TENTATIVAS_DE_CUNHAGEM; $tentativa++) {
            $chave = $novo->cunharChave();

            if (!file_exists($this->resolvedor->caminhoDe($chave))) {
                return $chave;
            }
        }

        throw new FalhaDeArmazenamento(
            'Não foi possível cunhar um nome livre para o arquivo novo após '
            . self::TENTATIVAS_DE_CUNHAGEM . ' tentativas.',
        );
    }

    /**
     * Grava num temporário do MESMO diretório e move para o lugar.
     *
     * O temporário precisa ser vizinho do destino: `rename()` só é atômico dentro do mesmo
     * sistema de arquivos. A publicação — o `rename()` que torna o arquivo visível com o nome
     * final — é SEMPRE feita a partir de um `.parcial-` vizinho, nos dois caminhos.
     *
     * Quando a fonte é um arquivo local que pode ser consumido, o caminho rápido
     * ({@see publicarMovendo()}) move a origem em vez de relê-la, o que é o que permite gravar
     * arquivo grande sem carregar nada em memória.
     */
    private function escreverAtomicamente(string $destino, FonteDeConteudo $fonte): void
    {
        $this->garantirDiretorio(\dirname($destino));

        $origem = $fonte->caminhoLocalOuNull();

        if ($origem !== null && $fonte->consomeOrigem() && $this->publicarMovendo($origem, $destino)) {
            return;
        }

        $temporario = $this->vizinhoParcial($destino);

        $saida = @fopen($temporario, 'wb');
        if ($saida === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível abrir o destino temporário %s.', $temporario),
            );
        }

        try {
            $fonte->escreverEm($saida);

            if (!fflush($saida)) {
                throw new FalhaDeArmazenamento('Não foi possível esvaziar o buffer de escrita.');
            }
        } catch (\Throwable $e) {
            fclose($saida);
            @unlink($temporario);

            throw $e;
        }

        fclose($saida);

        $this->normalizarModo($temporario);

        if (!@rename($temporario, $destino)) {
            @unlink($temporario);

            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível publicar o arquivo em %s.', $destino),
            );
        }

        // Só agora: a origem só some depois de o destino estar publicado. É a mesma prioridade da
        // E1 — órfão recuperável é aceitável, perda de conteúdo não.
        if ($origem !== null && $fonte->consomeOrigem()) {
            @unlink($origem);
        }
    }

    /**
     * Caminho rápido: move a origem para um `.parcial-` vizinho e só então para o destino.
     *
     * ## Por que dois passos, e não `rename($origem, $destino)`
     *
     * O `rename()` do PHP **não falha** com EXDEV: entre sistemas de arquivos — ou entre dois
     * pontos de montagem do mesmo — ele copia por dentro, direto no nome que recebeu, e devolve
     * true (medido no container na E2.4A: `/tmp` no dispositivo 100, `var/` no 2096). Com o nome
     * final como alvo, essa cópia não é atômica: um leitor vê o arquivo pela metade, um processo
     * morto deixa um parcial com nome de arquivo bom, e numa sobrescrita o conteúdo publicado é
     * truncado no lugar. É o caso normal do upload em produção — o PHP guarda o upload em `/tmp`,
     * e os uploads moram num volume. Com o vizinho como alvo, a eventual cópia acontece sob nome
     * temporário, e a publicação é um `rename()` dentro do mesmo diretório, que é atômico. Não há
     * heurística de dispositivo a errar.
     *
     * @return bool false quando nada saiu do lugar — a origem está intacta e o caminho lento pode
     *              tentar
     *
     * @throws FalhaDeArmazenamento quando o conteúdo já saiu da origem, não chegou ao destino e
     *                              também não pôde voltar — ele fica no vizinho, nunca é apagado
     */
    private function publicarMovendo(string $origem, string $destino): bool
    {
        $vizinho = $this->vizinhoParcial($destino);

        if (!@rename($origem, $vizinho)) {
            // A cópia entre sistemas de arquivos pode ter morrido no meio; o PHP só apaga a
            // origem quando termina. O parcial tem nome nosso e acabou de nascer.
            @unlink($vizinho);

            return false;
        }

        // O modo é acertado ANTES de o arquivo ficar visível: publicado, ele já nasce legível.
        $this->normalizarModo($vizinho);

        if (@rename($vizinho, $destino)) {
            return true;
        }

        // O conteúdo saiu da origem e não foi publicado: devolve-o antes de desistir, para a
        // origem não desaparecer sem o arquivo existir no destino.
        if (@rename($vizinho, $origem)) {
            return false;
        }

        // Falha dupla: o vizinho é a única cópia do conteúdo. Fica no volume — resíduo `.parcial-`
        // que a varredura de DT-6 reconhece — em vez de ser apagado: conteúdo vale mais que limpeza.
        throw new FalhaDeArmazenamento(sprintf(
            'Não foi possível publicar %s nem devolver a origem; o conteúdo ficou em %s.',
            $destino,
            $vizinho,
        ));
    }

    private function vizinhoParcial(string $destino): string
    {
        return $destino . '.parcial-' . bin2hex(random_bytes(8));
    }

    /**
     * Mesmo modo nos dois ramos de publicação.
     *
     * Sem isto o resultado depende de qual ramo rodou e de onde veio a origem: `rename()` no
     * mesmo sistema de arquivos preserva o modo dela (um `tempnam()` nasce `0600`), a cópia que o
     * PHP faz entre sistemas de arquivos recria o arquivo, e `fopen('wb')` cria com
     * `0666 & ~umask` (tipicamente `0644`). Arquivo de upload que nasce `0600` é invisível para o
     * nginx e para o worker.
     *
     * `0666 & ~umask` é exatamente o que `UploadedFile::move()` aplica hoje
     * (`vendor/symfony/http-foundation/File/UploadedFile.php`), então isto preserva o
     * comportamento atual em vez de inventar outro.
     */
    private function normalizarModo(string $caminho): void
    {
        @chmod($caminho, 0o666 & ~umask());
    }

    /**
     * Percorre os ancestrais de cima para baixo e lança se algum existir sem ser atravessável.
     *
     * De cima para baixo porque é a única ordem em que a resposta é confiável: só dá para
     * afirmar que um diretório "não existe" se o pai dele puder ser lido. Verificar apenas o pai
     * imediato deixa passar exatamente o caso em que a permissão quebrou mais acima.
     */
    private function exigirCadeiaLegivel(string $caminho, string $oQue): void
    {
        $partes  = explode('/', ltrim(\dirname($caminho), '/'));
        $prefixo = '';

        foreach ($partes as $parte) {
            $prefixo .= '/' . $parte;
            clearstatcache(true, $prefixo);

            if (!is_dir($prefixo)) {
                return; // a partir daqui nada existe, e o pai já se provou atravessável
            }

            if (!is_readable($prefixo) || !is_executable($prefixo)) {
                throw new FalhaDeArmazenamento(sprintf(
                    'Não foi possível determinar a existência de %s: o diretório %s não é legível. '
                    . 'Responder "não existe" aqui faria uma rotina de limpeza apagar registro válido.',
                    $oQue,
                    $prefixo,
                ));
            }
        }
    }

    private function garantirDiretorio(string $diretorio): void
    {
        if (is_dir($diretorio)) {
            return;
        }

        // A corrida importa: dois processos podem criar o mesmo diretório. O segundo `is_dir`
        // distingue "perdi a corrida" (tudo bem) de "não consegui criar" (erro de verdade).
        if (!@mkdir($diretorio, 0o755, true) && !is_dir($diretorio)) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível criar o diretório %s.', $diretorio),
            );
        }
    }

    private function mimeDe(string $caminho): string
    {
        $mime = @mime_content_type($caminho);

        return $mime !== false && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
