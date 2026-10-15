<?php

declare(strict_types=1);

namespace App\Tarefa\Armazenamento;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;

/**
 * Traduz o anexo de mensagem de tarefa em {@see ChaveDeArquivo} (E2.7, spec P2 §10.1).
 *
 * A coluna `tarefa_mensagem.arquivo_anexo` guarda o **caminho público**
 * `/uploads/tarefas/chat/<nome>`, com barras — o único formato que o código já escreveu e o único
 * presente em produção (19 de 19 em 15/10/2026). `ChaveDeArquivo` recusa barra (D5 da E2), e
 * relaxá-la está proibido; o dado também não muda. A saída é esta tradução: o prefixo fixo sai, o
 * `<nome>` vira o nome da chave, e o caminho de volta é {@see valorDaColuna()}.
 *
 * ## A guarda contra travessia mora aqui
 *
 * O valor vem do banco, mas era concatenado em caminho de disco (E1). A regra é a mesma allowlist
 * que o `CaminhoDeAnexoDeTarefa` aplicava — `<nome>` começa por alfanumérico (descarta `.` e `..`)
 * e só tem `[A-Za-z0-9._-]` (descarta `/`, `\`, espaço, `%`, byte nulo) —, com `\z` no fim: o `$`
 * do PCRE casaria antes de um `\n` final. Por cima disso, `ChaveDeArquivo` ainda recusa os cinco
 * casos da fronteira do storage.
 *
 * ## `/uploads/tarefas/admin/` não é mais servível (DP-9)
 *
 * O `CaminhoDeAnexoDeTarefa` aceitava `admin/`, de um fluxo antigo. Em produção há 2 arquivos ali e
 * **nenhuma** referência no banco. Endereçá-lo exigiria uma décima categoria, no contrato e no
 * layout remoto, por zero registros.
 *
 * ## Tenant (R1)
 *
 * O escopo sai da MENSAGEM (`tenant_id NOT NULL`), nunca da sessão. Arquivo novo: da TAREFA, de quem
 * a mensagem copia o escritório no `TarefaController::mensagem()` — o mesmo que
 * {@see anexoDeMensagem()} vai ler depois.
 */
final class ChavesDeTarefa
{
    /** O que a coluna guarda antes do nome. Lido também pelo template (`|split('/')|last`). */
    public const PREFIXO_DA_COLUNA = '/uploads/tarefas/chat/';

    private const VALOR = '#^/uploads/tarefas/chat/([A-Za-z0-9][A-Za-z0-9._-]*)\z#';

    private function __construct()
    {
    }

    /**
     * @throws ChaveDeArquivoInvalida quando a mensagem não tem anexo, o valor foge do formato ou falta o escritório
     */
    public static function anexoDeMensagem(TarefaMensagem $mensagem): ChaveDeArquivo
    {
        $valor = $mensagem->getArquivoAnexo();
        if ($valor === null || preg_match(self::VALOR, $valor, $partes) !== 1) {
            throw new ChaveDeArquivoInvalida(
                'O anexo desta mensagem não está no formato /uploads/tarefas/chat/<nome>; não há chave a montar.',
            );
        }

        return new ChaveDeArquivo(
            self::escopoDe($mensagem->getTenant(), 'anexo de mensagem de tarefa'),
            CategoriaDeArquivo::TAREFA_ANEXO,
            $partes[1],
        );
    }

    /** Arquivo novo: o nome é cunhado pelo storage (D8); o escopo sai da tarefa. */
    public static function novoAnexo(Tarefa $tarefa, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($tarefa->getTenant(), 'anexo novo de mensagem de tarefa'),
            CategoriaDeArquivo::TAREFA_ANEXO,
            $extensao,
        );
    }

    /** O valor que a coluna guarda para a chave — o formato de sempre, que a timeline e o template leem. */
    public static function valorDaColuna(ChaveDeArquivo $chave): string
    {
        if ($chave->categoria !== CategoriaDeArquivo::TAREFA_ANEXO) {
            throw new \LogicException(sprintf('Chave de %s não é anexo de tarefa.', $chave->categoria->value));
        }

        return self::PREFIXO_DA_COLUNA . $chave->nome;
    }

    private static function escopoDe(?Tenant $tenant, string $oQue): EscopoDeArquivo
    {
        $id = $tenant?->getId();
        if ($id === null) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'Não dá para montar a chave de armazenamento de %s sem o escritório dono.',
                $oQue,
            ));
        }

        return EscopoDeArquivo::deTenant($id);
    }
}
