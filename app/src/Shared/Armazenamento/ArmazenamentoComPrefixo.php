<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * Varrer e apagar TUDO de um escopo de uma vez — operação que só existe onde é segura (D7).
 *
 * ## Por que é uma interface separada do núcleo
 *
 * Dois motivos, e nenhum é estético. Primeiro, ela tem **um único consumidor** em todo o sistema:
 * `PurgarEscritorioUseCase`. Segundo, em objeto remoto a listagem é paginada e eventualmente
 * consistente — obrigar todo backend a oferecê-la no núcleo seria prometer o que o R2 não cumpre
 * do mesmo jeito.
 *
 * ## A barreira de D7 está no TIPO do parâmetro
 *
 * O parâmetro é {@see CategoriaComIsolamentoFisico}, um enum de dois casos. Categoria plana
 * **não é representável** aqui: não existe a chamada errada a ser escrita, então não existe o
 * `if` a ser esquecido.
 *
 * O perigo que isso fecha é concreto. Sete das nove categorias moram em diretório compartilhado
 * por todos os escritórios — `uploads/clientes`, `uploads/justificativas`, `uploads/kanban`… Um
 * `excluirPrefixo(tenant 5, CLIENTE_DOCUMENTO)` resolvido ingenuamente apagaria o acervo de todo
 * mundo. Isolamento lógico **não se infere de diretório fisicamente compartilhado**.
 *
 * Nas sete planas a purga continua fazendo o que já faz e está certo: identifica os arquivos
 * **pelos registros do tenant** e apaga um a um, via `excluir()` do núcleo.
 *
 * ## Cinto e suspensório
 *
 * Mesmo recebendo o tipo certo, a implementação deve **provar** antes de apagar que o prefixo
 * resolvido pertence exclusivamente àquele escopo — e lançar se não puder. Motivo medido:
 * `PASTA_IMAGEM_EDITOR` mora em subpastas de `uploads/pastas/`, a MESMA raiz de
 * `PASTA_DOCUMENTO`. Um escopo global, ou um bug de resolução que devolvesse a raiz, apagaria o
 * acervo de peças inteiro.
 *
 * Implementada na fatia E2.5, junto com a migração da purga.
 */
interface ArmazenamentoComPrefixo
{
    /**
     * Chaves existentes sob (escopo, categoria).
     *
     * @return iterable<ChaveDeArquivo>
     *
     * @throws FalhaDeArmazenamento se o prefixo não puder ser resolvido com segurança
     */
    public function listar(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): iterable;

    /**
     * Remove tudo sob (escopo, categoria). Devolve quantos arquivos foram removidos.
     *
     * @throws FalhaDeArmazenamento se o prefixo resolvido não pertencer exclusivamente ao escopo
     */
    public function excluirPrefixo(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): int;
}
